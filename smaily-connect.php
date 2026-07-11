<?php
/*
 * Author URI:           https://smaily.com
 * Author:               Smaily
 * Description:          Connect your WooCommerce shop to Smaily for email marketing, automation, and personalised recommendations. (BETA: extended e-commerce sync and Smaily Campaign Intelligence integration.)
 * Domain Path:          /languages
 * License URI:          https://www.gnu.org/licenses/gpl-3.0.en.html
 * License:              GPL-3.0+
 * Requires at least:    6.6
 * Requires PHP:         8.0
 * WC requires at least: 6.9
 * WC tested up to:      10.7
 * Plugin Name:          Smaily Connect
 * Plugin URI:           https://smaily.com/help/user-manual/smaily-connect-for-wordpress/
 * Text Domain:          smaily-connect
 * Update URI:           https://github.com/erkkimarkus/smaily-wordpress-plugin
 * Version:              3.6.1
*/

/*
 * Update URI (above) is load-bearing, not informational: the folder name
 * matches the wordpress.org `smaily-connect` slug, and upstream ships its
 * own releases there (their 2.0.0 is a 1.x-line WP-7.0 bump, NOT this
 * codebase). Without this header WordPress would offer — or with
 * auto-updates enabled, silently apply — upstream's package over this
 * fork. Any non-wordpress.org value makes core skip w.org updates for
 * this plugin entirely (WP 5.8+). Do not remove until the fork is merged
 * back upstream. The 2.1.x numbering exists for the same collision (see
 * DECISIONS F3-35).
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Current plugin version (PSR-4 callers should prefer Smaily\Connect\Constants::version()).
 */
define( 'SMAILY_CONNECT_VERSION', '3.6.1' );

/**
 * Legacy version constant — kept for upstream compatibility (used by older
 * classes that still reference it). New code should use SMAILY_CONNECT_VERSION.
 */
define( 'SMAILY_CONNECT_PLUGIN_VERSION', '3.6.1' );

/**
 * The name of the plugin.
 */
define( 'SMAILY_CONNECT_PLUGIN_NAME', 'smaily-connect' );

/**
 * Absolute URL to the Smaily plugin directory.
 */
define( 'SMAILY_CONNECT_PLUGIN_URL', plugins_url( '', __FILE__ ) );

/**
 * Absolute path to the Smaily plugin directory.
 */
define( 'SMAILY_CONNECT_PLUGIN_PATH', plugin_dir_path( __FILE__ ) );

/**
 * Absolute path to the core plugin file.
 */
define( 'SMAILY_CONNECT_PLUGIN_FILE', __FILE__ );

/**
 * Composer autoloader — required for the Smaily\Connect\* PSR-4 namespace and
 * for the bundled Action Scheduler. When vendor/ is missing (e.g. a developer
 * cloned the repo without running `composer install`), surface a clear notice
 * instead of fatally erroring during plugin activation.
 */
if ( file_exists( SMAILY_CONNECT_PLUGIN_PATH . 'vendor/autoload.php' ) ) {
	require_once SMAILY_CONNECT_PLUGIN_PATH . 'vendor/autoload.php';
} else {
	add_action(
		'admin_notices',
		static function () {
			if ( ! current_user_can( 'activate_plugins' ) ) {
				return;
			}
			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				esc_html__(
					'Smaily Connect is missing its Composer dependencies. Run `composer install` in the plugin directory.',
					'smaily-connect'
				)
			);
		}
	);

	return;
}

// Required to use functions is_plugin_active and deactivate_plugins.
require_once ABSPATH . 'wp-admin/includes/plugin.php';

/**
 * The plugin lifecycle (legacy bootstrap — handles CF7, Elementor, Gutenberg blocks,
 * existing Smaily-API client). Retained verbatim during the BETA to keep upstream
 * functionality working unchanged; the new namespaced code in Smaily\Connect\*
 * runs alongside it.
 */
require_once SMAILY_CONNECT_PLUGIN_PATH . 'includes/smaily-lifecycle.class.php';

/**
 * The core legacy plugin class.
 */
require_once SMAILY_CONNECT_PLUGIN_PATH . 'includes/smaily.class.php';

/**
 * Begins execution of the legacy plugin.
 */
if ( class_exists( 'Smaily_Connect' ) ) {
	new Smaily_Connect( SMAILY_CONNECT_PLUGIN_NAME, SMAILY_CONNECT_PLUGIN_VERSION );
}

/**
 * Boot the namespaced Smaily\Connect\* code path. Runs alongside the legacy
 * class above; the two coexist in disjoint namespaces during the BETA.
 */
\Smaily\Connect\Bootstrap::instance()->boot();
