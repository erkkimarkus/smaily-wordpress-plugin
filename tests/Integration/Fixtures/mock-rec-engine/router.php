<?php
/**
 * Mock rec-engine router script for `php -S`.
 *
 * Loaded by RecEngineMockServer when an integration test needs the
 * engine but a live deploy isn't available (every CI run, plus
 * local runs without RECENGINE_LIVE=1). Implements just enough of
 * RECENGINE_API_CONTRACT.md to exercise the plugin's connect /
 * ping / disconnect cycle:
 *
 *   POST /setup/exchange       one-time use; first call returns 200
 *                              + tenant config; subsequent calls
 *                              with the same token return 410.
 *   GET  /api/v1/ingest/ping   requires Authorization: Bearer sk_*;
 *                              401 without, 200 + tenant info with.
 *
 * Token state is persisted to /tmp/<state_file> so the "second use
 * returns 410" assertion works across multiple HTTP requests from
 * within a single test method.
 */

// Standard headers per contract §1.
header( 'Content-Type: application/json' );
header( 'Cache-Control: no-store' );
header( 'X-Engine-Version: 1.0.0' );

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$path   = parse_url( $_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH ) ?? '/';

$state_file = sys_get_temp_dir() . '/smaily-rec-mock-state.json';
$state      = file_exists( $state_file )
	? json_decode( (string) file_get_contents( $state_file ), true )
	: array();
if ( ! is_array( $state ) ) {
	$state = array();
}

function reply( int $status, array $body ): void {
	http_response_code( $status );
	echo json_encode( $body );
	exit;
}

function save_state( string $path, array $state ): void {
	file_put_contents( $path, json_encode( $state ) );
}

if ( $method === 'POST' && $path === '/setup/exchange' ) {
	$raw  = (string) file_get_contents( 'php://input' );
	$body = json_decode( $raw, true );
	if ( ! is_array( $body ) || ! isset( $body['setup_token'] ) ) {
		reply(
			400,
			array(
				'error'      => 'invalid_request',
				'message'    => 'setup_token required',
				'request_id' => 'req_' . bin2hex( random_bytes( 4 ) ),
				'timestamp'  => gmdate( 'c' ),
			)
		);
	}

	$token = (string) $body['setup_token'];

	// Honour state file: same token twice = 410.
	if ( isset( $state[ 'used_' . $token ] ) ) {
		reply(
			410,
			array(
				'error'          => 'setup_token_expired_or_used',
				'message'        => 'This setup token has already been used.',
				'regenerate_url' => 'https://mock-engine.test/admin/regenerate/' . $token,
				'request_id'     => 'req_' . bin2hex( random_bytes( 4 ) ),
				'timestamp'      => gmdate( 'c' ),
			)
		);
	}

	// Tokens that start with "notfound_" simulate the "not found" case.
	if ( strpos( $token, 'notfound_' ) === 0 ) {
		reply(
			404,
			array(
				'error'      => 'setup_token_not_found',
				'message'    => 'Setup token not found.',
				'request_id' => 'req_' . bin2hex( random_bytes( 4 ) ),
				'timestamp'  => gmdate( 'c' ),
			)
		);
	}

	// Anything else: success. Mark token used.
	$state[ 'used_' . $token ]   = true;
	$state[ 'api_key' ]          = 'sk_mock_' . bin2hex( random_bytes( 16 ) );
	$state[ 'tenant_id' ]        = '00000000-0000-4000-8000-' . bin2hex( random_bytes( 6 ) );
	$state[ 'tenant_name' ]      = 'Mock Tenant';
	save_state( $state_file, $state );

	reply(
		200,
		array(
			'tenant_id'       => $state['tenant_id'],
			'tenant_name'     => $state['tenant_name'],
			'api_key'         => $state['api_key'],
			'engine_base_url' => sprintf( 'http://%s', $_SERVER['HTTP_HOST'] ?? 'localhost:9876' ),
			'engine_version'  => '1.0.0',
			'endpoints'       => array(
				'ping'             => '/api/v1/ingest/ping',
				'catalog'          => '/api/v1/ingest/catalog',
				'customers'        => '/api/v1/ingest/customers',
				'orders'           => '/api/v1/ingest/orders',
				'browse'           => '/api/v1/ingest/browse',
				'identity_merge'   => '/api/v1/identity/merge',
				'customer_export'  => '/api/v1/customer/{email}/export',
				'customer_delete'  => '/api/v1/customer/{email}',
				'customer_opt_out' => '/api/v1/customer/{email}/opt-out',
			),
			'config'          => array(
				'tracking_cookie_name' => 'smaily_rec_uid',
				'session_cookie_name'  => 'smaily_anon_sid',
				'rec_id_cookie_name'   => 'smaily_rec_id',
				'context_cookie_name'  => 'smaily_rec_ctx',
				'cookie_ttl_days'      => 365,
				'session_ttl_days'     => 30,
				'rate_limit_browse'    => 500,
				'rate_limit_other'     => 100,
				'batch_size_max'       => 100,
			),
			'issued_at'       => gmdate( 'c' ),
		)
	);
}

if ( $method === 'GET' && $path === '/api/v1/ingest/ping' ) {
	$auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
	if ( ! preg_match( '/^Bearer\s+sk_[A-Za-z0-9_]+$/', $auth ) ) {
		reply(
			401,
			array(
				'error'      => 'unauthorized',
				'message'    => 'Authorization header missing or malformed.',
				'request_id' => 'req_' . bin2hex( random_bytes( 4 ) ),
				'timestamp'  => gmdate( 'c' ),
			)
		);
	}

	// Optional: tenant_id from state lets the test correlate.
	$tenant_id   = isset( $state['tenant_id'] ) ? (string) $state['tenant_id'] : '';
	$tenant_name = isset( $state['tenant_name'] ) ? (string) $state['tenant_name'] : 'Mock Tenant';

	reply(
		200,
		array(
			'ok'                  => true,
			'tenant_id'           => $tenant_id,
			'tenant_name'         => $tenant_name,
			'engine_version'      => '1.0.0',
			'tenant_status'       => 'active',
			'endpoints_available' => array( 'catalog', 'customers', 'orders', 'browse', 'identity_merge' ),
			'server_time'         => gmdate( 'c' ),
		)
	);
}

// Fallback.
reply(
	404,
	array(
		'error'      => 'not_found',
		'message'    => sprintf( 'Mock engine has no route for %s %s', $method, $path ),
		'request_id' => 'req_' . bin2hex( random_bytes( 4 ) ),
		'timestamp'  => gmdate( 'c' ),
	)
);
