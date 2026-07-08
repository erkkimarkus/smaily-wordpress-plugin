<?php
/**
 * Integration: the Settings-writer ↔ legacy-cron-reader seam for the
 * abandoned-cart status option (F3-54).
 *
 * @package Smaily\Connect\Tests\Integration
 */

declare(strict_types=1);

namespace Smaily\Connect\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Smaily\Connect\Tests\Integration\Support\EnvScrub;
use Smaily\Connect\Tests\Integration\Support\RestRequestHelper;
use Smaily\Connect\Wizard\EnvDetector;

/**
 * What F3-54 bug class this pins (Prike, 2026-07-08):
 *
 *   The new Settings/wizard wrote `smaily_connect_abandoned_cart_status`
 *   as a BARE BOOLEAN (stored by WP as '1'/''), while the legacy cron
 *   pass reads it as an ARRAY — `$status['enabled']` on the stored string
 *   is a PHP 8 TypeError that fataled the abandoned-cart tick every 15
 *   minutes, and toggling the feature off just wrote the other string.
 *   The old guard test never saw it because it seeded the option ITSELF
 *   in the legacy array shape — the fixture's shape, not the real
 *   writer's shape (the same class of gap as mock-vs-live).
 *
 *   Pinned here, writer and reader in the same test:
 *   - the REAL SettingsEndpoint save produces a shape the REAL cron pass
 *     survives (and the option is the array shape on disk);
 *   - a pre-3.4.3 corrupted value ('1'/'') no longer fatals the pass;
 *   - a wizard-mapped abandoned-cart workflow sends via AutomationRouter;
 *   - a pre-wizard store's legacy array autoresponder_id still sends
 *     (fallback), and a Settings re-save PRESERVES that id;
 *   - EnvDetector hydrates enabled from the array shape (a disabled
 *     array used to (bool)-cast to TRUE).
 */
final class AbandonedCartSettingsSeamTest extends TestCase {

	private const TABLE         = 'smaily_connect_abandoned_carts';
	private const STATUS_OPTION = 'smaily_connect_abandoned_cart_status';

	/** @var array<int, int> */
	private array $created_users = array();

	protected function setUp(): void {
		parent::setUp();
		EnvScrub::reset();
		$this->ensure_cart_table();
		$this->wipe_carts();
		RestRequestHelper::login_as_admin();
	}

	protected function tearDown(): void {
		$this->wipe_carts();
		foreach ( $this->created_users as $user_id ) {
			wp_delete_user( $user_id );
		}
		$this->created_users = array();

		// Bootstrap caches Smaily clients per account key; drop any client
		// built from this test's seeded credentials so later tests see the
		// post-EnvScrub (unconfigured) state, not a warm client.
		$bootstrap = \Smaily\Connect\Bootstrap::instance();
		$prop      = new \ReflectionProperty( $bootstrap, 'smaily_clients' );
		$prop->setAccessible( true );
		$prop->setValue( $bootstrap, array() );

		parent::tearDown();
	}

	public function test_settings_save_writes_the_array_shape_and_the_tick_survives(): void {
		$response = RestRequestHelper::post(
			'/settings',
			array(
				'tab'  => 'woocommerce',
				'data' => array( 'abandonedCartEnabled' => true ),
			)
		);
		self::assertSame( 200, $response->get_status() );

		$stored = get_option( self::STATUS_OPTION );
		self::assertIsArray( $stored, 'The Settings writer must produce the array shape the legacy cron pass offsets into.' );
		self::assertTrue( $stored['enabled'] );
		self::assertArrayHasKey( 'autoresponder_id', $stored );

		// The real reader survives the real writer's output. No workflow is
		// mapped, so the cart stays pending (unmapped is a config gap, not
		// a poison row) — reaching the assert proves the tick didn't fatal.
		$user = $this->make_user( 'seam-real-writer' );
		$this->seed_cart( $user );

		do_action( 'smaily_connect_cron_abandoned_carts_email' );

		self::assertNull( $this->mail_sent_of( $user ) );
	}

	public function test_pre_343_boolean_shapes_no_longer_fatal_the_tick(): void {
		$user = $this->make_user( 'seam-corrupt' );
		$this->seed_cart( $user );

		// What the pre-3.4.3 Settings actually left behind: true → '1'.
		update_option( self::STATUS_OPTION, true );
		do_action( 'smaily_connect_cron_abandoned_carts_email' );
		self::assertNull( $this->mail_sent_of( $user ), 'Enabled-as-string: pass runs (no mapping → pending), no PHP 8 offset fatal.' );

		// The failed "turn it off in admin" path: false → ''.
		update_option( self::STATUS_OPTION, false );
		do_action( 'smaily_connect_cron_abandoned_carts_email' );
		self::assertNull( $this->mail_sent_of( $user ), 'Disabled-as-string: pass exits cleanly at the guard.' );
	}

	public function test_mapped_workflow_sends_via_the_automation_router(): void {
		// The REAL wizard payload: toggle on + an abandoned_cart mapping row.
		$response = RestRequestHelper::post(
			'/settings',
			array(
				'tab'  => 'woocommerce',
				'data' => array(
					'abandonedCartEnabled' => true,
					'automationMappings'   => array(
						array(
							'triggerType'       => 'abandoned_cart',
							'language'          => 'default',
							'accountKey'        => 'default',
							'workflowId'        => '4242',
							'isDefaultFallback' => true,
						),
					),
				),
			)
		);
		self::assertSame( 200, $response->get_status() );

		$this->seed_credentials();

		$captured = null;
		$fake     = $this->fake_transport( $captured );

		$user = $this->make_user( 'seam-mapped' );
		$this->seed_cart( $user );

		add_filter( 'pre_http_request', $fake, 10, 2 );
		try {
			do_action( 'smaily_connect_cron_abandoned_carts_email' );
		} finally {
			remove_filter( 'pre_http_request', $fake, 10 );
		}

		self::assertIsArray( $captured, 'The mapped workflow must reach the transport.' );
		self::assertSame( '4242', (string) $captured['autoresponder'], 'The workflow id must come from the automation-mapping row, not the legacy option.' );
		self::assertSame( '1', $this->mail_sent_of( $user ), 'A routed send marks the cart sent.' );
	}

	public function test_legacy_autoresponder_id_is_the_fallback_when_no_mapping_exists(): void {
		// A pre-wizard upgraded store: array shape with a real id, no
		// mapping rows (EnvScrub truncated the table in setUp).
		update_option(
			self::STATUS_OPTION,
			array(
				'enabled'          => true,
				'autoresponder_id' => 88,
			)
		);
		$this->seed_credentials();

		$captured = null;
		$fake     = $this->fake_transport( $captured );

		$user = $this->make_user( 'seam-fallback' );
		$this->seed_cart( $user );

		add_filter( 'pre_http_request', $fake, 10, 2 );
		try {
			do_action( 'smaily_connect_cron_abandoned_carts_email' );
		} finally {
			remove_filter( 'pre_http_request', $fake, 10 );
		}

		self::assertIsArray( $captured, 'The legacy fallback must reach the transport.' );
		self::assertSame( '88', (string) $captured['autoresponder'], 'With no mapping row the legacy array autoresponder_id drives the send.' );
		self::assertSame( '1', $this->mail_sent_of( $user ) );
	}

	public function test_settings_resave_preserves_the_legacy_autoresponder_id_and_hydrate_reads_the_array(): void {
		update_option(
			self::STATUS_OPTION,
			array(
				'enabled'          => false,
				'autoresponder_id' => 55,
			)
		);

		// Hydrate pin: a DISABLED array used to (bool)-cast to TRUE.
		$saved = ( new EnvDetector() )->saved_settings();
		self::assertFalse( $saved['abandonedCartEnabled'], 'A disabled legacy array must hydrate as false — (bool) on a non-empty array is always true.' );

		$response = RestRequestHelper::post(
			'/settings',
			array(
				'tab'  => 'woocommerce',
				'data' => array( 'abandonedCartEnabled' => true ),
			)
		);
		self::assertSame( 200, $response->get_status() );

		self::assertSame(
			array(
				'enabled'          => true,
				'autoresponder_id' => 55,
			),
			get_option( self::STATUS_OPTION ),
			'A Settings save must not destroy the upgraded store\'s legacy autoresponder id — it is the no-mapping fallback.'
		);
	}

	// --- helpers -------------------------------------------------------------

	/**
	 * Seed complete legacy credentials (Bootstrap::smaily_client requires
	 * subdomain + username + a DECRYPTABLE non-empty password —
	 * CredentialSet::is_complete). Encrypted through the real Cypher so
	 * Credentials::decrypt_password round-trips.
	 */
	private function seed_credentials(): void {
		update_option(
			'smaily_connect_api_credentials',
			array(
				'subdomain' => 'testsub',
				'username'  => 'tester',
				'password'  => \Smaily_Connect\Includes\Cypher::encrypt( 'test-password' ),
			)
		);
	}

	/**
	 * A pre_http_request fake: captures the outgoing POST body into
	 * $captured (by reference) and returns a Smaily 101 success.
	 *
	 * @param mixed $captured By-ref capture target.
	 */
	private function fake_transport( &$captured ): callable {
		return static function ( $pre, $args ) use ( &$captured ) {
			$captured = isset( $args['body'] ) ? $args['body'] : null;
			return array(
				'headers'  => array(),
				'body'     => wp_json_encode( array( 'code' => 101, 'message' => 'OK' ) ),
				'response' => array(
					'code'    => 200,
					'message' => 'OK',
				),
				'cookies'  => array(),
				'filename' => '',
			);
		};
	}

	/**
	 * Same reflection route as AbandonedCartGuardTest: create the legacy
	 * cart table through the REAL Lifecycle schema so the fixture can't
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
				'user_login' => 'smly_seam_' . $slug . '_' . wp_generate_password( 6, false ),
				'user_email' => $slug . '-' . wp_generate_password( 6, false ) . '@example.test',
				'user_pass'  => wp_generate_password( 20 ),
			)
		);
		self::assertIsInt( $user_id );
		$this->created_users[] = $user_id;
		return $user_id;
	}

	private function seed_cart( int $customer_id ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$inserted = $wpdb->insert(
			$wpdb->prefix . self::TABLE,
			array(
				'customer_id'         => $customer_id,
				'cart_updated'        => gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS ),
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
