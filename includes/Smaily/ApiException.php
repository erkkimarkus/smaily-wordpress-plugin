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
 * backoff. The retry orchestration itself lives in EventQueue, not here.
 */
final class ApiException extends \RuntimeException {
}
