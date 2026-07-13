<?php
/**
 * Pins uninstall.php's cleanup coverage for the ProfilingConsent state
 * (PRO-1336) against the actual class constants — so a rename of the
 * option/transient prefixes or a stripped cleanup line fails loudly.
 *
 * uninstall.php itself is NOT executed here: it DROPs the plugin's custom
 * tables and bulk-deletes wp_options rows, which is destructive to run
 * inside the shared test process (see tests/Integration/Support/EnvScrub.php,
 * built specifically as a non-destructive sibling for test isolation). A
 * source-level pin is the safe, cheap alternative.
 *
 * @package Smaily\Connect\Tests
 */

declare(strict_types=1);

namespace Smaily\Connect\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Smaily\Connect\Privacy\ProfilingConsent;

final class UninstallCleanupTest extends TestCase {

	private function uninstall_source(): string {
		return (string) file_get_contents( dirname( __DIR__, 2 ) . '/uninstall.php' );
	}

	public function test_removes_the_durable_profiling_optout_registry_option(): void {
		$option = ( new ReflectionClass( ProfilingConsent::class ) )->getConstant( 'OPTION_OPTOUTS' );

		self::assertIsString( $option );
		self::assertStringContainsString(
			"'{$option}'",
			$this->uninstall_source(),
			'uninstall.php must delete the ProfilingConsent durable opt-out registry option (PRO-1336).'
		);
	}

	public function test_sweeps_the_profiling_consent_cache_transients_by_prefix(): void {
		$reflection   = new ReflectionClass( ProfilingConsent::class );
		$cache_prefix = $reflection->getConstant( 'CACHE_PREFIX' );
		$stale_prefix = $reflection->getConstant( 'STALE_CACHE_PREFIX' );

		self::assertIsString( $cache_prefix );
		self::assertIsString( $stale_prefix );
		// The stale-cache prefix nests inside the fresh-cache prefix, so a
		// single LIKE sweep on the fresh prefix must catch both transients.
		self::assertStringStartsWith( $cache_prefix, $stale_prefix );

		$source = $this->uninstall_source();
		self::assertStringContainsString(
			"_transient_{$cache_prefix}",
			$source,
			'uninstall.php must sweep the ProfilingConsent transient VALUE rows by prefix (PRO-1336).'
		);
		self::assertStringContainsString(
			"_transient_timeout_{$cache_prefix}",
			$source,
			'uninstall.php must sweep the ProfilingConsent transient TIMEOUT rows by prefix (PRO-1336).'
		);
	}
}
