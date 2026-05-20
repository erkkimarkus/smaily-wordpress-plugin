<?php
/**
 * Detects the WordPress environment for the bootstrap payload that
 * admin/wizard.php and admin/settings.php hand to the React bundle.
 *
 * @package Smaily\Connect\Wizard
 */

declare(strict_types=1);

namespace Smaily\Connect\Wizard;

defined( 'ABSPATH' ) || exit;

use Smaily\Connect\Multilingual\DetectorFactory;
use Smaily\Connect\Multilingual\PolylangAdapter;
use Smaily\Connect\Multilingual\SiteLocaleAdapter;
use Smaily\Connect\Multilingual\TranslatePressAdapter;
use Smaily\Connect\Multilingual\WPMLAdapter;
use Smaily\Connect\Settings\Credentials;

/**
 * One-shot snapshot of the WP site state the wizard / settings panels
 * need at boot. Emitted into a single `wp_localize_script` call as
 * `smailyConnectBoot.envSnapshot` (and a parallel `savedSettings` for
 * Mode B/C credentials, persisted toggles, etc).
 *
 * Why a dedicated class instead of inlining the queries into the PHP
 * mount files: each piece of data has its own subtle "what does this
 * mean if X is inactive" question (WC inactive → orders count 0, not
 * the function-doesn't-exist error; Polylang inactive → single-locale
 * fallback, not an empty list). Pulling them together here keeps the
 * mount files small and lets PHPUnit cover the edge cases without
 * standing up admin pages.
 *
 * Result shape — matches WizardState.env in admin/src/state/types.ts:
 *
 *   {
 *     detectedLanguages: string[],
 *     multilingualPlugin: 'wpml'|'polylang'|'translatepress'|null,
 *     elementorPresent:   bool,
 *     cf7Present:         bool,
 *     wcActive:           bool,
 *     hposActive:         bool,
 *     storeTotals: {
 *       customers: int,
 *       orders:    int,
 *       products:  int,
 *     },
 *   }
 *
 * The React bundle reads everything that's not in WizardState.env
 * (`hposActive`, `multilingualPlugin`, `wcActive`) for status
 * banners and conditional UI but doesn't mirror it into reducer
 * state — those values can't change without a page reload.
 */
class EnvDetector {

	/**
	 * Snapshot the full env payload. Pure read — does not write options.
	 *
	 * @return array{
	 *   detectedLanguages: array<int, string>,
	 *   multilingualPlugin: 'wpml'|'polylang'|'translatepress'|null,
	 *   elementorPresent: bool,
	 *   cf7Present: bool,
	 *   wcActive: bool,
	 *   hposActive: bool,
	 *   storeTotals: array{customers: int, orders: int, products: int},
	 * }
	 */
	public function snapshot(): array {
		return array(
			'detectedLanguages'  => $this->detected_languages(),
			'multilingualPlugin' => $this->multilingual_plugin(),
			'elementorPresent'   => $this->elementor_present(),
			'cf7Present'         => $this->cf7_present(),
			'wcActive'           => $this->wc_active(),
			'hposActive'         => $this->hpos_active(),
			'storeTotals'        => $this->store_totals(),
		);
	}

	/**
	 * @return array<int, string>
	 */
	private function detected_languages(): array {
		$languages = DetectorFactory::create()->get_detected_languages();

		// Normalise: drop empties and re-index.
		$languages = array_values(
			array_filter(
				$languages,
				static fn ( $lang ): bool => is_string( $lang ) && $lang !== ''
			)
		);

		return $languages;
	}

	/**
	 * Returns the canonical key for the active multilingual plugin, or
	 * null when on the SiteLocaleAdapter fallback. The React bundle uses
	 * this to decide whether the MultilingualModePicker is meaningful
	 * (single-locale sites stay on Mode "single" and the picker hides).
	 */
	private function multilingual_plugin(): ?string {
		$detector = DetectorFactory::create();

		if ( $detector instanceof WPMLAdapter ) {
			return 'wpml';
		}
		if ( $detector instanceof PolylangAdapter ) {
			return 'polylang';
		}
		if ( $detector instanceof TranslatePressAdapter ) {
			return 'translatepress';
		}
		if ( $detector instanceof SiteLocaleAdapter ) {
			return null;
		}

		// Future detector subclasses get reported as null — the UI then
		// falls back to single-mode behaviour, which is the safe default.
		return null;
	}

	/**
	 * Elementor is detected via its main class constant, mirroring the
	 * legacy admin's check. `class_exists()` triggers autoload, which is
	 * fine here because Elementor's classes are PSR-4 and cheap to probe.
	 */
	private function elementor_present(): bool {
		return defined( 'ELEMENTOR_VERSION' ) || class_exists( '\Elementor\Plugin' );
	}

	/**
	 * Contact Form 7 ships the `WPCF7_VERSION` constant on plugins_loaded.
	 * Some forks load the engine without the constant — `class_exists`
	 * for the main class plugs that gap.
	 */
	private function cf7_present(): bool {
		return defined( 'WPCF7_VERSION' ) || class_exists( '\WPCF7' );
	}

	private function wc_active(): bool {
		return class_exists( '\WooCommerce' );
	}

	/**
	 * High-Performance Order Storage status. We check the OrderUtil
	 * helper rather than reading the wc_orders table directly — WC 7.1+
	 * exposes it as the canonical API and the helper handles the
	 * feature-flag transition correctly when HPOS is "enabled but not yet
	 * synced" (which would otherwise look false).
	 */
	private function hpos_active(): bool {
		if ( ! $this->wc_active() ) {
			return false;
		}
		if ( class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' ) ) {
			return (bool) \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
		}
		return false;
	}

	/**
	 * @return array{customers: int, orders: int, products: int}
	 */
	private function store_totals(): array {
		return array(
			'customers' => $this->customers_count(),
			'orders'    => $this->orders_count(),
			'products'  => $this->products_count(),
		);
	}

	private function customers_count(): int {
		if ( ! function_exists( 'count_users' ) ) {
			return 0;
		}
		// Counting users by role: 'customer' (WC) + 'subscriber' (WP) +
		// 'shop_manager' would over-count merchants. Step 2 of the wizard
		// frames the backfill scope as "WordPress users", so the user-table
		// total is the honest number — same that legacy Options uses.
		$counts = count_users();
		return (int) $counts['total_users'];
	}

	/**
	 * Orders count, HPOS-aware. wc_get_orders returns the row count when
	 * we pass `return = 'ids'` and `limit = -1`, with one query against
	 * the active store (wp_wc_orders for HPOS, wp_posts for legacy CPT).
	 * Step 6's Done summary shows this number so the merchant can confirm
	 * the backfill will catch their order history.
	 */
	private function orders_count(): int {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return 0;
		}
		$ids = wc_get_orders(
			array(
				'limit'  => -1,
				'return' => 'ids',
				'status' => array_keys( wc_get_order_statuses() ),
			)
		);
		return is_array( $ids ) ? count( $ids ) : 0;
	}

	private function products_count(): int {
		if ( ! function_exists( 'wp_count_posts' ) ) {
			return 0;
		}
		$counts = wp_count_posts( 'product' );
		if ( ! is_object( $counts ) ) {
			return 0;
		}
		// Sum of published + draft — Step 2's backfill scope includes
		// drafts because the contact-level data doesn't depend on a
		// product being publicly visible.
		$published = isset( $counts->publish ) ? (int) $counts->publish : 0;
		$draft     = isset( $counts->draft ) ? (int) $counts->draft : 0;
		return $published + $draft;
	}

	/**
	 * Snapshot the currently-persisted settings so the React bundle
	 * doesn't have to round-trip via the REST API on initial load. The
	 * payload mirrors the per-tab POST body shape so the same field
	 * names land in WizardState on hydrate.
	 *
	 * Credentials are returned WITHOUT the password — security gate. The
	 * UI re-renders the password field as an empty input ("***"
	 * placeholder) and a no-op save leaves the stored value untouched.
	 *
	 * @return array<string, mixed>
	 */
	public function saved_settings(): array {
		$creds = new Credentials();
		$set   = $creds->get();

		return array(
			'smailyCredentials'             => array(
				'subdomain' => $set !== null ? $set->subdomain : '',
				'username'  => $set !== null ? $set->username : '',
				// Password intentionally omitted; UI shows it blank.
				'password'  => '',
			),
			// Empty string when the option was never set — lets hydrate
			// pick an env-aware default (Mode B for multilingual sites,
			// 'single' otherwise). Once the merchant explicitly saves a
			// mode via Settings the actual value lands here and wins.
			'multilingualMode'              => (string) get_option( 'smly_plus_multilingual_mode', '' ),
			'defaultFallbackAccountKey'     => (string) get_option( 'smly_plus_default_fallback_account', 'default' ),

			// Step 2: subscribers.
			'subscriberSyncEnabled'         => (bool) get_option( 'smaily_connect_subscriber_sync_enabled', true ),
			'syncFields'                    => (array) get_option( 'smaily_connect_subscriber_sync_fields', array() ),
			'wordpressSubscriptionCheckbox' => (bool) get_option( 'smaily_connect_wp_subscription_enabled', false ),
			'checkoutSubscriptionCheckbox'  => (bool) get_option( 'smaily_connect_checkout_subscription_enabled', false ),

			// Step 3: WooCommerce automations.
			'abandonedCartCutoffMinutes'    => (int) get_option( 'smaily_connect_abandoned_cart_cutoff', 30 ),
			'welcomeEnabled'                => (bool) get_option( 'smly_plus_welcome_enabled', false ),
			'firstOrderEnabled'             => (bool) get_option( 'smly_plus_first_order_enabled', false ),
			'abandonedCartEnabled'          => (bool) get_option( 'smaily_connect_abandoned_cart_status', false ),
		);
	}
}
