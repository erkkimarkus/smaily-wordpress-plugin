<?php
/**
 * CatalogHookHandler tests — product hooks → ingest queue, the
 * is_connected gate, variable-product fan-out, delete-object capture, and
 * per-request dedupe.
 *
 * @package Smaily\Connect\Tests
 */

declare(strict_types=1);

namespace Smaily\Connect\Tests\Unit\Integrations\WooCommerce;

use PHPUnit\Framework\TestCase;
use Smaily\Connect\Integrations\WooCommerce\CatalogHookHandler;
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
		$handler = $this->handler( $queue, true, $product, array( $product ) );

		$handler->on_save_product( 100 );

		self::assertCount( 1, $queue->enqueued );
		self::assertSame( CatalogHookHandler::EVENT_CATALOG_UPSERT, $queue->enqueued[0]['type'] );
		self::assertSame( '100', $queue->enqueued[0]['entity_id'] );
		self::assertSame( array(), $queue->enqueued[0]['payload'], 'Upsert payload is empty — the flusher loads fresh.' );
	}

	public function test_no_enqueue_when_not_connected(): void {
		$queue   = $this->fake_queue();
		$product = $this->fake_product( 100, 'ACA-1' );
		$handler = $this->handler( $queue, false, $product, array( $product ) );

		$handler->on_save_product( 100 );

		self::assertSame( array(), $queue->enqueued, 'No tenant connected → the catalog gate is shut.' );
	}

	public function test_variable_product_fans_out_to_each_variation(): void {
		$queue  = $this->fake_queue();
		$parent = $this->fake_product( 50, '' ); // variable parent (skuless)
		$v1     = $this->fake_product( 101, 'V-1' );
		$v2     = $this->fake_product( 102, 'V-2' );
		$handler = $this->handler( $queue, true, $parent, array( $v1, $v2 ) );

		$handler->on_save_product( 50 );

		self::assertCount( 2, $queue->enqueued );
		self::assertSame( '101', $queue->enqueued[0]['entity_id'] );
		self::assertSame( '102', $queue->enqueued[1]['entity_id'] );
	}

	public function test_skuless_unit_is_not_enqueued(): void {
		$queue   = $this->fake_queue();
		$product = $this->fake_product( 100, '' );
		$handler = $this->handler( $queue, true, $product, array( $product ) );

		$handler->on_save_product( 100 );

		self::assertSame( array(), $queue->enqueued, 'A unit without a SKU cannot be keyed by the engine — drop it.' );
	}

	public function test_repeat_save_in_one_request_is_deduped(): void {
		$queue   = $this->fake_queue();
		$product = $this->fake_product( 100, 'ACA-1' );
		$handler = $this->handler( $queue, true, $product, array( $product ) );

		$handler->on_save_product( 100 );
		$handler->on_save_product( 100 );

		self::assertCount( 1, $queue->enqueued, 'Repeated save_post_product in one request collapses to a single row.' );
	}

	public function test_delete_enqueues_catalog_delete_with_captured_object(): void {
		$queue   = $this->fake_queue();
		$product = $this->fake_product( 100, 'GONE-1' );
		$handler = $this->handler( $queue, true, $product, array( $product ) );

		$handler->on_delete_product( 100 );

		self::assertCount( 1, $queue->enqueued );
		self::assertSame( CatalogHookHandler::EVENT_CATALOG_DELETE, $queue->enqueued[0]['type'] );
		$payload = $queue->enqueued[0]['payload'];
		self::assertArrayHasKey( 'object', $payload, 'Delete captures the full object now (the product is still loadable).' );
		self::assertSame( 'GONE-1', $payload['object']['sku'] );
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
	 * @param array<int, \WC_Product> $expand_units
	 */
	private function handler( IngestQueue $queue, bool $connected, ?\WC_Product $product, array $expand_units ): CatalogHookHandler {
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
				return array( 'sku' => (string) $product->get_sku(), 'event_id' => $event_uuid, 'in_stock' => true );
			}
		};

		return new class( $queue, $builder, $settings, $product ) extends CatalogHookHandler {
			private ?\WC_Product $product;
			public function __construct( IngestQueue $queue, CatalogPayloadBuilder $builder, RecEngineSettings $settings, ?\WC_Product $product ) {
				parent::__construct( $queue, $builder, $settings );
				$this->product = $product;
			}
			protected function get_product( int $product_id ): ?\WC_Product {
				return $this->product;
			}
		};
	}

	private function fake_product( int $id, string $sku ): \WC_Product {
		return new class( $id, $sku ) extends \WC_Product {
			private int $id;
			private string $sku;
			public function __construct( int $id, string $sku ) {
				$this->id  = $id;
				$this->sku = $sku;
			}
			public function get_id( $context = 'view' ) {
				return $this->id;
			}
			public function get_sku( $context = 'view' ) {
				return $this->sku;
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
