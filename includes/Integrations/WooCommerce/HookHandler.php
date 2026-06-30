<?php
/**
 * WC + WP hook callbacks for the namespaced Smaily\Connect\* code.
 *
 * @package Smaily\Connect\Integrations\WooCommerce
 */

declare(strict_types=1);

namespace Smaily\Connect\Integrations\WooCommerce;

defined( 'ABSPATH' ) || exit;

use Smaily\Connect\Smaily\ContactAudience;
use Smaily\Connect\Smaily\ContactReconciler;
use Smaily\Connect\Smaily\ContactSyncMode;
use Smaily\Connect\Smaily\EventQueue;
use Smaily\Connect\Support\ContactLanguageResolver;

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

	public const EVENT_CONTACT_SYNC           = 'contact.sync';
	public const EVENT_AUTOMATION_WELCOME     = 'automation.welcome';
	public const EVENT_AUTOMATION_FIRST_ORDER = 'automation.first_order';

	/**
	 * Master gate for the new live-sync path. Until the setup wizard is
	 * finished, the legacy Smaily_Connect subscriber-sync owns WooCommerce
	 * events; this handler stays dormant so the two never both fire (P1 #1,
	 * backward-compat audit). Wizard Finish flips it true and Bootstrap then
	 * de-registers the legacy hooks — see LegacyHookBridge.
	 */
	private const OPTION_SETUP_COMPLETED = 'smly_plus_setup_completed';

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

	/** @var bool Per-request guard so the closed-gate notice logs at most once. */
	private static bool $gate_logged = false;

	private EventQueue $queue;

	private ?\Smaily\Connect\Smaily\SubscriberPayloadBuilder $builder = null;

	private ?ContactLanguageResolver $language_resolver = null;

	private ?ContactAudience $audience = null;

	private ?ContactSyncMode $mode = null;

	public function __construct( EventQueue $queue ) {
		$this->queue = $queue;
	}

	public function on_user_register( int $user_id ): void {
		if ( $this->gate_closed() ) {
			return;
		}

		$user = get_userdata( $user_id );
		if ( $user === false ) {
			return;
		}

		if ( $this->is_enabled( self::OPTION_SUBSCRIBER_SYNC_ENABLED, true ) && $this->audience()->should_sync_user( $user ) ) {
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
		if ( $this->gate_closed() ) {
			return;
		}

		if ( ! $this->is_enabled( self::OPTION_SUBSCRIBER_SYNC_ENABLED, true ) ) {
			return;
		}

		$user = get_userdata( $user_id );
		if ( $user === false ) {
			return;
		}

		if ( ! $this->audience()->should_sync_user( $user ) ) {
			return;
		}

		$this->maybe_enqueue(
			self::EVENT_CONTACT_SYNC,
			(string) $user_id,
			$this->build_contact_payload( $user )
		);
	}

	/**
	 * Propagate a WP marketing opt-in / opt-out to Smaily (F3-48.6, consent mode
	 * only). Bound to `update_user_meta` (existing meta changes) — the action
	 * fires BEFORE the write, so get_user_meta still returns the OLD value.
	 *
	 * @param int|string $meta_id
	 * @param mixed      $meta_value
	 */
	public function on_user_newsletter_meta_update( $meta_id, int $object_id, string $meta_key, $meta_value ): void {
		if ( $meta_key !== ContactAudience::OPTIN_META ) {
			return;
		}
		$old = function_exists( 'get_user_meta' ) ? (int) get_user_meta( $object_id, ContactAudience::OPTIN_META, true ) : 0;
		$this->handle_newsletter_change( $object_id, $old, (int) $meta_value );
	}

	/**
	 * Same as on_user_newsletter_meta_update but for the FIRST set of the meta
	 * (WordPress routes a never-seen key through `add_user_meta`, not update).
	 * No prior value → old = 0.
	 *
	 * @param mixed $meta_value
	 */
	public function on_user_newsletter_meta_add( int $object_id, string $meta_key, $meta_value ): void {
		if ( $meta_key !== ContactAudience::OPTIN_META ) {
			return;
		}
		$this->handle_newsletter_change( $object_id, 0, (int) $meta_value );
	}

	public function on_woocommerce_created_customer( int $customer_id ): void {
		// Same effect as user_register but fires from WooCommerce's
		// checkout-creates-account flow. Reuse the same path so welcome
		// emails fire identically whether the user came in through
		// wp-login or through checkout.
		$this->on_user_register( $customer_id );
	}

	/**
	 * @param array<string, mixed> $posted_data Classic-checkout POSTed fields (3-arg hook).
	 */
	public function on_checkout_order_processed( int $order_id, array $posted_data = array() ): void {
		if ( ! function_exists( 'wc_get_order' ) ) {
			return;
		}

		$order = wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		// Attribution capture is order-meta only — no Smaily call — so it runs
		// regardless of the wizard gate; the rec-engine reads these later.
		$this->save_attribution_cookies_to_order( $order );

		// Gate the sync path (P1 #1): only fire first-order automation once
		// the wizard is finished, so it never doubles the legacy sync.
		if ( $this->gate_closed() ) {
			return;
		}

		// F3-48 F1: order-path contact sync — guests + the checkout-opt-in preset.
		// Classic checkout carries the newsletter checkbox in $posted_data; block
		// checkout uses on_checkout_block_optin instead.
		$opted_in = isset( $posted_data['user_newsletter'] ) && (int) $posted_data['user_newsletter'] === 1;
		$this->sync_order_contact( $order, $opted_in );

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

		$payload = array(
			'email'  => $email,
			'fields' => array(
				'order_id'       => (string) $order_id,
				'order_total'    => (string) $order->get_total(),
				'order_currency' => $order->get_currency(),
			),
		);

		$language = $this->detect_language_for_order( $order );
		if ( $language !== '' ) {
			$payload['language'] = $language;
		}

		$this->maybe_enqueue( self::EVENT_AUTOMATION_FIRST_ORDER, (string) $order_id, $payload );
	}

	/**
	 * Block-checkout opt-in (F3-48 F1). The WC Blocks Store API carries the
	 * subscription checkbox in the request extensions, not in classic POST data.
	 *
	 * @param mixed $request The store-API request (array-accessible extensions bag).
	 */
	public function on_checkout_block_optin( \WC_Order $order, $request ): void {
		$opted_in = isset( $request['extensions']['smaily-checkout-optin']['user_newsletter'] )
			&& true === $request['extensions']['smaily-checkout-optin']['user_newsletter'];

		$this->sync_order_contact( $order, $opted_in );
	}

	/**
	 * Stamp the rec-attribution cookies onto a BLOCK-checkout order
	 * (`woocommerce_store_api_checkout_order_processed`). The classic-checkout
	 * stamping in on_checkout_order_processed never fires for Store-API orders,
	 * so a block-checkout store captured the `smaily_rec` cookie but the order
	 * never carried `_smaily_rec_id` → `smaily_rec_id` was absent from every
	 * order payload (the F3-46 "classic checkout only" gap; MiuMjau field
	 * regression 2026-06-30). Attribution is rec-engine, not contact sync, so it
	 * runs ungated — exactly like the classic path.
	 */
	public function on_block_checkout_order_processed( \WC_Order $order ): void {
		$this->save_attribution_cookies_to_order( $order );
	}

	/**
	 * Enqueue a contact.sync for an order's billing email when the mode's
	 * audience says so (F3-48 F1 — the guest / checkout-opt-in path the account
	 * hooks don't cover). Registered customers in consent / legitimate interest
	 * are handled by the account hooks; checkout-only routes everyone here.
	 */
	private function sync_order_contact( \WC_Order $order, bool $opted_in ): void {
		if ( $this->gate_closed() || ! $this->is_enabled( self::OPTION_SUBSCRIBER_SYNC_ENABLED, true ) ) {
			return;
		}

		$customer_id = (int) $order->get_customer_id();
		if ( ! $this->audience()->should_sync_order_email( $customer_id, $opted_in ) ) {
			return;
		}

		$email = (string) $order->get_billing_email();
		if ( $email === '' ) {
			return;
		}

		$user = $customer_id > 0 ? get_userdata( $customer_id ) : false;
		if ( $user instanceof \WP_User ) {
			$payload = $this->build_contact_payload( $user );
		} else {
			$payload  = array(
				'email'  => $email,
				'fields' => array( 'store' => function_exists( 'get_site_url' ) ? (string) get_site_url() : '' ),
			);
			$language = $this->detect_language_for_order( $order );
			if ( $language !== '' ) {
				$payload['language'] = $language;
			}
		}

		// An explicit checkout opt-in subscribes (consent / checkout-only); under
		// legitimate interest Smaily owns consent, so is_unsubscribed is omitted.
		if ( $opted_in && $this->mode()->mode() !== ContactSyncMode::MODE_LEGITIMATE_INTEREST ) {
			$payload['is_unsubscribed'] = 0;
		}

		$this->maybe_enqueue( self::EVENT_CONTACT_SYNC, 'order:' . $order->get_id(), $payload );
	}

	/**
	 * Reset the per-request dedupe set. Tests use this between cases;
	 * production code never calls it — the static is request-scoped and
	 * PHP discards it at request end.
	 */
	public static function reset_seen(): void {
		self::$seen        = array();
		self::$gate_logged = false;
	}

	/**
	 * Master gate (P1 #1). Returns true — callback must no-op — until the
	 * setup wizard is finished. Before Finish the legacy subscriber-sync
	 * owns WooCommerce events; this handler firing too would double-send
	 * the contact (two API calls, risk of double automation). Logs the
	 * first closed-gate touch per request when WP_DEBUG so the dormancy is
	 * visible without spamming production debug.log.
	 */
	private function gate_closed(): bool {
		if ( (bool) get_option( self::OPTION_SETUP_COMPLETED, false ) ) {
			return false;
		}

		if ( ! self::$gate_logged && defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			self::$gate_logged = true;
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			\Smaily\Connect\Support\DebugLog::write( '[smaily-connect] Live sync deferred: smly_plus_setup_completed is false; legacy Smaily sync owns WooCommerce events until the setup wizard is finished.' );
		}

		return true;
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
		$payload = array(
			'email'  => (string) $user->user_email,
			'fields' => $this->payload_builder()->build_fields( $user ),
		);

		// Omit `language` when unresolved — Smaily treats absent as
		// "leave existing intact", empty as "wipe". Never wipe.
		$language = $this->detect_language_for_user( $user );
		if ( $language !== '' ) {
			$payload['language'] = $language;
		}

		return $payload;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function build_automation_payload( \WP_User $user ): array {
		$payload = array(
			'email'  => (string) $user->user_email,
			'fields' => $this->payload_builder()->build_fields( $user ),
		);

		$language = $this->detect_language_for_user( $user );
		if ( $language !== '' ) {
			$payload['language'] = $language;
		}

		return $payload;
	}

	private function payload_builder(): \Smaily\Connect\Smaily\SubscriberPayloadBuilder {
		if ( $this->builder === null ) {
			$this->builder = new \Smaily\Connect\Smaily\SubscriberPayloadBuilder();
		}
		return $this->builder;
	}

	private function language_resolver(): ContactLanguageResolver {
		if ( $this->language_resolver === null ) {
			$this->language_resolver = new ContactLanguageResolver();
		}
		return $this->language_resolver;
	}

	private function audience(): ContactAudience {
		if ( $this->audience === null ) {
			$this->audience = new ContactAudience();
		}
		return $this->audience;
	}

	private function mode(): ContactSyncMode {
		if ( $this->mode === null ) {
			$this->mode = new ContactSyncMode();
		}
		return $this->mode;
	}

	/**
	 * Turn a user_newsletter transition into a Smaily consent change. Opt-in
	 * (→1) sends is_unsubscribed=0 (subscribe — an explicit re-grant overrides a
	 * prior Smaily unsubscribe); opt-out (1→0) sends is_unsubscribed=1. Only in
	 * consent mode: legitimate interest leaves consent to Smaily, checkout-only
	 * has no accounts. The regular data sync (on_profile_update) never sends
	 * is_unsubscribed — so a routine profile edit can't resurrect a Smaily
	 * unsubscribe between reconciles; only an actual opt-state transition does.
	 */
	private function handle_newsletter_change( int $user_id, int $old_value, int $new_value ): void {
		// The reconciler's own Smaily→WP write fires this hook — don't echo it
		// back to Smaily (a mirrored `delete` would re-create the contact).
		if ( ContactReconciler::is_applying() ) {
			return;
		}
		if ( $old_value === $new_value || $this->gate_closed() ) {
			return;
		}
		if ( ! $this->is_enabled( self::OPTION_SUBSCRIBER_SYNC_ENABLED, true ) ) {
			return;
		}
		if ( $this->mode()->mode() !== ContactSyncMode::MODE_CONSENT ) {
			return;
		}

		$user = get_userdata( $user_id );
		if ( $user === false ) {
			return;
		}

		if ( $new_value === 1 ) {
			$this->enqueue_consent_change( $user, 0 );
		} elseif ( $old_value === 1 ) {
			$this->enqueue_consent_change( $user, 1 );
		}
	}

	private function enqueue_consent_change( \WP_User $user, int $is_unsubscribed ): void {
		$payload                    = $this->build_contact_payload( $user );
		$payload['is_unsubscribed'] = $is_unsubscribed;

		// Distinct entity id so this consent event is a SEPARATE queue row from
		// any same-request data sync (which omits is_unsubscribed = preserve) —
		// the per-request dedupe never swallows the consent change.
		$this->maybe_enqueue( self::EVENT_CONTACT_SYNC, $user->ID . ':consent', $payload );
	}

	private function detect_language_for_user( \WP_User $user ): string {
		return $this->language_resolver()->for_user( $user );
	}

	private function detect_language_for_order( \WC_Order $order ): string {
		return $this->language_resolver()->for_order( $order );
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
			if ( ! isset( $_COOKIE[ $cookie_name ] ) ) {
				continue;
			}

			$value = sanitize_text_field( wp_unslash( $_COOKIE[ $cookie_name ] ) );
			if ( $value === '' ) {
				continue;
			}

			$order->update_meta_data( $meta_key, $value );
			$wrote_any = true;
		}

		if ( $wrote_any ) {
			$order->save();
		}
	}

	private function is_enabled( string $option_key, bool $fallback ): bool {
		$value = get_option( $option_key, $fallback );

		if ( is_bool( $value ) ) {
			return $value;
		}

		return $value === 1 || $value === '1' || $value === 'yes' || $value === 'true';
	}
}
