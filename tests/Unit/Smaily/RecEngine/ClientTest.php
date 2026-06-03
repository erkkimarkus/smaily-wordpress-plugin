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
			array( 'items' => array( array( 'sku' => 'A', 'event_id' => 'u1' ) ) ),
			$client->captured['body'],
			'Catalog wire wrapper key is `items` (verified against the live engine; it 400s on `products`).'
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
