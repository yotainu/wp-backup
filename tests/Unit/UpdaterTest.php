<?php

namespace WpPcloudBackup\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use WpPcloudBackup\Notifier;
use WpPcloudBackup\Settings;
use WpPcloudBackup\Tests\Support\TempDir;
use WpPcloudBackup\Updater;

final class UpdaterTest extends TestCase {

	private const BASENAME = 'wp-pcloud-backup/wp-pcloud-backup.php';
	private const ZIP_URL  = 'https://github.com/OWNER/wp-pcloud-backup/releases/download/v0.2.0/wp-pcloud-backup.zip';
	private const SHA_URL  = 'https://github.com/OWNER/wp-pcloud-backup/releases/download/v0.2.0/wp-pcloud-backup.zip.sha256';

	private const HOOK_EXTRA = array( 'plugin' => self::BASENAME );

	/**
	 * Scripted httpGet() results: array{status,body}, null (transport failure) or a Throwable.
	 *
	 * @var array
	 */
	private $queue = array();

	/**
	 * Recorded httpGet() calls: url, headers.
	 *
	 * @var array
	 */
	private $requests = array();

	/**
	 * In-memory site transients.
	 *
	 * @var array
	 */
	private $transients = array();

	/**
	 * TTL of each site transient written.
	 *
	 * @var array
	 */
	private $ttls = array();

	/**
	 * Mails sent through wp_mail().
	 *
	 * @var array
	 */
	private $mails = array();

	/**
	 * @var string
	 */
	private $dir = '';

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\stubTranslationFunctions();
		Functions\stubEscapeFunctions();

		$this->queue      = array();
		$this->requests   = array();
		$this->transients = array();
		$this->ttls       = array();
		$this->mails      = array();
		$this->dir        = TempDir::create();

		Functions\when( 'get_site_transient' )->alias(
			function ( $key ) {
				return $this->transients[ $key ] ?? false;
			}
		);
		Functions\when( 'set_site_transient' )->alias(
			function ( $key, $value, $ttl = 0 ) {
				$this->transients[ $key ] = $value;
				$this->ttls[ $key ]       = $ttl;
				return true;
			}
		);
		Functions\when( 'wp_mail' )->alias(
			function ( $to, $subject, $message ) {
				$this->mails[] = array( $to, $subject, $message );
				return true;
			}
		);
	}

	protected function tearDown(): void {
		TempDir::remove( $this->dir );
		Monkey\tearDown();
		parent::tearDown();
	}

	private function queueJson( array $data, int $status = 200 ): void {
		$this->queue[] = array(
			'status' => $status,
			'body'   => json_encode( $data ),
		);
	}

	private function queueBody( string $body, int $status = 200 ): void {
		$this->queue[] = array(
			'status' => $status,
			'body'   => $body,
		);
	}

	private function manifest( array $override = array() ): array {
		return $override + array(
			'version'      => '0.2.0',
			'download_url' => self::ZIP_URL,
			'sha256_url'   => self::SHA_URL,
			'homepage'     => 'https://github.com/OWNER/wp-pcloud-backup',
			'requires'     => '6.0',
			'requires_php' => '7.4',
			'tested'       => '6.8',
			'changelog'    => "Fixes\n<b>Adds</b>",
		);
	}

	private function updater( array $settingValues = array(), bool $jobRunning = false, string $current = '0.1.0' ): Updater {
		$settingValues += array(
			'auto_update'  => true,
			'notify_email' => 'a@example.com',
		);

		$settings = new class( $settingValues ) extends Settings {
			/**
			 * @var array
			 */
			private $values;

			public function __construct( array $values ) {
				$this->values = $values;
			}

			public function get( string $key ) {
				return $this->values[ $key ] ?? null;
			}
		};

		$handler = function ( string $url, array $headers ) {
			$this->requests[] = array(
				'url'     => $url,
				'headers' => $headers,
			);
			if ( array() === $this->queue ) {
				throw new \LogicException( 'httpGet queue is empty.' );
			}
			$next = array_shift( $this->queue );
			if ( $next instanceof \Throwable ) {
				throw $next;
			}
			return $next;
		};

		return new TestableUpdater(
			$settings,
			'https://raw.githubusercontent.com/OWNER/wp-pcloud-backup/main/update.json',
			self::BASENAME,
			$current,
			static function () use ( $jobRunning ) {
				return $jobRunning;
			},
			new Notifier( $settings, 'example.com', 'https://example.com/wp-admin/' ),
			$handler
		);
	}

	private function transient(): \stdClass {
		$transient           = new \stdClass();
		$transient->response = array();

		return $transient;
	}

	public function test_injects_update_when_newer(): void {
		$this->queueJson( $this->manifest() );

		$result = $this->updater()->injectUpdate( $this->transient() );

		$this->assertArrayHasKey( self::BASENAME, $result->response );
		$entry = $result->response[ self::BASENAME ];
		$this->assertSame( 'wp-pcloud-backup', $entry->slug );
		$this->assertSame( self::BASENAME, $entry->plugin );
		$this->assertSame( '0.2.0', $entry->new_version );
		$this->assertSame( self::ZIP_URL, $entry->package );
		$this->assertSame( 'https://github.com/OWNER/wp-pcloud-backup', $entry->url );
		$this->assertSame( '6.0', $entry->requires );
		$this->assertSame( '7.4', $entry->requires_php );
		$this->assertSame( '6.8', $entry->tested );
	}

	public function test_no_update_when_same_or_older(): void {
		foreach ( array( '0.2.0', '0.3.0' ) as $current ) {
			$this->transients = array();
			$this->queueJson( $this->manifest() );

			$result = $this->updater( array(), false, $current )->injectUpdate( $this->transient() );

			$this->assertArrayNotHasKey( self::BASENAME, $result->response );
			$this->assertArrayHasKey( self::BASENAME, $result->no_update );
			$this->assertSame( $current, $result->no_update[ self::BASENAME ]->new_version );
		}
	}

	public function test_invalid_manifest_injects_nothing_and_caches_negative_result(): void {
		$bad = $this->manifest();
		unset( $bad['sha256_url'] );
		$this->queueJson( $bad );

		$updater = $this->updater();
		$result  = $updater->injectUpdate( $this->transient() );

		$this->assertSame( array(), $result->response );
		$this->assertNull( $updater->fetchManifest() );
		$this->assertCount( 1, $this->requests );
		$this->assertSame( array( 'invalid' => true ), $this->transients['wp_pcloud_backup_update_manifest'] );
		$this->assertSame( HOUR_IN_SECONDS, $this->ttls['wp_pcloud_backup_update_manifest'] );
	}

	public function test_non_object_transient_is_returned_as_is(): void {
		$this->assertFalse( $this->updater()->injectUpdate( false ) );
		$this->assertSame( array(), $this->requests );
	}

	public function test_network_failure_returns_null_and_caches_nothing(): void {
		$this->queue[] = null;

		$this->assertNull( $this->updater()->fetchManifest() );
		$this->assertSame( array(), $this->transients );
	}

	public function test_manifest_cached_12h(): void {
		$this->queueJson( $this->manifest() );
		$updater = $this->updater();

		$first  = $updater->fetchManifest();
		$second = $updater->fetchManifest();

		$this->assertSame( '0.2.0', $first['version'] );
		$this->assertSame( $first, $second );
		$this->assertCount( 1, $this->requests );
		$this->assertSame( 'application/json', $this->requests[0]['headers']['Accept'] );
		$this->assertSame( 12 * HOUR_IN_SECONDS, $this->ttls['wp_pcloud_backup_update_manifest'] );
	}

	public function test_force_bypasses_cache(): void {
		$this->queueJson( $this->manifest() );
		$this->queueJson( $this->manifest( array( 'version' => '0.3.0' ) ) );
		$updater = $this->updater();

		$updater->fetchManifest();
		$forced = $updater->fetchManifest( true );

		$this->assertSame( '0.3.0', $forced['version'] );
		$this->assertCount( 2, $this->requests );
	}

	public function test_auto_update_false_when_setting_off_or_job_running(): void {
		$item = (object) array( 'plugin' => self::BASENAME );

		$this->assertTrue( $this->updater()->shouldAutoUpdate( false, $item ) );
		$this->assertFalse( $this->updater( array( 'auto_update' => false ) )->shouldAutoUpdate( true, $item ) );
		$this->assertFalse( $this->updater( array(), true )->shouldAutoUpdate( true, $item ) );
	}

	public function test_auto_update_leaves_other_plugins_alone(): void {
		$other = (object) array( 'plugin' => 'akismet/akismet.php' );
		$this->assertTrue( $this->updater( array( 'auto_update' => false ) )->shouldAutoUpdate( true, $other ) );
		$this->assertFalse( $this->updater()->shouldAutoUpdate( false, $other ) );
	}

	public function test_plugin_info_for_own_slug_only(): void {
		$this->queueJson( $this->manifest() );
		$updater = $this->updater();

		$info = $updater->pluginInfo( false, 'plugin_information', (object) array( 'slug' => 'wp-pcloud-backup' ) );
		$this->assertSame( '0.2.0', $info->version );
		$this->assertSame( self::ZIP_URL, $info->download_link );
		$this->assertSame( 'Fixes<br>&lt;b&gt;Adds&lt;/b&gt;', $info->sections['changelog'] );

		$this->assertFalse( $updater->pluginInfo( false, 'plugin_information', (object) array( 'slug' => 'other' ) ) );
		$this->assertFalse( $updater->pluginInfo( false, 'query_plugins', (object) array( 'slug' => 'wp-pcloud-backup' ) ) );
	}

	public function test_register_adds_all_filters(): void {
		$updater = $this->updater();
		$updater->register();

		$this->assertNotFalse( has_filter( 'pre_set_site_transient_update_plugins', array( $updater, 'injectUpdate' ) ) );
		$this->assertNotFalse( has_filter( 'plugins_api', array( $updater, 'pluginInfo' ) ) );
		$this->assertNotFalse( has_filter( 'auto_update_plugin', array( $updater, 'shouldAutoUpdate' ) ) );
		$this->assertNotFalse( has_filter( 'upgrader_pre_download', array( $updater, 'verifyPackage' ) ) );
		$this->assertNotFalse( has_filter( 'upgrader_source_selection', array( $updater, 'fixSourceDir' ) ) );
	}

	private function stubDownload( string $contents ): void {
		$dir = $this->dir;
		Functions\when( 'download_url' )->alias(
			static function () use ( $dir, $contents ) {
				$tmp = $dir . '/download.zip';
				file_put_contents( $tmp, $contents );
				return $tmp;
			}
		);
	}

	public function test_good_sha256_returns_tmp_path(): void {
		$this->stubDownload( 'zip-bytes' );
		$this->queueJson( $this->manifest() );
		// The seam returns the final response, so a GitHub 302 to the CDN is invisible here.
		$this->queueBody( strtoupper( hash( 'sha256', 'zip-bytes' ) ) . "  wp-pcloud-backup.zip\n" );

		$result = $this->updater()->verifyPackage( false, self::ZIP_URL, new \stdClass(), self::HOOK_EXTRA );

		$this->assertSame( $this->dir . '/download.zip', $result );
		$this->assertFileExists( $result );
		$this->assertSame( array(), $this->mails );
		$this->assertSame( self::SHA_URL, $this->requests[1]['url'] );
	}

	public function test_bad_sha256_returns_wp_error_and_notifies(): void {
		$this->stubDownload( 'zip-bytes' );
		$this->queueJson( $this->manifest() );
		$this->queueBody( hash( 'sha256', 'something else' ) . "\n" );

		$result = $this->updater()->verifyPackage( false, self::ZIP_URL, new \stdClass(), self::HOOK_EXTRA );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'wp_pcloud_backup_bad_checksum', $result->get_error_code() );
		$this->assertSame( '更新パッケージの検証に失敗しました（SHA-256 不一致）', $result->get_error_message() );
		$this->assertFileDoesNotExist( $this->dir . '/download.zip' );
		$this->assertCount( 1, $this->mails );
		$this->assertSame( '[WP pCloud Backup] 更新失敗: example.com', $this->mails[0][1] );
		$this->assertStringContainsString( 'SHA-256 不一致', $this->mails[0][2] );
	}

	public function test_unparsable_checksum_file_is_reported_as_unavailable(): void {
		$this->stubDownload( 'zip-bytes' );
		$this->queueJson( $this->manifest() );
		$this->queueBody( 'not a hash' );

		$result = $this->updater()->verifyPackage( false, self::ZIP_URL, new \stdClass(), self::HOOK_EXTRA );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'wp_pcloud_backup_checksum_unavailable', $result->get_error_code() );
		$this->assertSame( '更新パッケージの検証用チェックサムを取得できませんでした', $result->get_error_message() );
		$this->assertFileDoesNotExist( $this->dir . '/download.zip' );
		$this->assertCount( 1, $this->mails );
		$this->assertStringContainsString( 'チェックサムを取得できませんでした', $this->mails[0][2] );
	}

	public function test_checksum_http_error_and_transport_failure_are_unavailable(): void {
		foreach ( array( array( 'status' => 404, 'body' => hash( 'sha256', 'zip-bytes' ) ), null ) as $reply ) {
			$this->transients = array();
			$this->queue      = array();
			$this->stubDownload( 'zip-bytes' );
			$this->queueJson( $this->manifest() );
			$this->queue[] = $reply;

			$result = $this->updater()->verifyPackage( false, self::ZIP_URL, new \stdClass(), self::HOOK_EXTRA );

			$this->assertInstanceOf( \WP_Error::class, $result );
			$this->assertSame( 'wp_pcloud_backup_checksum_unavailable', $result->get_error_code() );
			$this->assertFileDoesNotExist( $this->dir . '/download.zip' );
		}
	}

	public function test_failure_mail_is_throttled_for_six_hours(): void {
		$this->stubDownload( 'zip-bytes' );
		$updater = $this->updater();
		$this->queueJson( $this->manifest() );
		$this->queueBody( hash( 'sha256', 'other' ) );
		$this->queueBody( hash( 'sha256', 'other' ) );

		$updater->verifyPackage( false, self::ZIP_URL, new \stdClass(), self::HOOK_EXTRA );
		$updater->verifyPackage( false, self::ZIP_URL, new \stdClass(), self::HOOK_EXTRA );

		$this->assertCount( 1, $this->mails );
		$this->assertSame( 6 * HOUR_IN_SECONDS, $this->ttls['wp_pcloud_backup_update_fail_notified'] );
	}

	public function test_download_error_is_passed_through(): void {
		$error = new \WP_Error( 'http_request_failed', 'nope' );
		Functions\when( 'download_url' )->justReturn( $error );
		$this->queueJson( $this->manifest() );

		$result = $this->updater()->verifyPackage( false, self::ZIP_URL, new \stdClass(), self::HOOK_EXTRA );

		$this->assertSame( $error, $result );
		$this->assertSame( array(), $this->mails );
	}

	public function test_manifest_unavailable_still_verifies_via_derived_checksum_url(): void {
		$this->stubDownload( 'zip-bytes' );
		$this->queue[] = null; // Manifest fetch fails.
		$this->queueBody( hash( 'sha256', 'zip-bytes' ) );

		$result = $this->updater()->verifyPackage( false, self::ZIP_URL, new \stdClass(), self::HOOK_EXTRA );

		$this->assertSame( $this->dir . '/download.zip', $result );
		$this->assertSame( self::ZIP_URL . '.sha256', $this->requests[1]['url'] );
	}

	public function test_manifest_with_other_download_url_verifies_via_derived_checksum_url(): void {
		$other = 'https://github.com/OWNER/wp-pcloud-backup/releases/download/v0.3.0/wp-pcloud-backup.zip';
		$this->stubDownload( 'zip-bytes' );
		$this->queueJson( $this->manifest( array( 'download_url' => $other, 'sha256_url' => $other . '.sha256' ) ) );
		$this->queueBody( hash( 'sha256', 'zip-bytes' ) );

		$result = $this->updater()->verifyPackage( false, self::ZIP_URL, new \stdClass(), self::HOOK_EXTRA );

		$this->assertSame( $this->dir . '/download.zip', $result );
		$this->assertSame( self::ZIP_URL . '.sha256', $this->requests[1]['url'] );
	}

	public function test_non_https_package_is_refused(): void {
		$result = $this->updater()->verifyPackage( false, 'http://example.com/wp-pcloud-backup.zip', new \stdClass(), self::HOOK_EXTRA );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( array(), $this->requests );
	}

	public function test_verify_package_ignores_foreign_plugin_without_network(): void {
		$result = $this->updater()->verifyPackage( false, 'https://downloads.wordpress.org/plugin/akismet.zip', new \stdClass(), array( 'plugin' => 'akismet/akismet.php' ) );
		$this->assertFalse( $result );

		$result = $this->updater()->verifyPackage( 'reply', self::ZIP_URL, new \stdClass(), array() );
		$this->assertSame( 'reply', $result );

		$this->assertSame( array(), $this->requests );
	}

	public function test_auto_update_false_when_job_state_check_throws(): void {
		$settings = new Settings();
		$updater  = new Updater(
			new class() extends Settings {
				public function __construct() {}

				public function get( string $key ) {
					return true;
				}
			},
			'https://example.com/update.json',
			self::BASENAME,
			'0.1.0',
			static function () {
				throw new \RuntimeException( 'boom' );
			},
			new Notifier( $settings, 'example.com', 'https://example.com/wp-admin/' )
		);

		$this->assertFalse( $updater->shouldAutoUpdate( true, (object) array( 'plugin' => self::BASENAME ) ) );
	}

	public function test_http_get_uses_wp_remote_get_with_redirects(): void {
		Functions\expect( 'wp_remote_get' )
			->once()
			->with(
				self::SHA_URL,
				array(
					'timeout'     => 15,
					'redirection' => 5,
					'headers'     => array(),
				)
			)
			->andReturn( array( 'response' => array( 'code' => 200 ), 'body' => 'abc' ) );
		Functions\when( 'wp_remote_retrieve_response_code' )->alias(
			static function ( $response ) {
				return $response['response']['code'];
			}
		);
		Functions\when( 'wp_remote_retrieve_body' )->alias(
			static function ( $response ) {
				return $response['body'];
			}
		);

		$settings = new class() extends Settings {
			public function __construct() {}
		};
		$updater  = new RealHttpUpdater( $settings, 'u', self::BASENAME, '0.1.0', 'is_string', new Notifier( $settings, 'h', 'a' ) );

		$this->assertSame(
			array(
				'status' => 200,
				'body'   => 'abc',
			),
			$updater->get( self::SHA_URL )
		);
	}

	public function test_http_get_returns_null_on_wp_error(): void {
		Functions\when( 'wp_remote_get' )->justReturn( new \WP_Error( 'http_request_failed', 'x' ) );

		$settings = new class() extends Settings {
			public function __construct() {}
		};
		$updater  = new RealHttpUpdater( $settings, 'u', self::BASENAME, '0.1.0', 'is_string', new Notifier( $settings, 'h', 'a' ) );

		$this->assertNull( $updater->get( self::SHA_URL ) );
	}

	public function test_fix_source_dir_renames_versioned_folder(): void {
		$source = $this->dir . '/wp-pcloud-backup-1.2.3';
		mkdir( $source );
		file_put_contents( $source . '/wp-pcloud-backup.php', '<?php' );

		$result = $this->updater()->fixSourceDir( $source . '/', $this->dir, new \stdClass(), self::HOOK_EXTRA );

		$this->assertSame( $this->dir . '/wp-pcloud-backup/', $result );
		$this->assertFileExists( $this->dir . '/wp-pcloud-backup/wp-pcloud-backup.php' );
		$this->assertDirectoryDoesNotExist( $source );
	}

	public function test_fix_source_dir_leaves_correct_or_foreign_sources_alone(): void {
		$good = $this->dir . '/wp-pcloud-backup/';
		mkdir( $good );
		$this->assertSame( $good, $this->updater()->fixSourceDir( $good, $this->dir, new \stdClass(), self::HOOK_EXTRA ) );

		$foreign = $this->dir . '/akismet-9.9/';
		mkdir( $foreign );
		$this->assertSame( $foreign, $this->updater()->fixSourceDir( $foreign, $this->dir, new \stdClass(), array( 'plugin' => 'akismet/akismet.php' ) ) );
		$this->assertDirectoryExists( $foreign );
	}
}

/**
 * Updater with scripted HTTP (named class: Brain Monkey cannot resolve anonymous-class callbacks).
 */
final class TestableUpdater extends Updater {

	/**
	 * @var callable
	 */
	private $handler;

	public function __construct( $settings, $url, $basename, $current, $jobRunning, $notifier, $handler ) {
		parent::__construct( $settings, $url, $basename, $current, $jobRunning, $notifier );
		$this->handler = $handler;
	}

	protected function httpGet( string $url, array $headers = array() ): ?array {
		return ( $this->handler )( $url, $headers );
	}
}

/**
 * Exposes the real httpGet().
 */
final class RealHttpUpdater extends Updater {

	public function get( string $url ): ?array {
		return $this->httpGet( $url );
	}
}
