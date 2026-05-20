<?php
/**
 * Setup Wizard admin page mount.
 *
 * Registers the `Smaily → Setup wizard` submenu, enqueues the
 * Vite-built admin bundle, and renders the React mount node with
 * data-view="wizard". State hydration happens client-side via
 * `window.smailyConnectBoot` (set by wp_localize_script below).
 *
 * Coexistence note: the legacy Smaily admin (smaily-admin.class.php)
 * still owns its own top-level menu page. This new mount lives as a
 * SIBLING entry — Phase 4 swaps the parent when the React tree replaces
 * legacy admin UI wholesale. Until then the merchant sees both:
 *
 *   Smaily            (legacy menu, current pilot)
 *   Smaily Connect    (this menu — BETA fork wizard + settings)
 *
 * @package Smaily\Connect
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

use Smaily\Connect\Wizard\EnvDetector;

/**
 * Registers the wizard + settings submenu pages under a fresh top-level
 * "Smaily Connect" menu. Hooked from Bootstrap::boot() onto admin_menu.
 */
function smaily_connect_register_admin_pages(): void {
	$capability = 'manage_options';
	$icon       = 'data:image/svg+xml;base64,' . base64_encode(
		'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor"><path d="M3 5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2zm9 4-7 4v7h14v-7z"/></svg>'
	);

	add_menu_page(
		__( 'Smaily Connect', 'smaily-connect' ),
		'Smaily Connect',
		$capability,
		'smaily-connect-wizard',
		'smaily_connect_render_wizard_page',
		$icon,
		56
	);

	add_submenu_page(
		'smaily-connect-wizard',
		__( 'Setup wizard', 'smaily-connect' ),
		__( 'Setup wizard', 'smaily-connect' ),
		$capability,
		'smaily-connect-wizard',
		'smaily_connect_render_wizard_page'
	);

	add_submenu_page(
		'smaily-connect-wizard',
		__( 'Settings', 'smaily-connect' ),
		__( 'Settings', 'smaily-connect' ),
		$capability,
		'smaily-connect-settings',
		'smaily_connect_render_settings_page'
	);
}

/**
 * Render the wizard mount node. The actual React bundle takes over from
 * `<div id="smaily-connect-app" data-view="wizard">` after DOMContentLoaded.
 */
function smaily_connect_render_wizard_page(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You do not have permission to view this page.', 'smaily-connect' ) );
	}

	smaily_connect_emit_mount( 'wizard' );
}

/**
 * Render the settings mount node. Same React bundle, different data-view.
 */
function smaily_connect_render_settings_page(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You do not have permission to view this page.', 'smaily-connect' ) );
	}

	smaily_connect_emit_mount( 'settings' );
}

/**
 * Shared HTML wrapper. The `wrap` class lets WP's default admin notices
 * land above the React tree; the mount node sits inside so React owns
 * its own subtree exclusively.
 *
 * @param 'wizard'|'settings' $view
 */
function smaily_connect_emit_mount( string $view ): void {
	?>
	<div class="wrap" id="smaily-connect-wrap">
		<div
			id="smaily-connect-app"
			data-view="<?php echo esc_attr( $view ); ?>"
		></div>
	</div>
	<?php
}

/**
 * Enqueue the Vite-built admin bundle when on a Smaily Connect screen.
 * Bound from Bootstrap::boot() onto admin_enqueue_scripts.
 *
 * @param string $hook_suffix The current admin page hook (toplevel_page_*).
 */
function smaily_connect_enqueue_admin_bundle( string $hook_suffix ): void {
	if ( strpos( $hook_suffix, 'smaily-connect' ) === false ) {
		return;
	}

	$dist_dir = SMAILY_CONNECT_PLUGIN_PATH . 'dist/admin';
	$dist_url = plugins_url( 'dist/admin', SMAILY_CONNECT_PLUGIN_FILE );

	$js_path  = $dist_dir . '/admin.js';
	$css_path = $dist_dir . '/admin.css';

	if ( ! file_exists( $js_path ) ) {
		add_action(
			'admin_notices',
			static function (): void {
				printf(
					'<div class="notice notice-error"><p>%s</p></div>',
					esc_html__( 'Smaily Connect admin bundle not built. Run `npm run build` in the plugin directory.', 'smaily-connect' )
				);
			}
		);
		return;
	}

	wp_enqueue_script(
		'smaily-connect-admin',
		$dist_url . '/admin.js',
		array(),
		(string) filemtime( $js_path ),
		true
	);

	// ESM bundle — Vite emits `type="module"` markup. WordPress' enqueue
	// API doesn't set the attribute directly; we tag it via the
	// script_loader_tag filter just for this handle.
	add_filter(
		'script_loader_tag',
		static function ( string $tag, string $handle ): string {
			if ( $handle === 'smaily-connect-admin' ) {
				return str_replace( ' src=', ' type="module" src=', $tag );
			}
			return $tag;
		},
		10,
		2
	);

	if ( file_exists( $css_path ) ) {
		wp_enqueue_style(
			'smaily-connect-admin',
			$dist_url . '/admin.css',
			array(),
			(string) filemtime( $css_path )
		);
	}

	$detector = new EnvDetector();
	$boot     = array(
		'nonce'         => wp_create_nonce( 'wp_rest' ),
		'restRoot'      => esc_url_raw( rest_url( 'smaily-connect/v1' ) ),
		'view'          => isset( $_GET['page'] ) && $_GET['page'] === 'smaily-connect-settings' ? 'settings' : 'wizard', // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		'envSnapshot'   => $detector->snapshot(),
		'savedSettings' => $detector->saved_settings(),
	);

	wp_add_inline_script(
		'smaily-connect-admin',
		'window.smailyConnectBoot = ' . wp_json_encode( $boot ) . ';',
		'before'
	);
}
