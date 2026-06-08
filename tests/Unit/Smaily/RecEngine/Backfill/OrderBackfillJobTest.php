<?php
/**
 * Unit: OrderBackfillJob's pure storage-mode mapping (3.5.2) + the single-source
 * mapped-status list. The HPOS path runs no integration query (the pilot is
 * legacy), so its table/column mapping is verified here as a forward-compat
 * guarantee — see STATUS / CLAUDE "OrderBackfill HPOS path".
 *
 * @package Smaily\Connect\Tests\Unit\Smaily\RecEngine\Backfill
 */

declare(strict_types=1);

namespace Smaily\Connect\Tests\Unit\Smaily\RecEngine\Backfill;

use PHPUnit\Framework\TestCase;
use Smaily\Connect\Smaily\RecEngine\Backfill\OrderBackfillJob;
use Smaily\Connect\Smaily\RecEngine\OrderPayloadBuilder;

final class OrderBackfillJobTest extends TestCase {

	public function test_legacy_table_spec_targets_wp_posts(): void {
		$spec = OrderBackfillJob::table_spec( false, 'wp_' );
		self::assertSame( 'wp_posts', $spec['table'] );
		self::assertSame( 'ID', $spec['id_col'] );
		self::assertSame( 'post_type', $spec['type_col'] );
		self::assertSame( 'post_status', $spec['status_col'] );
	}

	public function test_hpos_table_spec_targets_wc_orders(): void {
		$spec = OrderBackfillJob::table_spec( true, 'wp_' );
		self::assertSame( 'wp_wc_orders', $spec['table'], 'HPOS reads the custom orders table, not wp_posts.' );
		self::assertSame( 'id', $spec['id_col'] );
		self::assertSame( 'type', $spec['type_col'] );
		self::assertSame( 'status', $spec['status_col'] );
	}

	public function test_mapped_statuses_are_the_single_source_for_the_filter(): void {
		$statuses = OrderPayloadBuilder::mapped_wc_statuses();

		// Exactly the sale states map_status() accepts — no pending/failed/draft.
		self::assertContains( 'completed', $statuses );
		self::assertContains( 'processing', $statuses );
		self::assertContains( 'on-hold', $statuses );
		self::assertContains( 'cancelled', $statuses );
		self::assertContains( 'refunded', $statuses );
		self::assertNotContains( 'pending', $statuses );
		self::assertNotContains( 'failed', $statuses );

		// Every listed status maps to a non-empty engine status (drift guard).
		$builder = new OrderPayloadBuilder();
		foreach ( $statuses as $status ) {
			self::assertNotSame( '', $builder->map_status( $status ), "{$status} must map to a non-empty engine status." );
		}
	}
}
