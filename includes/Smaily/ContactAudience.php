<?php
/**
 * Decides whether a given user / guest email is in the contact-sync audience.
 *
 * @package Smaily\Connect\Smaily
 */

declare(strict_types=1);

namespace Smaily\Connect\Smaily;

defined( 'ABSPATH' ) || exit;

/**
 * The mode-aware "is this person a contact we sync?" gate (F3-48). One source
 * used by the live HookHandler, the BackfillJob mass walk, and the reconciler,
 * so live-sync and backfill never disagree on the audience.
 *
 * Opt-in is read from the legacy `user_newsletter` user meta — the WP-side
 * consent record the registration / account / checkout checkboxes write
 * (profile-settings.class.php).
 */
final class ContactAudience {

	public const OPTIN_META = 'user_newsletter';

	private ContactSyncMode $mode;

	public function __construct( ?ContactSyncMode $mode = null ) {
		$this->mode = $mode ?? new ContactSyncMode();
	}

	/**
	 * Should this registered user be synced as a Smaily contact?
	 *
	 *   - checkout_optin      → no (no account-based sync)
	 *   - legitimate_interest → yes (all registered customers)
	 *   - consent             → only when opted in (user_newsletter=1)
	 */
	public function should_sync_user( \WP_User $user ): bool {
		if ( ! $this->mode->syncs_accounts() ) {
			return false;
		}

		if ( ! $this->mode->requires_optin() ) {
			return true;
		}

		return $this->opted_in( (int) $user->ID );
	}

	/**
	 * Should an ORDER's billing email be synced as a contact (the checkout
	 * path, F3-48)? Registered customers are covered by the account hooks, so in
	 * consent / legitimate-interest this only adds GUESTS:
	 *
	 *   - checkout_optin      → the checkbox is the only source: sync (guest OR
	 *                           account) iff opted in;
	 *   - legitimate_interest → guests synced iff include_guests (no opt-in needed);
	 *   - consent             → guests synced iff include_guests AND opted in.
	 *
	 * @param int  $customer_id Order's WP customer id (0 = guest).
	 * @param bool $opted_in    Whether the checkout subscription checkbox was ticked.
	 */
	public function should_sync_order_email( int $customer_id, bool $opted_in ): bool {
		if ( $this->mode->mode() === ContactSyncMode::MODE_CHECKOUT_OPTIN ) {
			return $opted_in;
		}

		if ( $customer_id > 0 ) {
			return false;
		}

		if ( ! $this->mode->include_guests() ) {
			return false;
		}

		return $this->mode->requires_optin() ? $opted_in : true;
	}

	private function opted_in( int $user_id ): bool {
		if ( $user_id <= 0 || ! function_exists( 'get_user_meta' ) ) {
			return false;
		}
		return (int) get_user_meta( $user_id, self::OPTIN_META, true ) === 1;
	}
}
