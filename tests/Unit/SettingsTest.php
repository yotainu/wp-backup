<?php

namespace WpPcloudBackup\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use WpPcloudBackup\Crypto;
use WpPcloudBackup\Settings;

final class SettingsTest extends TestCase {

	/**
	 * Fake wp_options table.
	 *
	 * @var array
	 */
	private $options = array();

	/**
	 * Every update_option() call as array( name, value, autoload ).
	 *
	 * @var array
	 */
	private $updates = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		if ( ! defined( 'AUTH_KEY' ) ) {
			define( 'AUTH_KEY', 'test-auth-key' );
		}
		if ( ! defined( 'SECURE_AUTH_KEY' ) ) {
			define( 'SECURE_AUTH_KEY', 'test-secure-auth-key' );
		}

		$this->options = array( 'admin_email' => 'admin@example.com' );
		$this->updates = array();

		Functions\when( 'get_option' )->alias(
			function ( $name, $default = false ) {
				return array_key_exists( $name, $this->options ) ? $this->options[ $name ] : $default;
			}
		);
		Functions\when( 'update_option' )->alias(
			function ( $name, $value, $autoload = null ) {
				$this->updates[]       = array( $name, $value, $autoload );
				$this->options[ $name ] = $value;
				return true;
			}
		);
		Functions\when( 'home_url' )->justReturn( 'https://example.com/blog' );
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
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_defaults_match_spec(): void {
		$d = ( new Settings() )->defaults();

		$this->assertSame( 'weekly', $d['schedule'] );
		$this->assertSame( '03:00', $d['start_time'] );
		$this->assertSame( 3, $d['keep'] );
		$this->assertSame( 25, $d['part_size_mb'] );
		$this->assertSame( 20, $d['run_budget_sec'] );
		$this->assertSame( '/WPBackup', $d['root_folder'] );
		$this->assertTrue( $d['use_wp_cron'] );
		$this->assertTrue( $d['auto_update'] );
		$this->assertFalse( $d['notify_on_success'] );
		$this->assertSame( 'example.com', $d['site_folder'] );
		$this->assertSame( 'admin@example.com', $d['notify_email'] );
		$this->assertSame( '', $d['cron_key'] );
		$this->assertSame( rtrim( ABSPATH, '/' ), $d['backup_root'] );
		$this->assertContains( 'wp-content/cache', $d['excludes'] );
		$this->assertContains( 'wp-content/uploads/backup*', $d['excludes'] );
		$this->assertContains( 'wp-content/updraft', $d['excludes'] );
		$this->assertContains( 'wp-content/ai1wm-backups', $d['excludes'] );
		$this->assertContains( '.git', $d['excludes'] );
		$this->assertContains( 'node_modules', $d['excludes'] );
		$this->assertSame( Settings::KEYS, array_keys( $d ) );
	}

	public function test_get_prefers_stored_value_over_default(): void {
		$this->options[ Settings::OPTION ] = array( 'keep' => 5 );
		$settings                          = new Settings();

		$this->assertSame( 5, $settings->get( 'keep' ) );
		$this->assertSame( 'weekly', $settings->get( 'schedule' ) );
		$this->assertSame( 5, $settings->all()['keep'] );
	}

	public function test_update_sanitizes_and_clamps(): void {
		$settings = new Settings();

		$this->assertSame( 30, $settings->update( array( 'keep' => 99 ) )['keep'] );
		$this->assertSame( 1, $settings->update( array( 'keep' => 0 ) )['keep'] );
		$this->assertSame( 7, $settings->update( array( 'keep' => '7' ) )['keep'] );
		$this->assertSame( 25, $settings->update( array( 'part_size_mb' => 64 ) )['part_size_mb'] );
		$this->assertSame( 100, $settings->update( array( 'part_size_mb' => '100' ) )['part_size_mb'] );
		$this->assertSame( 'weekly', $settings->update( array( 'schedule' => 'hourly' ) )['schedule'] );
		$this->assertSame( 'monthly', $settings->update( array( 'schedule' => 'monthly' ) )['schedule'] );
		$this->assertSame( '03:00', $settings->update( array( 'start_time' => '25:99' ) )['start_time'] );
		$this->assertSame( '23:59', $settings->update( array( 'start_time' => '23:59' ) )['start_time'] );
		$this->assertSame( 5, $settings->update( array( 'run_budget_sec' => 1 ) )['run_budget_sec'] );
		$this->assertSame( 120, $settings->update( array( 'run_budget_sec' => 999 ) )['run_budget_sec'] );
		$this->assertSame(
			'a@x.jp,b@x.jp',
			$settings->update( array( 'notify_email' => 'a@x.jp, bad, b@x.jp' ) )['notify_email']
		);
		$this->assertSame(
			array( 'wp-content/cache', 'foo/bar', 'baz' ),
			$settings->update( array( 'excludes' => "  /wp-content/cache \r\n\r\n//foo/bar\n   \nbaz" ) )['excludes']
		);
	}

	public function test_update_normalizes_folders_and_booleans(): void {
		$settings = new Settings();
		$result   = $settings->update(
			array(
				'root_folder'       => 'Backups/Sites//',
				'site_folder'       => 'my site/日本語.example',
				'notify_on_success' => '1',
				'use_wp_cron'       => '0',
				'auto_update'       => false,
			)
		);

		$this->assertSame( '/Backups/Sites', $result['root_folder'] );
		$this->assertSame( 'my_site' . str_repeat( '_', 10 ) . '.example', $result['site_folder'] );
		$this->assertTrue( $result['notify_on_success'] );
		$this->assertFalse( $result['use_wp_cron'] );
		$this->assertFalse( $result['auto_update'] );
		$this->assertSame( '/WPBackup', $settings->update( array( 'root_folder' => ' / ' ) )['root_folder'] );
		$this->assertSame( '/var/www/html', $settings->update( array( 'backup_root' => '/var/www/html/' ) )['backup_root'] );
	}

	public function test_update_persists_merged_array_with_autoload(): void {
		$this->options[ Settings::OPTION ] = array( 'keep' => 5 );
		$settings                          = new Settings();
		$settings->update( array( 'schedule' => 'daily', 'bogus' => 'x' ) );

		$this->assertCount( 1, $this->updates );
		$this->assertSame( Settings::OPTION, $this->updates[0][0] );
		$this->assertSame( array( 'keep' => 5, 'schedule' => 'daily' ), $this->updates[0][1] );
		$this->assertTrue( $this->updates[0][2] );
		$this->assertSame( 'daily', $settings->get( 'schedule' ) );
	}

	public function test_update_ignores_access_token_enc(): void {
		$settings = new Settings();
		$settings->update( array( 'access_token_enc' => 'evil' ) );

		$this->assertSame( '', $settings->get( 'access_token_enc' ) );
	}

	public function test_part_size_bytes(): void {
		$settings = new Settings();
		$this->assertSame( 25 * 1048576, $settings->partSizeBytes() );

		$settings->update( array( 'part_size_mb' => 50 ) );
		$this->assertSame( 50 * 1048576, $settings->partSizeBytes() );
	}

	public function test_set_access_token_stores_encrypted_value_and_clears_with_null(): void {
		$settings = new Settings();
		$settings->setAccessToken( 'tok' );

		$blob = $this->options[ Settings::OPTION ]['access_token_enc'];
		$this->assertNotSame( 'tok', $blob );
		$this->assertSame( 'tok', Crypto::decrypt( $blob ) );
		$this->assertSame( 'tok', $settings->accessToken() );
		$this->assertSame( 'tok', ( new Settings() )->accessToken() );

		$settings->setAccessToken( null );
		$this->assertSame( '', $this->options[ Settings::OPTION ]['access_token_enc'] );
		$this->assertNull( $settings->accessToken() );
	}

	public function test_access_token_is_null_without_constant_or_stored_token(): void {
		$this->assertNull( ( new Settings() )->accessToken() );
	}

	public function test_access_token_is_null_when_decryption_fails(): void {
		$this->options[ Settings::OPTION ] = array( 'access_token_enc' => 'garbage' );

		$this->assertNull( ( new Settings() )->accessToken() );
	}

	public function test_save_merges_keys_changed_by_other_processes(): void {
		$this->options[ Settings::OPTION ] = array( 'keep' => 2 );
		$settings                          = new Settings();
		$settings->get( 'keep' ); // This instance has read the option and never saw last_success.

		$this->options[ Settings::OPTION ] = array( 'keep' => 2, 'last_success' => 123 );
		$settings->update( array( 'keep' => 5 ) );

		$this->assertSame( array( 'keep' => 5, 'last_success' => 123 ), $this->options[ Settings::OPTION ] );
	}

	public function test_save_does_not_resurrect_stale_values_of_untouched_keys(): void {
		$this->options[ Settings::OPTION ] = array( 'keep' => 2, 'schedule' => 'daily' );
		$settings                          = new Settings();
		$settings->get( 'keep' );

		$this->options[ Settings::OPTION ] = array( 'keep' => 2, 'schedule' => 'monthly' );
		$settings->update( array( 'keep' => 5 ) );

		$this->assertSame( 'monthly', $this->options[ Settings::OPTION ]['schedule'] );
		$this->assertSame( 'monthly', $settings->get( 'schedule' ) );
	}

	public function test_dot_segments_in_folders_fall_back_to_default(): void {
		$settings = new Settings();

		$this->assertSame( 'example.com', $settings->update( array( 'site_folder' => '..' ) )['site_folder'] );
		$this->assertSame( 'example.com', $settings->update( array( 'site_folder' => '.' ) )['site_folder'] );
		$this->assertSame( '/WPBackup', $settings->update( array( 'root_folder' => '/WPBackup/../x' ) )['root_folder'] );
		$this->assertSame( '/WPBackup', $settings->update( array( 'root_folder' => './x' ) )['root_folder'] );
		$this->assertSame( '/a/b..c', $settings->update( array( 'root_folder' => 'a/b..c' ) )['root_folder'] );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_constant_overrides_option_and_locks_key(): void {
		define( 'WP_PCLOUD_BACKUP_KEEP', 7 );
		$this->options[ Settings::OPTION ] = array( 'keep' => 4 );
		$settings                          = new Settings();

		$this->assertSame( 7, $settings->get( 'keep' ) );
		$this->assertSame( 7, $settings->all()['keep'] );
		$this->assertTrue( $settings->isLocked( 'keep' ) );
		$this->assertFalse( $settings->isLocked( 'schedule' ) );

		$this->assertSame( 7, $settings->update( array( 'keep' => 2, 'schedule' => 'daily' ) )['keep'] );
		$this->assertSame( 'daily', $settings->get( 'schedule' ) );
		$this->assertSame( 4, $this->updates[0][1]['keep'] );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_constant_values_are_sanitized(): void {
		define( 'WP_PCLOUD_BACKUP_KEEP', '99' );
		define( 'WP_PCLOUD_BACKUP_SCHEDULE', 'hourly' );
		define( 'WP_PCLOUD_BACKUP_ROOT_FOLDER', 'Custom/' );
		define( 'WP_PCLOUD_BACKUP_NOTIFY_EMAIL', 'ops@x.jp, bad' );
		$settings = new Settings();

		$this->assertSame( 30, $settings->get( 'keep' ) );
		$this->assertSame( 'weekly', $settings->get( 'schedule' ) );
		$this->assertSame( '/Custom', $settings->get( 'root_folder' ) );
		$this->assertSame( 'ops@x.jp', $settings->get( 'notify_email' ) );
		$this->assertTrue( $settings->isLocked( 'notify_email' ) );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_access_token_constant_wins(): void {
		define( 'WP_PCLOUD_BACKUP_ACCESS_TOKEN', 'const-token' );
		$settings = new Settings();
		$settings->setAccessToken( 'stored-token' );

		$this->assertSame( 'const-token', $settings->accessToken() );
		$this->assertTrue( $settings->isLocked( 'access_token' ) );
	}
}
