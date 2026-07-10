<?php
/**
 * CatalogBackfillJob tests — the per-record enqueue branch: a PUBLISHED post
 * upserts (flusher loads fresh, real stock); a TRASHED post is kept as an
 * in_stock=false removal (catalog.delete with a captured object) so its
 * order-history join survives; the multilingual collapse and the
 * is_removable guard hold on both.
 *
 * @package Smaily\Connect\Tests
 */

declare(strict_types=1);

namespace Smaily\Connect\Tests\Unit\Smaily\RecEngine\Backfill;

use PHPUnit\Framework\TestCase;
use Smaily\Connect\Integrations\WooCommerce\CatalogHookHandler;
use Smaily\Connect\Multilingual\DetectorInterface;
use Smaily\Connect\Smaily\RecEngine\Backfill\CatalogBackfillJob;
use Smaily\Connect\Smaily\RecEngine\CatalogPayloadBuilder;
use Smaily\Connect\Smaily\RecEngine\IngestFlusher;
use Smaily\Connect\Smaily\RecEngine\IngestQueue;

final class CatalogBackfillJobTest extends TestCase {

	public function test_published_product_enqueues_upsert(): void {
		$queue   = $this->fake_queue();
		$product = $this->fake_product( 100, 'SKU-PUB' );
		$job     = $this->job( $queue, array( 100 => $product ), array( $product ), array( 'status' => array( 100 => 'publish' ) ) );

		$job->run( 100 );

		self::assertCount( 1, $queue->enqueued );
		self::assertSame( CatalogHookHandler::EVENT_CATALOG_UPSERT, $queue->enqueued[0]['type'] );
		self::assertSame( '100', $queue->enqueued[0]['entity_id'] );
		self::assertSame( array(), $queue->enqueued[0]['payload'], 'Upsert payload is empty — the flusher loads fresh.' );
	}

	public function test_trashed_product_is_kept_as_catalog_delete_with_captured_object(): void {
		// A trashed product a customer once bought stays in the engine catalog as
		// in_stock=false (the flusher stamps it on the catalog.delete row at send
		// time) so the order-history join / training survives — never dropped.
		$queue   = $this->fake_queue();
		$product = $this->fake_product( 100, 'SKU-TRASH' );
		$job     = $this->job( $queue, array( 100 => $product ), array( $product ), array( 'status' => array( 100 => 'trash' ) ) );

		$job->run( 100 );

		self::assertCount( 1, $queue->enqueued );
		self::assertSame( CatalogHookHandler::EVENT_CATALOG_DELETE, $queue->enqueued[0]['type'] );
		self::assertSame( '100', $queue->enqueued[0]['entity_id'] );
		$payload = $queue->enqueued[0]['payload'];
		self::assertArrayHasKey( 'object', $payload, 'A trashed post captures its object now (still loadable) — the flusher stamps in_stock=false.' );
		self::assertSame( 'SKU-TRASH', $payload['object']['sku'] );
	}

	public function test_trashed_product_with_blank_category_path_is_skipped(): void {
		// Same guard as the live delete hook: a removal object with an empty
		// category_path is contract-guaranteed to 400, so it is not enqueued.
		$queue   = $this->fake_queue();
		$product = $this->fake_product( 100, 'SKU-NOCAT', '', 'https://shop.test/p' );
		$job     = $this->job( $queue, array( 100 => $product ), array( $product ), array( 'status' => array( 100 => 'trash' ) ) );

		$job->run( 100 );

		self::assertSame( array(), $queue->enqueued, 'A trashed product with no category_path cannot be sent — skipped, not a 400-bound row.' );
	}

	public function test_trashed_product_with_blank_product_url_is_skipped(): void {
		$queue   = $this->fake_queue();
		$product = $this->fake_product( 100, 'SKU-NOURL', 'food/dry', '' );
		$job     = $this->job( $queue, array( 100 => $product ), array( $product ), array( 'status' => array( 100 => 'trash' ) ) );

		$job->run( 100 );

		self::assertSame( array(), $queue->enqueued );
	}

	public function test_translation_of_published_canonical_is_collapsed_away(): void {
		// The collapse keys on a PUBLISHED canonical: post 200 → canonical 100,
		// which is itself enumerated-as-publish, so 200 is skipped (the canonical
		// enqueues itself on its own cursor step). Holds for trash too.
		$queue     = $this->fake_queue();
		$canonical = $this->fake_product( 100, '' );
		$job       = $this->job(
			$queue,
			array( 100 => $canonical ),
			array( $canonical ),
			array(
				'canonical'  => array( 200 => 100 ),
				'enumerated' => array( 100 ),
				'status'     => array( 200 => 'trash' ),
			)
		);

		$job->run( 200 );

		self::assertSame( array(), $queue->enqueued, 'A translation whose canonical is a published product is collapsed — no duplicate SKU.' );
	}

	public function test_variable_trashed_product_fans_out_each_variation_as_delete(): void {
		$queue  = $this->fake_queue();
		$parent = $this->fake_product( 50, '' ); // variable parent, skuless.
		$v1     = $this->fake_product( 101, 'V-1' );
		$v2     = $this->fake_product( 102, 'V-2' );
		$job    = $this->job( $queue, array( 50 => $parent ), array( $v1, $v2 ), array( 'status' => array( 50 => 'trash' ) ) );

		$job->run( 50 );

		self::assertCount( 2, $queue->enqueued, 'Each variation of a trashed variable product is kept as its own in_stock=false unit.' );
		self::assertSame( CatalogHookHandler::EVENT_CATALOG_DELETE, $queue->enqueued[0]['type'] );
		self::assertSame( '101', $queue->enqueued[0]['entity_id'] );
		self::assertSame( CatalogHookHandler::EVENT_CATALOG_DELETE, $queue->enqueued[1]['type'] );
		self::assertSame( '102', $queue->enqueued[1]['entity_id'] );
	}

	// --- doubles -------------------------------------------------------------

	private function fake_queue(): IngestQueue {
		return new class() extends IngestQueue {
			/** @var array<int, array{type:string, entity_id:string, payload:array<string,mixed>}> */
			public array $enqueued = array();

			public function enqueue( string $event_type, string $entity_id, array $payload, ?string $event_uuid = null, ?string $flush_hook = null, ?string $flush_group = null ): ?int {
				$this->enqueued[] = array(
					'type'      => $event_type,
					'entity_id' => $entity_id,
					'payload'   => $payload,
				);
				return count( $this->enqueued );
			}
		};
	}

	/**
	 * @param array<int, \WC_Product> $units builder->expand() result.
	 */
	private function fake_builder( array $units ): CatalogPayloadBuilder {
		return new class( $units ) extends CatalogPayloadBuilder {
			/** @var array<int, \WC_Product> */
			private array $units;
			/** @param array<int, \WC_Product> $units */
			public function __construct( array $units ) {
				$this->units = $units;
			}
			public function expand( \WC_Product $product ): array {
				return $this->units;
			}
			public function build( \WC_Product $product, string $event_uuid ): array {
				$object = array( 'sku' => (string) $product->get_sku(), 'event_id' => $event_uuid, 'in_stock' => true );
				// The real builder always carries category_path + product_url; the
				// removal guard (is_removable) keys on them, so mirror them here.
				if ( method_exists( $product, 'smly_category_path' ) ) {
					$object['category_path'] = $product->smly_category_path();
					$object['product_url']   = $product->smly_product_url();
				}
				return $object;
			}
		};
	}

	/**
	 * @param array<int, \WC_Product>                        $products_by_id get_product() lookup.
	 * @param array<int, \WC_Product>                        $expand_units   builder->expand() result.
	 * @param array{canonical?: array<int,int>, status?: array<int,string>, enumerated?: array<int,int>} $opts
	 */
	private function job( IngestQueue $queue, array $products_by_id, array $expand_units, array $opts = array() ): CatalogBackfillJob {
		$canonical_map = $opts['canonical'] ?? array();
		$status_map    = $opts['status'] ?? array();
		$enumerated    = $opts['enumerated'] ?? array();

		$detector = $this->createMock( DetectorInterface::class );
		$detector->method( 'get_canonical_post_id' )->willReturnCallback(
			static fn ( int $id ): int => $canonical_map[ $id ] ?? $id
		);

		$builder = $this->fake_builder( $expand_units );
		$flusher = $this->createMock( IngestFlusher::class );

		return new class( $queue, $flusher, $builder, $detector, $products_by_id, $status_map, $enumerated ) extends CatalogBackfillJob {
			/** @var array<int, \WC_Product> */
			private array $products_by_id;
			/** @var array<int, string> */
			private array $status_map;
			/** @var array<int, int> */
			private array $enumerated;

			/**
			 * @param array<int, \WC_Product> $products_by_id
			 * @param array<int, string>      $status_map
			 * @param array<int, int>         $enumerated
			 */
			public function __construct( IngestQueue $queue, IngestFlusher $flusher, CatalogPayloadBuilder $builder, DetectorInterface $detector, array $products_by_id, array $status_map, array $enumerated ) {
				parent::__construct( $queue, $flusher, $builder, $detector );
				$this->products_by_id = $products_by_id;
				$this->status_map     = $status_map;
				$this->enumerated     = $enumerated;
			}

			public function run( int $id ): void {
				$this->enqueue_record( $id );
			}

			protected function get_product( int $product_id ): ?\WC_Product {
				return $this->products_by_id[ $product_id ] ?? null;
			}

			protected function post_status( int $post_id ): string {
				return $this->status_map[ $post_id ] ?? 'publish';
			}

			protected function canonical_is_enumerated( int $canonical_id ): bool {
				return in_array( $canonical_id, $this->enumerated, true );
			}
		};
	}

	private function fake_product( int $id, string $sku, string $category_path = 'food/dry', string $product_url = 'https://shop.test/p' ): \WC_Product {
		return new class( $id, $sku, $category_path, $product_url ) extends \WC_Product {
			private int $id;
			private string $sku;
			private string $category_path;
			private string $product_url;
			public function __construct( int $id, string $sku, string $category_path, string $product_url ) {
				$this->id            = $id;
				$this->sku           = $sku;
				$this->category_path = $category_path;
				$this->product_url   = $product_url;
			}
			public function get_id( $context = 'view' ) {
				return $this->id;
			}
			public function get_sku( $context = 'view' ) {
				return $this->sku;
			}
			public function smly_category_path(): string {
				return $this->category_path;
			}
			public function smly_product_url(): string {
				return $this->product_url;
			}
		};
	}
}

// Minimal WC_Product shim for the anonymous fakes to extend.
if ( ! class_exists( \WC_Product::class ) ) {
	// phpcs:ignore Squiz.Commenting.ClassComment.Missing -- test shim.
	eval(
		<<<'PHP'
		class WC_Product {
			public function get_id( $context = 'view' ) { return 0; }
			public function get_parent_id( $context = 'view' ) { return 0; }
			public function get_sku( $context = 'view' ) { return ''; }
		}
PHP
	);
}
