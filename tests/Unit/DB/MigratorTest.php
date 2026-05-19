<?php
/**
 * Migrator unit tests — exercise discovery + version-gating logic without
 * standing up a real WP install. The dbDelta() call inside apply() is the
 * one bit we can't test here; the integration suite (added later) covers
 * that path against a real test database.
 *
 * @package Smaily\Connect\Tests
 */

declare(strict_types=1);

namespace Smaily\Connect\Tests\Unit\DB;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Smaily\Connect\Constants;
use Smaily\Connect\DB\Migrator;

final class MigratorTest extends TestCase {

	private string $tmp_dir = '';

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->tmp_dir = sys_get_temp_dir() . '/smaily-migrator-' . uniqid();
		mkdir( $this->tmp_dir, 0777, true );
	}

	protected function tearDown(): void {
		$this->remove_dir( $this->tmp_dir );
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_discover_returns_empty_array_when_directory_is_missing(): void {
		$migrator = new Migrator( $this->tmp_dir . '/does-not-exist' );
		self::assertSame( array(), $migrator->discover() );
	}

	public function test_discover_picks_up_files_matching_nnn_dash_slug_sql(): void {
		$this->seed_file( '001-create-event-queue.sql', 'CREATE TABLE foo;' );
		$this->seed_file( '002-create-backfill-job.sql', 'CREATE TABLE bar;' );

		$migrator = new Migrator( $this->tmp_dir );
		$found    = $migrator->discover();

		self::assertSame( array( 1, 2 ), array_keys( $found ) );
		self::assertStringEndsWith( '001-create-event-queue.sql', $found[1] );
		self::assertStringEndsWith( '002-create-backfill-job.sql', $found[2] );
	}

	public function test_discover_ignores_legacy_php_files_and_unnumbered_sql(): void {
		$this->seed_file( '001-create-event-queue.sql', 'CREATE TABLE foo;' );
		$this->seed_file( 'upgrade-1-3-0.php', '<?php // legacy' );
		$this->seed_file( 'create-something.sql', 'CREATE TABLE no_number;' );
		$this->seed_file( 'index.php', '<?php' );

		$migrator = new Migrator( $this->tmp_dir );
		$found    = $migrator->discover();

		self::assertSame( array( 1 ), array_keys( $found ) );
	}

	public function test_discover_sorts_numerically_not_lexically(): void {
		$this->seed_file( '001-a.sql', 'x' );
		$this->seed_file( '010-b.sql', 'x' );
		$this->seed_file( '002-c.sql', 'x' );
		$this->seed_file( '011-d.sql', 'x' );

		$migrator = new Migrator( $this->tmp_dir );
		$found    = $migrator->discover();

		self::assertSame(
			array( 1, 2, 10, 11 ),
			array_keys( $found ),
			'Migrations must sort numerically — lexical sort would put 010/011 before 002.'
		);
	}

	public function test_migrate_does_nothing_when_no_migrations_exist(): void {
		Functions\expect( 'get_option' )
			->once()
			->with( Constants::OPTION_SCHEMA_VERSION, 0 )
			->andReturn( 0 );

		Functions\expect( 'update_option' )->never();

		$migrator = new Migrator( $this->tmp_dir );
		self::assertSame( array(), $migrator->migrate() );
	}

	public function test_migrate_skips_versions_at_or_below_current(): void {
		$this->seed_file( '001-a.sql', 'CREATE TABLE foo;' );
		$this->seed_file( '002-b.sql', 'CREATE TABLE bar;' );

		Functions\expect( 'get_option' )
			->once()
			->with( Constants::OPTION_SCHEMA_VERSION, 0 )
			->andReturn( 2 );

		Functions\expect( 'update_option' )->never();

		$migrator = new Migrator( $this->tmp_dir );
		self::assertSame( array(), $migrator->migrate() );
	}

	public function test_discover_skips_zero_or_negative_version_prefix(): void {
		$this->seed_file( '000-zero.sql', 'x' );
		$this->seed_file( '001-real.sql', 'x' );

		$migrator = new Migrator( $this->tmp_dir );
		$found    = $migrator->discover();

		self::assertSame( array( 1 ), array_keys( $found ) );
	}

	private function seed_file( string $name, string $contents ): void {
		file_put_contents( $this->tmp_dir . '/' . $name, $contents );
	}

	private function remove_dir( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			return;
		}

		foreach ( (array) glob( $dir . '/*' ) as $entry ) {
			is_dir( $entry ) ? $this->remove_dir( $entry ) : unlink( $entry );
		}

		rmdir( $dir );
	}
}
