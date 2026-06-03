<?php
/**
 * WC product hooks → rec-engine catalog ingest queue.
 *
 * @package Smaily\Connect\Integrations\WooCommerce
 */

declare(strict_types=1);

namespace Smaily\Connect\Integrations\WooCommerce;

defined( 'ABSPATH' ) || exit;

use Smaily\Connect\Settings\RecEngineSettings;
use Smaily\Connect\Smaily\RecEngine\CatalogPayloadBuilder;
use Smaily\Connect\Smaily\RecEngine\IngestQueue;

/**
 * Fans WooCommerce product changes into the rec-engine ingest queue
 * (PLUGIN_IMPLEMENTATION_WP.md §452): save / stock-change → catalog.upsert,
 * delete → catalog.delete. The IngestFlusher later turns each queued row
 * into a /api/v1/ingest/catalog object.
 *
 * Variable products fan out: each variation is its own ingest unit with
 * its own queue row and event_uuid (CatalogPayloadBuilder::expand), so the
 * engine dedups variations independently. SKU-less units are dropped — the
 * engine keys catalog on SKU.
 *
 * Gate: enqueue only while a tenant is connected (RecEngineSettings::
 * is_connected). Unlike the email HookHandler's setup_completed gate (which
 * exists to avoid double-sync with the legacy plugin), catalog ingest has
 * no legacy counterpart — the only question is whether there's a tenant to
 * send to.
 *
 * Per-request dedupe: save_post_product can fire repeatedly within one
 * request; a static $seen set collapses repeats to a single row per unit.
 *
 * Delete capture: the product is still loadable in before_delete_post, so
 * the full catalog object is built and stored in the row now; the flusher
 * stamps in_stock=false + event_id at send time (it can't load a gone
 * product).
 *
 * Not final: tests subclass to stub get_product() while recording enqueues
 * through a doubled IngestQueue.
 */
class CatalogHookHandler {

	public const EVENT_CATALOG_UPSERT = 'catalog.upsert';
	public const EVENT_CATALOG_DELETE = 'catalog.delete';

	/** @var array<string, bool> per-request dedupe keyed by "{event}:{product_id}". */
	private static array $seen = array();

	private IngestQueue $queue;
	private CatalogPayloadBuilder $builder;
	private RecEngineSettings $settings;

	public function __construct( IngestQueue $queue, CatalogPayloadBuilder $builder, RecEngineSettings $settings ) {
		$this->queue    = $queue;
		$this->builder  = $builder;
		$this->settings = $settings;
	}

	public function on_save_product( int $post_id ): void {
		if ( ! $this->gate_open() ) {
			return;
		}
		$product = $this->get_product( $post_id );
		if ( $product === null ) {
			return;
		}
		foreach ( $this->builder->expand( $product ) as $unit ) {
			$this->enqueue_upsert( $unit );
		}
	}

	/**
	 * woocommerce_product_set_stock_status fires ($product_id, $stock_status,
	 * $product). A stock flip is just a re-sync of the product.
	 *
	 * @param int|string            $product_id
	 * @param string                $stock_status
	 * @param \WC_Product|null|mixed $product
	 */
	public function on_stock_change( $product_id, $stock_status = '', $product = null ): void {
		if ( ! $this->gate_open() ) {
			return;
		}
		$id     = ( $product instanceof \WC_Product ) ? (int) $product->get_id() : (int) $product_id;
		$loaded = $this->get_product( $id );
		if ( $loaded === null ) {
			return;
		}
		foreach ( $this->builder->expand( $loaded ) as $unit ) {
			$this->enqueue_upsert( $unit );
		}
	}

	public function on_delete_product( int $post_id ): void {
		if ( ! $this->gate_open() ) {
			return;
		}
		$product = $this->get_product( $post_id );
		if ( $product === null ) {
			// Not a product (before_delete_post fires for every post type) or
			// already gone — nothing to mark unavailable.
			return;
		}
		foreach ( $this->builder->expand( $product ) as $unit ) {
			$this->enqueue_delete( $unit );
		}
	}

	/**
	 * Reset the per-request dedupe set. Tests use it between cases; production
	 * never calls it (the static is request-scoped).
	 */
	public static function reset_seen(): void {
		self::$seen = array();
	}

	private function enqueue_upsert( \WC_Product $unit ): void {
		if ( (string) $unit->get_sku() === '' ) {
			return;
		}
		if ( $this->already_seen( self::EVENT_CATALOG_UPSERT, (int) $unit->get_id() ) ) {
			return;
		}
		// Empty payload — the flusher loads the product fresh by entity_id so
		// the engine gets current state at send time.
		$this->queue->enqueue( self::EVENT_CATALOG_UPSERT, (string) $unit->get_id(), array() );
	}

	private function enqueue_delete( \WC_Product $unit ): void {
		if ( (string) $unit->get_sku() === '' ) {
			return;
		}
		if ( $this->already_seen( self::EVENT_CATALOG_DELETE, (int) $unit->get_id() ) ) {
			return;
		}
		// Capture the full object now (still loadable); event_uuid is generated
		// at enqueue, so the flusher stamps event_id + in_stock=false at send.
		$object = $this->builder->build( $unit, '' );
		$this->queue->enqueue( self::EVENT_CATALOG_DELETE, (string) $unit->get_id(), array( 'object' => $object ) );
	}

	private function already_seen( string $event_type, int $product_id ): bool {
		$key = $event_type . ':' . $product_id;
		if ( isset( self::$seen[ $key ] ) ) {
			return true;
		}
		self::$seen[ $key ] = true;
		return false;
	}

	private function gate_open(): bool {
		return $this->settings->is_connected();
	}

	/**
	 * Load a product by id. Protected so tests can stub the lookup.
	 */
	protected function get_product( int $product_id ): ?\WC_Product {
		if ( $product_id <= 0 || ! function_exists( 'wc_get_product' ) ) {
			return null;
		}
		$product = wc_get_product( $product_id );
		return $product instanceof \WC_Product ? $product : null;
	}
}
