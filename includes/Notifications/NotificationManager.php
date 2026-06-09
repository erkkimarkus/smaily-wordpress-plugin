<?php
/**
 * Proactive health notifications (PLUGIN.md §13a) — Layer 3 base (3.10.2).
 *
 * A recurring health-check surfaces sync trouble as a WP admin notice so a pilot
 * operator learns "something broke" without watching the Event Log. This is the
 * admin-notice level only — the email level (§13a) is 3.10.3, post-pilot.
 *
 * @package Smaily\Connect\Notifications
 */

declare(strict_types=1);

namespace Smaily\Connect\Notifications;

defined( 'ABSPATH' ) || exit;

use Smaily\Connect\Constants;
use Smaily\Connect\Settings\RecEngineSettings;
use Smaily\Connect\Smaily\Client as SmailyClient;
use Smaily\Connect\Smaily\EventQueue;
use Smaily\Connect\Smaily\RecEngine\Client as RecEngineClient;
use Smaily\Connect\Smaily\RecEngine\IngestQueue;

/**
 * Two `error`-severity signals (§13a), both from the health-check cron:
 *   - failed events > threshold in 24h (across both durable queues);
 *   - rec-engine unreachable for > 1h (time-based, via a periodic ping).
 *
 * Each maps to a sticky-until-resolved admin notice: the health-check sets it
 * while the condition holds and clears it the moment the condition resolves
 * (a successful ping / the failed count dropping back under threshold). To stay
 * non-spammy the notice is dismissible with a 24h cooldown — a plain nonce'd
 * admin-post link, no per-page nag, no JS.
 */
final class NotificationManager {

	public const HEALTH_HOOK = 'smly_plus_health_check';
	public const AS_GROUP    = 'smaily-connect';

	/** Default "too many failures" threshold (§13a: >50 in 24h); filterable. */
	public const FAILED_THRESHOLD_DEFAULT = 50;

	/** How long the engine must be unreachable before we notify (§13a: >1h). */
	public const ENGINE_DOWN_GRACE = HOUR_IN_SECONDS;

	/** Dismiss cooldown — re-show a still-active notice after this (§13a's 24h window). */
	public const DISMISS_COOLDOWN = DAY_IN_SECONDS;

	public const OPTION_NOTICES           = 'smly_active_notices';
	public const OPTION_DOWN_SINCE        = 'smly_rec_health_down_since';
	public const OPTION_SMAILY_DOWN_SINCE = 'smly_smaily_health_down_since';
	public const OPTION_DISMISSED         = 'smly_notice_dismissed';

	public const DISMISS_ACTION = 'smly_dismiss_notice';

	private RecEngineSettings $settings;

	/** @var callable():RecEngineClient */
	private $client_factory;

	/** @var callable():?SmailyClient */
	private $smaily_client_factory;

	/**
	 * @param callable():RecEngineClient $client_factory Lazily builds a connected
	 *        rec-engine client (so we don't construct one when disconnected).
	 * @param callable():?SmailyClient   $smaily_client_factory Builds the default
	 *        Smaily client, or null when the email side isn't configured yet
	 *        (so an un-set-up store isn't reported as "Smaily down").
	 */
	public function __construct(
		RecEngineSettings $settings,
		callable $client_factory,
		callable $smaily_client_factory
	) {
		$this->settings              = $settings;
		$this->client_factory        = $client_factory;
		$this->smaily_client_factory = $smaily_client_factory;
	}

	public function register(): void {
		add_action( self::HEALTH_HOOK, array( $this, 'run_health_check' ) );
		add_action( 'admin_notices', array( $this, 'render' ) );
		add_action( 'admin_post_' . self::DISMISS_ACTION, array( $this, 'handle_dismiss' ) );
	}

	// --- the health check (cron callback) ----------------------------------

	/**
	 * Recompute both signals and persist the active-notice set. Called by the
	 * recurring Action Scheduler tick (Bootstrap schedules HEALTH_HOOK).
	 */
	public function run_health_check(): void {
		$now               = time();
		$failed            = $this->failed_last_24h();
		$down_since        = $this->probe_engine( $now );
		$smaily_down_since = $this->probe_smaily( $now );

		$notices = $this->evaluate_signals(
			$failed,
			$down_since,
			$now,
			$this->failed_threshold(),
			$smaily_down_since
		);

		update_option( self::OPTION_NOTICES, $notices, false );
	}

	/**
	 * Pure signal evaluation — given the inputs, return the active-notice map.
	 * Split out so the threshold + grace logic is unit-testable without a DB,
	 * network, or clock. Covers the pilot's BOTH sync paths: the rec-engine
	 * (`engine_down`) and Smaily email (`smaily_down`).
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function evaluate_signals(
		int $failed_24h,
		?int $down_since,
		int $now,
		int $threshold,
		?int $smaily_down_since = null
	): array {
		$notices = array();

		if ( $failed_24h > $threshold ) {
			$notices['failed_events'] = array(
				'severity' => 'error',
				'count'    => $failed_24h,
				'since'    => $now,
			);
		}

		if ( $down_since !== null && ( $now - $down_since ) >= self::ENGINE_DOWN_GRACE ) {
			$notices['engine_down'] = array(
				'severity'   => 'error',
				'down_since' => $down_since,
			);
		}

		if ( $smaily_down_since !== null && ( $now - $smaily_down_since ) >= self::ENGINE_DOWN_GRACE ) {
			$notices['smaily_down'] = array(
				'severity'   => 'error',
				'down_since' => $smaily_down_since,
			);
		}

		return $notices;
	}

	/**
	 * Ping the engine and maintain the persisted `down_since` stamp: cleared on a
	 * successful ping (or when disconnected — no engine to be "down"), set to now
	 * on the first failed ping, kept across subsequent failures. Returns the
	 * current down_since (or null when up / disconnected).
	 */
	private function probe_engine( int $now ): ?int {
		if ( ! $this->settings->is_connected() ) {
			delete_option( self::OPTION_DOWN_SINCE );
			return null;
		}

		$up = true;
		try {
			( $this->client_factory )()->ping();
		} catch ( \Throwable $e ) {
			$up = false;
		}

		if ( $up ) {
			delete_option( self::OPTION_DOWN_SINCE );
			return null;
		}

		$down_since = (int) get_option( self::OPTION_DOWN_SINCE, 0 );
		if ( $down_since <= 0 ) {
			$down_since = $now;
			update_option( self::OPTION_DOWN_SINCE, $down_since, false );
		}

		return $down_since;
	}

	/**
	 * Same down_since contract as probe_engine(), but for the Smaily email API —
	 * the pilot's OTHER sync path (contacts + welcome/abandoned-cart automations).
	 * The factory returns null when the email side isn't configured (setup wizard
	 * not finished), so an un-set-up store is never reported as "Smaily down".
	 */
	private function probe_smaily( int $now ): ?int {
		$client = ( $this->smaily_client_factory )();
		if ( ! $client instanceof SmailyClient ) {
			delete_option( self::OPTION_SMAILY_DOWN_SINCE );
			return null;
		}

		$up = false;
		try {
			$up = $client->test_connection();
		} catch ( \Throwable $e ) {
			$up = false;
		}

		if ( $up ) {
			delete_option( self::OPTION_SMAILY_DOWN_SINCE );
			return null;
		}

		$down_since = (int) get_option( self::OPTION_SMAILY_DOWN_SINCE, 0 );
		if ( $down_since <= 0 ) {
			$down_since = $now;
			update_option( self::OPTION_SMAILY_DOWN_SINCE, $down_since, false );
		}

		return $down_since;
	}

	/** Failed rows across both durable queues in the last 24h. */
	private function failed_last_24h(): int {
		global $wpdb;

		$rec    = $wpdb->prefix . IngestQueue::TABLE_SUFFIX;
		$smaily = $wpdb->prefix . EventQueue::TABLE_SUFFIX;
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT
					( SELECT COUNT(*) FROM {$rec} WHERE status = 'failed' AND created_at >= %s )
					+ ( SELECT COUNT(*) FROM {$smaily} WHERE status = 'failed' AND created_at >= %s )",
				$cutoff,
				$cutoff
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	private function failed_threshold(): int {
		return (int) apply_filters( 'smaily_connect_failed_notice_threshold', self::FAILED_THRESHOLD_DEFAULT );
	}

	// --- rendering + dismissal ---------------------------------------------

	/**
	 * admin_notices callback. Renders each active, not-currently-dismissed notice
	 * as a sticky `notice-error` with a "View Event Log" link + a Dismiss link.
	 */
	public function render(): void {
		if ( ! current_user_can( Constants::CAPABILITY ) ) {
			return;
		}

		$notices   = $this->active_notices();
		$dismissed = (array) get_option( self::OPTION_DISMISSED, array() );
		$now       = time();

		foreach ( $notices as $key => $notice ) {
			$dismissed_at = isset( $dismissed[ $key ] ) ? (int) $dismissed[ $key ] : 0;
			if ( $dismissed_at > 0 && ( $now - $dismissed_at ) < self::DISMISS_COOLDOWN ) {
				continue;
			}

			printf(
				'<div class="notice notice-error"><p>%1$s %2$s %3$s</p></div>',
				esc_html( $this->message_for( $key, $notice ) ),
				wp_kses_post( $this->event_log_link() ),
				wp_kses_post( $this->dismiss_link( $key ) )
			);
		}
	}

	/**
	 * @return array<string, array<string, mixed>>
	 */
	public function active_notices(): array {
		$notices = get_option( self::OPTION_NOTICES, array() );
		return is_array( $notices ) ? $notices : array();
	}

	/**
	 * admin_post handler for the Dismiss link. Stamps the dismissal (24h cooldown)
	 * and bounces back to the referring admin page.
	 */
	public function handle_dismiss(): void {
		$key = isset( $_GET['key'] ) ? sanitize_key( wp_unslash( $_GET['key'] ) ) : '';

		if ( ! current_user_can( Constants::CAPABILITY )
			|| ! isset( $_GET['_wpnonce'] )
			|| ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), self::DISMISS_ACTION . '_' . $key )
		) {
			wp_die( esc_html__( 'Invalid request.', 'smaily-connect' ), '', array( 'response' => 403 ) );
		}

		$this->dismiss( $key );

		$back = wp_get_referer();
		wp_safe_redirect( $back !== false ? $back : admin_url() );
		exit;
	}

	public function dismiss( string $key ): void {
		if ( $key === '' ) {
			return;
		}
		$dismissed         = (array) get_option( self::OPTION_DISMISSED, array() );
		$dismissed[ $key ] = time();
		update_option( self::OPTION_DISMISSED, $dismissed, false );
	}

	/** @param array<string, mixed> $notice */
	private function message_for( string $key, array $notice ): string {
		if ( $key === 'failed_events' ) {
			$count = isset( $notice['count'] ) ? (int) $notice['count'] : 0;
			return sprintf(
				/* translators: %d: number of failed sync events in the last 24 hours. */
				_n(
					'Smaily Connect: %d sync event failed in the last 24 hours.',
					'Smaily Connect: %d sync events failed in the last 24 hours.',
					$count,
					'smaily-connect'
				),
				$count
			);
		}

		if ( $key === 'engine_down' ) {
			return __(
				'Smaily Connect: the recommendation engine has been unreachable for over an hour — sync is queued and will resume automatically when it recovers.',
				'smaily-connect'
			);
		}

		if ( $key === 'smaily_down' ) {
			return __(
				'Smaily Connect: the Smaily API has been unreachable for over an hour — contact sync and email automations are paused until the connection recovers.',
				'smaily-connect'
			);
		}

		return __( 'Smaily Connect: a sync health check needs your attention.', 'smaily-connect' );
	}

	private function event_log_link(): string {
		return sprintf(
			'<a href="%s">%s</a>',
			esc_url( admin_url( 'admin.php?page=smaily-connect-settings#events' ) ),
			esc_html__( 'View Event Log', 'smaily-connect' )
		);
	}

	private function dismiss_link( string $key ): string {
		$url = wp_nonce_url(
			add_query_arg(
				array(
					'action' => self::DISMISS_ACTION,
					'key'    => $key,
				),
				admin_url( 'admin-post.php' )
			),
			self::DISMISS_ACTION . '_' . $key
		);

		return sprintf(
			'<a href="%s">%s</a>',
			esc_url( $url ),
			esc_html__( 'Dismiss', 'smaily-connect' )
		);
	}
}
