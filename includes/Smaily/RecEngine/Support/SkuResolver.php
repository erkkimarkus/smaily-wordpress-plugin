<?php
/**
 * Single source of truth for the rec-engine product key (wire `sku`).
 *
 * @package Smaily\Connect\Smaily\RecEngine\Support
 */

declare(strict_types=1);

namespace Smaily\Connect\Smaily\RecEngine\Support;

defined( 'ABSPATH' ) || exit;

use Smaily\Connect\Multilingual\DetectorFactory;
use Smaily\Connect\Multilingual\DetectorInterface;

/**
 * Resolves the engine-side product key for a WC product or order line: the
 * merchant's real SKU when one is set, otherwise a synthetic `wc-{id}` key
 * built from the WooCommerce product/variation id.
 *
 * Why this exists (F3-36): the engine keys the ENTIRE pipeline on `sku` —
 * catalog natural key `(tenant_id, sku)`, order `items[].sku` (required),
 * browse `product_view`/`cart_*` (required) — but WooCommerce does not require
 * SKUs and the pilot store has none at all. Before this helper the SKU-less
 * case degraded each surface differently and worst of all silently: catalog
 * units were dropped before enqueue (no Event Log trace — the engine simply
 * never saw the product), orders whose every line was SKU-less were sent with
 * an empty `items[]` and D6-rejected wholesale, and the beacon omitted `sku`
 * so the engine rejected the browse event. One resolver used by ALL THREE
 * surfaces keeps the key consistent — the same product yields the same key in
 * catalog, order items, and browse events, which is what makes the engine's
 * joins work.
 *
 * Multilingual canonicalization (catalog-correctness CC.2): on a WPML/Polylang
 * store each translation is its own product post with its own id, so a
 * SYNTHETIC key would differ per language (`wc-{et_id}`, `wc-{lv_id}`, …) and
 * the engine would see the same product N times — duplicate SKUs it cannot
 * dedupe (RECENGINE_API_CONTRACT.md §3). Because this resolver is the single
 * chokepoint for all three surfaces, canonicalizing HERE makes catalog, order
 * items, AND browse all key the same product to the same canonical SKU. The id
 * is mapped to its default-language post via the active DetectorInterface
 * (`get_canonical_post_id`); a real SKU is the merchant's own canonical key and
 * is returned untouched. Single-language sites resolve every id to itself
 * (passthrough), so this is a no-op there. The detector defaults to the active
 * one (DetectorFactory) — callers may inject one (tests, determinism).
 *
 * Synthetic-key properties: `wc-{canonical_id}` is stable for the product's
 * lifetime and fits the engine's 64-char cap (ids are ints). Deleted products:
 * current WC ZEROES the order items' product/variation reference on permanent
 * deletion (verified empirically on WC 10.7 — `_product_id` becomes 0). When the
 * id survives, the line keys `wc-{canonical_id}` and ingests; when it's zeroed,
 * resolve_order_item() keys the line on the order-item id (`wc-oi-{item_id}`) so
 * the line is NEVER dropped — an order must never silently vanish for one
 * unkeyable line (F3-43, reversing F3-36's drop+terminal-skip). The fallback
 * key won't match a catalog row (no item-level inference), but the order still
 * ingests. (A deleted post may not resolve a canonical via the detector → it
 * keys `wc-{stored_id}`, the same lossy-history case F3-36 already documents.)
 *
 * Documented trade-off (F3-36): if a merchant later assigns a real SKU to a
 * product that already ingested under `wc-{id}`, the key changes — the
 * catalog gets a fresh entry and subsequent order/browse history accrues to
 * the new key (the old entry goes stale; the engine has no key-rename). For a
 * store adopting SKUs wholesale that is a one-time history split, accepted
 * over a tenant-level key-mode setting (more code, identical output for the
 * SKU-less pilot).
 */
final class SkuResolver {

	/** Prefix for synthetic keys so they are recognisable in engine data. */
	public const SYNTHETIC_PREFIX = 'wc-';

	/**
	 * Engine key for a loadable product/variation: its SKU, else the synthetic
	 * `wc-{canonical_id}` (the id collapsed to its default-language post).
	 *
	 * @param DetectorInterface|null $detector Multilingual detector; defaults to
	 *        the active one. Inject for tests / call-site determinism.
	 */
	public static function resolve( \WC_Product $product, ?DetectorInterface $detector = null ): string {
		$sku = (string) $product->get_sku();
		if ( $sku !== '' ) {
			return $sku;
		}
		return self::SYNTHETIC_PREFIX . (string) self::canonical_id( (int) $product->get_id(), $detector );
	}

	/**
	 * Engine key for an order line whose product no longer loads (deleted):
	 * synthesised from the id WC stored on the line item at purchase time.
	 * Prefers the variation id (catalog ingests variations as the units) and
	 * falls back to the parent product id. The id is canonicalized so an order
	 * placed against a translated product keys the SAME `wc-{canonical_id}` the
	 * catalog ingested (engine-side join).
	 *
	 * NEVER returns '' (F3-43): when current WC has zeroed BOTH ids on permanent
	 * deletion, this keys the line on the order-item id (`wc-oi-{item_id}`) — a
	 * unique, non-empty fallback so the line is never dropped and the whole order
	 * never silently vanishes for one unkeyable line (engine brief 2026-06-19).
	 * That key won't match a catalog row, so item-level inference can't apply,
	 * but the order still ingests (RFM / tier) — the accepted trade-off, because
	 * the order surviving is what matters. This supersedes F3-36's "unkeyable
	 * line is dropped → all-deleted order is terminal-skipped" for deleted lines;
	 * the flusher's empty-items skip now only guards a genuinely product-less
	 * order (only shipping/fee lines).
	 *
	 * @param DetectorInterface|null $detector Multilingual detector; defaults to
	 *        the active one. Inject for tests / call-site determinism.
	 */
	public static function resolve_order_item( \WC_Order_Item_Product $item, ?DetectorInterface $detector = null ): string {
		$id = (int) $item->get_variation_id();
		if ( $id <= 0 ) {
			$id = (int) $item->get_product_id();
		}
		if ( $id <= 0 ) {
			// Deleted product, ids zeroed — key on the order-item id (always > 0
			// on a saved line) so the order is never dropped. (F3-43.)
			return self::SYNTHETIC_PREFIX . 'oi-' . (string) $item->get_id();
		}
		return self::SYNTHETIC_PREFIX . (string) self::canonical_id( $id, $detector );
	}

	/**
	 * Collapse a product/variation id to its canonical (default-language) post
	 * id via the detector. Falls back to the input id when the detector can't
	 * resolve one (single-language site, unlinked variation, deleted post) — a
	 * degraded per-language key beats dropping the surface.
	 */
	private static function canonical_id( int $id, ?DetectorInterface $detector ): int {
		$detector  = $detector ?? DetectorFactory::create();
		$canonical = $detector->get_canonical_post_id( $id );
		return $canonical > 0 ? $canonical : $id;
	}
}
