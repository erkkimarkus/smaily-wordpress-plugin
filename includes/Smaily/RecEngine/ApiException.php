<?php
/**
 * Thrown when the rec-engine API returns a non-success response.
 *
 * @package Smaily\Connect\Smaily\RecEngine
 */

declare(strict_types=1);

namespace Smaily\Connect\Smaily\RecEngine;

defined( 'ABSPATH' ) || exit;

use Exception;

/**
 * Surfaces engine-side errors to plugin callers. Mirrors the existing
 * Smaily\Smaily\ApiException pattern (Smaily mailer) so both APIs
 * have a consistent error-handling shape.
 *
 * The contract guarantees a JSON error body (§5):
 *   { error, message, details?, request_id, timestamp }
 *
 * The exception carries the canonical `error` code so callers can
 * branch on it (`api_key_revoked`, `rate_limit_exceeded`, etc.) without
 * re-parsing the body. `request_id` is preserved for admin-notice
 * support-bug-report copy.
 */
final class ApiException extends Exception {

	private string $error_code;
	private ?string $request_id;

	/**
	 * @param array<string, mixed> $body Parsed JSON body from the engine response, if any.
	 */
	public function __construct(
		int $http_status,
		string $error_code,
		string $message,
		array $body = array()
	) {
		parent::__construct( $message, $http_status );
		$this->error_code = $error_code;
		$this->request_id = isset( $body['request_id'] ) ? (string) $body['request_id'] : null;
	}

	public function error_code(): string {
		return $this->error_code;
	}

	public function request_id(): ?string {
		return $this->request_id;
	}
}
