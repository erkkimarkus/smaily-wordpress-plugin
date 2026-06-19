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

use Smaily\Connect\Integrations\WooCommerce\CatalogHookHandler;
use Smaily\Connect\Integrations\WooCommerce\CustomerHookHandler;
use Smaily\Connect\Integrations\WooCommerce\HookHandler as WooHookHandler;
use Smaily\Connect\Integrations\WooCommerce\IdentityHookHandler;
use Smaily\Connect\Integrations\WooCommerce\OrderHookHandler;
use Smaily\Connect\Integrations\WooCommerce\StorefrontBeacon;
use Smaily\Connect\DB\QueueJanitor;
use Smaily\Connect\Notifications\NotificationManager;
use Smaily\Connect\Privacy\GdprHandler;
use Smaily\Connect\Privacy\ProfilingConsent;
use Smaily\Connect\Privacy\ProfilingConsentAccount;
use Smaily\Connect\Integrations\WooCommerce\Hooks as WooHooks;
use Smaily\Connect\Integrations\WooCommerce\LegacyHookBridge;
use Smaily\Connect\Multilingual\DetectorFactory;
use Smaily\Connect\Multilingual\DetectorInterface;
use Smaily\Connect\Multilingual\Router as MultilingualRouter;
use Smaily\Connect\REST\BackfillEndpoint;
use Smaily\Connect\REST\EndpointRegistry;
use Smaily\Connect\Settings\Credentials;
use Smaily\Connect\Settings\RecEngineSettings;
use Smaily\Connect\Smaily\AutomationRouter;
use Smaily\Connect\Smaily\BackfillJob;
use Smaily\Connect\Smaily\BackfillJobInterface;
use Smaily\Connect\Smaily\RecEngine\Backfill\CatalogBackfillJob;
use Smaily\Connect\Smaily\RecEngine\Backfill\CustomerBackfillJob;
use Smaily\Connect\Smaily\RecEngine\Backfill\OrderBackfillJob;
use Smaily\Connect\Smaily\Client;
use Smaily\Connect\Smaily\EventQueue;
use Smaily\Connect\Smaily\Flusher;
use Smaily\Connect\Smaily\RecEngine\CatalogPayloadBuilder;
use Smaily\Connect\Smaily\RecEngine\Client as RecEngineClient;
use Smaily\Connect\Smaily\RecEngine\CustomerFlusher;
use Smaily\Connect\Smaily\RecEngine\CustomerPayloadBuilder;
use Smaily\Connect\Smaily\RecEngine\IngestFlusher;
use Smaily\Connect\Smaily\RecEngine\IngestQueue;
use Smaily\Connect\Smaily\RecEngine\OrderFlusher;
use Smaily\Connect\Smaily\RecEngine\OrderPayloadBuilder;
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

	private ?EventQueue $event_queue             = null;
	private ?WorkflowResolverInterface $resolver = null;
	private ?Credentials $credentials            = null;
	private ?AutomationRouter $automation_router = null;
	private ?Flusher $flusher                    = null;

	private ?IngestQueue $ingest_queue                = null;
	private ?CatalogPayloadBuilder $catalog_builder   = null;
	private ?RecEngineSettings $rec_settings          = null;
	private ?IngestFlusher $ingest_flusher            = null;
	private ?CustomerPayloadBuilder $customer_builder = null;
	private ?CustomerFlusher $customer_flusher        = null;
	private ?OrderPayloadBuilder $order_builder       = null;
	private ?OrderFlusher $order_flusher              = null;

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

		// P1 #2: register_activation_hook only fires on an explicit activate.
		// Common upgrade paths (`wp plugin update`, `wp plugin install
		// --force` on an active plugin, auto-update) replace files WITHOUT
		// re-firing it, so the new tables would never be created. A cheap
		// admin_init version-check runs the (idempotent) migrations whenever
		// the stored version trails the code — the upgrade-detect the
		// activation hook can't provide.
		add_action( 'admin_init', array( $this, 'maybe_run_upgrade' ) );

		add_action( 'before_woocommerce_init', array( $this, 'declare_woocommerce_compatibility' ) );
		add_action( 'plugins_loaded', array( $this, 'load_textdomain' ) );
		add_action( 'init', array( $this, 'register_woocommerce_hooks' ) );
		add_action( 'init', array( $this, 'register_action_scheduler_jobs' ) );

		// AS callbacks live at request-level priority so they're available
		// when Action Scheduler's runner cycle fires.
		add_action( EventQueue::FLUSH_HOOK, array( $this, 'on_flush_event_queue' ) );
		add_action( 'smly_plus_retry_failed_events', array( $this, 'on_flush_event_queue' ) );

		// Rec-engine ingest flush (catalog/customers/orders). IngestQueue
		// enqueues a one-off flush per change; the recurring schedule below
		// re-ticks for row-level retries (next_retry_at).
		add_action( IngestQueue::FLUSH_HOOK, array( $this, 'on_flush_ingest_queue' ) );
		// Customers drain on their own hook — the shared queue routes catalog.*
		// and customer.* rows to separate flushers (3.3.3).
		add_action( CustomerFlusher::FLUSH_HOOK, array( $this, 'on_flush_customer_queue' ) );
		// Orders drain on their own hook too (3.3-orders.3).
		add_action( OrderFlusher::FLUSH_HOOK, array( $this, 'on_flush_order_queue' ) );

		// Bridges from new AS hook names → legacy WP-Cron hook names.
		// The legacy Smaily_Connect\Integrations\WooCommerce\Cron class
		// still has its add_action() registrations on these hook names;
		// firing them via do_action() keeps business logic unchanged
		// while moving scheduling to Action Scheduler. See
		// Migration\WPCronAuditor for the corresponding WP-Cron clear.
		add_action( 'smly_plus_contact_sync', array( $this, 'on_contact_sync_tick' ) );
		add_action( 'smly_plus_abandoned_cart', array( $this, 'on_abandoned_cart_tick' ) );

		// REST endpoints + the AS callback that drives the backfill loop.
		add_action( 'rest_api_init', array( $this, 'register_rest_endpoints' ) );
		add_action( BackfillEndpoint::TICK_HOOK, array( $this, 'on_backfill_tick' ), 10, 1 );

		// Storefront browse-beacon enqueue (3.4.3). wp_enqueue_scripts is a
		// front-end-only hook; StorefrontBeacon::enqueue() self-gates on
		// connected + browse-tracking + WooCommerce active.
		( new StorefrontBeacon( new RecEngineSettings() ) )->register();

		// GDPR rights for rec-engine personal data (3.8) — registers a WP
		// Privacy API exporter (Art 15) + eraser (Art 17) so WordPress's own
		// Tools → Export / Erase Personal Data covers the rec data + markers.
		$gdpr_bootstrap = $this;
		( new GdprHandler(
			$this->rec_engine_settings(),
			static function () use ( $gdpr_bootstrap ): RecEngineClient {
				return $gdpr_bootstrap->rec_client();
			}
		) )->register();

		// Proactive health notifications (3.10.2) — a recurring health-check sets
		// admin notices for "too many failures" / "engine unreachable >1h", so a
		// pilot operator learns something broke without watching the Event Log.
		$notify_bootstrap = $this;
		( new NotificationManager(
			$this->rec_engine_settings(),
			static function () use ( $notify_bootstrap ): RecEngineClient {
				return $notify_bootstrap->rec_client();
			},
			static function () use ( $notify_bootstrap ): ?Client {
				// Only probe Smaily once the email wizard is finished — an
				// un-set-up store isn't "down", just unconfigured.
				if ( ! (bool) get_option( 'smly_plus_setup_completed', false ) ) {
					return null;
				}
				return $notify_bootstrap->smaily_client();
			}
		) )->register();

		// Shopper-facing profiling-consent opt-out ((a).2) — a My Account privacy
		// toggle. The opt-out the opt-out model legally requires (DECISIONS F3-31).
		( new ProfilingConsentAccount( $this->profiling_consent() ) )->register();

		// Queue janitor (FABLE_AUDIT F6) — daily retention prune of terminal
		// sent/failed rows in both durable queues, so the tables stay bounded
		// in production. Scheduled in register_action_scheduler_jobs().
		( new QueueJanitor() )->register_hooks();

		// Admin UI (wizard + settings React mount). The two helpers in
		// admin/wizard.php are intentionally loaded only on admin requests
		// — there's no point pulling them in on REST or front-end loads.
		if ( is_admin() ) {
			require_once SMAILY_CONNECT_PLUGIN_PATH . 'admin/wizard.php';
			add_action( 'admin_menu', 'smaily_connect_register_admin_pages' );
			add_action( 'admin_enqueue_scripts', 'smaily_connect_enqueue_admin_bundle' );

			// The legacy "Smaily" top-level menu no longer needs hiding: the
			// legacy admin that registered it was removed (F3-45). The new
			// "Smaily Connect" menu (Setup wizard + Settings) is the only one.
		}
	}

	/**
	 * Register the namespaced /wp-json/smaily-connect/v1/* routes.
	 *
	 * Array-loop registration per Erkki's 2.H.3 spec: every endpoint
	 * sits in one place so a new route can't accidentally skip the
	 * registration line (sub-PR 2.H caught BackfillEndpoint dropping
	 * out of this list earlier because its constructor threw on
	 * unconfigured credentials — fixed now by making the BackfillJob
	 * dependency lazy via a factory). Phase 3 adds rec-engine routes
	 * by appending to this array, never by adding a parallel
	 * ->register() call.
	 */
	public function register_rest_endpoints(): void {
		foreach ( EndpointRegistry::endpoints( $this ) as $endpoint ) {
			$endpoint->register();
		}
	}

	/**
	 * AS callback for BackfillEndpoint::TICK_HOOK. Processes one batch
	 * (≤100 users by default per BackfillJob) and reschedules another
	 * tick 30s out if the job hasn't reached its terminal state.
	 *
	 * @param string $job_type Currently only "contacts"; Phase 3 adds more.
	 */
	public function on_backfill_tick( string $job_type = 'contacts' ): void {
		$job = $this->make_backfill_job( $job_type );
		if ( $job === null ) {
			error_log( sprintf( '[smaily-connect backfill.tick] no job for job_type=%s (unconfigured or unknown)', $job_type ) );
			return;
		}

		$result = $job->process_batch();

		// Reschedule until the job reaches its terminal state — each tick
		// resumes from the saved cursor (resumable; never restarts).
		if ( empty( $result['completed'] ) && function_exists( 'as_schedule_single_action' ) ) {
			as_schedule_single_action(
				time() + 30,
				BackfillEndpoint::TICK_HOOK,
				array( 'job_type' => $job_type ),
				EventQueue::AS_GROUP
			);
		}
	}

	/**
	 * Map a job_type to its backfill implementation — the single dispatch
	 * shared by the REST endpoint factory (EndpointRegistry) and the AS tick.
	 * Returns null when the job_type is unknown or its connection is absent.
	 */
	public function make_backfill_job( string $job_type ): ?BackfillJobInterface {
		switch ( $job_type ) {
			case BackfillJob::BACKFILL_TYPE: // 'contacts' — legacy Smaily.
				try {
					return new BackfillJob( $this->smaily_client() );
				} catch ( \RuntimeException $e ) {
					return null;
				}
			case 'products': // 3.5.0 rec-engine catalog backfill.
				return new CatalogBackfillJob(
					$this->ingest_queue(),
					$this->ingest_flusher(),
					$this->catalog_payload_builder(),
					$this->multilingual_detector()
				);
			case 'customers': // 3.5.1 rec-engine customer backfill (A-filter).
				return new CustomerBackfillJob(
					$this->ingest_queue(),
					$this->customer_flusher()
				);
			case 'orders': // 3.5.2 rec-engine order backfill (HPOS-aware, sale states).
				return new OrderBackfillJob(
					$this->ingest_queue(),
					$this->order_flusher()
				);
			default:
				return null;
		}
	}

	/**
	 * AS callback for smly_plus_contact_sync (daily). Bridges to the
	 * legacy `smaily_connect_cron_sync_subscribers` hook so the existing
	 * Smaily_Connect\Integrations\WooCommerce\Cron::smaily_sync_subscribers
	 * callback runs unchanged.
	 */
	public function on_contact_sync_tick(): void {
		do_action( 'smaily_connect_cron_sync_subscribers' );
	}

	/**
	 * AS callback for smly_plus_abandoned_cart (every 15 minutes). Fires
	 * the two legacy hook names in order — status first (which marks
	 * abandoned carts), then email (which sends the reminder). The same
	 * ordering the upstream WP-Cron used; both hooks fired in the same
	 * 15-minute interval, and the legacy Cron class's email handler
	 * relies on the status pass having run first.
	 */
	public function on_abandoned_cart_tick(): void {
		do_action( 'smaily_connect_cron_abandoned_carts_status' );
		do_action( 'smaily_connect_cron_abandoned_carts_email' );
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

		// Rec-engine catalog ingest (Phase 3.2.3). The handler self-gates on
		// RecEngineSettings::is_connected(), so registering unconditionally is
		// safe — the callbacks no-op until a tenant is connected.
		$catalog = new CatalogHookHandler(
			$this->ingest_queue(),
			$this->catalog_payload_builder(),
			$this->rec_engine_settings(),
			$this->multilingual_detector()
		);
		add_action( 'save_post_product', array( $catalog, 'on_save_product' ), 10, 1 );
		add_action( 'woocommerce_product_set_stock_status', array( $catalog, 'on_stock_change' ), 10, 3 );
		// Variations fire their OWN stock hook, not the parent's — without
		// this a variation selling out never refreshes its catalog row's
		// in_stock and the engine keeps recommending it (found 2026-06-12).
		// Same ($id, $status, $product) signature; the handler covers both.
		add_action( 'woocommerce_variation_set_stock_status', array( $catalog, 'on_stock_change' ), 10, 3 );
		// before_delete_post (not delete_post): the product is still loadable,
		// so the handler can capture its catalog object before it's gone.
		add_action( 'before_delete_post', array( $catalog, 'on_delete_product' ), 10, 1 );
		// Trashing is NOT a delete — before_delete_post never fires for it, so a
		// trashed product would silently keep its stale (in_stock=true) engine row
		// while it can no longer be bought. Route trash through the same removal
		// path: the engine keeps the SKU (no delete-by-key) as an in_stock=false
		// UPSERT, so order-history joins / training survive but it stops being
		// recommended. Untrash re-syncs real stock via the normal upsert path.
		// (Trash disabled, EMPTY_TRASH_DAYS=0 → trashing IS a permanent delete →
		// before_delete_post already covers it.)
		add_action( 'wp_trash_post', array( $catalog, 'on_delete_product' ), 10, 1 );
		add_action( 'untrashed_post', array( $catalog, 'on_save_product' ), 10, 1 );

		// Rec-engine customer ingest (Phase 3.3.3). Self-gates on
		// is_connected() like catalog; enqueues every registered user
		// (A-filter) on the §488 hook set, routed to the customer flusher.
		$customer = new CustomerHookHandler(
			$this->ingest_queue(),
			$this->rec_engine_settings()
		);
		add_action( 'user_register', array( $customer, 'on_user_register' ), 10, 1 );
		add_action( 'profile_update', array( $customer, 'on_profile_update' ), 10, 1 );
		add_action( 'woocommerce_created_customer', array( $customer, 'on_woocommerce_created_customer' ), 10, 1 );
		add_action( 'woocommerce_save_account_details', array( $customer, 'on_save_account_details' ), 10, 1 );

		// Rec-engine order ingest (Phase 3.3-orders.3). Self-gates on
		// is_connected(); enqueues on order-status changes whose mapped engine
		// status changes to a confirmed purchase (OrderHookHandler).
		$order = new OrderHookHandler(
			$this->ingest_queue(),
			$this->order_payload_builder(),
			$this->rec_engine_settings()
		);
		add_action( 'woocommerce_order_status_changed', array( $order, 'on_order_status_changed' ), 10, 3 );

		// Rec-engine identity merge (3.7). On login, explicitly bind the
		// anon-session cookies to the now-known customer (§7) — complementary to
		// the engine's automatic browse-event retroactive binding (§6).
		$bootstrap = $this;
		$identity  = new IdentityHookHandler(
			$this->rec_engine_settings(),
			static function () use ( $bootstrap ): RecEngineClient {
				return $bootstrap->rec_client();
			},
			// (a).1: don't retroactively bind an opted-out contact's anon browse
			// history — respect the profiling opt-out, even backwards.
			$this->profiling_consent()
		);
		$identity->register();

		// P1 #1: once the wizard is finished the new path owns contact sync,
		// so strip the legacy subscriber-sync hooks (the legacy service
		// re-registers them at every plugin load, so this must run every
		// request — not once at Finish). Before Finish the new HookHandler
		// gate keeps this path dormant and the legacy hooks stay in place.
		if ( (bool) get_option( 'smly_plus_setup_completed', false ) ) {
			LegacyHookBridge::deregister_subscriber_sync();
		}
	}

	/**
	 * Idempotent upgrade-detect. Runs the activation routine (migrations +
	 * AS scheduling) when the stored schema-owner version trails the running
	 * code — covering the upgrade paths that never fire
	 * register_activation_hook. Activation::run stamps the version, so the
	 * steady state is a single cheap get_option() per admin request.
	 */
	public function maybe_run_upgrade(): void {
		$current = defined( 'SMAILY_CONNECT_VERSION' ) ? (string) SMAILY_CONNECT_VERSION : '';
		if ( $current === '' ) {
			return;
		}

		if ( (string) get_option( Activation::OPTION_PLUGIN_VERSION, '' ) === $current ) {
			return;
		}

		Activation::run();
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

		// Rec-engine ingest flush — re-ticks every 60s so a row parked with a
		// future next_retry_at is picked up without a fresh product change.
		if ( ! as_has_scheduled_action( IngestQueue::FLUSH_HOOK, array(), IngestQueue::AS_GROUP ) ) {
			as_schedule_recurring_action( time(), 60, IngestQueue::FLUSH_HOOK, array(), IngestQueue::AS_GROUP );
		}

		// Customer ingest flush — its own recurring tick (separate hook/group),
		// so customer retries drain independently of the catalog cycle.
		if ( ! as_has_scheduled_action( CustomerFlusher::FLUSH_HOOK, array(), CustomerFlusher::AS_GROUP ) ) {
			as_schedule_recurring_action( time(), 60, CustomerFlusher::FLUSH_HOOK, array(), CustomerFlusher::AS_GROUP );
		}

		// Order ingest flush — its own recurring tick.
		if ( ! as_has_scheduled_action( OrderFlusher::FLUSH_HOOK, array(), OrderFlusher::AS_GROUP ) ) {
			as_schedule_recurring_action( time(), 60, OrderFlusher::FLUSH_HOOK, array(), OrderFlusher::AS_GROUP );
		}

		// Proactive health check (3.10.2) — every 15 min, recompute the admin-notice
		// signals (failed-count > threshold in 24h; engine unreachable > 1h). Slow
		// cadence: it makes a network ping and the signals are coarse-grained.
		if ( ! as_has_scheduled_action( NotificationManager::HEALTH_HOOK, array(), NotificationManager::AS_GROUP ) ) {
			as_schedule_recurring_action( time(), 900, NotificationManager::HEALTH_HOOK, array(), NotificationManager::AS_GROUP );
		}

		// Queue janitor — daily retention prune (sent 30d / failed 90d,
		// filterable) of both durable queues. Daily cadence: retention is
		// measured in days, and the LIMIT-batched DELETEs drain any backlog
		// over consecutive runs.
		if ( ! as_has_scheduled_action( QueueJanitor::HOOK, array(), QueueJanitor::AS_GROUP ) ) {
			as_schedule_recurring_action( time(), DAY_IN_SECONDS, QueueJanitor::HOOK, array(), QueueJanitor::AS_GROUP );
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
	 * Action Scheduler callback for smly_rec_flush_ingest — drains the
	 * rec-engine ingest queue (catalog in 3.2.3; customers/orders later).
	 */
	public function on_flush_ingest_queue(): void {
		$this->ingest_flusher()->flush();
	}

	/**
	 * Action Scheduler callback for smly_rec_flush_customers — drains the
	 * rec-engine ingest queue's customer.* rows through the D6 flusher.
	 */
	public function on_flush_customer_queue(): void {
		$this->customer_flusher()->flush();
	}

	/**
	 * Action Scheduler callback for smly_rec_flush_orders — drains the
	 * rec-engine ingest queue's order.* rows through the D6 flusher.
	 */
	public function on_flush_order_queue(): void {
		$this->order_flusher()->flush();
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

	public function ingest_queue(): IngestQueue {
		if ( $this->ingest_queue === null ) {
			$this->ingest_queue = new IngestQueue();
		}

		return $this->ingest_queue;
	}

	public function catalog_payload_builder(): CatalogPayloadBuilder {
		if ( $this->catalog_builder === null ) {
			$this->catalog_builder = new CatalogPayloadBuilder( $this->multilingual_detector() );
		}

		return $this->catalog_builder;
	}

	/**
	 * The active multilingual detector (WPML / Polylang / TranslatePress /
	 * single-language fallback). DetectorFactory caches its decision per
	 * request, so this is the one shared instance the catalog enumeration uses
	 * to collapse translations to a canonical product (catalog-correctness P1).
	 * SkuResolver resolves the same instance lazily for the order/browse SKU.
	 */
	public function multilingual_detector(): DetectorInterface {
		return DetectorFactory::create();
	}

	public function rec_engine_settings(): RecEngineSettings {
		if ( $this->rec_settings === null ) {
			$this->rec_settings = new RecEngineSettings();
		}

		return $this->rec_settings;
	}

	/**
	 * Build a rec-engine Client from the stored tenant config. Not cached —
	 * the api_key/endpoints can change on (dis)connect within a request, and
	 * a small max_attempts keeps the flush job from blocking the AS worker on
	 * long backoff (durable retry lives in IngestQueue).
	 */
	public function rec_client(): RecEngineClient {
		$settings = $this->rec_engine_settings();

		return new RecEngineClient(
			$settings->api_key(),
			$settings->base_url(),
			$settings->endpoints(),
			2
		);
	}

	/**
	 * Profiling-consent enforcement ((a).0/.1). The Smaily-client factory returns
	 * the default client only once the email wizard is finished — an un-set-up
	 * store has no contact to read consent from.
	 */
	public function profiling_consent(): ProfilingConsent {
		$bootstrap = $this;

		return new ProfilingConsent(
			$this->rec_engine_settings(),
			static function () use ( $bootstrap ): ?Client {
				if ( ! (bool) get_option( 'smly_plus_setup_completed', false ) ) {
					return null;
				}
				return $bootstrap->smaily_client();
			},
			static function () use ( $bootstrap ): RecEngineClient {
				return $bootstrap->rec_client();
			}
		);
	}

	public function ingest_flusher(): IngestFlusher {
		if ( $this->ingest_flusher === null ) {
			$bootstrap            = $this;
			$this->ingest_flusher = new IngestFlusher(
				$this->ingest_queue(),
				$this->catalog_payload_builder(),
				$this->rec_engine_settings(),
				static function () use ( $bootstrap ): RecEngineClient {
					return $bootstrap->rec_client();
				}
			);
		}

		return $this->ingest_flusher;
	}

	public function customer_payload_builder(): CustomerPayloadBuilder {
		if ( $this->customer_builder === null ) {
			$this->customer_builder = new CustomerPayloadBuilder();
		}

		return $this->customer_builder;
	}

	public function customer_flusher(): CustomerFlusher {
		if ( $this->customer_flusher === null ) {
			$bootstrap              = $this;
			$this->customer_flusher = new CustomerFlusher(
				$this->ingest_queue(),
				$this->customer_payload_builder(),
				$this->rec_engine_settings(),
				static function () use ( $bootstrap ): RecEngineClient {
					return $bootstrap->rec_client();
				}
			);
		}

		return $this->customer_flusher;
	}

	public function order_payload_builder(): OrderPayloadBuilder {
		if ( $this->order_builder === null ) {
			$this->order_builder = new OrderPayloadBuilder();
		}

		return $this->order_builder;
	}

	public function order_flusher(): OrderFlusher {
		if ( $this->order_flusher === null ) {
			$bootstrap           = $this;
			$this->order_flusher = new OrderFlusher(
				$this->ingest_queue(),
				$this->order_payload_builder(),
				$this->rec_engine_settings(),
				static function () use ( $bootstrap ): RecEngineClient {
					return $bootstrap->rec_client();
				}
			);
		}

		return $this->order_flusher;
	}

	public function automation_router(): AutomationRouter {
		if ( $this->automation_router === null ) {
			$bootstrap               = $this;
			$this->automation_router = new AutomationRouter(
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

	public function set_ingest_queue( IngestQueue $queue ): void {
		$this->ingest_queue   = $queue;
		$this->ingest_flusher = null; // rebuild with the new queue on next call
	}

	public function set_rec_engine_settings( RecEngineSettings $settings ): void {
		$this->rec_settings   = $settings;
		$this->ingest_flusher = null;
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
