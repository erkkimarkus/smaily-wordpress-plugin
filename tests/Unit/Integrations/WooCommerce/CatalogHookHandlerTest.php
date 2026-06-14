<?php
/**
 * CatalogHookHandler tests — product hooks → ingest queue, the
 * is_connected gate, variable-product fan-out, delete-object capture,
 * per-request dedupe, and multilingual canonical collapse (P1 + P4).
 *
 * @package Smaily\Connect\Tests
 */

declare(strict_types=1);

namespace Smaily\Connect\Tests\Unit\Integrations\WooCommerce;

use PHPUnit\Framework\TestCase;
use Smaily\Connect\Integrations\WooCommerce\CatalogHookHandler;
use Smaily\Connect\Multilingual\DetectorInterface;
use Smaily\Connect\Settings\RecEngineSettings;
use Smaily\Connect\Smaily\RecEngine\CatalogPayloadBuilder;
use Smaily\Connect\Smaily\RecEngine\IngestQueue;

final class CatalogHookHandlerTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		CatalogHookHandler::reset_seen();
	}

	protected function tearDown(): void {
		CatalogHookHandler::reset_seen();
		parent::tearDown();
	}

	public function test_save_enqueues_catalog_upsert_when_connected(): void {
		$queue   = $this->fake_queue();
		$product = $this->fake_product( 100, 'ACA-1' );
		$handler = $this->handler( $queue, true, array( 100 => $product ), array( $product ) );

		$handler->on_save_product( 100 );

		self::assertCount( 1, $queue->enqueued );
		self::assertSame( CatalogHookHandler::EVENT_CATALOG_UPSERT, $queue->enqueued[0]['type'] );
		self::assertSame( '100', $queue->enqueued[0]['entity_id'] );
		self::assertSame( array(), $queue->enqueued[0]['payload'], 'Upsert payload is empty — the flusher loads fresh.' );
	}

	public function test_no_enqueue_when_not_connected(): void {
		$queue   = $this->fake_queue();
		$product = $this->fake_product( 100, 'ACA-1' );
		$handler = $this->handler( $queue, false, array( 100 => $product ), array( $product ) );

		$handler->on_save_product( 100 );

		self::assertSame( array(), $queue->enqueued, 'No tenant connected → the catalog gate is shut.' );
	}

	public function test_variable_product_fans_out_to_each_variation(): void {
		$queue   = $this->fake_queue();
		$parent  = $this->fake_product( 50, '' ); // variable parent (skuless)
		$v1      = $this->fake_product( 101, 'V-1' );
		$v2      = $this->fake_product( 102, 'V-2' );
		$handler = $this->handler( $queue, true, array( 50 => $parent ), array( $v1, $v2 ) );

		$handler->on_save_product( 50 );

		self::assertCount( 2, $queue->enqueued );
		self::assertSame( '101', $queue->enqueued[0]['entity_id'] );
		self::assertSame( '102', $queue->enqueued[1]['entity_id'] );
	}

	public function test_skuless_unit_is_enqueued_for_synthetic_keying(): void {
		// F3-36: SkuResolver keys SKU-less units wc-{id} in the builder, so
		// the handler no longer drops them (the old drop silently emptied a
		// SKU-less store's catalog — the pilot find).
		$queue   = $this->fake_queue();
		$product = $this->fake_product( 100, '' );
		$handler = $this->handler( $queue, true, array( 100 => $product ), array( $product ) );

		$handler->on_save_product( 100 );

		self::assertCount( 1, $queue->enqueued, 'SKU-less unit is enqueued; build() supplies the synthetic key.' );
		self::assertSame( '100', $queue->enqueued[0]['entity_id'] );
	}

	public function test_repeat_save_in_one_request_is_deduped(): void {
		$queue   = $this->fake_queue();
		$product = $this->fake_product( 100, 'ACA-1' );
		$handler = $this->handler( $queue, true, array( 100 => $product ), array( $product ) );

		$handler->on_save_product( 100 );
		$handler->on_save_product( 100 );

		self::assertCount( 1, $queue->enqueued, 'Repeated save_post_product in one request collapses to a single row.' );
	}

	public function test_delete_enqueues_catalog_delete_with_captured_object(): void {
		$queue   = $this->fake_queue();
		$product = $this->fake_product( 100, 'GONE-1' );
		$handler = $this->handler( $queue, true, array( 100 => $product ), array( $product ) );

		$handler->on_delete_product( 100 );

		self::assertCount( 1, $queue->enqueued );
		self::assertSame( CatalogHookHandler::EVENT_CATALOG_DELETE, $queue->enqueued[0]['type'] );
		$payload = $queue->enqueued[0]['payload'];
		self::assertArrayHasKey( 'object', $payload, 'Delete captures the full object now (the product is still loadable).' );
		self::assertSame( 'GONE-1', $payload['object']['sku'] );
	}

	// --- multilingual canonical collapse (P1 + P4) ---------------------------

	public function test_save_of_a_translation_resyncs_the_canonical_product(): void {
		// Saving the LV translation (200) re-syncs the ET canonical (100), so
		// the engine row stays single and keyed on the canonical unit.
		$queue     = $this->fake_queue();
		$canonical = $this->fake_product( 100, '' );
		$handler   = $this->handler(
			$queue,
			true,
			array( 100 => $canonical ),
			array( $canonical ),
			$this->detector( array( 200 => 100 ) )
		);

		$handler->on_save_product( 200 );

		self::assertCount( 1, $queue->enqueued );
		self::assertSame( CatalogHookHandler::EVENT_CATALOG_UPSERT, $queue->enqueued[0]['type'] );
		self::assertSame( '100', $queue->enqueued[0]['entity_id'], 'The canonical product is enqueued, not the translation.' );
	}

	public function test_deleting_a_translation_resyncs_canonical_not_delete(): void {
		// P4: removing the LV translation must NOT mark the canonical SKU gone —
		// the product still exists in ET. It re-syncs (upsert) instead.
		$queue     = $this->fake_queue();
		$canonical = $this->fake_product( 100, '' );
		$handler   = $this->handler(
			$queue,
			true,
			array( 100 => $canonical ),
			array( $canonical ),
			$this->detector( array( 200 => 100 ) )
		);

		$handler->on_delete_product( 200 );

		self::assertCount( 1, $queue->enqueued );
		self::assertSame(
			CatalogHookHandler::EVENT_CATALOG_UPSERT,
			$queue->enqueued[0]['type'],
			'Deleting a translation re-syncs the canonical (upsert), it does not delete the SKU.'
		);
		self::assertSame( '100', $queue->enqueued[0]['entity_id'] );
	}

	public function test_deleting_the_canonical_enqueues_delete(): void {
		// The canonical (default-language) product itself is deleted → mark the
		// SKU unavailable (canonical maps to itself → the delete branch).
		$queue     = $this->fake_queue();
		$canonical = $this->fake_product( 100, 'GONE-CANON' );
		$handler   = $this->handler(
			$queue,
			true,
			array( 100 => $canonical ),
			array( $canonical ),
			$this->detector( array() ) // 100 → 100 passthrough.
		);

		$handler->on_delete_product( 100 );

		self::assertCount( 1, $queue->enqueued );
		self::assertSame( CatalogHookHandler::EVENT_CATALOG_DELETE, $queue->enqueued[0]['type'] );
	}

	// --- never-published-artifact delete skip (the auto-draft GC burst) ------

	public function test_delete_of_object_with_blank_category_path_is_not_enqueued(): void {
		// WordPress's daily auto-draft GC deletes piles of AUTO-DRAFT products,
		// firing before_delete_post. They have an empty category_path and were
		// never ingested (backfill is publish-only) — enqueuing a catalog.delete
		// the engine is guaranteed to 400 only litters the Event Log. Skip it.
		$queue   = $this->fake_queue();
		$product = $this->fake_product( 60233, 'wc-60233', '', 'https://miumjau.test/?post_type=product&p=60233' );
		$handler = $this->handler( $queue, true, array( 60233 => $product ), array( $product ) );

		$handler->on_delete_product( 60233 );

		self::assertSame( array(), $queue->enqueued, 'A removal object with an empty category_path is contract-guaranteed to 400 — not enqueued.' );
	}

	public function test_delete_of_object_with_blank_product_url_is_not_enqueued(): void {
		// product_url is the other REQUIRED non-empty field; an abandoned draft
		// whose permalink resolves empty is likewise skipped.
		$queue   = $this->fake_queue();
		$product = $this->fake_product( 27695, 'wc-27695', 'food/dry', '' );
		$handler = $this->handler( $queue, true, array( 27695 => $product ), array( $product ) );

		$handler->on_delete_product( 27695 );

		self::assertSame( array(), $queue->enqueued, 'A removal object with an empty product_url is contract-guaranteed to 400 — not enqueued.' );
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
	 * @param array<int, int> $map post id → canonical id (missing → passthrough).
	 */
	private function detector( array $map ): DetectorInterface {
		$detector = $this->createMock( DetectorInterface::class );
		$detector->method( 'get_canonical_post_id' )->willReturnCallback(
			static fn ( int $id ): int => $map[ $id ] ?? $id
		);
		return $detector;
	}

	/**
	 * @param array<int, \WC_Product> $products_by_id get_product() lookup table.
	 * @param array<int, \WC_Product> $expand_units   builder->expand() result.
	 */
	private function handler( IngestQueue $queue, bool $connected, array $products_by_id, array $expand_units, ?DetectorInterface $detector = null ): CatalogHookHandler {
		$settings = new class( $connected ) extends RecEngineSettings {
			private bool $connected;
			public function __construct( bool $connected ) {
				$this->connected = $connected;
			}
			public function is_connected(): bool {
				return $this->connected;
			}
		};

		$builder = new class( $expand_units ) extends CatalogPayloadBuilder {
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
				// delete guard (removable()) keys on them, so mirror them here. A
				// fake_product exposes them via smly_* accessors (defaults non-empty,
				// blank for the never-published-artifact skip cases).
				if ( method_exists( $product, 'smly_category_path' ) ) {
					$object['category_path'] = $product->smly_category_path();
					$object['product_url']   = $product->smly_product_url();
				}
				return $object;
			}
		};

		$detector = $detector ?? $this->detector( array() );

		return new class( $queue, $builder, $settings, $detector, $products_by_id ) extends CatalogHookHandler {
			/** @var array<int, \WC_Product> */
			private array $products_by_id;
			/** @param array<int, \WC_Product> $products_by_id */
			public function __construct( IngestQueue $queue, CatalogPayloadBuilder $builder, RecEngineSettings $settings, DetectorInterface $detector, array $products_by_id ) {
				parent::__construct( $queue, $builder, $settings, $detector );
				$this->products_by_id = $products_by_id;
			}
			protected function get_product( int $product_id ): ?\WC_Product {
				return $this->products_by_id[ $product_id ] ?? null;
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
			public function get_sku( $context = 'view' ) { return ''; }
		}
PHP
	);
}
