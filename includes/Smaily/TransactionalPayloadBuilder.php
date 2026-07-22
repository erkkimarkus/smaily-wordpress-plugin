<?php
/**
 * Builds the Smaily message/send `context` merge-tag payload for an order.
 *
 * @package Smaily\Connect\Smaily
 */

declare(strict_types=1);

namespace Smaily\Connect\Smaily;

defined( 'ABSPATH' ) || exit;

/**
 * WC_Order → the `context` object POSTed to `message/send.php` (PRO-1504
 * Stage 2). Follows the SAME template-parity shape CartPayloadBuilder
 * established for abandoned-cart merge tags — a `product_<field>_1..10`
 * matrix (prefilled '' per slot) plus `over_10_products`, so a merchant
 * reusing similar Smaily template blocks gets consistent tag names.
 *
 * Differs from CartPayloadBuilder because the source is a placed ORDER, not
 * a live cart:
 *   - product name/quantity come from the frozen WC_Order_Item_Product
 *     snapshot (survives the product being edited/deleted later), not a
 *     live wc_get_product() read;
 *   - prices are what the customer actually PAID (the order line, gross —
 *     PRO-1241), not the product's current live price;
 *   - image/description still need a live wc_get_product() lookup (order
 *     items don't snapshot those) and are omitted when the product is gone.
 *
 * Money fields are GROSS (PRO-1241): $order->get_total() is already gross
 * (products + shipping + tax − discounts, as charged) — do NOT add tax
 * again here, unlike an order ITEM's get_total() which is net and needs
 * + get_total_tax() (see product_fields()).
 *
 * Not final: unit tests subclass to stub the price-display seam (needs a
 * real WC pricing stack), mirroring CartPayloadBuilder.
 */
class TransactionalPayloadBuilder {

	/** Template parity with CartPayloadBuilder: at most 10 product slots on the wire. */
	private const MAX_PRODUCTS = 10;

	/** Every product key is prefilled '' for slots 1..10 (legacy Smaily-template parity). */
	private const PRODUCT_KEYS = array(
		'product_name',
		'product_sku',
		'product_quantity',
		'product_price',
		'product_base_price',
		'product_description',
		'product_image_url',
	);

	/**
	 * Build the `context` object for one order.
	 *
	 * @return array<string, string>
	 */
	public function build( \WC_Order $order ): array {
		$context = array(
			'order_number'    => (string) $order->get_order_number(),
			// Already gross (products + shipping + tax − discounts, as
			// charged) — do not add get_total_tax() on top (PRO-1241).
			'order_total'     => $this->price_display( (float) $order->get_total() ),
			'currency'        => $this->currency( $order ),
			'payment_method'  => (string) $order->get_payment_method_title(),
			'shipping_method' => (string) $order->get_shipping_method(),
			'first_name'      => (string) $order->get_billing_first_name(),
			'last_name'       => (string) $order->get_billing_last_name(),
		);

		return $context + $this->product_fields( $order );
	}

	/**
	 * The `product_<field>_1..10` matrix, mirroring CartPayloadBuilder's
	 * legacy-parity shape: every slot prefilled '', filled per order line,
	 * `over_10_products` flagged past slot 10.
	 *
	 * @return array<string, string>
	 */
	private function product_fields( \WC_Order $order ): array {
		$fields = array();
		foreach ( self::PRODUCT_KEYS as $key ) {
			for ( $i = 1; $i <= self::MAX_PRODUCTS; $i++ ) {
				$fields[ $key . '_' . $i ] = '';
			}
		}

		$slot = 1;
		foreach ( $order->get_items() as $item ) {
			if ( ! $item instanceof \WC_Order_Item_Product ) {
				continue;
			}

			if ( $slot > self::MAX_PRODUCTS ) {
				$fields['over_10_products'] = 'true';
				break;
			}

			$qty = (int) $item->get_quantity();
			// GROSS (PRO-1241): the order item's get_total()/get_subtotal()
			// are NET — add the tax share, same basis as OrderPayloadBuilder.
			$total_gross    = (float) $item->get_total() + (float) $item->get_total_tax();
			$subtotal_gross = (float) $item->get_subtotal() + (float) $item->get_subtotal_tax();

			$product = $item->get_product();

			$fields[ 'product_name_' . $slot ]       = (string) $item->get_name();
			$fields[ 'product_sku_' . $slot ]        = $product instanceof \WC_Product ? (string) $product->get_sku() : '';
			$fields[ 'product_quantity_' . $slot ]   = (string) $qty;
			$fields[ 'product_price_' . $slot ]      = $qty > 0 ? $this->price_display( $total_gross / $qty ) : '';
			$fields[ 'product_base_price_' . $slot ] = $qty > 0 ? $this->price_display( $subtotal_gross / $qty ) : '';
			$fields[ 'product_description_' . $slot ] = $product instanceof \WC_Product ? (string) $product->get_description() : '';
			$fields[ 'product_image_url_' . $slot ]   = $product instanceof \WC_Product ? $this->product_image_url( $product ) : '';

			++$slot;
		}

		return $fields;
	}

	/**
	 * Display-formatted price (currency-symboled, HTML-tag-stripped) — ready
	 * to drop straight into an email merge tag. Protected seam: needs a real
	 * WC pricing stack, so unit tests stub it (mirrors CartPayloadBuilder).
	 */
	protected function price_display( float $amount ): string {
		$price = wc_price( $amount );

		return wp_strip_all_tags( html_entity_decode( $price, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML401 ) );
	}

	/**
	 * Featured image, else first gallery image (CartPayloadBuilder parity).
	 */
	protected function product_image_url( \WC_Product $product ): string {
		$image_id = (int) $product->get_image_id();
		if ( $image_id > 0 ) {
			$url = wp_get_attachment_url( $image_id );
			if ( is_string( $url ) && $url !== '' ) {
				return $url;
			}
		}

		$gallery = $product->get_gallery_image_ids();
		if ( is_array( $gallery ) && $gallery !== array() ) {
			$url = wp_get_attachment_url( (int) $gallery[0] );
			if ( is_string( $url ) && $url !== '' ) {
				return $url;
			}
		}

		return '';
	}

	private function currency( \WC_Order $order ): string {
		$currency = trim( (string) $order->get_currency() );
		return $currency !== '' ? $currency : 'EUR';
	}
}
