<?php
/**
 * PHPUnit bootstrap for Smaily Connect.
 *
 * Loads the Composer autoloader so unit tests can reference Smaily\Connect\*
 * classes directly. WordPress core functions used inside the code under test
 * are mocked per-test via Brain\Monkey; the bootstrap deliberately does NOT
 * load a real WordPress test installation. That setup belongs to the
 * integration test suite (added in a later phase once we have WP-CLI
 * scaffolding and a database fixture).
 *
 * @package Smaily\Connect\Tests
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

// ABSPATH is checked by the main plugin file's guard. Tests that load the
// plugin entry directly need it set; class-only tests don't, but defining it
// once here is harmless and keeps the bootstrap consistent.
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/../' );
}

// Constants normally defined by WordPress at request time. Define safe stubs
// so any inadvertent reference from the code under test resolves to a value
// instead of an "Undefined constant" notice.
if ( ! defined( 'WPINC' ) ) {
	define( 'WPINC', 'wp-includes' );
}
if ( ! defined( 'SMAILY_CONNECT_PLUGIN_FILE' ) ) {
	define( 'SMAILY_CONNECT_PLUGIN_FILE', __DIR__ . '/../smaily-connect.php' );
}
if ( ! defined( 'SMAILY_CONNECT_PLUGIN_PATH' ) ) {
	define( 'SMAILY_CONNECT_PLUGIN_PATH', __DIR__ . '/../' );
}
if ( ! defined( 'SMAILY_CONNECT_VERSION' ) ) {
	define( 'SMAILY_CONNECT_VERSION', '2.0.0-beta.1' );
}
