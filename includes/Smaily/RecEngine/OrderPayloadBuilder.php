<?php
/**
 * Single source of truth for WC_Order → rec-engine order-object mapping.
 *
 * @package Smaily\Connect\Smaily\RecEngine
 */

declare(strict_types=1);

namespace Smaily\Connect\Smaily\RecEngine;

defined( 'ABSPATH' ) || exit;

use Smaily\Connect\Smaily\RecEngine\Support\IsoDate;

/**
 * Translates a WC_Order into one entry of the `POST /api/v1/ingest/orders`
 * `orders[]` array (RECENGINE_API_CONTRACT.md §5, W5 batch contract).
 *
 * Order natural key is `(tenant_id, external_order_id)`; the customer is
 * referenced by `customer_email` (lowercased) and **auto-created engine-side**
 * if absent (W4 email identity), so guest orders need no separate
 * customer.upsert — the OrderFlusher is keyed on the order id, and guest
 * orders have order ids. Items are fully replaced on re-ingest (the order is
 * the unit); send the complete item set every time.
 *
 * Status mapping (F3-22): WC order statuses are mapped to the engine's 4-value
 * enum. completed/processing/cancelled/refunded map directly; `on-hold` maps
 * to `processing` (a placed order awaiting payment — BACS/COD/cheque — is a
 * real purchase intent, common in the pilot's market). Anything else
 * (pending / failed / draft / custom) is NOT a confirmed purchase and maps to
 * `''` — the OrderHookHandler skips those orders (and logs unknown statuses).
 * The engine requires the enum, so the builder never sends a raw WC status.
 *
 * Empty-value policy mirrors the other builders (F2-10): an OPTIONAL field
 * whose source is empty is OMITTED, never sent as "" / null. Always-present:
 * event_id, external_order_id, customer_email, ordered_at, total_amount,
 * currency, status, items.
 *
 * Datetime fields go through IsoDate (F3-21) — the engine's strict Zod
 * `.datetime()` requires the `Z` form, not `+00:00`.
 *
 * Attribution is async engine-side (N-10): this builder only forwards the
 * stored attribution signals (smaily_rec_id / smaily_visitor_token /
 * smaily_rec_ctx / session_id), stamped onto order meta at checkout by the
 * email HookHandler. The ingest response carries no attribution counts.
 *
 * Not final: tests subclass to stub WC reads. Same rationale as the other
 * PayloadBuilders.
 */
class OrderPayloadBuilder {

	/**
	 * WC order status → engine status enum. A WC status absent here is not a
	 * confirmed purchase and is not ingested (see map_status()).
	 *
	 * @var array<string, string>
	 */
	private const STATUS_MAP = array(
		'completed'  => 'completed',
		'processing' => 'processing',
		'on-hold'    => 'processing',
		'cancelled'  => 'cancelled',
		'refunded'   => 'refunded',
	);

	/** Order-meta keys the email HookHandler stamps the attribution cookies into. */
	private const META_REC_ID        = '_smaily_rec_id';
	private const META_VISITOR_TOKEN = '_smaily_visitor_token';
	private const META_REC_CTX       = '_smaily_rec_ctx';
	private const META_SESSION_ID    = '_smaily_anon_session_id';

	/**
	 * Build the order wire object for one WC_Order + its queue event_uuid.
	 *
	 * @return array<string, mixed>
	 */
	public function build( \WC_Order $order, string $event_uuid ): array {
		$payload = array(
			'event_id'          => $event_uuid,
			'external_order_id' => (string) $order->get_id(),
			// Customer reference (W4 email identity); the engine auto-creates
			// the customer from this when it doesn't exist yet.
			'customer_email'    => strtolower( trim( (string) $order->get_billing_email() ) ),
			'ordered_at'        => $this->ordered_at( $order ),
			'total_amount'      => (float) $order->get_total(),
			'currency'          => $this->currency( $order ),
			'status'            => $this->map_status( (string) $order->get_status() ),
			'items'             => $this->items( $order ),
		);

		$discount = (float) $order->get_total_discount();
		if ( $discount > 0.0 ) {
			$payload['discount_amount'] = $discount;
		}

		// Attribution signals stamped onto order meta at checkout — omitted
		// when absent (F2-10). The engine stores them; matching is async.
		$rec_id = $this->meta( $order, self::META_REC_ID );
		if ( $rec_id !== '' ) {
			$payload['smaily_rec_id'] = $rec_id;
		}
		$visitor_token = $this->meta( $order, self::META_VISITOR_TOKEN );
		if ( $visitor_token !== '' ) {
			$payload['smaily_visitor_token'] = $visitor_token;
		}
		$rec_ctx = $this->meta( $order, self::META_REC_CTX );
		if ( $rec_ctx !== '' ) {
			$payload['smaily_rec_ctx'] = $rec_ctx;
		}
		$session_id = $this->meta( $order, self::META_SESSION_ID );
		if ( $session_id !== '' ) {
			$payload['session_id'] = $session_id;
		}

		return $payload;
	}

	/**
	 * Map a WC order status to the engine enum, or '' when the status is not a
	 * confirmed purchase (pending / failed / draft) or is custom/unknown. The
	 * OrderHookHandler uses this to decide whether to enqueue at all. WC's
	 * get_status() returns the un-prefixed slug, but a 'wc-' prefix is
	 * normalised defensively.
	 */
	public function map_status( string $wc_status ): string {
		$key = ( strpos( $wc_status, 'wc-' ) === 0 ) ? substr( $wc_status, 3 ) : $wc_status;
		return self::STATUS_MAP[ $key ] ?? '';
	}

	/**
	 * The un-prefixed WC statuses that map to a non-empty engine status — the
	 * orders worth ingesting. Single source (CC-9) for the order backfill's SQL
	 * status filter (3.5.2): the enumeration filters on exactly the statuses
	 * map_status() accepts, so the two can't drift (no duplicated status list).
	 *
	 * @return string[] e.g. ['completed', 'processing', 'on-hold', 'cancelled', 'refunded'].
	 */
	public static function mapped_wc_statuses(): array {
		return array_keys( self::STATUS_MAP );
	}

	private function ordered_at( \WC_Order $order ): string {
		$date = $order->get_date_created();
		if ( ! is_object( $date ) || ! method_exists( $date, 'getTimestamp' ) ) {
			return '';
		}
		return IsoDate::to_z( (int) $date->getTimestamp() );
	}

	private function currency( \WC_Order $order ): string {
		$currency = trim( (string) $order->get_currency() );
		return $currency !== '' ? $currency : 'EUR';
	}

	/**
	 * Order line items → wire `items[]`. Shipping / fee / tax / coupon lines
	 * are skipped (only product lines), and so are SKU-less product lines: the
	 * engine keys order items on SKU (§5 `items[].sku` is required), so a
	 * SKU-less line would fail per-item validation and reject the whole order.
	 * Dropping it is safe — items[] already excludes shipping/tax/fees, so the
	 * engine never sums items to total_amount, and a SKU-less line can't be
	 * recommendation-keyed anyway. unit_price is the pre-discount per-unit
	 * price (subtotal / qty); line_total is the post-discount total; the
	 * per-line discount is subtotal − total (omitted when zero).
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function items( \WC_Order $order ): array {
		$items = array();
		foreach ( $order->get_items() as $item ) {
			if ( ! $item instanceof \WC_Order_Item_Product ) {
				continue;
			}

			$product = $item->get_product();
			$sku     = ( $product instanceof \WC_Product ) ? (string) $product->get_sku() : '';
			if ( $sku === '' ) {
				continue;
			}

			$qty      = (int) $item->get_quantity();
			$subtotal = (float) $item->get_subtotal();
			$total    = (float) $item->get_total();

			$line = array(
				'sku'        => $sku,
				'qty'        => $qty,
				'unit_price' => $qty > 0 ? round( $subtotal / $qty, 4 ) : 0.0,
				'line_total' => $total,
			);

			$discount = $subtotal - $total;
			if ( $discount > 0.0 ) {
				$line['discount_amount'] = round( $discount, 4 );
			}

			$items[] = $line;
		}

		return $items;
	}

	private function meta( \WC_Order $order, string $key ): string {
		if ( ! method_exists( $order, 'get_meta' ) ) {
			return '';
		}
		return trim( (string) $order->get_meta( $key ) );
	}
}
