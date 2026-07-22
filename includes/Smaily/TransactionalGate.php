<?php
/**
 * Full gate check for a transactional-email send (PRO-1504 Stage 2).
 *
 * @package Smaily\Connect\Smaily
 */

declare(strict_types=1);

namespace Smaily\Connect\Smaily;

defined( 'ABSPATH' ) || exit;

use Smaily\Connect\Settings\Credentials;

/**
 * Single source of truth for "is a transactional send allowed to happen for
 * this trigger right now" — reused by both the WC hook handler (decides
 * whether to attempt a send) and the native-WC-email suppression filters
 * (design point 6: suppression is active ONLY while this SAME gate holds).
 * Duplicating the four conditions in two places would let them drift.
 *
 * Deliberately NO consent/opt-out gate (platform answer Q7, PRO-1380):
 * transactional sends override marketing opt-out, so this never touches
 * ProfilingConsent or any marketing-consent check.
 *
 * All four conditions must hold:
 *   1. the transactional-emails enablement toggle is on;
 *   2. that trigger's own toggle is on;
 *   3. a mapping row exists for the trigger (account_key='transactional',
 *      language='default' — this account has no per-language variant);
 *   4. the mapped account's credentials resolve (subdomain+username+password
 *      all set).
 *
 * Not final: tests inject resolver/credentials doubles.
 */
class TransactionalGate {

	public const TRIGGER_ORDER_CONFIRMATION    = 'order_confirmation';
	public const TRIGGER_SHIPPING_CONFIRMATION = 'shipping_confirmation';

	private const OPTION_ENABLED               = 'smly_plus_transactional_emails_enabled';
	private const OPTION_ORDER_CONFIRMATION    = 'smly_plus_order_confirmation_enabled';
	private const OPTION_SHIPPING_CONFIRMATION = 'smly_plus_shipping_confirmation_enabled';

	private Credentials $credentials;
	private WorkflowResolverInterface $resolver;

	public function __construct( Credentials $credentials, WorkflowResolverInterface $resolver ) {
		$this->credentials = $credentials;
		$this->resolver    = $resolver;
	}

	/**
	 * Returns the matched workflow when every gate condition holds for
	 * $trigger_type, or null when any one of them doesn't — the caller
	 * treats null as "do nothing" (no send, no suppression).
	 */
	public function resolve_if_open( string $trigger_type ): ?WorkflowMatch {
		if ( ! (bool) get_option( self::OPTION_ENABLED, false ) ) {
			return null;
		}

		if ( ! (bool) get_option( $this->trigger_toggle_option( $trigger_type ), false ) ) {
			return null;
		}

		$match = $this->resolver->resolve_workflow( $trigger_type, null );
		if ( $match === null ) {
			return null;
		}

		$set = $this->credentials->get( $match->account_key );
		if ( $set === null || ! $set->is_complete() ) {
			return null;
		}

		return $match;
	}

	private function trigger_toggle_option( string $trigger_type ): string {
		if ( $trigger_type === self::TRIGGER_SHIPPING_CONFIRMATION ) {
			return self::OPTION_SHIPPING_CONFIRMATION;
		}
		return self::OPTION_ORDER_CONFIRMATION;
	}
}
