<?php

namespace WpPcloudBackup\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use WpPcloudBackup\Settings;
use WpPcloudBackup\Tests\Support\TempDir;
use WpPcloudBackup\WorkDir;

final class WorkDirTest extends TestCase {

	/**
	 * Fake wp_options table.
	 *
	 * @var array
	 */
	private $options = array();

	/**
	 * Temporary uploads base directory.
	 *
	 * @var string
	 */
	private $uploads;

	/**
	 * Original SERVER_SOFTWARE value.
	 *
	 * @var mixed
	 */
	private $serverSoftware;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->uploads        = TempDir::create();
		$this->serverSoftware = $_SERVER['SERVER_SOFTWARE'] ?? null;
		$this->options        = array( 'admin_email' => 'admin@example.com' );

		Functions\when( 'get_option' )->alias(
			function ( $name, $default = false ) {
				return array_key_exists( $name, $this->options ) ? $this->options[ $name ] : $default;
			}
		);
		Functions\when( 'update_option' )->alias(
			function ( $name, $value ) {
				$this->options[ $name ] = $value;
				return true;
			}
		);
		Functions\when( 'wp_cache_delete' )->justReturn( true );
		Functions\when( 'home_url' )->justReturn( 'https://example.com/blog' );
		Functions\when( 'site_url' )->justReturn( 'https://example.com/blog' );
		Functions\when( 'sanitize_email' )->alias(
			function ( $email ) {
				return false === filter_var( $email, FILTER_VALIDATE_EMAIL ) ? '' : $email;
			}
		);
		Functions\when( 'sanitize_text_field' )->alias(
			function ( $text ) {
				return trim( strip_tags( (string) $text ) );
			}
		);
		Functions\when( 'wp_mkdir_p' )->alias(
			function ( $dir ) {
				return is_dir( $dir ) || mkdir( $dir, 0755, true );
			}
		);
	}

	protected function tearDown(): void {
		if ( null === $this->serverSoftware ) {
			unset( $_SERVER['SERVER_SOFTWARE'] );
		} else {
			$_SERVER['SERVER_SOFTWARE'] = $this->serverSoftware;
		}
		TempDir::remove( $this->uploads );
		Monkey\tearDown();
		parent::tearDown();
	}

	private function workDir(): WorkDir {
		return new WorkDir( new Settings(), $this->uploads );
	}

	public function test_ensure_creates_random_dir_with_htaccess_and_index(): void {
		$workDir = $this->workDir();
		$path    = $workDir->ensure();

		$this->assertDirectoryExists( $path );
		$this->assertSame( $this->uploads, dirname( $path ) );
		$this->assertMatchesRegularExpression( '/^wp-pcloud-backup-[a-z0-9]{16}$/', basename( $path ) );
		$this->assertSame( basename( $path ), ( new Settings() )->get( 'workdir_name' ) );

		$htaccess = file_get_contents( $path . '/.htaccess' );
		$this->assertStringContainsString( 'Require all denied', $htaccess );
		$this->assertStringContainsString( 'Deny from all', $htaccess );
		$this->assertFileExists( $path . '/index.php' );

		$this->assertSame( $path, $workDir->ensure() );
		$this->assertSame( $path, $this->workDir()->ensure() );
		$this->assertCount( 1, glob( $this->uploads . '/*' ) );
	}

	public function test_ensure_recreates_protection_files_when_missing(): void {
		$path = $this->workDir()->ensure();
		unlink( $path . '/.htaccess' );
		unlink( $path . '/index.php' );

		$this->assertSame( $path, $this->workDir()->ensure() );
		$this->assertFileExists( $path . '/.htaccess' );
		$this->assertFileExists( $path . '/index.php' );
	}

	public function test_ensure_regenerates_when_saved_directory_is_gone(): void {
		$workDir = $this->workDir();
		$first   = $workDir->ensure();
		TempDir::remove( $first );

		$second = $this->workDir()->ensure();

		$this->assertDirectoryExists( $second );
		$this->assertNotSame( $first, $second );
	}

	public function test_path_joins_file_and_throws_without_name(): void {
		$workDir = $this->workDir();

		try {
			$workDir->path();
			$this->fail( 'Expected RuntimeException.' );
		} catch ( \RuntimeException $e ) {
			$this->assertNotSame( '', $e->getMessage() );
		}

		$dir = $workDir->ensure();
		$this->assertSame( $dir, $workDir->path() );
		$this->assertSame( $dir . '/job.json', $workDir->path( 'job.json' ) );
	}

	public function test_is_web_server_apache(): void {
		$_SERVER['SERVER_SOFTWARE'] = 'Apache/2.4.57 (Unix)';
		$this->assertTrue( $this->workDir()->isWebServerApache() );

		$_SERVER['SERVER_SOFTWARE'] = 'LiteSpeed';
		$this->assertTrue( $this->workDir()->isWebServerApache() );

		$_SERVER['SERVER_SOFTWARE'] = 'nginx/1.25.0';
		$this->assertFalse( $this->workDir()->isWebServerApache() );

		unset( $_SERVER['SERVER_SOFTWARE'] );
		$this->assertFalse( $this->workDir()->isWebServerApache() );
	}

	public function test_free_bytes_returns_int_or_null(): void {
		$workDir = $this->workDir();
		$this->assertNull( $workDir->freeBytes() );

		$workDir->ensure();
		$free = $workDir->freeBytes();
		$this->assertIsInt( $free );
		$this->assertGreaterThan( 0, $free );
	}
}
