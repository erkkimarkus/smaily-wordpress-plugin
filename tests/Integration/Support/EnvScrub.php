<?php
/**
 * Test-support helper — resets every wp_options row + table content the
 * plugin owns so each integration test starts from a known clean slate.
 *
 * @package Smaily\Connect\Tests\Integration
 */

declare(strict_types=1);

namespace Smaily\Connect\Tests\Integration\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Mirror of uninstall.php's cleanup but WITHOUT dropping the custom
 * tables (we only TRUNCATE) — the schema must stay so the next test
 * doesn't need to reactivate the plugin. Use this in setUp() of every
 * integration test to avoid cross-test state bleed.
 *
 * Why not piggyback on uninstall.php directly: that file DROPs tables
 * + invalidates the object cache, both of which are expensive and
 * destructive between test cases. EnvScrub is the lighter weight
 * sibling for test-isolation.
 */
final class EnvScrub {

	public static function reset(): void {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared

		// wp_options — every smly_plus_* row and the legacy keys this plugin owns.
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
				$wpdb->esc_like( 'smly_plus_' ) . '%'
			)
		);
		$legacy_options = array(
			'smaily_connect_api_credentials',
			'smaily_connect_subscriber_sync_enabled',
			'smaily_connect_subscriber_sync_fields',
			'smaily_connect_checkout_subscription_enabled',
			'smaily_connect_wp_subscription_enabled',
			'smaily_connect_abandoned_cart_cutoff',
			'smaily_connect_abandoned_cart_status',
		);
		foreach ( $legacy_options as $opt ) {
			delete_option( $opt );
		}

		// Custom tables — TRUNCATE only (keep schema).
		$tables = array(
			'smly_plus_event_queue',
			'smly_plus_backfill_job',
			'smly_plus_automation_mapping',
			'smly_rec_event_queue',
			'smly_rec_visitor',
		);
		foreach ( $tables as $suffix ) {
			$table = $wpdb->prefix . $suffix;
			// Defensive: integration suite may run before activation
			// completed the migrator; TRUNCATE on a missing table is
			// fatal, so skip when absent.
			$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
			if ( $exists === $table ) {
				$wpdb->query( "TRUNCATE TABLE {$table}" );
			}
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared

		// Invalidate alloptions so the in-process get_option() reflects
		// the deletes immediately rather than serving the warm cache.
		wp_cache_delete( 'alloptions', 'options' );
	}

	private function __construct() {
		// Static-only helper.
	}
}
