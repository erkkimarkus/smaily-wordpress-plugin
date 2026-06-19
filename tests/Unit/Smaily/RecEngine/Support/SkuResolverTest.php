<?php
/**
 * @package Smaily\Connect\Tests
 */

declare(strict_types=1);

namespace Smaily\Connect\Tests\Unit\Smaily\RecEngine\Support;

use PHPUnit\Framework\TestCase;
use Smaily\Connect\Multilingual\DetectorFactory;
use Smaily\Connect\Multilingual\DetectorInterface;
use Smaily\Connect\Smaily\RecEngine\Support\SkuResolver;

/**
 * F3-36: one resolver supplies the engine product key on every surface
 * (catalog, order items, browse) — real SKU when set, synthetic `wc-{id}`
 * when not, so a SKU-less store (the pilot) still keys consistently.
 *
 * CC.2: the synthetic id is collapsed to its canonical (default-language)
 * post via the detector, so a translated product keys ONE canonical SKU
 * across catalog + orders + browse (no per-language duplication).
 */
final class SkuResolverTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		// Drop any cached detector so the no-detector cases resolve through the
		// single-language SiteLocale fallback (passthrough) deterministically,
		// independent of other tests in the process.
		DetectorFactory::reset();
	}

	protected function tearDown(): void {
		DetectorFactory::reset();
		parent::tearDown();
	}

	public function test_real_sku_wins(): void {
		$product = $this->product( 7, 'REAL-1' );

		self::assertSame( 'REAL-1', SkuResolver::resolve( $product ) );
	}

	public function test_skuless_product_gets_wc_id_key(): void {
		$product = $this->product( 7, '' );

		// No multilingual plugin → SiteLocale passthrough → id unchanged.
		self::assertSame( 'wc-7', SkuResolver::resolve( $product ) );
	}

	public function test_order_item_prefers_variation_id(): void {
		self::assertSame( 'wc-433', SkuResolver::resolve_order_item( $this->item( 432, 433 ) ) );
	}

	public function test_order_item_falls_back_to_product_id(): void {
		self::assertSame( 'wc-432', SkuResolver::resolve_order_item( $this->item( 432, 0 ) ) );
	}

	public function test_order_item_without_ids_keys_from_order_item_id(): void {
		// Deleted product, both ids zeroed (current WC). NEVER '' (F3-43) — that
		// would drop the line and silently lose the whole order (#58922). Keys on
		// the order-item id so the order still ingests.
		self::assertSame( 'wc-oi-8001', SkuResolver::resolve_order_item( $this->item( 0, 0 ) ) );
		self::assertSame( 'wc-oi-4242', SkuResolver::resolve_order_item( $this->item( 0, 0, 4242 ) ) );
	}

	public function test_skuless_product_canonicalizes_via_detector(): void {
		// The LV translation (id 59221) collapses to the ET canonical (59199) —
		// the real MiuMjau shampoo case. One SKU across languages.
		$detector = $this->detector( array( 59221 => 59199 ) );

		self::assertSame( 'wc-59199', SkuResolver::resolve( $this->product( 59221, '' ), $detector ) );
	}

	public function test_real_sku_is_never_canonicalized(): void {
		// A real SKU is the merchant's own canonical key — the detector is not
		// even consulted (translations share/own their SKU).
		$detector = $this->createMock( DetectorInterface::class );
		$detector->expects( self::never() )->method( 'get_canonical_post_id' );

		self::assertSame( 'REAL-LV', SkuResolver::resolve( $this->product( 59221, 'REAL-LV' ), $detector ) );
	}

	public function test_order_item_variation_canonicalizes(): void {
		// A variation bought on the LV store (201) keys the canonical variation
		// (101) — WCML links product_variation across languages, so the engine
		// joins the order line to the catalog's canonical variation row.
		$detector = $this->detector( array( 201 => 101 ) );

		self::assertSame( 'wc-101', SkuResolver::resolve_order_item( $this->item( 200, 201 ), $detector ) );
	}

	public function test_canonical_falls_back_to_input_when_unresolved(): void {
		// Detector returns the input unchanged (single-language / unlinked) →
		// degraded per-language key, never a dropped surface.
		$detector = $this->detector( array() );

		self::assertSame( 'wc-432', SkuResolver::resolve_order_item( $this->item( 432, 0 ), $detector ) );
	}

	// --- doubles -------------------------------------------------------------

	/**
	 * @param array<int, int> $map id → canonical id (missing keys pass through).
	 */
	private function detector( array $map ): DetectorInterface {
		$detector = $this->createMock( DetectorInterface::class );
		$detector->method( 'get_canonical_post_id' )->willReturnCallback(
			static fn ( int $id ): int => $map[ $id ] ?? $id
		);
		return $detector;
	}

	private function product( int $id, string $sku ): \WC_Product {
		$product = $this->createMock( \WC_Product::class );
		$product->method( 'get_id' )->willReturn( $id );
		$product->method( 'get_sku' )->willReturn( $sku );
		return $product;
	}

	private function item( int $product_id, int $variation_id, int $item_id = 8001 ): \WC_Order_Item_Product {
		$item = $this->createMock( \WC_Order_Item_Product::class );
		$item->method( 'get_id' )->willReturn( $item_id );
		$item->method( 'get_product_id' )->willReturn( $product_id );
		$item->method( 'get_variation_id' )->willReturn( $variation_id );
		return $item;
	}
}
