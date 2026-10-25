<?php

namespace WpPcloudBackup\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WpPcloudBackup\Plugin;
use WpPcloudBackup\Storage\LocalStorage;
use WpPcloudBackup\Storage\PcloudClient;
use WpPcloudBackup\Tests\Support\TempDir;

final class PluginTest extends TestCase {

	public function test_local_dir_wins_over_token(): void {
		$dir = TempDir::create();

		try {
			$this->assertInstanceOf( LocalStorage::class, Plugin::buildStorage( 'token', $dir ) );
			$this->assertInstanceOf( LocalStorage::class, Plugin::buildStorage( null, $dir ) );
		} finally {
			TempDir::remove( $dir );
		}
	}

	public function test_token_builds_pcloud_client(): void {
		$this->assertInstanceOf( PcloudClient::class, Plugin::buildStorage( 'token', null ) );
		$this->assertInstanceOf( PcloudClient::class, Plugin::buildStorage( 'token', '', 'eapi.pcloud.com' ) );
	}

	public function test_no_token_and_no_local_dir_is_not_connected(): void {
		$this->assertNull( Plugin::buildStorage( null, null ) );
		$this->assertNull( Plugin::buildStorage( '', null ) );
		$this->assertNull( Plugin::buildStorage( null, '' ) );
	}

	public function test_dev_storage_dir_from_treats_undefined_and_empty_as_pcloud(): void {
		$this->assertNull( Plugin::devStorageDirFrom( null ) );
		$this->assertNull( Plugin::devStorageDirFrom( '' ) );
		$this->assertNull( Plugin::devStorageDirFrom( '   ' ) );
		$this->assertNull( Plugin::devStorageDirFrom( array( '/x' ) ) );
		$this->assertSame( '/var/www/html/wp-content/wppb-remote', Plugin::devStorageDirFrom( '/var/www/html/wp-content/wppb-remote' ) );
	}

	public function test_dev_storage_dir_is_null_when_the_constant_is_undefined(): void {
		$this->assertFalse( defined( 'WP_PCLOUD_BACKUP_STORAGE_DIR' ) );
		$this->assertNull( Plugin::devStorageDir() );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_dev_storage_dir_is_null_when_the_constant_is_empty(): void {
		define( 'WP_PCLOUD_BACKUP_STORAGE_DIR', '' );
		$this->assertNull( Plugin::devStorageDir() );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_dev_storage_dir_returns_the_constant(): void {
		define( 'WP_PCLOUD_BACKUP_STORAGE_DIR', '/srv/remote' );
		$this->assertSame( '/srv/remote', Plugin::devStorageDir() );
	}

	public function test_requirements_met_returns_empty_list(): void {
		$loaded = static function ( string $extension ): bool {
			return true;
		};

		$this->assertSame( array(), Plugin::missingRequirements( $loaded, 70400 ) );
		$this->assertSame( array(), Plugin::missingRequirements( $loaded, 80300 ) );
	}

	public function test_missing_extensions_are_named(): void {
		$loaded = static function ( string $extension ): bool {
			return 'curl' === $extension;
		};

		$this->assertSame( array( 'zlib', 'openssl' ), Plugin::missingRequirements( $loaded, 80200 ) );
	}

	public function test_old_php_is_reported_first(): void {
		$loaded = static function ( string $extension ): bool {
			return 'zlib' !== $extension;
		};

		$this->assertSame( array( 'PHP 7.4', 'zlib' ), Plugin::missingRequirements( $loaded, 70319 ) );
	}

	public function test_default_arguments_use_the_running_environment(): void {
		// The test environment (PHP >= 7.4 with zlib, curl and openssl) must satisfy the requirements.
		$this->assertSame( array(), Plugin::missingRequirements() );
	}
}
