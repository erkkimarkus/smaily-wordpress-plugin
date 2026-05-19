<?php
/**
 * REST endpoint that verifies Smaily API credentials.
 *
 * @package Smaily\Connect\REST
 */

declare(strict_types=1);

namespace Smaily\Connect\REST;

defined( 'ABSPATH' ) || exit;

use Smaily\Connect\Constants;
use Smaily\Connect\Smaily\Client;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * `POST /wp-json/smaily-connect/v1/test-smaily`
 *
 * Body:
 *   {
 *     "subdomain": "demo",
 *     "username":  "alice",
 *     "password":  "s3cret"
 *   }
 *
 * Response:
 *   200 OK
 *   {
 *     "connected": true|false,
 *     "error":     "Optional human-readable failure reason"
 *   }
 *
 * Auth: nonce (`wp_rest`) + `manage_options` capability. Credentials in
 * the request body are NOT persisted — this endpoint exists so the
 * Settings UI can validate a credential set before saving it. Persisting
 * to `smaily_connect_api_credentials` is handled by the existing
 * Smaily_Connect\Includes\Options writer once the user clicks Save.
 *
 * Not final: tests subclass to override build_client() with a Smaily\Client
 * mock. Same rationale as Smaily\Client itself.
 */
class TestConnectionEndpoint {

	public const ROUTE = '/test-smaily';

	public function register(): void {
		register_rest_route(
			Constants::REST_NAMESPACE,
			self::ROUTE,
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle' ),
				'permission_callback' => array( $this, 'permission_check' ),
				'args'                => array(
					'subdomain' => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					),
					'username'  => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					),
					'password'  => array(
						'type'     => 'string',
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
				__( 'You do not have permission to test the Smaily connection.', 'smaily-connect' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	public function handle( WP_REST_Request $request ): WP_REST_Response {
		$subdomain = (string) $request->get_param( 'subdomain' );
		$username  = (string) $request->get_param( 'username' );
		$password  = (string) $request->get_param( 'password' );

		if ( $subdomain === '' || $username === '' || $password === '' ) {
			return new WP_REST_Response(
				array(
					'connected' => false,
					'error'     => __( 'Subdomain, username, and password are required.', 'smaily-connect' ),
				),
				200
			);
		}

		$client    = $this->build_client( $subdomain, $username, $password );
		$connected = $client->test_connection();

		return new WP_REST_Response(
			array(
				'connected' => $connected,
				'error'     => $connected
					? null
					: __( 'Smaily did not accept those credentials.', 'smaily-connect' ),
			),
			200
		);
	}

	/**
	 * Factory split out for testability — PHPUnit injects a mocked Client
	 * via a subclass override without having to stub Smaily\Client's HTTP
	 * surface here.
	 */
	protected function build_client( string $subdomain, string $username, string $password ): Client {
		return new Client( $subdomain, $username, $password );
	}
}
