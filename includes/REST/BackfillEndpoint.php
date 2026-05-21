<?php
/**
 * REST endpoints driving the backfill lifecycle.
 *
 * @package Smaily\Connect\REST
 */

declare(strict_types=1);

namespace Smaily\Connect\REST;

defined( 'ABSPATH' ) || exit;

use Smaily\Connect\Constants;
use Smaily\Connect\Smaily\BackfillJob;
use Smaily\Connect\Smaily\EventQueue;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Three sub-routes under `/wp-json/smaily-connect/v1/backfill/...`:
 *
 *   POST   /backfill/start   body: {"job_type": "contacts"}
 *                            → {"job_id": int, "status": "running", "total": int}
 *
 *   GET    /backfill/status  query: ?job_type=contacts
 *                            → {"status": "running|completed|failed|idle",
 *                               "processed": int, "total": int,
 *                               "percent": int, "eta_seconds": int|null}
 *
 *   POST   /backfill/cancel  body: {"job_type": "contacts"}
 *                            → {"cancelled": bool}
 *
 * Auth: nonce (wp_rest) + manage_options capability on all three.
 *
 * Job types accepted:
 *   - "contacts" — Smaily-side user backfill (Faas 1)
 *   - Phase 3 adds "orders", "customers", "products" against the rec-engine.
 *     The endpoint surface here is stable; only the JOB_TYPE_HANDLERS map
 *     grows. Cancelling an "orders" job in Phase 3 will hit the same route.
 */
class BackfillEndpoint {

	public const ROUTE_PREFIX = '/backfill';
	public const TABLE_SUFFIX = 'smly_plus_backfill_job';

	public const STATUS_RUNNING   = 'running';
	public const STATUS_COMPLETED = 'completed';
	public const STATUS_FAILED    = 'failed';
	public const STATUS_CANCELLED = 'cancelled';

	/** Hook the AS tick scheduler fires once /backfill/start enqueues it. */
	public const TICK_HOOK = 'smly_plus_backfill_tick';

	/**
	 * Job types this endpoint accepts. Phase 1 has one; Phase 3 will extend
	 * this constant when rec-engine backfill lands.
	 *
	 * @var string[]
	 */
	private const SUPPORTED_JOB_TYPES = array( 'contacts' );

	/**
	 * Factory that constructs a BackfillJob on demand. Lazy because the
	 * Smaily Client dependency requires credentials, which the merchant
	 * may not have configured yet — we still need the route registered
	 * so the UI doesn't 404 before they finish Step 1 of the wizard.
	 * Returning null from the factory yields a 503 response so the
	 * caller surfaces "Smaily not connected" instead of an exception.
	 *
	 * @var callable(): ?BackfillJob
	 */
	private $job_factory;

	/**
	 * @param callable(): ?BackfillJob $job_factory Factory producing a
	 *   BackfillJob at request time, or null when credentials are absent.
	 */
	public function __construct( callable $job_factory ) {
		$this->job_factory = $job_factory;
	}

	public function register(): void {
		register_rest_route(
			Constants::REST_NAMESPACE,
			self::ROUTE_PREFIX . '/start',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'start' ),
				'permission_callback' => array( $this, 'permission_check' ),
				'args'                => $this->job_type_arg(),
			)
		);

		register_rest_route(
			Constants::REST_NAMESPACE,
			self::ROUTE_PREFIX . '/status',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'status' ),
				'permission_callback' => array( $this, 'permission_check' ),
				'args'                => $this->job_type_arg(),
			)
		);

		register_rest_route(
			Constants::REST_NAMESPACE,
			self::ROUTE_PREFIX . '/cancel',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'cancel' ),
				'permission_callback' => array( $this, 'permission_check' ),
				'args'                => $this->job_type_arg(),
			)
		);
	}

	/**
	 * @return bool|WP_Error
	 */
	public function permission_check( WP_REST_Request $request ) {
		if ( ! current_user_can( Constants::CAPABILITY ) ) {
			return new WP_Error(
				'smaily_connect_forbidden',
				__( 'You do not have permission to manage backfill jobs.', 'smaily-connect' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	public function start( WP_REST_Request $request ): WP_REST_Response {
		// Diagnostic — sub-PR 2.H.6.
		error_log(
			sprintf(
				'[smaily-connect backfill.endpoint.start] requested job_type=%s',
				(string) $request->get_param( 'job_type' )
			)
		);

		$job_type = $this->resolve_job_type( $request );
		if ( $job_type === null ) {
			return $this->unsupported_job_type_response();
		}

		$job = ( $this->job_factory )();
		if ( ! $job instanceof BackfillJob ) {
			error_log( '[smaily-connect backfill.endpoint.start] factory returned null — credentials missing' );
			return new WP_REST_Response(
				array(
					'error'   => 'smaily_not_configured',
					'message' => __(
						'Smaily credentials are not configured. Finish Step 1 of the setup wizard first.',
						'smaily-connect'
					),
				),
				503
			);
		}

		$job_id = $job->start();
		error_log( sprintf( '[smaily-connect backfill.endpoint.start] start() returned row_id=%d', $job_id ) );

		// Schedule the first AS tick so backfill processing begins
		// immediately. The tick handler reschedules itself until the job
		// reaches its terminal state.
		if ( function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action(
				self::TICK_HOOK,
				array( 'job_type' => $job_type ),
				EventQueue::AS_GROUP
			);
		}

		$row = $this->read_state( $job_type );

		return new WP_REST_Response(
			array(
				'job_id' => $job_id,
				'status' => is_array( $row ) ? (string) $row['status'] : self::STATUS_RUNNING,
				'total'  => is_array( $row ) ? (int) $row['total_count'] : 0,
			),
			200
		);
	}

	public function status( WP_REST_Request $request ): WP_REST_Response {
		$job_type = $this->resolve_job_type( $request );
		if ( $job_type === null ) {
			return $this->unsupported_job_type_response();
		}

		$row = $this->read_state( $job_type );
		if ( $row === null ) {
			return new WP_REST_Response(
				array(
					'status'       => 'idle',
					'processed'    => 0,
					'total'        => 0,
					'percent'      => 0,
					'eta_seconds'  => null,
					'started_at'   => null,
					'completed_at' => null,
				),
				200
			);
		}

		$processed = (int) $row['processed_count'];
		$total     = (int) $row['total_count'];
		$percent   = $total > 0 ? (int) round( ( $processed / $total ) * 100 ) : 0;

		// Surface started_at + completed_at so the React UI can render
		// "Last run: 2026-05-21 10:42, 142 / 142" between runs. Previously
		// the only way to see a completed backfill was to be on the page
		// when it finished — reload blanked the panel because the boot
		// payload didn't include this state.
		return new WP_REST_Response(
			array(
				'status'       => (string) $row['status'],
				'processed'    => $processed,
				'total'        => $total,
				'percent'      => min( 100, max( 0, $percent ) ),
				'eta_seconds'  => $this->estimate_eta( $row, $processed, $total ),
				'started_at'   => isset( $row['started_at'] ) ? (string) $row['started_at'] : null,
				'completed_at' => isset( $row['completed_at'] ) ? (string) $row['completed_at'] : null,
			),
			200
		);
	}

	public function cancel( WP_REST_Request $request ): WP_REST_Response {
		$job_type = $this->resolve_job_type( $request );
		if ( $job_type === null ) {
			return $this->unsupported_job_type_response();
		}

		global $wpdb;
		$table   = $wpdb->prefix . self::TABLE_SUFFIX;
		$updated = $wpdb->update(
			$table,
			array(
				'status'       => self::STATUS_CANCELLED,
				'completed_at' => current_time( 'mysql', true ),
			),
			array(
				'job_type' => $job_type,
				'target'   => BackfillJob::BACKFILL_TARGET,
			),
			array( '%s', '%s' ),
			array( '%s', '%s' )
		);

		// Drop any pending AS ticks so process_batch doesn't undo the cancel.
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions(
				self::TICK_HOOK,
				array( 'job_type' => $job_type ),
				EventQueue::AS_GROUP
			);
		}

		return new WP_REST_Response(
			array( 'cancelled' => $updated !== false && $updated > 0 ),
			200
		);
	}

	/**
	 * @return array{job_type: array<string, mixed>}
	 */
	private function job_type_arg(): array {
		return array(
			'job_type' => array(
				'type'              => 'string',
				'required'          => true,
				'sanitize_callback' => 'sanitize_key',
			),
		);
	}

	private function resolve_job_type( WP_REST_Request $request ): ?string {
		$value = (string) $request->get_param( 'job_type' );
		if ( ! in_array( $value, self::SUPPORTED_JOB_TYPES, true ) ) {
			return null;
		}

		return $value;
	}

	private function unsupported_job_type_response(): WP_REST_Response {
		return new WP_REST_Response(
			array(
				'error'           => __( 'Unsupported job_type.', 'smaily-connect' ),
				'supported_types' => self::SUPPORTED_JOB_TYPES,
			),
			400
		);
	}

	/**
	 * @return array<string, mixed>|null
	 */
	private function read_state( string $job_type ): ?array {
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE_SUFFIX;

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, status, processed_count, total_count, started_at, completed_at FROM {$table} WHERE job_type = %s AND target = %s",
				$job_type,
				BackfillJob::BACKFILL_TARGET
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared
		// phpcs:enable WordPress.DB.DirectDatabaseQuery

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Rough ETA: assumes a steady processing rate of (processed) /
	 * (seconds since start). Returns null when there's no progress yet
	 * or when the job is already in a terminal state.
	 *
	 * @param array<string, mixed> $row
	 */
	private function estimate_eta( array $row, int $processed, int $total ): ?int {
		if ( $processed === 0 || $total === 0 || $processed >= $total ) {
			return null;
		}

		$started_at = isset( $row['started_at'] ) ? (string) $row['started_at'] : '';
		if ( $started_at === '' ) {
			return null;
		}

		$elapsed = max( 1, time() - (int) strtotime( $started_at . ' UTC' ) );
		$rate    = $processed / $elapsed; // items per second
		if ( $rate <= 0 ) {
			return null;
		}

		return (int) ceil( ( $total - $processed ) / $rate );
	}
}
