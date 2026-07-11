<?php
/**
 * Integration: OrderFlusher → Client::ingest_orders against the mock engine —
 * the D6 per-item contract end-to-end (partial success splits the batch),
 * email-keyed orders wire format, and the terminal-4xx path.
 *
 * The OrderHookHandler lands in 3.3-orders.3, so rows are enqueued directly
 * here; the flusher loads each WC_Order fresh and ships it.
 *
 * @package Smaily\Connect\Tests\Integration
 */

declare(strict_types=1);

namespace Smaily\Connect\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Smaily\Connect\Integrations\WooCommerce\OrderHookHandler;
use Smaily\Connect\Settings\RecEngineSettings;
use Smaily\Connect\Smaily\RecEngine\Client;
use Smaily\Connect\Smaily\RecEngine\IngestQueue;
use Smaily\Connect\Smaily\RecEngine\OrderFlusher;
use Smaily\Connect\Smaily\RecEngine\OrderPayloadBuilder;
use Smaily\Connect\Tests\Integration\Fixtures\RecEngineMockServer;
use Smaily\Connect\Tests\Integration\Support\EnvScrub;
use Smaily\Connect\Tests\Integration\Support\EnvSeed;

final class RecEngineOrdersTest extends TestCase {

	private static ?RecEngineMockServer $engine = null;

	/** @var array<int, int> Order ids created by a test, torn down after. */
	private array $created_orders = array();

	/** @var array<int, int> Product ids created by a test, torn down after. */
	private array $created_products = array();

	public static function setUpBeforeClass(): void {
		self::$engine = RecEngineMockServer::start();
	}

	protected function setUp(): void {
		parent::setUp();
		if ( ! function_exists( 'wc_create_order' ) || ! class_exists( 'WC_Product_Simple' ) ) {
			self::markTestSkipped( 'WooCommerce not active — orders ingest needs WC_Order.' );
		}
		EnvScrub::reset();
		RecEngineMockServer::reset();
		OrderHookHandler::reset_seen();
		$this->connect();
	}

	protected function tearDown(): void {
		foreach ( $this->created_orders as $order_id ) {
			// NOT wp_delete_post: under HPOS orders live in wc_orders, so a
			// post-delete is a silent no-op and the order leaks across runs.
			$order = wc_get_order( $order_id );
			if ( $order instanceof \WC_Order ) {
				$order->delete( true );
			}
		}
		foreach ( $this->created_products as $product_id ) {
			wp_delete_post( $product_id, true );
		}
		$this->created_orders   = array();
		$this->created_products = array();
		parent::tearDown();
	}

	public function test_d6_partial_success_marks_errored_row_failed_and_rest_sent(): void {
		$queue   = new IngestQueue();
		$product = $this->make_product( 'ORD-SKU-1', '10.00' );

		// Two valid orders + one whose customer_email triggers a per-item error
		// in the mock (`d6err-` prefix). Creating a completed order fires
		// woocommerce_order_status_changed; the registered OrderHookHandler
		// enqueues the order.upsert row — the real wiring, no manual enqueue.
		$this->make_order( 'valid-a@example.test', 'completed', $product );
		$this->make_order( 'valid-b@example.test', 'completed', $product );
		$this->make_order( 'd6err-bad@example.test', 'completed', $product );

		$stats = $this->flusher()->flush();

		self::assertSame( 2, $stats['sent'], 'The two valid orders are processed → sent.' );
		self::assertSame( 1, $stats['failed'], 'The errors[] order is marked failed, not the whole batch.' );
		self::assertSame(
			array(),
			$queue->pending( 10, array( OrderFlusher::EVENT_ORDER_UPSERT ) ),
			'Every order row reached a terminal state.'
		);

		$received = self::$engine->state()['last_orders_received'] ?? null;
		self::assertIsArray( $received );
		self::assertCount( 3, $received, 'All three orders were sent in one batch.' );
	}

	public function test_all_valid_orders_are_sent(): void {
		$product = $this->make_product( 'ORD-SKU-2', '5.00' );
		$this->make_order( 'all-good-1@example.test', 'processing', $product );
		$this->make_order( 'all-good-2@example.test', 'completed', $product );

		$stats = $this->flusher()->flush();

		self::assertSame( 2, $stats['sent'] );
		self::assertSame( 0, $stats['failed'] );
	}

	public function test_flush_records_the_send_exchange_on_the_row(): void {
		// F3-44 end-to-end: after a real flush the row carries the exact JSON
		// POSTed + the engine reply (migration 007 columns, store_exchange write).
		global $wpdb;
		$product = $this->make_product( 'ORD-SKU-EX', '7.00' );
		$oid     = $this->make_order( 'exchange@example.test', 'completed', $product );

		$this->flusher()->flush();

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT sent_payload, last_response, status FROM {$wpdb->prefix}smly_rec_event_queue WHERE entity_id = %s AND event_type = %s",
				(string) $oid,
				OrderFlusher::EVENT_ORDER_UPSERT
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		self::assertIsArray( $row );
		self::assertSame( 'sent', $row['status'] );
		self::assertStringContainsString( '"external_order_id":"' . $oid . '"', (string) $row['sent_payload'], 'The exact wire object is stored.' );
		self::assertStringContainsString( '"outcome":"accepted"', (string) $row['last_response'] );
	}

	public function test_skuless_product_order_ingests_with_woo_key(): void {
		// PRO-1224 (pilot find): the pilot store has no SKUs at all. The order
		// must still ingest, keyed woo-{product id} by SkuResolver (the platform
		// id, never the merchant SKU) — before this, every line dropped and the
		// empty items[] D6-failed the order on every retry.
		$product = $this->make_skuless_product( '7.00' );
		$this->make_order( 'skuless@example.test', 'completed', $product );

		$stats = $this->flusher()->flush();

		self::assertSame( 1, $stats['sent'], 'stats: ' . wp_json_encode( $stats ) );
		self::assertSame( 0, $stats['failed'] );

		$payloads = self::$engine->state()['last_orders_payload'] ?? null;
		self::assertIsArray( $payloads );
		self::assertSame( 'woo-' . $product->get_id(), $payloads[0]['items'][0]['sku'] );
	}

	public function test_deleted_product_order_is_kept_and_sent_not_dropped(): void {
		// F3-43 (engine brief #58922): the pilot's failed orders referenced
		// already-deleted products. Empirical (this env, WC 10.7): permanent
		// product deletion ZEROES the order items' _product_id reference. The
		// order must NOT be dropped — that empties items[] and the whole order is
		// silently lost (marked "sent" with no POST). The line keys on the
		// order-item id (woo-oi-{id}) so the order ingests; the engine accepts it.
		$product = $this->make_skuless_product( '9.00' );
		$pid     = $product->get_id();
		$this->make_order( 'deleted-product@example.test', 'completed', $product );

		wp_delete_post( $pid, true );
		self::assertFalse( wc_get_product( $pid ), 'Precondition: the product row is gone.' );

		$stats = $this->flusher()->flush();

		self::assertSame( 1, $stats['sent'], 'A deleted-product order is kept + sent, never dropped. stats: ' . wp_json_encode( $stats ) );
		self::assertSame( 0, $stats['skipped'] );
		self::assertSame( 0, $stats['failed'] );

		$payloads = self::$engine->state()['last_orders_payload'] ?? null;
		self::assertIsArray( $payloads );
		self::assertNotEmpty( $payloads[0]['items'], 'items[] must be non-empty — the line is kept from the snapshot.' );
		self::assertStringStartsWith( 'woo-oi-', (string) $payloads[0]['items'][0]['sku'], 'A zeroed-id deleted line keys on the order-item id.' );
	}

	public function test_taxed_discounted_order_wires_gross_amounts_and_the_sender_invariant_holds(): void {
		// PRO-1241 / contract v1.4.0 §5 "Amount semantics": ALL money fields
		// are GROSS (tax-inclusive). Real WC tax engine (24% VAT, prices
		// entered ex-tax), two product lines, a fixed-cart coupon and taxed
		// shipping — the wire must carry line_total = get_total() +
		// get_total_tax() (bare get_total() is the pre-1.4.0 net bug, the
		// pilot's ~24% per-SKU revenue understatement) and satisfy
		// Σ items[].line_total + shipping ≈ total_amount.
		$this->enable_24_percent_vat();

		$product_a = $this->make_product( 'GROSS-A', '10.00' );
		$product_b = $this->make_product( 'GROSS-B', '20.00' );

		$coupon = new \WC_Coupon();
		$coupon->set_code( 'gross-5-off' );
		$coupon->set_discount_type( 'fixed_cart' );
		$coupon->set_amount( '5.00' );
		$coupon_id = (int) $coupon->save();

		try {
			$order = wc_create_order();
			$order->set_billing_email( 'gross-invariant@example.test' );
			$order->add_product( $product_a, 1 );
			$order->add_product( $product_b, 2 );

			$shipping = new \WC_Order_Item_Shipping();
			$shipping->set_method_title( 'Flat rate' );
			$shipping->set_total( '5.00' );
			$order->add_item( $shipping );

			$order->apply_coupon( 'gross-5-off' );
			$order->calculate_totals( true );
			$order->set_status( 'completed' );
			$order_id               = (int) $order->save();
			$this->created_orders[] = $order_id;

			$order = wc_get_order( $order_id );
			self::assertGreaterThan( 0.0, (float) $order->get_total_tax(), 'Precondition: the WC tax engine really taxed this order.' );

			$stats = $this->flusher()->flush();
			self::assertSame( 1, $stats['sent'], 'stats: ' . wp_json_encode( $stats ) );
			self::assertSame( 0, $stats['failed'] );

			$payloads = self::$engine->state()['last_orders_payload'] ?? null;
			self::assertIsArray( $payloads );
			$payload = $payloads[0];

			// Each wire line is the GROSS charged amount from the real order.
			$wc_items = array_values( $order->get_items() );
			self::assertCount( count( $wc_items ), $payload['items'] );
			foreach ( $wc_items as $i => $wc_item ) {
				$expected_gross = (float) $wc_item->get_total() + (float) $wc_item->get_total_tax();
				self::assertGreaterThan(
					(float) $wc_item->get_total(),
					(float) $payload['items'][ $i ]['line_total'],
					'line_total must exceed the NET get_total() — i.e. it includes tax.'
				);
				self::assertEqualsWithDelta( $expected_gross, (float) $payload['items'][ $i ]['line_total'], 0.0001 );
				$qty = (int) $wc_item->get_quantity();
				self::assertEqualsWithDelta( $expected_gross / $qty, (float) $payload['items'][ $i ]['unit_price'], 0.0001, 'unit_price = gross ÷ qty.' );
				self::assertGreaterThan( 0.0, (float) $payload['items'][ $i ]['discount_amount'], 'The cart coupon discounts every line — gross delta present.' );
			}

			// §5 sender invariant against the REAL order totals.
			$line_sum       = array_sum( array_column( $payload['items'], 'line_total' ) );
			$gross_shipping = (float) $order->get_shipping_total() + (float) $order->get_shipping_tax();
			self::assertEqualsWithDelta(
				(float) $payload['total_amount'],
				$line_sum + $gross_shipping,
				0.01,
				'Σ items[].line_total + shipping ≈ total_amount (± rounding).'
			);

			// The order-level discount is gross too (discount + its tax share).
			self::assertEqualsWithDelta(
				(float) $order->get_total_discount( false ),
				(float) $payload['discount_amount'],
				0.0001
			);
			self::assertGreaterThan(
				(float) $order->get_total_discount( true ),
				(float) $payload['discount_amount'],
				'Gross discount must exceed the ex-tax discount on a taxed order.'
			);
		} finally {
			wp_delete_post( $coupon_id, true );
			$this->disable_taxes();
		}
	}

	public function test_zero_tax_order_wires_amounts_equal_to_net_and_invariant_holds(): void {
		// Taxes off (a tax-exempt store): gross == net; the §5 invariant must
		// hold on the same code path with a zero tax share.
		$product = $this->make_product( 'GROSS-ZERO', '12.50' );
		$this->make_order( 'gross-zero-tax@example.test', 'completed', $product );

		$stats = $this->flusher()->flush();
		self::assertSame( 1, $stats['sent'] );

		$payloads = self::$engine->state()['last_orders_payload'] ?? null;
		self::assertIsArray( $payloads );
		$payload = $payloads[0];

		self::assertEqualsWithDelta( 12.5, (float) $payload['items'][0]['line_total'], 0.0001 );
		self::assertEqualsWithDelta( 12.5, (float) $payload['items'][0]['unit_price'], 0.0001 );
		$line_sum = array_sum( array_column( $payload['items'], 'line_total' ) );
		self::assertEqualsWithDelta( (float) $payload['total_amount'], $line_sum, 0.01, 'No shipping, no tax: Σ line_total == total_amount.' );
	}

	public function test_order_with_no_product_lines_is_terminal_skipped(): void {
		// Only non-product lines (a fee) → items[] builds empty → the engine
		// would D6-reject on items min 1 forever. The flusher terminal-skips
		// instead of sending (F3-36).
		$order = wc_create_order();
		$order->set_billing_email( 'fee-only@example.test' );
		$fee = new \WC_Order_Item_Fee();
		$fee->set_name( 'Handling' );
		$fee->set_total( '5.00' );
		$order->add_item( $fee );
		$order->calculate_totals();
		$order->set_status( 'completed' );
		$order_id               = (int) $order->save();
		$this->created_orders[] = $order_id;

		$before = self::$engine->state()['last_orders_received'] ?? null;
		$stats  = $this->flusher()->flush();

		self::assertSame( 1, $stats['skipped'] );
		self::assertSame( 0, $stats['sent'] );
		self::assertSame( 0, $stats['failed'] );
		self::assertSame(
			$before,
			self::$engine->state()['last_orders_received'] ?? null,
			'No engine call was made for the empty-items order.'
		);
	}

	public function test_revoked_key_401_fails_batch_without_retry(): void {
		$product = $this->make_product( 'ORD-SKU-3', '5.00' );
		$this->make_order( 'auth-401@example.test', 'completed', $product );

		$stats = $this->flusher()->flush();

		self::assertSame( 0, $stats['sent'] );
		self::assertSame( 1, $stats['failed'], 'A revoked key is terminal — mark failed, no retry.' );
		self::assertSame( 0, $stats['retried'] );
	}

	/** @var int Tax rate id created by enable_24_percent_vat(), 0 = none. */
	private int $tax_rate_id = 0;

	/**
	 * Turn the REAL WC tax engine on: 24% standard VAT (matches the pilot
	 * market), prices entered ex-tax, taxed shipping, tax by shop base.
	 * Callers pair with disable_taxes() in a finally block.
	 */
	private function enable_24_percent_vat(): void {
		update_option( 'woocommerce_calc_taxes', 'yes' );
		update_option( 'woocommerce_prices_include_tax', 'no' );
		update_option( 'woocommerce_tax_based_on', 'base' );
		$this->tax_rate_id = (int) \WC_Tax::_insert_tax_rate(
			array(
				'tax_rate_country'  => '', // every country — location-independent.
				'tax_rate_state'    => '',
				'tax_rate'          => '24.0000',
				'tax_rate_name'     => 'VAT',
				'tax_rate_priority' => 1,
				'tax_rate_compound' => 0,
				'tax_rate_shipping' => 1,
				'tax_rate_order'    => 0,
				'tax_rate_class'    => '',
			)
		);
	}

	private function disable_taxes(): void {
		if ( $this->tax_rate_id > 0 ) {
			\WC_Tax::_delete_tax_rate( $this->tax_rate_id );
			$this->tax_rate_id = 0;
		}
		update_option( 'woocommerce_calc_taxes', 'no' );
	}

	private function flusher(): OrderFlusher {
		$settings = new RecEngineSettings();
		return new OrderFlusher(
			new IngestQueue(),
			new OrderPayloadBuilder(),
			$settings,
			static function () use ( $settings ): Client {
				return new Client( $settings->api_key(), $settings->base_url(), $settings->endpoints(), 2 );
			}
		);
	}

	private function make_product( string $sku, string $price ): \WC_Product {
		$existing = wc_get_product_id_by_sku( $sku );
		if ( $existing ) {
			wp_delete_post( $existing, true );
		}
		$product = new \WC_Product_Simple();
		$product->set_sku( $sku );
		$product->set_name( 'Order Test ' . $sku );
		$product->set_regular_price( $price );
		$product->set_price( $price );
		$product->set_stock_status( 'instock' );
		$id                       = (int) $product->save();
		$this->created_products[] = $id;

		$loaded = wc_get_product( $id );
		self::assertInstanceOf( \WC_Product::class, $loaded );
		return $loaded;
	}

	private function make_skuless_product( string $price ): \WC_Product {
		$product = new \WC_Product_Simple();
		$product->set_name( 'Order Test (no sku)' );
		$product->set_regular_price( $price );
		$product->set_price( $price );
		$product->set_stock_status( 'instock' );
		$id                       = (int) $product->save();
		$this->created_products[] = $id;

		$loaded = wc_get_product( $id );
		self::assertInstanceOf( \WC_Product::class, $loaded );
		self::assertSame( '', $loaded->get_sku(), 'Precondition: the product really has no SKU.' );
		return $loaded;
	}

	private function make_order( string $email, string $status, \WC_Product $product ): int {
		$order = wc_create_order();
		$order->set_billing_email( $email );
		$order->add_product( $product, 1 );
		$order->calculate_totals();
		$order->set_status( $status );
		$order_id = (int) $order->save();

		$this->created_orders[] = $order_id;
		return $order_id;
	}

	private function connect(): void {
		$base = (string) self::$engine->base_url();
		EnvSeed::connect(
			array(
				'engine_base_url' => $base,
				'endpoints'       => array(
					'ingest_ping'      => $base . '/api/v1/ingest/ping',
					'ingest_catalog'   => $base . '/api/v1/ingest/catalog',
					'ingest_customers' => $base . '/api/v1/ingest/customers',
					'ingest_orders'    => $base . '/api/v1/ingest/orders',
				),
			)
		);
	}
}
