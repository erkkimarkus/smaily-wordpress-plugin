<?php
/**
 * Integration: Client::ingest_catalog against the mock engine — wire
 * format, event_id read/write symmetry, deduplicated-response handling,
 * and the retry policy (429 / 5xx transient, 4xx terminal).
 *
 * @package Smaily\Connect\Tests\Integration
 */

declare(strict_types=1);

namespace Smaily\Connect\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Smaily\Connect\Integrations\WooCommerce\CatalogHookHandler;
use Smaily\Connect\Settings\RecEngineSettings;
use Smaily\Connect\Smaily\RecEngine\ApiException;
use Smaily\Connect\Smaily\RecEngine\CatalogPayloadBuilder;
use Smaily\Connect\Smaily\RecEngine\Client;
use Smaily\Connect\Smaily\RecEngine\IngestFlusher;
use Smaily\Connect\Smaily\RecEngine\IngestQueue;
use Smaily\Connect\Tests\Integration\Fixtures\RecEngineMockServer;
use Smaily\Connect\Tests\Integration\Support\EnvScrub;
use Smaily\Connect\Tests\Integration\Support\EnvSeed;

/**
 * What this catches that the unit tests can't:
 *
 *   - The full chain CatalogPayloadBuilder → Client → real HTTP → engine.
 *     A doubled request_url() can't catch header serialisation, status
 *     parsing, or the endpoints-map URL actually resolving to a live
 *     route.
 *
 *   - Read/write symmetry on the WIRE: the event_uuid written to the
 *     queue must arrive at the engine as products[].event_id, byte for
 *     byte. The mock records what it received; the test asserts it equals
 *     the queue row's uuid.
 *
 *   - The deduplicated short-circuit: a resent event_id must come back
 *     200 {"deduplicated": true} and be treated as success (the flush job
 *     marks the row sent, never retries — the most likely future
 *     regression).
 *
 *   - The retry policy end-to-end: 429 + Retry-After and 5xx are retried
 *     to success; a 4xx (revoked key) throws ApiException with no retry.
 *
 * Also exercises EnvSeed (deferred from 3.2.0) — it points a "connected"
 * tenant at the mock so no setup-exchange is needed; the mock accepts any
 * sk_* bearer.
 */
final class RecEngineCatalogTest extends TestCase {

	private static ?RecEngineMockServer $engine = null;

	/** @var array<int, int> Product ids created by a test, torn down after. */
	private array $created_products = array();

	public static function setUpBeforeClass(): void {
		self::$engine = RecEngineMockServer::start();
	}

	protected function setUp(): void {
		parent::setUp();
		if ( ! class_exists( 'WC_Product_Simple' ) ) {
			self::markTestSkipped( 'WooCommerce not active — catalog ingest needs WC_Product.' );
		}
		EnvScrub::reset();
		RecEngineMockServer::reset();
	}

	protected function tearDown(): void {
		foreach ( $this->created_products as $product_id ) {
			wp_delete_post( $product_id, true );
		}
		$this->created_products = array();
		parent::tearDown();
	}

	public function test_catalog_success_and_event_id_matches_queue_uuid(): void {
		$client = $this->connected_client();

		// A real queue row: its event_uuid is the idempotency key that must
		// travel to the engine unchanged as products[].event_id.
		$queue = new IngestQueue();
		$queue->enqueue( 'catalog.upsert', '0', array( 'sku' => 'CAT-SYM-1' ) );
		$row  = $queue->pending( 1 )[0];
		$uuid = (string) $row['event_uuid'];

		$product = $this->make_product( 'CAT-SYM-1', '22.99' );
		$payload = ( new CatalogPayloadBuilder() )->build( $product, $uuid );

		$result = $client->ingest_catalog( array( $payload ) );

		self::assertTrue( (bool) ( $result['ok'] ?? false ), 'Catalog ingest should succeed (200 ok).' );
		self::assertSame( 1, $result['created'] ?? null );

		$received = self::$engine->state()['last_catalog_received'] ?? null;
		self::assertSame(
			array( $uuid ),
			$received,
			'Read/write symmetry: the engine must receive products[].event_id == queue.event_uuid.'
		);
	}

	public function test_resent_event_id_returns_deduplicated_and_is_not_an_error(): void {
		$client  = $this->connected_client();
		$product = $this->make_product( 'CAT-DUP-1', '10.00' );
		$payload = ( new CatalogPayloadBuilder() )->build( $product, 'dup-event-uuid-1234' );

		$first = $client->ingest_catalog( array( $payload ) );
		self::assertTrue( (bool) ( $first['ok'] ?? false ) );
		self::assertArrayNotHasKey( 'deduplicated', $first, 'First send is a fresh INSERT, not a duplicate.' );

		// Identical event_id resent (what a flush-job retry does).
		$second = $client->ingest_catalog( array( $payload ) );
		self::assertTrue(
			(bool) ( $second['deduplicated'] ?? false ),
			'A resent event_id must return 200 {"deduplicated": true} — the flush job marks the row sent, NOT a retry.'
		);
	}

	public function test_transient_429_then_retry_succeeds(): void {
		$client  = $this->connected_client();
		$product = $this->make_product( 'RETRY-429', '5.00' );
		$payload = ( new CatalogPayloadBuilder() )->build( $product, 'evt-429' );

		$result = $client->ingest_catalog( array( $payload ) );

		self::assertTrue(
			(bool) ( $result['ok'] ?? false ),
			'Client must honour Retry-After on 429, back off, retry, and ultimately succeed.'
		);
	}

	public function test_transient_500_then_retry_succeeds(): void {
		$client  = $this->connected_client();
		$product = $this->make_product( 'RETRY-500', '5.00' );
		$payload = ( new CatalogPayloadBuilder() )->build( $product, 'evt-500' );

		$result = $client->ingest_catalog( array( $payload ) );

		self::assertTrue(
			(bool) ( $result['ok'] ?? false ),
			'Client must back off on 5xx, retry, and ultimately succeed.'
		);
	}

	public function test_revoked_key_401_throws_without_retry(): void {
		$client  = $this->connected_client();
		$product = $this->make_product( 'AUTH-401', '1.00' );
		$payload = ( new CatalogPayloadBuilder() )->build( $product, 'evt-401' );

		try {
			$client->ingest_catalog( array( $payload ) );
			self::fail( 'A 401 (revoked key) must throw ApiException, not return.' );
		} catch ( ApiException $e ) {
			self::assertSame( 401, $e->getCode(), 'ApiException carries the HTTP status as its code.' );
			self::assertSame( 'api_key_revoked', $e->error_code() );
		}
	}

	public function test_product_save_through_flusher_reaches_engine_end_to_end(): void {
		// The whole 3.2.3 chain with NO doubles: a real product save enqueues
		// a real row, the real flusher drains it to the mock engine.
		$base = (string) self::$engine->base_url();
		EnvSeed::connect(
			array(
				'engine_base_url' => $base,
				'endpoints'       => self::mock_endpoints( $base ),
			)
		);

		$settings = new RecEngineSettings();
		$queue    = new IngestQueue();
		$builder  = new CatalogPayloadBuilder();

		$product    = $this->make_product( 'CAT-E2E-1', '9.99' );
		$product_id = (int) $product->get_id();

		// Hook layer.
		( new CatalogHookHandler( $queue, $builder, $settings ) )->on_save_product( $product_id );

		$pending = $queue->pending( 10 );
		self::assertCount( 1, $pending, 'save_post_product must enqueue exactly one catalog row.' );
		self::assertSame( CatalogHookHandler::EVENT_CATALOG_UPSERT, $pending[0]['event_type'] );
		$uuid = (string) $pending[0]['event_uuid'];

		// Flush layer.
		$flusher = new IngestFlusher(
			$queue,
			$builder,
			$settings,
			static function () use ( $settings ): Client {
				return new Client( $settings->api_key(), $settings->base_url(), $settings->endpoints(), 2 );
			}
		);
		$stats = $flusher->flush();

		self::assertSame( 1, $stats['sent'] );
		self::assertSame( array(), $queue->pending( 10 ), 'The row is sent and no longer pending.' );

		$received = self::$engine->state()['last_catalog_received'] ?? null;
		self::assertSame(
			array( $uuid ),
			$received,
			'End-to-end: the engine received products[].event_id == the queue row event_uuid.'
		);
	}

	/**
	 * Seed a connected tenant pointed at the mock and build a Client from
	 * the stored settings (api_key + base_url + endpoints map).
	 */
	private function connected_client(): Client {
		$base = (string) self::$engine->base_url();

		EnvSeed::connect(
			array(
				'engine_base_url' => $base,
				'endpoints'       => self::mock_endpoints( $base ),
			)
		);

		$settings = new RecEngineSettings();
		return new Client( $settings->api_key(), $settings->base_url(), $settings->endpoints() );
	}

	/**
	 * @return array<string, string>
	 */
	private static function mock_endpoints( string $base ): array {
		return array(
			'ingest_ping'      => $base . '/api/v1/ingest/ping',
			'ingest_catalog'   => $base . '/api/v1/ingest/catalog',
			'ingest_customers' => $base . '/api/v1/ingest/customers',
			'ingest_orders'    => $base . '/api/v1/ingest/orders',
		);
	}

	private function make_product( string $sku, string $price ): \WC_Product {
		// Idempotent across crashed runs that skipped tearDown: drop any
		// leftover product with this SKU before creating a fresh one.
		$existing = wc_get_product_id_by_sku( $sku );
		if ( $existing ) {
			wp_delete_post( $existing, true );
		}

		$product = new \WC_Product_Simple();
		$product->set_sku( $sku );
		$product->set_name( 'Catalog Test ' . $sku );
		$product->set_regular_price( $price );
		$product->set_price( $price );
		$product->set_stock_status( 'instock' );
		$id = (int) $product->save();

		$this->created_products[] = $id;

		$loaded = wc_get_product( $id );
		self::assertInstanceOf( \WC_Product::class, $loaded );
		return $loaded;
	}
}
