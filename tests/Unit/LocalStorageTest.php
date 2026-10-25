<?php

namespace WpPcloudBackup\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WpPcloudBackup\Storage\LocalStorage;
use WpPcloudBackup\Storage\StorageException;
use WpPcloudBackup\Tests\Support\TempDir;

final class LocalStorageTest extends TestCase {

	/**
	 * @var string
	 */
	private $base;

	/**
	 * @var string
	 */
	private $src;

	protected function setUp(): void {
		parent::setUp();
		$this->base = TempDir::create();
		$this->src  = TempDir::create();
	}

	protected function tearDown(): void {
		TempDir::remove( $this->base );
		TempDir::remove( $this->src );
		parent::tearDown();
	}

	public function test_roundtrip_folder_upload_list_rename_delete(): void {
		$storage = new LocalStorage( $this->base );

		$site = $storage->ensureFolder( '/WPBackup/example.com' );
		$this->assertGreaterThan( 0, $site );
		$this->assertSame( $site, $storage->ensureFolder( '/WPBackup/example.com' ) );
		$this->assertDirectoryExists( $this->base . '/WPBackup/example.com' );

		$file = $this->src . '/a.zip';
		file_put_contents( $file, 'hello' );
		$uploaded = $storage->uploadFile( $site, $file, 'a.zip' );
		$this->assertSame( 5, $uploaded['size'] );
		$this->assertSame( sha1( 'hello' ), $uploaded['sha1'] );
		$this->assertSame( 'hello', file_get_contents( $this->base . '/WPBackup/example.com/a.zip' ) );

		$run = $storage->ensureFolder( '/WPBackup/example.com/2026-10-24' );
		$list = $storage->listFolder( $site );
		$names = array_column( $list, 'name' );
		sort( $names );
		$this->assertSame( array( '2026-10-24', 'a.zip' ), $names );
		$byName = array_column( $list, null, 'name' );
		$this->assertTrue( $byName['2026-10-24']['isfolder'] );
		$this->assertSame( $run, $byName['2026-10-24']['id'] );
		$this->assertFalse( $byName['a.zip']['isfolder'] );
		$this->assertSame( $uploaded['id'], $byName['a.zip']['id'] );
		$this->assertSame( 5, $byName['a.zip']['size'] );
		$this->assertGreaterThan( 0, $byName['a.zip']['modified'] );

		$this->assertSame(
			array(
				'sha1' => sha1( 'hello' ),
				'md5'  => md5( 'hello' ),
				'size' => 5,
			),
			$storage->checksum( $uploaded['id'] )
		);

		$storage->renameFolder( $run, 'done' );
		$this->assertDirectoryExists( $this->base . '/WPBackup/example.com/done' );
		$this->assertDirectoryDoesNotExist( $this->base . '/WPBackup/example.com/2026-10-24' );

		$storage->deleteFile( $uploaded['id'] );
		$this->assertFileDoesNotExist( $this->base . '/WPBackup/example.com/a.zip' );

		$storage->deleteFolder( $run );
		$this->assertDirectoryDoesNotExist( $this->base . '/WPBackup/example.com/done' );
		$this->assertSame( array(), $storage->listFolder( $site ) );
	}

	public function test_rename_updates_descendant_ids(): void {
		$storage = new LocalStorage( $this->base );
		$child   = $storage->ensureFolder( '/parent/child' );
		$parent  = $storage->ensureFolder( '/parent' );
		$file    = $this->src . '/f.txt';
		file_put_contents( $file, 'x' );
		$uploaded = $storage->uploadFile( $child, $file, 'f.txt' );

		$storage->renameFolder( $parent, 'renamed' );

		// A fresh instance reads persisted ids.
		$fresh = new LocalStorage( $this->base );
		$this->assertSame( $child, $fresh->ensureFolder( '/renamed/child' ) );
		$list = $fresh->listFolder( $child );
		$this->assertSame( 'f.txt', $list[0]['name'] );
		$this->assertSame( $uploaded['id'], $list[0]['id'] );
		$this->assertSame( 1, $fresh->checksum( $uploaded['id'] )['size'] );
	}

	public function test_delete_folder_drops_descendant_ids(): void {
		$storage = new LocalStorage( $this->base );
		$child   = $storage->ensureFolder( '/p/c' );
		$parent  = $storage->ensureFolder( '/p' );

		$storage->deleteFolder( $parent );

		$this->expectException( StorageException::class );
		$storage->listFolder( $child );
	}

	public function test_missing_ids_throw_non_retryable(): void {
		$storage = new LocalStorage( $this->base );

		foreach ( array(
			function () use ( $storage ) {
				$storage->listFolder( 999 );
			},
			function () use ( $storage ) {
				$storage->deleteFile( 999 );
			},
			function () use ( $storage ) {
				$storage->checksum( 999 );
			},
			function () use ( $storage ) {
				$storage->renameFolder( 999, 'x' );
			},
			function () use ( $storage ) {
				$storage->deleteFolder( 999 );
			},
		) as $call ) {
			try {
				$call();
				$this->fail( 'Expected StorageException' );
			} catch ( StorageException $e ) {
				$this->assertFalse( $e->isRetryable() );
				$this->assertSame( 'other', $e->category() );
			}
		}
	}

	public function test_upload_missing_source_throws(): void {
		$storage = new LocalStorage( $this->base );

		$this->expectException( StorageException::class );
		$storage->uploadFile( 0, $this->src . '/nope', 'x' );
	}
}
