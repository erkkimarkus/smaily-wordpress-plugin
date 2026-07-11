<?php
/**
 * OrderPayloadBuilder tests — WC_Order → §5 wire object, the WC→enum status
 * mapping (on-hold→processing; pending/failed/draft/trash → not ingested;
 * custom statuses default THROUGH as `processing` — F3-42), line items, the
 * IsoDate `Z` datetime, attribution-from-meta, and the F2-10 omission policy.
 *
 * WC objects are PHPUnit mocks (the woocommerce-stubs are loaded), so the
 * method signatures match without hand-rolled shims.
 *
 * @package Smaily\Connect\Tests
 */

declare(strict_types=1);

namespace Smaily\Connect\Tests\Unit\Smaily\RecEngine;

use PHPUnit\Framework\TestCase;
use Smaily\Connect\Smaily\RecEngine\OrderPayloadBuilder;

final class OrderPayloadBuilderTest extends TestCase {

	public function test_build_maps_required_fields_and_event_id(): void {
		$order = $this->mock_order(
			array(
				'id'       => 12345,
				'email'    => 'Mari@Example.com',
				'status'   => 'completed',
				'currency' => 'EUR',
				'total'    => '67.50',
				'date'     => '2026-05-15 14:30:00',
				'items'    => array( $this->mock_item( 'ACA-1', 1, '22.99', '22.99' ) ),
			)
		);

		$payload = ( new OrderPayloadBuilder() )->build( $order, 'evt-order-1' );

		self::assertSame( 'evt-order-1', $payload['event_id'] );
		self::assertSame( '12345', $payload['external_order_id'] );
		self::assertSame( 'mari@example.com', $payload['customer_email'], 'Email is lowercased.' );
		self::assertSame( '2026-05-15T14:30:00Z', $payload['ordered_at'], 'IsoDate Z form (F3-21).' );
		self::assertSame( 67.50, $payload['total_amount'] );
		self::assertSame( 'EUR', $payload['currency'] );
		self::assertSame( 'completed', $payload['status'] );
		self::assertCount( 1, $payload['items'] );
	}

	/**
	 * @dataProvider status_cases
	 */
	public function test_map_status( string $wc_status, string $expected ): void {
		self::assertSame( $expected, ( new OrderPayloadBuilder() )->map_status( $wc_status ) );
	}

	/**
	 * @return array<int, array{0:string,1:string}>
	 */
	public function status_cases(): array {
		return array(
			array( 'completed', 'completed' ),
			array( 'processing', 'processing' ),
			array( 'on-hold', '' ),               // payment not captured → NOT a sale (F3-42, was processing)
			array( 'cancelled', 'cancelled' ),
			array( 'refunded', 'refunded' ),
			array( 'pending', '' ),               // not a confirmed purchase → skipped
			array( 'failed', '' ),
			array( 'checkout-draft', '' ),
			array( 'draft', '' ),                 // WP draft → not a sale
			array( 'auto-draft', '' ),            // order being created → not a sale
			array( 'trash', '' ),                 // trashed order → not a sale
			array( 'shipped', 'processing' ),     // custom status DEFAULTS THROUGH as a sale (F3-42)
			array( 'label-printed', 'processing' ), // the pilot's custom fulfilment status → a sale
			array( 'wc-shipped', 'processing' ),  // 'wc-' prefix normalised on a custom status too
			array( 'wc-completed', 'completed' ), // 'wc-' prefix normalised
		);
	}

	public function test_currency_defaults_to_eur_when_empty(): void {
		$order = $this->mock_order( array( 'currency' => '', 'status' => 'processing' ) );

		self::assertSame( 'EUR', ( new OrderPayloadBuilder() )->build( $order, 'u' )['currency'] );
	}

	public function test_items_carry_gross_unit_price_line_total_and_per_line_discount(): void {
		// GROSS basis (v1.4.0 §5, PRO-1241): 24% VAT. Net subtotal 50.00
		// (+12.00 tax) discounted to net total 45.00 (+10.80 tax) →
		// line_total = 45.00 + 10.80 = 55.80 (get_total + get_total_tax, NOT
		// bare get_total); unit_price = 55.80 / 2 = 27.90 (gross ÷ qty);
		// discount = (50.00 + 12.00) − 55.80 = 6.20 (incl. the discounted tax).
		$order = $this->mock_order(
			array(
				'status' => 'completed',
				'items'  => array(
					$this->mock_item(
						'POC-DENT',
						2,
						'50.00',
						'45.00',
						array(
							'product_id'   => 500,
							'subtotal_tax' => '12.00',
							'total_tax'    => '10.80',
						)
					),
				),
			)
		);

		$item = ( new OrderPayloadBuilder() )->build( $order, 'u' )['items'][0];

		self::assertSame( 'woo-500', $item['sku'] );
		self::assertSame( 2, $item['qty'] );
		self::assertSame( 27.9, $item['unit_price'] );
		self::assertSame( 55.8, $item['line_total'] );
		self::assertSame( 6.2, $item['discount_amount'] );
	}

	public function test_zero_tax_line_amounts_equal_the_net_amounts(): void {
		// Tax-exempt / taxes-off store: gross == net, so the payload is
		// unchanged from the pre-1.4.0 basis. unit_price is now the
		// POST-discount gross ÷ qty (10.00 → 8.00 over qty 2 → 4.00).
		$order = $this->mock_order(
			array(
				'status' => 'completed',
				'items'  => array( $this->mock_item( 'NOTAX', 2, '10.00', '8.00', array( 'product_id' => 501 ) ) ),
			)
		);

		$item = ( new OrderPayloadBuilder() )->build( $order, 'u' )['items'][0];

		self::assertSame( 4.0, $item['unit_price'] );
		self::assertSame( 8.0, $item['line_total'] );
		self::assertSame( 2.0, $item['discount_amount'] );
	}

	public function test_gross_sender_invariant_sum_of_lines_plus_shipping_is_total_amount(): void {
		// Contract §5 sender invariant: Σ items[].line_total + shipping ≈
		// total_amount. Taxed (24%) multi-line order, one line discounted,
		// gross shipping 5.00: line1 gross 12.40, line2 gross 18.60
		// (net 20.00→15.00 + tax 4.80→3.60), total 12.40+18.60+5.00 = 36.00.
		$order = $this->mock_order(
			array(
				'status'               => 'completed',
				'total'                => '36.00',
				'total_discount_gross' => '6.20',
				'items'                => array(
					$this->mock_item( 'L1', 1, '10.00', '10.00', array( 'product_id' => 1, 'subtotal_tax' => '2.40', 'total_tax' => '2.40' ) ),
					$this->mock_item( 'L2', 2, '20.00', '15.00', array( 'product_id' => 2, 'subtotal_tax' => '4.80', 'total_tax' => '3.60' ) ),
				),
			)
		);

		$payload  = ( new OrderPayloadBuilder() )->build( $order, 'u' );
		$shipping = 5.00; // gross shipping — inside total_amount, not a wire field.

		$line_sum = array_sum( array_column( $payload['items'], 'line_total' ) );
		self::assertEqualsWithDelta( 31.0, $line_sum, 0.0001 );
		self::assertEqualsWithDelta(
			$payload['total_amount'],
			$line_sum + $shipping,
			0.005,
			'Sender invariant (§5): Σ line_total + shipping ≈ total_amount.'
		);
		self::assertSame( 6.2, $payload['discount_amount'], 'Order discount is gross (incl. its tax share).' );
	}

	public function test_gross_rounding_uneven_unit_price_rounds_to_4_decimals(): void {
		// Gross 12.40 over qty 3 → 4.133333… → 4.1333; line_total stays the
		// exact charged gross (per-line rounding must not corrupt the line sum).
		$order = $this->mock_order(
			array(
				'status' => 'completed',
				'items'  => array( $this->mock_item( 'R1', 3, '10.00', '10.00', array( 'product_id' => 7, 'subtotal_tax' => '2.40', 'total_tax' => '2.40' ) ) ),
			)
		);

		$item = ( new OrderPayloadBuilder() )->build( $order, 'u' )['items'][0];

		self::assertSame( 4.1333, $item['unit_price'] );
		self::assertSame( 12.4, $item['line_total'] );
		self::assertArrayNotHasKey( 'discount_amount', $item );
	}

	public function test_line_discount_omitted_when_zero(): void {
		$order = $this->mock_order(
			array(
				'status' => 'completed',
				'items'  => array( $this->mock_item( 'NODISC', 1, '10.00', '10.00' ) ),
			)
		);

		$item = ( new OrderPayloadBuilder() )->build( $order, 'u' )['items'][0];

		self::assertArrayNotHasKey( 'discount_amount', $item );
	}

	public function test_order_discount_present_only_when_nonzero_and_is_gross(): void {
		// The mock returns the NET discount for get_total_discount() /
		// get_total_discount(true) and the GROSS one only for the explicit
		// `false` arg — so this pins that the builder asks for the
		// tax-inclusive discount (v1.4.0 §5), not the ex-tax default.
		$with    = $this->mock_order(
			array(
				'status'               => 'completed',
				'total_discount'       => '5.00',
				'total_discount_gross' => '6.20',
			)
		);
		$without = $this->mock_order( array( 'status' => 'completed', 'total_discount' => '0' ) );

		$builder = new OrderPayloadBuilder();

		self::assertSame( 6.2, $builder->build( $with, 'u' )['discount_amount'], 'Gross (incl. tax), not the ex-tax default.' );
		self::assertArrayNotHasKey( 'discount_amount', $builder->build( $without, 'u' ) );
	}

	public function test_attribution_signals_from_meta_omitted_when_absent(): void {
		$present = $this->mock_order(
			array(
				'status' => 'completed',
				'meta'   => array(
					'_smaily_rec_id'          => 'rec_abc',
					'_smaily_visitor_token'   => 'vt_xyz',
					'_smaily_rec_ctx'         => 'cart_abandoned',
					'_smaily_anon_session_id' => 'sess_1',
				),
			)
		);
		$absent = $this->mock_order( array( 'status' => 'completed' ) );

		$builder = new OrderPayloadBuilder();
		$p       = $builder->build( $present, 'u' );
		$a       = $builder->build( $absent, 'u' );

		self::assertSame( 'rec_abc', $p['smaily_rec_id'] );
		self::assertSame( 'vt_xyz', $p['smaily_visitor_token'] );
		self::assertSame( 'cart_abandoned', $p['smaily_rec_ctx'] );
		self::assertSame( 'sess_1', $p['session_id'] );

		self::assertArrayNotHasKey( 'smaily_rec_id', $a );
		self::assertArrayNotHasKey( 'smaily_visitor_token', $a );
		self::assertArrayNotHasKey( 'smaily_rec_ctx', $a );
		self::assertArrayNotHasKey( 'session_id', $a );
	}

	public function test_non_product_line_items_are_skipped(): void {
		// get_items() also returns shipping / fee / coupon lines — only
		// product lines become wire items.
		$order = $this->mock_order(
			array(
				'status' => 'completed',
				'items'  => array( $this->mock_item( 'REAL', 1, '10.00', '10.00', array( 'product_id' => 100 ) ), new \stdClass() ),
			)
		);

		$payload = ( new OrderPayloadBuilder() )->build( $order, 'u' );

		self::assertCount( 1, $payload['items'], 'Only WC_Order_Item_Product lines are mapped.' );
		self::assertSame( 'woo-100', $payload['items'][0]['sku'] );
	}

	public function test_every_line_keys_on_woo_id_ignoring_merchant_sku(): void {
		// PRO-1224: §5 requires items[].sku; SkuResolver keys EVERY line on
		// `woo-{product id}` — the merchant SKU field is never emitted, so a
		// line with a SKU and a line without one key the same way (the order-line
		// key must equal the catalog row's key so they join engine-side).
		$order = $this->mock_order(
			array(
				'status' => 'completed',
				'items'  => array(
					$this->mock_item( 'HAS-SKU', 1, '10.00', '10.00', array( 'product_id' => 100 ) ),
					$this->mock_item( '', 1, '5.00', '5.00', array( 'product_id' => 432 ) ),
				),
			)
		);

		$payload = ( new OrderPayloadBuilder() )->build( $order, 'u' );

		self::assertCount( 2, $payload['items'] );
		self::assertSame( 'woo-100', $payload['items'][0]['sku'], 'A line WITH a merchant SKU still keys woo-<id>.' );
		self::assertSame( 'woo-432', $payload['items'][1]['sku'] );
	}

	public function test_deleted_product_line_keys_from_stored_item_ids(): void {
		// The id-SURVIVES case: the product no longer loads but the line item
		// still carries ids (older WC / intact data) — variation id wins over
		// the parent product id, like catalog treats variations as the units.
		// NB: current WC zeroes these ids on permanent deletion (F3-36); that
		// path is integration-tested as a terminal skip.
		$order = $this->mock_order(
			array(
				'status' => 'completed',
				'items'  => array(
					$this->mock_item( '', 1, '5.00', '5.00', array( 'product' => null, 'product_id' => 432 ) ),
					$this->mock_item( '', 2, '8.00', '8.00', array( 'product' => null, 'product_id' => 432, 'variation_id' => 433 ) ),
				),
			)
		);

		$payload = ( new OrderPayloadBuilder() )->build( $order, 'u' );

		self::assertCount( 2, $payload['items'] );
		self::assertSame( 'woo-432', $payload['items'][0]['sku'] );
		self::assertSame( 'woo-433', $payload['items'][1]['sku'] );
	}

	public function test_deleted_line_with_zeroed_ids_keys_from_order_item_id_never_dropped(): void {
		// The zeroed-id case (current WC permanent delete): the product doesn't
		// load AND product_id/variation_id are 0. The line MUST NOT be dropped —
		// that would empty items[] and silently lose the whole order (#58922,
		// F3-43). It keys on the order-item id (`woo-oi-{id}`) so the order still
		// ingests; the snapshot qty/total come from the line item.
		$order = $this->mock_order(
			array(
				'status' => 'completed',
				'items'  => array( $this->mock_item( '', 2, '14.00', '14.00', array( 'product' => null, 'item_id' => 5512 ) ) ),
			)
		);

		$payload = ( new OrderPayloadBuilder() )->build( $order, 'u' );

		self::assertCount( 1, $payload['items'], 'A deleted-product line is kept, never dropped — the order is never lost.' );
		self::assertSame( 'woo-oi-5512', $payload['items'][0]['sku'] );
		self::assertSame( 2, $payload['items'][0]['qty'] );
		self::assertSame( 14.0, $payload['items'][0]['line_total'] );
	}

	// --- doubles -------------------------------------------------------------

	/**
	 * @param array<string, mixed> $p
	 */
	private function mock_order( array $p ): \WC_Order {
		$order = $this->createMock( \WC_Order::class );
		$order->method( 'get_id' )->willReturn( (int) ( $p['id'] ?? 1 ) );
		$order->method( 'get_billing_email' )->willReturn( (string) ( $p['email'] ?? 'x@example.test' ) );
		$order->method( 'get_status' )->willReturn( (string) ( $p['status'] ?? 'completed' ) );
		$order->method( 'get_currency' )->willReturn( (string) ( $p['currency'] ?? 'EUR' ) );
		$order->method( 'get_total' )->willReturn( (string) ( $p['total'] ?? '0' ) );
		// Arg-sensitive: the ex-tax default vs the gross (`false`) discount —
		// lets tests pin that the builder requests the GROSS one (v1.4.0 §5).
		$net_discount   = (string) ( $p['total_discount'] ?? '0' );
		$gross_discount = (string) ( $p['total_discount_gross'] ?? $net_discount );
		$order->method( 'get_total_discount' )->willReturnCallback(
			static function ( $ex_taxes = true ) use ( $net_discount, $gross_discount ) {
				return $ex_taxes ? $net_discount : $gross_discount;
			}
		);
		$order->method( 'get_items' )->willReturn( $p['items'] ?? array() );

		if ( isset( $p['date'] ) ) {
			// The builder only needs is_object + getTimestamp(); a plain object
			// avoids mocking WC_DateTime (DateTime subclass) which isn't loaded.
			$date = new class( (int) strtotime( (string) $p['date'] . ' UTC' ) ) {
				private int $ts;
				public function __construct( int $ts ) {
					$this->ts = $ts;
				}
				public function getTimestamp(): int {
					return $this->ts;
				}
			};
			$order->method( 'get_date_created' )->willReturn( $date );
		}

		$meta = $p['meta'] ?? array();
		$order->method( 'get_meta' )->willReturnCallback(
			static function ( $key = '', $single = true, $context = 'view' ) use ( $meta ) {
				return $meta[ $key ] ?? '';
			}
		);

		return $order;
	}

	/**
	 * @param array<string, mixed> $opts 'product' => null simulates a deleted
	 *                                   product; 'product_id'/'variation_id'
	 *                                   are the ids WC stored on the line.
	 */
	private function mock_item( string $sku, int $qty, string $subtotal, string $total, array $opts = array() ): \WC_Order_Item_Product {
		if ( array_key_exists( 'product', $opts ) ) {
			$product = $opts['product'];
		} else {
			$product = $this->createMock( \WC_Product::class );
			$product->method( 'get_sku' )->willReturn( $sku );
			$product->method( 'get_id' )->willReturn( (int) ( $opts['product_id'] ?? 0 ) );
		}

		$item = $this->createMock( \WC_Order_Item_Product::class );
		$item->method( 'get_product' )->willReturn( $product );
		$item->method( 'get_id' )->willReturn( (int) ( $opts['item_id'] ?? 7777 ) );
		$item->method( 'get_product_id' )->willReturn( (int) ( $opts['product_id'] ?? 0 ) );
		$item->method( 'get_variation_id' )->willReturn( (int) ( $opts['variation_id'] ?? 0 ) );
		$item->method( 'get_quantity' )->willReturn( $qty );
		$item->method( 'get_subtotal' )->willReturn( $subtotal );
		$item->method( 'get_total' )->willReturn( $total );
		// Tax shares (v1.4.0 §5 gross basis) — default 0 = a tax-exempt line.
		$item->method( 'get_subtotal_tax' )->willReturn( (string) ( $opts['subtotal_tax'] ?? '0' ) );
		$item->method( 'get_total_tax' )->willReturn( (string) ( $opts['total_tax'] ?? '0' ) );

		return $item;
	}
}

// WC_Order_Item_Product shim for createMock (woocommerce-stubs are PHPStan-only,
// not loaded at runtime; WC_Order lives in HookHandlerTest, WC_Product in
// CatalogPayloadBuilderTest). Guarded so it coexists with any other definition.
if ( ! class_exists( \WC_Order_Item_Product::class ) ) {
	// phpcs:ignore Squiz.Commenting.ClassComment.Missing -- test shim.
	eval(
		<<<'PHP'
		class WC_Order_Item_Product {
			public function get_id() { return 0; }
			public function get_product() { return null; }
			public function get_product_id( $context = 'view' ) { return 0; }
			public function get_variation_id( $context = 'view' ) { return 0; }
			public function get_quantity( $context = 'view' ) { return 0; }
			public function get_subtotal( $context = 'view' ) { return '0'; }
			public function get_total( $context = 'view' ) { return '0'; }
			public function get_subtotal_tax( $context = 'view' ) { return '0'; }
			public function get_total_tax( $context = 'view' ) { return '0'; }
		}
PHP
	);
}
