<?php
/**
 * REST endpoint persisting Settings tab payloads.
 *
 * @package Smaily\Connect\REST
 */

declare(strict_types=1);

namespace Smaily\Connect\REST;

defined( 'ABSPATH' ) || exit;

use Smaily\Connect\Constants;
use Smaily\Connect\Settings\Credentials;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * `POST /wp-json/smaily-connect/v1/settings`
 *
 * Body:
 *   {
 *     "tab":  "connection" | "subscribers" | "woocommerce" | "recommendations",
 *     "data": { ...tab-specific payload }
 *   }
 *
 * Response:
 *   200 OK
 *   {
 *     "saved":  true,
 *     "errors": []
 *   }
 * — or —
 *   400 Bad Request
 *   {
 *     "saved":  false,
 *     "errors": [ { "field": "subdomain", "message": "Required" } ]
 *   }
 *
 * Each tab has its own validate + persist pair. Connection routes
 * credentials through the legacy Smaily_Connect\Includes\Options
 * writer (which handles encryption) so legacy code paths (CF7, Gutenberg
 * blocks) still see the same values; new BETA options live under the
 * smly_plus_* prefix and write straight via update_option.
 *
 * Auth: nonce + manage_options. Per-tab field sanitisation happens
 * inside each handler — we don't trust WP's args sanitize_callback alone
 * because the payload shape varies per tab.
 */
class SettingsEndpoint {

	public const ROUTE = '/settings';

	private const VALID_TABS = array( 'connection', 'subscribers', 'woocommerce', 'recommendations' );

	private const LEGACY_OPTION_API_CREDENTIALS = 'smaily_connect_api_credentials';
	private const LEGACY_OPTION_SYNC_ENABLED    = 'smaily_connect_subscriber_sync_enabled';
	private const LEGACY_OPTION_SYNC_FIELDS     = 'smaily_connect_subscriber_sync_fields';
	private const LEGACY_OPTION_CHECKOUT_OPTIN  = 'smaily_connect_checkout_subscription_enabled';
	private const LEGACY_OPTION_CART_CUTOFF     = 'smaily_connect_abandoned_cart_cutoff';
	private const LEGACY_OPTION_CART_STATUS     = 'smaily_connect_abandoned_cart_status';

	public function register(): void {
		register_rest_route(
			Constants::REST_NAMESPACE,
			self::ROUTE,
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle' ),
				'permission_callback' => array( $this, 'permission_check' ),
				'args'                => array(
					'tab'  => array(
						'type'     => 'string',
						'required' => true,
					),
					'data' => array(
						'type'     => 'object',
						'required' => true,
					),
				),
			)
		);
	}

	/**
	 * @return bool|WP_Error
	 */
	public function permission_check( WP_REST_Request $request ) {
		if ( ! current_user_can( Constants::CAPABILITY ) ) {
			return new WP_Error(
				'smaily_connect_forbidden',
				__( 'You do not have permission to save Smaily Connect settings.', 'smaily-connect' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	public function handle( WP_REST_Request $request ): WP_REST_Response {
		$tab  = is_string( $request->get_param( 'tab' ) ) ? $request->get_param( 'tab' ) : '';
		$data = $request->get_param( 'data' );

		if ( ! in_array( $tab, self::VALID_TABS, true ) ) {
			return $this->error_response(
				array(
					array(
						'field'   => 'tab',
						'message' => __( 'Unknown settings tab.', 'smaily-connect' ),
					),
				),
				400
			);
		}

		if ( ! is_array( $data ) ) {
			return $this->error_response(
				array(
					array(
						'field'   => 'data',
						'message' => __( 'Payload must be an object.', 'smaily-connect' ),
					),
				),
				400
			);
		}

		switch ( $tab ) {
			case 'connection':
				return $this->save_connection( $data );
			case 'subscribers':
				return $this->save_subscribers( $data );
			case 'woocommerce':
				return $this->save_woocommerce( $data );
			case 'recommendations':
				return $this->save_recommendations( $data );
			default:
				// Defensive — in_array() above forbids reaching here, but the
				// switch needs a terminal arm for return-type narrowing.
				return $this->error_response( array(), 400 );
		}
	}

	/**
	 * @param array<string, mixed> $data
	 */
	private function save_connection( array $data ): WP_REST_Response {
		$errors = array();

		$smaily       = isset( $data['smailyCredentials'] ) && is_array( $data['smailyCredentials'] )
			? $data['smailyCredentials']
			: array();
		$subdomain    = isset( $smaily['subdomain'] ) ? sanitize_text_field( (string) $smaily['subdomain'] ) : '';
		$username     = isset( $smaily['username'] ) ? sanitize_text_field( (string) $smaily['username'] ) : '';
		$password     = isset( $smaily['password'] ) ? (string) $smaily['password'] : '';
		$mode_raw     = isset( $data['multilingualMode'] ) ? (string) $data['multilingualMode'] : 'single';
		$valid_modes  = array( 'single', 'A', 'B', 'C' );
		$multilingual = in_array( $mode_raw, $valid_modes, true ) ? $mode_raw : 'single';

		if ( $subdomain === '' || $username === '' ) {
			$errors[] = array(
				'field'   => 'subdomain',
				'message' => __( 'Subdomain + username are required.', 'smaily-connect' ),
			);
		}

		if ( ! empty( $errors ) ) {
			return $this->error_response( $errors, 400 );
		}

		$encrypted_password = $this->encrypt_password( $password );

		update_option(
			self::LEGACY_OPTION_API_CREDENTIALS,
			array(
				'subdomain' => $subdomain,
				'username'  => $username,
				'password'  => $encrypted_password,
			)
		);

		update_option( 'smly_plus_multilingual_mode', $multilingual );

		// Mode A per-language credentials.
		$per_language = isset( $data['perLanguageAccounts'] ) && is_array( $data['perLanguageAccounts'] )
			? $data['perLanguageAccounts']
			: array();
		foreach ( $per_language as $account ) {
			if ( ! is_array( $account ) ) {
				continue;
			}
			$account_key   = isset( $account['accountKey'] ) ? sanitize_key( (string) $account['accountKey'] ) : '';
			$creds         = isset( $account['credentials'] ) && is_array( $account['credentials'] )
				? $account['credentials']
				: array();
			$acc_subdomain = isset( $creds['subdomain'] ) ? sanitize_text_field( (string) $creds['subdomain'] ) : '';
			$acc_username  = isset( $creds['username'] ) ? sanitize_text_field( (string) $creds['username'] ) : '';
			$acc_password  = isset( $creds['password'] ) ? (string) $creds['password'] : '';

			if ( $account_key === '' ) {
				continue;
			}

			update_option(
				Credentials::PHASE2_OPTION_PREFIX . $account_key,
				array(
					'subdomain' => $acc_subdomain,
					'username'  => $acc_username,
					'password'  => $this->encrypt_password( $acc_password ),
				)
			);
		}

		$fallback_key = isset( $data['defaultFallbackAccountKey'] )
			? sanitize_key( (string) $data['defaultFallbackAccountKey'] )
			: 'default';
		update_option( 'smly_plus_default_fallback_account', $fallback_key );

		return $this->success_response();
	}

	/**
	 * @param array<string, mixed> $data
	 */
	private function save_subscribers( array $data ): WP_REST_Response {
		$sync_enabled   = ! empty( $data['subscriberSyncEnabled'] );
		$fields_raw     = isset( $data['syncFields'] ) && is_array( $data['syncFields'] ) ? $data['syncFields'] : array();
		$fields         = array_values(
			array_filter(
				array_map(
					static fn ( $f ): string => sanitize_key( (string) $f ),
					$fields_raw
				)
			)
		);
		$wp_optin       = ! empty( $data['wordpressSubscriptionCheckbox'] );
		$checkout_optin = ! empty( $data['checkoutSubscriptionCheckbox'] );

		update_option( self::LEGACY_OPTION_SYNC_ENABLED, $sync_enabled );
		update_option( self::LEGACY_OPTION_SYNC_FIELDS, $fields );
		update_option( 'smly_plus_wordpress_subscription_checkbox', $wp_optin );
		update_option( self::LEGACY_OPTION_CHECKOUT_OPTIN, $checkout_optin );

		return $this->success_response();
	}

	/**
	 * @param array<string, mixed> $data
	 */
	private function save_woocommerce( array $data ): WP_REST_Response {
		$welcome  = ! empty( $data['welcomeEnabled'] );
		$first    = ! empty( $data['firstOrderEnabled'] );
		$cart     = ! empty( $data['abandonedCartEnabled'] );
		$cutoff   = isset( $data['abandonedCartCutoffMinutes'] ) ? (int) $data['abandonedCartCutoffMinutes'] : 30;
		$cutoff   = max( 10, $cutoff );
		$mappings = isset( $data['automationMappings'] ) && is_array( $data['automationMappings'] )
			? $data['automationMappings']
			: array();

		update_option( 'smly_plus_welcome_enabled', $welcome );
		update_option( 'smly_plus_first_order_enabled', $first );
		update_option( self::LEGACY_OPTION_CART_STATUS, $cart );
		update_option( self::LEGACY_OPTION_CART_CUTOFF, $cutoff );

		// Replace the mapping table contents in one shot — Settings always
		// sends the full list it wants persisted, no partial updates.
		$this->replace_automation_mappings( $mappings );

		return $this->success_response();
	}

	/**
	 * @param array<int, array<string, mixed>> $rows
	 */
	private function replace_automation_mappings( array $rows ): void {
		global $wpdb;
		$table = $wpdb->prefix . 'smly_plus_automation_mapping';

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( "TRUNCATE TABLE {$table}" );

		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$trigger     = isset( $row['triggerType'] ) ? sanitize_key( (string) $row['triggerType'] ) : '';
			$language    = isset( $row['language'] ) ? sanitize_text_field( (string) $row['language'] ) : '';
			$account_key = isset( $row['accountKey'] ) ? sanitize_key( (string) $row['accountKey'] ) : 'default';
			$workflow_id = isset( $row['workflowId'] ) ? (string) $row['workflowId'] : '';
			$is_fallback = ! empty( $row['isDefaultFallback'] ) ? 1 : 0;

			if ( $trigger === '' || $language === '' || $workflow_id === '' ) {
				continue;
			}

			$wpdb->query(
				$wpdb->prepare(
					"INSERT INTO {$table} (trigger_type, language, account_key, workflow_id, is_default_fallback) VALUES (%s, %s, %s, %s, %d)",
					$trigger,
					$language,
					$account_key,
					$workflow_id,
					$is_fallback
				)
			);
		}
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * @param array<string, mixed> $data
	 */
	private function save_recommendations( array $data ): WP_REST_Response {
		$features = isset( $data['recEngineFeatures'] ) && is_array( $data['recEngineFeatures'] )
			? $data['recEngineFeatures']
			: array();

		update_option( 'smly_plus_rec_sync_orders', ! empty( $features['syncOrders'] ) );
		update_option( 'smly_plus_rec_sync_customers', ! empty( $features['syncCustomers'] ) );
		update_option( 'smly_plus_rec_sync_products', ! empty( $features['syncProducts'] ) );
		update_option( 'smly_plus_rec_track_cart_events', ! empty( $features['trackCartEvents'] ) );
		update_option( 'smly_plus_rec_track_browsing', ! empty( $features['trackBrowsing'] ) );

		return $this->success_response();
	}

	private function encrypt_password( string $plain ): string {
		if ( $plain === '' ) {
			return '';
		}
		if ( class_exists( '\\Smaily_Connect\\Includes\\Cypher' ) ) {
			return (string) \Smaily_Connect\Includes\Cypher::encrypt( $plain );
		}
		return $plain; // Defensive fallback — shouldn't happen post-bootstrap.
	}

	private function success_response(): WP_REST_Response {
		return new WP_REST_Response(
			array(
				'saved'  => true,
				'errors' => array(),
			),
			200
		);
	}

	/**
	 * @param array<int, array{field: string, message: string}> $errors
	 */
	private function error_response( array $errors, int $status ): WP_REST_Response {
		return new WP_REST_Response(
			array(
				'saved'  => false,
				'errors' => $errors,
			),
			$status
		);
	}
}
