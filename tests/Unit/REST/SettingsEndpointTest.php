<?php
/**
 * SettingsEndpoint tests — per-tab payload validation + persistence.
 *
 * @package Smaily\Connect\Tests
 */

declare(strict_types=1);

namespace Smaily\Connect\Tests\Unit\REST;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Smaily\Connect\REST\SettingsEndpoint;
use WP_REST_Request;

final class SettingsEndpointTest extends TestCase {

	/** @var array<string, mixed> Captures update_option() writes. */
	private array $option_writes = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'sanitize_text_field' )->returnArg( 1 );
		Functions\when( 'sanitize_key' )->returnArg( 1 );
		Functions\when( '__' )->returnArg( 1 );

		$this->option_writes = array();
		Functions\when( 'update_option' )->alias(
			function ( string $key, $value ): bool {
				$this->option_writes[ $key ] = $value;
				return true;
			}
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
		unset( $GLOBALS['wpdb'] );
	}

	public function test_rejects_unknown_tab(): void {
		$endpoint = new SettingsEndpoint();

		$request = new WP_REST_Request();
		$request->set_param( 'tab', 'mystery' );
		$request->set_param( 'data', array() );

		$response = $endpoint->handle( $request );

		self::assertSame( 400, $response->get_status() );
		self::assertFalse( $response->get_data()['saved'] );
	}

	public function test_connection_tab_persists_credentials_via_legacy_option(): void {
		$endpoint = new SettingsEndpoint();

		$request = new WP_REST_Request();
		$request->set_param( 'tab', 'connection' );
		$request->set_param(
			'data',
			array(
				'smailyCredentials' => array(
					'subdomain' => 'mypetshop',
					'username'  => 'alice',
					'password'  => 's3cret',
				),
				'multilingualMode'  => 'B',
			)
		);

		$response = $endpoint->handle( $request );

		self::assertSame( 200, $response->get_status() );
		self::assertTrue( $response->get_data()['saved'] );
		self::assertArrayHasKey( 'smaily_connect_api_credentials', $this->option_writes );
		self::assertSame( 'mypetshop', $this->option_writes['smaily_connect_api_credentials']['subdomain'] );
		self::assertSame( 'B', $this->option_writes['smly_plus_multilingual_mode'] );
	}

	public function test_connection_tab_rejects_missing_subdomain(): void {
		$endpoint = new SettingsEndpoint();

		$request = new WP_REST_Request();
		$request->set_param( 'tab', 'connection' );
		$request->set_param(
			'data',
			array(
				'smailyCredentials' => array(
					'subdomain' => '',
					'username'  => 'alice',
					'password'  => 's3cret',
				),
			)
		);

		$response = $endpoint->handle( $request );

		self::assertSame( 400, $response->get_status() );
		self::assertNotEmpty( $response->get_data()['errors'] );
		self::assertSame( 'subdomain', $response->get_data()['errors'][0]['field'] );
		self::assertArrayNotHasKey( 'smaily_connect_api_credentials', $this->option_writes );
	}

	public function test_subscribers_tab_persists_field_list_and_toggles(): void {
		$endpoint = new SettingsEndpoint();

		$request = new WP_REST_Request();
		$request->set_param( 'tab', 'subscribers' );
		$request->set_param(
			'data',
			array(
				'subscriberSyncEnabled'         => true,
				'syncFields'                    => array( 'first_name', 'last_name' ),
				'wordpressSubscriptionCheckbox' => true,
				'checkoutSubscriptionCheckbox'  => false,
			)
		);

		$response = $endpoint->handle( $request );

		self::assertTrue( $response->get_data()['saved'] );
		self::assertSame(
			array( 'first_name', 'last_name' ),
			$this->option_writes['smaily_connect_subscriber_sync_fields']
		);
		self::assertTrue( $this->option_writes['smly_plus_wordpress_subscription_checkbox'] );
		self::assertFalse( $this->option_writes['smaily_connect_checkout_subscription_enabled'] );
	}

	public function test_woocommerce_tab_clamps_cutoff_and_persists_toggles(): void {
		$endpoint        = new SettingsEndpoint();
		$GLOBALS['wpdb'] = $this->fake_wpdb_with_mappings();

		$request = new WP_REST_Request();
		$request->set_param( 'tab', 'woocommerce' );
		$request->set_param(
			'data',
			array(
				'welcomeEnabled'             => true,
				'firstOrderEnabled'          => false,
				'abandonedCartEnabled'       => true,
				'abandonedCartCutoffMinutes' => 5,    // below 10-minute floor
				'automationMappings'         => array(
					array(
						'triggerType'       => 'welcome',
						'language'          => 'et_EE',
						'accountKey'        => 'default',
						'workflowId'        => '42',
						'isDefaultFallback' => true,
					),
				),
			)
		);

		$response = $endpoint->handle( $request );

		self::assertTrue( $response->get_data()['saved'] );
		self::assertTrue( $this->option_writes['smly_plus_welcome_enabled'] );
		self::assertFalse( $this->option_writes['smly_plus_first_order_enabled'] );
		self::assertSame( 10, $this->option_writes['smaily_connect_abandoned_cart_cutoff'] );
	}

	public function test_recommendations_tab_persists_feature_flags(): void {
		$endpoint = new SettingsEndpoint();

		$request = new WP_REST_Request();
		$request->set_param( 'tab', 'recommendations' );
		$request->set_param(
			'data',
			array(
				'recEngineFeatures' => array(
					'syncOrders'      => true,
					'syncCustomers'   => true,
					'syncProducts'    => false,
					'trackCartEvents' => true,
					'trackBrowsing'   => false,
				),
			)
		);

		$response = $endpoint->handle( $request );

		self::assertTrue( $response->get_data()['saved'] );
		self::assertTrue( $this->option_writes['smly_plus_rec_sync_orders'] );
		self::assertFalse( $this->option_writes['smly_plus_rec_sync_products'] );
		self::assertFalse( $this->option_writes['smly_plus_rec_track_browsing'] );
	}

	public function test_permission_check_denies_non_admin(): void {
		Functions\when( 'current_user_can' )->justReturn( false );

		$endpoint = new SettingsEndpoint();
		$result   = $endpoint->permission_check( new WP_REST_Request() );

		self::assertInstanceOf( \WP_Error::class, $result );
	}

	private function fake_wpdb_with_mappings(): object {
		return new class() {
			public string $prefix = 'wp_';
			public array $queries = array();

			public function query( string $sql ): int {
				$this->queries[] = $sql;
				return 1;
			}

			public function prepare( string $sql, ...$args ): string {
				return $sql;
			}
		};
	}
}
