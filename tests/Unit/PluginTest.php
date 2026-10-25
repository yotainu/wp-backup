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
}
