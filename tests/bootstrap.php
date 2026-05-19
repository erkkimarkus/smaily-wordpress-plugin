<?php
/**
 * PHPUnit bootstrap for Smaily Connect.
 *
 * Order matters: WordPress's defined('ABSPATH') || exit guard at the top of
 * every plugin PHP file means class-files will short-circuit (exit) if they
 * are loaded before ABSPATH is defined. We therefore define ABSPATH *before*
 * requiring the Composer autoloader so PSR-4 lazy-loading sees a sane env
 * even if a test triggers a class load during autoload registration.
 *
 * WordPress core functions used inside the code under test are mocked
 * per-test via Brain\Monkey; the bootstrap deliberately does NOT load a
 * real WordPress test installation. That setup belongs to the integration
 * test suite (added in a later phase once we have WP-CLI scaffolding and
 * a database fixture).
 *
 * @package Smaily\Connect\Tests
 */

declare(strict_types=1);

// 1. Constants that gate plugin-file inclusion go FIRST, before autoload.
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/../' );
}
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

// 2. Composer autoloader — registers PSR-4 mappings. Actual class files
//    load lazily on first reference; by now ABSPATH is set so the
//    direct-access guards in those files won't short-circuit.
require_once __DIR__ . '/../vendor/autoload.php';
