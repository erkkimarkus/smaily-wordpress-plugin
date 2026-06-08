<?php
/**
 * Backfill every published WooCommerce product into the rec-engine catalog.
 *
 * @package Smaily\Connect\Smaily\RecEngine\Backfill
 */

declare(strict_types=1);

namespace Smaily\Connect\Smaily\RecEngine\Backfill;

defined( 'ABSPATH' ) || exit;

use Smaily\Connect\Integrations\WooCommerce\CatalogHookHandler;
use Smaily\Connect\Smaily\RecEngine\CatalogPayloadBuilder;
use Smaily\Connect\Smaily\RecEngine\IngestFlusher;
use Smaily\Connect\Smaily\RecEngine\IngestQueue;

/**
 * The cursor walks PARENT products (post_type=product, publish) by ascending
 * ID; each is expanded into its ingest units the same way
 * CatalogHookHandler::on_save_product does (a variable product fans out to its
 * variations), so backfill and live ingest enqueue identical rows. Products
 * live in wp_posts regardless of HPOS (HPOS is orders-only), so a direct
 * id-cursor query is safe here.
 */
class CatalogBackfillJob extends AbstractBackfillJob {

	private CatalogPayloadBuilder $builder;

	public function __construct( IngestQueue $queue, IngestFlusher $flusher, CatalogPayloadBuilder $builder ) {
		parent::__construct( $queue, $flusher );
		$this->builder = $builder;
	}

	public function job_type(): string {
		return 'products';
	}

	protected function batch_size(): int {
		return 100;
	}

	protected function count_total(): int {
		global $wpdb;
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s AND post_status = %s",
				'product',
				'publish'
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	/**
	 * @return int[]
	 */
	protected function fetch_ids_after( int $after_id, int $limit ): array {
		global $wpdb;
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_status = %s AND ID > %d ORDER BY ID ASC LIMIT %d",
				'product',
				'publish',
				$after_id,
				$limit
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		return array_map( 'intval', $ids );
	}

	protected function enqueue_record( int $entity_id ): void {
		if ( ! function_exists( 'wc_get_product' ) ) {
			return;
		}
		$product = wc_get_product( $entity_id );
		if ( ! $product instanceof \WC_Product ) {
			return;
		}

		// Same fan-out as the live hook: a variable product enqueues one row per
		// variation unit; a simple product enqueues itself.
		foreach ( $this->builder->expand( $product ) as $unit ) {
			$this->queue->enqueue( CatalogHookHandler::EVENT_CATALOG_UPSERT, (string) $unit->get_id(), array() );
		}
	}
}
