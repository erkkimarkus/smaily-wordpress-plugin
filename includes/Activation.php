<?php
/**
 * Plugin activation handler for the namespaced Smaily\Connect\* code.
 *
 * @package Smaily\Connect
 */

declare(strict_types=1);

namespace Smaily\Connect;

defined( 'ABSPATH' ) || exit;

use Smaily\Connect\DB\Migrator;
use Smaily\Connect\Migration\WPCronAuditor;
use Smaily\Connect\Smaily\EventQueue;

/**
 * Runs on plugin activation.
 *
 * The legacy Lifecycle class (Smaily_Connect\Includes\Lifecycle) handles CF7 /
 * Elementor / WooCommerce table setup for the 1.x feature set. This class
 * complements it by initialising 2.0-only state:
 *
 *   - DB schema for the new event-queue / backfill-job / automation-mapping /
 *     rec-engine tables (via DB\Migrator)
 *   - Default option values for the new BETA features
 *   - Legacy WP-Cron → Action Scheduler migration (via Migration\WPCronAuditor)
 *   - Action Scheduler recurring jobs that drive the queue + cart flows
 *
 * Activation must be idempotent — WordPress runs it on every "activate" click,
 * including after upgrades. The schema migration is keyed on
 * Constants::OPTION_SCHEMA_VERSION so re-runs are no-ops. AS scheduling uses
 * as_has_scheduled_action() so we never enqueue a duplicate recurring row.
 */
final class Activation {

	/**
	 * Activation callback. WordPress passes a $network_wide boolean we ignore
	 * for now — multisite network-wide activation is in the v1.x backlog
	 * (PLUGIN.md §1 "Selgelt väljas").
	 */
	public static function run(): void {
		self::set_default_options();
		self::run_migrations();
		self::migrate_wp_cron_to_action_scheduler();
		self::schedule_recurring_action_scheduler_jobs();
	}

	private static function set_default_options(): void {
		// Placeholder: future sub-commits populate this with defaults for the
		// new Settings keys (multilingual mode, notification preferences, etc.).
		// Kept as an explicit empty hook so the structure is visible to readers.
	}

	private static function run_migrations(): void {
		( new Migrator() )->migrate();
	}

	/**
	 * Three-pass legacy-cron migration (WPCronAuditor):
	 *   1. log what's currently scheduled,
	 *   2. wp_clear_scheduled_hook each known legacy hook,
	 *   3. paranoid re-audit; admin-error if anything survived.
	 *
	 * Legacy hooks: smaily_connect_cron_sync_subscribers,
	 * smaily_connect_cron_abandoned_carts_status,
	 * smaily_connect_cron_abandoned_carts_email.
	 */
	private static function migrate_wp_cron_to_action_scheduler(): void {
		$auditor = new WPCronAuditor();

		$before = $auditor->audit_before_clear();
		if ( ! empty( $before ) ) {
			error_log(
				sprintf(
					'[smaily-connect] WP-Cron audit before clear: %s',
					wp_json_encode( $before )
				)
			);
		}

		$auditor->clear_legacy_crons();

		$survivors = $auditor->audit_after_clear();
		if ( ! empty( $survivors ) ) {
			error_log(
				sprintf(
					'[smaily-connect] WP-Cron audit AFTER clear still shows %s — investigate (custom plugin re-scheduling?)',
					wp_json_encode( $survivors )
				)
			);
		}
	}

	/**
	 * Replace the legacy WP-Cron schedules with Action Scheduler recurring
	 * actions (Bootstrap registers the actual tick callbacks):
	 *
	 *   smly_plus_contact_sync   — daily,  bridges to smaily_connect_cron_sync_subscribers
	 *   smly_plus_abandoned_cart — 15 min, bridges to the two abandoned_cart_* legacy hooks
	 *
	 * smly_plus_flush_event_queue + smly_plus_retry_failed_events are
	 * scheduled by Bootstrap::register_action_scheduler_jobs on init for
	 * every request; the activation hook only seeds the two cart/sync
	 * recurring rows because those need the daily / 15-min cadence rather
	 * than the queue's tight 60s flush loop.
	 */
	private static function schedule_recurring_action_scheduler_jobs(): void {
		if ( ! function_exists( 'as_has_scheduled_action' ) || ! function_exists( 'as_schedule_recurring_action' ) ) {
			return;
		}

		if ( ! as_has_scheduled_action( 'smly_plus_contact_sync', array(), EventQueue::AS_GROUP ) ) {
			as_schedule_recurring_action(
				time(),
				DAY_IN_SECONDS,
				'smly_plus_contact_sync',
				array(),
				EventQueue::AS_GROUP
			);
		}

		if ( ! as_has_scheduled_action( 'smly_plus_abandoned_cart', array(), EventQueue::AS_GROUP ) ) {
			as_schedule_recurring_action(
				time(),
				15 * MINUTE_IN_SECONDS,
				'smly_plus_abandoned_cart',
				array(),
				EventQueue::AS_GROUP
			);
		}
	}

	private function __construct() {
		// Static-only handler.
	}
}
