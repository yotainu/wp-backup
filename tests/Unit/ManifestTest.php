<?php

namespace WpPcloudBackup\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WpPcloudBackup\Manifest;

final class ManifestTest extends TestCase {

	public function test_contains_required_keys_and_unicode_unescaped(): void {
		$json = Manifest::build(
			array(
				'plugin_version' => '1.0.0',
				'created_at'     => 1792000000,
				'timezone'       => new \DateTimeZone( 'Asia/Tokyo' ),
				'site_url'       => 'https://example.com/blog',
				'home_url'       => 'https://example.com',
				'wp_version'     => '6.6',
				'php_version'    => '7.4.33',
				'backup_root'    => '/home/user/www',
				'abspath'        => '/home/user/www/blog/',
				'files_count'    => 12,
				'files_bytes'    => 3456,
				'parts'          => array(
					array(
						'name'   => 'files.zip.001',
						'size'   => 3456,
						'sha1'   => 'aa',
						'sha256' => 'bb',
					),
				),
				'database'       => array(
					'tables' => array( 'wp_posts', 'wp_options' ),
					'rows'   => 99,
					'sha1'   => 'cc',
					'sha256' => 'dd',
				),
				'warnings'       => array( '読めないファイル: 写真/猫.jpg' ),
				'excludes'       => array( '.git' ),
			)
		);

		$this->assertStringContainsString( '写真/猫.jpg', $json );
		$this->assertStringNotContainsString( '\u', $json );
		$this->assertStringNotContainsString( '\/', $json );

		$data = json_decode( $json, true );
		$this->assertSame(
			array( 'format', 'plugin_version', 'created_at', 'site_url', 'home_url', 'wp_version', 'php_version', 'backup_root', 'abspath', 'files_count', 'files_bytes', 'parts', 'database', 'warnings', 'excludes' ),
			array_keys( $data )
		);
		$this->assertSame( 1, $data['format'] );
		$this->assertSame( '2026-10-15T02:46:40+09:00', $data['created_at'] );
		$this->assertSame( 'files.zip.001', $data['parts'][0]['name'] );
		$this->assertSame(
			array( 'file', 'tables', 'rows', 'sha1', 'sha256' ),
			array_keys( $data['database'] )
		);
		$this->assertSame( 'database.sql.gz', $data['database']['file'] );
		$this->assertSame( array( 'wp_posts', 'wp_options' ), $data['database']['tables'] );
		$this->assertSame( 99, $data['database']['rows'] );
	}

	public function test_missing_input_falls_back_to_empty_values(): void {
		$data = json_decode( Manifest::build( array() ), true );

		$this->assertSame( 1, $data['format'] );
		$this->assertSame( '', $data['site_url'] );
		$this->assertSame( 0, $data['files_count'] );
		$this->assertSame( array(), $data['parts'] );
		$this->assertSame( array(), $data['warnings'] );
		$this->assertSame( 'database.sql.gz', $data['database']['file'] );
		$this->assertSame( array(), $data['database']['tables'] );
		$this->assertSame( '1970-01-01T00:00:00+00:00', $data['created_at'] );
	}

	public function test_preformatted_created_at_is_kept(): void {
		$data = json_decode( Manifest::build( array( 'created_at' => '2026-10-25T03:17:00+09:00' ) ), true );

		$this->assertSame( '2026-10-25T03:17:00+09:00', $data['created_at'] );
	}
}
