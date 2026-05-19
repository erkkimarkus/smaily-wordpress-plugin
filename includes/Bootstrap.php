<?php
/**
 * Plugin bootstrap — wires WordPress lifecycle hooks for the namespaced
 * Smaily\Connect\* code that runs alongside the legacy Smaily_Connect\* tree.
 *
 * @package Smaily\Connect
 */

declare(strict_types=1);

namespace Smaily\Connect;

defined( 'ABSPATH' ) || exit;

use Smaily\Connect\Integrations\WooCommerce\HookHandler as WooHookHandler;
use Smaily\Connect\Integrations\WooCommerce\Hooks as WooHooks;
use Smaily\Connect\Multilingual\Router as MultilingualRouter;
use Smaily\Connect\Settings\Credentials;
use Smaily\Connect\Smaily\AutomationRouter;
use Smaily\Connect\Smaily\Client;
use Smaily\Connect\Smaily\EventQueue;
use Smaily\Connect\Smaily\Flusher;
use Smaily\Connect\Smaily\WorkflowResolverInterface;

/**
 * Singleton entry point invoked from smaily-connect.php.
 *
 * Phase 1 responsibilities, in three tiers:
 *
 *   1. Lifecycle wiring (boot()): activation / deactivation callbacks,
 *      WooCommerce HPOS compatibility declaration, text-domain loading.
 *
 *   2. Object graph (lazy-getter cluster below): the namespaced code path
 *      forms a small dependency graph — EventQueue, WorkflowResolver,
 *      Smaily\Client (per account key), AutomationRouter, Credentials.
 *      Bootstrap is the composition root that wires them together and
 *      caches the instances per request. This is deliberately a hand-rolled
 *      lazy-getter pattern rather than a DI container: the graph is tiny,
 *      every dependency is unambiguous, and a container would add a layer
 *      of indirection that future readers would have to learn to navigate.
 *
 *   3. Hook registration for the rest of the namespaced code (REST
 *      controllers, WC hooks, AS job handlers). Those land as later
 *      sub-PRs add the corresponding classes.
 *
 * Tests can override any individual dependency through the set_*() seam
 * methods (used by sub-PR 5.B's HookHandler tests to inject fake event
 * queues etc.) without breaking the per-request caching for production.
 */
final class Bootstrap {

	private static ?self $instance = null;

	private bool $booted = false;

	private ?EventQueue $event_queue                   = null;
	private ?WorkflowResolverInterface $resolver       = null;
	private ?Credentials $credentials                  = null;
	private ?AutomationRouter $automation_router       = null;
	private ?Flusher $flusher                          = null;

	/** @var array<string, Client> */
	private array $smaily_clients = array();

	public static function instance(): self {
		if ( self::$instance === null ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Wires all WordPress hooks. Safe to call multiple times — only the first
	 * call has effect.
	 */
	public function boot(): void {
		if ( $this->booted ) {
			return;
		}
		$this->booted = true;

		$plugin_file = defined( 'SMAILY_CONNECT_PLUGIN_FILE' )
			? SMAILY_CONNECT_PLUGIN_FILE
			: __FILE__;

		register_activation_hook( $plugin_file, array( Activation::class, 'run' ) );
		register_deactivation_hook( $plugin_file, array( Deactivation::class, 'run' ) );

		add_action( 'before_woocommerce_init', array( $this, 'declare_woocommerce_compatibility' ) );
		add_action( 'plugins_loaded', array( $this, 'load_textdomain' ) );
		add_action( 'init', array( $this, 'register_woocommerce_hooks' ) );
		add_action( 'init', array( $this, 'register_action_scheduler_jobs' ) );

		// AS callbacks live at request-level priority so they're available
		// when Action Scheduler's runner cycle fires.
		add_action( EventQueue::FLUSH_HOOK, array( $this, 'on_flush_event_queue' ) );
		add_action( 'smly_plus_retry_failed_events', array( $this, 'on_flush_event_queue' ) );
	}

	/**
	 * Wire the WC + user hook callbacks into add_action().
	 *
	 * Deferred to `init` so WooCommerce's hook names are guaranteed
	 * registered with WP by the time we call add_action() on them. WC's
	 * own plugin file fires on `plugins_loaded` priority 5, so by `init`
	 * everything is in place.
	 */
	public function register_woocommerce_hooks(): void {
		WooHooks::register( new WooHookHandler( $this->event_queue() ) );
	}

	/**
	 * Schedule the recurring Action Scheduler jobs that drive the queue.
	 *
	 * Idempotent — `as_has_scheduled_action()` skips the schedule call
	 * when a row already exists. Activation seeds the same actions; this
	 * `init` registration re-runs every request so a deactivation /
	 * reactivation that cancelled the recurring rows restores them
	 * without the user having to re-save Settings.
	 *
	 *   smly_plus_flush_event_queue   — every 60 seconds — Flusher::flush()
	 *   smly_plus_retry_failed_events — every 5 minutes  — same callback,
	 *                                                       handles events
	 *                                                       still in 'pending'
	 *                                                       with attempts > 0
	 */
	public function register_action_scheduler_jobs(): void {
		if ( ! function_exists( 'as_has_scheduled_action' ) || ! function_exists( 'as_schedule_recurring_action' ) ) {
			return;
		}

		if ( ! as_has_scheduled_action( EventQueue::FLUSH_HOOK, array(), EventQueue::AS_GROUP ) ) {
			as_schedule_recurring_action( time(), 60, EventQueue::FLUSH_HOOK, array(), EventQueue::AS_GROUP );
		}

		if ( ! as_has_scheduled_action( 'smly_plus_retry_failed_events', array(), EventQueue::AS_GROUP ) ) {
			as_schedule_recurring_action( time(), 300, 'smly_plus_retry_failed_events', array(), EventQueue::AS_GROUP );
		}
	}

	/**
	 * Action Scheduler callback for smly_plus_flush_event_queue and
	 * smly_plus_retry_failed_events. Both hooks share Flusher::flush()
	 * since the EventQueue::pending() query already includes retries.
	 */
	public function on_flush_event_queue(): void {
		$this->flusher()->flush();
	}

	/**
	 * Declare compatibility with WooCommerce High-Performance Order Storage.
	 *
	 * Required because the BETA fork uses wc_get_order() / wc_get_orders() and
	 * is explicitly HPOS-aware; without this declaration WooCommerce 8.2+ shows
	 * the plugin as "unknown" and refuses to enable HPOS.
	 */
	public function declare_woocommerce_compatibility(): void {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
				'custom_order_tables',
				defined( 'SMAILY_CONNECT_PLUGIN_FILE' ) ? SMAILY_CONNECT_PLUGIN_FILE : __FILE__,
				true
			);
		}
	}

	/**
	 * Load translations from the bundled languages/ directory.
	 *
	 * The legacy Lifecycle class also calls load_plugin_textdomain on the same
	 * text-domain (smaily-connect); WordPress dedupes the call internally, so
	 * the double registration is harmless. We register from the new code path
	 * to keep the namespaced classes self-contained.
	 */
	public function load_textdomain(): void {
		load_plugin_textdomain(
			Constants::TEXT_DOMAIN,
			false,
			dirname( plugin_basename( defined( 'SMAILY_CONNECT_PLUGIN_FILE' ) ? SMAILY_CONNECT_PLUGIN_FILE : __FILE__ ) ) . '/languages'
		);
	}

	// ---------------------------------------------------------------
	// Object graph — lazy getters with per-request caching.
	// ---------------------------------------------------------------

	public function event_queue(): EventQueue {
		if ( $this->event_queue === null ) {
			$this->event_queue = new EventQueue();
		}

		return $this->event_queue;
	}

	public function workflow_resolver(): WorkflowResolverInterface {
		if ( $this->resolver === null ) {
			$this->resolver = new MultilingualRouter();
		}

		return $this->resolver;
	}

	public function credentials(): Credentials {
		if ( $this->credentials === null ) {
			$this->credentials = new Credentials();
		}

		return $this->credentials;
	}

	/**
	 * Returns a Smaily\Client wired to the credential set keyed by
	 * $account_key (defaults to "default" for Mode B/C; Mode A passes
	 * the language code).
	 *
	 * Throws RuntimeException when the requested account hasn't been
	 * configured — callers must check Bootstrap::credentials()->has_default()
	 * or similar before constructing a Client, since an unconfigured
	 * account can't authenticate.
	 */
	public function smaily_client( string $account_key = Credentials::DEFAULT_ACCOUNT_KEY ): Client {
		if ( isset( $this->smaily_clients[ $account_key ] ) ) {
			return $this->smaily_clients[ $account_key ];
		}

		$set = $this->credentials()->get( $account_key );
		if ( $set === null || ! $set->is_complete() ) {
			throw new \RuntimeException(
				sprintf(
					'Smaily credentials are not configured for account "%s"',
					$account_key
				)
			);
		}

		$this->smaily_clients[ $account_key ] = new Client(
			$set->subdomain,
			$set->username,
			$set->password
		);

		return $this->smaily_clients[ $account_key ];
	}

	public function flusher(): Flusher {
		if ( $this->flusher === null ) {
			$bootstrap     = $this;
			$this->flusher = new Flusher(
				$this->event_queue(),
				$this->automation_router(),
				static function ( string $account_key ) use ( $bootstrap ): Client {
					return $bootstrap->smaily_client( $account_key );
				}
			);
		}

		return $this->flusher;
	}

	public function automation_router(): AutomationRouter {
		if ( $this->automation_router === null ) {
			$bootstrap                = $this;
			$this->automation_router  = new AutomationRouter(
				$this->workflow_resolver(),
				static function ( string $account_key ) use ( $bootstrap ): Client {
					return $bootstrap->smaily_client( $account_key );
				}
			);
		}

		return $this->automation_router;
	}

	// ---------------------------------------------------------------
	// Test seams — production code never calls set_*() directly. They
	// exist so PHPUnit can inject doubles into the singleton without
	// having to rebuild the entire graph through Reflection.
	// ---------------------------------------------------------------

	public function set_event_queue( EventQueue $queue ): void {
		$this->event_queue = $queue;
	}

	public function set_workflow_resolver( WorkflowResolverInterface $resolver ): void {
		$this->resolver          = $resolver;
		$this->automation_router = null; // re-build with new resolver on next call
	}

	public function set_credentials( Credentials $credentials ): void {
		$this->credentials    = $credentials;
		$this->smaily_clients = array();
	}

	/**
	 * Reset the singleton — only intended for tests. Production code keeps
	 * the same Bootstrap instance for the lifetime of a request.
	 */
	public static function reset_for_tests(): void {
		self::$instance = null;
	}

	private function __construct() {
		// Use Bootstrap::instance() instead of constructing directly.
	}
}
