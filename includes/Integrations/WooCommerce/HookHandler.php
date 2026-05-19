<?php
/**
 * WC + WP hook callbacks for the namespaced Smaily\Connect\* code.
 *
 * @package Smaily\Connect\Integrations\WooCommerce
 */

declare(strict_types=1);

namespace Smaily\Connect\Integrations\WooCommerce;

defined( 'ABSPATH' ) || exit;

use Smaily\Connect\Smaily\EventQueue;

/**
 * Hook callbacks that fan WordPress + WooCommerce events into the Smaily
 * event queue (PLUGIN.md §9). Three concerns lived here:
 *
 *   1. Translate WP/WC entities into Smaily event payloads
 *      (event_type + entity_id + key-value array).
 *   2. Skip duplicate fires within one request via a static $seen guard.
 *      profile_update in particular can be called repeatedly during a
 *      single Settings save as WordPress applies the form fields one by
 *      one.
 *   3. Persist the four rec-engine attribution cookies into order meta
 *      as soon as the order is created (PLUGIN.md §9 "Order-meta
 *      attribution"). The cookies aren't reachable from the
 *      woocommerce_order_status_completed hook later — that callback
 *      can fire days later when admin marks the order complete — so
 *      capturing them in woocommerce_checkout_order_processed is the
 *      only safe time. Rec-engine consumption of those values lands in
 *      Phase 3; saving them now means Phase 3 doesn't need to refactor
 *      the hook layer.
 *
 * Settings options gating the actual dispatch (defaults set conservatively
 * so a fresh install doesn't fire workflows until the Settings UI in
 * sub-PR 6 turns them on):
 *
 *   - smly_plus_subscriber_sync_enabled — on by default (PLUGIN.md §5
 *     step 2 "Sync contacts to Smaily" linnuke vaikimisi sees)
 *   - smly_plus_welcome_enabled         — off (opt-in via Settings UI)
 *   - smly_plus_first_order_enabled     — off (opt-in via Settings UI)
 */
class HookHandler {

	public const EVENT_CONTACT_SYNC         = 'contact.sync';
	public const EVENT_AUTOMATION_WELCOME   = 'automation.welcome';
	public const EVENT_AUTOMATION_FIRST_ORDER = 'automation.first_order';

	private const OPTION_SUBSCRIBER_SYNC_ENABLED = 'smly_plus_subscriber_sync_enabled';
	private const OPTION_WELCOME_ENABLED         = 'smly_plus_welcome_enabled';
	private const OPTION_FIRST_ORDER_ENABLED     = 'smly_plus_first_order_enabled';

	private const ORDER_META_KEYS = array(
		'_smaily_anon_session_id' => 'smaily_anon_sid',
		'_smaily_visitor_token'   => 'smaily_rec_uid',
		'_smaily_rec_id'          => 'smaily_rec_id',
		'_smaily_rec_ctx'         => 'smaily_rec_ctx',
	);

	/** @var array<string, bool> per-request dedupe set keyed by "{event}:{entity_id}". */
	private static array $seen = array();

	private EventQueue $queue;

	public function __construct( EventQueue $queue ) {
		$this->queue = $queue;
	}

	public function on_user_register( int $user_id ): void {
		$user = get_userdata( $user_id );
		if ( $user === false ) {
			return;
		}

		if ( $this->is_enabled( self::OPTION_SUBSCRIBER_SYNC_ENABLED, true ) ) {
			$this->maybe_enqueue(
				self::EVENT_CONTACT_SYNC,
				(string) $user_id,
				$this->build_contact_payload( $user )
			);
		}

		if ( $this->is_enabled( self::OPTION_WELCOME_ENABLED, false ) ) {
			$this->maybe_enqueue(
				self::EVENT_AUTOMATION_WELCOME,
				(string) $user_id,
				$this->build_automation_payload( $user )
			);
		}
	}

	public function on_profile_update( int $user_id ): void {
		if ( ! $this->is_enabled( self::OPTION_SUBSCRIBER_SYNC_ENABLED, true ) ) {
			return;
		}

		$user = get_userdata( $user_id );
		if ( $user === false ) {
			return;
		}

		$this->maybe_enqueue(
			self::EVENT_CONTACT_SYNC,
			(string) $user_id,
			$this->build_contact_payload( $user )
		);
	}

	public function on_woocommerce_created_customer( int $customer_id ): void {
		// Same effect as user_register but fires from WooCommerce's
		// checkout-creates-account flow. Reuse the same path so welcome
		// emails fire identically whether the user came in through
		// wp-login or through checkout.
		$this->on_user_register( $customer_id );
	}

	public function on_checkout_order_processed( int $order_id ): void {
		if ( ! function_exists( 'wc_get_order' ) ) {
			return;
		}

		$order = wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		$this->save_attribution_cookies_to_order( $order );

		$email = $order->get_billing_email();
		if ( $email === '' ) {
			return;
		}

		if ( ! $this->is_enabled( self::OPTION_FIRST_ORDER_ENABLED, false ) ) {
			return;
		}

		if ( ! $this->is_first_order( $order ) ) {
			return;
		}

		$this->maybe_enqueue(
			self::EVENT_AUTOMATION_FIRST_ORDER,
			(string) $order_id,
			array(
				'email'    => $email,
				'language' => $this->detect_language_for_order( $order ),
				'fields'   => array(
					'order_id'       => (string) $order_id,
					'order_total'    => (string) $order->get_total(),
					'order_currency' => $order->get_currency(),
				),
			)
		);
	}

	/**
	 * Reset the per-request dedupe set. Tests use this between cases;
	 * production code never calls it — the static is request-scoped and
	 * PHP discards it at request end.
	 */
	public static function reset_seen(): void {
		self::$seen = array();
	}

	/**
	 * @param array<string, mixed> $payload
	 */
	private function maybe_enqueue( string $event_type, string $entity_id, array $payload ): void {
		$key = $event_type . ':' . $entity_id;
		if ( isset( self::$seen[ $key ] ) ) {
			return;
		}
		self::$seen[ $key ] = true;

		$this->queue->enqueue( $event_type, $entity_id, $payload );
	}

	/**
	 * @return array<string, mixed>
	 */
	private function build_contact_payload( \WP_User $user ): array {
		return array(
			'email'      => (string) $user->user_email,
			'language'   => $this->detect_language_for_user( $user ),
			'fields'     => array(
				'first_name' => (string) $user->first_name,
				'last_name'  => (string) $user->last_name,
				'user_id'    => (string) $user->ID,
			),
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function build_automation_payload( \WP_User $user ): array {
		return array(
			'email'    => (string) $user->user_email,
			'language' => $this->detect_language_for_user( $user ),
			'fields'   => array(
				'first_name' => (string) $user->first_name,
				'last_name'  => (string) $user->last_name,
			),
		);
	}

	private function detect_language_for_user( \WP_User $user ): string {
		if ( function_exists( 'get_user_locale' ) ) {
			return (string) get_user_locale( $user->ID );
		}

		return function_exists( 'get_locale' ) ? (string) get_locale() : '';
	}

	private function detect_language_for_order( \WC_Order $order ): string {
		$customer_id = $order->get_customer_id();
		if ( $customer_id > 0 && function_exists( 'get_user_locale' ) ) {
			return (string) get_user_locale( $customer_id );
		}

		return function_exists( 'get_locale' ) ? (string) get_locale() : '';
	}

	/**
	 * True when the order is the customer's first paid order. Anonymous
	 * checkouts (guest, no account) never qualify — there's no prior
	 * order history to compare against, so we conservatively skip.
	 */
	private function is_first_order( \WC_Order $order ): bool {
		$customer_id = $order->get_customer_id();
		if ( $customer_id <= 0 || ! function_exists( 'wc_get_customer_order_count' ) ) {
			return false;
		}

		return (int) wc_get_customer_order_count( $customer_id ) === 1;
	}

	private function save_attribution_cookies_to_order( \WC_Order $order ): void {
		$wrote_any = false;

		foreach ( self::ORDER_META_KEYS as $meta_key => $cookie_name ) {
			$value = isset( $_COOKIE[ $cookie_name ] ) ? (string) $_COOKIE[ $cookie_name ] : '';
			if ( $value === '' ) {
				continue;
			}

			$order->update_meta_data( $meta_key, sanitize_text_field( $value ) );
			$wrote_any = true;
		}

		if ( $wrote_any ) {
			$order->save();
		}
	}

	private function is_enabled( string $option_key, bool $default ): bool {
		$value = get_option( $option_key, $default );

		if ( is_bool( $value ) ) {
			return $value;
		}

		return $value === 1 || $value === '1' || $value === 'yes' || $value === 'true';
	}
}
