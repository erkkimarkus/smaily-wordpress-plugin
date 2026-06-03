<?php
/**
 * Drains the rec-engine ingest queue and ships each batch to the engine.
 *
 * @package Smaily\Connect\Smaily\RecEngine
 */

declare(strict_types=1);

namespace Smaily\Connect\Smaily\RecEngine;

defined( 'ABSPATH' ) || exit;

use Smaily\Connect\Integrations\WooCommerce\CatalogHookHandler;
use Smaily\Connect\Settings\RecEngineSettings;

/**
 * Action Scheduler callback for `smly_rec_flush_ingest`. Reads a batch of
 * due rows from IngestQueue, turns each into a catalog wire object, POSTs
 * the whole batch through Client::ingest_catalog, and advances every row
 * to its terminal/retry state.
 *
 * Per-row → wire object:
 *   - catalog.upsert : load the product FRESH by entity_id and build it, so
 *     the engine always gets the latest state (a product edited several
 *     times before the flush sends one current snapshot per row). A product
 *     that vanished since enqueue is a terminal skip — a catalog.delete row
 *     will follow.
 *   - catalog.delete : the product is already gone, so the full object was
 *     captured at delete time in the row payload; we stamp in_stock=false
 *     and the row's event_uuid as event_id.
 *
 * event_uuid → event_id is applied on every object (CatalogPayloadBuilder
 * for upserts, here for deletes) so queue.event_uuid == body.event_id holds
 * and the engine can dedup a retried row.
 *
 * Response handling (the contract the flush job depends on):
 *   - 2xx, including 200 {"deduplicated": true} — the whole batch is SENT.
 *     A deduplicated body means a retry re-sent rows the engine already
 *     has; that's success, never a re-retry.
 *   - ApiException with a terminal 4xx (not 429) — the batch is malformed
 *     or the key is revoked; retrying won't help, so mark_failed.
 *   - ApiException otherwise (429 / 5xx exhausted / network) — row-level
 *     retry via record_attempt + next_retry_at, until max_attempts, then
 *     mark_failed. The Client runs only 1-2 in-request attempts (it must
 *     not block the AS worker on long backoff); durability lives in the
 *     queue.
 *
 * Not final: tests subclass to stub get_product() (the WC lookup) while
 * driving flush() through doubled queue / builder / client collaborators.
 */
class IngestFlusher {

	/** Spec-conservative batch ceiling (engine tolerates far more). */
	public const DEFAULT_BATCH_SIZE = 100;

	/**
	 * Row-level retry backoff per attempt number (seconds): 1m, 5m, 15m,
	 * 1h, 6h. The AS recurring flush re-ticks and pending() only returns
	 * rows whose next_retry_at has passed.
	 *
	 * @var array<int, int>
	 */
	private const RETRY_BACKOFF = array( 60, 300, 900, 3600, 21600 );

	private IngestQueue $queue;
	private CatalogPayloadBuilder $builder;
	private RecEngineSettings $settings;

	/** @var callable(): Client */
	private $client_factory;

	/**
	 * @param callable(): Client $client_factory Builds a rec-engine Client from the stored
	 *                                          tenant config (with a small max_attempts).
	 */
	public function __construct(
		IngestQueue $queue,
		CatalogPayloadBuilder $builder,
		RecEngineSettings $settings,
		callable $client_factory
	) {
		$this->queue          = $queue;
		$this->builder        = $builder;
		$this->settings       = $settings;
		$this->client_factory = $client_factory;
	}

	/**
	 * Process up to $batch_size due rows.
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

		// No tenant → nothing can be authenticated; leave rows pending for
		// when the merchant (re)connects.
		if ( ! $this->settings->is_connected() ) {
			return $stats;
		}

		$rows = $this->queue->pending( $batch_size );
		if ( $rows === array() ) {
			return $stats;
		}

		$products   = array();
		$batch_rows = array();
		foreach ( $rows as $row ) {
			++$stats['processed'];
			$id      = (int) ( $row['id'] ?? 0 );
			$product = $this->row_to_object( $row );

			if ( $product === null ) {
				// Terminal skip (product gone for an upsert, or undecodable
				// payload). Mark sent so it leaves the queue — a retry can't
				// recover either case.
				$this->queue->mark_sent( $id );
				++$stats['skipped'];
				continue;
			}

			$products[]   = $product;
			$batch_rows[] = $row;
		}

		if ( $products === array() ) {
			return $stats;
		}

		try {
			( $this->client_factory )()->ingest_catalog( $products );
			foreach ( $batch_rows as $row ) {
				$this->queue->mark_sent( (int) ( $row['id'] ?? 0 ) );
				++$stats['sent'];
			}
		} catch ( ApiException $e ) {
			$terminal = $this->is_terminal( $e );
			foreach ( $batch_rows as $row ) {
				$this->handle_failure( $row, $e, $terminal, $stats );
			}
		}

		return $stats;
	}

	/**
	 * @param array<string, mixed>                                           $row
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
	 * Map a queue row to its catalog wire object, or null for a terminal skip.
	 *
	 * @param array<string, mixed> $row
	 *
	 * @return array<string, mixed>|null
	 */
	private function row_to_object( array $row ): ?array {
		$event_uuid = (string) ( $row['event_uuid'] ?? '' );
		$event_type = (string) ( $row['event_type'] ?? '' );

		if ( $event_type === CatalogHookHandler::EVENT_CATALOG_DELETE ) {
			$payload = $this->decode_payload( (string) ( $row['payload'] ?? '' ) );
			$object  = ( isset( $payload['object'] ) && is_array( $payload['object'] ) ) ? $payload['object'] : null;
			if ( $object === null ) {
				return null;
			}
			$object['event_id'] = $event_uuid;
			$object['in_stock'] = false;
			return $object;
		}

		// catalog.upsert — load fresh so the engine gets current state.
		$product = $this->get_product( (int) ( $row['entity_id'] ?? 0 ) );
		if ( $product === null ) {
			return null;
		}
		return $this->builder->build( $product, $event_uuid );
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
	 * @return array<string, mixed>
	 */
	private function decode_payload( string $json ): array {
		if ( $json === '' ) {
			return array();
		}
		$decoded = json_decode( $json, true );
		return is_array( $decoded ) ? $decoded : array();
	}

	/**
	 * Load a product by id. Protected so tests can stub the lookup.
	 */
	protected function get_product( int $product_id ): ?\WC_Product {
		if ( $product_id <= 0 || ! function_exists( 'wc_get_product' ) ) {
			return null;
		}
		$product = wc_get_product( $product_id );
		return $product instanceof \WC_Product ? $product : null;
	}
}
