<?php
/**
 * Plugin activation handler for the namespaced Smaily\Connect\* code.
 *
 * @package Smaily\Connect
 */

declare(strict_types=1);

namespace Smaily\Connect;

/**
 * Runs on plugin activation.
 *
 * The legacy Lifecycle class (Smaily_Connect\Includes\Lifecycle) handles CF7 /
 * Elementor / WooCommerce table setup for the 1.x feature set. This class
 * complements it by initialising 2.0-only state:
 *
 *   - DB schema for the new event-queue / backfill-job / automation-mapping /
 *     rec-engine tables (wired up in the DB\Migrator sub-commit)
 *   - Default option values for the new BETA features
 *   - Action Scheduler jobs (registered as later sub-commits land)
 *
 * Activation must be idempotent — WordPress runs it on every "activate" click,
 * including after upgrades. The schema migration is keyed on
 * Constants::OPTION_SCHEMA_VERSION so re-runs are no-ops.
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
	}

	private static function set_default_options(): void {
		// Placeholder: future sub-commits populate this with defaults for the
		// new Settings keys (multilingual mode, notification preferences, etc.).
		// Kept as an explicit empty hook so the structure is visible to readers.
	}

	private static function run_migrations(): void {
		// Placeholder: the DB\Migrator sub-commit replaces this with a real call:
		//
		//   ( new DB\Migrator() )->migrate();
		//
		// Activation hook is the correct trigger because dbDelta() expects a
		// fully loaded WP environment, which is guaranteed on the activation
		// request.
	}

	private function __construct() {
		// Static-only handler.
	}
}
