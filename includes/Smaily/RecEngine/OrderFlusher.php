<?php
/**
 * Drains the rec-engine ingest queue's order rows and ships each batch to
 * POST /api/v1/ingest/orders, applying the D6 per-item contract.
 *
 * @package Smaily\Connect\Smaily\RecEngine
 */

declare(strict_types=1);

namespace Smaily\Connect\Smaily\RecEngine;

use Smaily\Connect\Settings\RecEngineSettings;

defined( 'ABSPATH' ) || exit;

/**
 * Action Scheduler callback for `smly_rec_flush_orders`. The order sibling of
 * CustomerFlusher — same D6 per-item-`errors[]` shape, kept SEPARATE (Variant
 * 2) until the N-7 consolidation. Drains only `order.*` rows from the shared
 * queue (IngestQueue::pending() event_type filter); batch cap is **50** (lower
 * than the 100 for catalog/customers — orders carry nested line items).
 *
 * Per-row → wire object:
 *   - order.upsert : load the WC_Order FRESH by entity_id (the order id) and
 *     build it, so the engine gets current state (status, items). This is
 *     order-id keyed, so guest orders work without a payload-carried path —
 *     the engine auto-creates the customer from the order's customer_email.
 *     Two terminal skips: the order vanished, OR its CURRENT status is no
 *     longer a confirmed purchase (map_status === '' — e.g. it moved back to
 *     pending after enqueue). The hook re-enqueues if it becomes mappable
 *     again, so skipping (mark_sent) is safe.
 *
 * Response handling is the D6 contract (RECENGINE_API_CONTRACT.md §5, F3-18),
 * identical to CustomerFlusher:
 *   - 2xx {ok, processed, deduplicated, errors:[{index, external_order_id?,
 *     field, message}]} — per-item partial success; errors[].index maps to
 *     the index-aligned batch_rows[index]. Errored rows → mark_failed; the
 *     rest (processed / deduplicated) → mark_sent. Invariant
 *     processed + deduplicated + errors.length == total is logged on mismatch.
 *     Attribution is async — there are no attribution counts to read here.
 *   - ApiException terminal 4xx → whole batch mark_failed (a wrapper-level
 *     reject: non-array / empty / >50, or a revoked key; details preserved).
 *   - ApiException otherwise (429 / 5xx exhausted / network) → row-level retry.
 *
 * Not final: tests subclass to stub get_order(). Same rationale as the other
 * flushers.
 */
class OrderFlusher {

	/** Queue event type for an order upsert (created / status-changed). */
	public const EVENT_ORDER_UPSERT = 'order.upsert';

	/** Action Scheduler hook + group, separate from catalog/customer cycles. */
	public const FLUSH_HOOK = 'smly_rec_flush_orders';
	public const AS_GROUP   = 'smaily-rec-orders';

	/** Orders are heavier (nested items) — the engine caps the batch at 50. */
	public const DEFAULT_BATCH_SIZE = 50;

	/**
	 * Row-level retry backoff per attempt number (seconds): 1m, 5m, 15m,
	 * 1h, 6h — same policy as the other flushers.
	 *
	 * @var array<int, int>
	 */
	private const RETRY_BACKOFF = array( 60, 300, 900, 3600, 21600 );

	private IngestQueue $queue;
	private OrderPayloadBuilder $builder;
	private RecEngineSettings $settings;

	/** @var callable(): Client */
	private $client_factory;

	/**
	 * @param callable(): Client $client_factory Builds a rec-engine Client from the stored
	 *                                          tenant config (with a small max_attempts).
	 */
	public function __construct(
		IngestQueue $queue,
		OrderPayloadBuilder $builder,
		RecEngineSettings $settings,
		callable $client_factory
	) {
		$this->queue          = $queue;
		$this->builder        = $builder;
		$this->settings       = $settings;
		$this->client_factory = $client_factory;
	}

	/**
	 * Process up to $batch_size due order rows.
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

		$rows = $this->queue->pending( $batch_size, array( self::EVENT_ORDER_UPSERT ) );
		if ( $rows === array() ) {
			return $stats;
		}

		$orders     = array();
		$batch_rows = array();
		foreach ( $rows as $row ) {
			++$stats['processed'];
			$id     = (int) ( $row['id'] ?? 0 );
			$object = $this->row_to_object( $row );

			if ( $object === null ) {
				// Order gone, or no longer a confirmed-purchase status — terminal
				// skip. Mark sent so the row leaves the queue.
				$this->queue->mark_sent( $id );
				++$stats['skipped'];
				continue;
			}

			$orders[]     = $object;
			$batch_rows[] = $row;
		}

		if ( $orders === array() ) {
			return $stats;
		}

		try {
			$response = ( $this->client_factory )()->ingest_orders( $orders );
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
	 * engine-side bug; log it (the row states already followed errors[]).
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
				'[smaily-connect] D6 invariant violation on orders ingest: processed(%d) + deduplicated(%d) + errors(%d) != batch(%d). Engine-side bug?',
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
	 * Map a queue row to its order wire object, or null for a terminal skip
	 * (the order was deleted, or its current status is no longer a confirmed
	 * purchase the engine accepts).
	 *
	 * @param array<string, mixed> $row
	 *
	 * @return array<string, mixed>|null
	 */
	private function row_to_object( array $row ): ?array {
		$event_uuid = (string) ( $row['event_uuid'] ?? '' );
		$order      = $this->get_order( (int) ( $row['entity_id'] ?? 0 ) );
		if ( $order === null ) {
			return null;
		}
		// Status may have changed since enqueue; read it fresh. If it's no
		// longer mappable (e.g. moved to pending), don't send — the hook
		// re-enqueues when it returns to a confirmed-purchase status.
		if ( $this->builder->map_status( (string) $order->get_status() ) === '' ) {
			return null;
		}
		return $this->builder->build( $order, $event_uuid );
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
	 * Load a WC order by id. Protected so tests can stub the lookup.
	 */
	protected function get_order( int $order_id ): ?\WC_Order {
		if ( $order_id <= 0 || ! function_exists( 'wc_get_order' ) ) {
			return null;
		}
		$order = wc_get_order( $order_id );
		return $order instanceof \WC_Order ? $order : null;
	}
}
