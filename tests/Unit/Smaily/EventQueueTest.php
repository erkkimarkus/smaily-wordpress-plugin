<?php
/**
 * EventQueue tests — exercise enqueue() against a stubbed $wpdb + AS API
 * mocks. The integration suite (added later) covers real DB writes.
 *
 * @package Smaily\Connect\Tests
 */

declare(strict_types=1);

namespace Smaily\Connect\Tests\Unit\Smaily;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Smaily\Connect\Smaily\EventQueue;

final class EventQueueTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( 'current_time' )->justReturn( '2026-05-19 12:00:00' );
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );

		// Default Action Scheduler env: functions exist and no job is queued yet.
		Functions\when( 'as_next_scheduled_action' )->justReturn( false );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
		unset( $GLOBALS['wpdb'] );
	}

	public function test_enqueue_persists_row_and_schedules_flush(): void {
		$wpdb           = $this->fake_wpdb_with_successful_insert( 1234 );
		$GLOBALS['wpdb'] = $wpdb;

		$enqueued = array();
		Functions\when( 'as_enqueue_async_action' )->alias(
			static function ( string $hook, array $args, string $group ) use ( &$enqueued ): int {
				$enqueued[] = compact( 'hook', 'args', 'group' );
				return 1;
			}
		);

		$queue = new EventQueue();
		$id    = $queue->enqueue( 'contact.sync', '42', array( 'email' => 'a@b.c' ) );

		self::assertSame( 1234, $id );
		self::assertCount( 1, $wpdb->inserts );
		self::assertSame( 'wp_smly_plus_event_queue', $wpdb->inserts[0]['table'] );
		self::assertSame( 'contact.sync', $wpdb->inserts[0]['data']['event_type'] );
		self::assertSame( '42', $wpdb->inserts[0]['data']['entity_id'] );
		self::assertSame( EventQueue::STATUS_PENDING, $wpdb->inserts[0]['data']['status'] );
		self::assertCount( 1, $enqueued );
		self::assertSame( EventQueue::FLUSH_HOOK, $enqueued[0]['hook'] );
		self::assertSame( EventQueue::AS_GROUP, $enqueued[0]['group'] );
	}

	public function test_enqueue_returns_null_when_insert_fails(): void {
		$wpdb           = $this->fake_wpdb_with_failed_insert();
		$GLOBALS['wpdb'] = $wpdb;

		$scheduled = false;
		Functions\when( 'as_enqueue_async_action' )->alias(
			static function () use ( &$scheduled ): int {
				$scheduled = true;
				return 1;
			}
		);

		$queue = new EventQueue();
		$id    = $queue->enqueue( 'contact.sync', '42', array() );

		self::assertNull( $id );
		self::assertFalse( $scheduled, 'Flush must not be scheduled when the row insert fails.' );
	}

	public function test_enqueue_skips_scheduling_when_flush_already_pending(): void {
		$wpdb            = $this->fake_wpdb_with_successful_insert( 99 );
		$GLOBALS['wpdb'] = $wpdb;

		// Pretend AS already has a pending flush.
		Functions\when( 'as_next_scheduled_action' )->justReturn( 4242 );

		$scheduled_again = false;
		Functions\when( 'as_enqueue_async_action' )->alias(
			static function () use ( &$scheduled_again ): int {
				$scheduled_again = true;
				return 1;
			}
		);

		$queue = new EventQueue();
		$queue->enqueue( 'contact.sync', '7', array() );

		self::assertFalse(
			$scheduled_again,
			'enqueue() must dedupe — a second flush schedule on top of an existing pending one is wasteful.'
		);
	}

	public function test_enqueue_returns_null_when_payload_cannot_be_json_encoded(): void {
		$wpdb            = $this->fake_wpdb_with_successful_insert( 1 );
		$GLOBALS['wpdb'] = $wpdb;

		Functions\when( 'wp_json_encode' )->justReturn( false );

		$queue = new EventQueue();
		self::assertNull( $queue->enqueue( 'contact.sync', '7', array( 'whatever' ) ) );
		self::assertCount( 0, $wpdb->inserts, 'No insert should be attempted on JSON failure.' );
	}

	/**
	 * Builds a fake $wpdb compatible enough with EventQueue's usage to record
	 * insert() and update() calls without touching a real database.
	 */
	private function fake_wpdb_with_successful_insert( int $insert_id ): object {
		return new class( $insert_id ) {
			public string $prefix    = 'wp_';
			public int $insert_id    = 0;
			public array $inserts    = array();
			private int $stamped_id  = 0;

			public function __construct( int $id ) {
				$this->stamped_id = $id;
			}

			public function insert( string $table, array $data, array $formats ): int {
				$this->inserts[]  = compact( 'table', 'data', 'formats' );
				$this->insert_id  = $this->stamped_id;
				return 1;
			}
		};
	}

	private function fake_wpdb_with_failed_insert(): object {
		// Matches the real $wpdb->insert signature: returns int|false (false
		// on error). The EventQueue check is "$inserted !== 1", so false
		// triggers the early-return path.
		return new class() {
			public string $prefix = 'wp_';
			public int $insert_id = 0;
			public array $inserts = array();

			public function insert( string $table, array $data, array $formats ) {
				return false;
			}
		};
	}
}
