<?php
/**
 * Server-side capture of Smaily recommendation-attribution URL params.
 *
 * @package Smaily\Connect\Integrations\WooCommerce
 */

declare(strict_types=1);

namespace Smaily\Connect\Integrations\WooCommerce;

defined( 'ABSPATH' ) || exit;

use Smaily\Connect\Settings\RecEngineSettings;
use Smaily\Connect\Support\DebugLog;

/**
 * Captures the attribution signals an email recommendation link carries
 * (`smaily_rec` / `smaily_vt` / `smaily_ctx`, RECENGINE_API_CONTRACT.md
 * §"Cookie names") into the plugin's first-party cookies on the storefront
 * landing — SERVER-SIDE, so attribution works even when the JS browse-beacon
 * is disabled, ad-blocked, or the visitor hasn't decided cookie consent yet.
 *
 * Why this is needed even though StorefrontBeacon already captures the same
 * params client-side: the beacon only loads when browse-tracking is enabled AND
 * marketing consent is granted AND `sc-runtime.js` isn't ad-blocked.
 * Recommendation ATTRIBUTION must not depend on any of those — the rec link
 * already landed on the merchant's own product page, the rec_id is right there
 * in the URL, and nothing read it (pilot: 374 orders / 0 `smaily_rec_id`). This
 * class is the missing producer of that cookie.
 *
 * The cookies written here are exactly the ones
 * HookHandler::save_attribution_cookies_to_order() already stamps onto the
 * order at checkout (`smaily_rec_id` → `_smaily_rec_id`, `smaily_rec_uid` →
 * `_smaily_visitor_token`, `smaily_rec_ctx` → `_smaily_rec_ctx`), which
 * OrderPayloadBuilder forwards to the engine. So this class is the ONLY new
 * piece — the stamp → send chain downstream is untouched.
 *
 * Contract vs the engine brief (2026-06-26): the byte-synced contract sources
 * the rec_id from the engine's own `smaily_rec` URL param into the
 * `smaily_rec_id` cookie. The link ALSO carries `utm_content=<rec_id>`
 * (url-builder.ts); that is honoured only as a fallback and only when
 * `utm_source=smaily`, because `utm_content` is a shared marketing param
 * (Google Ads / GA) and must not credit a non-Smaily campaign.
 *
 * Consent (Erkki, 2026-06-26): recommendation attribution is a first-party
 * functional signal captured UNCONDITIONALLY when the engine is connected — a
 * rec_id (uuid) and a visitor token (opaque) are not personal data on their
 * own, and tying engine-side recommendations to real purchases is the whole
 * point. Browse telemetry (Layer 2) stays separately gated behind the
 * browse-tracking toggle + marketing consent (StorefrontBeacon). The
 * `smaily_connect_capture_attribution` filter is the merchant escape-hatch.
 *
 * Not final: `send_cookie()` is a seam tests override to assert captured values
 * without emitting real headers — same testability rationale as the other
 * handlers.
 */
class LandingCapture {

	/** URL params the engine link carries (re: lib/sync/url-builder.ts). */
	private const URL_PARAM_REC_ID  = 'smaily_rec';
	private const URL_PARAM_VISITOR = 'smaily_vt';
	private const URL_PARAM_CONTEXT = 'smaily_ctx';

	/** The generic UTM the engine ALSO sets (utm_content = rec_id) + its source guard. */
	private const UTM_CONTENT       = 'utm_content';
	private const UTM_SOURCE        = 'utm_source';
	private const UTM_SOURCE_SMAILY = 'smaily';

	/** Capture slots — each maps to a cookie name + TTL via the engine config. */
	private const SLOT_REC_ID  = 'rec_id';
	private const SLOT_VISITOR = 'visitor';
	private const SLOT_CONTEXT = 'context';

	private RecEngineSettings $settings;

	public function __construct( RecEngineSettings $settings ) {
		$this->settings = $settings;
	}

	public function register(): void {
		// template_redirect: the front-end main query only (never admin / REST /
		// cron), and it fires before any template output, so cookies can still be
		// set as headers.
		add_action( 'template_redirect', array( $this, 'capture' ) );
	}

	/**
	 * Read the landing URL and, when it carries Smaily attribution params, write
	 * them to the first-party cookies the checkout stamping reads.
	 */
	public function capture(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- public email-link landing (read-only GET); every value is unslashed, sanitised and format-validated in resolve(); there is no form submission to nonce.
		$get = $_GET;

		// Fast path: do nothing on the overwhelming majority of requests, which
		// carry no attribution param at all.
		if ( ! $this->has_trigger_param( $get ) ) {
			return;
		}

		/**
		 * Master switch for server-side recommendation-attribution capture.
		 * Return false to disable it entirely (e.g. a merchant whose consent
		 * policy requires gating these functional cookies).
		 *
		 * @param bool $enabled Default true.
		 */
		if ( ! (bool) apply_filters( 'smaily_connect_capture_attribution', true ) ) {
			DebugLog::write( '[smaily-connect landing-capture] skipped: disabled by smaily_connect_capture_attribution filter' );
			return;
		}

		// Only meaningful when the engine is connected: rec links exist only for a
		// connected tenant, and orders are only ingested then.
		if ( ! $this->settings->is_connected() ) {
			DebugLog::write( '[smaily-connect landing-capture] bailed: rec-engine not connected (is_connected=false) — connect the engine in Settings → Campaign Intelligence' );
			return;
		}

		// Cookies are response headers — once output has started we cannot set them.
		if ( $this->headers_already_sent() ) {
			DebugLog::write( '[smaily-connect landing-capture] bailed: headers already sent (a theme/plugin printed output before template_redirect)' );
			return;
		}

		$captures = $this->resolve( $get );
		if ( array() === $captures ) {
			DebugLog::write( '[smaily-connect landing-capture] bailed: a trigger param was present but no valid attribution value resolved (param shape mismatch?)' );
			return;
		}

		$config = $this->settings->config();
		foreach ( $captures as $slot => $value ) {
			$this->set_cookie(
				$this->cookie_name( $config, (string) $slot ),
				(string) $value,
				$this->cookie_ttl_days( $config, (string) $slot )
			);
		}

		// The single success line: which signals were captured (no values — the
		// rec_id is a uuid, but keep the log shape-only). Visible under WP_DEBUG.
		DebugLog::write( sprintf( '[smaily-connect landing-capture] captured: %s', implode( ',', array_keys( $captures ) ) ) );
	}

	/**
	 * Map a raw GET array to the attribution values worth persisting, keyed by
	 * capture slot. Pure + side-effect-free so it can be unit-tested without
	 * touching cookies/headers. An absent or malformed value is simply omitted
	 * (last-touch overwrite only happens for values that are actually present).
	 *
	 * @param array<string, mixed> $get Raw request query params.
	 * @return array<string, string> slot => value.
	 */
	public function resolve( array $get ): array {
		$out = array();

		$rec_id = $this->rec_id_from( $get );
		if ( '' !== $rec_id ) {
			$out[ self::SLOT_REC_ID ] = $rec_id;
		}

		$visitor = $this->clean( $get, self::URL_PARAM_VISITOR );
		if ( $this->is_visitor_token( $visitor ) ) {
			$out[ self::SLOT_VISITOR ] = $visitor;
		}

		$context = $this->clean( $get, self::URL_PARAM_CONTEXT );
		if ( $this->is_context( $context ) ) {
			$out[ self::SLOT_CONTEXT ] = $context;
		}

		return $out;
	}

	/**
	 * The rec_id, preferring the engine's namespaced `smaily_rec` param and
	 * falling back to `utm_content` ONLY when `utm_source=smaily`.
	 *
	 * @param array<string, mixed> $get
	 */
	private function rec_id_from( array $get ): string {
		// Primary: the engine's own param (contract §"Cookie names"). Authoritative
		// and unambiguous, so it takes any bounded id token.
		$rec = $this->clean( $get, self::URL_PARAM_REC_ID );
		if ( $this->is_rec_id( $rec ) ) {
			return $rec;
		}

		// Fallback: utm_content carries the same rec_id, but it is a shared
		// marketing param (Google Ads / GA), so require the source guard + a
		// strict uuid shape before crediting it.
		if ( self::UTM_SOURCE_SMAILY === strtolower( $this->clean( $get, self::UTM_SOURCE ) ) ) {
			$utm = $this->clean( $get, self::UTM_CONTENT );
			if ( $this->is_uuid( $utm ) ) {
				return $utm;
			}
		}

		return '';
	}

	/**
	 * @param array<string, mixed> $get
	 */
	private function has_trigger_param( array $get ): bool {
		return isset( $get[ self::URL_PARAM_REC_ID ] )
			|| isset( $get[ self::URL_PARAM_VISITOR ] )
			|| isset( $get[ self::UTM_CONTENT ] );
	}

	/**
	 * @param array<string, mixed> $get
	 */
	private function clean( array $get, string $key ): string {
		if ( ! isset( $get[ $key ] ) || ! is_scalar( $get[ $key ] ) ) {
			return '';
		}
		return trim( sanitize_text_field( wp_unslash( (string) $get[ $key ] ) ) );
	}

	/**
	 * `smaily_rec` is the engine's authoritative param: accept any bounded
	 * id-token (a uuid today, but don't hard-fail if the engine's rec_id shape
	 * ever changes). The same-customer check engine-side is the real safety net.
	 */
	private function is_rec_id( string $value ): bool {
		return '' !== $value && 1 === preg_match( '/^[A-Za-z0-9._-]{1,64}$/', $value );
	}

	private function is_uuid( string $value ): bool {
		return 1 === preg_match(
			'/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/',
			$value
		);
	}

	/** Engine visitor-token format: `vt_` + alphanumerics (re: visitor-tokens/manager.ts). */
	private function is_visitor_token( string $value ): bool {
		return 1 === preg_match( '/^vt_[A-Za-z0-9]{1,64}$/', $value );
	}

	/** Intent slug (welcome / cart_abandoned / cross_sell / …). */
	private function is_context( string $value ): bool {
		return '' !== $value && 1 === preg_match( '/^[A-Za-z0-9._-]{1,64}$/', $value );
	}

	/**
	 * @param array<string, mixed> $config
	 */
	private function cookie_name( array $config, string $slot ): string {
		switch ( $slot ) {
			case self::SLOT_VISITOR:
				return $this->config_string( $config, 'tracking_cookie_name', 'smaily_rec_uid' );
			case self::SLOT_CONTEXT:
				return $this->config_string( $config, 'context_cookie_name', 'smaily_rec_ctx' );
			case self::SLOT_REC_ID:
			default:
				return $this->config_string( $config, 'rec_id_cookie_name', 'smaily_rec_id' );
		}
	}

	/**
	 * @param array<string, mixed> $config
	 */
	private function cookie_ttl_days( array $config, string $slot ): int {
		switch ( $slot ) {
			case self::SLOT_VISITOR:
				return $this->config_int( $config, 'cookie_ttl_days', 365 );
			case self::SLOT_CONTEXT:
				return $this->config_int( $config, 'context_ttl_days', 30 );
			case self::SLOT_REC_ID:
			default:
				return $this->config_int( $config, 'rec_id_ttl_days', 30 );
		}
	}

	private function set_cookie( string $name, string $value, int $ttl_days ): void {
		if ( '' === $name ) {
			return;
		}
		$expires = time() + ( $ttl_days * 86400 );
		$this->send_cookie( $name, $value, $expires );
		// Make the value observable within this same request too (the checkout
		// reads it on a later request; this just keeps $_COOKIE coherent).
		$_COOKIE[ $name ] = $value;
	}

	/**
	 * The actual header write. Isolated so tests can capture the values without
	 * emitting real Set-Cookie headers. Attributes mirror the contract
	 * §"Cookie names" table + the JS capture: SameSite=Lax, Secure on https,
	 * HttpOnly=false (the beacon proxy reads these cookies client-side).
	 */
	/**
	 * Whether the response headers have already been sent (cookies can't be set
	 * after that). A seam so tests can exercise the write path — PHPUnit's own
	 * progress output makes the bare headers_sent() true mid-suite, which never
	 * happens on a real template_redirect before any template output.
	 */
	protected function headers_already_sent(): bool {
		return headers_sent();
	}

	protected function send_cookie( string $name, string $value, int $expires ): void {
		setcookie(
			$name,
			$value,
			array(
				'expires'  => $expires,
				'path'     => ( defined( 'COOKIEPATH' ) && '' !== COOKIEPATH ) ? COOKIEPATH : '/',
				'domain'   => defined( 'COOKIE_DOMAIN' ) ? (string) COOKIE_DOMAIN : '',
				'secure'   => is_ssl(),
				'httponly' => false,
				'samesite' => 'Lax',
			)
		);
	}

	/**
	 * @param array<string, mixed> $config
	 */
	private function config_string( array $config, string $key, string $default ): string {
		$value = isset( $config[ $key ] ) ? (string) $config[ $key ] : '';
		return '' !== $value ? $value : $default;
	}

	/**
	 * @param array<string, mixed> $config
	 */
	private function config_int( array $config, string $key, int $default ): int {
		return isset( $config[ $key ] ) ? (int) $config[ $key ] : $default;
	}
}
