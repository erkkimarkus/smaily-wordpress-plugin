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
use Smaily\Connect\Multilingual\DetectorInterface;
use Smaily\Connect\Multilingual\SiteLocaleAdapter;
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
		self::assertSame( 1, $result['processed'] ?? null, 'Catalog is D6 now — one product processed.' );

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
		self::assertSame( 1, $first['processed'] ?? null, 'First send is a fresh process.' );
		self::assertSame( 0, $first['deduplicated'] ?? null, 'Nothing deduplicated on the first send.' );

		// Identical event_id resent (what a flush-job retry does).
		$second = $client->ingest_catalog( array( $payload ) );
		self::assertSame(
			1,
			$second['deduplicated'] ?? null,
			'A resent event_id is counted in deduplicated (D6 integer count) — the flush job marks the row sent, NOT a retry.'
		);
		self::assertTrue( (bool) ( $second['deduplicated_all'] ?? false ), 'A pure no-op retry flags deduplicated_all.' );
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
		( new CatalogHookHandler( $queue, $builder, $settings, new SiteLocaleAdapter() ) )->on_save_product( $product_id );

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

	public function test_multilingual_name_is_sent_as_a_lang_value_object(): void {
		// CC.3: with a multilingual detector, name/description/product_url go on
		// the wire as `{lang: value}` objects. Proves the object form survives
		// the real JSON round-trip to the engine (the mock accepts both forms;
		// this asserts which one was sent).
		$base = (string) self::$engine->base_url();
		EnvSeed::connect(
			array(
				'engine_base_url' => $base,
				'endpoints'       => self::mock_endpoints( $base ),
			)
		);

		$detector = $this->createMock( DetectorInterface::class );
		$detector->method( 'get_canonical_post_id' )->willReturnArgument( 0 );
		$detector->method( 'get_translations' )->willReturn(
			array(
				'name'        => array( 'et' => 'Eesti nimi', 'en' => 'English name' ),
				'description' => array( 'et' => 'Eesti kirjeldus', 'en' => 'English description' ),
				'product_url' => array( 'et' => 'https://shop.test/et/p', 'en' => 'https://shop.test/en/p' ),
			)
		);

		$settings = new RecEngineSettings();
		$queue    = new IngestQueue();
		$builder  = new CatalogPayloadBuilder( $detector );
		$product  = $this->make_product( 'CAT-ML-1', '9.99' );

		// Creating the product fired the live hook (connected) and enqueued a
		// row via Bootstrap's builder — clear it so only this test's row (built
		// with the multilingual detector) reaches the engine.
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
		$wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}smly_rec_event_queue" );

		$queue->enqueue( CatalogHookHandler::EVENT_CATALOG_UPSERT, (string) $product->get_id(), array() );

		$flusher = new IngestFlusher(
			$queue,
			$builder,
			$settings,
			static function () use ( $settings ): Client {
				return new Client( $settings->api_key(), $settings->base_url(), $settings->endpoints(), 2 );
			}
		);
		self::assertSame( 1, $flusher->flush()['sent'] );

		$names = self::$engine->state()['last_catalog_names'] ?? array();
		self::assertSame(
			array( array( 'et' => 'Eesti nimi', 'en' => 'English name' ) ),
			$names,
			'name reached the engine as a {lang:value} object, not a flattened string.'
		);
	}

	public function test_catalog_d6_partial_success_marks_errored_product_failed(): void {
		// N-7 lock fix: catalog is D6 now. A batch with one rejected product
		// (mock `D6ERR` sku trigger) must mark exactly that row FAILED and the
		// rest sent — before N-7 the catalog flusher marked the whole batch
		// sent on any 2xx, silently losing the rejected product.
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

		CatalogHookHandler::reset_seen();
		$good    = $this->make_product( 'CAT-D6-OK', '9.99' );
		$bad     = $this->make_product( 'D6ERR-CAT-BAD', '9.99' );
		$handler = new CatalogHookHandler( $queue, $builder, $settings, new SiteLocaleAdapter() );
		$handler->on_save_product( (int) $good->get_id() );
		$handler->on_save_product( (int) $bad->get_id() );

		$flusher = new IngestFlusher(
			$queue,
			$builder,
			$settings,
			static function () use ( $settings ): Client {
				return new Client( $settings->api_key(), $settings->base_url(), $settings->endpoints(), 2 );
			}
		);
		$stats = $flusher->flush();

		self::assertSame( 1, $stats['sent'], 'The valid product is processed → sent.' );
		self::assertSame( 1, $stats['failed'], 'The D6ERR product is marked failed — not silently sent (the lock fix).' );
		self::assertSame( array(), $queue->pending( 10 ), 'Both rows reached a terminal state.' );
	}

	public function test_trash_keeps_product_in_stock_false_and_untrash_restores(): void {
		// Trashing is NOT a delete (before_delete_post never fires for it), so
		// Bootstrap routes wp_trash_post → on_delete_product: the product stays in
		// the engine catalog as an in_stock=false UPSERT (kept for the order-history
		// join), not dropped. Untrash re-syncs it back to in_stock=true.
		$base = (string) self::$engine->base_url();
		EnvSeed::connect(
			array(
				'engine_base_url' => $base,
				'endpoints'       => self::mock_endpoints( $base ),
			)
		);

		self::assertNotFalse( has_action( 'wp_trash_post' ), 'Bootstrap must route product trashing through the catalog handler.' );
		self::assertNotFalse( has_action( 'untrashed_post' ), 'Bootstrap must re-sync a product on untrash.' );

		// A category is required for the removal (engine needs category_path).
		$product = $this->make_categorized_product( 'CAT-TRASH-1', '7.50' );
		$pid     = (int) $product->get_id();

		CatalogHookHandler::reset_seen();
		$this->truncate_queue(); // ignore the create-time save-hook row.

		// --- Trash → catalog.delete (flusher stamps in_stock=false) ---
		wp_trash_post( $pid );
		self::assertSame( 'trash', get_post_status( $pid ), 'Precondition: the product is trashed, not permanently deleted.' );

		$queue = new IngestQueue();
		self::assertCount(
			1,
			$queue->pending( 10, array( CatalogHookHandler::EVENT_CATALOG_DELETE ) ),
			'Trashing enqueues a catalog.delete (kept as in_stock=false), not nothing.'
		);

		$this->flush_catalog( $queue );
		$in_stock = self::$engine->state()['last_catalog_in_stock'] ?? array();
		self::assertFalse( $in_stock['CAT-TRASH-1'] ?? null, 'A trashed product reaches the engine in_stock=false — kept for the join, not recommended.' );

		// --- Untrash → catalog.upsert (real stock = in_stock=true) ---
		RecEngineMockServer::reset();
		CatalogHookHandler::reset_seen();
		$this->truncate_queue();

		wp_untrash_post( $pid );
		// Some WP versions restore an untrashed post to 'draft'; force publish so
		// is_in_stock() reflects a live product (status doesn't gate in_stock, but
		// keep the fixture realistic).
		wp_update_post( array( 'ID' => $pid, 'post_status' => 'publish' ) );

		self::assertNotEmpty(
			$queue->pending( 10, array( CatalogHookHandler::EVENT_CATALOG_UPSERT ) ),
			'Untrashing re-syncs the product as a catalog.upsert.'
		);

		$this->flush_catalog( $queue );
		$in_stock = self::$engine->state()['last_catalog_in_stock'] ?? array();
		self::assertTrue( $in_stock['CAT-TRASH-1'] ?? null, 'Untrash restores in_stock=true — the product is sellable again.' );
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

	public function test_variation_stock_change_enqueues_catalog_upsert(): void {
		// Found 2026-06-12: variations fire woocommerce_variation_set_stock_status,
		// NOT the parent-product hook — only the parent hook was registered, so
		// a variation selling out never refreshed its catalog in_stock and the
		// engine kept recommending it. Assert the REAL registration (Bootstrap
		// wiring, not a hand-called handler) and the queue row it produces.
		$this->connected_client(); // seeds is_connected so the gate is open.

		self::assertNotFalse(
			has_action( 'woocommerce_variation_set_stock_status' ),
			'Bootstrap must register the catalog handler on the VARIATION stock hook.'
		);
		self::assertNotFalse( has_action( 'woocommerce_product_set_stock_status' ) );

		$parent = new \WC_Product_Variable();
		$parent->set_name( 'Var Stock Parent' );
		$parent_id                = (int) $parent->save();
		$this->created_products[] = $parent_id;

		$variation = new \WC_Product_Variation();
		$variation->set_parent_id( $parent_id );
		$variation->set_regular_price( '4.00' );
		$variation->set_manage_stock( false );
		$variation->set_stock_status( 'instock' );
		$variation_id             = (int) $variation->save();
		$this->created_products[] = $variation_id;

		$queue  = new IngestQueue();
		$before = count( $queue->pending( 200, array( CatalogHookHandler::EVENT_CATALOG_UPSERT ) ) );

		CatalogHookHandler::reset_seen();
		$loaded = wc_get_product( $variation_id );
		$loaded->set_stock_status( 'outofstock' );
		$loaded->save(); // fires woocommerce_variation_set_stock_status through real WC.

		$rows      = $queue->pending( 200, array( CatalogHookHandler::EVENT_CATALOG_UPSERT ) );
		$entity_ids = array_map(
			static fn( array $row ): string => (string) $row['entity_id'],
			$rows
		);

		self::assertContains(
			(string) $variation_id,
			$entity_ids,
			'A variation stock flip must enqueue a catalog.upsert for THE VARIATION (the engine ingests variations as units).'
		);
		self::assertGreaterThan( $before, count( $rows ) );
	}

	public function test_taxonomy_attribute_wires_term_labels_not_ids(): void {
		// Engine ask 2026-06-12: a REAL WC taxonomy attribute's get_options()
		// returns term IDS (`pa_kaubamargid: ["398"]` was what the engine
		// received from the pilot) — the wire must carry term NAMES, or the
		// engine cannot derive brand / life_stage / pack_size rules. The unit
		// suite fakes the attribute object; only a real WC_Product_Attribute
		// exhibits the id behavior, hence this test (LESSONS §2.4).
		$attr_id = wc_create_attribute(
			array(
				'name'         => 'Testbrand',
				'slug'         => 'testbrand',
				'type'         => 'select',
				'order_by'     => 'menu_order',
				'has_archives' => false,
			)
		);
		self::assertIsInt( $attr_id, 'Precondition: attribute taxonomy created.' );

		// WC registers attribute taxonomies on init — this request predates
		// the new one, so register it manually for the test's lifetime.
		register_taxonomy( 'pa_testbrand', array( 'product' ) );
		$term = wp_insert_term( 'Brit Care', 'pa_testbrand' );
		self::assertIsArray( $term );

		try {
			$product = $this->make_product( 'CAT-ATTR-1', '3.00' );
			$pid     = $product->get_id();
			wp_set_object_terms( $pid, array( (int) $term['term_id'] ), 'pa_testbrand' );

			$attribute = new \WC_Product_Attribute();
			$attribute->set_id( (int) $attr_id );
			$attribute->set_name( 'pa_testbrand' );
			$attribute->set_options( array( (int) $term['term_id'] ) );
			$attribute->set_visible( true );
			$product->set_attributes( array( $attribute ) );
			$product->save();

			$payload = ( new CatalogPayloadBuilder() )->build( wc_get_product( $pid ), 'u-attr' );

			self::assertArrayHasKey( 'raw_attributes', $payload );
			self::assertSame(
				array( 'Brit Care' ),
				$payload['raw_attributes']['pa_testbrand'],
				'Wire must carry the term LABEL — a numeric term id here is the pilot bug regressing.'
			);
		} finally {
			wp_delete_term( (int) $term['term_id'], 'pa_testbrand' );
			wc_delete_attribute( (int) $attr_id );
			unregister_taxonomy( 'pa_testbrand' );
		}
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

	/**
	 * A product with a real product_cat term so its catalog object carries a
	 * non-empty category_path — required for the trash/delete removal to pass
	 * is_removable (the engine rejects a blank category_path).
	 */
	private function make_categorized_product( string $sku, string $price ): \WC_Product {
		$cat = term_exists( 'rec-trash-cat', 'product_cat' );
		if ( ! $cat ) {
			$cat = wp_insert_term( 'Rec Trash Cat', 'product_cat', array( 'slug' => 'rec-trash-cat' ) );
		}
		$term_id = (int) ( is_array( $cat ) ? $cat['term_id'] : $cat );

		$product = $this->make_product( $sku, $price );
		wp_set_object_terms( (int) $product->get_id(), array( $term_id ), 'product_cat' );

		$loaded = wc_get_product( (int) $product->get_id() );
		self::assertInstanceOf( \WC_Product::class, $loaded );
		return $loaded;
	}

	private function flush_catalog( IngestQueue $queue ): void {
		$settings = new RecEngineSettings();
		$flusher  = new IngestFlusher(
			$queue,
			new CatalogPayloadBuilder(),
			$settings,
			static function () use ( $settings ): Client {
				return new Client( $settings->api_key(), $settings->base_url(), $settings->endpoints(), 2 );
			}
		);
		$flusher->flush();
	}

	private function truncate_queue(): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
		$wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}smly_rec_event_queue" );
	}
}
