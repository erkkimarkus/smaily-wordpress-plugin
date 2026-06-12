<?php
/**
 * @package Smaily\Connect\Tests
 */

declare(strict_types=1);

namespace Smaily\Connect\Tests\Unit\Smaily\RecEngine\Support;

use PHPUnit\Framework\TestCase;
use Smaily\Connect\Smaily\RecEngine\Support\SkuResolver;

/**
 * F3-36: one resolver supplies the engine product key on every surface
 * (catalog, order items, browse) — real SKU when set, synthetic `wc-{id}`
 * when not, so a SKU-less store (the pilot) still keys consistently.
 */
final class SkuResolverTest extends TestCase {

	public function test_real_sku_wins(): void {
		$product = $this->product( 7, 'REAL-1' );

		self::assertSame( 'REAL-1', SkuResolver::resolve( $product ) );
	}

	public function test_skuless_product_gets_wc_id_key(): void {
		$product = $this->product( 7, '' );

		self::assertSame( 'wc-7', SkuResolver::resolve( $product ) );
	}

	public function test_order_item_prefers_variation_id(): void {
		self::assertSame( 'wc-433', SkuResolver::resolve_order_item( $this->item( 432, 433 ) ) );
	}

	public function test_order_item_falls_back_to_product_id(): void {
		self::assertSame( 'wc-432', SkuResolver::resolve_order_item( $this->item( 432, 0 ) ) );
	}

	public function test_order_item_without_ids_is_unkeyable(): void {
		self::assertSame( '', SkuResolver::resolve_order_item( $this->item( 0, 0 ) ) );
	}

	// --- doubles -------------------------------------------------------------

	private function product( int $id, string $sku ): \WC_Product {
		$product = $this->createMock( \WC_Product::class );
		$product->method( 'get_id' )->willReturn( $id );
		$product->method( 'get_sku' )->willReturn( $sku );
		return $product;
	}

	private function item( int $product_id, int $variation_id ): \WC_Order_Item_Product {
		$item = $this->createMock( \WC_Order_Item_Product::class );
		$item->method( 'get_product_id' )->willReturn( $product_id );
		$item->method( 'get_variation_id' )->willReturn( $variation_id );
		return $item;
	}
}
