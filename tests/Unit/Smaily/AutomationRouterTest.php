<?php
/**
 * Tests for AutomationRouter — verifies the resolver-then-dispatch path
 * without standing up the real WorkflowResolverInterface implementation
 * (which lives in Multilingual\Router, sub-PR 4).
 *
 * @package Smaily\Connect\Tests
 */

declare(strict_types=1);

namespace Smaily\Connect\Tests\Unit\Smaily;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Smaily\Connect\Smaily\AutomationRouter;
use Smaily\Connect\Smaily\Client;
use Smaily\Connect\Smaily\WorkflowMatch;
use Smaily\Connect\Smaily\WorkflowResolverInterface;

final class AutomationRouterTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( 'get_bloginfo' )->justReturn( 'x' );
		Functions\when( 'apply_filters' )->returnArg( 2 );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_skips_when_email_missing(): void {
		$resolver = $this->resolverThatReturns(
			new WorkflowMatch( 42, 'default' )
		);

		$factory = function ( string $key ): Client {
			self::fail( 'Client factory should not run when email is missing.' );
		};

		$router = new AutomationRouter( $resolver, $factory );

		self::assertFalse( $router->trigger_automation( 'welcome', array() ) );
	}

	public function test_skips_when_resolver_returns_null(): void {
		$resolver = $this->resolverThatReturns( null );

		$factory = function ( string $key ): Client {
			self::fail( 'Client factory should not run when resolver returns null.' );
		};

		$router = new AutomationRouter( $resolver, $factory );

		self::assertFalse(
			$router->trigger_automation( 'welcome', array( 'email' => 'a@b.c' ) )
		);
	}

	public function test_dispatches_with_resolved_workflow_id_and_account_key(): void {
		$resolver = $this->resolverThatReturns( new WorkflowMatch( 42, 'et_account', 'et' ) );

		$client = $this->createMock( Client::class );
		$client->expects( $this->once() )
			->method( 'trigger_automation' )
			->with(
				42,
				$this->callback(
					static function ( array $addresses ): bool {
						return $addresses[0]['email'] === 'a@b.c'
							&& $addresses[0]['first_name'] === 'Anna';
					}
				)
			)
			->willReturn( array( 'status' => 'ok' ) );

		$captured_key = null;
		$factory      = function ( string $key ) use ( $client, &$captured_key ): Client {
			$captured_key = $key;
			return $client;
		};

		$router = new AutomationRouter( $resolver, $factory );

		self::assertTrue(
			$router->trigger_automation(
				'welcome',
				array(
					'email'      => 'a@b.c',
					'language'   => 'et',
					'first_name' => 'Anna',
				)
			)
		);

		self::assertSame( 'et_account', $captured_key );
	}

	public function test_lets_api_exception_bubble_for_flusher_retry_handling(): void {
		$resolver = $this->resolverThatReturns( new WorkflowMatch( 1, 'default' ) );

		$client = $this->createMock( Client::class );
		$client->method( 'trigger_automation' )
			->willThrowException( new \Smaily\Connect\Smaily\ApiException( 'rate limited', 429 ) );

		$factory = static fn (): Client => $client;

		$router = new AutomationRouter( $resolver, $factory );

		$this->expectException( \Smaily\Connect\Smaily\ApiException::class );
		$this->expectExceptionCode( 429 );

		$router->trigger_automation( 'welcome', array( 'email' => 'a@b.c' ) );
	}

	private function resolverThatReturns( ?WorkflowMatch $match ): WorkflowResolverInterface {
		return new class( $match ) implements WorkflowResolverInterface {
			public function __construct( private ?WorkflowMatch $match ) {}

			public function resolve_workflow( string $trigger_type, ?string $language ): ?WorkflowMatch {
				return $this->match;
			}
		};
	}
}
