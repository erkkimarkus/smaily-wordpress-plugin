<?php
/**
 * Single source of truth for the rec-engine product key (wire `sku`).
 *
 * @package Smaily\Connect\Smaily\RecEngine\Support
 */

declare(strict_types=1);

namespace Smaily\Connect\Smaily\RecEngine\Support;

defined( 'ABSPATH' ) || exit;

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
 * Synthetic-key properties: `wc-{id}` is stable for the product's lifetime
 * and fits the engine's 64-char cap (ids are ints). Deleted products: current
 * WC ZEROES the order items' product/variation reference on permanent
 * deletion (verified empirically on WC 10.7 — `_product_id` becomes 0), so
 * those lines are unkeyable → resolve_order_item() returns '' → the line
 * drops and an all-deleted order is terminal-skipped by OrderFlusher. On a
 * WC version where the id survives, the line keys `wc-{id}` and ingests —
 * both outcomes are correct; neither is a send-and-fail.
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
	 * Engine key for a loadable product/variation: its SKU, else `wc-{id}`.
	 */
	public static function resolve( \WC_Product $product ): string {
		$sku = (string) $product->get_sku();
		if ( $sku !== '' ) {
			return $sku;
		}
		return self::SYNTHETIC_PREFIX . (string) $product->get_id();
	}

	/**
	 * Engine key for an order line whose product no longer loads (deleted):
	 * synthesised from the id WC stored on the line item at purchase time.
	 * Prefers the variation id (catalog ingests variations as the units) and
	 * falls back to the parent product id. Returns '' when the item carries
	 * no usable id — the caller must drop such a line, it cannot be keyed.
	 * NB: on current WC that is the COMMON deleted-product outcome (permanent
	 * deletion zeroes the reference — see the class docblock); the id-survives
	 * path exists for WC versions/data where the reference is intact.
	 */
	public static function resolve_order_item( \WC_Order_Item_Product $item ): string {
		$id = (int) $item->get_variation_id();
		if ( $id <= 0 ) {
			$id = (int) $item->get_product_id();
		}
		if ( $id <= 0 ) {
			return '';
		}
		return self::SYNTHETIC_PREFIX . (string) $id;
	}
}
