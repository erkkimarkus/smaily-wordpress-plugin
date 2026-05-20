<?php
/**
 * Idempotent backfill of existing WordPress users to Smaily as contacts.
 *
 * @package Smaily\Connect\Smaily
 */

declare(strict_types=1);

namespace Smaily\Connect\Smaily;

defined( 'ABSPATH' ) || exit;

/**
 * Walks the WP user table in batches of 100, marking each user with a
 * _smaily_synced_at meta on successful upsert so re-runs only touch
 * users that have never been synced or whose last sync is older than the
 * "freshness window" (default 7 days).
 *
 * Lifecycle (driven by Action Scheduler — sub-PR 5 wires the recurring
 * tick into the Settings "Start backfill" button + the daily contact-sync
 * cron):
 *
 *   1. start() seeds a smly_plus_backfill_job row with total_count and
 *      status='running'.
 *   2. process_batch() handles up to $batch_size users, then either
 *      schedules the next iteration (when the cursor hasn't reached
 *      total_count) or marks status='completed'.
 *   3. Failures bump the row's error_message and leave status='failed'
 *      so the UI surfaces a "Retry" affordance.
 *
 * The Smaily API call itself is delegated to a Client instance supplied
 * via constructor injection so tests don't need wp_remote_post mocks.
 *
 * Not final: REST endpoint + Bootstrap tests subclass with anonymous
 * doubles to short-circuit start() / process_batch() without exercising
 * the WP user-table read path. Same rationale as Smaily\Client.
 */
class BackfillJob {

	public const BACKFILL_TARGET = 'smaily';
	public const BACKFILL_TYPE   = 'contacts';

	public const TABLE_SUFFIX = 'smly_plus_backfill_job';
	public const META_KEY     = '_smaily_synced_at';

	/**
	 * Default freshness window — users synced within this many seconds are
	 * skipped on re-run. 7 days, expressed as a literal so the file is
	 * loadable without WordPress's DAY_IN_SECONDS constant being available
	 * (e.g. from unit tests).
	 */
	private const DEFAULT_FRESHNESS_SECONDS = 7 * 86400;

	private Client $client;

	private int $freshness_seconds;

	private ?SubscriberPayloadBuilder $builder = null;

	public function __construct( Client $client, int $freshness_seconds = self::DEFAULT_FRESHNESS_SECONDS ) {
		$this->client            = $client;
		$this->freshness_seconds = $freshness_seconds;
	}

	/**
	 * Initialise (or reset) the backfill state row.
	 *
	 * @return int The backfill_job row id.
	 */
	public function start(): int {
		global $wpdb;

		$counts = count_users();
		$total  = (int) $counts['total_users'];

		$table = $this->table_name();

		// Diagnostic logging — sub-PR 2.H.6. Erkki's staging shows AS
		// ticks completing with zero work; these lines let us see in
		// debug.log which decision branch fires. Drop these after the
		// pilot stabilises (Phase 4).
		error_log(
			sprintf(
				'[smaily-connect backfill.start] total_users=%d table=%s',
				$total,
				$table
			)
		);

		// Table name interpolation: MySQL forbids parameterising it,
		// $table is composed from controlled values ($wpdb->prefix + a
		// private const). Real arguments are bound via %s / %d placeholders.
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$table} (job_type, target, status, total_count, processed_count, started_at) VALUES (%s, %s, %s, %d, %d, %s) ON DUPLICATE KEY UPDATE status = VALUES(status), total_count = VALUES(total_count), processed_count = 0, cursor_value = NULL, started_at = VALUES(started_at), completed_at = NULL, error_message = NULL",
				self::BACKFILL_TYPE,
				self::BACKFILL_TARGET,
				'running',
				$total,
				0,
				current_time( 'mysql', true )
			)
		);

		$id = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE job_type = %s AND target = %s",
				self::BACKFILL_TYPE,
				self::BACKFILL_TARGET
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared

		$last_error = isset( $wpdb->last_error ) && (string) $wpdb->last_error !== ''
			? (string) $wpdb->last_error
			: '(none)';
		error_log(
			sprintf(
				'[smaily-connect backfill.start] row_id=%d wpdb_last_error=%s',
				$id,
				$last_error
			)
		);

		return $id;
	}

	/**
	 * Process the next chunk of users.
	 *
	 * Picks up where the previous batch left off via the row's cursor field
	 * (stored as the last seen user_id), syncs each user whose
	 * _smaily_synced_at meta is missing or stale, and updates
	 * processed_count + cursor.
	 *
	 * @return array{processed: int, remaining: int, completed: bool}
	 */
	public function process_batch( int $batch_size = 100 ): array {
		global $wpdb;

		$table = $this->table_name();

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
		$state = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, cursor_value, processed_count, total_count FROM {$table} WHERE job_type = %s AND target = %s",
				self::BACKFILL_TYPE,
				self::BACKFILL_TARGET
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared

		if ( ! is_array( $state ) ) {
			error_log( '[smaily-connect backfill.batch] state row missing — start() was never run or insert failed' );
			return array(
				'processed' => 0,
				'remaining' => 0,
				'completed' => true,
			);
		}

		$after  = isset( $state['cursor_value'] ) ? (int) $state['cursor_value'] : 0;
		$users  = $this->fetch_users_after( $after, $batch_size );
		$synced = 0;

		error_log(
			sprintf(
				'[smaily-connect backfill.batch] state_id=%s status=%s processed=%s/%s cursor_after=%d fetched_users=%d',
				isset( $state['id'] ) ? (string) $state['id'] : '?',
				isset( $state['status'] ) ? (string) $state['status'] : '?',
				isset( $state['processed_count'] ) ? (string) $state['processed_count'] : '?',
				isset( $state['total_count'] ) ? (string) $state['total_count'] : '?',
				$after,
				count( $users )
			)
		);

		$fresh_skips = 0;
		foreach ( $users as $user ) {
			if ( $this->is_fresh( (int) $user->ID ) ) {
				++$fresh_skips;
				continue;
			}

			$payload = $this->build_subscriber_payload( $user );
			try {
				$this->client->upsert_subscribers( array( $payload ) );
				update_user_meta( (int) $user->ID, self::META_KEY, time() );
				++$synced;
			} catch ( ApiException $e ) {
				error_log(
					sprintf(
						'[smaily-connect backfill.batch] user_id=%d upsert_failed: %s',
						(int) $user->ID,
						$e->getMessage()
					)
				);
				$this->record_error( (int) $state['id'], $e->getMessage() );
				break;
			}
		}

		error_log(
			sprintf(
				'[smaily-connect backfill.batch] synced=%d fresh_skipped=%d',
				$synced,
				$fresh_skips
			)
		);

		$processed = (int) $state['processed_count'] + count( $users );
		$cursor    = empty( $users ) ? $after : (int) end( $users )->ID;
		$completed = count( $users ) < $batch_size;

		$wpdb->update(
			$table,
			array(
				'processed_count' => $processed,
				'cursor_value'    => (string) $cursor,
				'status'          => $completed ? 'completed' : 'running',
				'completed_at'    => $completed ? current_time( 'mysql', true ) : null,
			),
			array( 'id' => (int) $state['id'] ),
			array( '%d', '%s', '%s', '%s' ),
			array( '%d' )
		);

		return array(
			'processed' => $synced,
			'remaining' => max( 0, (int) $state['total_count'] - $processed ),
			'completed' => $completed,
		);
	}

	/**
	 * @return \WP_User[]
	 */
	private function fetch_users_after( int $after_id, int $limit ): array {
		$users = get_users(
			array(
				'fields'  => 'all',
				'orderby' => 'ID',
				'order'   => 'ASC',
				'number'  => $limit,
				'include' => array(), // safe default; overridden by 'after' equivalent below
			)
		);

		// get_users() doesn't expose a numeric "after id" filter directly; we
		// emulate it by selecting in ID order and pruning client-side. For
		// production-sized installs (PLUGIN.md §15 test #19 — 5000 users) a
		// dedicated SQL query would be faster, but the Phase 1 surface is
		// kept simple here. Sub-PR 7 may revisit when wiring the REST
		// progress endpoint.
		if ( $after_id <= 0 ) {
			return $users;
		}

		return array_values(
			array_filter(
				$users,
				static fn ( \WP_User $u ): bool => (int) $u->ID > $after_id
			)
		);
	}

	private function is_fresh( int $user_id ): bool {
		$synced = (int) get_user_meta( $user_id, self::META_KEY, true );
		if ( $synced <= 0 ) {
			return false;
		}

		return ( time() - $synced ) < $this->freshness_seconds;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function build_subscriber_payload( \WP_User $user ): array {
		return $this->payload_builder()->build( $user );
	}

	private function payload_builder(): SubscriberPayloadBuilder {
		if ( $this->builder === null ) {
			$this->builder = new SubscriberPayloadBuilder();
		}
		return $this->builder;
	}

	private function record_error( int $job_id, string $message ): void {
		global $wpdb;
		$wpdb->update(
			$this->table_name(),
			array(
				'status'        => 'failed',
				'error_message' => $message,
			),
			array( 'id' => $job_id ),
			array( '%s', '%s' ),
			array( '%d' )
		);
	}

	private function table_name(): string {
		global $wpdb;
		return $wpdb->prefix . self::TABLE_SUFFIX;
	}
}
