<?php
/**
 * Integration: legacy abandoned-cart email pass — backlog guard + per-cart errors.
 *
 * @package Smaily\Connect\Tests\Integration
 */

declare(strict_types=1);

namespace Smaily\Connect\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Smaily\Connect\Tests\Integration\Support\EnvScrub;

/**
 * What F3-37 bug class this catches:
 *
 *   The legacy email pass drains `cart_status='abandoned' AND mail_sent IS
 *   NULL` with NO time bound, and the pre-fix loop ABORTED on the first API
 *   failure without marking anything. A dormant scheduler period (dead
 *   WP-Cron — the pilot site's actual state across the 1.x era) therefore
 *   accumulates a backlog that the first tick after re-arming mass-mails.
 *   The pilot's day-1 mass email came from a third-party cart plugin's
 *   identical backlog drain, but OUR pipeline carries the same structural
 *   flaw and the pilot DB has it enabled — this test pins the guard that
 *   makes re-arming safe.
 *
 *   Covered here, against the real table + real hook wiring:
 *   - stale carts (cart_updated older than the window) are expired —
 *     marked mail_sent WITHOUT any send attempt;
 *   - fresh carts whose send FAILS stay mail_sent NULL (retry next tick),
 *     and the failure does not abort processing of other carts;
 *   - the format seam: cart_updated reads back 'Y-m-d H:i:s' while the
 *     writer used the Z-form — the guard compares epochs, not strings
 *     (a string compare wrongly expires every same-day cart: ' ' < 'T').
 *
 *   The Smaily client is deliberately UNCONFIGURED (EnvScrub wipes the
 *   credentials), so every real send attempt here fails fast — meaning a
 *   cart marked mail_sent can ONLY have been marked by the guard path.
 */
final class AbandonedCartGuardTest extends TestCase {

	private const TABLE = 'smaily_connect_abandoned_carts';

	/** @var array<int, int> */
	private array $created_users = array();

	protected function setUp(): void {
		parent::setUp();
		EnvScrub::reset();
		$this->ensure_cart_table();
		$this->wipe_carts();

		// Legacy feature flag ON (the pilot DB state) + a nominal autoresponder.
		update_option(
			'smaily_connect_abandoned_cart_status',
			array(
				'enabled'          => true,
				'autoresponder_id' => 999,
			)
		);
	}

	protected function tearDown(): void {
		$this->wipe_carts();
		foreach ( $this->created_users as $user_id ) {
			wp_delete_user( $user_id );
		}
		$this->created_users = array();
		parent::tearDown();
	}

	public function test_stale_cart_is_expired_without_sending_and_fresh_failure_does_not_block_it(): void {
		// Fresh cart first (lower customer_id → typical PK iteration order):
		// its send FAILS (no Smaily credentials). With the pre-fix abort-on-
		// error loop, the stale cart after it would never be reached.
		$fresh_user = $this->make_user( 'fresh-cart' );
		$stale_user = $this->make_user( 'stale-cart' );

		$this->seed_cart( $fresh_user, gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS ) );
		$this->seed_cart( $stale_user, gmdate( 'Y-m-d H:i:s', time() - 3 * DAY_IN_SECONDS ) );

		do_action( 'smaily_connect_cron_abandoned_carts_email' );

		self::assertSame( '1', $this->mail_sent_of( $stale_user ), 'Stale cart must be expired by the guard — even when an earlier cart in the loop failed to send.' );
		self::assertNull( $this->mail_sent_of( $fresh_user ), 'A fresh cart whose send failed stays unmarked (retried next tick).' );
	}

	public function test_same_day_cart_is_not_wrongly_expired(): void {
		// The format seam: a string compare of MySQL 'Y-m-d H:i:s' against a
		// Z-form threshold expires EVERY same-day cart (' ' sorts before
		// 'T'). The guard must keep a 2-hour-old cart in the sendable set —
		// observable as mail_sent staying NULL (its send fails; expiry would
		// have set 1).
		$user = $this->make_user( 'sameday-cart' );
		$this->seed_cart( $user, gmdate( 'Y-m-d H:i:s', time() - 2 * HOUR_IN_SECONDS ) );

		do_action( 'smaily_connect_cron_abandoned_carts_email' );

		self::assertNull( $this->mail_sent_of( $user ), 'A 2h-old cart is inside the 24h window — it must be attempted (and here fail), never expired.' );
	}

	// --- helpers -------------------------------------------------------------

	/**
	 * The tests environment never runs the activation hook, so the legacy
	 * cart table may be missing. Create it through the REAL Lifecycle
	 * schema (private method, via reflection) so this fixture can never
	 * drift from production DDL.
	 */
	private function ensure_cart_table(): void {
		$lifecycle = new \Smaily_Connect\Includes\Lifecycle();
		$method    = new \ReflectionMethod( $lifecycle, 'create_woocommerce_tables' );
		$method->setAccessible( true );
		$method->invoke( $lifecycle );
	}

	private function make_user( string $slug ): int {
		$user_id = wp_insert_user(
			array(
				'user_login' => 'smly_test_' . $slug . '_' . wp_generate_password( 6, false ),
				'user_email' => $slug . '-' . wp_generate_password( 6, false ) . '@example.test',
				'user_pass'  => wp_generate_password( 20 ),
			)
		);
		self::assertIsInt( $user_id );
		$this->created_users[] = $user_id;
		return $user_id;
	}

	private function seed_cart( int $customer_id, string $cart_updated ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$inserted = $wpdb->insert(
			$wpdb->prefix . self::TABLE,
			array(
				'customer_id'         => $customer_id,
				'cart_updated'        => $cart_updated,
				'cart_content'        => maybe_serialize( array( 'item' => array( 'product_id' => 1, 'quantity' => 1 ) ) ),
				'cart_status'         => 'abandoned',
				'cart_abandoned_time' => gmdate( 'Y-m-d H:i:s' ),
				'mail_sent'           => null,
			)
		);
		self::assertNotFalse( $inserted, 'Cart seed insert failed: ' . $wpdb->last_error );
	}

	private function mail_sent_of( int $customer_id ): ?string {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return $wpdb->get_var(
			$wpdb->prepare(
				"SELECT mail_sent FROM {$wpdb->prefix}smaily_connect_abandoned_carts WHERE customer_id = %d",
				$customer_id
			)
		);
	}

	private function wipe_carts(): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query( "DELETE FROM {$wpdb->prefix}smaily_connect_abandoned_carts" );
	}
}
