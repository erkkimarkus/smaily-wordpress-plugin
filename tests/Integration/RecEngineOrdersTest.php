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
			wp_delete_post( $order_id, true );
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

	public function test_skuless_product_order_ingests_with_synthetic_key(): void {
		// F3-36 (pilot find): the pilot store has no SKUs at all. The order
		// must still ingest, keyed wc-{product id} by SkuResolver — before
		// this, every line dropped and the empty items[] D6-failed the order
		// on every retry.
		$product = $this->make_skuless_product( '7.00' );
		$this->make_order( 'skuless@example.test', 'completed', $product );

		$stats = $this->flusher()->flush();

		self::assertSame( 1, $stats['sent'], 'stats: ' . wp_json_encode( $stats ) );
		self::assertSame( 0, $stats['failed'] );

		$payloads = self::$engine->state()['last_orders_payload'] ?? null;
		self::assertIsArray( $payloads );
		self::assertSame( 'wc-' . $product->get_id(), $payloads[0]['items'][0]['sku'] );
	}

	public function test_deleted_product_order_is_kept_and_sent_not_dropped(): void {
		// F3-43 (engine brief #58922): the pilot's failed orders referenced
		// already-deleted products. Empirical (this env, WC 10.7): permanent
		// product deletion ZEROES the order items' _product_id reference. The
		// order must NOT be dropped — that empties items[] and the whole order is
		// silently lost (marked "sent" with no POST). The line keys on the
		// order-item id (wc-oi-{id}) so the order ingests; the engine accepts it.
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
		self::assertStringStartsWith( 'wc-oi-', (string) $payloads[0]['items'][0]['sku'], 'A zeroed-id deleted line keys on the order-item id.' );
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
