<?php

namespace WpPcloudBackup\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WpPcloudBackup\Retention;
use WpPcloudBackup\Storage\LocalStorage;
use WpPcloudBackup\Tests\Support\ArrayStorage;
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
	 * Note: once Brain Monkey has run in the same PHP process, Patchwork's file:// stream
	 * wrapper intercepts touch() and the stored mtime ends up 1 second late. Tests that
	 * use real mtimes therefore keep an hour of margin; exact boundaries are tested with
	 * ArrayStorage, which returns the given `modified` values verbatim.
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

	private function row( int $id, string $name, $modified, bool $isFolder = true ): array {
		return array(
			'id'       => $id,
			'name'     => $name,
			'isfolder' => $isFolder,
			'size'     => 0,
			'modified' => $modified,
		);
	}

	public function test_incomplete_ttl_boundary_is_exclusive(): void {
		$ttl     = 172800;
		$storage = new ArrayStorage(
			array(
				$this->row( 1, '.incomplete-2026-09-01_000000', self::NOW - $ttl ),
				$this->row( 2, '.incomplete-2026-09-02_000000', self::NOW - $ttl - 1 ),
				$this->row( 3, '.incomplete-2026-09-03_000000', self::NOW - $ttl + 1 ),
			)
		);

		$deleted = ( new Retention( $storage, 0, $ttl ) )->prune( 3, self::NOW );

		$this->assertSame( array( '.incomplete-2026-09-02_000000' ), $deleted );
		$this->assertSame( array( 2 ), $storage->deletedFolders );
	}

	public function test_incomplete_folder_with_unknown_modified_is_never_pruned(): void {
		$storage = new ArrayStorage(
			array(
				$this->row( 1, '.incomplete-2026-09-01_000000', 0 ),
				$this->row( 2, '.incomplete-2026-09-02_000000', null ),
				$this->row( 3, '.incomplete-2026-09-03_000000', 'unknown' ),
				array(
					'id'       => 4,
					'name'     => '.incomplete-2026-09-04_000000',
					'isfolder' => true,
				),
				$this->row( 5, '.incomplete-2026-09-05_000000', -5 ),
				$this->row( 6, '.incomplete-2026-08-01_000000', 1 ),
			)
		);

		$retention = new Retention( $storage, 0 );
		$deleted   = $retention->prune( 3, self::NOW );

		// Only the folder with a known, old mtime goes.
		$this->assertSame( array( '.incomplete-2026-08-01_000000' ), $deleted );
		$this->assertSame( array( 6 ), $storage->deletedFolders );
		$this->assertSame( 0, $retention->incompleteFolders()[3]['modified'] );
	}
}
