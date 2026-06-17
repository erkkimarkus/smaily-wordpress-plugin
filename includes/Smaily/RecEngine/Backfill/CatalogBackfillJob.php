<?php
/**
 * Backfill published (and trashed) WooCommerce products into the rec-engine
 * catalog.
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
 * The cursor walks PARENT products (post_type=product, status publish OR trash)
 * by ascending ID; each is expanded into its ingest units the same way
 * CatalogHookHandler::on_save_product does (a variable product fans out to its
 * variations), so backfill and live ingest enqueue identical rows. Products
 * live in wp_posts regardless of HPOS (HPOS is orders-only), so a direct
 * id-cursor query is safe here.
 *
 * Trashed products are enumerated too (not just publish): a product a customer
 * once bought but the merchant later trashed must STAY in the engine catalog so
 * its order-history join (and model training) survives — sent as in_stock=false
 * so it can't be recommended, never dropped (the engine has no delete-by-absence;
 * RECENGINE_API_CONTRACT.md §3). A published post enqueues a catalog.upsert (the
 * flusher loads it fresh, real stock); a trashed post enqueues a catalog.delete
 * carrying its captured object (the flusher stamps in_stock=false), guarded by
 * CatalogHookHandler::is_removable so a removal the engine would 400 (blank
 * category_path / product_url) is skipped. Permanently-deleted products can't be
 * recovered here — their data is gone from WC — so this closes the trash gap, not
 * the hard-delete one.
 *
 * Multilingual collapse (catalog-correctness P1): the WHERE clause still
 * enumerates every translation post (WPML/Polylang store each as its own
 * product row), but enqueue_record COLLAPSES them — a translation whose
 * canonical (default-language) post is itself a PUBLISHED product is SKIPPED,
 * so each real product is enqueued exactly once under its stable
 * `wc-{canonical_id}` key. The collapse keys on a *published* canonical only: a
 * still-published translation of a trashed default-language product is therefore
 * kept (it stands in as in_stock=true), never masked by the trashed canonical.
 * A fully-trashed multilingual product may emit a harmless duplicate
 * in_stock=false per language (idempotent on the engine's SKU upsert). The
 * cursor/processed_count still counts every enumerated post (so progress reaches
 * 100%); the SENT count is lower by design — that gap IS the collapse. Stateless:
 * each post decides independently, so it holds across batches without a dedupe set.
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
				"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s AND post_status IN ( %s, %s )",
				'product',
				'publish',
				'trash'
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
				"SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_status IN ( %s, %s ) AND ID > %d ORDER BY ID ASC LIMIT %d",
				'product',
				'publish',
				'trash',
				$after_id,
				$limit
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		return array_map( 'intval', $ids );
	}

	protected function enqueue_record( int $entity_id ): void {
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
		$product = $this->get_product( $entity_id );
		if ( $product === null ) {
			return;
		}

		// A trashed product stays in the catalog as in_stock=false (kept for the
		// order-history join / training) instead of an upsert; a published one
		// upserts with its real stock. Decided per parent post — every expanded
		// unit inherits it.
		$is_trashed = $this->post_status( $entity_id ) === 'trash';

		// Same fan-out as the live hook: a variable product enqueues one row per
		// variation unit; a simple product enqueues itself.
		foreach ( $this->builder->expand( $product ) as $unit ) {
			if ( $is_trashed ) {
				$this->enqueue_unavailable( $unit );
				continue;
			}
			$this->queue->enqueue( CatalogHookHandler::EVENT_CATALOG_UPSERT, (string) $unit->get_id(), array() );
		}
	}

	/**
	 * Enqueue a trashed unit as an in_stock=false removal: capture its object now
	 * (a trashed post is still loadable) and route it as catalog.delete — the
	 * flusher stamps in_stock=false, so the engine keeps the SKU (no delete-by-key)
	 * and the order-history join survives. Skip a removal the engine is
	 * contract-guaranteed to 400 (blank category_path / product_url), the SAME
	 * guard the live delete hook applies (CatalogHookHandler::is_removable).
	 */
	private function enqueue_unavailable( \WC_Product $unit ): void {
		$object = $this->builder->build( $unit, '' );
		if ( ! CatalogHookHandler::is_removable( $object ) ) {
			return;
		}
		$this->queue->enqueue( CatalogHookHandler::EVENT_CATALOG_DELETE, (string) $unit->get_id(), array( 'object' => $object ) );
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

	/**
	 * Load a product by id (publish or trash — both are loadable). Protected so
	 * tests can stub the lookup without a real WC_Product_Factory.
	 */
	protected function get_product( int $product_id ): ?\WC_Product {
		if ( $product_id <= 0 || ! function_exists( 'wc_get_product' ) ) {
			return null;
		}
		$product = wc_get_product( $product_id );
		return $product instanceof \WC_Product ? $product : null;
	}

	/**
	 * The post's status slug ('publish' / 'trash'). Protected so tests can drive
	 * the upsert-vs-unavailable branch without WordPress.
	 */
	protected function post_status( int $post_id ): string {
		return (string) get_post_status( $post_id );
	}
}
