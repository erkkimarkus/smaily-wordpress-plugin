<?php
/**
 * WC + WP hook registrar — wires HookHandler callbacks into add_action().
 *
 * @package Smaily\Connect\Integrations\WooCommerce
 */

declare(strict_types=1);

namespace Smaily\Connect\Integrations\WooCommerce;

defined( 'ABSPATH' ) || exit;

/**
 * Thin registrar: one add_action call per hook the HookHandler exposes.
 * Lives separately from the handler so the wiring is visible at a glance,
 * tests can substitute an alternate handler without re-registering hooks,
 * and a future "disable hook X" flag can target one line here without
 * touching the dispatch logic.
 *
 * Hooks wired in Phase 1:
 *
 *   - user_register                              → on_user_register
 *   - profile_update                             → on_profile_update
 *   - woocommerce_save_account_details           → on_profile_update
 *     (WC fires this when the customer saves their account form in
 *     My Account; profile_update may or may not fire alongside it
 *     depending on which fields changed — registering both ensures we
 *     don't miss e.g. an email change)
 *   - woocommerce_created_customer               → on_woocommerce_created_customer
 *   - woocommerce_checkout_order_processed       → on_checkout_order_processed
 *
 * Rec-engine-specific hooks (save_post_product, woocommerce_order_status_changed,
 * before_delete_post for products) land in Phase 3 alongside the rec-engine
 * integration tree. They aren't registered here because the BETA fork's
 * Phase 1 surface deliberately stops at Smaily-marketing events.
 */
final class Hooks {

	public static function register( HookHandler $handler ): void {
		add_action( 'user_register', array( $handler, 'on_user_register' ), 10, 1 );
		add_action( 'profile_update', array( $handler, 'on_profile_update' ), 10, 1 );
		add_action( 'woocommerce_save_account_details', array( $handler, 'on_profile_update' ), 10, 1 );
		add_action( 'woocommerce_created_customer', array( $handler, 'on_woocommerce_created_customer' ), 10, 1 );
		add_action( 'woocommerce_checkout_order_processed', array( $handler, 'on_checkout_order_processed' ), 10, 1 );
	}

	private function __construct() {
		// Static-only registrar.
	}
}
