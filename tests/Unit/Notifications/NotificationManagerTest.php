<?php
/**
 * Unit: NotificationManager (3.10.2) — the pure signal-evaluation logic.
 *
 * @package Smaily\Connect\Tests\Unit\Notifications
 */

declare(strict_types=1);

namespace Smaily\Connect\Tests\Unit\Notifications;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Smaily\Connect\Notifications\NotificationManager;
use Smaily\Connect\Settings\RecEngineSettings;
use Smaily\Connect\Smaily\RecEngine\Client as RecEngineClient;

final class NotificationManagerTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function manager(): NotificationManager {
		$client = $this->createMock( RecEngineClient::class );
		return new NotificationManager(
			$this->createMock( RecEngineSettings::class ),
			static fn (): RecEngineClient => $client
		);
	}

	public function test_failed_count_over_threshold_raises_a_notice(): void {
		$notices = $this->manager()->evaluate_signals( 51, null, 1_000_000, 50 );

		self::assertArrayHasKey( 'failed_events', $notices );
		self::assertSame( 51, $notices['failed_events']['count'] );
		self::assertSame( 'error', $notices['failed_events']['severity'] );
		self::assertArrayNotHasKey( 'engine_down', $notices );
	}

	public function test_failed_count_at_threshold_does_not_raise(): void {
		// Strictly greater — 50 is not > 50.
		$notices = $this->manager()->evaluate_signals( 50, null, 1_000_000, 50 );

		self::assertArrayNotHasKey( 'failed_events', $notices );
	}

	public function test_engine_down_over_an_hour_raises_a_notice(): void {
		$now     = 1_000_000;
		$notices = $this->manager()->evaluate_signals( 0, $now - 3700, $now, 50 );

		self::assertArrayHasKey( 'engine_down', $notices );
	}

	public function test_engine_down_within_grace_does_not_raise(): void {
		$now     = 1_000_000;
		// Down for 30 min — under the 1h grace, so no notice yet.
		$notices = $this->manager()->evaluate_signals( 0, $now - 1800, $now, 50 );

		self::assertArrayNotHasKey( 'engine_down', $notices );
	}

	public function test_engine_up_raises_nothing(): void {
		$notices = $this->manager()->evaluate_signals( 0, null, 1_000_000, 50 );

		self::assertSame( array(), $notices );
	}

	public function test_both_signals_can_be_active(): void {
		$now     = 1_000_000;
		$notices = $this->manager()->evaluate_signals( 99, $now - 7200, $now, 50 );

		self::assertArrayHasKey( 'failed_events', $notices );
		self::assertArrayHasKey( 'engine_down', $notices );
	}

	public function test_dismiss_records_the_key_with_a_timestamp(): void {
		Functions\when( 'get_option' )->justReturn( array() );
		$captured = null;
		Functions\when( 'update_option' )->alias(
			static function ( string $name, $value ) use ( &$captured ): bool {
				$captured = $value;
				return true;
			}
		);

		$this->manager()->dismiss( 'failed_events' );

		self::assertIsArray( $captured );
		self::assertArrayHasKey( 'failed_events', $captured );
		self::assertIsInt( $captured['failed_events'] );
	}
}
