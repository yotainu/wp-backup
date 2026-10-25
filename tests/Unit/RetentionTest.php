<?php

namespace WpPcloudBackup\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WpPcloudBackup\Retention;
use WpPcloudBackup\Storage\LocalStorage;
use WpPcloudBackup\Tests\Support\TempDir;

final class RetentionTest extends TestCase {

	private const NOW = 1792000000;

	/**
	 * @var string
	 */
	private $base;

	/**
	 * @var LocalStorage
	 */
	private $storage;

	/**
	 * @var int
	 */
	private $siteId;

	protected function setUp(): void {
		parent::setUp();
		$this->base    = TempDir::create();
		$this->storage = new LocalStorage( $this->base );
		$this->siteId  = $this->storage->ensureFolder( '/WPBackup/example.com' );
	}

	protected function tearDown(): void {
		TempDir::remove( $this->base );
		parent::tearDown();
	}

	/**
	 * Note: after Brain Monkey has been active in the same process, touch() stores mtime + 1,
	 * so assertions must not depend on an exact-second boundary.
	 */
	private function makeFolder( string $name, int $modified ): void {
		$this->storage->ensureFolder( '/WPBackup/example.com/' . $name );
		touch( $this->base . '/WPBackup/example.com/' . $name, $modified );
	}

	private function names( array $folders ): array {
		return array_column( $folders, 'name' );
	}

	public function test_generations_sorted_ascending_and_only_complete_folders(): void {
		$this->makeFolder( '2026-10-03_030000', self::NOW );
		$this->makeFolder( '2026-10-01_030000', self::NOW );
		$this->makeFolder( '2026-10-02_030000', self::NOW );
		$this->makeFolder( '.incomplete-2026-10-04_030000', self::NOW );
		$this->makeFolder( 'notes', self::NOW );

		$retention = new Retention( $this->storage, $this->siteId );

		$generations = $retention->generations();
		$this->assertSame(
			array( '2026-10-01_030000', '2026-10-02_030000', '2026-10-03_030000' ),
			$this->names( $generations )
		);
		$this->assertSame( array( 'id', 'name', 'modified' ), array_keys( $generations[0] ) );
		$this->assertSame( array( '.incomplete-2026-10-04_030000' ), $this->names( $retention->incompleteFolders() ) );
	}

	public function test_prune_keeps_newest_n(): void {
		foreach ( array( '01', '02', '03', '04', '05' ) as $day ) {
			$this->makeFolder( "2026-10-{$day}_030000", self::NOW );
		}

		$deleted = ( new Retention( $this->storage, $this->siteId ) )->prune( 3, self::NOW );

		$this->assertSame( array( '2026-10-01_030000', '2026-10-02_030000' ), $deleted );
		$this->assertDirectoryDoesNotExist( $this->base . '/WPBackup/example.com/2026-10-01_030000' );
		$this->assertDirectoryDoesNotExist( $this->base . '/WPBackup/example.com/2026-10-02_030000' );
		$this->assertDirectoryExists( $this->base . '/WPBackup/example.com/2026-10-03_030000' );
		$this->assertDirectoryExists( $this->base . '/WPBackup/example.com/2026-10-05_030000' );
	}

	public function test_prune_never_goes_below_one_generation(): void {
		$this->makeFolder( '2026-10-01_030000', self::NOW );
		$this->makeFolder( '2026-10-02_030000', self::NOW );

		$deleted = ( new Retention( $this->storage, $this->siteId ) )->prune( 0, self::NOW );

		$this->assertSame( array( '2026-10-01_030000' ), $deleted );
	}

	public function test_incomplete_folders_not_counted_and_pruned_after_ttl(): void {
		$this->makeFolder( '2026-10-01_030000', self::NOW );
		$this->makeFolder( '2026-10-02_030000', self::NOW );
		$this->makeFolder( '.incomplete-2026-09-01_030000', self::NOW - 172800 - 3600 );
		$this->makeFolder( '.incomplete-2026-10-24_030000', self::NOW - 3600 );

		$deleted = ( new Retention( $this->storage, $this->siteId ) )->prune( 2, self::NOW );

		$this->assertSame( array( '.incomplete-2026-09-01_030000' ), $deleted );
		$this->assertDirectoryDoesNotExist( $this->base . '/WPBackup/example.com/.incomplete-2026-09-01_030000' );
		$this->assertDirectoryExists( $this->base . '/WPBackup/example.com/.incomplete-2026-10-24_030000' );
		$this->assertDirectoryExists( $this->base . '/WPBackup/example.com/2026-10-01_030000' );
	}

	public function test_other_folders_untouched(): void {
		$this->makeFolder( '2026-10-01_030000', self::NOW );
		$this->makeFolder( '2026-10-02_030000', self::NOW );
		$this->makeFolder( 'notes', 1000 );
		$this->makeFolder( '.incomplete-garbage', 1000 );
		file_put_contents( $this->base . '/WPBackup/example.com/2026-10-00_000000', 'a file, not a folder' );
		file_put_contents( $this->base . '/WPBackup/example.com/readme.txt', 'x' );

		$deleted = ( new Retention( $this->storage, $this->siteId ) )->prune( 1, self::NOW );

		$this->assertSame( array( '2026-10-01_030000' ), $deleted );
		$this->assertDirectoryExists( $this->base . '/WPBackup/example.com/notes' );
		$this->assertDirectoryExists( $this->base . '/WPBackup/example.com/.incomplete-garbage' );
		$this->assertFileExists( $this->base . '/WPBackup/example.com/2026-10-00_000000' );
		$this->assertFileExists( $this->base . '/WPBackup/example.com/readme.txt' );
	}
}
