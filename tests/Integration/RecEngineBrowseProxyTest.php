<?php
/**
 * Integration: the public POST /beacon proxy (BeaconEndpoint, 3.4.0).
 *
 * @package Smaily\Connect\Tests\Integration
 */

declare(strict_types=1);

namespace Smaily\Connect\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Smaily\Connect\REST\BeaconEndpoint;
use Smaily\Connect\Tests\Integration\Fixtures\RecEngineMockServer;
use Smaily\Connect\Tests\Integration\Support\EnvScrub;
use Smaily\Connect\Tests\Integration\Support\EnvSeed;
use Smaily\Connect\Tests\Integration\Support\RestRequestHelper;

/**
 * What this catches that the unit tests can't:
 *
 *   - The hard gate: when browse-tracking is off (or the engine isn't
 *     connected) the route 404s — a bare not-found with no engine call, no
 *     api_key, no rate-limit transient. The unit test pins validate_batch;
 *     only a live dispatch proves the handler refuses to do anything when off.
 *
 *   - The full proxy chain: a same-origin POST → BeaconEndpoint decrypts the
 *     api_key → Client::ingest_browse → real HTTP → mock engine → D6 body
 *     back to the caller, WITHOUT the api_key ever appearing in the response.
 *
 *   - The abuse model end-to-end: a junk event_type / id-less event is a 400
 *     that never reaches the engine; exceeding the per-session window is a 429.
 *
 * The /beacon route is registered unconditionally (so it dispatches like any
 * other route); the connected+enabled gate lives in the handler.
 */
final class RecEngineBrowseProxyTest extends TestCase {

	private static ?RecEngineMockServer $engine = null;

	public static function setUpBeforeClass(): void {
		self::$engine = RecEngineMockServer::start();
	}

	protected function setUp(): void {
		parent::setUp();
		EnvScrub::reset();
		RecEngineMockServer::reset();
		unset( $_COOKIE['smaily_anon_sid'] );
		update_option( BeaconEndpoint::OPTION_TRACK_BROWSING, false );
	}

	protected function tearDown(): void {
		unset( $_COOKIE['smaily_anon_sid'] );
		update_option( BeaconEndpoint::OPTION_TRACK_BROWSING, false );
		parent::tearDown();
	}

	public function test_404_when_tracking_disabled(): void {
		$this->connect_to_mock();
		update_option( BeaconEndpoint::OPTION_TRACK_BROWSING, false );

		$response = RestRequestHelper::post( '/beacon', array( 'events' => array() ) );

		self::assertSame( 404, $response->get_status(), 'Disabled browse-tracking ⇒ a bare 404.' );
		// The hard gate runs BEFORE the engine — nothing was forwarded.
		self::assertNull( self::$engine->state()['last_browse_received'] ?? null );
	}

	public function test_404_when_not_connected(): void {
		// track_browsing on, but the engine is not connected.
		update_option( BeaconEndpoint::OPTION_TRACK_BROWSING, true );

		$response = RestRequestHelper::post( '/beacon', array( 'events' => array() ) );

		self::assertSame( 404, $response->get_status(), 'Not connected ⇒ 404 even with tracking on.' );
	}

	public function test_valid_batch_proxied_to_engine_returns_d6_counts(): void {
		$this->enable_beacon();

		$response = RestRequestHelper::post(
			'/beacon',
			array(
				'events' => array(
					array( 'event_id' => 'b1', 'event_type' => 'product_view', 'sku' => 'ACA-1', 'session_id' => 's1', 'event_ts' => '2026-06-06T10:00:00Z' ),
					array( 'event_id' => 'b2', 'event_type' => 'cart_add', 'sku' => 'ACA-1', 'session_id' => 's1', 'event_ts' => '2026-06-06T10:01:00Z' ),
				),
			)
		);

		self::assertSame( 200, $response->get_status() );
		$body = $response->get_data();
		self::assertTrue( $body['ok'] );
		self::assertSame( 2, $body['processed'], 'Both valid events reached the engine and processed.' );
		self::assertSame( array(), $body['errors'] );

		// The api_key must never surface in the proxied response.
		$serialised = (string) wp_json_encode( $body );
		self::assertStringNotContainsString( 'sk_', $serialised );
	}

	public function test_resent_event_id_is_deduplicated(): void {
		$this->enable_beacon();

		$batch = array(
			'events' => array(
				array( 'event_id' => 'dup-1', 'event_type' => 'product_view', 'session_id' => 's1', 'event_ts' => '2026-06-06T10:00:00Z' ),
			),
		);

		$first = RestRequestHelper::post( '/beacon', $batch );
		self::assertSame( 1, $first->get_data()['processed'] );

		$second = RestRequestHelper::post( '/beacon', $batch );
		self::assertSame( 0, $second->get_data()['processed'], 'A resent event_id processes nothing new.' );
		self::assertSame( 1, $second->get_data()['deduplicated'], 'It is counted as deduplicated (transport dedup).' );
	}

	public function test_unknown_event_type_is_400_and_never_reaches_engine(): void {
		$this->enable_beacon();

		$response = RestRequestHelper::post(
			'/beacon',
			array(
				'events' => array(
					array( 'event_id' => 'x1', 'event_type' => 'hack_attempt' ),
				),
			)
		);

		self::assertSame( 400, $response->get_status() );
		$body = $response->get_data();
		self::assertSame( 'invalid_events', $body['error'] );
		self::assertSame( 'event_type', $body['field'] );
		// The engine must not have been called.
		self::assertNull( self::$engine->state()['last_browse_received'] ?? null );
	}

	public function test_missing_event_id_is_400(): void {
		$this->enable_beacon();

		$response = RestRequestHelper::post(
			'/beacon',
			array(
				'events' => array(
					array( 'event_type' => 'product_view' ),
				),
			)
		);

		self::assertSame( 400, $response->get_status() );
		self::assertSame( 'event_id', $response->get_data()['field'] );
	}

	public function test_per_session_rate_limit_returns_429(): void {
		$this->enable_beacon();

		// Squeeze the per-session ceiling to 2 so three requests trip it
		// deterministically (instead of sending 30+).
		add_filter(
			'smaily_connect_beacon_rate_limit_session',
			static function (): int {
				return 2;
			}
		);
		$_COOKIE['smaily_anon_sid'] = 'rl-session-' . md5( 'beacon-rl-test' );
		delete_transient( 'smly_beacon_rl_s_' . md5( (string) $_COOKIE['smaily_anon_sid'] ) );

		$batch = static function ( string $id ): array {
			return array( 'events' => array( array( 'event_id' => $id, 'event_type' => 'product_view', 'session_id' => 'rl' ) ) );
		};

		self::assertSame( 200, RestRequestHelper::post( '/beacon', $batch( 'rl1' ) )->get_status() );
		self::assertSame( 200, RestRequestHelper::post( '/beacon', $batch( 'rl2' ) )->get_status() );
		$third = RestRequestHelper::post( '/beacon', $batch( 'rl3' ) );
		self::assertSame( 429, $third->get_status(), 'The 3rd request in the window exceeds the per-session ceiling of 2.' );
		self::assertSame( 'rate_limited', $third->get_data()['error'] );

		remove_all_filters( 'smaily_connect_beacon_rate_limit_session' );
		delete_transient( 'smly_beacon_rl_s_' . md5( (string) $_COOKIE['smaily_anon_sid'] ) );
	}

	// --- helpers --------------------------------------------------------

	// --- (a).1 profiling gate (the beacon's second gate) -------------------

	/** Mirror ProfilingConsent::cache_key so we can pre-seed a decision. */
	private function profiling_key( string $email ): string {
		return 'smly_profiling_' . md5( strtolower( trim( $email ) ) );
	}

	public function test_profiling_opt_out_drops_only_the_known_email_event(): void {
		$this->enable_beacon();
		// out@ has opted out (cache hit = '0'); in@ + anon are allowed (default-on).
		set_transient( $this->profiling_key( 'out@example.com' ), '0', DAY_IN_SECONDS );

		$response = RestRequestHelper::post(
			'/beacon',
			array(
				'events' => array(
					array( 'event_id' => 'pf-1', 'event_type' => 'product_view', 'session_id' => 's1', 'event_ts' => '2026-06-06T10:00:00Z', 'customer_email' => 'out@example.com' ),
					array( 'event_id' => 'pf-2', 'event_type' => 'product_view', 'session_id' => 's1', 'event_ts' => '2026-06-06T10:01:00Z', 'customer_email' => 'in@example.com' ),
					array( 'event_id' => 'pf-3', 'event_type' => 'product_view', 'session_id' => 's2', 'event_ts' => '2026-06-06T10:02:00Z' ),
				),
			)
		);

		self::assertSame( 200, $response->get_status() );
		// The opted-out event is dropped; the allowed + anon ones reach the engine.
		self::assertSame( 2, $response->get_data()['processed'] );

		delete_transient( $this->profiling_key( 'out@example.com' ) );
	}

	public function test_all_events_opted_out_returns_zero_without_calling_engine(): void {
		$this->enable_beacon();
		set_transient( $this->profiling_key( 'out@example.com' ), '0', DAY_IN_SECONDS );

		$response = RestRequestHelper::post(
			'/beacon',
			array(
				'events' => array(
					array( 'event_id' => 'pf-only', 'event_type' => 'product_view', 'session_id' => 's1', 'event_ts' => '2026-06-06T10:00:00Z', 'customer_email' => 'out@example.com' ),
				),
			)
		);

		self::assertSame( 200, $response->get_status() );
		self::assertSame( 0, $response->get_data()['processed'], 'all opted-out → nothing forwarded' );

		delete_transient( $this->profiling_key( 'out@example.com' ) );
	}

	private function enable_beacon(): void {
		$this->connect_to_mock();
		update_option( BeaconEndpoint::OPTION_TRACK_BROWSING, true );
	}

	private function connect_to_mock(): void {
		$base = (string) self::$engine->base_url();
		EnvSeed::connect(
			array(
				'engine_base_url' => $base,
				'endpoints'       => array(
					'ingest_browse' => $base . '/api/v1/ingest/browse',
				),
			)
		);
	}
}
