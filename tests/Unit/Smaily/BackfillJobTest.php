<?php
/**
 * BackfillJob tests — exercise start() + process_batch() flows against
 * stubbed $wpdb and mocked WP user-table helpers. The integration suite
 * (later phase) covers the real DB writes and large-batch performance.
 *
 * @package Smaily\Connect\Tests
 */

declare(strict_types=1);

namespace Smaily\Connect\Tests\Unit\Smaily;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Smaily\Connect\Smaily\ApiException;
use Smaily\Connect\Smaily\BackfillJob;
use Smaily\Connect\Smaily\Client;

final class BackfillJobTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( 'current_time' )->justReturn( '2026-05-19 12:00:00' );

		// Empty user-meta by default — every user looks "never synced".
		Functions\when( 'get_user_meta' )->justReturn( '' );

		// Pass-through for update_user_meta — tests don't need to inspect it
		// unless an assertion explicitly cares.
		Functions\when( 'update_user_meta' )->justReturn( true );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
		unset( $GLOBALS['wpdb'] );
	}

	public function test_start_seeds_a_running_row_and_returns_its_id(): void {
		$wpdb            = $this->fake_wpdb_for_start( 77 );
		$GLOBALS['wpdb'] = $wpdb;

		Functions\when( 'count_users' )->justReturn( array( 'total_users' => 5000 ) );

		$job = new BackfillJob( $this->createMock( Client::class ) );

		self::assertSame( 77, $job->start() );

		// One INSERT ... ON DUPLICATE KEY UPDATE + one SELECT id.
		self::assertCount( 2, $wpdb->prepare_calls );
		self::assertStringContainsString( 'INSERT INTO wp_smly_plus_backfill_job', $wpdb->prepare_calls[0]['sql'] );
		self::assertSame( 5000, $wpdb->prepare_calls[0]['args'][3] );
		self::assertStringContainsString( 'SELECT id FROM wp_smly_plus_backfill_job', $wpdb->prepare_calls[1]['sql'] );
	}

	public function test_process_batch_syncs_users_marks_meta_and_updates_progress(): void {
		$wpdb            = $this->fake_wpdb_for_process_batch(
			array(
				'id'              => 77,
				'cursor_value'    => '0',
				'processed_count' => '0',
				'total_count'     => '2',
			)
		);
		$GLOBALS['wpdb'] = $wpdb;

		Functions\when( 'get_users' )->justReturn(
			array(
				$this->fake_user( 1, 'a@x.test' ),
				$this->fake_user( 2, 'b@x.test' ),
			)
		);

		$client = $this->createMock( Client::class );
		$client->expects( $this->exactly( 2 ) )
			->method( 'upsert_subscribers' );

		$update_meta_calls = array();
		Functions\when( 'update_user_meta' )->alias(
			static function ( int $user_id, string $key, $value ) use ( &$update_meta_calls ): bool {
				$update_meta_calls[] = compact( 'user_id', 'key', 'value' );
				return true;
			}
		);

		$result = ( new BackfillJob( $client ) )->process_batch( 10 );

		self::assertSame( 2, $result['processed'] );
		self::assertTrue( $result['completed'] );

		// _smaily_synced_at meta should have been set on both user ids.
		$user_ids = array_column( $update_meta_calls, 'user_id' );
		self::assertContains( 1, $user_ids );
		self::assertContains( 2, $user_ids );
		self::assertSame( BackfillJob::META_KEY, $update_meta_calls[0]['key'] );

		// processed_count + cursor updated.
		self::assertCount( 1, $wpdb->updates );
		self::assertSame( 'completed', $wpdb->updates[0]['data']['status'] );
		self::assertSame( '2', $wpdb->updates[0]['data']['cursor_value'] );
	}

	public function test_process_batch_skips_fresh_users(): void {
		$wpdb            = $this->fake_wpdb_for_process_batch(
			array(
				'id'              => 77,
				'cursor_value'    => '0',
				'processed_count' => '0',
				'total_count'     => '1',
			)
		);
		$GLOBALS['wpdb'] = $wpdb;

		Functions\when( 'get_users' )->justReturn( array( $this->fake_user( 1, 'fresh@x.test' ) ) );

		// User WAS synced one minute ago — well inside the 7-day freshness
		// window default — so upsert_subscribers must not be called.
		Functions\when( 'get_user_meta' )->justReturn( (string) ( time() - 60 ) );

		$client = $this->createMock( Client::class );
		$client->expects( $this->never() )->method( 'upsert_subscribers' );

		$result = ( new BackfillJob( $client ) )->process_batch();

		self::assertSame( 0, $result['processed'], 'No API calls because all users were already fresh.' );
	}

	public function test_process_batch_returns_completed_when_no_state_row_exists(): void {
		$wpdb            = $this->fake_wpdb_for_process_batch( null );
		$GLOBALS['wpdb'] = $wpdb;

		$result = ( new BackfillJob( $this->createMock( Client::class ) ) )->process_batch();

		self::assertSame(
			array(
				'processed' => 0,
				'remaining' => 0,
				'completed' => true,
			),
			$result
		);
	}

	public function test_process_batch_records_error_on_api_failure(): void {
		$wpdb            = $this->fake_wpdb_for_process_batch(
			array(
				'id'              => 77,
				'cursor_value'    => '0',
				'processed_count' => '0',
				'total_count'     => '1',
			)
		);
		$GLOBALS['wpdb'] = $wpdb;

		Functions\when( 'get_users' )->justReturn( array( $this->fake_user( 1, 'a@x.test' ) ) );

		$client = $this->createMock( Client::class );
		$client->method( 'upsert_subscribers' )
			->willThrowException( new ApiException( 'rate limited', 429 ) );

		( new BackfillJob( $client ) )->process_batch();

		// First update is the error-record (status='failed'), no further updates.
		self::assertNotEmpty( $wpdb->updates );
		self::assertSame( 'failed', $wpdb->updates[0]['data']['status'] );
		self::assertSame( 'rate limited', $wpdb->updates[0]['data']['error_message'] );
	}

	private function fake_user( int $id, string $email ): \WP_User {
		return new class( $id, $email ) extends \WP_User {
			public function __construct( int $id, string $email ) {
				$this->ID         = $id;
				$this->user_email = $email;
				$this->first_name = '';
				$this->last_name  = '';
			}
		};
	}

	private function fake_wpdb_for_start( int $stamped_id ): object {
		return new class( $stamped_id ) {
			public string $prefix = 'wp_';
			public array $prepare_calls = array();
			public array $queries       = array();
			private int $stamp;

			public function __construct( int $id ) {
				$this->stamp = $id;
			}

			public function prepare( string $sql, ...$args ): string {
				$this->prepare_calls[] = compact( 'sql', 'args' );
				return $sql;
			}

			public function query( string $sql ): int {
				$this->queries[] = $sql;
				return 1;
			}

			public function get_var( string $sql ) {
				return (string) $this->stamp;
			}
		};
	}

	/**
	 * @param array<string, mixed>|null $state_row
	 */
	private function fake_wpdb_for_process_batch( ?array $state_row ): object {
		return new class( $state_row ) {
			public string $prefix         = 'wp_';
			public array $prepare_calls   = array();
			public array $updates         = array();
			public array $queries         = array();
			private ?array $state_row     = null;

			public function __construct( ?array $row ) {
				$this->state_row = $row;
			}

			public function prepare( string $sql, ...$args ): string {
				$this->prepare_calls[] = compact( 'sql', 'args' );
				return $sql;
			}

			public function query( string $sql ): int {
				$this->queries[] = $sql;
				return 1;
			}

			public function get_row( string $sql, string $output = ARRAY_A ) {
				return $this->state_row;
			}

			public function update( string $table, array $data, array $where, $format = null, $where_format = null ): int {
				$this->updates[] = compact( 'table', 'data', 'where' );
				return 1;
			}
		};
	}
}
