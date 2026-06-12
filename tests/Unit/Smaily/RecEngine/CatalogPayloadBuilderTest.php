<?php
/**
 * CatalogPayloadBuilder tests — WC_Product → rec-engine catalog object,
 * variable-product expansion, event_uuid → event_id symmetry, and the
 * absent != empty omission policy.
 *
 * @package Smaily\Connect\Tests
 */

declare(strict_types=1);

namespace Smaily\Connect\Tests\Unit\Smaily\RecEngine;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Smaily\Connect\Smaily\RecEngine\CatalogPayloadBuilder;

final class CatalogPayloadBuilderTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( 'wp_strip_all_tags' )->alias( static fn( $s ) => strip_tags( (string) $s ) );
		Functions\when( 'get_permalink' )->justReturn( 'https://shop.test/p/acana-3kg' );
		Functions\when( 'wp_get_attachment_url' )->justReturn( false );
		Functions\when( 'get_the_terms' )->justReturn( false );
		Functions\when( 'get_ancestors' )->justReturn( array() );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_build_maps_required_fields_and_event_id(): void {
		$product = $this->fake_product(
			array(
				'id'       => 12345,
				'sku'      => 'ACA-DOG-3KG',
				'name'     => 'Acana Adult Dog 3kg',
				'price'    => '22.99',
				'in_stock' => true,
			)
		);

		$payload = ( new CatalogPayloadBuilder() )->build( $product, 'evt-uuid-aaaa' );

		self::assertSame( 'evt-uuid-aaaa', $payload['event_id'] );
		self::assertSame( 'ACA-DOG-3KG', $payload['sku'] );
		self::assertSame( 'Acana Adult Dog 3kg', $payload['name'] );
		self::assertSame( 22.99, $payload['price'] );
		self::assertTrue( $payload['in_stock'] );
		self::assertSame( 'https://shop.test/p/acana-3kg', $payload['product_url'] );
		self::assertSame( '12345', $payload['external_id'] );
	}

	public function test_event_id_equals_supplied_queue_uuid(): void {
		// Unit-level guard of the queue.event_uuid == body.event_id invariant
		// (the integration test pins it end-to-end against the mock engine).
		$product = $this->fake_product( array( 'sku' => 'X', 'price' => '1.00' ) );

		$payload = ( new CatalogPayloadBuilder() )->build( $product, 'the-exact-row-uuid' );

		self::assertSame( 'the-exact-row-uuid', $payload['event_id'] );
	}

	public function test_optional_fields_omitted_when_source_empty(): void {
		$product = $this->fake_product(
			array(
				'sku'               => 'BARE-1',
				'name'              => 'Bare product',
				'price'             => '10.00',
				'regular_price'     => '10.00', // == price → no compare_price
				'short_description' => '',
				'image_id'          => 0,
			)
		);

		$payload = ( new CatalogPayloadBuilder() )->build( $product, 'u1' );

		self::assertArrayNotHasKey( 'compare_price', $payload );
		self::assertArrayNotHasKey( 'on_sale_until', $payload );
		self::assertArrayNotHasKey( 'description', $payload );
		self::assertArrayNotHasKey( 'image_url', $payload );
		self::assertArrayNotHasKey( 'tags', $payload, 'No brand + no category → tags must be absent, not {}.' );
		self::assertArrayNotHasKey( 'raw_attributes', $payload );
	}

	public function test_compare_price_present_only_on_genuine_discount(): void {
		$on_sale = $this->fake_product(
			array( 'sku' => 'S', 'price' => '22.99', 'regular_price' => '25.99' )
		);
		$full_price = $this->fake_product(
			array( 'sku' => 'F', 'price' => '25.99', 'regular_price' => '25.99' )
		);

		$builder = new CatalogPayloadBuilder();

		self::assertSame( 25.99, $builder->build( $on_sale, 'u' )['compare_price'] );
		self::assertArrayNotHasKey( 'compare_price', $builder->build( $full_price, 'u' ) );
	}

	public function test_on_sale_until_serialised_iso8601_with_z_suffix(): void {
		$date    = new class() {
			public function getTimestamp(): int {
				return (int) strtotime( '2026-06-01 00:00:00 UTC' );
			}
		};
		$product = $this->fake_product(
			array( 'sku' => 'S', 'price' => '1.00', 'date_on_sale_to' => $date )
		);

		$payload = ( new CatalogPayloadBuilder() )->build( $product, 'u' );

		// `Z`, not `+00:00` — the engine's strict Zod .datetime() rejects an
		// offset (3.3.4 live-walk found the sibling first_seen_at bug).
		self::assertSame( '2026-06-01T00:00:00Z', $payload['on_sale_until'] );
	}

	public function test_description_is_stripped_and_capped_at_500(): void {
		$product = $this->fake_product(
			array(
				'sku'               => 'D',
				'price'             => '1.00',
				'short_description' => '<p>' . str_repeat( 'a', 600 ) . '</p>',
			)
		);

		$payload = ( new CatalogPayloadBuilder() )->build( $product, 'u' );

		self::assertArrayHasKey( 'description', $payload );
		self::assertSame( 500, strlen( $payload['description'] ) );
		self::assertStringNotContainsString( '<', $payload['description'] );
	}

	public function test_image_url_resolved_from_attachment(): void {
		Functions\when( 'wp_get_attachment_url' )->alias(
			static fn( $id ) => 7 === $id ? 'https://cdn.test/img-7.jpg' : false
		);
		$product = $this->fake_product( array( 'sku' => 'I', 'price' => '1.00', 'image_id' => 7 ) );

		$payload = ( new CatalogPayloadBuilder() )->build( $product, 'u' );

		self::assertSame( 'https://cdn.test/img-7.jpg', $payload['image_url'] );
	}

	public function test_category_path_uses_deepest_term_with_ancestor_slugs(): void {
		$food = (object) array( 'term_id' => 10, 'slug' => 'food' );
		$dry  = (object) array( 'term_id' => 20, 'slug' => 'dry' );

		Functions\when( 'get_the_terms' )->justReturn( array( $food, $dry ) );
		Functions\when( 'get_ancestors' )->alias(
			static function ( int $term_id ): array {
				return 20 === $term_id ? array( 10 ) : array();
			}
		);
		Functions\when( 'get_term' )->alias(
			static fn( int $id ) => 10 === $id ? (object) array( 'slug' => 'food' ) : null
		);

		$product = $this->fake_product( array( 'sku' => 'C', 'price' => '1.00' ) );

		$payload = ( new CatalogPayloadBuilder() )->build( $product, 'u' );

		self::assertSame( 'food/dry', $payload['category_path'] );
	}

	public function test_expand_simple_product_returns_itself(): void {
		$product = $this->fake_product( array( 'sku' => 'SIMPLE-1', 'type' => 'simple' ) );

		$units = ( new CatalogPayloadBuilder() )->expand( $product );

		self::assertCount( 1, $units );
		self::assertSame( $product, $units[0] );
	}

	public function test_expand_simple_without_sku_returns_itself(): void {
		// F3-36: SKU-less units are no longer dropped — build() keys them
		// synthetically. Dropping here silently emptied a SKU-less store's
		// whole catalog (the pilot).
		$product = $this->fake_product( array( 'sku' => '', 'type' => 'simple' ) );

		$units = ( new CatalogPayloadBuilder() )->expand( $product );

		self::assertCount( 1, $units );
		self::assertSame( $product, $units[0] );
	}

	public function test_build_skuless_product_gets_synthetic_wc_id_key(): void {
		$product = $this->fake_product( array( 'id' => 77, 'sku' => '', 'price' => '1.00' ) );

		$payload = ( new CatalogPayloadBuilder() )->build( $product, 'uuid-77' );

		self::assertSame( 'wc-77', $payload['sku'] );
	}

	public function test_expand_variable_fans_out_to_all_variations(): void {
		$v1 = $this->fake_product( array( 'id' => 101, 'sku' => 'V-1' ) );
		$v2 = $this->fake_product( array( 'id' => 102, 'sku' => '' ) ); // skuless → synthetic key in build() (F3-36)
		$v3 = $this->fake_product( array( 'id' => 103, 'sku' => 'V-3' ) );

		Functions\when( 'wc_get_product' )->alias(
			static function ( int $id ) use ( $v1, $v2, $v3 ) {
				return array( 101 => $v1, 102 => $v2, 103 => $v3 )[ $id ] ?? null;
			}
		);

		$parent = $this->fake_product(
			array( 'sku' => 'PARENT', 'type' => 'variable', 'children' => array( 101, 102, 103 ) )
		);

		$units = ( new CatalogPayloadBuilder() )->expand( $parent );

		self::assertCount( 3, $units, 'Every loadable variation is its own ingest unit — SKU-less ones included (F3-36).' );
		self::assertSame( 'V-1', $units[0]->get_sku() );
		self::assertSame( '', $units[1]->get_sku() );
		self::assertSame( 'V-3', $units[2]->get_sku() );
	}

	public function test_tags_carry_brand_and_category_path(): void {
		Functions\when( 'get_the_terms' )->justReturn( array( (object) array( 'term_id' => 5, 'slug' => 'toys' ) ) );

		$product = $this->fake_product(
			array(
				'sku'           => 'T',
				'price'         => '1.00',
				'attribute_map' => array( 'brand' => 'Acana' ),
			)
		);

		$payload = ( new CatalogPayloadBuilder() )->build( $product, 'u' );

		self::assertSame( array( 'brand' => 'Acana', 'category_path' => 'toys' ), $payload['tags'] );
	}

	public function test_raw_attributes_custom_attribute_values_pass_through(): void {
		// Non-taxonomy (custom) attribute: options ARE the literal values.
		$attr = new class() {
			/** @return string */
			public function get_name() {
				return 'flavor';
			}
			/** @return array<int, string> */
			public function get_options() {
				return array( 'chicken' );
			}
			/** @return bool */
			public function is_taxonomy() {
				return false;
			}
		};

		$product = $this->fake_product(
			array( 'sku' => 'R', 'price' => '1.00', 'attributes' => array( 'flavor' => $attr ) )
		);

		$payload = ( new CatalogPayloadBuilder() )->build( $product, 'u' );

		self::assertSame( array( 'flavor' => array( 'chicken' ) ), $payload['raw_attributes'] );
	}

	public function test_raw_attributes_taxonomy_term_ids_resolve_to_labels(): void {
		// Engine ask 2026-06-12: a REAL taxonomy attribute's get_options()
		// returns term IDS — the wire must carry term NAMES. (The previous
		// version of this test faked options that were already strings,
		// mirroring the wrong assumption — LESSONS §2.4.)
		Functions\when( 'wc_get_product_terms' )->alias(
			static function ( int $product_id, string $taxonomy, array $args = array() ) {
				return ( 'pa_kaubamargid' === $taxonomy && ( $args['fields'] ?? '' ) === 'names' )
					? array( 'Brit Care' )
					: array();
			}
		);

		$attr = new class() {
			/** @return string */
			public function get_name() {
				return 'pa_kaubamargid';
			}
			/** @return array<int, int> */
			public function get_options() {
				return array( 398 ); // term id, the pilot's exact symptom.
			}
			/** @return bool */
			public function is_taxonomy() {
				return true;
			}
		};

		$product = $this->fake_product(
			array( 'sku' => 'R2', 'price' => '1.00', 'attributes' => array( 'pa_kaubamargid' => $attr ) )
		);

		$payload = ( new CatalogPayloadBuilder() )->build( $product, 'u' );

		self::assertSame(
			array( 'pa_kaubamargid' => array( 'Brit Care' ) ),
			$payload['raw_attributes'],
			'Term ids must resolve to term labels on the wire.'
		);
	}

	public function test_raw_attributes_variation_slug_resolves_to_label(): void {
		Functions\when( 'taxonomy_exists' )->alias(
			static fn( string $tax ): bool => 'pa_vali-kaal' === $tax
		);
		Functions\when( 'get_term_by' )->alias(
			static function ( string $by, string $value, string $tax ) {
				return ( 'slug' === $by && '3kg' === $value && 'pa_vali-kaal' === $tax )
					? new \WP_Term( '3 kg' )
					: false;
			}
		);

		$product = $this->fake_product(
			array(
				'sku'        => 'V',
				'price'      => '1.00',
				'attributes' => array(
					'pa_vali-kaal' => '3kg',     // taxonomy slug → label
					'engraving'    => 'Muki',    // custom value → unchanged
				),
			)
		);

		$payload = ( new CatalogPayloadBuilder() )->build( $product, 'u' );

		self::assertSame( '3 kg', $payload['raw_attributes']['pa_vali-kaal'] );
		self::assertSame( 'Muki', $payload['raw_attributes']['engraving'] );
	}

	public function test_variation_inherits_parent_category_path(): void {
		// Variations carry no product_cat terms; the engine requires a
		// non-empty category_path, so the builder must fall back to the parent.
		Functions\when( 'get_the_terms' )->alias(
			static function ( int $id ) {
				return 50 === $id ? array( (object) array( 'term_id' => 9, 'slug' => 'food' ) ) : false;
			}
		);
		$variation = $this->fake_product( array( 'id' => 101, 'sku' => 'V-1', 'price' => '1.00', 'parent_id' => 50 ) );

		$payload = ( new CatalogPayloadBuilder() )->build( $variation, 'u' );

		self::assertSame( 'food', $payload['category_path'], 'A variation must inherit its parent product category.' );
	}

	/**
	 * @param array<string, mixed> $p
	 */
	private function fake_product( array $p ): \WC_Product {
		return new class( $p ) extends \WC_Product {
			/** @var array<string, mixed> */
			private array $p;

			/** @param array<string, mixed> $p */
			public function __construct( array $p ) {
				$this->p = $p;
			}

			public function get_id( $context = 'view' ) {
				return (int) ( $this->p['id'] ?? 0 );
			}
			public function get_parent_id( $context = 'view' ) {
				return (int) ( $this->p['parent_id'] ?? 0 );
			}
			public function get_sku( $context = 'view' ) {
				return (string) ( $this->p['sku'] ?? '' );
			}
			public function get_name( $context = 'view' ) {
				return (string) ( $this->p['name'] ?? '' );
			}
			public function get_price( $context = 'view' ) {
				return (string) ( $this->p['price'] ?? '' );
			}
			public function get_regular_price( $context = 'view' ) {
				return (string) ( $this->p['regular_price'] ?? '' );
			}
			public function is_in_stock() {
				return (bool) ( $this->p['in_stock'] ?? true );
			}
			public function get_date_on_sale_to( $context = 'view' ) {
				return $this->p['date_on_sale_to'] ?? null;
			}
			public function get_short_description( $context = 'view' ) {
				return (string) ( $this->p['short_description'] ?? '' );
			}
			public function get_image_id( $context = 'view' ) {
				return (int) ( $this->p['image_id'] ?? 0 );
			}
			public function is_type( $type ) {
				return ( $this->p['type'] ?? 'simple' ) === $type;
			}
			public function get_children( $context = 'view' ) {
				return $this->p['children'] ?? array();
			}
			public function get_attributes( $context = 'view' ) {
				return $this->p['attributes'] ?? array();
			}
			public function get_attribute( $name ) {
				return (string) ( ( $this->p['attribute_map'] ?? array() )[ $name ] ?? '' );
			}
		};
	}
}

// Minimal WC_Product shim for the anonymous fakes to extend — Brain Monkey
// doesn't load WooCommerce. Declares only the surface CatalogPayloadBuilder
// touches.
if ( ! class_exists( \WC_Product::class ) ) {
	// phpcs:ignore Squiz.Commenting.ClassComment.Missing -- test shim.
	eval(
		<<<'PHP'
		class WC_Product {
			public function get_id( $context = 'view' ) { return 0; }
			public function get_parent_id( $context = 'view' ) { return 0; }
			public function get_sku( $context = 'view' ) { return ''; }
			public function get_name( $context = 'view' ) { return ''; }
			public function get_price( $context = 'view' ) { return ''; }
			public function get_regular_price( $context = 'view' ) { return ''; }
			public function is_in_stock() { return true; }
			public function get_date_on_sale_to( $context = 'view' ) { return null; }
			public function get_short_description( $context = 'view' ) { return ''; }
			public function get_image_id( $context = 'view' ) { return 0; }
			public function is_type( $type ) { return false; }
			public function get_children( $context = 'view' ) { return array(); }
			public function get_attributes( $context = 'view' ) { return array(); }
			public function get_attribute( $name ) { return ''; }
		}
PHP
	);
}

// WP_Term shim — variation_term_label() type-checks `instanceof WP_Term`
// (PHPStan: the stubs' WP_Term::$name is non-nullable, so isset() is
// rejected), and Brain Monkey doesn't load WP core classes.
if ( ! class_exists( \WP_Term::class ) ) {
	// phpcs:ignore Squiz.Commenting.ClassComment.Missing -- test shim.
	eval(
		<<<'PHP'
		class WP_Term {
			public $name = '';
			public function __construct( $name = '' ) { $this->name = $name; }
		}
PHP
	);
}
