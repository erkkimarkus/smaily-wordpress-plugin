<?php
/**
 * Immutable result returned by WorkflowResolverInterface.
 *
 * @package Smaily\Connect\Smaily
 */

declare(strict_types=1);

namespace Smaily\Connect\Smaily;

defined( 'ABSPATH' ) || exit;

/**
 * Pairs the Smaily workflow id (the autoresponder to fire) with the
 * account_key identifying which credential set should drive the request.
 *
 * In Mode B/C account_key is always "default" — there's only one Smaily
 * account so the credential lookup is trivial. Mode A introduces several
 * account_keys (one per language); AutomationRouter must use the matched
 * key when constructing the Client instance for the call.
 */
final class WorkflowMatch {

	public function __construct(
		public readonly int $workflow_id,
		public readonly string $account_key,
		public readonly ?string $matched_language = null
	) {}
}
