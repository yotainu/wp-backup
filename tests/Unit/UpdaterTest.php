<?php

namespace WpPcloudBackup\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use WpPcloudBackup\Notifier;
use WpPcloudBackup\Settings;
use WpPcloudBackup\Tests\Support\FakeTransport;
use WpPcloudBackup\Tests\Support\TempDir;
use WpPcloudBackup\Updater;

final class UpdaterTest extends TestCase {

	private const BASENAME = 'wp-pcloud-backup/wp-pcloud-backup.php';
	private const ZIP_URL  = 'https://github.com/OWNER/wp-pcloud-backup/releases/download/v0.2.0/wp-pcloud-backup.zip';
	private const SHA_URL  = 'https://github.com/OWNER/wp-pcloud-backup/releases/download/v0.2.0/wp-pcloud-backup.zip.sha256';

	/**
	 * @var FakeTransport
	 */
	private $http;

	/**
	 * In-memory site transients.
	 *
	 * @var array
	 */
	private $transients = array();

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

		$this->http       = new FakeTransport();
		$this->transients = array();
		$this->mails      = array();
		$this->dir        = TempDir::create();

		Functions\when( 'get_site_transient' )->alias(
			function ( $key ) {
				return $this->transients[ $key ] ?? false;
			}
		);
		Functions\when( 'set_site_transient' )->alias(
			function ( $key, $value ) {
				$this->transients[ $key ] = $value;
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

		return new Updater(
			$settings,
			$this->http,
			'https://raw.githubusercontent.com/OWNER/wp-pcloud-backup/main/update.json',
			self::BASENAME,
			$current,
			static function () use ( $jobRunning ) {
				return $jobRunning;
			},
			new Notifier( $settings, 'example.com', 'https://example.com/wp-admin/' )
		);
	}

	private function transient(): \stdClass {
		$transient           = new \stdClass();
		$transient->response = array();

		return $transient;
	}

	public function test_injects_update_when_newer(): void {
		$this->http->queueJson( $this->manifest() );

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
			$this->http->queueJson( $this->manifest() );

			$result = $this->updater( array(), false, $current )->injectUpdate( $this->transient() );

			$this->assertArrayNotHasKey( self::BASENAME, $result->response );
			$this->assertArrayHasKey( self::BASENAME, $result->no_update );
			$this->assertSame( $current, $result->no_update[ self::BASENAME ]->new_version );
		}
	}

	public function test_invalid_manifest_injects_nothing_and_caches_negative_result(): void {
		$bad = $this->manifest();
		unset( $bad['sha256_url'] );
		$this->http->queueJson( $bad );

		$updater = $this->updater();
		$result  = $updater->injectUpdate( $this->transient() );

		$this->assertSame( array(), $result->response );
		$this->assertNull( $updater->fetchManifest() );
		$this->assertCount( 1, $this->http->requests );
		$this->assertSame( array( 'invalid' => true ), $this->transients['wp_pcloud_backup_update_manifest'] );
	}

	public function test_non_object_transient_is_returned_as_is(): void {
		$this->assertFalse( $this->updater()->injectUpdate( false ) );
		$this->assertSame( array(), $this->http->requests );
	}

	public function test_network_failure_returns_null_and_caches_nothing(): void {
		$this->http->queueException( \WpPcloudBackup\Storage\StorageException::network( 'boom' ) );

		$this->assertNull( $this->updater()->fetchManifest() );
		$this->assertSame( array(), $this->transients );
	}

	public function test_manifest_cached_12h(): void {
		$this->http->queueJson( $this->manifest() );
		$updater = $this->updater();

		$first  = $updater->fetchManifest();
		$second = $updater->fetchManifest();

		$this->assertSame( '0.2.0', $first['version'] );
		$this->assertSame( $first, $second );
		$this->assertCount( 1, $this->http->requests );
		$this->assertSame( 'GET', $this->http->requests[0]['method'] );
		$this->assertSame( 'application/json', $this->http->requests[0]['options']['headers']['Accept'] );
		$this->assertSame( 15, $this->http->requests[0]['options']['timeout'] );
		$this->assertArrayHasKey( 'wp_pcloud_backup_update_manifest', $this->transients );
	}

	public function test_force_bypasses_cache(): void {
		$this->http->queueJson( $this->manifest() );
		$this->http->queueJson( $this->manifest( array( 'version' => '0.3.0' ) ) );
		$updater = $this->updater();

		$updater->fetchManifest();
		$forced = $updater->fetchManifest( true );

		$this->assertSame( '0.3.0', $forced['version'] );
		$this->assertCount( 2, $this->http->requests );
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
		$this->http->queueJson( $this->manifest() );
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
		$this->http->queueJson( $this->manifest() );
		$this->http->queue( array( 'status' => 200, 'body' => strtoupper( hash( 'sha256', 'zip-bytes' ) ) . "  wp-pcloud-backup.zip\n" ) );

		$result = $this->updater()->verifyPackage( false, self::ZIP_URL, new \stdClass(), array() );

		$this->assertSame( $this->dir . '/download.zip', $result );
		$this->assertFileExists( $result );
		$this->assertSame( array(), $this->mails );
		$this->assertSame( self::SHA_URL, $this->http->requests[1]['url'] );
	}

	public function test_bad_sha256_returns_wp_error_and_notifies(): void {
		$this->stubDownload( 'zip-bytes' );
		$this->http->queueJson( $this->manifest() );
		$this->http->queue( array( 'status' => 200, 'body' => hash( 'sha256', 'something else' ) . "\n" ) );

		$result = $this->updater()->verifyPackage( false, self::ZIP_URL, new \stdClass(), array() );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'wp_pcloud_backup_bad_checksum', $result->get_error_code() );
		$this->assertFileDoesNotExist( $this->dir . '/download.zip' );
		$this->assertCount( 1, $this->mails );
		$this->assertStringContainsString( 'SHA-256', $this->mails[0][2] );
	}

	public function test_unparsable_checksum_file_is_a_failure(): void {
		$this->stubDownload( 'zip-bytes' );
		$this->http->queueJson( $this->manifest() );
		$this->http->queue( array( 'status' => 200, 'body' => 'not a hash' ) );

		$result = $this->updater()->verifyPackage( false, self::ZIP_URL, new \stdClass(), array() );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertFileDoesNotExist( $this->dir . '/download.zip' );
		$this->assertCount( 1, $this->mails );
	}

	public function test_checksum_fetch_error_is_a_failure(): void {
		$this->stubDownload( 'zip-bytes' );
		$this->http->queueJson( $this->manifest() );
		$this->http->queue( array( 'status' => 404, 'body' => hash( 'sha256', 'zip-bytes' ) ) );

		$result = $this->updater()->verifyPackage( false, self::ZIP_URL, new \stdClass(), array() );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertFileDoesNotExist( $this->dir . '/download.zip' );
	}

	public function test_download_error_is_passed_through(): void {
		$error = new \WP_Error( 'http_request_failed', 'nope' );
		Functions\when( 'download_url' )->justReturn( $error );
		$this->http->queueJson( $this->manifest() );

		$result = $this->updater()->verifyPackage( false, self::ZIP_URL, new \stdClass(), array() );

		$this->assertSame( $error, $result );
		$this->assertSame( array(), $this->mails );
	}

	public function test_verify_package_ignores_foreign_package(): void {
		$this->http->queueJson( $this->manifest() );

		$result = $this->updater()->verifyPackage( false, 'https://downloads.wordpress.org/plugin/akismet.zip', new \stdClass(), array() );

		$this->assertFalse( $result );
		$this->assertCount( 1, $this->http->requests );
	}

	public function test_fix_source_dir_renames_versioned_folder(): void {
		$source = $this->dir . '/wp-pcloud-backup-1.2.3';
		mkdir( $source );
		file_put_contents( $source . '/wp-pcloud-backup.php', '<?php' );

		$result = $this->updater()->fixSourceDir( $source . '/', $this->dir, new \stdClass(), array( 'plugin' => self::BASENAME ) );

		$this->assertSame( $this->dir . '/wp-pcloud-backup/', $result );
		$this->assertFileExists( $this->dir . '/wp-pcloud-backup/wp-pcloud-backup.php' );
		$this->assertDirectoryDoesNotExist( $source );
	}

	public function test_fix_source_dir_leaves_correct_or_foreign_sources_alone(): void {
		$good = $this->dir . '/wp-pcloud-backup/';
		mkdir( $good );
		$this->assertSame( $good, $this->updater()->fixSourceDir( $good, $this->dir, new \stdClass(), array( 'plugin' => self::BASENAME ) ) );

		$foreign = $this->dir . '/akismet-9.9/';
		mkdir( $foreign );
		$this->assertSame( $foreign, $this->updater()->fixSourceDir( $foreign, $this->dir, new \stdClass(), array( 'plugin' => 'akismet/akismet.php' ) ) );
		$this->assertDirectoryExists( $foreign );
	}
}
