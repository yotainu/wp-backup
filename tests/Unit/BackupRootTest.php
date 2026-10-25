<?php

namespace WpPcloudBackup\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WpPcloudBackup\BackupRoot;

final class BackupRootTest extends TestCase {

	public function test_same_path_when_site_equals_home(): void {
		$this->assertSame(
			'/var/www/html',
			BackupRoot::detect( '/var/www/html/', 'https://ex.jp', 'https://ex.jp' )
		);
	}

	public function test_goes_up_when_wp_in_subdir(): void {
		$this->assertSame(
			'/var/www/html',
			BackupRoot::detect( '/var/www/html/wp/', 'https://ex.jp/wp', 'https://ex.jp' )
		);
	}

	public function test_two_levels(): void {
		$this->assertSame(
			'/var/www/html',
			BackupRoot::detect( '/var/www/html/a/b/', 'https://ex.jp/a/b', 'https://ex.jp/' )
		);
	}

	public function test_ignores_when_home_is_deeper_than_site(): void {
		$this->assertSame(
			'/var/www/html',
			BackupRoot::detect( '/var/www/html/', 'https://ex.jp/', 'https://ex.jp/blog' )
		);
	}

	public function test_ignores_when_paths_diverge(): void {
		$this->assertSame(
			'/var/www/html/wp',
			BackupRoot::detect( '/var/www/html/wp/', 'https://ex.jp/wp', 'https://ex.jp/blog' )
		);
	}

	public function test_normalize_is_textual(): void {
		$this->assertSame( '/var/www/html', BackupRoot::normalize( '/var//www/./html/' ) );
		$this->assertSame( '/var/html', BackupRoot::normalize( '/var/www/../html' ) );
		$this->assertSame( '/', BackupRoot::normalize( '/' ) );
		$this->assertSame( '/', BackupRoot::normalize( '/..' ) );
	}

	public function test_validate_accepts_detected_abspath_and_between(): void {
		$this->assertTrue( BackupRoot::validate( '/var/www/html', '/var/www', '/var/www/html/wp' ) );
		$this->assertTrue( BackupRoot::validate( '/var/www', '/var/www', '/var/www/html/wp' ) );
		$this->assertTrue( BackupRoot::validate( '/var/www/html/wp/', '/var/www', '/var/www/html/wp/' ) );
	}

	public function test_validate_rejects_above_detected_and_outside_abspath(): void {
		$this->assertFalse( BackupRoot::validate( '/var', '/var/www', '/var/www/html/wp' ) );
		$this->assertFalse( BackupRoot::validate( '/etc', '/var/www', '/var/www/html/wp' ) );
		$this->assertFalse( BackupRoot::validate( '/var/www/html/wp/sub', '/var/www', '/var/www/html/wp' ) );
		$this->assertFalse( BackupRoot::validate( '/var/www/html2', '/var/www', '/var/www/html' ) );
		$this->assertFalse( BackupRoot::validate( '/var/www/../..', '/var/www', '/var/www/html' ) );
	}
	public function test_stays_at_abspath_when_the_folder_does_not_match_the_url(): void {
		// site_url .../wp but WordPress lives in .../wordpress (rewrite or alias): do not guess.
		$this->assertSame(
			'/var/www/html/wordpress',
			BackupRoot::detect( '/var/www/html/wordpress/', 'https://ex.jp/wp', 'https://ex.jp' )
		);
		// Only the last of two segments matches: still no guess.
		$this->assertSame(
			'/var/www/html/x/b',
			BackupRoot::detect( '/var/www/html/x/b/', 'https://ex.jp/a/b', 'https://ex.jp' )
		);
		// More URL segments than folders.
		$this->assertSame(
			'/wp',
			BackupRoot::detect( '/wp/', 'https://ex.jp/a/wp', 'https://ex.jp' )
		);
	}

	public function test_matches_percent_encoded_segments(): void {
		$this->assertSame(
			'/var/www/html',
			BackupRoot::detect( '/var/www/html/my wp/', 'https://ex.jp/my%20wp', 'https://ex.jp' )
		);
	}

	public function test_climbs_to_the_filesystem_root(): void {
		$this->assertSame( '/', BackupRoot::detect( '/wp/', 'https://ex.jp/wp', 'https://ex.jp' ) );
	}
}
