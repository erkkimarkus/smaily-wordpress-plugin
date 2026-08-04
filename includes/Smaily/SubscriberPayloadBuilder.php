<?php
/**
 * Single source of truth for WP user → Smaily contact-payload mapping.
 *
 * @package Smaily\Connect\Smaily
 */

declare(strict_types=1);

namespace Smaily\Connect\Smaily;

defined( 'ABSPATH' ) || exit;

/**
 * Translates a WP_User into the Smaily `/api/contact.php` payload shape
 * documented in spec/FIELD_MAPPING.md.
 *
 * Used by both BackfillJob (bulk-sync via Action Scheduler) and the
 * WooCommerce HookHandler (per-event live-sync). Centralising the
 * mapping here is the only way to guarantee backfill and live-sync
 * agree on what a contact looks like — Erkki's field-mapping audit
 * (sub-PR 2.H.14) caught the BackfillJob shipping a hard-coded
 * three-field subset while the merchant's `syncFields` checkbox
 * choices in Step 2 were ignored entirely.
 *
 * Reads the per-merchant opt-in toggles from
 * `wp_options.smaily_connect_subscriber_sync_fields` (canonical field
 * names per FIELD_MAPPING.md §2/§3). `email` and `store` are sent
 * regardless of the toggle array (§1).
 *
 * Per-field transforms live here, not in the option storage. A field
 * whose source value is empty is OMITTED from the payload — Smaily
 * treats absent and empty differently (absent leaves any existing
 * value intact, empty wipes it).
 */
class SubscriberPayloadBuilder {

	public const OPTION_SYNC_FIELDS = 'smaily_connect_subscriber_sync_fields';

	/**
	 * Cross-channel sync fields per FIELD_MAPPING.md §2/§3. Field names
	 * here are the merchant-facing toggle keys (same as
	 * DEFAULT_SYNC_FIELDS in admin/src/state/types.ts).
	 *
	 * @var array<int, string>
	 */
	private const SUPPORTED_FIELDS = array(
		'first_name',
		'last_name',
		'nickname',
		'user_phone',
		'user_gender',
		'birthday',
		'customer_id',
		'customer_group',
		'first_registered',
		'site_title',
	);

	/**
	 * Pre-PRO-1683 toggle keys the wizard saved → the canonical names above.
	 *
	 * The wizard's checkbox list wrote `phone` / `gender` while this builder
	 * has always read `user_phone` / `user_gender`, so both selections were
	 * discarded by the SUPPORTED_FIELDS intersection and neither field ever
	 * reached Smaily. The wizard now writes the canonical names; this map is
	 * what keeps a store that saved its selection BEFORE the fix working
	 * without a migration — the wire names are untouched either way
	 * (FIELD_MAPPING.md §2: renaming them would break existing segments).
	 *
	 * @var array<string, string>
	 */
	private const LEGACY_FIELD_ALIASES = array(
		'phone'  => 'user_phone',
		'gender' => 'user_gender',
	);

	/**
	 * Build the Smaily contact payload for a WP user.
	 *
	 * @return array<string, mixed>
	 */
	public function build( \WP_User $user ): array {
		$payload = array(
			'email' => (string) ( $user->user_email ?? '' ),
			// `store` always — template context per FIELD_MAPPING.md §1.
			'store' => function_exists( 'get_site_url' ) ? (string) get_site_url() : '',
		);

		foreach ( $this->enabled() as $field ) {
			$value = $this->resolve_field( $user, $field );
			if ( $value === null || $value === '' ) {
				continue;
			}
			$payload[ $field ] = $value;
		}

		return $payload;
	}

	/**
	 * Build the nested-`fields` payload shape used by the WC HookHandler.
	 *
	 * Same field set as build(), but wrapped under a `fields` key alongside
	 * `email` and `language` so the EventQueue row keeps its existing
	 * envelope. `store` joins the `fields` bag (templating context applies
	 * to automation triggers too).
	 *
	 * @return array<string, mixed>
	 */
	public function build_fields( \WP_User $user ): array {
		$fields = array(
			'store' => function_exists( 'get_site_url' ) ? (string) get_site_url() : '',
		);

		foreach ( $this->enabled() as $field ) {
			$value = $this->resolve_field( $user, $field );
			if ( $value === null || $value === '' ) {
				continue;
			}
			$fields[ $field ] = $value;
		}

		return $fields;
	}

	/**
	 * The merchant's selection, read fresh per payload: a handler registered
	 * at `init` outlives a settings save in a long-running process, and
	 * `get_option()` on this autoloaded key is an in-memory lookup anyway.
	 *
	 * @return array<int, string>
	 */
	private function enabled(): array {
		$stored = get_option( self::OPTION_SYNC_FIELDS, null );
		if ( ! is_array( $stored ) ) {
			// Never-saved or corrupt — fall back to the documented
			// merchant-friendly default (every cross-channel field on).
			$stored = self::SUPPORTED_FIELDS;
		}

		// Reject unknown field names — a stale value in the option
		// from a future plugin version shouldn't drag bogus keys
		// into the payload.
		return array_values(
			array_intersect( self::SUPPORTED_FIELDS, array_map( 'strval', self::canonical_fields( $stored ) ) )
		);
	}

	/**
	 * Translate a stored field selection to the canonical §2/§3 names.
	 *
	 * Only string VALUES are rewritten and every key is preserved, so the
	 * legacy associative shape (`array( 'user_phone' => false, … )`, whose
	 * values are booleans) comes back byte-identical — this is not the place
	 * that teaches the readers to understand that shape.
	 *
	 * @param array<int|string, mixed> $stored Raw option value.
	 *
	 * @return array<int|string, mixed>
	 */
	public static function canonical_fields( array $stored ): array {
		$canonical = array();
		foreach ( $stored as $key => $value ) {
			$canonical[ $key ] = is_string( $value ) && isset( self::LEGACY_FIELD_ALIASES[ $value ] )
				? self::LEGACY_FIELD_ALIASES[ $value ]
				: $value;
		}

		return $canonical;
	}

	/**
	 * Resolve a single canonical field-name to its transformed source
	 * value. Returns null when the source is absent — caller drops
	 * absent fields from the payload.
	 *
	 * @return string|null
	 */
	private function resolve_field( \WP_User $user, string $field ): ?string {
		switch ( $field ) {
			case 'first_name':
				return $this->trim_or_null( (string) ( $user->first_name ?? '' ) );
			case 'last_name':
				return $this->trim_or_null( (string) ( $user->last_name ?? '' ) );
			case 'nickname':
				return $this->trim_or_null( (string) ( $user->nickname ?? '' ) );
			case 'user_phone':
				return $this->trim_or_null( $this->user_meta( $user, 'user_phone' ) );
			case 'user_gender':
				$raw = $this->user_meta( $user, 'user_gender' );
				if ( $raw === '' ) {
					return null;
				}
				// Legacy enum convention (data-handler.class.php):
				// '0' → Female, anything else → Male. Documented in
				// FIELD_MAPPING.md §2 so future readers see the
				// non-obvious mapping.
				return $raw === '0' ? 'Female' : 'Male';
			case 'birthday':
				$raw = $this->user_meta( $user, 'user_dob' );
				if ( $raw === '' ) {
					return null;
				}
				$timestamp = strtotime( $raw );
				if ( $timestamp === false ) {
					return null;
				}
				return gmdate( 'Y-m-d', $timestamp );
			case 'customer_id':
				$id = (int) $user->ID;
				return $id > 0 ? (string) $id : null;
			case 'customer_group':
				$role = isset( $user->roles[0] ) ? (string) $user->roles[0] : '';
				return $role !== '' ? $role : null;
			case 'first_registered':
				$registered = (string) ( $user->user_registered ?? '' );
				return $registered !== '' ? $registered : null;
			case 'site_title':
				if ( ! function_exists( 'get_bloginfo' ) ) {
					return null;
				}
				$title = (string) get_bloginfo( 'name' );
				return $title !== '' ? $title : null;
			default:
				return null;
		}
	}

	private function user_meta( \WP_User $user, string $key ): string {
		if ( ! function_exists( 'get_user_meta' ) ) {
			return '';
		}
		$value = get_user_meta( (int) $user->ID, $key, true );
		return is_string( $value ) ? $value : '';
	}

	private function trim_or_null( string $value ): ?string {
		$trimmed = trim( $value );
		return $trimmed === '' ? null : $trimmed;
	}
}
