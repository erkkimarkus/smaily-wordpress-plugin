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
use Smaily\Connect\Multilingual\DetectorInterface;
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
 *
 * Multilingual collapse (catalog-correctness P1): the WHERE clause still
 * enumerates every translation post (WPML/Polylang store each as its own
 * product row), but enqueue_record COLLAPSES them — a translation whose
 * canonical (default-language) post is itself a published product is SKIPPED,
 * so each real product is enqueued exactly once under its stable
 * `wc-{canonical_id}` key. The cursor/processed_count still counts every
 * enumerated post (so progress reaches 100%); the SENT count is lower by
 * design — that gap IS the collapse. Stateless: each post decides
 * independently, so it holds across batches without carrying a dedupe set.
 */
class CatalogBackfillJob extends AbstractBackfillJob {

	private CatalogPayloadBuilder $builder;
	private DetectorInterface $detector;

	public function __construct( IngestQueue $queue, IngestFlusher $flusher, CatalogPayloadBuilder $builder, DetectorInterface $detector ) {
		parent::__construct( $queue, $flusher );
		$this->builder  = $builder;
		$this->detector = $detector;
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

		$canonical_id = $this->detector->get_canonical_post_id( $entity_id );
		if ( $canonical_id > 0 && $canonical_id !== $entity_id && $this->canonical_is_enumerated( $canonical_id ) ) {
			// A translation whose canonical (default-language) post is itself a
			// published product in this walk — the canonical enqueues itself on
			// its own cursor step, so skip this one to avoid a duplicate SKU.
			return;
		}

		// Not skipped → ingest THIS post: either it IS the canonical (the common
		// multilingual case — translations were skipped above), or its canonical
		// isn't separately enumerable (draft / trashed default-language post) so
		// this published post stands in rather than being dropped (LESSONS
		// §2.11). The SKU is canonicalized by SkuResolver regardless, so the row
		// still keys on `wc-{canonical_id}`.
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

	/**
	 * Whether $canonical_id is itself a published product — i.e. it appears in
	 * this backfill's enumeration and will enqueue itself. Only then is it safe
	 * to skip a translation pointing at it; otherwise skipping would drop the
	 * product entirely (the default-language post is draft/trashed but a
	 * translation is published).
	 */
	protected function canonical_is_enumerated( int $canonical_id ): bool {
		return get_post_status( $canonical_id ) === 'publish'
			&& get_post_type( $canonical_id ) === 'product';
	}
}
