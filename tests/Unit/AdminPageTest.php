<?php

namespace WpPcloudBackup\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use PHPUnit\Framework\TestCase;
use WpPcloudBackup\Admin\AdminPage;
use WpPcloudBackup\Plugin;
use WpPcloudBackup\Settings;
use WpPcloudBackup\Storage\PcloudClient;
use WpPcloudBackup\Storage\StorageException;
use WpPcloudBackup\Tests\Support\TempDir;
use WpPcloudBackup\Trigger;
use WpPcloudBackup\WorkDir;

/**
 * Settings that keep values in an array instead of wp_options.
 */
class ArrayBackedSettings extends Settings {

	/** @var array */
	public $values;

	/** @var string[] */
	public $locked;

	/** @var array[] */
	public $updates = array();

	/** @var string|null */
	public $token = null;

	/** @var array */
	public $tokenCalls = array();

	public function __construct( array $values = array(), array $locked = array() ) {
		$this->values = $values + array(
			'keep'              => 3,
			'schedule'          => 'weekly',
			'start_time'        => '03:00',
			'workdir_name'      => '',
			'connection_email'  => '',
			'cron_key'          => 'k',
			'root_folder'       => '/WPBackup',
			'site_folder'       => 'example.com',
			'backup_root'       => '/var/www/html',
			'excludes'          => array(),
			'part_size_mb'      => 25,
			'run_budget_sec'    => 20,
			'use_wp_cron'       => true,
			'auto_update'       => true,
			'notify_email'      => '',
			'notify_on_success' => false,
		);
		$this->locked = $locked;
	}

	public function get( string $key ) {
		return $this->values[ $key ] ?? null;
	}

	public function all(): array {
		return $this->values;
	}

	public function update( array $input ): array {
		foreach ( $input as $key => $value ) {
			if ( ! in_array( $key, $this->locked, true ) ) {
				$this->values[ $key ] = $value;
			}
		}
		$this->updates[] = $input;

		return $this->values;
	}

	public function isLocked( string $key ): bool {
		return in_array( $key, $this->locked, true );
	}

	public function accessToken(): ?string {
		return $this->token;
	}

	public function setAccessToken( ?string $plain ): void {
		$this->tokenCalls[] = $plain;
		$this->token        = $plain;
	}
}

/**
 * Fake of the final JobRunner surface that AdminPage uses.
 */
class FakeAdminRunner {

	/** @var bool */
	public $running = false;

	/** @var string[] */
	public $aborted = array();

	public function status(): array {
		return array(
			'running'        => $this->running,
			'step'           => null,
			'progress'       => 0.5,
			'files_done'     => 1,
			'files_total'    => 2,
			'parts_uploaded' => 0,
			'started'        => 1,
			'message'        => 'working',
			'last_error'     => null,
		);
	}

	public function nextRun(): int {
		return 1700000000;
	}

	public function abort( string $reason ): void {
		$this->aborted[] = $reason;
	}
}

final class AdminPageTest extends TestCase {

	/** @var array<string,mixed> */
	private $transients = array();

	/** @var string|null */
	private $uploads = null;

	/** @var string[] */
	private $nonceChecks = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\stubTranslationFunctions();
		Functions\stubEscapeFunctions();

		$this->transients  = array();
		$this->nonceChecks = array();
		$_POST             = array();
		$_GET             = array();

		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'check_admin_referer' )->alias(
			function ( $action ) {
				$this->nonceChecks[] = $action;
				return 1;
			}
		);
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'get_current_user_id' )->justReturn( 7 );
		Functions\when( 'home_url' )->justReturn( 'https://example.com/' );
		Functions\when( 'admin_url' )->alias(
			static function ( $path = '' ) {
				return 'https://example.com/wp-admin/' . $path;
			}
		);
		Functions\when( 'add_query_arg' )->alias(
			static function ( $args, $url ) {
				return $url . '?' . http_build_query( $args );
			}
		);
		Functions\when( 'set_transient' )->alias(
			function ( $key, $value ) {
				$this->transients[ $key ] = $value;
				return true;
			}
		);
		Functions\when( 'get_transient' )->alias(
			function ( $key ) {
				return $this->transients[ $key ] ?? false;
			}
		);
		Functions\when( 'delete_transient' )->alias(
			function ( $key ) {
				unset( $this->transients[ $key ] );
				return true;
			}
		);
	}

	protected function tearDown(): void {
		$_POST = array();
		$_GET  = array();
		if ( null !== $this->uploads ) {
			TempDir::remove( $this->uploads );
			$this->uploads = null;
		}
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Plugin (final, no seams) with its services replaced through reflection.
	 */
	private function plugin( Settings $settings, array $services = array() ): Plugin {
		$plugin = new Plugin();
		$set    = static function ( Plugin $plugin, string $property, $value ): void {
			$ref = new \ReflectionProperty( Plugin::class, $property );
			$ref->setAccessible( true );
			$ref->setValue( $plugin, $value );
		};

		$set( $plugin, 'settings', $settings );
		foreach ( $services as $property => $value ) {
			$set( $plugin, $property, $value );
		}

		return $plugin;
	}

	/**
	 * AdminPage with every side-effecting seam replaced.
	 *
	 * @param array $overrides client (callable token => object), exchange (callable), extensions (string[] missing), cronDisabled, dev.
	 */
	private function page( Plugin $plugin, array $overrides = array() ): AdminPage {
		return new class( $plugin, $overrides ) extends AdminPage {
			/** @var string[] */
			public $redirects = array();
			/** @var array */
			public $overrides;

			public function __construct( Plugin $plugin, array $overrides ) {
				parent::__construct( $plugin );
				$this->overrides = $overrides;
			}

			protected function redirect( string $url ): void {
				$this->redirects[] = $url;
			}

			protected function terminate(): void {
			}

			protected function makeClient( string $token ): PcloudClient {
				return ( $this->overrides['client'] )( $token );
			}

			protected function exchange( string $id, string $secret, string $code ): string {
				return ( $this->overrides['exchange'] )( $id, $secret, $code );
			}

			protected function runner() {
				return $this->overrides['runner'] ?? null;
			}

			protected function hasExtension( string $name ): bool {
				return ! in_array( $name, $this->overrides['missing'] ?? array(), true );
			}

			protected function isWpCronDisabled(): bool {
				return $this->overrides['cronDisabled'] ?? false;
			}

			protected function isDevStorage(): bool {
				return $this->overrides['dev'] ?? false;
			}
		};
	}

	/**
	 * An empty WP_PCLOUD_BACKUP_STORAGE_DIR (how .wp-env.override.json turns development mode off)
	 * is not development mode: Plugin::buildStorage() then uses the pCloud token.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_empty_storage_dir_constant_is_not_dev_storage(): void {
		define( 'WP_PCLOUD_BACKUP_STORAGE_DIR', '' );

		$method = new \ReflectionMethod( AdminPage::class, 'isDevStorage' );
		$method->setAccessible( true );

		$this->assertFalse( $method->invoke( new AdminPage( Plugin::instance() ) ) );
	}

	private function trigger( Settings $settings ): Trigger {
		return new Trigger(
			$settings,
			static function () {
				return null;
			}
		);
	}

	private function failingClient(): callable {
		return static function ( string $token ) {
			$client = Mockery::mock( PcloudClient::class );
			$client->shouldReceive( 'userInfo' )->andThrow( new StorageException( 'Invalid access_token ' . $token, 2094, false, 'auth' ) );

			return $client;
		};
	}

	private function workingClient( string $email ): callable {
		return static function ( string $token ) use ( $email ) {
			$client = Mockery::mock( PcloudClient::class );
			$client->shouldReceive( 'userInfo' )->andReturn( array( 'email' => $email, 'quota' => 10, 'usedquota' => 1 ) );

			return $client;
		};
	}

	private function notice(): ?array {
		return $this->transients[ AdminPage::NOTICE_TRANSIENT ] ?? null;
	}

	// === diagnostics ===

	public function test_diagnostics_reports_missing_extensions_and_disabled_cron(): void {
		$this->uploads = TempDir::create();
		$settings      = new ArrayBackedSettings();
		$plugin        = $this->plugin( $settings, array( 'workDir' => new WorkDir( $settings, $this->uploads ) ) );
		$page          = $this->page( $plugin, array( 'missing' => array( 'zlib', 'curl' ), 'cronDisabled' => true ) );

		$diag = $page->diagnostics();

		$this->assertSame( array( 'value' => false, 'ok' => false ), $diag['ext_zlib'] );
		$this->assertSame( array( 'value' => false, 'ok' => false ), $diag['ext_curl'] );
		$this->assertTrue( $diag['ext_openssl']['value'] );
		$this->assertSame( array( 'value' => true, 'ok' => false ), $diag['disable_wp_cron'] );
		$this->assertTrue( $diag['workdir_writable']['ok'] );
		$this->assertTrue( $diag['workdir_htaccess']['value'] );
		$this->assertFalse( $diag['incomplete_job']['value'] );
		$this->assertSame( PHP_VERSION, $diag['php_version']['value'] );
		$this->assertSame(
			array( 'php_version', 'max_execution_time', 'memory_limit', 'ext_zlib', 'ext_curl', 'ext_openssl', 'workdir_writable', 'workdir_htaccess', 'server_apache', 'disable_wp_cron', 'free_bytes', 'incomplete_job' ),
			array_keys( $diag )
		);
	}

	public function test_diagnostics_detects_an_incomplete_job(): void {
		$this->uploads = TempDir::create();
		$settings      = new ArrayBackedSettings();
		$workDir       = new WorkDir( $settings, $this->uploads );
		file_put_contents( $workDir->ensure() . '/state.json', '{}' );

		$diag = $this->page( $this->plugin( $settings, array( 'workDir' => $workDir ) ) )->diagnostics();

		$this->assertTrue( $diag['incomplete_job']['value'] );
		$this->assertFalse( $diag['disable_wp_cron']['value'] );
		$this->assertTrue( $diag['disable_wp_cron']['ok'] );
	}

	// === save_settings ===

	public function test_locked_settings_are_not_overwritten_by_post(): void {
		$settings = new ArrayBackedSettings( array( 'keep' => 5, 'root_folder' => '/WPBackup' ), array( 'keep' ) );
		$_POST    = array(
			'keep'        => '30',
			'root_folder' => '/Other',
			'cron_key'    => 'hacked',
			'next_run'    => '99',
			'excludes'    => "cache\r\n\r\n.git",
		);
		$page     = $this->page( $this->plugin( $settings ) );

		$page->handleSaveSettings();

		$this->assertSame( 5, $settings->values['keep'] );
		$this->assertSame( '/Other', $settings->values['root_folder'] );
		$this->assertSame( 'k', $settings->values['cron_key'] );
		$this->assertArrayNotHasKey( 'next_run', $settings->values );
		$this->assertCount( 1, $settings->updates );
		$this->assertArrayNotHasKey( 'keep', $settings->updates[0] );
		$this->assertArrayNotHasKey( 'cron_key', $settings->updates[0] );
		$this->assertSame( array( 'cache', '', '.git' ), $settings->updates[0]['excludes'] );
		$this->assertSame( 'success', $this->notice()['type'] );
		$this->assertStringContainsString( 'tab=settings', $page->redirects[0] );
	}

	public function test_unchecked_checkboxes_are_saved_as_false(): void {
		$settings = new ArrayBackedSettings( array( 'use_wp_cron' => true, 'auto_update' => true, 'notify_on_success' => true ) );
		$_POST    = array( 'auto_update' => '1' );

		$this->page( $this->plugin( $settings ) )->handleSaveSettings();

		$this->assertFalse( $settings->values['use_wp_cron'] );
		$this->assertTrue( $settings->values['auto_update'] );
		$this->assertFalse( $settings->values['notify_on_success'] );
	}

	public function test_changed_schedule_resets_next_run(): void {
		$settings = new ArrayBackedSettings( array( 'schedule' => 'weekly', 'start_time' => '03:00', 'next_run' => 1700000000 ) );
		$_POST    = array( 'schedule' => 'daily', 'start_time' => '03:00' );

		$this->page( $this->plugin( $settings ) )->handleSaveSettings();

		$this->assertSame( 'daily', $settings->values['schedule'] );
		$this->assertSame( 0, $settings->values['next_run'] );
	}

	public function test_changed_start_time_resets_next_run(): void {
		$settings = new ArrayBackedSettings( array( 'next_run' => 1700000000 ) );
		$_POST    = array( 'schedule' => 'weekly', 'start_time' => '04:30' );

		$this->page( $this->plugin( $settings ) )->handleSaveSettings();

		$this->assertSame( 0, $settings->values['next_run'] );
	}

	public function test_unchanged_schedule_keeps_next_run(): void {
		$settings = new ArrayBackedSettings( array( 'next_run' => 1700000000 ) );
		$_POST    = array( 'schedule' => 'weekly', 'start_time' => '03:00', 'keep' => '4' );

		$this->page( $this->plugin( $settings ) )->handleSaveSettings();

		$this->assertSame( 1700000000, $settings->values['next_run'] );
		$this->assertSame( '4', $settings->values['keep'] );
	}

	// === authorization ===

	public function test_handlers_die_without_capability_and_do_nothing(): void {
		Functions\when( 'current_user_can' )->justReturn( false );
		Functions\expect( 'wp_die' )->times( 8 );
		$settings = new ArrayBackedSettings();
		$_POST    = array( 'access_token' => 'tok', 'keep' => '9' );
		$page     = $this->page( $this->plugin( $settings ), array( 'client' => $this->workingClient( 'a@b.c' ) ) );

		foreach ( array( 'SaveSettings', 'SaveToken', 'ExchangeCode', 'RevealToken', 'Disconnect', 'RegenerateKey', 'DetectRoot', 'Abort' ) as $name ) {
			$page->{'handle' . $name}();
		}

		$this->assertSame( array(), $settings->updates );
		$this->assertSame( array(), $settings->tokenCalls );
		$this->assertSame( array(), $page->redirects );
		$this->assertSame( array(), $this->transients );
		$this->assertSame( array(), $this->nonceChecks );
	}

	public function test_handlers_check_the_admin_nonce(): void {
		$page = $this->page( $this->plugin( new ArrayBackedSettings() ) );

		$page->handleSaveSettings();

		$this->assertSame( array( 'wp_pcloud_backup_admin' ), $this->nonceChecks );
	}

	// === connection ===

	public function test_save_token_with_failing_user_info_does_not_store_the_token(): void {
		$settings = new ArrayBackedSettings();
		$_POST    = array( 'access_token' => '  secret-token  ' );
		$page     = $this->page( $this->plugin( $settings ), array( 'client' => $this->failingClient() ) );

		$page->handleSaveToken();

		$this->assertSame( array(), $settings->tokenCalls );
		$this->assertSame( array(), $settings->updates );
		$this->assertSame( 'error', $this->notice()['type'] );
		$this->assertStringContainsString( 'Invalid access_token', $this->notice()['message'] );
		$this->assertStringNotContainsString( 'secret-token', $this->notice()['message'] );
		$this->assertStringContainsString( 'tab=connection', $page->redirects[0] );
	}

	public function test_save_token_stores_a_validated_token_and_the_email(): void {
		$settings = new ArrayBackedSettings();
		$_POST    = array( 'access_token' => ' secret-token ' );
		$page     = $this->page( $this->plugin( $settings ), array( 'client' => $this->workingClient( 'me@example.com' ) ) );

		$page->handleSaveToken();

		$this->assertSame( array( 'secret-token' ), $settings->tokenCalls );
		$this->assertSame( 'me@example.com', $settings->values['connection_email'] );
		$this->assertSame( 'success', $this->notice()['type'] );
	}

	public function test_save_token_rejects_an_empty_token(): void {
		$settings = new ArrayBackedSettings();
		$_POST    = array( 'access_token' => '   ' );

		$this->page( $this->plugin( $settings ), array( 'client' => $this->workingClient( 'x@y.z' ) ) )->handleSaveToken();

		$this->assertSame( array(), $settings->tokenCalls );
		$this->assertSame( 'error', $this->notice()['type'] );
	}

	public function test_save_token_in_dev_mode_skips_validation(): void {
		$settings = new ArrayBackedSettings();
		$_POST    = array( 'access_token' => 'dev-token' );
		$client   = static function () {
			throw new \LogicException( 'must not be called' );
		};

		$this->page( $this->plugin( $settings ), array( 'dev' => true, 'client' => $client ) )->handleSaveToken();

		$this->assertSame( array( 'dev-token' ), $settings->tokenCalls );
	}

	public function test_save_token_is_refused_when_the_token_is_a_constant(): void {
		$settings = new ArrayBackedSettings( array(), array( 'access_token' ) );
		$_POST    = array( 'access_token' => 'tok' );

		$this->page( $this->plugin( $settings ), array( 'client' => $this->workingClient( 'x@y.z' ) ) )->handleSaveToken();

		$this->assertSame( array(), $settings->tokenCalls );
		$this->assertSame( 'error', $this->notice()['type'] );
	}

	public function test_exchange_code_stores_the_token_but_not_client_credentials(): void {
		$settings = new ArrayBackedSettings();
		$_POST    = array( 'client_id' => 'cid', 'client_secret' => 'csecret', 'code' => 'abc' );
		$seen     = array();
		$page     = $this->page(
			$this->plugin( $settings ),
			array(
				'client'   => $this->workingClient( 'me@example.com' ),
				'exchange' => static function ( $id, $secret, $code ) use ( &$seen ) {
					$seen = array( $id, $secret, $code );
					return 'new-token';
				},
			)
		);

		$page->handleExchangeCode();

		$this->assertSame( array( 'cid', 'csecret', 'abc' ), $seen );
		$this->assertSame( array( 'new-token' ), $settings->tokenCalls );
		$this->assertSame( 'me@example.com', $settings->values['connection_email'] );
		$this->assertStringNotContainsString( 'csecret', serialize( $settings->values ) );
	}

	public function test_exchange_code_failure_stores_nothing_and_hides_the_secret(): void {
		$settings = new ArrayBackedSettings();
		$_POST    = array( 'client_id' => 'cid', 'client_secret' => 'csecret', 'code' => 'abc' );
		$page     = $this->page(
			$this->plugin( $settings ),
			array(
				'exchange' => static function () {
					throw new StorageException( 'bad secret csecret', 2000, false, 'auth' );
				},
			)
		);

		$page->handleExchangeCode();

		$this->assertSame( array(), $settings->tokenCalls );
		$this->assertSame( 'error', $this->notice()['type'] );
		$this->assertStringNotContainsString( 'csecret', $this->notice()['message'] );
	}

	public function test_exchange_code_requires_all_fields(): void {
		$settings = new ArrayBackedSettings();
		$_POST    = array( 'client_id' => 'cid', 'client_secret' => '', 'code' => 'abc' );
		$exchange = static function () {
			throw new \LogicException( 'must not be called' );
		};

		$this->page( $this->plugin( $settings ), array( 'exchange' => $exchange ) )->handleExchangeCode();

		$this->assertSame( 'error', $this->notice()['type'] );
	}

	public function test_reveal_token_requires_valid_password(): void {
		$settings = new ArrayBackedSettings();
		$settings->token = 'secret-token';
		$_POST    = array( 'current_password' => 'wrong' );
		Functions\when( 'wp_get_current_user' )->justReturn( (object) array( 'user_pass' => 'hash', 'ID' => 7 ) );
		Functions\expect( 'wp_check_password' )->once()->with( 'wrong', 'hash', 7 )->andReturn( false );
		Functions\expect( 'wp_generate_password' )->never();
		$page = $this->page( $this->plugin( $settings ) );

		$page->handleRevealToken();

		// The only transient written is the error notice, never a one-time reveal key.
		$this->assertSame( array( AdminPage::NOTICE_TRANSIENT ), array_keys( $this->transients ) );
		$this->assertSame( 'error', $this->notice()['type'] );
		$this->assertStringNotContainsString( 'reveal=', $page->redirects[0] );
		$this->assertStringNotContainsString( 'secret-token', $page->redirects[0] );
	}

	public function test_reveal_token_with_valid_password_creates_a_one_time_key(): void {
		$settings = new ArrayBackedSettings();
		$_POST    = array( 'current_password' => 'right' );
		Functions\when( 'wp_get_current_user' )->justReturn( (object) array( 'user_pass' => 'hash', 'ID' => 7 ) );
		Functions\when( 'wp_check_password' )->justReturn( true );
		Functions\when( 'wp_generate_password' )->justReturn( 'onetimekey' );
		$page = $this->page( $this->plugin( $settings ) );

		$page->handleRevealToken();

		$this->assertSame( 'onetimekey', $this->transients['wp_pcloud_backup_reveal_7'] );
		$this->assertStringContainsString( 'tab=connection', $page->redirects[0] );
		$this->assertStringContainsString( 'reveal=onetimekey', $page->redirects[0] );
		$this->assertArrayNotHasKey( AdminPage::NOTICE_TRANSIENT, $this->transients );
	}

	public function test_reveal_token_is_refused_when_the_token_is_a_constant(): void {
		$settings = new ArrayBackedSettings( array(), array( 'access_token' ) );
		Functions\expect( 'wp_check_password' )->never();
		$page = $this->page( $this->plugin( $settings ) );

		$page->handleRevealToken();

		$this->assertSame( 'error', $this->notice()['type'] );
		$this->assertArrayNotHasKey( 'wp_pcloud_backup_reveal_7', $this->transients );
	}

	public function test_disconnect_clears_the_token_and_email(): void {
		$settings        = new ArrayBackedSettings( array( 'connection_email' => 'me@example.com' ) );
		$settings->token = 'tok';

		$this->page( $this->plugin( $settings ) )->handleDisconnect();

		$this->assertSame( array( null ), $settings->tokenCalls );
		$this->assertSame( '', $settings->values['connection_email'] );
	}

	public function test_regenerate_key_delegates_to_the_trigger(): void {
		$settings = new ArrayBackedSettings();

		$this->page( $this->plugin( $settings, array( 'trigger' => $this->trigger( $settings ) ) ) )->handleRegenerateKey();

		$this->assertMatchesRegularExpression( '/^[0-9a-f]{64}$/', $settings->values['cron_key'] );
	}

	public function test_detect_root_restores_the_detected_folder(): void {
		$settings = new ArrayBackedSettings( array( 'backup_root' => '/custom' ) );
		// WordPress in a subfolder named like ABSPATH's last folder (BackupRoot only climbs on a match).
		Functions\when( 'site_url' )->justReturn( 'https://example.com/' . basename( rtrim( ABSPATH, '/' ) ) );
		Functions\when( 'home_url' )->justReturn( 'https://example.com' );

		$this->page( $this->plugin( $settings ) )->handleDetectRoot();

		$this->assertSame( dirname( rtrim( ABSPATH, '/' ) ), $settings->values['backup_root'] );
	}

	public function test_abort_stops_only_a_running_job(): void {
		$runner = new FakeAdminRunner();
		$page   = $this->page( $this->plugin( new ArrayBackedSettings() ), array( 'runner' => $runner ) );

		$page->handleAbort();
		$this->assertSame( array(), $runner->aborted );
		$this->assertSame( 'error', $this->notice()['type'] );

		$runner->running = true;
		$page->handleAbort();
		$this->assertCount( 1, $runner->aborted );
		$this->assertSame( 'success', $this->notice()['type'] );
	}

	// === rendering ===

	private function stubRendering(): void {
		Functions\when( 'sanitize_key' )->alias( 'strtolower' );
		Functions\when( 'wp_nonce_field' )->alias(
			static function () {
				echo '<input type="hidden" name="_wpnonce" value="n">';
			}
		);
		Functions\when( 'size_format' )->alias(
			static function ( $bytes ) {
				return $bytes . ' B';
			}
		);
		Functions\when( 'wp_next_scheduled' )->justReturn( 1700000100 );
		Functions\when( 'submit_button' )->alias(
			static function ( $text = '' ) {
				echo '<p class="submit"><input type="submit" value="' . $text . '"></p>';
			}
		);
		Functions\when( 'checked' )->alias(
			static function ( $checked, $current = true, $echo = true ) {
				$out = (string) $checked === (string) $current ? " checked='checked'" : '';
				if ( $echo ) {
					echo $out;
				}
				return $out;
			}
		);
		Functions\when( 'selected' )->alias(
			static function ( $selected, $current = true, $echo = true ) {
				$out = (string) $selected === (string) $current ? " selected='selected'" : '';
				if ( $echo ) {
					echo $out;
				}
				return $out;
			}
		);
		Functions\when( 'disabled' )->alias(
			static function ( $disabled, $current = true, $echo = true ) {
				$out = (string) $disabled === (string) $current ? " disabled='disabled'" : '';
				if ( $echo ) {
					echo $out;
				}
				return $out;
			}
		);
	}

	private function renderTab( AdminPage $page, string $tab ): string {
		$_GET['tab'] = $tab;
		ob_start();
		$page->render();

		return (string) ob_get_clean();
	}

	public function test_status_tab_renders_progress_history_and_escapes_output(): void {
		$this->stubRendering();
		$this->uploads = TempDir::create();
		$settings      = new ArrayBackedSettings( array( 'last_success' => 1700000000 ) );
		$runner        = new FakeAdminRunner();
		$runner->running = true;
		Functions\when( 'get_option' )->justReturn(
			array(
				array( 'started' => 1700000000, 'finished' => 1700000090, 'result' => 'failed', 'bytes' => 2048, 'files' => 10, 'remote' => '/WPBackup/a', 'message' => '<script>alert(1)</script>' ),
			)
		);
		$history = new \WpPcloudBackup\History();
		$plugin        = $this->plugin( $settings, array( 'history' => $history, 'storage' => new \WpPcloudBackup\Storage\LocalStorage( $this->uploads ), 'storageBuilt' => true ) );

		$html = $this->renderTab( $this->page( $plugin, array( 'runner' => $runner ) ), 'status' );

		$this->assertStringContainsString( 'wppb-progress', $html );
		$this->assertStringContainsString( 'width:50%', $html );
		$this->assertStringContainsString( 'id="wppb-start"', $html );
		$this->assertStringContainsString( 'wp_pcloud_backup_abort', $html );
		$this->assertStringContainsString( '2023-11-14 22:13', $html );
		$this->assertStringContainsString( '2048 B', $html );
		$this->assertStringNotContainsString( '<script>alert(1)</script>', $html );
	}

	public function test_settings_tab_disables_locked_rows(): void {
		$this->stubRendering();
		$settings = new ArrayBackedSettings(
			array( 'keep' => 5, 'excludes' => array( 'a', 'b' ), 'schedule' => 'weekly', 'part_size_mb' => 25, 'backup_root' => '/x', 'notify_email' => 'a@b.c' ),
			array( 'keep' )
		);

		$html = $this->renderTab( $this->page( $this->plugin( $settings ) ), 'settings' );

		$this->assertMatchesRegularExpression( '/name="keep"[^>]*disabled/', $html );
		$this->assertStringContainsString( 'wp-config.php の定数で設定済み', $html );
		$this->assertStringContainsString( "a\nb", $html );
		$this->assertStringContainsString( 'wp_pcloud_backup_detect_root', $html );
		$this->assertMatchesRegularExpression( '/<input[^>]*name="notify_email"[^>]*>/', $html );
	}

	public function test_connection_tab_never_renders_the_token_without_a_reveal_key(): void {
		$this->stubRendering();
		$this->uploads = TempDir::create();
		$settings      = new ArrayBackedSettings( array( 'connection_email' => 'me@example.com' ) );
		$settings->token = 'secret-token';
		$workDir       = new WorkDir( $settings, $this->uploads );
		$plugin        = $this->plugin( $settings, array( 'trigger' => $this->trigger( $settings ), 'workDir' => $workDir ) );
		$page          = $this->page( $plugin );

		$html = $this->renderTab( $page, 'connection' );

		$this->assertStringContainsString( 'me@example.com', $html );
		$this->assertStringContainsString( 'wp-pcloud-backup=run&key=k', str_replace( '&amp;', '&', $html ) );
		$this->assertStringNotContainsString( 'secret-token', $html );

		// A wrong key does not reveal it either.
		$this->transients['wp_pcloud_backup_reveal_7'] = 'rightkey';
		$_GET['reveal']                                = 'wrongkey';
		$this->assertStringNotContainsString( 'secret-token', $this->renderTab( $page, 'connection' ) );
	}

	public function test_connection_tab_reveals_the_token_exactly_once(): void {
		$this->stubRendering();
		$this->uploads = TempDir::create();
		$settings      = new ArrayBackedSettings();
		$settings->token = 'secret-token';
		$plugin        = $this->plugin( $settings, array( 'trigger' => $this->trigger( $settings ), 'workDir' => new WorkDir( $settings, $this->uploads ) ) );
		$page          = $this->page( $plugin );

		$this->transients['wp_pcloud_backup_reveal_7'] = 'rightkey';
		$_GET['reveal']                                = 'rightkey';
		$first                                         = $this->renderTab( $page, 'connection' );
		$second                                        = $this->renderTab( $page, 'connection' );

		$this->assertStringContainsString( 'value="secret-token"', $first );
		$this->assertStringNotContainsString( 'secret-token', $second );
		$this->assertArrayNotHasKey( 'wp_pcloud_backup_reveal_7', $this->transients );
	}

	public function test_notice_is_shown_once(): void {
		$this->stubRendering();
		$this->uploads = TempDir::create();
		$settings      = new ArrayBackedSettings();
		$plugin        = $this->plugin( $settings, array( 'history' => new \WpPcloudBackup\History() ) );
		Functions\when( 'get_option' )->justReturn( array() );
		$this->transients[ AdminPage::NOTICE_TRANSIENT ] = array( 'type' => 'error', 'message' => 'Bad <b>thing</b>' );
		$page = $this->page( $plugin );

		$first  = $this->renderTab( $page, 'status' );
		$second = $this->renderTab( $page, 'status' );

		$this->assertStringContainsString( 'notice-error', $first );
		$this->assertStringNotContainsString( '<b>thing</b>', $first );
		$this->assertStringNotContainsString( 'notice-error', $second );
	}
}
