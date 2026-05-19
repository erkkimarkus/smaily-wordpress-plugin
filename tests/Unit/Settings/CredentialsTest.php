<?php
/**
 * Tests for the Settings\Credentials resolver.
 *
 * @package Smaily\Connect\Tests
 */

declare(strict_types=1);

namespace Smaily\Connect\Tests\Unit\Settings;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Smaily\Connect\Settings\CredentialSet;
use Smaily\Connect\Settings\Credentials;

/**
 * Stub for the legacy Cypher class. Brain Monkey doesn't auto-mock global
 * classes in a different namespace, and Smaily_Connect\Includes\Cypher
 * isn't autoloaded outside the WP environment, so we declare a minimal
 * shim in the same FQCN that the Credentials class probes via
 * class_exists() + static call.
 *
 * The shim's decrypt() is XOR-trivial; the test only asserts that
 * Credentials passes the stored value through to decrypt() and returns
 * what comes back.
 */
if ( ! class_exists( \Smaily_Connect\Includes\Cypher::class ) ) {
	// phpcs:ignore Squiz.Commenting.ClassComment.Missing -- test-only shim.
	eval( <<<'PHP'
namespace Smaily_Connect\Includes;

class Cypher {
	public static $decrypt_calls = array();
	public static $return_value  = '';
	public static function decrypt( $cyphertext ): string {
		self::$decrypt_calls[] = $cyphertext;
		return self::$return_value;
	}
}
PHP
	);
}

final class CredentialsTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( 'sanitize_key' )->alias(
			static fn ( string $key ): string => strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', $key ) ?? '' )
		);

		\Smaily_Connect\Includes\Cypher::$decrypt_calls = array();
		\Smaily_Connect\Includes\Cypher::$return_value  = '';
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_default_account_reads_legacy_option(): void {
		\Smaily_Connect\Includes\Cypher::$return_value = 'plain-password';

		$captured_key = null;
		Functions\when( 'get_option' )->alias(
			static function ( string $key, $default = false ) use ( &$captured_key ) {
				$captured_key = $key;
				return array(
					'subdomain' => 'demo',
					'username'  => 'alice',
					'password'  => 'encrypted-blob',
				);
			}
		);

		$set = ( new Credentials() )->get();

		self::assertSame( Credentials::LEGACY_OPTION_KEY, $captured_key );
		self::assertInstanceOf( CredentialSet::class, $set );
		self::assertSame( 'demo', $set->subdomain );
		self::assertSame( 'alice', $set->username );
		self::assertSame( 'plain-password', $set->password );
		self::assertTrue( $set->is_complete() );
	}

	public function test_named_account_reads_phase2_option_prefix(): void {
		\Smaily_Connect\Includes\Cypher::$return_value = 'pw';

		$captured_key = null;
		Functions\when( 'get_option' )->alias(
			static function ( string $key, $default = false ) use ( &$captured_key ) {
				$captured_key = $key;
				return array(
					'subdomain' => 'demo-et',
					'username'  => 'eve',
					'password'  => 'enc',
				);
			}
		);

		( new Credentials() )->get( 'et' );

		self::assertSame( Credentials::PHASE2_OPTION_PREFIX . 'et', $captured_key );
	}

	public function test_returns_null_when_option_is_empty(): void {
		Functions\when( 'get_option' )->justReturn( array() );

		self::assertNull( ( new Credentials() )->get() );
	}

	public function test_returns_null_when_option_is_not_array(): void {
		Functions\when( 'get_option' )->justReturn( 'corrupted-string' );

		self::assertNull( ( new Credentials() )->get() );
	}

	public function test_empty_password_after_decrypt_does_not_block_partial_credentials(): void {
		\Smaily_Connect\Includes\Cypher::$return_value = '';

		Functions\when( 'get_option' )->justReturn(
			array(
				'subdomain' => 'demo',
				'username'  => 'alice',
				'password'  => 'enc',
			)
		);

		$set = ( new Credentials() )->get();

		self::assertNotNull( $set );
		self::assertFalse(
			$set->is_complete(),
			'Empty password must yield is_complete()=false so callers refuse to issue API calls.'
		);
	}

	public function test_has_default_returns_false_when_credentials_missing(): void {
		Functions\when( 'get_option' )->justReturn( array() );

		self::assertFalse( ( new Credentials() )->has_default() );
	}

	public function test_has_default_returns_true_for_complete_credentials(): void {
		\Smaily_Connect\Includes\Cypher::$return_value = 'pw';

		Functions\when( 'get_option' )->justReturn(
			array(
				'subdomain' => 'demo',
				'username'  => 'alice',
				'password'  => 'enc',
			)
		);

		self::assertTrue( ( new Credentials() )->has_default() );
	}

	public function test_account_key_is_sanitised_to_prevent_arbitrary_option_lookup(): void {
		\Smaily_Connect\Includes\Cypher::$return_value = 'pw';

		$captured_key = null;
		Functions\when( 'get_option' )->alias(
			static function ( string $key ) use ( &$captured_key ) {
				$captured_key = $key;
				return array();
			}
		);

		( new Credentials() )->get( 'et/../OTHER!@#' );

		self::assertStringStartsWith( Credentials::PHASE2_OPTION_PREFIX, (string) $captured_key );
		self::assertStringNotContainsString( '..', (string) $captured_key );
		self::assertStringNotContainsString( '/', (string) $captured_key );
	}
}
