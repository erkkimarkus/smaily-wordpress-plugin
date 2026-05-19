<?php
/**
 * HTTP client for the Smaily marketing API.
 *
 * @package Smaily\Connect\Smaily
 */

declare(strict_types=1);

namespace Smaily\Connect\Smaily;

defined( 'ABSPATH' ) || exit;

use Smaily\Connect\Constants;

/**
 * Thin HTTP wrapper around https://{subdomain}.sendsmaily.net/api/*.
 *
 * The legacy Smaily_Connect\Includes\Smaily_Client class stays in place to
 * serve CF7 / Elementor integrations that already call it. This namespaced
 * Client exists so 2.0 code paths get a typed API with explicit
 * Smaily\Connect\Smaily\ApiException error semantics, dependency-injectable
 * HTTP arguments (for tests), and a single place to layer in the retry /
 * backoff strategy that PLUGIN.md §8 prescribes (4xx no-retry, 429 honour
 * Retry-After, 5xx exponential backoff).
 *
 * The retry logic itself is added in sub-PR 5 alongside the hook layer that
 * drives the calls — here we expose the synchronous request methods so the
 * AutomationRouter and BackfillJob layers have something to call against.
 *
 * Note: deliberately NOT declared final so tests can mock it directly. The
 * collaborator boundaries (AutomationRouter, BackfillJob) already typehint
 * the concrete class, so allowing extension here costs nothing architectural.
 */
class Client {

	private string $subdomain;
	private string $username;
	private string $password;

	public function __construct( string $subdomain, string $username, string $password ) {
		$this->subdomain = $subdomain;
		$this->username  = $username;
		$this->password  = $password;
	}

	/**
	 * Verifies the credentials by hitting the workflows listing endpoint.
	 *
	 * Returns true on any 2xx response (the body content doesn't matter for
	 * a connection check — an empty list is still a valid auth).
	 */
	public function test_connection(): bool {
		try {
			$this->list_autoresponders();
			return true;
		} catch ( ApiException $e ) {
			return false;
		}
	}

	/**
	 * GET /api/workflows.php?trigger_type=form_submitted
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function list_autoresponders(): array {
		$body = $this->request( 'GET', 'workflows', array( 'trigger_type' => 'form_submitted' ) );

		return is_array( $body ) ? $body : array();
	}

	/**
	 * POST /api/autoresponder.php — fires a Smaily workflow.
	 *
	 * @param int                              $workflow_id   Autoresponder ID.
	 * @param array<int, array<string, mixed>> $addresses     Recipient address rows.
	 * @param bool                             $force_opt_in  Whether to bypass double opt-in.
	 *
	 * @return array<string, mixed>
	 */
	public function trigger_automation( int $workflow_id, array $addresses, bool $force_opt_in = true ): array {
		$body = $this->request(
			'POST',
			'autoresponder',
			array(
				'autoresponder' => $workflow_id,
				'addresses'     => $addresses,
				'force_opt_in'  => $force_opt_in,
			)
		);

		return is_array( $body ) ? $body : array();
	}

	/**
	 * POST /api/contact.php — upsert a single subscriber.
	 *
	 * @param array<int, array<string, mixed>> $subscribers
	 *
	 * @return array<string, mixed>
	 */
	public function upsert_subscribers( array $subscribers ): array {
		$body = $this->request( 'POST', 'contact', $subscribers );

		return is_array( $body ) ? $body : array();
	}

	/**
	 * Performs a single HTTP request against the configured Smaily subdomain.
	 *
	 * @param string                                   $method   "GET" or "POST".
	 * @param string                                   $endpoint Smaily endpoint slug,
	 *                                                           e.g. "workflows".
	 * @param array<int|string, mixed>                 $data     Request payload —
	 *                                                           query-string for GET,
	 *                                                           form body for POST.
	 *
	 * @throws ApiException On HTTP transport errors and on non-2xx responses.
	 *
	 * @return mixed Decoded JSON body.
	 */
	private function request( string $method, string $endpoint, array $data ) {
		$url = sprintf( 'https://%s.sendsmaily.net/api/%s.php', $this->subdomain, $endpoint );

		$args = array(
			'headers'    => array(
				'Authorization' => 'Basic ' . base64_encode( $this->username . ':' . $this->password ),
			),
			'user-agent' => $this->user_agent(),
			'timeout'    => 30,
		);

		if ( $method === 'GET' ) {
			$response = wp_remote_get( $url . '?' . http_build_query( $data ), $args );
		} else {
			$args['body'] = $data;
			$response     = wp_remote_post( $url, $args );
		}

		if ( is_wp_error( $response ) ) {
			throw new ApiException(
				'Smaily HTTP transport error: ' . $response->get_error_message(),
				0
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( $code < 200 || $code >= 300 ) {
			throw new ApiException(
				sprintf( 'Smaily API returned HTTP %d for %s %s', $code, $method, $endpoint ),
				$code
			);
		}

		return $body;
	}

	private function user_agent(): string {
		$wp_version = function_exists( 'get_bloginfo' ) ? (string) get_bloginfo( 'version' ) : 'unknown';
		$site_url   = function_exists( 'get_bloginfo' ) ? (string) get_bloginfo( 'url' ) : '';

		return sprintf(
			'%s/%s (WordPress/%s%s)',
			Constants::SLUG,
			Constants::version(),
			$wp_version,
			$site_url !== '' ? '; +' . $site_url : ''
		);
	}
}
