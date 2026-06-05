<?php
/**
 * Shared D6 batch-flush engine for the rec-engine ingest endpoints.
 *
 * @package Smaily\Connect\Smaily\RecEngine
 */

declare(strict_types=1);

namespace Smaily\Connect\Smaily\RecEngine;

use Smaily\Connect\Settings\RecEngineSettings;

defined( 'ABSPATH' ) || exit;

/**
 * The D6 per-item contract (RECENGINE_API_CONTRACT.md, DECISIONS F3-18) is
 * identical across the ingest endpoints — only four things differ per
 * endpoint: which event types to drain, the batch cap, the Client call, and
 * how a queue row becomes a wire object. This base holds the shared logic so
 * the three flushers (customers, orders, and — after the N-7 retrofit —
 * catalog) don't triplicate it; each subclass keeps its own Action Scheduler
 * hook/group and recurring tick (independent retry cycles), it just inherits
 * the D6 machinery.
 *
 * Shared (here): the flush() skeleton (gate → drain → build → send → split),
 * the errors[].index → batch_rows[index] split, the
 * processed+deduplicated+errors==total invariant, and the ApiException
 * terminal-4xx / transient-retry policy.
 *
 * Per-endpoint (subclass): event_types(), batch_size(), endpoint_label(),
 * send(), row_to_object(). The subclass owns its PayloadBuilder + entity
 * loader, and its FLUSH_HOOK / AS_GROUP constants (used by Bootstrap, not
 * here).
 *
 * Not final: tests subclass the concrete flushers to stub the entity loader.
 */
abstract class AbstractD6Flusher {

	/**
	 * Row-level retry backoff per attempt number (seconds): 1m, 5m, 15m,
	 * 1h, 6h. The recurring flush re-ticks; pending() only returns rows
	 * whose next_retry_at has passed.
	 *
	 * @var array<int, int>
	 */
	private const RETRY_BACKOFF = array( 60, 300, 900, 3600, 21600 );

	protected IngestQueue $queue;
	protected RecEngineSettings $settings;

	/** @var callable(): Client */
	protected $client_factory;

	/**
	 * @param callable(): Client $client_factory Builds a rec-engine Client from the stored
	 *                                          tenant config (with a small max_attempts).
	 */
	public function __construct( IngestQueue $queue, RecEngineSettings $settings, callable $client_factory ) {
		$this->queue          = $queue;
		$this->settings       = $settings;
		$this->client_factory = $client_factory;
	}

	/**
	 * Queue event types this flusher drains (so it never consumes another
	 * endpoint's rows from the shared queue).
	 *
	 * @return array<int, string>
	 */
	abstract protected function event_types(): array;

	/** Default batch ceiling (catalog/customers 100; orders 50). */
	abstract protected function batch_size(): int;

	/** Endpoint name for the invariant-violation log line. */
	abstract protected function endpoint_label(): string;

	/**
	 * POST the batch to the endpoint's Client method; returns the decoded D6
	 * response. Throws ApiException on HTTP failure.
	 *
	 * @param array<int, array<string, mixed>> $batch
	 *
	 * @return array<string, mixed>
	 */
	abstract protected function send( array $batch ): array;

	/**
	 * Turn one queue row into its wire object, or null for a terminal skip
	 * (the entity vanished, or is no longer in a sendable state).
	 *
	 * @param array<string, mixed> $row
	 *
	 * @return array<string, mixed>|null
	 */
	abstract protected function row_to_object( array $row ): ?array;

	/**
	 * Process up to $batch_size due rows (defaults to the endpoint's cap).
	 *
	 * @return array{processed: int, sent: int, failed: int, retried: int, skipped: int}
	 */
	public function flush( ?int $batch_size = null ): array {
		$stats = array(
			'processed' => 0,
			'sent'      => 0,
			'failed'    => 0,
			'retried'   => 0,
			'skipped'   => 0,
		);

		if ( ! $this->settings->is_connected() ) {
			return $stats;
		}

		$limit = $batch_size ?? $this->batch_size();
		$rows  = $this->queue->pending( $limit, $this->event_types() );
		if ( $rows === array() ) {
			return $stats;
		}

		$objects    = array();
		$batch_rows = array();
		foreach ( $rows as $row ) {
			++$stats['processed'];
			$id     = (int) ( $row['id'] ?? 0 );
			$object = $this->row_to_object( $row );

			if ( $object === null ) {
				// Terminal skip — mark sent so the row leaves the queue.
				$this->queue->mark_sent( $id );
				++$stats['skipped'];
				continue;
			}

			$objects[]    = $object;
			$batch_rows[] = $row;
		}

		if ( $objects === array() ) {
			return $stats;
		}

		try {
			$response = $this->send( $objects );
			$this->apply_d6_response( $response, $batch_rows, $stats );
		} catch ( ApiException $e ) {
			$terminal = $this->is_terminal( $e );
			foreach ( $batch_rows as $row ) {
				$this->handle_failure( $row, $e, $terminal, $stats );
			}
		}

		return $stats;
	}

	/**
	 * Split an index-aligned batch by the D6 response: rows named in errors[]
	 * fail, every other row succeeded (processed or deduplicated).
	 *
	 * @param array<string, mixed>                                            $response
	 * @param array<int, array<string, mixed>>                                $batch_rows
	 * @param array{processed:int,sent:int,failed:int,retried:int,skipped:int} $stats
	 */
	private function apply_d6_response( array $response, array $batch_rows, array &$stats ): void {
		$errors = ( isset( $response['errors'] ) && is_array( $response['errors'] ) ) ? $response['errors'] : array();

		$failed_index = array();
		foreach ( $errors as $error ) {
			if ( ! is_array( $error ) || ! isset( $error['index'] ) ) {
				continue;
			}
			$index = (int) $error['index'];
			if ( ! isset( $batch_rows[ $index ] ) ) {
				continue;
			}
			$failed_index[ $index ] = true;
			$this->queue->mark_failed(
				(int) ( $batch_rows[ $index ]['id'] ?? 0 ),
				$this->item_error_message( $error )
			);
			++$stats['failed'];
		}

		foreach ( $batch_rows as $index => $row ) {
			if ( isset( $failed_index[ $index ] ) ) {
				continue;
			}
			$this->queue->mark_sent( (int) ( $row['id'] ?? 0 ) );
			++$stats['sent'];
		}

		$this->assert_invariant( $response, count( $batch_rows ), count( $errors ) );
	}

	/**
	 * The engine's counts must reconcile with the batch size:
	 * processed + deduplicated + errors.length == total. A mismatch is an
	 * engine-side bug; log it (the row states already followed errors[], which
	 * is authoritative).
	 *
	 * @param array<string, mixed> $response
	 */
	private function assert_invariant( array $response, int $total, int $error_count ): void {
		$processed    = isset( $response['processed'] ) ? (int) $response['processed'] : 0;
		$deduplicated = isset( $response['deduplicated'] ) ? (int) $response['deduplicated'] : 0;

		if ( $processed + $deduplicated + $error_count === $total ) {
			return;
		}

		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		error_log(
			sprintf(
				'[smaily-connect] D6 invariant violation on %s ingest: processed(%d) + deduplicated(%d) + errors(%d) != batch(%d). Engine-side bug?',
				$this->endpoint_label(),
				$processed,
				$deduplicated,
				$error_count,
				$total
			)
		);
	}

	/**
	 * @param array<string, mixed> $error A single errors[] entry.
	 */
	private function item_error_message( array $error ): string {
		$field   = isset( $error['field'] ) ? (string) $error['field'] : '';
		$message = isset( $error['message'] ) ? (string) $error['message'] : '';
		return sprintf( 'd6_item_error field=%s: %s', $field, $message );
	}

	/**
	 * @param array<string, mixed>                                            $row
	 * @param array{processed:int,sent:int,failed:int,retried:int,skipped:int} $stats
	 */
	private function handle_failure( array $row, ApiException $e, bool $terminal, array &$stats ): void {
		$id       = (int) ( $row['id'] ?? 0 );
		$attempts = (int) ( $row['attempts'] ?? 0 );
		$max      = (int) ( $row['max_attempts'] ?? IngestQueue::DEFAULT_MAX_ATTEMPTS );
		$message  = sprintf( 'http_%d %s', $e->getCode(), $e->error_code() );

		if ( $terminal || $attempts + 1 >= $max ) {
			$this->queue->mark_failed( $id, $message );
			++$stats['failed'];
			return;
		}

		$this->queue->record_attempt( $id, $message, $this->backoff_seconds( $attempts + 1 ) );
		++$stats['retried'];
	}

	private function is_terminal( ApiException $e ): bool {
		$status = $e->getCode();
		return $status >= 400 && $status < 500 && 429 !== $status;
	}

	private function backoff_seconds( int $attempt ): int {
		$index = max( 0, min( $attempt - 1, count( self::RETRY_BACKOFF ) - 1 ) );
		return self::RETRY_BACKOFF[ $index ];
	}
}
