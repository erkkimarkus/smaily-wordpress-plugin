<?php
/**
 * HTTP client for the Smaily Recommendation Engine REST API.
 *
 * @package Smaily\Connect\Smaily\RecEngine
 */

declare(strict_types=1);

namespace Smaily\Connect\Smaily\RecEngine;

defined( 'ABSPATH' ) || exit;

/**
 * Authenticated calls to https://<engine_base_url>/api/v1/... using
 * the Bearer api_key the merchant exchanged during setup.
 *
 * Scope in sub-PR 3.1:
 *   - Constructor + private request() helper with retry policy.
 *   - ping() — GET /api/v1/ingest/ping (the only feature method).
 *
 * Scaffold methods (ingest_catalog, ingest_customers, ingest_orders,
 * ingest_browse, identity_merge, customer_export, customer_delete,
 * customer_opt_out) throw a NotImplementedException so callers see a
 * clear "added later" message. Sub-PRs 3.2+ replace the throw with
 * the real wp_remote_post body for each.
 *
 * Retry policy (RECENGINE_API_CONTRACT.md §6):
 *   - 429 + Retry-After header → respect the header, exponential
 *     backoff (1s, 2s, 4s, 8s, 16s), max 5 attempts.
 *   - 5xx (500/502/503/504) → same backoff, max 5 attempts.
 *   - 4xx (non-429) → no retry, throw ApiException with the body's
 *     error_code so the caller can branch on `api_key_revoked` etc.
 *
 * Version handling (contract §3): every response carries
 * `X-Engine-Version`. The client warns (error_log) on a major-version
 * jump beyond what the plugin supports but does NOT refuse to work —
 * graceful degradation is explicit in the contract.
 *
 * Non-final: Bootstrap-injection tests double the class through
 * subclassing the request() method, the same pattern Smaily\Client
 * uses.
 */
class Client {

	/**
	 * Plugin's supported engine major version range. Bumps land in
	 * the same commit that handles a breaking-change migration.
	 */
	public const SUPPORTED_MAJOR = 1;

	// ---------------------------------------------------------------
	// Path constants — every URL the plugin sends to the engine flows
	// through these. Single source of truth: sub-PR 3.1.2 split out
	// after an integration bug where SetupExchange called
	// /setup/exchange (wrong) while Client::ping happened to call
	// /api/v1/ingest/ping (right). Different call sites, no shared
	// definition, drift.
	//
	// Engine actually serves SETUP_EXCHANGE under /api/setup/exchange
	// (the /api prefix is consistent across the entire route
	// surface). Spec doc updated in the same commit to match.
	//
	// Where the engine returns its own endpoints map in the
	// setup-exchange response (RECENGINE_API_CONTRACT.md §7.1), Faas
	// 3.2's ingest path should prefer that map; these constants are
	// the fallback / type-safety anchor.
	// ---------------------------------------------------------------
	public const PATH_SETUP_EXCHANGE       = '/api/setup/exchange';
	public const PATH_PING                 = '/api/v1/ingest/ping';
	public const PATH_INGEST_CATALOG       = '/api/v1/ingest/catalog';
	public const PATH_INGEST_CUSTOMERS     = '/api/v1/ingest/customers';
	public const PATH_INGEST_ORDERS        = '/api/v1/ingest/orders';
	public const PATH_INGEST_BROWSE        = '/api/v1/ingest/browse';
	public const PATH_IDENTITY_MERGE       = '/api/v1/identity/merge';
	public const PATH_CUSTOMER_EXPORT_FMT  = '/api/v1/customer/%s/export';
	public const PATH_CUSTOMER_DELETE_FMT  = '/api/v1/customer/%s';
	public const PATH_CUSTOMER_OPT_OUT_FMT = '/api/v1/customer/%s/opt-out';

	/** Default in-request retry ceiling — generous for one-shot calls (ping, setup). */
	public const DEFAULT_MAX_ATTEMPTS = 5;

	private string $api_key;
	private string $base_url;

	/** @var array<string, string> Engine-returned endpoint URL map (keys like "ingest_catalog"). */
	private array $endpoints;

	private int $max_attempts;

	/**
	 * @param string                $api_key      Bearer key, e.g. "sk_8f3k2a...".
	 * @param string                $base_url     Engine origin, e.g. "https://re-example.vercel.app".
	 * @param array<string, string> $endpoints    RecEngineSettings::endpoints() — the engine's own
	 *                                            URL map (source of truth for paths). Empty when a
	 *                                            caller only needs base_url + PATH_* constants (ping).
	 * @param int                   $max_attempts In-request retry ceiling. The flush job passes a
	 *                                            small value (1-2): a long sleep-backoff would block
	 *                                            the Action Scheduler worker, and the durable queue
	 *                                            already retries at the row level via next_retry_at.
	 */
	public function __construct( string $api_key, string $base_url, array $endpoints = array(), int $max_attempts = self::DEFAULT_MAX_ATTEMPTS ) {
		$this->api_key      = $api_key;
		$this->base_url     = rtrim( $base_url, '/' );
		$this->endpoints    = $endpoints;
		$this->max_attempts = max( 1, $max_attempts );
	}

	/**
	 * Health-check the configured tenant. Used by the Settings UI
	 * "Test connection" button.
	 *
	 * Return shape per contract §7.2:
	 *   ok, tenant_id, tenant_name, engine_version, tenant_status,
	 *   endpoints_available, server_time.
	 *
	 * Declared as array<string, mixed> rather than a strict shape so
	 * the caller can defensively isset() each field — the engine
	 * version could change the response shape under minor-bump rules
	 * and we want graceful degradation, not phpstan exceptions.
	 *
	 * @return array<string, mixed>
	 *
	 * @throws ApiException On 4xx (non-429) or unrecoverable network failure.
	 */
	public function ping(): array {
		return $this->request( 'GET', self::PATH_PING );
	}

	// ---------------------------------------------------------------
	// Scaffold methods — Faas 3.2+ fills these in.
	// ---------------------------------------------------------------

	/**
	 * Batch-upsert catalog products. The engine UPSERTs per SKU and
	 * deduplicates per-product on (tenant_id, event_id), so a row resent
	 * by a flush-job retry — same products[].event_id — comes back 200
	 * {"deduplicated": true} rather than double-applied. That body is a
	 * SUCCESS to the caller (the flush job marks the row sent, never
	 * retries); request() only throws on real 4xx/5xx failures.
	 *
	 * URL comes from the engine's endpoints map (PATH preferred from the
	 * map's "ingest_catalog" key — full absolute URL), falling back to
	 * base_url + PATH_INGEST_CATALOG when the map isn't available.
	 *
	 * Wire wrapper key is `products` (§5). History is a flip-flop: the doc
	 * originally said `products`; the 3.2.4 live probe found the deployed
	 * engine then wanted `items` (we switched); W2 (engine `b5b1295`) renamed
	 * it back to `products` — a clean break, an `items`-wrapped payload now
	 * 400s `validation_failed` (fieldErrors: products Required). The W2 sync
	 * updated the doc but NOT this code; the mock (still enforcing `items`)
	 * hid the drift until the N-7.1 catalog live-walk — the first catalog
	 * live-request after W2 — caught it (LESSONS §2.6, the sync-vs-code gap).
	 *
	 * @param array<int, array<string, mixed>> $products
	 *
	 * @return array<string, mixed>
	 *
	 * @throws ApiException On 4xx (non-429) or unrecoverable network failure.
	 */
	public function ingest_catalog( array $products ): array {
		$url = $this->resolve_url( 'ingest_catalog', self::PATH_INGEST_CATALOG );
		return $this->request_url( 'POST', $url, array( 'products' => $products ) );
	}

	/**
	 * Batch-upload customers to POST /api/v1/ingest/customers.
	 *
	 * Wrapper key is `customers` (W4 §4 email-first contract), live-verified
	 * against the deployed engine in the 3.3.1 wrapper probe: a single
	 * {customers:[...]} item returned 200 processed:1 — no products→items-style
	 * surprise like catalog had (LESSONS §2.4). Identity is email; the engine
	 * UPSERTs on (tenant_id, email), case-insensitive.
	 *
	 * Per-item D6 partial success: a 2xx body carries
	 * {ok, processed, deduplicated, errors:[{index, email?, field, message}]};
	 * the customer flusher (3.3.2) reads errors[] from this return value and
	 * splits the batch. A wrapper-level failure (non-array / empty / >100) is a
	 * 400 and throws ApiException carrying the body's `details` (F3-18).
	 *
	 * @param array<int, array<string, mixed>> $customers 1..100 customer objects.
	 *
	 * @return array<string, mixed> The decoded engine response (D6 shape).
	 *
	 * @throws ApiException On 4xx (non-429) or unrecoverable network failure.
	 */
	public function ingest_customers( array $customers ): array {
		$url = $this->resolve_url( 'ingest_customers', self::PATH_INGEST_CUSTOMERS );
		return $this->request_url( 'POST', $url, array( 'customers' => $customers ) );
	}

	/**
	 * Batch-upload orders to POST /api/v1/ingest/orders.
	 *
	 * Wrapper key is `orders` (W5 §5); the batch cap is **50** orders per
	 * request (lower than the 100 for catalog/customers — orders carry nested
	 * line items and are heavier). Order natural key is
	 * `(tenant_id, external_order_id)`; the customer is referenced by
	 * `customer_email` and auto-created engine-side. Items are fully replaced
	 * on re-ingest of an existing order.
	 *
	 * Per-item D6 partial success: a 2xx body carries
	 * {ok, processed, deduplicated, errors:[{index, external_order_id?, field,
	 * message}]}; the order flusher (3.3-orders.2) splits the batch from
	 * errors[]. Attribution is async — the response carries no attribution
	 * counts. A wrapper-level failure (non-array / empty / >50) is a 400 and
	 * throws ApiException carrying the body's `details` (F3-18).
	 *
	 * @param array<int, array<string, mixed>> $orders 1..50 order objects.
	 *
	 * @return array<string, mixed> The decoded engine response (D6 shape).
	 *
	 * @throws ApiException On 4xx (non-429) or unrecoverable network failure.
	 */
	public function ingest_orders( array $orders ): array {
		$url = $this->resolve_url( 'ingest_orders', self::PATH_INGEST_ORDERS );
		return $this->request_url( 'POST', $url, array( 'orders' => $orders ) );
	}

	/**
	 * @param array<int, array<string, mixed>> $events
	 *
	 * @return array<string, mixed>
	 */
	public function ingest_browse( array $events ): array {
		throw new \RuntimeException( 'ingest_browse: not implemented in sub-PR 3.1 (lands in 3.4).' );
	}

	// ---------------------------------------------------------------
	// Private request engine.
	// ---------------------------------------------------------------

	/**
	 * Resolve a wire endpoint URL. The engine-returned endpoints map is the
	 * source of truth — its values are full absolute URLs keyed like
	 * "ingest_catalog" (the engine's own naming; the plugin once read the
	 * unprefixed "catalog" and got a null URL, the 3.1.2-class bug). The
	 * PATH_* constant is the fallback for the rare pre-setup-exchange call
	 * where no map is stored. A relative map value (legacy shape) is
	 * defensively re-based on base_url.
	 */
	private function resolve_url( string $endpoint_key, string $fallback_path ): string {
		$mapped = isset( $this->endpoints[ $endpoint_key ] ) ? trim( (string) $this->endpoints[ $endpoint_key ] ) : '';
		if ( $mapped !== '' ) {
			if ( preg_match( '#^https?://#i', $mapped ) === 1 ) {
				return $mapped;
			}
			return $this->base_url . '/' . ltrim( $mapped, '/' );
		}
		return $this->base_url . $fallback_path;
	}

	/**
	 * Issue a request against a path relative to base_url. Thin wrapper over
	 * request_url() for the constant-path callers (ping).
	 *
	 * @param array<string, mixed>|null $body Request body for non-GET methods.
	 *
	 * @return array<string, mixed>
	 *
	 * @throws ApiException
	 */
	protected function request( string $method, string $path, ?array $body = null ): array {
		return $this->request_url( $method, $this->base_url . $path, $body );
	}

	/**
	 * Issue a request against a fully-resolved URL, with the retry policy.
	 *
	 * @param array<string, mixed>|null $body Request body for non-GET methods.
	 *
	 * @return array<string, mixed>
	 *
	 * @throws ApiException
	 */
	protected function request_url( string $method, string $url, ?array $body = null ): array {
		$attempts = 0;
		$backoff  = 1;

		while ( true ) {
			++$attempts;

			$args = array(
				'method'  => $method,
				'timeout' => 15,
				'headers' => array(
					'Authorization' => 'Bearer ' . $this->api_key,
					'User-Agent'    => sprintf(
						'SmailyConnect-WooPlugin/%s',
						defined( 'SMAILY_CONNECT_VERSION' ) ? (string) SMAILY_CONNECT_VERSION : '0.0.0'
					),
				),
			);
			if ( $body !== null ) {
				$args['headers']['Content-Type'] = 'application/json';
				$args['body']                    = (string) wp_json_encode( $body );
			}

			$response = wp_remote_request( $url, $args );
			if ( is_wp_error( $response ) ) {
				if ( $attempts >= $this->max_attempts ) {
					throw new ApiException(
						0,
						'network_error',
						sprintf( 'Engine unreachable after %d attempts: %s', $attempts, $response->get_error_message() )
					);
				}
				$this->sleep_with_backoff( $backoff );
				$backoff *= 2;
				continue;
			}

			$status   = (int) wp_remote_retrieve_response_code( $response );
			$raw_body = (string) wp_remote_retrieve_body( $response );
			$decoded  = json_decode( $raw_body, true );
			$decoded  = is_array( $decoded ) ? $decoded : array();

			$this->check_engine_version( wp_remote_retrieve_header( $response, 'x-engine-version' ) );

			if ( $status >= 200 && $status < 300 ) {
				return $decoded;
			}

			$is_retryable = ( $status === 429 || ( $status >= 500 && $status < 600 ) );
			if ( $is_retryable && $attempts < $this->max_attempts ) {
				$retry_after = (int) wp_remote_retrieve_header( $response, 'retry-after' );
				$sleep_for   = $retry_after > 0 ? $retry_after : $backoff;
				$this->sleep_with_backoff( $sleep_for );
				$backoff *= 2;
				continue;
			}

			$error_code = isset( $decoded['error'] ) ? (string) $decoded['error'] : 'http_' . $status;
			$message    = isset( $decoded['message'] ) ? (string) $decoded['message'] : sprintf( 'Engine returned HTTP %d', $status );
			throw new ApiException( $status, $error_code, $message, $decoded );
		}
	}

	/**
	 * Engine version compatibility check. Contract §3 instructs us to
	 * NEVER refuse on version mismatch — data loss is a bigger risk
	 * than compatibility issues — so we log and continue. A future
	 * sub-PR can surface admin notices when the gap is too wide.
	 *
	 * @param string|mixed $header_value
	 */
	private function check_engine_version( $header_value ): void {
		$version = is_string( $header_value ) ? trim( $header_value ) : '';
		if ( $version === '' ) {
			return;
		}
		if ( ! preg_match( '/^(\d+)\./', $version, $m ) ) {
			return;
		}
		$major = (int) $m[1];
		if ( $major > self::SUPPORTED_MAJOR ) {
			error_log(
				sprintf(
					'[smaily-connect rec-engine] Engine version %s is beyond plugin support range (<=%d.x). Continuing in graceful-degradation mode.',
					$version,
					self::SUPPORTED_MAJOR
				)
			);
		}
	}

	/**
	 * Real sleep in production; protected so tests can subclass and
	 * fast-forward.
	 */
	protected function sleep_with_backoff( int $seconds ): void {
		if ( $seconds > 0 ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_sleep
			sleep( $seconds );
		}
	}
}
