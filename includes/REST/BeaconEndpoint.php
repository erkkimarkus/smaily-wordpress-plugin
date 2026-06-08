<?php
/**
 * Public browse-beacon proxy: POST /wp-json/smaily-connect/v1/beacon.
 *
 * @package Smaily\Connect\REST
 */

declare(strict_types=1);

namespace Smaily\Connect\REST;

defined( 'ABSPATH' ) || exit;

use Smaily\Connect\Constants;
use Smaily\Connect\Settings\RecEngineSettings;
use Smaily\Connect\Smaily\RecEngine\ApiException;
use Smaily\Connect\Smaily\RecEngine\Client;
use WP_REST_Request;
use WP_REST_Response;

/**
 * The ONE public, unauthenticated route in the plugin.
 *
 * Why public: browse events come from anonymous storefront visitors, so the
 * route cannot be `manage_options`-gated like the rest of the rec-engine
 * surface. The contract (§ "API key must never appear in client-side code — a
 * plugin-side server proxy is required for browse events") mandates this proxy:
 * the browser POSTs same-origin to `/beacon`, the plugin decrypts the
 * tenant api_key server-side and forwards to the engine's `/api/v1/ingest/browse`.
 * The api_key never reaches the browser.
 *
 * Because it is public AND spends the tenant's api_key + engine quota on every
 * call, an unprotected `/beacon` is a real attack surface (spam → polluted
 * engine data + cost on the tenant's account). The abuse model (3.4.0) is part
 * of this endpoint, not a later add-on:
 *
 *   1. HARD GATE (404 when off) — the handler's FIRST act is is_enabled()
 *      (connected engine AND browse-tracking on). Disabled ⇒ an immediate 404
 *      before any work: no api_key decrypt, no engine call, no rate-limit
 *      transient, no validation. To a client a disabled `/beacon` is
 *      indistinguishable from an unregistered route. (The route itself is
 *      registered unconditionally — an earlier design registered it
 *      conditionally, but proving "absent when disabled" needs a fresh
 *      WP_REST_Server, and re-firing rest_api_init mid-suite to rebuild it
 *      segfaults wp-env. Gating in the handler is the same attack surface — a
 *      bare 404 doing zero work — and is testable with a plain dispatch.)
 *   2. RATE LIMIT — per-IP AND per-session (the anon-session cookie). IP alone
 *      collapses behind NAT/mobile; the session counter complements it. Fixed
 *      60s windows via transients.
 *   3. SERVER-SIDE VALIDATION — before forwarding: event_type must be one of
 *      the 9 §6 types, every event must carry an event_id, the batch is capped
 *      at 100, and each event is field-whitelisted to the §6 shape. Our own
 *      JS client never produces an invalid type or an id-less event, so a
 *      violation signals tampering ⇒ hard 400, nothing forwarded.
 *
 * Browse does NOT use the IngestQueue/Flusher pattern (catalog/customers/orders
 * do): it is client-buffered, best-effort telemetry forwarded synchronously
 * (the Client's own layered retry covers transient engine blips). A lost batch
 * is acceptable; durable delivery is not warranted for telemetry.
 *
 * The Client is injected via a closure (like RecEngineEndpoint) so integration
 * tests can stand up a mock engine without monkey-patching wp_remote_post.
 */
class BeaconEndpoint {

	public const ROUTE = '/beacon';

	/** Option flag (master browse gate) written by SettingsEndpoint. */
	public const OPTION_TRACK_BROWSING = 'smly_plus_rec_track_browsing';

	/** Max events per batch (mirrors the engine wrapper cap, §6 / config batch_size_max). */
	public const MAX_EVENTS = 100;

	/** Rate-limit fixed window, seconds. */
	public const RL_WINDOW_SECONDS = 60;

	/** Default per-session request ceiling per window (filterable). */
	public const RL_MAX_PER_SESSION = 30;

	/** Default per-IP request ceiling per window (filterable). */
	public const RL_MAX_PER_IP = 120;

	/**
	 * The full §6 event_type enum — exactly these 9. Any other value is junk
	 * our client never emits ⇒ the whole request is rejected.
	 *
	 * @var array<int, string>
	 */
	public const EVENT_TYPES = array(
		'product_view',
		'category_view',
		'search',
		'cart_add',
		'cart_remove',
		'wishlist_add',
		'wishlist_remove',
		'checkout_start',
		'checkout_complete',
	);

	/**
	 * The §6 per-event field whitelist. Anything outside this set is dropped
	 * before forwarding (caps payload bloat + blocks key injection).
	 *
	 * @var array<int, string>
	 */
	private const EVENT_FIELDS = array(
		'event_id',
		'session_id',
		'event_type',
		'sku',
		'category_path',
		'search_query',
		'dwell_seconds',
		'event_ts',
		'source',
		'customer_email',
		'smaily_visitor_token',
		'smaily_rec_id',
		'smaily_ctx',
		'external_id',
	);

	private RecEngineSettings $settings;

	/** @var callable(string $api_key, string $base_url): Client */
	private $client_factory;

	/**
	 * @param callable(string $api_key, string $base_url): Client $client_factory
	 */
	public function __construct( RecEngineSettings $settings, callable $client_factory ) {
		$this->settings       = $settings;
		$this->client_factory = $client_factory;
	}

	/**
	 * Registered unconditionally (so it is in EndpointRegistry::expected_routes
	 * and testable with a plain dispatch). The gate lives in the handler, which
	 * 404s immediately when disabled — see the class docblock for why this beats
	 * conditional registration.
	 */
	public function register(): void {
		register_rest_route(
			Constants::REST_NAMESPACE,
			self::ROUTE,
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * The gate: connected engine + browse-tracking enabled.
	 */
	public function is_enabled(): bool {
		return $this->settings->is_connected() && (bool) get_option( self::OPTION_TRACK_BROWSING, false );
	}

	public function handle( WP_REST_Request $request ): WP_REST_Response {
		// HARD GATE — disabled ⇒ a bare 404, indistinguishable from a route
		// that isn't there, BEFORE any work (no api_key, no engine, no rate
		// limit, no validation).
		if ( ! $this->is_enabled() ) {
			return new WP_REST_Response(
				array(
					'ok'    => false,
					'error' => 'not_found',
				),
				404
			);
		}

		$session = $this->session_id();

		if ( $this->rate_limited( $this->client_ip(), $session ) ) {
			return new WP_REST_Response(
				array(
					'ok'    => false,
					'error' => 'rate_limited',
				),
				429
			);
		}

		$raw = $request->get_param( 'events' );
		if ( ! is_array( $raw ) ) {
			$raw = array();
		}

		$validation = self::validate_batch( $raw );
		if ( ! $validation['valid'] ) {
			return new WP_REST_Response(
				array(
					'ok'      => false,
					'error'   => 'invalid_events',
					'field'   => $validation['field'],
					'message' => $validation['message'],
				),
				400
			);
		}

		$api_key  = $this->settings->api_key();
		$base_url = $this->settings->base_url();
		if ( $api_key === '' || $base_url === '' ) {
			return new WP_REST_Response(
				array(
					'ok'    => false,
					'error' => 'configuration_incomplete',
				),
				503
			);
		}

		$client = ( $this->client_factory )( $api_key, $base_url );
		try {
			$result = $client->ingest_browse( $validation['events'] );
		} catch ( ApiException $e ) {
			return new WP_REST_Response(
				array(
					'ok'      => false,
					'error'   => $e->error_code(),
					'message' => $e->getMessage(),
				),
				502
			);
		}

		// Pass the engine's D6 body straight back (it never contains the
		// api_key); the client logs counts and otherwise fires-and-forgets.
		return new WP_REST_Response(
			array(
				'ok'           => isset( $result['ok'] ) ? (bool) $result['ok'] : true,
				'processed'    => isset( $result['processed'] ) ? (int) $result['processed'] : 0,
				'deduplicated' => isset( $result['deduplicated'] ) ? (int) $result['deduplicated'] : 0,
				'errors'       => isset( $result['errors'] ) && is_array( $result['errors'] ) ? $result['errors'] : array(),
			),
			200
		);
	}

	/**
	 * Validate + sanitize a raw events batch. PURE (no WP calls) so it is
	 * unit-testable without a WordPress bootstrap.
	 *
	 * Rules (the abuse filter): non-empty, ≤ MAX_EVENTS, every event is an
	 * array with a non-empty string event_id and an event_type in the §6
	 * allowlist. On the first violation the whole batch is rejected (our own
	 * client never produces one). Valid events are field-whitelisted to the
	 * §6 shape before forwarding.
	 *
	 * @param array<int|string, mixed> $events
	 *
	 * @return array{valid: bool, field: string, message: string, events: array<int, array<string, mixed>>}
	 */
	public static function validate_batch( array $events ): array {
		if ( $events === array() ) {
			return self::invalid( 'events', 'No events in the batch.' );
		}
		if ( count( $events ) > self::MAX_EVENTS ) {
			return self::invalid( 'events', 'Batch exceeds the 100-event cap.' );
		}

		$clean = array();
		foreach ( $events as $event ) {
			if ( ! is_array( $event ) ) {
				return self::invalid( 'events', 'Each event must be an object.' );
			}

			$event_id = isset( $event['event_id'] ) ? (string) $event['event_id'] : '';
			if ( trim( $event_id ) === '' ) {
				return self::invalid( 'event_id', 'Every event needs an event_id (browse has no natural key).' );
			}

			$event_type = isset( $event['event_type'] ) ? (string) $event['event_type'] : '';
			if ( ! in_array( $event_type, self::EVENT_TYPES, true ) ) {
				return self::invalid( 'event_type', 'Unknown event_type.' );
			}

			$row = array();
			foreach ( self::EVENT_FIELDS as $field ) {
				if ( array_key_exists( $field, $event ) ) {
					$row[ $field ] = $event[ $field ];
				}
			}
			$clean[] = $row;
		}

		return array(
			'valid'   => true,
			'field'   => '',
			'message' => '',
			'events'  => $clean,
		);
	}

	/**
	 * @return array{valid: bool, field: string, message: string, events: array<int, array<string, mixed>>}
	 */
	private static function invalid( string $field, string $message ): array {
		return array(
			'valid'   => false,
			'field'   => $field,
			'message' => $message,
			'events'  => array(),
		);
	}

	/**
	 * Fixed-window per-IP + per-session throttle via transients. Approximate
	 * (transients aren't atomic) — fine for rate-limiting. Returns true when
	 * EITHER counter is over its ceiling for the current window.
	 */
	private function rate_limited( string $ip, string $session ): bool {
		$over = false;

		if ( $ip !== '' ) {
			$max = (int) apply_filters( 'smaily_connect_beacon_rate_limit_ip', self::RL_MAX_PER_IP );
			if ( $this->bump( 'smly_beacon_rl_ip_' . md5( $ip ), $max ) ) {
				$over = true;
			}
		}

		if ( $session !== '' ) {
			$max = (int) apply_filters( 'smaily_connect_beacon_rate_limit_session', self::RL_MAX_PER_SESSION );
			if ( $this->bump( 'smly_beacon_rl_s_' . md5( $session ), $max ) ) {
				$over = true;
			}
		}

		return $over;
	}

	/**
	 * Increment a window counter; return true once it exceeds $max.
	 */
	private function bump( string $key, int $max ): bool {
		$count = (int) get_transient( $key );
		++$count;
		set_transient( $key, $count, self::RL_WINDOW_SECONDS );
		return $count > $max;
	}

	/**
	 * REMOTE_ADDR only — X-Forwarded-For is attacker-spoofable, so trusting it
	 * would let one client masquerade as many IPs and defeat the throttle.
	 */
	private function client_ip(): string {
		if ( ! isset( $_SERVER['REMOTE_ADDR'] ) ) {
			return '';
		}
		$raw = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) );
		$ip  = filter_var( $raw, FILTER_VALIDATE_IP );
		return is_string( $ip ) ? $ip : '';
	}

	/**
	 * The anonymous-session cookie value (name comes from the engine config,
	 * default smaily_anon_sid). Empty before the client sets it on first visit.
	 */
	private function session_id(): string {
		$config = $this->settings->config();
		$name   = isset( $config['session_cookie_name'] ) ? (string) $config['session_cookie_name'] : 'smaily_anon_sid';
		if ( ! isset( $_COOKIE[ $name ] ) ) {
			return '';
		}
		return sanitize_text_field( wp_unslash( (string) $_COOKIE[ $name ] ) );
	}
}
