<?php
/**
 * Integration: CatalogBackfillJob (3.5.0) — cursor traversal of existing
 * products into the SAME ingest queue + D6 flusher the live hook uses.
 *
 * @package Smaily\Connect\Tests\Integration
 */

declare(strict_types=1);

namespace Smaily\Connect\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Smaily\Connect\Integrations\WooCommerce\CatalogHookHandler;
use Smaily\Connect\Settings\RecEngineSettings;
use Smaily\Connect\Smaily\RecEngine\Backfill\AbstractBackfillJob;
use Smaily\Connect\Smaily\RecEngine\Backfill\CatalogBackfillJob;
use Smaily\Connect\Smaily\RecEngine\CatalogPayloadBuilder;
use Smaily\Connect\Smaily\RecEngine\Client;
use Smaily\Connect\Smaily\RecEngine\IngestFlusher;
use Smaily\Connect\Smaily\RecEngine\IngestQueue;
use Smaily\Connect\Tests\Integration\Fixtures\RecEngineMockServer;
use Smaily\Connect\Tests\Integration\Support\EnvScrub;
use Smaily\Connect\Tests\Integration\Support\EnvSeed;
use Smaily\Connect\Tests\Integration\Support\RestRequestHelper;

/**
 * The two properties that make backfill worth having:
 *   - RESUMABILITY — an interrupted run continues from the saved cursor, it
 *     does not restart from the top (the whole point for thousands of records).
 *   - BOUNDED QUEUE — decision 3.5 (b): each batch is flushed inline before the
 *     next is enqueued, so the queue never balloons to thousands of pending rows.
 */
final class RecEngineCatalogBackfillTest extends TestCase {

	private static ?RecEngineMockServer $engine = null;

	/** @var int[] */
	private array $created = array();

	public static function setUpBeforeClass(): void {
		self::$engine = RecEngineMockServer::start();
	}

	protected function setUp(): void {
		parent::setUp();
		if ( ! class_exists( 'WC_Product_Simple' ) ) {
			self::markTestSkipped( 'WooCommerce not active.' );
		}
		EnvScrub::reset();
		RecEngineMockServer::reset();
		$this->truncate_queue();
		$this->delete_all_products();

		$base = (string) self::$engine->base_url();
		EnvSeed::connect(
			array(
				'engine_base_url' => $base,
				'endpoints'       => array( 'ingest_catalog' => $base . '/api/v1/ingest/catalog' ),
			)
		);
	}

	protected function tearDown(): void {
		$this->delete_all_products();
		parent::tearDown();
	}

	public function test_start_seeds_a_products_row_keyed_on_rec_engine_target(): void {
		$this->make_product( 'BF-START-1' );
		$this->make_product( 'BF-START-2' );

		$job    = $this->job();
		$row_id = $job->start();
		self::assertGreaterThan( 0, $row_id );

		$row = $this->read_backfill_row();
		self::assertSame( 'products', $row['job_type'] );
		self::assertSame( AbstractBackfillJob::TARGET, $row['target'] );
		self::assertSame( 'running', $row['status'] );
		self::assertSame( 2, (int) $row['total_count'] );
		self::assertSame( 0, (int) $row['processed_count'] );
	}

	public function test_resumes_from_cursor_and_does_not_restart(): void {
		$p1 = $this->make_product( 'BF-RES-1' );
		$p2 = $this->make_product( 'BF-RES-2' );
		$p3 = $this->make_product( 'BF-RES-3' );

		$job = $this->job( 2 ); // batch_size = 2
		$job->start();

		// Batch 1 → p1, p2. Cursor lands on p2; not yet complete.
		$b1 = $job->process_batch();
		self::assertSame( 2, $b1['processed'] );
		self::assertFalse( $b1['completed'] );
		$row = $this->read_backfill_row();
		self::assertSame( (string) $p2, $row['cursor_value'], 'Cursor advanced to the 2nd product.' );
		self::assertSame( 2, (int) $row['processed_count'] );

		// Batch 2 resumes AFTER p2 → only p3 (id > cursor), then completes.
		$b2 = $job->process_batch();
		self::assertSame( 1, $b2['processed'], 'Resumed: only the 1 product after the cursor, not a restart.' );
		self::assertTrue( $b2['completed'] );
		$row = $this->read_backfill_row();
		self::assertSame( (string) $p3, $row['cursor_value'] );
		self::assertSame( 3, (int) $row['processed_count'], 'Cumulative 3, not restarted to 2.' );

		// The engine received p3 in the 2nd flush (one product), not p1/p2 again.
		$received = self::$engine->state()['last_catalog_received'] ?? array();
		self::assertCount( 1, is_array( $received ) ? $received : array() );
	}

	public function test_queue_is_bounded_after_each_batch(): void {
		$this->make_product( 'BF-BND-1' );
		$this->make_product( 'BF-BND-2' );
		$this->make_product( 'BF-BND-3' );

		$queue = new IngestQueue();
		$job   = $this->job( 2, $queue );
		$job->start();

		$job->process_batch();
		self::assertSame(
			array(),
			$queue->pending( 1000, array( CatalogHookHandler::EVENT_CATALOG_UPSERT, CatalogHookHandler::EVENT_CATALOG_DELETE ) ),
			'Inline flush drained the batch — the queue is empty before the next batch (bounded).'
		);
	}

	public function test_full_backfill_sends_every_product_and_completes(): void {
		for ( $i = 1; $i <= 5; $i++ ) {
			$this->make_product( 'BF-FULL-' . $i );
		}

		$job = $this->job( 2 );
		$job->start();

		$guard = 0;
		do {
			$result = $job->process_batch();
		} while ( empty( $result['completed'] ) && ++$guard < 20 );

		$row = $this->read_backfill_row();
		self::assertSame( 'completed', $row['status'] );
		self::assertSame( 5, (int) $row['processed_count'] );
		self::assertSame( 5, (int) $row['total_count'] );
	}

	public function test_endpoint_start_accepts_products_and_seeds_via_make_backfill_job(): void {
		$this->make_product( 'BF-EP-1' );
		$this->make_product( 'BF-EP-2' );

		RestRequestHelper::login_as_admin();
		$response = RestRequestHelper::post( '/backfill/start', array( 'job_type' => 'products' ) );

		// 200 (not 400 unsupported, not 503) proves the endpoint → Bootstrap::
		// make_backfill_job('products') → CatalogBackfillJob → start() path.
		self::assertSame( 200, $response->get_status() );
		self::assertSame( 2, $response->get_data()['total'] );
	}

	// --- helpers --------------------------------------------------------

	private function job( int $batch_size = 100, ?IngestQueue $queue = null ): CatalogBackfillJob {
		$settings = new RecEngineSettings();
		$queue    = $queue ?? new IngestQueue();
		$builder  = new CatalogPayloadBuilder();
		$flusher  = new IngestFlusher(
			$queue,
			$builder,
			$settings,
			static function () use ( $settings ): Client {
				return new Client( $settings->api_key(), $settings->base_url(), $settings->endpoints(), 2 );
			}
		);

		if ( $batch_size === 100 ) {
			return new CatalogBackfillJob( $queue, $flusher, $builder );
		}

		return new class( $queue, $flusher, $builder, $batch_size ) extends CatalogBackfillJob {
			private int $bs;

			public function __construct( IngestQueue $queue, IngestFlusher $flusher, CatalogPayloadBuilder $builder, int $bs ) {
				parent::__construct( $queue, $flusher, $builder );
				$this->bs = $bs;
			}

			protected function batch_size(): int {
				return $this->bs;
			}
		};
	}

	private function make_product( string $sku ): int {
		$existing = wc_get_product_id_by_sku( $sku );
		if ( $existing ) {
			wp_delete_post( $existing, true );
		}
		$product = new \WC_Product_Simple();
		$product->set_sku( $sku );
		$product->set_name( 'Backfill ' . $sku );
		$product->set_regular_price( '5.00' );
		$product->set_price( '5.00' );
		$product->set_stock_status( 'instock' );
		$id              = (int) $product->save();
		$this->created[] = $id;
		return $id;
	}

	private function delete_all_products(): void {
		$ids = get_posts(
			array(
				'post_type'      => 'product',
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
			)
		);
		foreach ( $ids as $id ) {
			wp_delete_post( (int) $id, true );
		}
		$this->created = array();
	}

	private function truncate_queue(): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
		$wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}smly_rec_event_queue" );
	}

	/**
	 * @return array<string, mixed>
	 */
	private function read_backfill_row(): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}smly_plus_backfill_job WHERE job_type = %s AND target = %s",
				'products',
				AbstractBackfillJob::TARGET
			),
			ARRAY_A
		);
		return is_array( $row ) ? $row : array();
	}
}
