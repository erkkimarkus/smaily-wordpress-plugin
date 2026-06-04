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

// Sub-PR 3.1.2 — engine serves setup under /api/setup/exchange.
// The plugin (Client::PATH_SETUP_EXCHANGE) now sends to the same
// path; mock follows suit so integration tests exercise the live
// route shape, not a mock-only convenience.
if ( $method === 'POST' && $path === '/api/setup/exchange' ) {
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

	// Endpoints map MIRRORS the live engine response verified in 3.1.2:
	// keys carry the `ingest_` prefix (NOT bare "catalog") and values are
	// ABSOLUTE URLs (NOT relative paths). The plugin reads
	// endpoints()['ingest_catalog']; a mock serving the old unprefixed/
	// relative shape would pass while production got a null URL — exactly
	// the mock↔engine divergence the path-bug taught us to close. All 11
	// endpoints present (resolves the endpoints-map audit, P2 #11).
	$engine_base = sprintf( 'http://%s', $_SERVER['HTTP_HOST'] ?? 'localhost:9876' );
	reply(
		200,
		array(
			'tenant_id'       => $state['tenant_id'],
			'tenant_name'     => $state['tenant_name'],
			'api_key'         => $state['api_key'],
			'engine_base_url' => $engine_base,
			'engine_version'  => '1.0.0',
			'endpoints'       => array(
				'ingest_ping'             => $engine_base . '/api/v1/ingest/ping',
				'ingest_catalog'          => $engine_base . '/api/v1/ingest/catalog',
				'ingest_customers'        => $engine_base . '/api/v1/ingest/customers',
				'ingest_orders'           => $engine_base . '/api/v1/ingest/orders',
				'ingest_browse'           => $engine_base . '/api/v1/ingest/browse',
				'identity_merge'          => $engine_base . '/api/v1/identity/merge',
				'customer_export'         => $engine_base . '/api/v1/customer/%s/export',
				'customer_delete'         => $engine_base . '/api/v1/customer/%s',
				'customer_opt_out'        => $engine_base . '/api/v1/customer/%s/opt-out',
				'recommendations_preview' => $engine_base . '/api/v1/recommendations/preview',
				'recommendations_issue'   => $engine_base . '/api/v1/recommendations/issue',
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

// Catalog ingest. Mirrors RECENGINE_API_CONTRACT.md §3 + the engine
// team's catalog sanity (6/6): Bearer auth, per-product event_id dedup
// keyed on (tenant, event_id), and a 200 {"deduplicated": true} body when
// the whole batch is a resend. Scenario triggers (by SKU prefix on the
// first product) let a test force transient failures + a revoked key to
// exercise the Client's retry policy deterministically.
if ( $method === 'POST' && $path === '/api/v1/ingest/catalog' ) {
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

	$raw  = (string) file_get_contents( 'php://input' );
	$body = json_decode( $raw, true );

	// Wire wrapper key is `items` — verified against the live engine in the
	// 3.2.4 probe (it 400s on `products`). The mock enforces the same so it
	// can't drift back toward the plugin's old assumption (LESSONS §2.4).
	if ( ! is_array( $body ) || ! isset( $body['items'] ) || ! is_array( $body['items'] ) ) {
		reply(
			400,
			array(
				'error'   => 'validation_failed',
				'details' => array( 'fieldErrors' => array( 'items' => array( 'Required' ) ) ),
			)
		);
	}

	$products = $body['items'];

	$first_sku = ( isset( $products[0]['sku'] ) ) ? (string) $products[0]['sku'] : '';

	// Revoked / invalid key — terminal 4xx, the Client must NOT retry.
	if ( strpos( $first_sku, 'AUTH-401' ) === 0 ) {
		reply(
			401,
			array(
				'error'      => 'api_key_revoked',
				'message'    => 'This api_key has been revoked.',
				'request_id' => 'req_' . bin2hex( random_bytes( 4 ) ),
				'timestamp'  => gmdate( 'c' ),
			)
		);
	}

	// Transient failure on the FIRST attempt only, then succeed on retry.
	// The per-sku counter survives across the Client's retry HTTP calls via
	// the state file.
	if ( strpos( $first_sku, 'RETRY-429' ) === 0 || strpos( $first_sku, 'RETRY-500' ) === 0 ) {
		$counter_key = 'attempts_' . $first_sku;
		$seen_count  = isset( $state[ $counter_key ] ) ? (int) $state[ $counter_key ] : 0;
		$state[ $counter_key ] = $seen_count + 1;
		save_state( $state_file, $state );

		if ( $seen_count === 0 ) {
			if ( strpos( $first_sku, 'RETRY-429' ) === 0 ) {
				header( 'Retry-After: 1' );
				reply(
					429,
					array(
						'error'      => 'rate_limited',
						'message'    => 'Too many requests — slow down.',
						'request_id' => 'req_' . bin2hex( random_bytes( 4 ) ),
						'timestamp'  => gmdate( 'c' ),
					)
				);
			}
			reply(
				500,
				array(
					'error'      => 'internal_error',
					'message'    => 'Transient engine error.',
					'request_id' => 'req_' . bin2hex( random_bytes( 4 ) ),
					'timestamp'  => gmdate( 'c' ),
				)
			);
		}
		// seen_count >= 1 → fall through to the success path on retry.
	}

	// Per-product idempotency: unique (tenant_id, event_id). A resent
	// event_id is skipped; a whole-batch resend returns deduplicated:true.
	$seen     = ( isset( $state['catalog_event_ids'] ) && is_array( $state['catalog_event_ids'] ) )
		? $state['catalog_event_ids']
		: array();
	$received = array();
	$created  = 0;
	$skipped  = 0;
	foreach ( $products as $product ) {
		$event_id   = isset( $product['event_id'] ) ? (string) $product['event_id'] : '';
		$received[] = $event_id;
		if ( $event_id !== '' && in_array( $event_id, $seen, true ) ) {
			++$skipped;
			continue;
		}
		if ( $event_id !== '' ) {
			$seen[] = $event_id;
		}
		++$created;
	}
	$state['catalog_event_ids']    = $seen;
	$state['last_catalog_received'] = $received;
	save_state( $state_file, $state );

	// Whole batch was a duplicate → the engine's deduplicated short-circuit.
	if ( $created === 0 && $skipped > 0 ) {
		reply( 200, array( 'deduplicated' => true ) );
	}

	reply(
		200,
		array(
			'ok'                  => true,
			'processed'           => count( $products ),
			'created'             => $created,
			'updated'             => 0,
			'skipped'             => $skipped,
			'errors'              => array(),
			'unmapped_attributes' => array(),
			'request_id'          => 'req_' . bin2hex( random_bytes( 4 ) ),
		)
	);
}

// Customers ingest — W4 email-first + D6 per-item errors[]. Wrapper key is
// `customers` (live-verified in the 3.3.1 probe: {customers:[...]} → 200; no
// products→items surprise). Per-item fate: processed / deduplicated / error.
// Scenario triggers on the FIRST customer's email exercise the flusher's
// terminal-4xx and transient-retry paths; a `d6err-` email prefix on ANY item
// forces that item into errors[] so the partial-success split is testable
// (the plugin can't send a malformed email — WP validates on user create — so
// the mock triggers the error by prefix, not by real validation).
if ( $method === 'POST' && $path === '/api/v1/ingest/customers' ) {
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

	$raw  = (string) file_get_contents( 'php://input' );
	$body = json_decode( $raw, true );

	// Wrapper key is `customers` (W4 §4). Non-array / missing → 400 wrapper-level.
	if ( ! is_array( $body ) || ! isset( $body['customers'] ) || ! is_array( $body['customers'] ) ) {
		reply(
			400,
			array(
				'error'   => 'validation_failed',
				'details' => array( 'fieldErrors' => array( 'customers' => array( 'Required' ) ) ),
			)
		);
	}

	$customers = $body['customers'];

	// Empty / >100 → 400 (the wrapper is all-or-nothing; only per-ITEM
	// failures use errors[]).
	if ( count( $customers ) === 0 || count( $customers ) > 100 ) {
		reply(
			400,
			array(
				'error'   => 'validation_failed',
				'details' => array( 'fieldErrors' => array( 'customers' => array( 'Array must contain between 1 and 100 element(s)' ) ) ),
			)
		);
	}

	$first_email = isset( $customers[0]['email'] ) ? (string) $customers[0]['email'] : '';

	// Revoked / invalid key — terminal 4xx, the flusher must NOT retry.
	if ( strpos( $first_email, 'auth-401@' ) === 0 ) {
		reply(
			401,
			array(
				'error'      => 'api_key_revoked',
				'message'    => 'This api_key has been revoked.',
				'request_id' => 'req_' . bin2hex( random_bytes( 4 ) ),
				'timestamp'  => gmdate( 'c' ),
			)
		);
	}

	// Transient 500 on the FIRST attempt only, then succeed on retry.
	if ( strpos( $first_email, 'retry-500@' ) === 0 ) {
		$counter_key           = 'cust_attempts_' . md5( $first_email );
		$seen_count            = isset( $state[ $counter_key ] ) ? (int) $state[ $counter_key ] : 0;
		$state[ $counter_key ] = $seen_count + 1;
		save_state( $state_file, $state );
		if ( $seen_count === 0 ) {
			reply(
				500,
				array(
					'error'      => 'internal_error',
					'message'    => 'Transient engine error.',
					'request_id' => 'req_' . bin2hex( random_bytes( 4 ) ),
					'timestamp'  => gmdate( 'c' ),
				)
			);
		}
	}

	// Per-item D6: error (by `d6err-` trigger) / deduplicated (event_id seen) /
	// processed. Natural key is email; transport dedup is per-item event_id.
	$seen         = ( isset( $state['customer_event_ids'] ) && is_array( $state['customer_event_ids'] ) )
		? $state['customer_event_ids']
		: array();
	$processed    = 0;
	$deduplicated = 0;
	$errors       = array();
	foreach ( $customers as $index => $customer ) {
		$email    = isset( $customer['email'] ) ? (string) $customer['email'] : '';
		$event_id = isset( $customer['event_id'] ) ? (string) $customer['event_id'] : '';

		if ( strpos( $email, 'd6err-' ) === 0 ) {
			$errors[] = array(
				'index'   => $index,
				'email'   => $email,
				'field'   => 'email',
				'message' => 'Invalid email (mock per-item trigger)',
			);
			continue;
		}

		if ( $event_id !== '' && in_array( $event_id, $seen, true ) ) {
			++$deduplicated;
			continue;
		}
		if ( $event_id !== '' ) {
			$seen[] = $event_id;
		}
		++$processed;
	}
	$state['customer_event_ids']    = $seen;
	$state['last_customers_received'] = array_map(
		static function ( $customer ) {
			return isset( $customer['event_id'] ) ? (string) $customer['event_id'] : '';
		},
		$customers
	);
	save_state( $state_file, $state );

	$response = array(
		'ok'           => true,
		'processed'    => $processed,
		'deduplicated' => $deduplicated,
		'errors'       => $errors,
	);
	// Pure no-op retry (everything deduplicated, nothing errored).
	if ( $processed === 0 && $deduplicated > 0 && $errors === array() ) {
		$response['deduplicated_all'] = true;
	}
	reply( 200, $response );
}

// Orders ingest — W5 batch + email customer-ref + D6 per-item errors[].
// Wrapper key `orders`, 1..50 per request. Scenario triggers on the FIRST
// order's customer_email (auth-401@ / retry-500@); a `d6err-` customer_email
// on ANY order forces a per-item status error so the partial-success split is
// testable (external_order_id is the WC post id, not controllable in a test;
// the billing email is). Attribution is async — no attribution counts here.
if ( $method === 'POST' && $path === '/api/v1/ingest/orders' ) {
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

	$raw  = (string) file_get_contents( 'php://input' );
	$body = json_decode( $raw, true );

	if ( ! is_array( $body ) || ! isset( $body['orders'] ) || ! is_array( $body['orders'] ) ) {
		reply(
			400,
			array(
				'error'   => 'validation_failed',
				'details' => array( 'fieldErrors' => array( 'orders' => array( 'Required' ) ) ),
			)
		);
	}

	$orders = $body['orders'];

	// Wrapper is all-or-nothing: empty / >50 → 400 (only per-ITEM failures use errors[]).
	if ( count( $orders ) === 0 || count( $orders ) > 50 ) {
		reply(
			400,
			array(
				'error'   => 'validation_failed',
				'details' => array( 'fieldErrors' => array( 'orders' => array( 'Array must contain between 1 and 50 element(s)' ) ) ),
			)
		);
	}

	$first_email = isset( $orders[0]['customer_email'] ) ? (string) $orders[0]['customer_email'] : '';

	if ( strpos( $first_email, 'auth-401@' ) === 0 ) {
		reply(
			401,
			array(
				'error'      => 'api_key_revoked',
				'message'    => 'This api_key has been revoked.',
				'request_id' => 'req_' . bin2hex( random_bytes( 4 ) ),
				'timestamp'  => gmdate( 'c' ),
			)
		);
	}

	if ( strpos( $first_email, 'retry-500@' ) === 0 ) {
		$counter_key           = 'order_attempts_' . md5( $first_email );
		$seen_count            = isset( $state[ $counter_key ] ) ? (int) $state[ $counter_key ] : 0;
		$state[ $counter_key ] = $seen_count + 1;
		save_state( $state_file, $state );
		if ( $seen_count === 0 ) {
			reply(
				500,
				array(
					'error'      => 'internal_error',
					'message'    => 'Transient engine error.',
					'request_id' => 'req_' . bin2hex( random_bytes( 4 ) ),
					'timestamp'  => gmdate( 'c' ),
				)
			);
		}
	}

	$seen         = ( isset( $state['order_event_ids'] ) && is_array( $state['order_event_ids'] ) )
		? $state['order_event_ids']
		: array();
	$processed    = 0;
	$deduplicated = 0;
	$errors       = array();
	foreach ( $orders as $index => $order ) {
		$external_id = isset( $order['external_order_id'] ) ? (string) $order['external_order_id'] : '';
		$email       = isset( $order['customer_email'] ) ? (string) $order['customer_email'] : '';
		$event_id    = isset( $order['event_id'] ) ? (string) $order['event_id'] : '';

		if ( strpos( $email, 'd6err-' ) === 0 ) {
			$errors[] = array(
				'index'             => $index,
				'external_order_id' => $external_id,
				'field'             => 'status',
				'message'           => 'Invalid enum value (mock per-item trigger)',
			);
			continue;
		}

		if ( $event_id !== '' && in_array( $event_id, $seen, true ) ) {
			++$deduplicated;
			continue;
		}
		if ( $event_id !== '' ) {
			$seen[] = $event_id;
		}
		++$processed;
	}
	$state['order_event_ids']     = $seen;
	$state['last_orders_received'] = array_map(
		static function ( $order ) {
			return isset( $order['external_order_id'] ) ? (string) $order['external_order_id'] : '';
		},
		$orders
	);
	save_state( $state_file, $state );

	$response = array(
		'ok'           => true,
		'processed'    => $processed,
		'deduplicated' => $deduplicated,
		'errors'       => $errors,
	);
	if ( $processed === 0 && $deduplicated > 0 && $errors === array() ) {
		$response['deduplicated_all'] = true;
	}
	reply( 200, $response );
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
