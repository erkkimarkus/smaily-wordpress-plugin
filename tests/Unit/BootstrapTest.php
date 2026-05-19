<?php
/**
 * Tests for the Bootstrap singleton's lazy-getter object graph.
 *
 * @package Smaily\Connect\Tests
 */

declare(strict_types=1);

namespace Smaily\Connect\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Smaily\Connect\Bootstrap;
use Smaily\Connect\Multilingual\Router as MultilingualRouter;
use Smaily\Connect\Settings\CredentialSet;
use Smaily\Connect\Settings\Credentials;
use Smaily\Connect\Smaily\AutomationRouter;
use Smaily\Connect\Smaily\Client;
use Smaily\Connect\Smaily\EventQueue;

final class BootstrapTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Bootstrap::reset_for_tests();
	}

	protected function tearDown(): void {
		Bootstrap::reset_for_tests();
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_event_queue_getter_returns_a_singleton_per_request(): void {
		$bs = Bootstrap::instance();

		$first  = $bs->event_queue();
		$second = $bs->event_queue();

		self::assertInstanceOf( EventQueue::class, $first );
		self::assertSame( $first, $second );
	}

	public function test_workflow_resolver_defaults_to_multilingual_router(): void {
		$resolver = Bootstrap::instance()->workflow_resolver();

		self::assertInstanceOf( MultilingualRouter::class, $resolver );
	}

	public function test_credentials_getter_returns_a_singleton(): void {
		$bs = Bootstrap::instance();

		self::assertSame( $bs->credentials(), $bs->credentials() );
	}

	public function test_smaily_client_throws_when_credentials_missing(): void {
		$creds = $this->createMock( Credentials::class );
		$creds->method( 'get' )->willReturn( null );

		$bs = Bootstrap::instance();
		$bs->set_credentials( $creds );

		$this->expectException( \RuntimeException::class );

		$bs->smaily_client( 'default' );
	}

	public function test_smaily_client_caches_per_account_key(): void {
		$set = new CredentialSet( 'demo', 'alice', 's3cret' );

		$creds = $this->createMock( Credentials::class );
		$creds->method( 'get' )->willReturn( $set );

		$bs = Bootstrap::instance();
		$bs->set_credentials( $creds );

		$first  = $bs->smaily_client( 'default' );
		$second = $bs->smaily_client( 'default' );

		self::assertInstanceOf( Client::class, $first );
		self::assertSame( $first, $second, 'Repeated calls for the same account key must reuse the Client.' );
	}

	public function test_smaily_client_returns_distinct_instances_per_account_key(): void {
		$default_set = new CredentialSet( 'demo', 'alice', 's3cret' );
		$et_set      = new CredentialSet( 'demo-et', 'eve', 's3cret-et' );

		$creds = $this->createMock( Credentials::class );
		$creds->method( 'get' )->willReturnCallback(
			static fn ( string $key ): ?CredentialSet => $key === 'et' ? $et_set : $default_set
		);

		$bs = Bootstrap::instance();
		$bs->set_credentials( $creds );

		$default = $bs->smaily_client( 'default' );
		$et      = $bs->smaily_client( 'et' );

		self::assertNotSame( $default, $et );
	}

	public function test_automation_router_is_wired_with_resolver_and_client_factory(): void {
		$set = new CredentialSet( 'demo', 'alice', 's3cret' );

		$creds = $this->createMock( Credentials::class );
		$creds->method( 'get' )->willReturn( $set );

		$bs = Bootstrap::instance();
		$bs->set_credentials( $creds );

		$router = $bs->automation_router();
		self::assertInstanceOf( AutomationRouter::class, $router );

		// Subsequent call returns the same instance.
		self::assertSame( $router, $bs->automation_router() );
	}

	public function test_set_workflow_resolver_rebuilds_automation_router(): void {
		$set   = new CredentialSet( 'demo', 'alice', 's3cret' );
		$creds = $this->createMock( Credentials::class );
		$creds->method( 'get' )->willReturn( $set );

		$bs = Bootstrap::instance();
		$bs->set_credentials( $creds );

		$first = $bs->automation_router();

		// Swap the resolver — automation router must be rebuilt.
		$new_resolver = $this->createMock( \Smaily\Connect\Smaily\WorkflowResolverInterface::class );
		$bs->set_workflow_resolver( $new_resolver );

		$second = $bs->automation_router();

		self::assertNotSame( $first, $second );
	}
}
