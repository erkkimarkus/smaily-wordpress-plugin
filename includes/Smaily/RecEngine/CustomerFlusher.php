<?php
/**
 * Drains the rec-engine ingest queue's customer rows and ships each batch
 * to POST /api/v1/ingest/customers, applying the D6 per-item contract.
 *
 * @package Smaily\Connect\Smaily\RecEngine
 */

declare(strict_types=1);

namespace Smaily\Connect\Smaily\RecEngine;

use Smaily\Connect\Settings\RecEngineSettings;

defined( 'ABSPATH' ) || exit;

/**
 * Action Scheduler callback for `smly_rec_flush_customers`. The customer
 * sibling of IngestFlusher (catalog), kept SEPARATE on purpose (Variant 2):
 * customers is the D6 per-item-`errors[]` reference implementation today,
 * while catalog is still all-or-nothing until the N-7 retrofit. Rather than
 * one flusher carrying conditional "tolerates both" logic that disappears at
 * N-7, each endpoint gets a clean flusher; they consolidate into a shared D6
 * dispatcher when catalog + browse move to D6.
 *
 * The two flushers share ONE queue table. CustomerFlusher drains only
 * customer.* rows (IngestQueue::pending() event_type filter) and the catalog
 * flusher only catalog.* — neither silently consumes the other's rows.
 *
 * Per-row → wire object:
 *   - customer.upsert : load the WP_User FRESH by entity_id (the user id) and
 *     build it, so the engine always gets current state. A user that vanished
 *     since enqueue is a terminal skip. (There is no customer.delete here —
 *     user erasure is the GDPR flow, a separate later sub-PR, not a normal
 *     lifecycle ingest event.)
 *
 * Response handling — the D6 contract (RECENGINE_API_CONTRACT.md §4,
 * DECISIONS F3-18):
 *   - 2xx body {ok, processed, deduplicated, errors:[{index, email?, field,
 *     message}]} — PER-ITEM partial success. Each item has exactly one fate:
 *     processed / deduplicated (both success → mark_sent) or an errors[]
 *     entry (per-item validation failure → mark_failed; retrying the same bad
 *     data won't help, and the engine never registered its event_id so a
 *     corrected re-enqueue still processes). errors[].index maps directly to
 *     the index-aligned batch_rows[index]. The invariant
 *     processed + deduplicated + errors.length == total is asserted; a
 *     mismatch is an engine-side bug and is logged (not fatal — the row
 *     states are driven by errors[], which is authoritative for failures).
 *   - ApiException with a terminal 4xx (not 429) — a WRAPPER-level reject
 *     (non-array / empty / >100 customers, or a revoked key); retrying won't
 *     help, so mark_failed the whole batch. The body's `details` (preserved
 *     on ApiException) explains why.
 *   - ApiException otherwise (429 / 5xx exhausted / network) — row-level retry
 *     via record_attempt + next_retry_at until max_attempts, then mark_failed.
 *
 * Not final: tests subclass to stub get_user() while driving flush() through
 * doubled queue / builder / client collaborators. Same rationale as
 * IngestFlusher.
 */
class CustomerFlusher {

	/** Queue event type for a customer upsert (register / update / checkout). */
	public const EVENT_CUSTOMER_UPSERT = 'customer.upsert';

	/** Action Scheduler hook + group, separate from catalog's flush cycle. */
	public const FLUSH_HOOK = 'smly_rec_flush_customers';
	public const AS_GROUP   = 'smaily-rec-customers';

	/** Spec-conservative batch ceiling (engine accepts 1..100). */
	public const DEFAULT_BATCH_SIZE = 100;

	/**
	 * Row-level retry backoff per attempt number (seconds): 1m, 5m, 15m,
	 * 1h, 6h — same policy as IngestFlusher.
	 *
	 * @var array<int, int>
	 */
	private const RETRY_BACKOFF = array( 60, 300, 900, 3600, 21600 );

	private IngestQueue $queue;
	private CustomerPayloadBuilder $builder;
	private RecEngineSettings $settings;

	/** @var callable(): Client */
	private $client_factory;

	/**
	 * @param callable(): Client $client_factory Builds a rec-engine Client from the stored
	 *                                          tenant config (with a small max_attempts).
	 */
	public function __construct(
		IngestQueue $queue,
		CustomerPayloadBuilder $builder,
		RecEngineSettings $settings,
		callable $client_factory
	) {
		$this->queue          = $queue;
		$this->builder        = $builder;
		$this->settings       = $settings;
		$this->client_factory = $client_factory;
	}

	/**
	 * Process up to $batch_size due customer rows.
	 *
	 * @return array{processed: int, sent: int, failed: int, retried: int, skipped: int}
	 */
	public function flush( int $batch_size = self::DEFAULT_BATCH_SIZE ): array {
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

		// Drain customer rows only — the shared queue also carries catalog rows.
		$rows = $this->queue->pending( $batch_size, array( self::EVENT_CUSTOMER_UPSERT ) );
		if ( $rows === array() ) {
			return $stats;
		}

		$customers  = array();
		$batch_rows = array();
		foreach ( $rows as $row ) {
			++$stats['processed'];
			$id     = (int) ( $row['id'] ?? 0 );
			$object = $this->row_to_object( $row );

			if ( $object === null ) {
				// User gone since enqueue — terminal skip. Mark sent so the row
				// leaves the queue; a retry can't recover a deleted user.
				$this->queue->mark_sent( $id );
				++$stats['skipped'];
				continue;
			}

			$customers[]  = $object;
			$batch_rows[] = $row;
		}

		if ( $customers === array() ) {
			return $stats;
		}

		try {
			$response = ( $this->client_factory )()->ingest_customers( $customers );
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

		// Map errors[].index → the row that failed. Out-of-range indexes are
		// ignored here but still counted toward the invariant check below.
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

		// Every row NOT in errors[] was processed or deduplicated — both are
		// success, so the row leaves the queue.
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
	 * The engine's own counts must reconcile with the batch size:
	 * processed + deduplicated + errors.length == total. A mismatch is an
	 * engine-side bug; log it (the row states already followed errors[], which
	 * is authoritative for which items failed).
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
				'[smaily-connect] D6 invariant violation on customers ingest: processed(%d) + deduplicated(%d) + errors(%d) != batch(%d). Engine-side bug?',
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

	/**
	 * Map a queue row to its customer wire object, or null for a terminal skip
	 * (the user was deleted after enqueue).
	 *
	 * @param array<string, mixed> $row
	 *
	 * @return array<string, mixed>|null
	 */
	private function row_to_object( array $row ): ?array {
		$event_uuid = (string) ( $row['event_uuid'] ?? '' );
		$user       = $this->get_user( (int) ( $row['entity_id'] ?? 0 ) );
		if ( $user === null ) {
			return null;
		}
		return $this->builder->build( $user, $event_uuid );
	}

	private function is_terminal( ApiException $e ): bool {
		$status = $e->getCode();
		return $status >= 400 && $status < 500 && 429 !== $status;
	}

	private function backoff_seconds( int $attempt ): int {
		$index = max( 0, min( $attempt - 1, count( self::RETRY_BACKOFF ) - 1 ) );
		return self::RETRY_BACKOFF[ $index ];
	}

	/**
	 * Load a WP user by id. Protected so tests can stub the lookup.
	 */
	protected function get_user( int $user_id ): ?\WP_User {
		if ( $user_id <= 0 || ! function_exists( 'get_userdata' ) ) {
			return null;
		}
		$user = get_userdata( $user_id );
		return $user instanceof \WP_User ? $user : null;
	}
}
