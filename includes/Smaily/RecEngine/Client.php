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

	private string $api_key;
	private string $base_url;

	/**
	 * @param string $api_key  Bearer key, e.g. "sk_8f3k2a...".
	 * @param string $base_url Engine origin, e.g. "https://re-example.vercel.app".
	 */
	public function __construct( string $api_key, string $base_url ) {
		$this->api_key  = $api_key;
		$this->base_url = rtrim( $base_url, '/' );
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
		return $this->request( 'GET', '/api/v1/ingest/ping' );
	}

	// ---------------------------------------------------------------
	// Scaffold methods — Faas 3.2+ fills these in.
	// ---------------------------------------------------------------

	/**
	 * @param array<int, array<string, mixed>> $products
	 *
	 * @return array<string, mixed>
	 */
	public function ingest_catalog( array $products ): array {
		throw new \RuntimeException( 'ingest_catalog: not implemented in sub-PR 3.1 (lands in 3.2).' );
	}

	/**
	 * @param array<int, array<string, mixed>> $customers
	 *
	 * @return array<string, mixed>
	 */
	public function ingest_customers( array $customers ): array {
		throw new \RuntimeException( 'ingest_customers: not implemented in sub-PR 3.1 (lands in 3.2).' );
	}

	/**
	 * @param array<int, array<string, mixed>> $orders
	 *
	 * @return array<string, mixed>
	 */
	public function ingest_orders( array $orders ): array {
		throw new \RuntimeException( 'ingest_orders: not implemented in sub-PR 3.1 (lands in 3.2).' );
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
	 * @param array<string, mixed>|null $body Request body for non-GET methods.
	 *
	 * @return array<string, mixed>
	 *
	 * @throws ApiException
	 */
	protected function request( string $method, string $path, ?array $body = null ): array {
		$url      = $this->base_url . $path;
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
				if ( $attempts >= 5 ) {
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
			if ( $is_retryable && $attempts < 5 ) {
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
