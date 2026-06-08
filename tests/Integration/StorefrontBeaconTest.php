<?php
/**
 * Integration: StorefrontBeacon (3.4.3a) — the storefront enqueue gate and the
 * boot blob it hands the beacon JS.
 *
 * @package Smaily\Connect\Tests\Integration
 */

declare(strict_types=1);

namespace Smaily\Connect\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Smaily\Connect\Integrations\WooCommerce\StorefrontBeacon;
use Smaily\Connect\Settings\RecEngineSettings;
use Smaily\Connect\Tests\Integration\Support\EnvScrub;
use Smaily\Connect\Tests\Integration\Support\EnvSeed;

/**
 * What this catches that a unit test can't: the gate reads real wp_options
 * (connected flag + the track-browsing toggle) and the config blob is built
 * from the real stored setup-exchange config — so it asserts the same data the
 * storefront would actually receive.
 */
final class StorefrontBeaconTest extends TestCase {

	private const TRACK_OPTION = 'smly_plus_rec_track_browsing';

	protected function setUp(): void {
		parent::setUp();
		EnvScrub::reset();
		if ( ! function_exists( 'is_woocommerce' ) ) {
			self::markTestSkipped( 'WooCommerce not active — the storefront beacon gates on it.' );
		}
		update_option( self::TRACK_OPTION, false );
	}

	protected function tearDown(): void {
		update_option( self::TRACK_OPTION, false );
		parent::tearDown();
	}

	private function beacon(): StorefrontBeacon {
		return new StorefrontBeacon( new RecEngineSettings() );
	}

	public function test_disabled_when_not_connected(): void {
		update_option( self::TRACK_OPTION, true );
		self::assertFalse( $this->beacon()->is_enabled(), 'No connected engine ⇒ no beacon.' );
	}

	public function test_disabled_when_tracking_off(): void {
		EnvSeed::connect();
		update_option( self::TRACK_OPTION, false );
		self::assertFalse( $this->beacon()->is_enabled(), 'Browse-tracking off ⇒ no beacon.' );
	}

	public function test_enabled_when_connected_and_tracking_on(): void {
		EnvSeed::connect();
		update_option( self::TRACK_OPTION, true );
		self::assertTrue( $this->beacon()->is_enabled() );
	}

	public function test_beacon_config_carries_beacon_url_and_cookie_names(): void {
		EnvSeed::connect();
		$config = $this->beacon()->beacon_config();

		self::assertStringContainsString( '/smaily-connect/v1/beacon', (string) $config['beaconUrl'] );
		// Names come from the seeded engine config (EnvSeed::fixture_config).
		self::assertSame( 'smaily_anon_sid', $config['cookieNames']['session'] );
		self::assertSame( 'smaily_rec_uid', $config['cookieNames']['visitor'] );
		self::assertSame( 'smaily_vt', $config['urlParams']['visitorToken'] );
		self::assertSame( 30, $config['cookieTtlDays']['recId'] );
		self::assertSame( 365, $config['cookieTtlDays']['visitor'] );
	}

	public function test_beacon_config_falls_back_to_defaults_without_engine_config(): void {
		// Connected but with an empty config map → §6 defaults.
		EnvSeed::connect( array( 'config' => array() ) );
		$config = $this->beacon()->beacon_config();

		self::assertSame( 'smaily_anon_sid', $config['cookieNames']['session'] );
		self::assertSame( 'smaily_rec_id', $config['cookieNames']['recId'] );
		self::assertSame( 30, $config['sessionTtlDays'] );
	}

	public function test_page_context_is_other_without_a_storefront_query(): void {
		EnvSeed::connect();
		$context = $this->beacon()->page_context();
		self::assertSame( 'other', $context['pageType'], 'No product/category/search query ⇒ no page-view event.' );
	}
}
