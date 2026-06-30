<?php
/**
 * Tests for the WC + WP hook callbacks.
 *
 * @package Smaily\Connect\Tests
 */

declare(strict_types=1);

namespace Smaily\Connect\Tests\Unit\Integrations\WooCommerce;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Smaily\Connect\Integrations\WooCommerce\HookHandler;
use Smaily\Connect\Multilingual\DetectorFactory;
use Smaily\Connect\Smaily\ContactReconciler;
use Smaily\Connect\Smaily\ContactSyncMode;
use Smaily\Connect\Smaily\EventQueue;

final class HookHandlerTest extends TestCase {

	/** @var array<int, array{type: string, entity_id: string, payload: array<string, mixed>}> */
	private array $enqueued = array();

	private EventQueue $queue;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		HookHandler::reset_seen();
		// ContactLanguageResolver caches the active detector via DetectorFactory
		// (a process-global static). Reset so each case resolves through the
		// single-language SiteLocale fallback, independent of other tests.
		DetectorFactory::reset();
		$this->enqueued = array();

		// Fake EventQueue that records enqueue() calls in the local array
		// instead of touching $wpdb / Action Scheduler.
		$enqueued    = &$this->enqueued;
		$this->queue = new class( $enqueued ) extends EventQueue {
			private array $sink;

			public function __construct( array &$sink ) {
				$this->sink = &$sink;
			}

			public function enqueue( string $event_type, string $entity_id, array $payload ): ?int {
				$this->sink[] = array(
					'type'      => $event_type,
					'entity_id' => $entity_id,
					'payload'   => $payload,
				);
				return count( $this->sink );
			}
		};

		Functions\when( 'get_user_locale' )->justReturn( 'et_EE' );
		Functions\when( 'get_locale' )->justReturn( 'et_EE' );
		// Users are opted-in by default so the default (consent) contact-sync
		// mode lets contact.sync through (F3-48 audience). No preferred-language
		// meta → the resolver falls through to the SiteLocale default
		// ('et_EE' → 'et'). Audience-gating cases override this stub.
		Functions\when( 'get_user_meta' )->alias(
			static function ( int $user_id, string $key, bool $single = false ) {
				return $key === 'user_newsletter' ? '1' : '';
			}
		);
		Functions\when( 'sanitize_text_field' )->returnArg( 1 );
		Functions\when( 'wp_unslash' )->returnArg( 1 );

		// Default Settings: subscriber sync on, welcome / first_order off.
		Functions\when( 'get_option' )->alias(
			static function ( string $key, $default = null ) {
				if ( $key === 'smly_plus_setup_completed' ) {
					return true;
				}
				if ( $key === 'smly_plus_subscriber_sync_enabled' ) {
					return true;
				}
				if ( $key === 'smly_plus_welcome_enabled' ) {
					return false;
				}
				if ( $key === 'smly_plus_first_order_enabled' ) {
					return false;
				}
				return $default;
			}
		);
	}

	protected function tearDown(): void {
		HookHandler::reset_seen();
		DetectorFactory::reset();
		Monkey\tearDown();
		parent::tearDown();
		$_COOKIE = array();
	}

	public function test_gate_closed_suppresses_callbacks_until_setup_completed(): void {
		Functions\when( 'get_option' )->alias(
			static function ( string $key, $default = null ) {
				if ( $key === 'smly_plus_setup_completed' ) {
					return false;
				}
				if ( $key === 'smly_plus_subscriber_sync_enabled' ) {
					return true;
				}
				return $default;
			}
		);
		Functions\when( 'get_userdata' )->justReturn( $this->fake_user( 7, 'gated@example.test', 'G', 'X' ) );

		$handler = new HookHandler( $this->queue );
		$handler->on_user_register( 7 );
		$handler->on_profile_update( 7 );

		self::assertSame( array(), $this->enqueued, 'Closed gate (wizard unfinished) must suppress every new callback so legacy owns sync.' );
	}

	public function test_gate_open_allows_enqueue_after_setup_completed(): void {
		Functions\when( 'get_userdata' )->justReturn( $this->fake_user( 7, 'open@example.test', 'O', 'X' ) );

		( new HookHandler( $this->queue ) )->on_user_register( 7 );

		self::assertCount( 1, $this->enqueued );
		self::assertSame( HookHandler::EVENT_CONTACT_SYNC, $this->enqueued[0]['type'] );
	}

	public function test_user_register_enqueues_contact_sync(): void {
		Functions\when( 'get_userdata' )->justReturn( $this->fake_user( 42, 'alice@example.test', 'Alice', 'A' ) );

		( new HookHandler( $this->queue ) )->on_user_register( 42 );

		self::assertCount( 1, $this->enqueued );
		self::assertSame( HookHandler::EVENT_CONTACT_SYNC, $this->enqueued[0]['type'] );
		self::assertSame( '42', $this->enqueued[0]['entity_id'] );
		self::assertSame( 'alice@example.test', $this->enqueued[0]['payload']['email'] );
		// Resolver normalises to the short content-language code; with no
		// preferred-language meta it lands on the SiteLocale default ('et').
		self::assertSame( 'et', $this->enqueued[0]['payload']['language'] );
		self::assertSame( 'Alice', $this->enqueued[0]['payload']['fields']['first_name'] );
	}

	public function test_consent_mode_skips_contact_sync_for_non_opted_in_user(): void {
		// Default mode is consent → a user without user_newsletter=1 is not in
		// the audience, so no contact.sync (F3-48).
		Functions\when( 'get_user_meta' )->justReturn( '' );
		Functions\when( 'get_userdata' )->justReturn( $this->fake_user( 42, 'a@b.c', '', '' ) );

		( new HookHandler( $this->queue ) )->on_user_register( 42 );

		self::assertSame( array(), array_column( $this->enqueued, 'type' ) );
	}

	public function test_legitimate_interest_mode_syncs_non_opted_in_user(): void {
		Functions\when( 'get_option' )->alias(
			static function ( string $key, $default = null ) {
				if ( $key === 'smly_plus_setup_completed' ) {
					return true;
				}
				if ( $key === 'smly_plus_subscriber_sync_enabled' ) {
					return true;
				}
				if ( $key === ContactSyncMode::OPTION_MODE ) {
					return ContactSyncMode::MODE_LEGITIMATE_INTEREST;
				}
				return $default;
			}
		);
		// Not opted in — legitimate interest syncs everyone anyway.
		Functions\when( 'get_user_meta' )->justReturn( '' );
		Functions\when( 'get_userdata' )->justReturn( $this->fake_user( 42, 'a@b.c', '', '' ) );

		( new HookHandler( $this->queue ) )->on_user_register( 42 );

		self::assertCount( 1, $this->enqueued );
		self::assertSame( HookHandler::EVENT_CONTACT_SYNC, $this->enqueued[0]['type'] );
	}

	public function test_regular_contact_sync_never_carries_is_unsubscribed(): void {
		// Regression lock (F3-48.6): a routine data sync must NOT send
		// is_unsubscribed — only an explicit opt-state transition does — so a
		// profile edit can't resurrect a Smaily unsubscribe between reconciles.
		Functions\when( 'get_userdata' )->justReturn( $this->fake_user( 42, 'a@b.c', '', '' ) );

		( new HookHandler( $this->queue ) )->on_user_register( 42 );

		self::assertCount( 1, $this->enqueued );
		self::assertArrayNotHasKey( 'is_unsubscribed', $this->enqueued[0]['payload'] );
	}

	public function test_newsletter_optout_enqueues_unsubscribe_consent_event(): void {
		// Default consent mode; setUp stubs user_newsletter='1' (opted in) → the
		// pre-write old value; new value 0 → opt-out.
		Functions\when( 'get_userdata' )->justReturn( $this->fake_user( 42, 'a@b.c', '', '' ) );

		( new HookHandler( $this->queue ) )->on_user_newsletter_meta_update( 0, 42, 'user_newsletter', 0 );

		self::assertCount( 1, $this->enqueued );
		self::assertSame( HookHandler::EVENT_CONTACT_SYNC, $this->enqueued[0]['type'] );
		self::assertSame( '42:consent', $this->enqueued[0]['entity_id'] );
		self::assertSame( 1, $this->enqueued[0]['payload']['is_unsubscribed'] );
	}

	public function test_newsletter_optin_enqueues_subscribe_consent_event(): void {
		// Add path → old = 0 (no prior meta); new = 1 → opt-in.
		Functions\when( 'get_userdata' )->justReturn( $this->fake_user( 42, 'a@b.c', '', '' ) );

		( new HookHandler( $this->queue ) )->on_user_newsletter_meta_add( 42, 'user_newsletter', 1 );

		self::assertCount( 1, $this->enqueued );
		self::assertSame( 0, $this->enqueued[0]['payload']['is_unsubscribed'] );
	}

	public function test_reconcile_write_does_not_echo_a_consent_change_back(): void {
		// Security re-audit fix: the reconciler's own Smaily→WP user_newsletter
		// write fires this hook; it must NOT echo a contact.sync back to Smaily
		// (a mirrored `delete` would otherwise re-create the deleted contact).
		Functions\when( 'get_userdata' )->justReturn( $this->fake_user( 42, 'a@b.c', '', '' ) );

		ContactReconciler::run_suppressed(
			function (): void {
				( new HookHandler( $this->queue ) )->on_user_newsletter_meta_update( 0, 42, 'user_newsletter', 0 );
			}
		);

		self::assertSame( array(), $this->enqueued, 'A reconcile-driven meta write must not echo back to Smaily.' );
	}

	public function test_newsletter_change_ignored_for_other_meta_keys(): void {
		( new HookHandler( $this->queue ) )->on_user_newsletter_meta_update( 0, 42, 'billing_phone', 0 );

		self::assertSame( array(), $this->enqueued );
	}

	public function test_newsletter_change_ignored_outside_consent_mode(): void {
		Functions\when( 'get_option' )->alias(
			static function ( string $key, $default = null ) {
				if ( $key === 'smly_plus_setup_completed' ) {
					return true;
				}
				if ( $key === 'smly_plus_subscriber_sync_enabled' ) {
					return true;
				}
				if ( $key === ContactSyncMode::OPTION_MODE ) {
					return ContactSyncMode::MODE_LEGITIMATE_INTEREST;
				}
				return $default;
			}
		);
		Functions\when( 'get_userdata' )->justReturn( $this->fake_user( 42, 'a@b.c', '', '' ) );

		( new HookHandler( $this->queue ) )->on_user_newsletter_meta_update( 0, 42, 'user_newsletter', 0 );

		self::assertSame( array(), $this->enqueued, 'Legitimate interest leaves consent to Smaily.' );
	}

	public function test_user_register_skips_contact_sync_when_disabled(): void {
		Functions\when( 'get_option' )->justReturn( false );
		Functions\when( 'get_userdata' )->justReturn( $this->fake_user( 42, 'a@b.c', '', '' ) );

		( new HookHandler( $this->queue ) )->on_user_register( 42 );

		self::assertCount( 0, $this->enqueued );
	}

	public function test_user_register_fires_welcome_only_when_option_on(): void {
		Functions\when( 'get_option' )->alias(
			static function ( string $key, $default = null ) {
				if ( $key === 'smly_plus_setup_completed' ) {
					return true;
				}
				if ( $key === 'smly_plus_subscriber_sync_enabled' ) {
					return true;
				}
				if ( $key === 'smly_plus_welcome_enabled' ) {
					return true; // welcome enabled here
				}
				return $default;
			}
		);
		Functions\when( 'get_userdata' )->justReturn( $this->fake_user( 7, 'a@b.c', '', '' ) );

		( new HookHandler( $this->queue ) )->on_user_register( 7 );

		$types = array_column( $this->enqueued, 'type' );
		self::assertContains( HookHandler::EVENT_CONTACT_SYNC, $types );
		self::assertContains( HookHandler::EVENT_AUTOMATION_WELCOME, $types );
	}

	public function test_user_register_ignores_unknown_user(): void {
		Functions\when( 'get_userdata' )->justReturn( false );

		( new HookHandler( $this->queue ) )->on_user_register( 999 );

		self::assertCount( 0, $this->enqueued );
	}

	public function test_per_request_dedupe_collapses_repeated_profile_updates(): void {
		Functions\when( 'get_userdata' )->justReturn( $this->fake_user( 42, 'a@b.c', '', '' ) );

		$h = new HookHandler( $this->queue );
		$h->on_profile_update( 42 );
		$h->on_profile_update( 42 );
		$h->on_profile_update( 42 );

		self::assertCount(
			1,
			$this->enqueued,
			'Three profile_update fires in one request must collapse into a single enqueue.'
		);
	}

	public function test_reset_seen_re_enables_enqueue_for_same_entity(): void {
		Functions\when( 'get_userdata' )->justReturn( $this->fake_user( 42, 'a@b.c', '', '' ) );

		$h = new HookHandler( $this->queue );
		$h->on_profile_update( 42 );

		HookHandler::reset_seen();
		$h->on_profile_update( 42 );

		self::assertCount( 2, $this->enqueued );
	}

	public function test_checkout_order_processed_skips_when_first_order_disabled(): void {
		Functions\when( 'wc_get_order' )->justReturn( $this->fake_order( 100, 'buyer@example.test', 9, 1 ) );

		( new HookHandler( $this->queue ) )->on_checkout_order_processed( 100 );

		self::assertSame(
			array(),
			array_column( $this->enqueued, 'type' ),
			'first_order disabled — no event should be enqueued even on a real first order.'
		);
	}

	public function test_checkout_order_processed_fires_automation_first_order_on_first_paid_order(): void {
		Functions\when( 'get_option' )->alias(
			static fn ( string $key, $default = null ) =>
				$key === 'smly_plus_setup_completed'
					? true
					: ( $key === 'smly_plus_first_order_enabled' ? true : $default )
		);
		Functions\when( 'wc_get_order' )->justReturn( $this->fake_order( 100, 'buyer@example.test', 9, 1 ) );
		Functions\when( 'wc_get_customer_order_count' )->justReturn( 1 );

		( new HookHandler( $this->queue ) )->on_checkout_order_processed( 100 );

		self::assertCount( 1, $this->enqueued );
		self::assertSame( HookHandler::EVENT_AUTOMATION_FIRST_ORDER, $this->enqueued[0]['type'] );
		self::assertSame( 'buyer@example.test', $this->enqueued[0]['payload']['email'] );
		self::assertSame( '100', $this->enqueued[0]['payload']['fields']['order_id'] );
	}

	public function test_checkout_order_processed_skips_on_second_order(): void {
		Functions\when( 'get_option' )->alias(
			static fn ( string $key, $default = null ) =>
				$key === 'smly_plus_setup_completed'
					? true
					: ( $key === 'smly_plus_first_order_enabled' ? true : $default )
		);
		Functions\when( 'wc_get_order' )->justReturn( $this->fake_order( 100, 'buyer@example.test', 9, 1 ) );
		Functions\when( 'wc_get_customer_order_count' )->justReturn( 2 ); // already had one

		( new HookHandler( $this->queue ) )->on_checkout_order_processed( 100 );

		self::assertCount( 0, $this->enqueued );
	}

	public function test_attribution_cookies_are_saved_to_order_meta(): void {
		Functions\when( 'get_option' )->justReturn( false );

		$order = $this->fake_order( 100, 'buyer@example.test', 9, 1 );
		Functions\when( 'wc_get_order' )->justReturn( $order );

		$_COOKIE['smaily_anon_sid'] = 'anon-xyz';
		$_COOKIE['smaily_rec_uid']  = 'visitor-abc';

		( new HookHandler( $this->queue ) )->on_checkout_order_processed( 100 );

		self::assertSame( 'anon-xyz', $order->get_meta( '_smaily_anon_session_id' ) );
		self::assertSame( 'visitor-abc', $order->get_meta( '_smaily_visitor_token' ) );
	}

	private function fake_user( int $id, string $email, string $first, string $last ): \WP_User {
		// WP_User isn't autoloadable in unit tests; build an object with the
		// fields HookHandler reads.
		$user             = new \stdClass();
		$user->ID         = $id;
		$user->user_email = $email;
		$user->first_name = $first;
		$user->last_name  = $last;

		// Cast through a thin shim that pretends to be a WP_User. PHPUnit's
		// type system accepts an anonymous class as the parent's instance,
		// provided we declare it as such.
		return new class( $id, $email, $first, $last ) extends \WP_User {
			public function __construct( int $id, string $email, string $first, string $last ) {
				$this->ID         = $id;
				$this->user_email = $email;
				$this->first_name = $first;
				$this->last_name  = $last;
			}
		};
	}

	private function fake_order( int $id, string $email, int $customer_id, int $total ): \WC_Order {
		return new class( $id, $email, $customer_id, $total ) extends \WC_Order {
			private int $id;
			private string $email;
			private int $customer_id;
			private int $total;
			private array $meta = array();

			public function __construct( int $id, string $email, int $customer_id, int $total ) {
				$this->id          = $id;
				$this->email       = $email;
				$this->customer_id = $customer_id;
				$this->total       = $total;
			}

			public function get_id(): int {
				return $this->id;
			}

			public function get_billing_email( $context = 'view' ): string {
				return $this->email;
			}

			public function get_customer_id( $context = 'view' ): int {
				return $this->customer_id;
			}

			public function get_total( $context = 'view' ): string {
				return (string) $this->total;
			}

			public function get_currency( $context = 'view' ): string {
				return 'EUR';
			}

			public function update_meta_data( $key, $value, $unique_id = 0 ): void {
				$this->meta[ $key ] = $value;
			}

			public function get_meta( $key = '', $single = true, $context = 'view' ) {
				return $this->meta[ $key ] ?? '';
			}

			public function save() {
				return $this->id;
			}
		};
	}
}

// Stubs for the WP_User / WC_Order classes the anonymous fakes extend.
// Brain Monkey doesn't ship these; we declare the minimum public surface
// our HookHandler relies on.
if ( ! class_exists( \WP_User::class ) ) {
	// phpcs:ignore Squiz.Commenting.ClassComment.Missing -- test shim.
	eval( <<<'PHP'
class WP_User {
	public int $ID = 0;
	public string $user_email = '';
	public string $first_name = '';
	public string $last_name = '';
}
PHP
	);
}

if ( ! class_exists( \WC_Order::class ) ) {
	// phpcs:ignore Squiz.Commenting.ClassComment.Missing -- test shim.
	eval( <<<'PHP'
class WC_Order {
	public function get_id(): int { return 0; }
	public function get_billing_email( $context = 'view' ): string { return ''; }
	public function get_customer_id( $context = 'view' ): int { return 0; }
	public function get_total( $context = 'view' ): string { return '0'; }
	public function get_currency( $context = 'view' ): string { return ''; }
	public function get_status( $context = 'view' ): string { return ''; }
	public function get_total_discount( $ex_tax = true ): string { return '0'; }
	public function get_date_created( $context = 'view' ) { return null; }
	public function get_items( $types = 'line_item' ): array { return array(); }
	public function update_meta_data( $key, $value, $unique_id = 0 ): void {}
	public function get_meta( $key = '', $single = true, $context = 'view' ) { return ''; }
	public function save() { return 0; }
}
PHP
	);
}
