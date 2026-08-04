<?php
/**
 * Exception raised when a Smaily API request fails.
 *
 * @package Smaily\Connect\Smaily
 */

declare(strict_types=1);

namespace Smaily\Connect\Smaily;

defined( 'ABSPATH' ) || exit;

/**
 * Carries the HTTP status code in $code (0 for transport-level errors that
 * never received a response) so callers can branch on the PLUGIN.md §8
 * retry policy: 4xx no-retry, 429 honour Retry-After, 5xx exponential
 * backoff. That branching lives in RetryPolicy; the row-level orchestration
 * (attempt counter, retry parking) lives in EventQueue. This class only
 * carries the two facts both need: the status code and — when Smaily sent a
 * `Retry-After` header — how long it asked us to wait.
 */
final class ApiException extends \RuntimeException {

	/** Seconds Smaily asked the caller to wait, or null when it didn't say. */
	private ?int $retry_after;

	public function __construct( string $message = '', int $code = 0, ?int $retry_after = null ) {
		parent::__construct( $message, $code );

		$this->retry_after = $retry_after;
	}

	public function retry_after(): ?int {
		return $this->retry_after;
	}
}
