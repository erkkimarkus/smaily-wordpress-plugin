<?php
/**
 * Integration: rec-engine base URL is reachable and answers auth challenges.
 *
 * @package Smaily\Connect\Tests\Integration
 */

declare(strict_types=1);

namespace Smaily\Connect\Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * Conditional connectivity smoke-test.
 *
 * What this catches BEFORE we start writing Faas-3 features:
 *
 *   - Engine URL changed and we didn't notice (DNS, deploy revert).
 *   - Engine returned 5xx — engine is down before we even try to
 *     wire the plugin against it.
 *   - Auth scheme drifted from RECENGINE_API_CONTRACT.md §2 — a
 *     missing Authorization header should produce 401 + a JSON body
 *     with `Cache-Control: no-store` and the X-Engine-Version header.
 *     If those guarantees don't hold, every Faas-3 feature is built
 *     on a moving target.
 *
 * Configuration:
 *
 *   RECENGINE_BASE_URL — required. Defaults to the pilot URL from
 *     the contract doc. Override via env when staging URL rotates.
 *
 * The test runs `GET /api/v1/ingest/ping` WITHOUT auth and asserts:
 *   - HTTP 401 (engine answers, auth scheme works).
 *   - JSON body with an `error` field.
 *   - `X-Engine-Version` header present (contract §3).
 *
 * It deliberately does NOT exchange a setup token. Setup tokens are
 * one-time-use (contract §7.1 idempotency); burning one here would
 * leave Faas-3.1 with no token to test against. The 401-on-no-auth
 * probe is enough to confirm the engine is reachable and behaves per
 * spec.
 */
final class RecEngineConnectivityTest extends TestCase {

	// Faas-3 pilot engine — currently live at the Erkki staging deploy.
	// RECENGINE_API_CONTRACT.md §1 quotes a different URL (re-seven-indol)
	// that's no longer reachable; this constant tracks the active
	// engine and the contract doc will be updated separately. CI / local
	// runs can override via the RECENGINE_BASE_URL env var when the
	// staging URL rotates again.
	private const DEFAULT_BASE = 'https://re-erkkimarkus-projects.vercel.app';
	private const PING_PATH    = '/api/v1/ingest/ping';

	public function test_engine_ping_returns_401_without_auth(): void {
		$base    = self::base_url();
		$url     = rtrim( $base, '/' ) . self::PING_PATH;
		$timeout = 10;

		$headers = array(
			'User-Agent' => 'SmailyConnect-IntegrationTest/0.1',
		);
		$bypass  = self::bypass_token();
		if ( $bypass !== '' ) {
			// Vercel deployment-protection bypass header — sent so the
			// request reaches the engine itself rather than the
			// platform-level SSO challenge. Generate a token in
			// Vercel dashboard → Project → Settings → Deployment
			// Protection → Protection Bypass for Automation, then
			// export it via RECENGINE_BYPASS_TOKEN before running
			// the suite.
			$headers['x-vercel-protection-bypass'] = $bypass;
		}

		$response = wp_remote_get(
			$url,
			array(
				'timeout' => $timeout,
				'headers' => $headers,
			)
		);

		if ( is_wp_error( $response ) ) {
			self::fail(
				sprintf(
					'Rec-engine unreachable at %s: %s. Check RECENGINE_API_CONTRACT.md or set RECENGINE_BASE_URL to the staging URL.',
					$url,
					$response->get_error_message()
				)
			);
		}

		$status       = (int) wp_remote_retrieve_response_code( $response );
		$content_type = strtolower( (string) wp_remote_retrieve_header( $response, 'content-type' ) );
		$body         = (string) wp_remote_retrieve_body( $response );

		// Vercel deployment-protection wall — 401 + HTML "Authentication
		// Required" page. Skip with an attention-grabbing message so
		// Erkki sees the signal in CI logs without the connectivity
		// check blocking plugin-side regressions. Plugin-side tests
		// are must-pass; this one is conditional per the agreed split
		// (engine reachability isn't a regression in plugin code).
		if ( stripos( $content_type, 'text/html' ) !== false
			&& stripos( $body, 'Authentication Required' ) !== false
		) {
			self::markTestSkipped(
				sprintf(
					"\n" .
					"==========================================================\n" .
					"REC-ENGINE CONNECTIVITY SKIPPED — Vercel SSO wall in front of %s\n" .
					"==========================================================\n" .
					"The integration suite cannot reach the engine to verify the\n" .
					"contract. Pick one of:\n" .
					"  1. Vercel → Project Settings → Deployment Protection →\n" .
					"     set Production to 'No Protection'.\n" .
					"  2. Generate a Protection Bypass for Automation token and\n" .
					"     export RECENGINE_BYPASS_TOKEN=<token> before running\n" .
					"     the suite (CI env var + local shell).\n" .
					"  3. Point RECENGINE_BASE_URL at a publicly-reachable engine.\n" .
					"This test is non-blocking by design — Faas 3.1+ will still\n" .
					"depend on engine reachability for feature work, so unblock\n" .
					"this before 3.1.\n" .
					"==========================================================",
					$base
				)
			);
		}

		self::assertSame(
			401,
			$status,
			sprintf(
				"Expected HTTP 401 from %s (no Authorization header). Got HTTP %d with body:\n%s",
				$url,
				$status,
				$body
			)
		);

		self::assertStringContainsString(
			'application/json',
			$content_type,
			'Rec-engine error response Content-Type is not JSON. Contract §1 requires JSON for every response, including errors.'
		);

		$engine_version = (string) wp_remote_retrieve_header( $response, 'x-engine-version' );
		self::assertNotSame(
			'',
			$engine_version,
			'X-Engine-Version header missing from rec-engine response. Contract §3 requires it on every reply.'
		);

		$body_json = json_decode( $body, true );
		self::assertIsArray( $body_json, 'Rec-engine 401 body did not decode as JSON.' );
		self::assertArrayHasKey( 'error', $body_json, 'Rec-engine 401 body missing the `error` field (contract §5).' );
	}

	private static function base_url(): string {
		$env = getenv( 'RECENGINE_BASE_URL' );
		if ( is_string( $env ) && $env !== '' ) {
			return $env;
		}
		return self::DEFAULT_BASE;
	}

	private static function bypass_token(): string {
		$env = getenv( 'RECENGINE_BYPASS_TOKEN' );
		return is_string( $env ) ? $env : '';
	}
}
