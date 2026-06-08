<?php
/**
 * RecEngine Client tests — endpoint-URL resolution (engine map vs PATH
 * fallback) and the deduplicated-response passthrough, both exercised
 * without real HTTP by overriding request_url().
 *
 * The network retry policy itself (429/5xx backoff, 4xx terminal) is
 * covered end-to-end against the mock engine in RecEngineCatalogTest /
 * RecEnginePingTest — a real php -S server catches transport-layer
 * behaviour a doubled request_url() can't.
 *
 * @package Smaily\Connect\Tests
 */

declare(strict_types=1);

namespace Smaily\Connect\Tests\Unit\Smaily\RecEngine;

use PHPUnit\Framework\TestCase;
use Smaily\Connect\Smaily\RecEngine\Client;

final class ClientTest extends TestCase {

	public function test_ingest_catalog_posts_to_engine_map_url(): void {
		$client = $this->capturing_client(
			'sk_live',
			'https://base.test',
			array( 'ingest_catalog' => 'https://engine.test/api/v1/ingest/catalog' )
		);

		$client->ingest_catalog( array( array( 'sku' => 'A', 'event_id' => 'u1' ) ) );

		self::assertSame( 'POST', $client->captured['method'] );
		self::assertSame(
			'https://engine.test/api/v1/ingest/catalog',
			$client->captured['url'],
			'The engine endpoints map (key ingest_catalog) is the source of truth for the URL.'
		);
		self::assertSame(
			array( 'products' => array( array( 'sku' => 'A', 'event_id' => 'u1' ) ) ),
			$client->captured['body'],
			'Catalog wire wrapper key is `products` (W2 renamed it back from `items`; an `items`-wrapped payload now 400s — N-7.1 live-walk caught the stale send).'
		);
	}

	public function test_ingest_catalog_falls_back_to_constant_path_without_map(): void {
		$client = $this->capturing_client( 'sk_live', 'https://base.test' );

		$client->ingest_catalog( array( array( 'sku' => 'A' ) ) );

		self::assertSame(
			'https://base.test' . Client::PATH_INGEST_CATALOG,
			$client->captured['url'],
			'With no endpoints map, ingest_catalog falls back to base_url + PATH_INGEST_CATALOG.'
		);
	}

	public function test_ingest_catalog_rebases_relative_map_value_on_base_url(): void {
		// Defensive: a legacy/relative map value must still resolve, not be
		// POSTed as a relative URL.
		$client = $this->capturing_client(
			'sk_live',
			'https://base.test',
			array( 'ingest_catalog' => '/api/v1/ingest/catalog' )
		);

		$client->ingest_catalog( array() );

		self::assertSame( 'https://base.test/api/v1/ingest/catalog', $client->captured['url'] );
	}

	public function test_ingest_catalog_returns_deduplicated_body_verbatim(): void {
		// The engine answers a resent event_id with 200 {"deduplicated": true}.
		// ingest_catalog must return it as-is (a success), NOT throw — that's
		// what lets the flush job mark the row sent instead of retrying it.
		$client = new class( 'sk_live', 'https://base.test' ) extends Client {
			protected function request_url( string $method, string $url, ?array $body = null ): array {
				return array( 'deduplicated' => true );
			}
		};

		self::assertSame(
			array( 'deduplicated' => true ),
			$client->ingest_catalog( array( array( 'sku' => 'A', 'event_id' => 'dup' ) ) )
		);
	}

	public function test_ingest_customers_posts_customers_wrapper_to_engine_map_url(): void {
		$client = $this->capturing_client(
			'sk_live',
			'https://base.test',
			array( 'ingest_customers' => 'https://engine.test/api/v1/ingest/customers' )
		);

		$client->ingest_customers( array( array( 'email' => 'a@x.test', 'event_id' => 'u1' ) ) );

		self::assertSame( 'POST', $client->captured['method'] );
		self::assertSame( 'https://engine.test/api/v1/ingest/customers', $client->captured['url'] );
		self::assertSame(
			array( 'customers' => array( array( 'email' => 'a@x.test', 'event_id' => 'u1' ) ) ),
			$client->captured['body'],
			'Customers wire wrapper key is `customers` (W4 §4; live-verified — engine returned 200 processed:1).'
		);
	}

	public function test_ingest_customers_falls_back_to_constant_path_without_map(): void {
		$client = $this->capturing_client( 'sk_live', 'https://base.test' );

		$client->ingest_customers( array( array( 'email' => 'a@x.test' ) ) );

		self::assertSame(
			'https://base.test' . Client::PATH_INGEST_CUSTOMERS,
			$client->captured['url'],
			'With no endpoints map, ingest_customers falls back to base_url + PATH_INGEST_CUSTOMERS.'
		);
	}

	public function test_ingest_customers_returns_d6_partial_success_body_verbatim(): void {
		// D6: a 2xx carries {processed, deduplicated, errors:[{index,...}]}.
		// ingest_customers returns it as-is — the flusher (3.3.2) splits the
		// batch from errors[]; the Client never interprets it.
		$d6 = array(
			'ok'           => true,
			'processed'    => 2,
			'deduplicated' => 0,
			'errors'       => array(
				array( 'index' => 2, 'email' => 'bad', 'field' => 'email', 'message' => 'Invalid email' ),
			),
		);
		$client = new class( 'sk_live', 'https://base.test', $d6 ) extends Client {
			/** @var array<string, mixed> */
			private array $d6;

			/** @param array<string, mixed> $d6 */
			public function __construct( string $api_key, string $base_url, array $d6 ) {
				parent::__construct( $api_key, $base_url );
				$this->d6 = $d6;
			}

			protected function request_url( string $method, string $url, ?array $body = null ): array {
				return $this->d6;
			}
		};

		self::assertSame( $d6, $client->ingest_customers( array( array( 'email' => 'a@x.test' ) ) ) );
	}

	public function test_ingest_orders_posts_orders_wrapper_to_engine_map_url(): void {
		$client = $this->capturing_client(
			'sk_live',
			'https://base.test',
			array( 'ingest_orders' => 'https://engine.test/api/v1/ingest/orders' )
		);

		$client->ingest_orders( array( array( 'external_order_id' => 'WC-1', 'event_id' => 'u1' ) ) );

		self::assertSame( 'POST', $client->captured['method'] );
		self::assertSame( 'https://engine.test/api/v1/ingest/orders', $client->captured['url'] );
		self::assertSame(
			array( 'orders' => array( array( 'external_order_id' => 'WC-1', 'event_id' => 'u1' ) ) ),
			$client->captured['body'],
			'Orders wire wrapper key is `orders` (W5 §5).'
		);
	}

	public function test_ingest_orders_falls_back_to_constant_path_without_map(): void {
		$client = $this->capturing_client( 'sk_live', 'https://base.test' );

		$client->ingest_orders( array( array( 'external_order_id' => 'WC-1' ) ) );

		self::assertSame(
			'https://base.test' . Client::PATH_INGEST_ORDERS,
			$client->captured['url'],
			'With no endpoints map, ingest_orders falls back to base_url + PATH_INGEST_ORDERS.'
		);
	}

	public function test_ingest_orders_returns_d6_partial_success_body_verbatim(): void {
		// D6: a 2xx carries {processed, deduplicated, errors:[{index,...}]}.
		// ingest_orders returns it as-is — the flusher splits the batch; the
		// Client never interprets it (and never reads attribution, which is async).
		$d6 = array(
			'ok'           => true,
			'processed'    => 1,
			'deduplicated' => 0,
			'errors'       => array(
				array( 'index' => 1, 'external_order_id' => 'WC-99', 'field' => 'status', 'message' => 'Invalid enum value' ),
			),
		);
		$client = new class( 'sk_live', 'https://base.test', $d6 ) extends Client {
			/** @var array<string, mixed> */
			private array $d6;

			/** @param array<string, mixed> $d6 */
			public function __construct( string $api_key, string $base_url, array $d6 ) {
				parent::__construct( $api_key, $base_url );
				$this->d6 = $d6;
			}

			protected function request_url( string $method, string $url, ?array $body = null ): array {
				return $this->d6;
			}
		};

		self::assertSame( $d6, $client->ingest_orders( array( array( 'external_order_id' => 'WC-1' ) ) ) );
	}

	public function test_ingest_browse_posts_events_wrapper_to_engine_map_url(): void {
		$client = $this->capturing_client(
			'sk_live',
			'https://base.test',
			array( 'ingest_browse' => 'https://engine.test/api/v1/ingest/browse' )
		);

		$client->ingest_browse( array( array( 'event_id' => 'e1', 'event_type' => 'product_view', 'sku' => 'A' ) ) );

		self::assertSame( 'POST', $client->captured['method'] );
		self::assertSame( 'https://engine.test/api/v1/ingest/browse', $client->captured['url'] );
		self::assertSame(
			array( 'events' => array( array( 'event_id' => 'e1', 'event_type' => 'product_view', 'sku' => 'A' ) ) ),
			$client->captured['body'],
			'Browse wire wrapper key is `events` (§6).'
		);
	}

	public function test_ingest_browse_falls_back_to_constant_path_without_map(): void {
		$client = $this->capturing_client( 'sk_live', 'https://base.test' );

		$client->ingest_browse( array( array( 'event_id' => 'e1', 'event_type' => 'product_view' ) ) );

		self::assertSame(
			'https://base.test' . Client::PATH_INGEST_BROWSE,
			$client->captured['url'],
			'With no endpoints map, ingest_browse falls back to base_url + PATH_INGEST_BROWSE.'
		);
	}

	/**
	 * Client double that captures the resolved (method, url, body) instead
	 * of hitting the network, and returns a canned 200 body.
	 *
	 * @param array<string, string> $endpoints
	 */
	private function capturing_client( string $api_key, string $base_url, array $endpoints = array() ): Client {
		return new class( $api_key, $base_url, $endpoints ) extends Client {
			/** @var array<string, mixed> */
			public array $captured = array();

			protected function request_url( string $method, string $url, ?array $body = null ): array {
				$this->captured = array(
					'method' => $method,
					'url'    => $url,
					'body'   => $body,
				);
				return array( 'ok' => true, 'processed' => 1 );
			}
		};
	}
}
