<?php
/**
 * Integration: transactional emails end to end (PRO-1504 Stage 2) — the
 * sync-first send via message/send.php, native-WC-email suppression, the
 * once-per-order-per-type meta guard, the queue-retry fallback on its own
 * flusher, and fail-open on a terminal Smaily rejection.
 *
 * The Smaily API is mocked at the pre_http_request seam — the same
 * established pattern CartPipelineTest uses for the marketing API. This is
 * NOT the rec-engine mock (message/send.php is the Smaily marketing API).
 *
 * @package Smaily\Connect\Tests\Integration
 */

declare(strict_types=1);

namespace Smaily\Connect\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Smaily\Connect\Smaily\EventQueue;
use Smaily\Connect\Smaily\TransactionalFlusher;
use Smaily\Connect\Smaily\TransactionalGate;
use Smaily\Connect\Tests\Integration\Support\EnvScrub;
use Smaily\Connect\Tests\Integration\Support\RestRequestHelper;

final class TransactionalEmailsPipelineTest extends TestCase {

	/** @var array<int, int> */
	private array $created_orders = array();

	/** @var array<int, int> */
	private array $created_products = array();

	protected function setUp(): void {
		parent::setUp();
		if ( ! function_exists( 'wc_create_order' ) || ! class_exists( 'WC_Product_Simple' ) ) {
			self::markTestSkipped( 'WooCommerce not active — transactional emails need WC_Order.' );
		}
		EnvScrub::reset();
		RestRequestHelper::login_as_admin();
	}

	protected function tearDown(): void {
		foreach ( $this->created_orders as $order_id ) {
			// NOT wp_delete_post: under HPOS orders live in wc_orders, so a
			// post-delete is a silent no-op and the order leaks across runs.
			$order = wc_get_order( $order_id );
			if ( $order instanceof \WC_Order ) {
				$order->delete( true );
			}
		}
		foreach ( $this->created_products as $product_id ) {
			wp_delete_post( $product_id, true );
		}
		$this->created_orders    = array();
		$this->created_products  = array();

		// Drop any Smaily client cached from this test's seeded credentials.
		$bootstrap = \Smaily\Connect\Bootstrap::instance();
		$prop      = new \ReflectionProperty( $bootstrap, 'smaily_clients' );
		$prop->setAccessible( true );
		$prop->setValue( $bootstrap, array() );

		parent::tearDown();
	}

	public function test_order_confirmation_sends_once_with_the_mapped_workflow_and_product_matrix(): void {
		$this->configure( array( 'order_confirmation' => '4242' ) );

		$product  = $this->make_product( 'E2E Confirmation Product', 19.90 );
		$order_id = $this->make_order( 'buyer@example.test', $product );

		$captured = array();
		$fake     = $this->fake_transport( $captured );
		add_filter( 'pre_http_request', $fake, 10, 3 );
		try {
			$this->fire_checkout_order_processed( $order_id );
		} finally {
			remove_filter( 'pre_http_request', $fake, 10 );
		}

		self::assertCount( 1, $captured, 'Exactly one send-message POST.' );
		self::assertStringContainsString( '/api/message/send.php', $captured[0]['url'] );
		self::assertSame( 4242, $captured[0]['body']['autoresponder_id'] );
		self::assertSame( array( 'buyer@example.test' ), $captured[0]['body']['to'] );
		self::assertSame( 'E2E Confirmation Product', $captured[0]['body']['context']['product_name_1'] );
		self::assertSame( '1', $captured[0]['body']['context']['product_quantity_1'] );

		$order = wc_get_order( $order_id );
		self::assertSame( TransactionalFlusher::META_STATUS_SENT, $order->get_meta( TransactionalFlusher::meta_key_for( TransactionalGate::TRIGGER_ORDER_CONFIRMATION ) ) );
	}

	public function test_shipping_confirmation_sends_once_and_repeated_flips_do_not_resend(): void {
		$this->configure( array( 'shipping_confirmation' => '5151' ) );

		$product  = $this->make_product( 'E2E Shipping Product', 9.00 );
		$order_id = $this->make_order( 'ship@example.test', $product );
		$order    = wc_get_order( $order_id );
		$order->set_status( 'processing' );
		$order->save();

		$captured = array();
		$fake     = $this->fake_transport( $captured );
		add_filter( 'pre_http_request', $fake, 10, 3 );
		try {
			$order->update_status( 'completed' );

			// A later flip away and back into the shipped set must not re-send —
			// the once-per-order-per-type meta guard.
			$order->update_status( 'on-hold' );
			$order->update_status( 'completed' );
		} finally {
			remove_filter( 'pre_http_request', $fake, 10 );
		}

		self::assertCount( 1, $captured, 'Only the FIRST transition into a shipped status sends.' );
		self::assertSame( 5151, $captured[0]['body']['autoresponder_id'] );
	}

	public function test_native_processing_order_email_suppressed_only_while_the_gate_holds(): void {
		$this->configure( array( 'order_confirmation' => '4242' ) );

		// Real WC_Email::is_enabled() calls this filter as
		// apply_filters( 'woocommerce_email_enabled_{id}', bool, $order, $email ) —
		// match that shape so any other real listener (e.g. WC core's own
		// POS suppression filter) doesn't choke on a missing arg.
		$product = $this->make_product( 'Suppression Product', 4.00 );
		$order   = wc_get_order( $this->make_order( 'suppress@example.test', $product ) );

		self::assertFalse(
			apply_filters( 'woocommerce_email_enabled_customer_processing_order', true, $order, null ),
			'The gate is open (toggle on, mapping present, credentials complete) → suppressed.'
		);

		update_option( 'smly_plus_transactional_emails_enabled', false );

		self::assertTrue(
			apply_filters( 'woocommerce_email_enabled_customer_processing_order', true, $order, null ),
			'Toggling the master switch off must instantly restore the native email — zero behavior change.'
		);
	}

	public function test_everything_off_is_zero_behavior_change(): void {
		// No configure() call — every transactional option is at its default
		// (off), matching a fresh install.
		$product  = $this->make_product( 'Untouched Product', 5.00 );
		$order_id = $this->make_order( 'off@example.test', $product );

		$captured = array();
		$fake     = $this->fake_transport( $captured );
		add_filter( 'pre_http_request', $fake, 10, 3 );
		try {
			$this->fire_checkout_order_processed( $order_id );
		} finally {
			remove_filter( 'pre_http_request', $fake, 10 );
		}

		self::assertSame( array(), $captured, 'No transactional send attempted.' );
		self::assertSame( 0, $this->queue_count( TransactionalFlusher::EVENT_TYPE_ORDER_CONFIRMATION ) );
		self::assertTrue( apply_filters( 'woocommerce_email_enabled_customer_processing_order', true, wc_get_order( $order_id ), null ) );
	}

	public function test_terminal_smaily_rejection_marks_the_row_failed_and_fails_open(): void {
		$this->configure( array( 'order_confirmation' => '4242' ) );

		$product  = $this->make_product( 'Terminal Product', 12.00 );
		$order_id = $this->make_order( 'terminal@example.test', $product );

		$fake = $this->fake_transport_with_code( 203 );
		add_filter( 'pre_http_request', $fake, 10, 3 );
		try {
			$this->fire_checkout_order_processed( $order_id );
		} finally {
			remove_filter( 'pre_http_request', $fake, 10 );
		}

		$row = $this->queue_row( TransactionalFlusher::EVENT_TYPE_ORDER_CONFIRMATION );
		self::assertNotNull( $row );
		self::assertSame( 'failed', $row['status'], 'A non-101 body code is deterministic — mark_failed, not an eternal retry.' );
		self::assertStringContainsString( 'smaily_response_code_203', (string) $row['last_error'] );

		$order = wc_get_order( $order_id );
		self::assertSame(
			TransactionalFlusher::META_STATUS_FAILED_OPEN,
			$order->get_meta( TransactionalFlusher::meta_key_for( TransactionalGate::TRIGGER_ORDER_CONFIRMATION ) ),
			'Fail-open decision recorded on the order — the WC mailer trigger itself is unit-covered.'
		);
	}

	public function test_transient_failure_lands_in_the_queue_and_only_its_own_flusher_drains_it(): void {
		$this->configure( array( 'order_confirmation' => '4242' ) );

		$product  = $this->make_product( 'Retry Product', 8.00 );
		$order_id = $this->make_order( 'retry@example.test', $product );

		$fake_5xx = $this->fake_transport_with_code( 500, 500 );
		add_filter( 'pre_http_request', $fake_5xx, 10, 3 );
		try {
			$this->fire_checkout_order_processed( $order_id );
		} finally {
			remove_filter( 'pre_http_request', $fake_5xx, 10 );
		}

		$row = $this->queue_row( TransactionalFlusher::EVENT_TYPE_ORDER_CONFIRMATION );
		self::assertNotNull( $row );
		self::assertSame( 'pending', $row['status'], 'A 5xx is transient — the row stays pending for the dedicated retry flusher.' );

		// The MAIN flusher must never touch it (event-type scoping).
		$fake_ok = $this->fake_transport_with_code( 101, 200 );
		add_filter( 'pre_http_request', $fake_ok, 10, 3 );
		try {
			do_action( EventQueue::FLUSH_HOOK );
		} finally {
			remove_filter( 'pre_http_request', $fake_ok, 10 );
		}
		$row = $this->queue_row( TransactionalFlusher::EVENT_TYPE_ORDER_CONFIRMATION );
		self::assertSame( 'pending', $row['status'], 'The main flusher excludes transactional.* event types.' );

		// TransactionalFlusher's OWN hook drains + retries it successfully.
		add_filter( 'pre_http_request', $fake_ok, 10, 3 );
		try {
			do_action( TransactionalFlusher::FLUSH_HOOK );
		} finally {
			remove_filter( 'pre_http_request', $fake_ok, 10 );
		}
		$row = $this->queue_row( TransactionalFlusher::EVENT_TYPE_ORDER_CONFIRMATION );
		self::assertSame( 'sent', $row['status'] );
	}

	// --- helpers -------------------------------------------------------------

	/**
	 * @param array<string, string> $mappings trigger_type => workflow id.
	 */
	private function configure( array $mappings ): void {
		$rows = array();
		foreach ( $mappings as $trigger => $workflow_id ) {
			$rows[] = array(
				'triggerType'       => $trigger,
				'language'          => 'default',
				'accountKey'        => 'transactional',
				'workflowId'        => $workflow_id,
				'isDefaultFallback' => true,
			);
		}

		$response = RestRequestHelper::post(
			'/settings',
			array(
				'tab'  => 'woocommerce',
				'data' => array(
					'transactionalEmailsEnabled'    => true,
					'orderConfirmationEnabled'      => true,
					'shippingConfirmationEnabled'   => true,
					'shippedOrderStatuses'          => array( 'completed' ),
					'transactionalCredentials'      => array(
						'subdomain' => 'txsub',
						'username'  => 'txuser',
						'password'  => 'txpass',
					),
					'automationMappings'            => $rows,
				),
			)
		);
		self::assertSame( 200, $response->get_status() );
	}

	private function make_product( string $name, float $price ): int {
		$product = new \WC_Product_Simple();
		$product->set_name( $name );
		$product->set_regular_price( (string) $price );
		$product->set_status( 'publish' );
		$product_id = $product->save();
		self::assertGreaterThan( 0, $product_id );
		$this->created_products[] = $product_id;
		return $product_id;
	}

	private function make_order( string $email, int $product_id ): int {
		$order = wc_create_order();
		$order->set_billing_email( $email );
		$order->add_product( wc_get_product( $product_id ), 1 );
		$order->calculate_totals();
		$order->set_status( 'pending' );
		$order_id = (int) $order->save();

		$this->created_orders[] = $order_id;
		return $order_id;
	}

	/**
	 * Fires the real 3-arg woocommerce_checkout_order_processed hook — WC's
	 * own shape ($order_id, $posted_data, $order). A bare 1-arg do_action()
	 * trips OTHER real listeners still registered on this hook (the legacy
	 * subscriber-sync callback) that declare all 3 params with no defaults.
	 */
	private function fire_checkout_order_processed( int $order_id ): void {
		do_action( 'woocommerce_checkout_order_processed', $order_id, array(), wc_get_order( $order_id ) );
	}

	/**
	 * A pre_http_request fake that captures every request and replies with
	 * Smaily {code:101} (success).
	 *
	 * @param array<int, array{url: string, body: array<string, mixed>}> $captured By-ref.
	 */
	private function fake_transport( array &$captured ): callable {
		return static function ( $pre, $args, $url ) use ( &$captured ) {
			$captured[] = array(
				'url'  => $url,
				'body' => json_decode( (string) ( $args['body'] ?? '{}' ), true ),
			);
			return array(
				'headers'  => array(),
				'body'     => wp_json_encode( array( 'code' => 101, 'message' => 'OK' ) ),
				'response' => array( 'code' => 200, 'message' => 'OK' ),
				'cookies'  => array(),
				'filename' => '',
			);
		};
	}

	/**
	 * A pre_http_request fake replying with a fixed Smaily body {code} at a
	 * fixed HTTP status.
	 */
	private function fake_transport_with_code( int $smaily_code, int $http_code = 200 ): callable {
		return static function ( $pre, $args, $url ) use ( $smaily_code, $http_code ) {
			return array(
				'headers'  => array(),
				'body'     => wp_json_encode( array( 'code' => $smaily_code ) ),
				'response' => array( 'code' => $http_code, 'message' => 'OK' ),
				'cookies'  => array(),
				'filename' => '',
			);
		};
	}

	private function queue_count( string $event_type ): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}smly_plus_event_queue WHERE event_type = %s",
				$event_type
			)
		);
	}

	/**
	 * @return array<string, mixed>|null
	 */
	private function queue_row( string $event_type ): ?array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}smly_plus_event_queue WHERE event_type = %s ORDER BY id DESC LIMIT 1",
				$event_type
			),
			ARRAY_A
		);
		return is_array( $row ) ? $row : null;
	}
}
