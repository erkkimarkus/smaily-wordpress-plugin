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
use Smaily\Connect\Multilingual\DetectorInterface;
use Smaily\Connect\Multilingual\SiteLocaleAdapter;
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

	public function test_translations_collapse_to_one_canonical_row(): void {
		// Two published products; the detector says the higher-id one (the
		// "translation") collapses to the lower-id one (the "canonical"). The
		// translation is SKIPPED — exactly one row, keyed on the canonical,
		// reaches the engine (P1, the core fix).
		$canonical   = $this->make_product( 'CANON-A' );
		$translation = $this->make_product( 'TRANS-B' );

		// Creating the products fired the live save_post_product hook (the env
		// is connected), enqueuing both. Clear that so the assertion sees only
		// what the BACKFILL enqueues — the unit under test here.
		$this->truncate_queue();

		$job = $this->job( 100, null, $this->mapping_detector( array( $translation => $canonical ) ) );
		$job->start();

		$result = $this->run_to_completion( $job );

		self::assertSame( 2, $result['processed'], 'Both posts were walked (progress counts every enumerated post).' );
		self::assertSame( 1, $result['sent'], 'The translation collapsed into the canonical — one row sent, not two.' );
		self::assertSame(
			array( 'CANON-A' ),
			self::$engine->state()['last_catalog_skus'] ?? array(),
			'The canonical (default-language) product is the one ingested; the translation was skipped.'
		);
	}

	public function test_draft_canonical_does_not_drop_a_published_translation(): void {
		// Edge: the default-language post is a draft (NOT enumerated), but a
		// translation is published. Skipping it would drop the product — instead
		// the published post stands in. No silent drop (LESSONS §2.11).
		$canonical = $this->make_product( 'DRAFT-A' );
		wp_update_post(
			array(
				'ID'          => $canonical,
				'post_status' => 'draft',
			)
		);
		$translation = $this->make_product( 'PUB-B' );

		$this->truncate_queue(); // ignore the live-hook rows from creation.

		$job = $this->job( 100, null, $this->mapping_detector( array( $translation => $canonical ) ) );
		$job->start();

		$result = $this->run_to_completion( $job );

		self::assertSame( 1, $result['sent'], 'The published translation is ingested — not dropped for its draft canonical.' );
		self::assertSame(
			array( 'PUB-B' ),
			self::$engine->state()['last_catalog_skus'] ?? array(),
			'The published post stands in; its draft default-language canonical is not enumerable.'
		);
	}

	/**
	 * Drive a backfill job to completion, returning the cumulative
	 * processed/sent/failed across its batches.
	 *
	 * @return array{processed: int, sent: int, failed: int}
	 */
	private function run_to_completion( CatalogBackfillJob $job ): array {
		$totals = array(
			'processed' => 0,
			'sent'      => 0,
			'failed'    => 0,
		);
		$guard = 0;
		do {
			$result              = $job->process_batch();
			$totals['processed'] += (int) $result['processed'];
			$totals['sent']      += (int) $result['sent'];
			$totals['failed']    += (int) $result['failed'];
		} while ( empty( $result['completed'] ) && ++$guard < 20 );

		return $totals;
	}

	// --- helpers --------------------------------------------------------

	private function job( int $batch_size = 100, ?IngestQueue $queue = null, ?DetectorInterface $detector = null ): CatalogBackfillJob {
		$settings = new RecEngineSettings();
		$queue    = $queue ?? new IngestQueue();
		$builder  = new CatalogPayloadBuilder();
		$detector = $detector ?? new SiteLocaleAdapter(); // single-language passthrough.
		$flusher  = new IngestFlusher(
			$queue,
			$builder,
			$settings,
			static function () use ( $settings ): Client {
				return new Client( $settings->api_key(), $settings->base_url(), $settings->endpoints(), 2 );
			}
		);

		if ( $batch_size === 100 ) {
			return new CatalogBackfillJob( $queue, $flusher, $builder, $detector );
		}

		return new class( $queue, $flusher, $builder, $detector, $batch_size ) extends CatalogBackfillJob {
			private int $bs;

			public function __construct( IngestQueue $queue, IngestFlusher $flusher, CatalogPayloadBuilder $builder, DetectorInterface $detector, int $bs ) {
				parent::__construct( $queue, $flusher, $builder, $detector );
				$this->bs = $bs;
			}

			protected function batch_size(): int {
				return $this->bs;
			}
		};
	}

	/**
	 * A detector double that maps the given post ids to canonical ids (missing
	 * keys pass through). Stands in for WPML/Polylang's translation linkage so
	 * the collapse logic is exercised without a configured multilingual plugin.
	 *
	 * @param array<int, int> $map post id → canonical id.
	 */
	private function mapping_detector( array $map ): DetectorInterface {
		$detector = $this->createMock( DetectorInterface::class );
		$detector->method( 'get_canonical_post_id' )->willReturnCallback(
			static fn ( int $id ): int => $map[ $id ] ?? $id
		);
		return $detector;
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
