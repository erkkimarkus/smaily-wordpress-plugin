<?php
/**
 * Integration: the Phone / Gender sync-field ticks actually reach Smaily (PRO-1683).
 *
 * The wizard saved the merchant's selection under `phone` / `gender` while the
 * sync has always read `user_phone` / `user_gender` (spec/FIELD_MAPPING.md §2),
 * so both ticks were discarded by the supported-fields intersection before
 * anything was built — no error, no notice, the checkbox simply did nothing.
 *
 * These cases drive the REAL save route + the REAL sync pipelines (live contact
 * sync and the backfill) with only the Smaily transport faked, and assert what
 * lands on the wire: both fields present with the value form existing customer
 * templates expect, absent when unticked, absent when the customer has no
 * value, and still present for a store that saved its selection BEFORE the fix.
 *
 * @package Smaily\Connect\Tests\Integration
 */

declare(strict_types=1);

namespace Smaily\Connect\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Smaily\Connect\Integrations\WooCommerce\HookHandler;
use Smaily\Connect\Smaily\BackfillJob;
use Smaily\Connect\Smaily\Client;
use Smaily\Connect\Smaily\ContactAudience;
use Smaily\Connect\Smaily\EventQueue;
use Smaily\Connect\Smaily\SubscriberPayloadBuilder;
use Smaily\Connect\Tests\Integration\Support\EnvScrub;
use Smaily\Connect\Tests\Integration\Support\RestRequestHelper;
use Smaily\Connect\Wizard\EnvDetector;

final class SubscriberSyncFieldSelectionTest extends TestCase {

	/** @var array<int, int> */
	private array $created_users = array();

	protected function setUp(): void {
		parent::setUp();
		EnvScrub::reset();
		HookHandler::reset_seen();
		RestRequestHelper::login_as_admin();

		update_option( 'smly_plus_setup_completed', true );
		update_option(
			'smaily_connect_api_credentials',
			array(
				'subdomain' => 'testsub',
				'username'  => 'tester',
				'password'  => \Smaily_Connect\Includes\Cypher::encrypt( 'test-password' ),
			)
		);
	}

	protected function tearDown(): void {
		foreach ( $this->created_users as $user_id ) {
			wp_delete_user( $user_id );
		}
		$this->created_users = array();

		HookHandler::reset_seen();
		wp_set_current_user( 0 );

		$bootstrap = \Smaily\Connect\Bootstrap::instance();
		$prop      = new \ReflectionProperty( $bootstrap, 'smaily_clients' );
		$prop->setAccessible( true );
		$prop->setValue( $bootstrap, array() );

		parent::tearDown();
	}

	public function test_ticking_phone_and_gender_sends_them_the_way_templates_expect(): void {
		$this->save_selection( $this->wizard_selection() );

		$female = $this->make_contact(
			array(
				'user_phone'  => '+372 555 12345',
				'user_gender' => '0',
			)
		);
		$male   = $this->make_contact(
			array(
				'user_phone'  => '+372 555 67890',
				'user_gender' => '1',
			)
		);

		$rows = $this->sync_contacts();

		$row = $this->row_for( $rows, $female );
		self::assertSame( '+372 555 12345', $row['user_phone'], 'The phone tick must put the value on the contact.' );
		self::assertSame( 'Female', $row['user_gender'], 'Gender ships as the legacy Female/Male enum existing templates read.' );

		self::assertSame( 'Male', $this->row_for( $rows, $male )['user_gender'] );
	}

	public function test_unticking_them_sends_neither_so_smaily_keeps_what_it_has(): void {
		$this->save_selection( array( 'first_name', 'last_name' ) );

		$user_id = $this->make_contact(
			array(
				'user_phone'  => '+372 555 12345',
				'user_gender' => '0',
			)
		);

		$row = $this->row_for( $this->sync_contacts(), $user_id );

		self::assertArrayNotHasKey( 'user_phone', $row, 'An unticked field must be absent — an empty value would WIPE what Smaily holds.' );
		self::assertArrayNotHasKey( 'user_gender', $row );
	}

	public function test_a_customer_without_the_values_omits_the_fields_rather_than_blanking_them(): void {
		$this->save_selection( $this->wizard_selection() );

		$user_id = $this->make_contact( array() );

		$row = $this->row_for( $this->sync_contacts(), $user_id );

		self::assertArrayNotHasKey( 'user_phone', $row, 'No source value → omitted, so an existing Smaily value survives.' );
		self::assertArrayNotHasKey( 'user_gender', $row );
	}

	public function test_a_selection_saved_before_the_fix_still_sends_both_fields(): void {
		// Exactly what the pre-PRO-1683 wizard wrote to the option.
		update_option(
			SubscriberPayloadBuilder::OPTION_SYNC_FIELDS,
			array( 'first_name', 'last_name', 'phone', 'birthday', 'gender' )
		);

		$user_id = $this->make_contact(
			array(
				'user_phone'  => '+372 555 12345',
				'user_gender' => '1',
			)
		);

		$row = $this->row_for( $this->sync_contacts(), $user_id );

		self::assertSame( '+372 555 12345', $row['user_phone'], 'An upgraded store must keep sending the same fields without re-saving its settings.' );
		self::assertSame( 'Male', $row['user_gender'] );

		// …and the wizard shows those ticks, so the merchant can also turn
		// them back off — the checkbox keys come from the same canonical set.
		$saved = ( new EnvDetector() )->saved_settings();
		self::assertContains( 'user_phone', $saved['syncFields'] );
		self::assertContains( 'user_gender', $saved['syncFields'] );
	}

	public function test_the_contact_backfill_sends_them_too(): void {
		$this->save_selection( $this->wizard_selection() );

		$user_id = $this->make_contact(
			array(
				'user_phone'  => '+372 555 24680',
				'user_gender' => '0',
			)
		);

		// The backfill POSTs the sync mode's audience — consent mode means opted in.
		update_user_meta( $user_id, ContactAudience::OPTIN_META, 1 );

		$rows = $this->run_backfill();
		$row  = $this->row_for( $rows, $user_id );

		self::assertSame( '+372 555 24680', $row['user_phone'], 'Backfill and live sync build from the same selection.' );
		self::assertSame( 'Female', $row['user_gender'] );
	}

	// --- helpers -------------------------------------------------------------

	/**
	 * The checkbox list the wizard REALLY offers, read from the admin source —
	 * not a copy of it. A copy would keep passing while the shipped wizard
	 * saved names the sync discards, which is precisely the bug.
	 *
	 * @return array<int, string>
	 */
	private function wizard_selection(): array {
		$source = (string) file_get_contents( SMAILY_CONNECT_PLUGIN_PATH . 'admin/src/state/types.ts' );

		$matched = preg_match( '/export const DEFAULT_SYNC_FIELDS = \[(.*?)\]/s', $source, $matches );
		self::assertSame( 1, $matched, 'DEFAULT_SYNC_FIELDS must stay findable in admin/src/state/types.ts.' );

		preg_match_all( "/'([a-z_]+)'/", $matches[1], $names );
		self::assertContains( 'user_phone', $names[1], 'The wizard must offer a Phone checkbox.' );
		self::assertContains( 'user_gender', $names[1], 'The wizard must offer a Gender checkbox.' );

		return $names[1];
	}

	/**
	 * Save the subscribers tab through the REAL wizard/settings route — the
	 * option shape under test is whatever that route writes.
	 *
	 * @param array<int, string> $fields
	 */
	private function save_selection( array $fields ): void {
		$response = RestRequestHelper::post(
			'/settings',
			array(
				'tab'  => 'subscribers',
				'data' => array(
					'subscriberSyncEnabled' => true,
					'syncFields'            => $fields,
				),
			)
		);
		self::assertSame( 200, $response->get_status() );
	}

	/**
	 * A registered, opted-in customer (the default consent-mode audience).
	 *
	 * @param array<string, string> $meta Profile meta the merchant's store collected.
	 */
	private function make_contact( array $meta ): int {
		$slug    = wp_generate_password( 6, false );
		$user_id = wp_insert_user(
			array(
				'user_login' => 'smly_fields_' . $slug,
				'user_email' => 'fields-' . $slug . '@example.test',
				'user_pass'  => wp_generate_password( 20 ),
				'first_name' => 'Mari',
			)
		);
		self::assertIsInt( $user_id );
		$this->created_users[] = $user_id;

		foreach ( $meta as $key => $value ) {
			update_user_meta( $user_id, $key, $value );
		}

		return $user_id;
	}

	/**
	 * Drive the live contact-sync pipeline: opting in is a real consent change,
	 * which enqueues a `contact.sync` row the flusher then POSTs.
	 *
	 * @return array<int, array<string, mixed>> Every subscriber row that reached the transport.
	 */
	private function sync_contacts(): array {
		foreach ( $this->created_users as $user_id ) {
			update_user_meta( $user_id, ContactAudience::OPTIN_META, 1 );
		}

		$bodies = $this->capture_posts(
			static function (): void {
				do_action( EventQueue::FLUSH_HOOK );
			}
		);

		$rows = array();
		foreach ( $bodies as $body ) {
			if ( is_array( $body ) && isset( $body[0] ) && is_array( $body[0] ) ) {
				foreach ( $body as $row ) {
					$rows[] = $row;
				}
			}
		}

		return $rows;
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private function run_backfill(): array {
		$job = new BackfillJob( new Client( 'testsub', 'tester', 'pw' ) );

		$bodies = $this->capture_posts(
			static function () use ( $job ): void {
				$job->start();
				$guard = 0;
				do {
					$result = $job->process_batch( 200 );
				} while ( ! $result['completed'] && ++$guard < 50 );
			}
		);

		$rows = array();
		foreach ( $bodies as $body ) {
			if ( is_array( $body ) && isset( $body[0] ) && is_array( $body[0] ) ) {
				foreach ( $body as $row ) {
					$rows[] = $row;
				}
			}
		}

		return $rows;
	}

	/**
	 * @param callable(): void $run
	 *
	 * @return array<int, mixed> Every POST body the run handed the transport.
	 */
	private function capture_posts( callable $run ): array {
		$bodies = array();
		$fake   = static function ( $pre, $args ) use ( &$bodies ) {
			$bodies[] = isset( $args['body'] ) ? $args['body'] : null;
			return array(
				'headers'  => array(),
				'body'     => wp_json_encode(
					array(
						'code'    => 101,
						'message' => 'OK',
					)
				),
				'response' => array(
					'code'    => 200,
					'message' => 'OK',
				),
				'cookies'  => array(),
				'filename' => '',
			);
		};

		add_filter( 'pre_http_request', $fake, 10, 2 );
		try {
			$run();
		} finally {
			remove_filter( 'pre_http_request', $fake, 10 );
		}

		return $bodies;
	}

	/**
	 * @param array<int, array<string, mixed>> $rows
	 *
	 * @return array<string, mixed>
	 */
	private function row_for( array $rows, int $user_id ): array {
		$email = (string) get_userdata( $user_id )->user_email;
		foreach ( $rows as $row ) {
			if ( isset( $row['email'] ) && (string) $row['email'] === $email ) {
				return $row;
			}
		}

		self::fail( 'The contact for ' . $email . ' never reached the Smaily transport.' );
	}
}
