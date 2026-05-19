<?php
/**
 * Drains the Smaily event queue and dispatches each pending event.
 *
 * @package Smaily\Connect\Smaily
 */

declare(strict_types=1);

namespace Smaily\Connect\Smaily;

defined( 'ABSPATH' ) || exit;

use Smaily\Connect\Integrations\WooCommerce\HookHandler;

/**
 * Action Scheduler callback for `smly_plus_flush_event_queue` and
 * `smly_plus_retry_failed_events`. Reads a batch of pending rows from
 * EventQueue, dispatches each based on `event_type`, and moves the row
 * to its terminal state.
 *
 * Event-type routing:
 *
 *   contact.sync              → Smaily\Client::upsert_subscribers (default account)
 *   automation.welcome        → AutomationRouter::trigger_automation('welcome', …)
 *   automation.first_order    → AutomationRouter::trigger_automation('first_order', …)
 *   automation.abandoned_cart → AutomationRouter::trigger_automation('abandoned_cart', …)
 *
 * Payload shape (what HookHandler enqueues):
 *
 *   {
 *     "email":    "buyer@example.test",
 *     "language": "et_EE",                    // null or empty for single-language sites
 *     "fields":   { "first_name": "Alice", ... }
 *   }
 *
 * `fields` is merged into the Smaily address row for contact.sync and
 * passed as additional_fields to AutomationRouter for automation events.
 *
 * Error model — three terminal states:
 *
 *   - mark_sent on success
 *   - mark_sent on skip (missing email or no workflow mapped — a retry
 *     can't recover either, so we don't waste retry attempts)
 *   - mark_failed on TerminalDispatchException (unknown event_type,
 *     payload decode failure)
 *   - record_attempt on ApiException (transient — AS retry job re-runs)
 */
final class Flusher {

	public const DEFAULT_BATCH_SIZE = 50;

	private EventQueue $queue;
	private AutomationRouter $router;

	/** @var callable(string $account_key): Client */
	private $client_factory;

	/**
	 * @param callable(string $account_key): Client $client_factory
	 */
	public function __construct( EventQueue $queue, AutomationRouter $router, callable $client_factory ) {
		$this->queue          = $queue;
		$this->router         = $router;
		$this->client_factory = $client_factory;
	}

	/**
	 * Process up to $batch_size pending events.
	 *
	 * @return array{processed: int, sent: int, failed: int, retried: int}
	 */
	public function flush( int $batch_size = self::DEFAULT_BATCH_SIZE ): array {
		$stats = array(
			'processed' => 0,
			'sent'      => 0,
			'failed'    => 0,
			'retried'   => 0,
		);

		foreach ( $this->queue->pending( $batch_size ) as $event ) {
			++$stats['processed'];

			$id   = (int) ( $event['id'] ?? 0 );
			$type = (string) ( $event['event_type'] ?? '' );

			try {
				$payload = $this->decode_payload( (string) ( $event['payload'] ?? '' ) );
				$this->dispatch( $type, $payload );

				$this->queue->mark_sent( $id );
				++$stats['sent'];
			} catch ( TerminalDispatchException $e ) {
				$this->queue->mark_failed( $id, $e->getMessage() );
				++$stats['failed'];
			} catch ( ApiException $e ) {
				$this->queue->record_attempt( $id, $e->getMessage() );
				++$stats['retried'];
			}
		}

		return $stats;
	}

	/**
	 * Run a single event through its handler.
	 *
	 * @param array<string, mixed> $payload
	 *
	 * @throws TerminalDispatchException Unknown event type.
	 * @throws ApiException              Transient API failure — should be retried.
	 */
	private function dispatch( string $event_type, array $payload ): void {
		$email = isset( $payload['email'] ) ? (string) $payload['email'] : '';
		if ( $email === '' ) {
			// Missing email — terminal skip; mark_sent path handles it
			// by returning early so the row leaves the queue.
			return;
		}

		$fields = isset( $payload['fields'] ) && is_array( $payload['fields'] )
			? $payload['fields']
			: array();

		switch ( $event_type ) {
			case HookHandler::EVENT_CONTACT_SYNC:
				$this->dispatch_contact_sync( $email, $fields );
				return;

			case HookHandler::EVENT_AUTOMATION_WELCOME:
				$this->dispatch_automation( 'welcome', $payload, $fields );
				return;

			case HookHandler::EVENT_AUTOMATION_FIRST_ORDER:
				$this->dispatch_automation( 'first_order', $payload, $fields );
				return;

			case 'automation.abandoned_cart':
				$this->dispatch_automation( 'abandoned_cart', $payload, $fields );
				return;

			default:
				throw new TerminalDispatchException( 'unknown_event_type: ' . $event_type );
		}
	}

	/**
	 * @param array<string, mixed> $fields
	 */
	private function dispatch_contact_sync( string $email, array $fields ): void {
		$row    = array_merge( array( 'email' => $email ), $fields );
		$client = ( $this->client_factory )( 'default' );
		$client->upsert_subscribers( array( $row ) );
	}

	/**
	 * @param array<string, mixed> $payload
	 * @param array<string, mixed> $fields
	 */
	private function dispatch_automation( string $trigger, array $payload, array $fields ): void {
		$contact_data = array(
			'email'    => (string) $payload['email'],
			'language' => isset( $payload['language'] ) ? (string) $payload['language'] : '',
		);

		// AutomationRouter::trigger_automation returns false for terminal
		// skips (no workflow mapped); ApiException bubbles for transient.
		// Either return path is acceptable for us — the false case still
		// has mark_sent semantics, which is what flush() does after
		// dispatch() returns without throwing.
		$this->router->trigger_automation( $trigger, $contact_data, $fields );
	}

	/**
	 * @return array<string, mixed>
	 *
	 * @throws TerminalDispatchException When the payload isn't valid JSON-encoded array.
	 */
	private function decode_payload( string $json ): array {
		if ( $json === '' ) {
			return array();
		}

		$decoded = json_decode( $json, true );

		if ( ! is_array( $decoded ) ) {
			throw new TerminalDispatchException( 'payload_decode_failure' );
		}

		return $decoded;
	}
}
