<?php
/**
 * Plugin bootstrap — wires WordPress lifecycle hooks for the namespaced
 * Smaily\Connect\* code that runs alongside the legacy Smaily_Connect\* tree.
 *
 * @package Smaily\Connect
 */

declare(strict_types=1);

namespace Smaily\Connect;

defined( 'ABSPATH' ) || exit;

/**
 * Singleton entry point invoked from smaily-connect.php.
 *
 * Responsibilities (Phase 1 surface, the rest is filled in by later sub-commits):
 *
 *   - Register activation / deactivation callbacks for the new code path
 *   - Declare WooCommerce HPOS compatibility before WC initialises
 *   - Load translations for the new text-domain entries on plugins_loaded
 *
 * Action Scheduler hook handlers, REST controllers, hooks for cart/order/user
 * events, and the Settings/Wizard controllers will be registered from this
 * class as later sub-commits introduce them.
 */
final class Bootstrap {

	private static ?self $instance = null;

	private bool $booted = false;

	public static function instance(): self {
		if ( self::$instance === null ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Wires all WordPress hooks. Safe to call multiple times — only the first
	 * call has effect.
	 */
	public function boot(): void {
		if ( $this->booted ) {
			return;
		}
		$this->booted = true;

		$plugin_file = defined( 'SMAILY_CONNECT_PLUGIN_FILE' )
			? SMAILY_CONNECT_PLUGIN_FILE
			: __FILE__;

		register_activation_hook( $plugin_file, array( Activation::class, 'run' ) );
		register_deactivation_hook( $plugin_file, array( Deactivation::class, 'run' ) );

		add_action( 'before_woocommerce_init', array( $this, 'declare_woocommerce_compatibility' ) );
		add_action( 'plugins_loaded', array( $this, 'load_textdomain' ) );
	}

	/**
	 * Declare compatibility with WooCommerce High-Performance Order Storage.
	 *
	 * Required because the BETA fork uses wc_get_order() / wc_get_orders() and
	 * is explicitly HPOS-aware; without this declaration WooCommerce 8.2+ shows
	 * the plugin as "unknown" and refuses to enable HPOS.
	 */
	public function declare_woocommerce_compatibility(): void {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
				'custom_order_tables',
				defined( 'SMAILY_CONNECT_PLUGIN_FILE' ) ? SMAILY_CONNECT_PLUGIN_FILE : __FILE__,
				true
			);
		}
	}

	/**
	 * Load translations from the bundled languages/ directory.
	 *
	 * The legacy Lifecycle class also calls load_plugin_textdomain on the same
	 * text-domain (smaily-connect); WordPress dedupes the call internally, so
	 * the double registration is harmless. We register from the new code path
	 * to keep the namespaced classes self-contained.
	 */
	public function load_textdomain(): void {
		load_plugin_textdomain(
			Constants::TEXT_DOMAIN,
			false,
			dirname( plugin_basename( defined( 'SMAILY_CONNECT_PLUGIN_FILE' ) ? SMAILY_CONNECT_PLUGIN_FILE : __FILE__ ) ) . '/languages'
		);
	}

	private function __construct() {
		// Use Bootstrap::instance() instead of constructing directly.
	}
}
