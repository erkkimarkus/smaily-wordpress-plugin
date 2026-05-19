<?php
/**
 * Versioned schema migrator for the BETA fork's new tables.
 *
 * @package Smaily\Connect\DB
 */

declare(strict_types=1);

namespace Smaily\Connect\DB;

defined( 'ABSPATH' ) || exit;

use Smaily\Connect\Constants;

/**
 * Runs forward-only SQL migrations from the migrations/ directory against
 * the current site's database.
 *
 * Migration files follow the pattern `NNN-{slug}.sql` (e.g.
 * `001-create-smly-plus-event-queue.sql`). The numeric prefix is the
 * schema version applied; files are sorted by integer (so 010 sorts
 * after 009, not lexicographically). The runner persists the highest
 * applied version in the Constants::OPTION_SCHEMA_VERSION option and
 * only runs files whose version exceeds it on each call, which keeps
 * activation idempotent.
 *
 * Coexistence with the legacy lifecycle:
 *   - The legacy Smaily_Connect\Includes\Lifecycle has its own migration
 *     map keyed by plugin version (1.3.0 → upgrade-1-3-0.php) and stores
 *     its own version pointer. Both systems live in migrations/ but
 *     don't read each other's files — pattern matching keeps them
 *     disjoint: legacy uses `upgrade-*.php`, the new system uses
 *     `NNN-*.sql`.
 *
 * SQL placeholder substitution:
 *   - `{prefix}` → the WordPress table prefix ($wpdb->prefix)
 *   - `{charset_collate}` → $wpdb->get_charset_collate(), the
 *     "DEFAULT CHARACTER SET ... COLLATE ..." clause WP recommends for
 *     all plugin tables
 *
 * dbDelta() is the WordPress-blessed way to run CREATE TABLE statements
 * — it handles re-running the same SQL safely (DESCRIBE first, then ALTER
 * for missing columns) and is the only path that satisfies the
 * Plugin Check tool's expectations.
 */
final class Migrator {

	private string $migrations_dir;

	public function __construct( ?string $migrations_dir = null ) {
		$this->migrations_dir = $migrations_dir ?? $this->default_migrations_dir();
	}

	/**
	 * Apply all migrations whose version exceeds the currently stored one.
	 *
	 * @return int[] List of versions that were applied during this call.
	 */
	public function migrate(): array {
		$current = (int) get_option( Constants::OPTION_SCHEMA_VERSION, 0 );
		$applied = array();

		foreach ( $this->discover() as $version => $file ) {
			if ( $version <= $current ) {
				continue;
			}

			$this->apply( $file );
			update_option( Constants::OPTION_SCHEMA_VERSION, $version, false );
			$applied[] = $version;
		}

		return $applied;
	}

	/**
	 * Discover migration files in the migrations directory.
	 *
	 * Returns an array keyed by integer version, sorted ascending. Only
	 * files matching `NNN-*.sql` participate — anything else (legacy
	 * upgrade-*.php, index.php, README, …) is ignored.
	 *
	 * Public to allow unit tests to introspect discovery output without
	 * having to mock the filesystem.
	 *
	 * @return array<int, string>
	 */
	public function discover(): array {
		if ( ! is_dir( $this->migrations_dir ) ) {
			return array();
		}

		$found = array();

		foreach ( (array) glob( $this->migrations_dir . '/*.sql' ) as $path ) {
			$basename = basename( $path );
			if ( preg_match( '/^(\d+)-.+\.sql$/', $basename, $matches ) !== 1 ) {
				continue;
			}

			$version = (int) $matches[1];
			if ( $version <= 0 ) {
				continue;
			}

			$found[ $version ] = $path;
		}

		ksort( $found, SORT_NUMERIC );

		return $found;
	}

	/**
	 * Run a single migration file through dbDelta().
	 */
	private function apply( string $file ): void {
		global $wpdb;

		$sql = (string) file_get_contents( $file );
		if ( $sql === '' ) {
			return;
		}

		$sql = strtr(
			$sql,
			array(
				'{prefix}'          => $wpdb->prefix,
				'{charset_collate}' => $wpdb->get_charset_collate(),
			)
		);

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	private function default_migrations_dir(): string {
		if ( defined( 'SMAILY_CONNECT_PLUGIN_PATH' ) ) {
			return rtrim( SMAILY_CONNECT_PLUGIN_PATH, '/\\' ) . '/migrations';
		}

		return dirname( __DIR__, 2 ) . '/migrations';
	}
}
