<?php
/**
 * Integration: CustomerFlusher → Client::ingest_customers against the mock
 * engine — the D6 per-item contract end-to-end (partial success splits the
 * batch), email-first wire format, and the terminal-4xx path.
 *
 * @package Smaily\Connect\Tests\Integration
 */

declare(strict_types=1);

namespace Smaily\Connect\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Smaily\Connect\Settings\RecEngineSettings;
use Smaily\Connect\Smaily\RecEngine\Client;
use Smaily\Connect\Smaily\RecEngine\CustomerFlusher;
use Smaily\Connect\Smaily\RecEngine\CustomerPayloadBuilder;
use Smaily\Connect\Smaily\RecEngine\IngestQueue;
use Smaily\Connect\Tests\Integration\Fixtures\RecEngineMockServer;
use Smaily\Connect\Tests\Integration\Support\EnvScrub;
use Smaily\Connect\Tests\Integration\Support\EnvSeed;

/**
 * What this catches that the unit tests can't:
 *
 *   - The full chain CustomerPayloadBuilder → Client → real HTTP → engine
 *     with the `customers` wrapper actually on the wire.
 *
 *   - The D6 partial-success split end-to-end: a batch where one item is
 *     rejected (errors[]) must mark exactly that row failed and the rest
 *     sent — the practical proof of the D6 contract plugin-side.
 *
 *   - Terminal 4xx (revoked key): the whole batch fails, no retry.
 */
final class RecEngineCustomersTest extends TestCase {

	private static ?RecEngineMockServer $engine = null;

	/** @var array<int, int> User ids created by a test, torn down after. */
	private array $created_users = array();

	public static function setUpBeforeClass(): void {
		self::$engine = RecEngineMockServer::start();
	}

	protected function setUp(): void {
		parent::setUp();
		EnvScrub::reset();
		RecEngineMockServer::reset();
		$this->connect();
	}

	protected function tearDown(): void {
		foreach ( $this->created_users as $user_id ) {
			wp_delete_user( $user_id );
		}
		$this->created_users = array();
		parent::tearDown();
	}

	public function test_d6_partial_success_marks_errored_row_failed_and_rest_sent(): void {
		$queue = new IngestQueue();

		// Two valid customers + one whose email triggers a per-item error in
		// the mock (`d6err-` prefix). All three are valid WP users.
		$this->enqueue_user( $queue, 'valid-a@example.test' );
		$this->enqueue_user( $queue, 'valid-b@example.test' );
		$this->enqueue_user( $queue, 'd6err-bad@example.test' );

		$stats = $this->flusher()->flush();

		self::assertSame( 2, $stats['sent'], 'The two valid customers are processed → sent.' );
		self::assertSame( 1, $stats['failed'], 'The errors[] item is marked failed, not the whole batch.' );
		self::assertSame( array(), $queue->pending( 10 ), 'Every row reached a terminal state (sent or failed).' );

		$received = self::$engine->state()['last_customers_received'] ?? null;
		self::assertIsArray( $received );
		self::assertCount( 3, $received, 'All three customers were sent in one batch.' );
	}

	public function test_all_valid_customers_are_sent(): void {
		$queue = new IngestQueue();
		$this->enqueue_user( $queue, 'all-good-1@example.test' );
		$this->enqueue_user( $queue, 'all-good-2@example.test' );

		$stats = $this->flusher()->flush();

		self::assertSame( 2, $stats['sent'] );
		self::assertSame( 0, $stats['failed'] );
		self::assertSame( array(), $queue->pending( 10 ) );
	}

	public function test_revoked_key_401_fails_batch_without_retry(): void {
		$queue = new IngestQueue();
		// `auth-401@` on the first customer makes the mock return a terminal 401.
		$this->enqueue_user( $queue, 'auth-401@example.test' );

		$stats = $this->flusher()->flush();

		self::assertSame( 0, $stats['sent'] );
		self::assertSame( 1, $stats['failed'], 'A revoked key is terminal — mark failed, no retry.' );
		self::assertSame( 0, $stats['retried'] );
	}

	private function flusher(): CustomerFlusher {
		$settings = new RecEngineSettings();
		return new CustomerFlusher(
			new IngestQueue(),
			new CustomerPayloadBuilder(),
			$settings,
			static function () use ( $settings ): Client {
				return new Client( $settings->api_key(), $settings->base_url(), $settings->endpoints(), 2 );
			}
		);
	}

	private function enqueue_user( IngestQueue $queue, string $email ): void {
		$user_id = $this->make_user( $email );
		$queue->enqueue( CustomerFlusher::EVENT_CUSTOMER_UPSERT, (string) $user_id, array() );
	}

	private function make_user( string $email ): int {
		$existing = get_user_by( 'email', $email );
		if ( $existing instanceof \WP_User ) {
			wp_delete_user( $existing->ID );
		}

		$user_id = wp_insert_user(
			array(
				'user_login' => 'cust_' . substr( md5( $email ), 0, 12 ),
				'user_email' => $email,
				'user_pass'  => wp_generate_password( 16 ),
				'first_name' => 'Test',
				'last_name'  => 'Customer',
				'role'       => 'customer',
			)
		);

		self::assertFalse( is_wp_error( $user_id ), 'User creation must succeed for the probe.' );
		$this->created_users[] = (int) $user_id;
		return (int) $user_id;
	}

	private function connect(): void {
		$base = (string) self::$engine->base_url();
		EnvSeed::connect(
			array(
				'engine_base_url' => $base,
				'endpoints'       => array(
					'ingest_ping'      => $base . '/api/v1/ingest/ping',
					'ingest_catalog'   => $base . '/api/v1/ingest/catalog',
					'ingest_customers' => $base . '/api/v1/ingest/customers',
					'ingest_orders'    => $base . '/api/v1/ingest/orders',
				),
			)
		);
	}
}
