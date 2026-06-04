<?php
/**
 * OrderPayloadBuilder tests — WC_Order → §5 wire object, the WC→enum status
 * mapping (on-hold→processing; pending/failed/custom → not ingested), line
 * items, the IsoDate `Z` datetime, attribution-from-meta, and the F2-10
 * omission policy.
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
			array( 'on-hold', 'processing' ),     // placed, payment pending → a purchase intent
			array( 'cancelled', 'cancelled' ),
			array( 'refunded', 'refunded' ),
			array( 'pending', '' ),               // not a confirmed purchase → skipped
			array( 'failed', '' ),
			array( 'checkout-draft', '' ),
			array( 'shipped', '' ),               // a custom status → not mapped
			array( 'wc-completed', 'completed' ), // 'wc-' prefix normalised
		);
	}

	public function test_currency_defaults_to_eur_when_empty(): void {
		$order = $this->mock_order( array( 'currency' => '', 'status' => 'processing' ) );

		self::assertSame( 'EUR', ( new OrderPayloadBuilder() )->build( $order, 'u' )['currency'] );
	}

	public function test_items_carry_unit_price_and_per_line_discount(): void {
		// subtotal 50.00 over qty 2 → unit_price 25.00; total 44.51 after a
		// 5.49 line discount.
		$order = $this->mock_order(
			array(
				'status' => 'completed',
				'items'  => array( $this->mock_item( 'POC-DENT', 2, '50.00', '44.51' ) ),
			)
		);

		$item = ( new OrderPayloadBuilder() )->build( $order, 'u' )['items'][0];

		self::assertSame( 'POC-DENT', $item['sku'] );
		self::assertSame( 2, $item['qty'] );
		self::assertSame( 25.0, $item['unit_price'] );
		self::assertSame( 44.51, $item['line_total'] );
		self::assertSame( 5.49, $item['discount_amount'] );
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

	public function test_order_discount_present_only_when_nonzero(): void {
		$with    = $this->mock_order( array( 'status' => 'completed', 'total_discount' => '5.00' ) );
		$without = $this->mock_order( array( 'status' => 'completed', 'total_discount' => '0' ) );

		$builder = new OrderPayloadBuilder();

		self::assertSame( 5.0, $builder->build( $with, 'u' )['discount_amount'] );
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
				'items'  => array( $this->mock_item( 'REAL', 1, '10.00', '10.00' ), new \stdClass() ),
			)
		);

		$payload = ( new OrderPayloadBuilder() )->build( $order, 'u' );

		self::assertCount( 1, $payload['items'], 'Only WC_Order_Item_Product lines are mapped.' );
		self::assertSame( 'REAL', $payload['items'][0]['sku'] );
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
		$order->method( 'get_total_discount' )->willReturn( (string) ( $p['total_discount'] ?? '0' ) );
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

	private function mock_item( string $sku, int $qty, string $subtotal, string $total ): \WC_Order_Item_Product {
		$product = $this->createMock( \WC_Product::class );
		$product->method( 'get_sku' )->willReturn( $sku );

		$item = $this->createMock( \WC_Order_Item_Product::class );
		$item->method( 'get_product' )->willReturn( $product );
		$item->method( 'get_quantity' )->willReturn( $qty );
		$item->method( 'get_subtotal' )->willReturn( $subtotal );
		$item->method( 'get_total' )->willReturn( $total );

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
			public function get_product() { return null; }
			public function get_quantity( $context = 'view' ) { return 0; }
			public function get_subtotal( $context = 'view' ) { return '0'; }
			public function get_total( $context = 'view' ) { return '0'; }
		}
PHP
	);
}
