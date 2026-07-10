<?php
/**
 * WC product hooks → rec-engine catalog ingest queue.
 *
 * @package Smaily\Connect\Integrations\WooCommerce
 */

declare(strict_types=1);

namespace Smaily\Connect\Integrations\WooCommerce;

defined( 'ABSPATH' ) || exit;

use Smaily\Connect\Multilingual\DetectorInterface;
use Smaily\Connect\Settings\RecEngineSettings;
use Smaily\Connect\Smaily\RecEngine\CatalogPayloadBuilder;
use Smaily\Connect\Smaily\RecEngine\IngestQueue;

/**
 * Fans WooCommerce product changes into the rec-engine ingest queue
 * (PLUGIN_IMPLEMENTATION_WP.md §452): save / stock-change → catalog.upsert,
 * delete → catalog.delete. The IngestFlusher later turns each queued row
 * into a /api/v1/ingest/catalog object.
 *
 * Variable products fan out: each variation is its own ingest unit with
 * its own queue row and event_uuid (CatalogPayloadBuilder::expand), so the
 * engine dedups variations independently. SKU-less units are NOT dropped —
 * SkuResolver keys every unit `woo-{id}` (the platform id, never the merchant
 * SKU field) in the builder (PRO-1224).
 *
 * Multilingual collapse (catalog-correctness P1): Polylang/WPML store each
 * translation as its own product post, so a save/stock/delete arriving for a
 * translation is first mapped to its CANONICAL (default-language) product via
 * the DetectorInterface — we always enqueue the canonical so its
 * `woo-{canonical_id}` key is stable across languages (one engine row per real
 * product, RECENGINE_API_CONTRACT.md §3). Single-language sites resolve every
 * post to itself (passthrough), so this is a no-op there.
 *
 * Gate: enqueue only while a tenant is connected (RecEngineSettings::
 * is_connected). Unlike the email HookHandler's setup_completed gate (which
 * exists to avoid double-sync with the legacy plugin), catalog ingest has
 * no legacy counterpart — the only question is whether there's a tenant to
 * send to.
 *
 * Per-request dedupe: save_post_product can fire repeatedly within one
 * request; a static $seen set collapses repeats to a single row per unit.
 * Because every save resolves to the canonical product first, repeated saves
 * of different translations collapse to the same canonical units too.
 *
 * Removal capture (trash + permanent delete): the product is still loadable
 * in both wp_trash_post and before_delete_post, so the full catalog object is
 * built and stored in the row now; the flusher stamps in_stock=false + event_id
 * at send time (it can't load a gone product). This is NOT a hard removal — the
 * engine has no delete-by-key, so a catalog.delete row is sent as an UPSERT with
 * in_stock=false (RECENGINE_API_CONTRACT.md §3, "Removal is explicit: re-send
 * with in_stock=false"). The SKU row therefore SURVIVES in the engine catalog so
 * its order-history join (and model training) is preserved — the product is just
 * marked unavailable, so it can't be recommended. Trashing and untrashing share
 * this path: trash → on_delete_product (in_stock=false), untrash → on_save_product
 * (re-sync real stock). Removing a TRANSLATION must NOT remove the canonical SKU
 * (P4) — see on_delete_product.
 *
 * Not final: tests subclass to stub get_product() while recording enqueues
 * through a doubled IngestQueue.
 */
class CatalogHookHandler {

	public const EVENT_CATALOG_UPSERT = 'catalog.upsert';
	public const EVENT_CATALOG_DELETE = 'catalog.delete';

	/** @var array<string, bool> per-request dedupe keyed by "{event}:{product_id}". */
	private static array $seen = array();

	private IngestQueue $queue;
	private CatalogPayloadBuilder $builder;
	private RecEngineSettings $settings;
	private DetectorInterface $detector;

	public function __construct( IngestQueue $queue, CatalogPayloadBuilder $builder, RecEngineSettings $settings, DetectorInterface $detector ) {
		$this->queue    = $queue;
		$this->builder  = $builder;
		$this->settings = $settings;
		$this->detector = $detector;
	}

	public function on_save_product( int $post_id ): void {
		if ( ! $this->gate_open() ) {
			return;
		}
		// Trashing a product fires save_post (wp_trash_post → wp_update_post) AFTER
		// wp_trash_post already enqueued the in_stock=false removal — re-syncing
		// here would clobber it back to in_stock=true. The trash/delete path owns a
		// trashed post; skip it. (Untrash restores the status FIRST, then fires
		// untrashed_post → here, so a restored product is correctly NOT skipped.)
		if ( $this->post_status( $post_id ) === 'trash' ) {
			return;
		}
		// Saving any translation re-syncs the CANONICAL product, so its
		// `wc-{canonical_id}` row stays single and current across languages (P1).
		$product = $this->canonical_product( $post_id );
		if ( $product === null ) {
			return;
		}
		foreach ( $this->builder->expand( $product ) as $unit ) {
			$this->enqueue_upsert( $unit );
		}
	}

	/**
	 * woocommerce_product_set_stock_status fires ($product_id, $stock_status,
	 * $product). A stock flip is just a re-sync of the product.
	 *
	 * @param int|string            $product_id
	 * @param string                $stock_status
	 * @param \WC_Product|null|mixed $product
	 */
	public function on_stock_change( $product_id, $stock_status = '', $product = null ): void {
		if ( ! $this->gate_open() ) {
			return;
		}
		$id     = ( $product instanceof \WC_Product ) ? (int) $product->get_id() : (int) $product_id;
		$loaded = $this->canonical_product( $id );
		if ( $loaded === null ) {
			return;
		}
		foreach ( $this->builder->expand( $loaded ) as $unit ) {
			$this->enqueue_upsert( $unit );
		}
	}

	/**
	 * A product left the catalog — trashed (wp_trash_post) or permanently
	 * deleted (before_delete_post). Both routes land here because the engine
	 * treats removal identically: an in_stock=false UPSERT that keeps the SKU
	 * row (so order-history joins / training survive), never a hard delete. The
	 * product is still loadable in both hooks, so enqueue_delete captures it.
	 */
	public function on_delete_product( int $post_id ): void {
		if ( ! $this->gate_open() ) {
			return;
		}

		// P4: removing a TRANSLATION must NOT remove the canonical SKU — the
		// product still exists in other languages. Re-sync the canonical
		// (upsert) so its content drops the removed language instead of marking
		// the whole product unavailable. Only the canonical's own removal (or
		// a single-language product) marks the row in_stock=false.
		$canonical_id = $this->detector->get_canonical_post_id( $post_id );
		if ( $canonical_id > 0 && $canonical_id !== $post_id ) {
			$canonical = $this->get_product( $canonical_id );
			if ( $canonical !== null ) {
				foreach ( $this->builder->expand( $canonical ) as $unit ) {
					$this->enqueue_upsert( $unit );
				}
				return;
			}
			// Canonical gone too → fall through and mark this post's units gone.
		}

		$product = $this->get_product( $post_id );
		if ( $product === null ) {
			// Not a product (before_delete_post fires for every post type) or
			// already gone — nothing to mark unavailable.
			return;
		}
		foreach ( $this->builder->expand( $product ) as $unit ) {
			$this->enqueue_delete( $unit );
		}
	}

	/**
	 * Resolve a (possibly translated) product post to its canonical
	 * default-language product, loading it. Falls back to the saved post itself
	 * when the canonical can't be loaded — never drops a save silently.
	 */
	private function canonical_product( int $post_id ): ?\WC_Product {
		$canonical_id = $this->detector->get_canonical_post_id( $post_id );
		if ( $canonical_id <= 0 ) {
			$canonical_id = $post_id;
		}
		$product = $this->get_product( $canonical_id );
		if ( $product === null && $canonical_id !== $post_id ) {
			$product = $this->get_product( $post_id );
		}
		return $product;
	}

	/**
	 * Reset the per-request dedupe set. Tests use it between cases; production
	 * never calls it (the static is request-scoped).
	 */
	public static function reset_seen(): void {
		self::$seen = array();
	}

	private function enqueue_upsert( \WC_Product $unit ): void {
		// No SKU guard here (F3-36): SkuResolver keys SKU-less units
		// synthetically in the builder, so every expanded unit is ingestable.
		if ( $this->already_seen( self::EVENT_CATALOG_UPSERT, (int) $unit->get_id() ) ) {
			return;
		}
		// Empty payload — the flusher loads the product fresh by entity_id so
		// the engine gets current state at send time.
		$this->queue->enqueue( self::EVENT_CATALOG_UPSERT, (string) $unit->get_id(), array() );
	}

	private function enqueue_delete( \WC_Product $unit ): void {
		// No SKU guard here either (F3-36) — see enqueue_upsert().
		if ( $this->already_seen( self::EVENT_CATALOG_DELETE, (int) $unit->get_id() ) ) {
			return;
		}
		// Capture the full object now (still loadable); event_uuid is generated
		// at enqueue, so the flusher stamps event_id + in_stock=false at send.
		$object = $this->builder->build( $unit, '' );

		// Skip a removal the engine is contract-guaranteed to 400: the engine has
		// no delete-by-key — removal is an UPSERT with in_stock=false that must
		// pass ProductSchema (category_path + product_url are REQUIRED non-empty,
		// RECENGINE_API_CONTRACT.md §3). A never-published artifact (auto-draft,
		// abandoned draft) has them empty and was never ingested anyway (the
		// backfill is publish-only) — there is nothing to remove. WordPress's
		// daily auto-draft GC fires before_delete_post for piles of these at once;
		// without this guard each becomes a permanently-failed d6_item_error row
		// (the catalog.delete burst Erkki saw, 2026-06-14). Skipping silently
		// mirrors the non-product early-return in on_delete_product().
		//
		// NOT applied to the upsert path: an empty category_path on a PUBLISHED
		// product is an intended merchant-data-gap signal the engine surfaces via
		// the Event Log — see CatalogPayloadBuilder::primary_category_path().
		if ( ! self::is_removable( $object ) ) {
			return;
		}

		$this->queue->enqueue( self::EVENT_CATALOG_DELETE, (string) $unit->get_id(), array( 'object' => $object ) );
	}

	/**
	 * Whether a captured catalog object carries the engine's REQUIRED non-empty
	 * removal fields (category_path + product_url). product_url may be the
	 * multilingual `{lang: value}` object form, so an empty array counts as blank
	 * just like an empty string.
	 *
	 * Public + static so the catalog backfill reuses the SAME guard when it
	 * sends a trashed product as in_stock=false — a removal object the engine is
	 * contract-guaranteed to 400 (blank category_path / product_url) must be
	 * skipped on BOTH paths, not just the live hook.
	 *
	 * @param array<string, mixed> $object
	 */
	public static function is_removable( array $object ): bool {
		$category_path = (string) ( $object['category_path'] ?? '' );
		if ( $category_path === '' ) {
			return false;
		}
		$product_url = $object['product_url'] ?? '';
		$has_url     = is_array( $product_url ) ? $product_url !== array() : (string) $product_url !== '';
		return $has_url;
	}

	private function already_seen( string $event_type, int $product_id ): bool {
		$key = $event_type . ':' . $product_id;
		if ( isset( self::$seen[ $key ] ) ) {
			return true;
		}
		self::$seen[ $key ] = true;
		return false;
	}

	private function gate_open(): bool {
		return $this->settings->is_connected();
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

	/**
	 * The post's status slug ('publish' / 'trash' / …). Protected so tests can
	 * drive the save-during-trashing guard without WordPress.
	 */
	protected function post_status( int $post_id ): string {
		return (string) get_post_status( $post_id );
	}
}
