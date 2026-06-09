<?php
/**
 * Smaily-side durable event queue (backed by smly_plus_event_queue + AS).
 *
 * @package Smaily\Connect\Smaily
 */

declare(strict_types=1);

namespace Smaily\Connect\Smaily;

defined( 'ABSPATH' ) || exit;

/**
 * Persistent queue for events headed to the Smaily marketing API:
 * contact.sync, automation.welcome, automation.first_order,
 * automation.abandoned_cart (PLUGIN.md §9).
 *
 * Storage layout: rows in {prefix}smly_plus_event_queue with status
 * pending → sent (terminal happy path) or pending → failed (terminal
 * after max attempts).
 *
 * Dispatch: enqueue() persists the row immediately and asks Action
 * Scheduler to fire smly_plus_flush_event_queue ASAP, deduplicated via
 * as_next_scheduled_action so multiple enqueues within one PHP request
 * collapse into a single flush job. The flush hook itself (which reads
 * pending rows, calls the appropriate API method, and updates status)
 * is registered by sub-PR 5 once the WC hook layer lands.
 *
 * This class deliberately does NOT call the Smaily API itself. That keeps
 * enqueue() cheap (it's invoked from hot paths like user_register and
 * woocommerce_checkout_order_processed) and concentrates retry policy in
 * one place — the flush job.
 *
 * Not final: tests subclass with an anonymous double to record enqueue()
 * calls without standing up $wpdb + Action Scheduler. Same rationale as
 * Smaily\Client.
 */
class EventQueue {

	public const TABLE_SUFFIX = 'smly_plus_event_queue';

	public const STATUS_PENDING = 'pending';
	public const STATUS_SENT    = 'sent';
	public const STATUS_FAILED  = 'failed';

	public const FLUSH_HOOK = 'smly_plus_flush_event_queue';
	public const AS_GROUP   = 'smaily-connect';

	/**
	 * Persist an event and ensure a flush is scheduled.
	 *
	 * @param string               $event_type e.g. "contact.sync", "automation.welcome".
	 * @param string               $entity_id  Free-form identifier (user_id, order_id, email).
	 * @param array<string, mixed> $payload    JSON-serialisable data the flush job will
	 *                                         hand off to the right API method.
	 *
	 * @return int|null Inserted row id on success, or null if the insert failed.
	 *                  Insert failures are intentionally silent — the caller is
	 *                  usually a hot WP hook and shouldn't bail because the
	 *                  queue is momentarily unreachable.
	 */
	public function enqueue( string $event_type, string $entity_id, array $payload ): ?int {
		global $wpdb;

		$json = wp_json_encode( $payload );
		if ( $json === false ) {
			return null;
		}

		$inserted = $wpdb->insert(
			$this->table_name(),
			array(
				'event_type' => $event_type,
				'entity_id'  => $entity_id,
				'payload'    => $json,
				'created_at' => current_time( 'mysql', true ),
				'attempts'   => 0,
				'status'     => self::STATUS_PENDING,
			),
			array( '%s', '%s', '%s', '%s', '%d', '%s' )
		);

		if ( $inserted !== 1 ) {
			return null;
		}

		$id = (int) $wpdb->insert_id;
		$this->maybe_schedule_flush();

		return $id;
	}

	/**
	 * Fetch the next batch of pending events for processing.
	 *
	 * The flush hook calls this, processes the rows, and uses mark_sent()
	 * / mark_failed() to advance their state. Selecting in created_at
	 * order keeps the queue FIFO so hooks like contact.sync don't end up
	 * arbitrarily reordered relative to subsequent automation.* events
	 * for the same user.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function pending( int $limit = 50 ): array {
		global $wpdb;

		$table = $this->table_name();

		// Table name interpolation is unavoidable — MySQL forbids
		// parameterising the FROM clause. $table is controlled.
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, event_type, entity_id, payload, created_at, attempts FROM {$table} WHERE status = %s ORDER BY created_at ASC LIMIT %d",
				self::STATUS_PENDING,
				$limit
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared

		return is_array( $rows ) ? $rows : array();
	}

	public function mark_sent( int $id ): void {
		global $wpdb;
		$wpdb->update(
			$this->table_name(),
			array( 'status' => self::STATUS_SENT ),
			array( 'id' => $id ),
			array( '%s' ),
			array( '%d' )
		);
	}

	public function mark_failed( int $id, string $error ): void {
		global $wpdb;
		$wpdb->update(
			$this->table_name(),
			array(
				'status'     => self::STATUS_FAILED,
				'last_error' => $error,
			),
			array( 'id' => $id ),
			array( '%s', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Increment attempt counter and persist last error. Used by the flush
	 * job between retries — when attempts reaches the policy ceiling the
	 * caller flips the row to STATUS_FAILED via mark_failed().
	 */
	public function record_attempt( int $id, string $error ): void {
		global $wpdb;

		$table = $this->table_name();

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET attempts = attempts + 1, last_error = %s WHERE id = %d",
				$error,
				$id
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Revive terminally-`failed` rows back to `pending` so the recurring flush
	 * re-attempts them (3.10.1 Event Log recovery). Resets the attempt counter +
	 * last_error. `$ids` null = every failed row; otherwise only the given ids.
	 * Manual-only by design (a deterministic failure would loop under auto-retry).
	 * Returns the row count. This queue has no next_retry_at column.
	 *
	 * @param int[]|null $ids
	 */
	public function reset_failed( ?array $ids = null ): int {
		global $wpdb;
		$table = $this->table_name();

		$set = 'SET status = %s, attempts = 0, last_error = NULL';

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		if ( $ids === null ) {
			$sql = $wpdb->prepare(
				"UPDATE {$table} {$set} WHERE status = %s",
				self::STATUS_PENDING,
				self::STATUS_FAILED
			);
		} else {
			$ids = array_values( array_filter( array_map( 'intval', $ids ), static fn ( int $i ): bool => $i > 0 ) );
			if ( $ids === array() ) {
				return 0;
			}
			$placeholders = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );
			$sql          = $wpdb->prepare(
				"UPDATE {$table} {$set} WHERE status = %s AND id IN ( {$placeholders} )",
				array_merge( array( self::STATUS_PENDING, self::STATUS_FAILED ), $ids )
			);
		}

		return (int) $wpdb->query( $sql );
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	/** Public kick so /events/retry can re-drive promptly after reset_failed(). */
	public function schedule_flush(): void {
		$this->maybe_schedule_flush();
	}

	/**
	 * Ensure an async flush is queued. Deduplicated so multiple enqueues
	 * in one request collapse to a single AS row.
	 */
	private function maybe_schedule_flush(): void {
		if ( ! function_exists( 'as_enqueue_async_action' ) ) {
			return;
		}

		if ( function_exists( 'as_next_scheduled_action' )
			&& as_next_scheduled_action( self::FLUSH_HOOK, array(), self::AS_GROUP ) !== false
		) {
			return;
		}

		as_enqueue_async_action( self::FLUSH_HOOK, array(), self::AS_GROUP );
	}

	private function table_name(): string {
		global $wpdb;
		return $wpdb->prefix . self::TABLE_SUFFIX;
	}
}
