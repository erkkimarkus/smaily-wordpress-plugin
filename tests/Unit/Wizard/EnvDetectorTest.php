<?php
/**
 * Tests for the Wizard\EnvDetector.
 *
 * @package Smaily\Connect\Tests
 */

declare(strict_types=1);

namespace Smaily\Connect\Tests\Unit\Wizard;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Smaily\Connect\Multilingual\DetectorFactory;
use Smaily\Connect\Wizard\EnvDetector;

final class EnvDetectorTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		DetectorFactory::reset();

		Functions\when( 'get_option' )->alias(
			static function ( string $key, $default = false ) {
				return $default;
			}
		);
		Functions\when( 'sanitize_key' )->alias(
			static fn ( string $key ): string => strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', $key ) ?? '' )
		);
		// Brain Monkey persists eval'd functions across tests — define
		// no-op defaults for every external function snapshot() touches
		// so test order doesn't matter. Individual tests override only
		// the fields they care about.
		Functions\when( 'count_users' )->justReturn( array( 'total_users' => 0 ) );
		Functions\when( 'wp_count_posts' )->justReturn(
			(object) array(
				'publish' => 0,
				'draft'   => 0,
			)
		);
		Functions\when( 'wc_get_orders' )->justReturn( array() );
		Functions\when( 'wc_get_order_statuses' )->justReturn( array() );

		\Smaily_Connect\Includes\Cypher::$decrypt_return = '';
	}

	protected function tearDown(): void {
		DetectorFactory::reset();
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_snapshot_returns_site_locale_fallback_when_no_multilingual_plugin_present(): void {
		Functions\when( 'get_locale' )->justReturn( 'et_EE' );

		$snapshot = ( new EnvDetector() )->snapshot();

		self::assertSame( array( 'et_EE' ), $snapshot['detectedLanguages'] );
		self::assertNull( $snapshot['multilingualPlugin'] );
	}

	// Polylang / WPML detection is covered exhaustively by
	// DetectorFactoryTest. We do NOT mock pll_languages_list here —
	// Brain Monkey can't fully unset an eval'd function between tests,
	// so a single Polylang test would leak DetectorFactory state into
	// the rest of the suite. EnvDetector's job is to surface what the
	// factory returns; that delegation is what the snapshot tests cover.

	public function test_snapshot_reports_wc_inactive_when_woocommerce_class_missing(): void {
		Functions\when( 'get_locale' )->justReturn( 'en_US' );

		$snapshot = ( new EnvDetector() )->snapshot();

		self::assertFalse( $snapshot['wcActive'] );
		self::assertFalse( $snapshot['hposActive'] );
		// Order count must be 0 when wc_get_orders is undefined — not a fatal error.
		self::assertSame( 0, $snapshot['storeTotals']['orders'] );
	}

	public function test_snapshot_counts_users_and_products(): void {
		Functions\when( 'get_locale' )->justReturn( 'en_US' );
		Functions\when( 'count_users' )->justReturn( array( 'total_users' => 42 ) );
		Functions\when( 'wp_count_posts' )->justReturn(
			(object) array(
				'publish' => 7,
				'draft'   => 3,
			)
		);

		$snapshot = ( new EnvDetector() )->snapshot();

		self::assertSame( 42, $snapshot['storeTotals']['customers'] );
		self::assertSame( 10, $snapshot['storeTotals']['products'] );
	}

	public function test_snapshot_detects_elementor_via_constant(): void {
		if ( ! defined( 'ELEMENTOR_VERSION' ) ) {
			define( 'ELEMENTOR_VERSION', '3.0-test' );
		}
		Functions\when( 'get_locale' )->justReturn( 'en_US' );

		$snapshot = ( new EnvDetector() )->snapshot();

		self::assertTrue( $snapshot['elementorPresent'] );
	}

	public function test_saved_settings_omits_password_field(): void {
		\Smaily_Connect\Includes\Cypher::$decrypt_return = 'plain-pw';

		Functions\when( 'get_option' )->alias(
			static function ( string $key, $default = false ) {
				if ( $key === \Smaily\Connect\Settings\Credentials::LEGACY_OPTION_KEY ) {
					return array(
						'subdomain' => 'demo',
						'username'  => 'alice',
						'password'  => 'enc-blob',
					);
				}
				return $default;
			}
		);

		$saved = ( new EnvDetector() )->saved_settings();

		self::assertSame( 'demo', $saved['smailyCredentials']['subdomain'] );
		self::assertSame( 'alice', $saved['smailyCredentials']['username'] );
		// Password is BLANK in the boot payload — UI re-enters it manually
		// if changed; no-op save leaves the stored value untouched.
		self::assertSame( '', $saved['smailyCredentials']['password'] );
	}

	public function test_saved_settings_carries_default_toggle_values_when_options_absent(): void {
		$saved = ( new EnvDetector() )->saved_settings();

		// Sub-PR 2.H.5: multilingualMode default is empty string when
		// the option was never written — hydrate.ts uses the env to
		// decide Mode B vs 'single'. We do NOT default to 'single' here
		// any more.
		self::assertSame( '', $saved['multilingualMode'] );
		self::assertSame( 'default', $saved['defaultFallbackAccountKey'] );
		self::assertTrue( $saved['subscriberSyncEnabled'] );
		self::assertSame( 30, $saved['abandonedCartCutoffMinutes'] );
		self::assertFalse( $saved['welcomeEnabled'] );
	}
}
