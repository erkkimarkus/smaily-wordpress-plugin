<?php
/**
 * Tests for the Smaily HTTP client — exercises request building, basic-auth
 * header, error mapping. wp_remote_* is mocked through Brain\Monkey so no
 * real HTTP traffic is generated.
 *
 * @package Smaily\Connect\Tests
 */

declare(strict_types=1);

namespace Smaily\Connect\Tests\Unit\Smaily;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Smaily\Connect\Smaily\ApiException;
use Smaily\Connect\Smaily\Client;

final class ClientTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( 'get_bloginfo' )->alias(
			static fn ( string $what ): string => $what === 'version' ? '6.2.0' : 'https://example.test'
		);
		Functions\when( 'apply_filters' )->returnArg( 2 );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_test_connection_returns_true_on_2xx_workflows_response(): void {
		Functions\when( 'wp_remote_get' )->justReturn( $this->successful_response( '[]' ) );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( '[]' );
		Functions\when( 'is_wp_error' )->justReturn( false );

		$client = new Client( 'demo', 'user', 'pass' );

		self::assertTrue( $client->test_connection() );
	}

	public function test_test_connection_returns_false_on_4xx(): void {
		Functions\when( 'wp_remote_get' )->justReturn( $this->successful_response( '{"error":"unauthorized"}' ) );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 401 );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( '{"error":"unauthorized"}' );
		Functions\when( 'is_wp_error' )->justReturn( false );

		$client = new Client( 'demo', 'user', 'pass' );

		self::assertFalse( $client->test_connection() );
	}

	public function test_test_connection_returns_false_on_transport_error(): void {
		$err = new \stdClass();
		Functions\when( 'wp_remote_get' )->justReturn( $err );
		Functions\when( 'is_wp_error' )->justReturn( true );

		$wp_error = $this->getMockBuilder( \stdClass::class )
			->addMethods( array( 'get_error_message' ) )
			->getMock();
		$wp_error->method( 'get_error_message' )->willReturn( 'cURL exploded' );

		Functions\when( 'wp_remote_get' )->justReturn( $wp_error );

		$client = new Client( 'demo', 'user', 'pass' );

		self::assertFalse( $client->test_connection() );
	}

	public function test_trigger_automation_throws_api_exception_on_5xx(): void {
		Functions\when( 'wp_remote_post' )->justReturn( $this->successful_response( '{"err":"boom"}' ) );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 503 );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( '{"err":"boom"}' );
		Functions\when( 'is_wp_error' )->justReturn( false );

		$client = new Client( 'demo', 'user', 'pass' );

		$this->expectException( ApiException::class );
		$this->expectExceptionCode( 503 );

		$client->trigger_automation( 42, array( array( 'email' => 'a@b.c' ) ) );
	}

	public function test_last_exchange_captures_request_and_response_without_auth(): void {
		// F3-44: request() records the exchange (method/endpoint/body + reply) so
		// the Smaily Flusher can store it in the Event Log — NEVER the auth header.
		Functions\when( 'wp_remote_post' )->justReturn( $this->successful_response( '{"code":101}' ) );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( '{"code":101}' );
		Functions\when( 'is_wp_error' )->justReturn( false );

		$client = new Client( 'demo', 'user', 'pass' );
		self::assertNull( $client->last_exchange(), 'No exchange before the first request.' );

		$client->trigger_automation( 42, array( array( 'email' => 'a@b.c' ) ) );

		$exchange = $client->last_exchange();
		self::assertIsArray( $exchange );
		self::assertSame( 'POST', $exchange['request']['method'] );
		self::assertSame( 'autoresponder', $exchange['request']['endpoint'] );
		self::assertSame( 42, $exchange['request']['body']['autoresponder'] );
		self::assertSame( 200, $exchange['response']['http'] );
		self::assertSame( array( 'code' => 101 ), $exchange['response']['body'] );

		// The Basic-auth credentials must NEVER appear in the recorded exchange.
		$json = (string) json_encode( $exchange );
		self::assertStringNotContainsString( 'Authorization', $json );
		self::assertStringNotContainsString( base64_encode( 'user:pass' ), $json );
	}

	public function test_last_exchange_records_a_failed_response(): void {
		// On a non-2xx the response is still captured (before the throw), so the
		// Event Log shows what the engine actually replied (F3-44).
		Functions\when( 'wp_remote_post' )->justReturn( $this->successful_response( '{"error":"bad"}' ) );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 422 );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( '{"error":"bad"}' );
		Functions\when( 'is_wp_error' )->justReturn( false );

		$client = new Client( 'demo', 'user', 'pass' );

		try {
			$client->trigger_automation( 42, array( array( 'email' => 'a@b.c' ) ) );
		} catch ( ApiException $e ) {
			unset( $e );
		}

		$exchange = $client->last_exchange();
		self::assertSame( 422, $exchange['response']['http'] );
	}

	public function test_request_includes_basic_auth_header(): void {
		$captured_args = null;
		Functions\when( 'wp_remote_get' )->alias(
			static function ( $url, $args ) use ( &$captured_args ) {
				$captured_args = $args;
				return array();
			}
		);
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( '[]' );
		Functions\when( 'is_wp_error' )->justReturn( false );

		( new Client( 'demo', 'alice', 's3cret' ) )->list_autoresponders();

		self::assertIsArray( $captured_args );
		self::assertSame(
			'Basic ' . base64_encode( 'alice:s3cret' ),
			$captured_args['headers']['Authorization']
		);
		self::assertStringStartsWith( 'smaily-connect/', $captured_args['user-agent'] );
	}

	public function test_list_autoresponders_hits_the_documented_smaily_endpoint(): void {
		// Sub-PR 2.H.14 — pin the URL so a future refactor can't quietly
		// flip back to the bogus /api/workflows.php that returned empty
		// rows and made the dropdown surface `#{id}` placeholders.
		$captured_url = null;
		Functions\when( 'wp_remote_get' )->alias(
			static function ( $url, $args ) use ( &$captured_url ) {
				$captured_url = $url;
				return array();
			}
		);
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( '[]' );
		Functions\when( 'is_wp_error' )->justReturn( false );

		( new Client( 'demo', 'alice', 's3cret' ) )->list_autoresponders();

		self::assertIsString( $captured_url );
		self::assertStringContainsString( '/api/autoresponder.php', $captured_url );
		self::assertStringContainsString( 'status=ACTIVE', $captured_url );
	}

	public function test_get_action_log_hits_history_endpoint_and_parses_rows(): void {
		$captured_url = null;
		Functions\when( 'wp_remote_get' )->alias(
			static function ( $url, $args ) use ( &$captured_url ) {
				$captured_url = $url;
				return array();
			}
		);
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn(
			'[{"seq_id":42,"email":"a@x.test","action":"optout"}]'
		);
		Functions\when( 'is_wp_error' )->justReturn( false );

		$rows = ( new Client( 'demo', 'alice', 's3cret' ) )->get_action_log( 0, array( 'optin', 'optout' ) );

		self::assertIsString( $captured_url );
		self::assertStringContainsString( '/api/history.php', $captured_url );
		self::assertStringContainsString( 'since_seq_id=0', $captured_url );
		self::assertCount( 1, $rows );
		self::assertSame( 'optout', $rows[0]['action'] );
	}

	public function test_get_action_log_returns_empty_on_status_payload(): void {
		Functions\when( 'wp_remote_get' )->justReturn( array() );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( '{"code":223,"message":"missing date"}' );
		Functions\when( 'is_wp_error' )->justReturn( false );

		self::assertSame( array(), ( new Client( 'demo', 'u', 'p' ) )->get_action_log( 0 ) );
	}

	public function test_list_contacts_hits_list_1_endpoint(): void {
		$captured_url = null;
		Functions\when( 'wp_remote_get' )->alias(
			static function ( $url, $args ) use ( &$captured_url ) {
				$captured_url = $url;
				return array();
			}
		);
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( '[{"email":"a@x.test","is_unsubscribed":"1"}]' );
		Functions\when( 'is_wp_error' )->justReturn( false );

		$rows = ( new Client( 'demo', 'alice', 's3cret' ) )->list_contacts( 0 );

		self::assertIsString( $captured_url );
		self::assertStringContainsString( '/api/contact.php', $captured_url );
		self::assertStringContainsString( 'list=1', $captured_url );
		self::assertStringContainsString( 'fields=', $captured_url );
		self::assertCount( 1, $rows );
	}

	private function successful_response( string $body ): array {
		return array(
			'response' => array( 'code' => 200 ),
			'body'     => $body,
			'headers'  => array(),
		);
	}
}
