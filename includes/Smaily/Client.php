<?php
/**
 * HTTP client for the Smaily marketing API.
 *
 * @package Smaily\Connect\Smaily
 */

declare(strict_types=1);

namespace Smaily\Connect\Smaily;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- exception messages are captured to the Event Log / returned to admin-only read models, never echoed to a browser; output-escaping does not apply.

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

	/**
	 * The last HTTP exchange — request {method, endpoint, body} + reply
	 * {http, body} — for the Event Log "Details" (F3-44). NEVER holds the
	 * Authorization header. Null until the first request() in this instance.
	 *
	 * @var array<string, mixed>|null
	 */
	private ?array $last_exchange = null;

	public function __construct( string $subdomain, string $username, string $password ) {
		$this->subdomain = $subdomain;
		$this->username  = $username;
		$this->password  = $password;
	}

	/**
	 * The last HTTP exchange this Client made (request body + reply), or null
	 * if it hasn't sent anything. The Smaily Flusher reads this after a dispatch
	 * to record what was sent + what came back (F3-44). Excludes the auth header.
	 *
	 * @return array<string, mixed>|null
	 */
	public function last_exchange(): ?array {
		return $this->last_exchange;
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
	 * GET /api/autoresponder.php — list automation workflows.
	 *
	 * Spec: https://smaily.com/help/api/automations-2/list-automation-workflows/
	 * Returns a JSON array of rows shaped roughly:
	 *   [ { "id": 1, "name": "Welcome series",
	 *       "status": "ACTIVE"|"INACTIVE",
	 *       "sections": [...], "tags": [...],
	 *       "created_at": ..., "activated_at": ... }, … ]
	 *
	 * We pass `status=ACTIVE` so the dropdown only carries automations
	 * the merchant can actually use; legacy mappings that point at an
	 * INACTIVE workflow are surfaced as warnings by the React layer.
	 *
	 * Sub-PR 2.H.14 — previously this method called `workflows.php`
	 * with `trigger_type=form_submitted`, which isn't a real Smaily
	 * route. Smaily returned an empty body, the normaliser fell back
	 * to `#{id}` placeholder names, and Erkki's "#3 / #4" surfaced.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function list_autoresponders(): array {
		$body = $this->request( 'GET', 'autoresponder', array( 'status' => 'ACTIVE' ) );

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
	 * Read a single contact's consent signals back by email (profiling-consent
	 * wiring, (a).0). Smaily returns the contact's fields on a hit, or a
	 * `{code:206, message:"Could not find requested email address"}` status on a
	 * miss (HTTP 200 either way — probe-confirmed against the live API).
	 *
	 * @return array{found: bool, is_unsubscribed: ?string, smaily_rec_profiling: ?string}
	 *         Values come back as STRINGS ("0"/"1") — callers compare as strings.
	 */
	public function get_contact_consent( string $email ): array {
		$body = $this->request( 'GET', 'contact', array( 'email' => $email ) );

		// A status payload ({code, message}) = not-found / error, never a contact.
		if ( ! is_array( $body ) || isset( $body['code'] ) ) {
			return array(
				'found'                => false,
				'is_unsubscribed'      => null,
				'smaily_rec_profiling' => null,
			);
		}

		// The hit is a contact object, or a single-element list of one.
		$contact = isset( $body[0] ) && is_array( $body[0] ) ? $body[0] : $body;

		return array(
			'found'                => true,
			'is_unsubscribed'      => isset( $contact['is_unsubscribed'] ) ? (string) $contact['is_unsubscribed'] : null,
			'smaily_rec_profiling' => isset( $contact['smaily_rec_profiling'] ) ? (string) $contact['smaily_rec_profiling'] : null,
		);
	}

	/**
	 * Write the profiling-consent fields onto a contact via the existing upsert
	 * (the custom fields auto-create on Smaily's side — probe-confirmed). The
	 * boolean drives enforcement; the ISO-8601 timestamp is the Art 7 audit trail.
	 *
	 * @return array<string, mixed> The Smaily response ({code:101} on success).
	 */
	public function write_profiling_consent( string $email, bool $may_profile, string $changed_at ): array {
		return $this->upsert_subscribers(
			array(
				array(
					'email'                   => $email,
					'smaily_rec_profiling'    => $may_profile ? 1 : 0,
					'smaily_rec_profiling_ts' => $changed_at,
				),
			)
		);
	}

	/**
	 * Poll the subscriber action log (`GET /api/history.php`) for events newer
	 * than $since_seq_id. Smaily is pull-only (no webhooks); the caller tracks
	 * the max seq_id as a durable cursor and loops while a full page returns.
	 * Returns ONE page of action rows (each `{seq_id, email, action, time, …}`);
	 * an error/status payload comes back as an empty list.
	 *
	 * @param array<int, string> $actions Action types to filter (optin/optout/delete/complaint/…).
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function get_action_log( int $since_seq_id, array $actions = array(), int $limit = 10000 ): array {
		$params = array(
			'since_seq_id' => $since_seq_id,
			'limit'        => $limit,
		);
		if ( $actions !== array() ) {
			$params['actions'] = array_values( $actions );
		}

		$body = $this->request( 'GET', 'history', $params );

		if ( ! is_array( $body ) || isset( $body['code'] ) ) {
			return array();
		}

		return array_values( array_filter( $body, 'is_array' ) );
	}

	/**
	 * Page the full subscriber list (`GET /api/contact.php?list=1`) — email +
	 * is_unsubscribed only, to keep the pull lean. Used ONLY for the occasional
	 * reconcile re-baseline (onboarding / stale cursor); the standing reconcile
	 * uses the action log. `$offset` is the 0-indexed PAGE; the last page returns
	 * fewer than `$limit` rows.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function list_contacts( int $offset = 0, int $limit = 25000 ): array {
		$body = $this->request(
			'GET',
			'contact',
			array(
				'list'   => 1,
				'fields' => 'email,is_unsubscribed',
				'offset' => $offset,
				'limit'  => $limit,
			)
		);

		if ( ! is_array( $body ) || isset( $body['code'] ) ) {
			return array();
		}

		return array_values( array_filter( $body, 'is_array' ) );
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

		// Record the request for the Event Log (F3-44) — method/endpoint/body
		// only, NEVER the Authorization header. The reply is filled in below.
		$this->last_exchange = array(
			'request'  => array(
				'method'   => $method,
				'endpoint' => $endpoint,
				'body'     => $data,
			),
			'response' => null,
		);

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
			$this->last_exchange['response'] = array( 'error' => $response->get_error_message() );
			throw new ApiException(
				'Smaily HTTP transport error: ' . $response->get_error_message(),
				0
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		$this->last_exchange['response'] = array(
			'http' => $code,
			'body' => $body,
		);

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
