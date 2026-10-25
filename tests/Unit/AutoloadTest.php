<?php

namespace WpPcloudBackup\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class AutoloadTest extends TestCase {

	public function test_autoloader_resolves_namespace_to_includes_dir(): void {
		$this->assertTrue( class_exists( \WpPcloudBackup\Plugin::class ) );
		$this->assertSame( 'wp-pcloud-backup', WP_PCLOUD_BACKUP_SLUG );
	}
}
