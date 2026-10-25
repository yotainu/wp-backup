<?php

namespace WpPcloudBackup\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use Mockery;
use PHPUnit\Framework\TestCase;
use WpPcloudBackup\Scheduler;
use WpPcloudBackup\Notifier;
use WpPcloudBackup\Settings;
use WpPcloudBackup\StaleMonitor;
use WpPcloudBackup\Trigger;

/**
 * Trigger tests. JobRunner is final, so a recording fake with the same
 * tick/run/startJob/status surface is injected through the runner provider.
 */
final class TriggerTest extends TestCase {

	private const KEY = 'abcdef0123456789abcdef0123456789abcdef0123456789abcdef0123456789';

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\stubTranslationFunctions();
		$_GET = array();
	}

	protected function tearDown(): void {
		$_GET = array();
		Monkey\tearDown();
		parent::tearDown();
	}

	private function fakeRunner(): object {
		return new class() {
			/** @var string[] */
			public $calls = array();

			public function tick(): array {
				$this->calls[] = 'tick';
				return array( 'running' => true, 'message' => 'ticked' );
			}

			public function run(): array {
				$this->calls[] = 'run';
				return array( 'running' => true, 'message' => 'ran' );
			}

			public function startJob( string $trigger, ?int $slot = null ): bool {
				$this->calls[] = 'startJob:' . $trigger;
				return true;
			}

			public function status(): array {
				$this->calls[] = 'status';
				return array( 'running' => false, 'message' => 'idle' );
			}
		};
	}

	private function settings( array $values ): Settings {
		$settings = Mockery::mock( Settings::class );
		$settings->shouldReceive( 'get' )->andReturnUsing(
			static function ( string $key ) use ( $values ) {
				return $values[ $key ] ?? null;
			}
		);
		// handleUrl() records last_url_hit.
		$settings->shouldReceive( 'update' )->andReturn( array() );

		return $settings;
	}

	/**
	 * Trigger whose terminate() throws instead of exiting, and whose beforeLongRun() is recorded.
	 */
	private function trigger( Settings $settings, ?object $runner, ?StaleMonitor $monitor = null, ?int $now = null ): Trigger {
		return new class( $settings, static function () use ( $runner ) {
			return $runner;
		}, $monitor, null === $now ? null : static function () use ( $now ): int {
			return $now;
		} ) extends Trigger {
			public $longRuns = 0;

			protected function terminate(): void {
				throw new \RuntimeException( 'terminated' );
			}

			protected function beforeLongRun(): void {
				++$this->longRuns;
			}
		};
	}

	public function test_url_with_wrong_key_returns_403_and_does_not_run(): void {
		$runner  = $this->fakeRunner();
		$trigger = $this->trigger( $this->settings( array( 'cron_key' => self::KEY ) ), $runner );

		Functions\expect( 'status_header' )->once()->with( 403 );
		Functions\expect( 'wp_send_json' )->never();

		try {
			$trigger->handleUrl( str_repeat( 'f', 64 ) );
			$this->fail( 'terminate() was not called' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'terminated', $e->getMessage() );
		}

		$this->assertSame( array(), $runner->calls );
		$this->assertSame( 0, $trigger->longRuns );
	}

	public function test_url_with_correct_key_ticks_and_returns_json(): void {
		$runner  = $this->fakeRunner();
		$trigger = $this->trigger( $this->settings( array( 'cron_key' => self::KEY ) ), $runner );
		$sent    = null;

		Functions\expect( 'status_header' )->never();
		Functions\expect( 'nocache_headers' )->once();
		Functions\when( 'wp_send_json' )->alias(
			static function ( $data ) use ( &$sent ) {
				$sent = $data;
			}
		);

		$trigger->handleUrl( self::KEY );

		$this->assertSame( array( 'tick' ), $runner->calls );
		$this->assertSame( 1, $trigger->longRuns );
		$this->assertSame( 'ticked', $sent['message'] );
	}

	public function test_empty_key_always_rejects(): void {
		foreach ( array( '', null, 0 ) as $stored ) {
			$runner  = $this->fakeRunner();
			$trigger = $this->trigger( $this->settings( array( 'cron_key' => $stored ) ), $runner );

			Functions\expect( 'status_header' )->once()->with( 403 );

			try {
				$trigger->handleUrl( '' );
				$this->fail( 'terminate() was not called' );
			} catch ( \RuntimeException $e ) {
				$this->assertSame( 'terminated', $e->getMessage() );
			}

			$this->assertSame( array(), $runner->calls );
			Monkey\tearDown();
			Monkey\setUp();
			Functions\stubTranslationFunctions();
		}
	}

	public function test_regenerate_key_is_64_hex(): void {
		$saved    = null;
		$settings = Mockery::mock( Settings::class );
		$settings->shouldReceive( 'update' )->once()->andReturnUsing(
			static function ( array $input ) use ( &$saved ) {
				$saved = $input;
				return $input;
			}
		);

		$key = ( new Trigger( $settings, static function () {
			return null;
		} ) )->regenerateKey();

		$this->assertMatchesRegularExpression( '/^[0-9a-f]{64}$/', $key );
		$this->assertSame( array( 'cron_key' => $key ), $saved );
	}

	public function test_wp_cron_disabled_setting_skips_tick(): void {
		$runner  = $this->fakeRunner();
		$trigger = $this->trigger( $this->settings( array( 'use_wp_cron' => false ) ), $runner );

		$trigger->onCron();

		$this->assertSame( array(), $runner->calls );
		$this->assertSame( 0, $trigger->longRuns );
	}

	public function test_wp_cron_enabled_ticks(): void {
		$runner  = $this->fakeRunner();
		$trigger = $this->trigger( $this->settings( array( 'use_wp_cron' => true ) ), $runner );

		$trigger->onCron();

		$this->assertSame( array( 'tick' ), $runner->calls );
		$this->assertSame( 1, $trigger->longRuns );
	}

	public function test_cron_without_storage_does_nothing(): void {
		$trigger = $this->trigger( $this->settings( array( 'use_wp_cron' => true ) ), null );

		$trigger->onCron();

		$this->assertSame( 0, $trigger->longRuns );
	}

	public function test_url_without_storage_reports_not_connected(): void {
		$trigger = $this->trigger( $this->settings( array( 'cron_key' => self::KEY ) ), null );
		$sent    = null;

		Functions\expect( 'nocache_headers' )->once();
		Functions\when( 'wp_send_json' )->alias(
			static function ( $data ) use ( &$sent ) {
				$sent = $data;
			}
		);

		$trigger->handleUrl( self::KEY );

		$this->assertFalse( $sent['running'] );
		$this->assertNull( $sent['step'] );
		$this->assertSame( 0.0, $sent['progress'] );
		$this->assertSame( 'pCloud に接続されていません', $sent['message'] );
		$this->assertArrayHasKey( 'files_total', $sent );
	}

	public function test_on_init_only_handles_run_requests(): void {
		$runner  = $this->fakeRunner();
		$trigger = $this->trigger( $this->settings( array( 'cron_key' => self::KEY ) ), $runner );

		Functions\expect( 'status_header' )->never();
		Functions\expect( 'wp_send_json' )->never();

		$trigger->onInit();
		$_GET = array( 'wp-pcloud-backup' => 'other', 'key' => self::KEY );
		$trigger->onInit();

		$this->assertSame( array(), $runner->calls );
	}

	public function test_on_init_run_without_key_is_rejected(): void {
		$trigger = $this->trigger( $this->settings( array( 'cron_key' => self::KEY ) ), $this->fakeRunner() );
		$_GET    = array( 'wp-pcloud-backup' => 'run' );

		Functions\expect( 'status_header' )->once()->with( 403 );

		$this->expectException( \RuntimeException::class );
		$trigger->onInit();
	}

	public function test_on_init_run_with_array_key_is_rejected(): void {
		$trigger = $this->trigger( $this->settings( array( 'cron_key' => self::KEY ) ), $this->fakeRunner() );
		$_GET    = array( 'wp-pcloud-backup' => 'run', 'key' => array( self::KEY ) );

		Functions\expect( 'status_header' )->once()->with( 403 );

		$this->expectException( \RuntimeException::class );
		$trigger->onInit();
	}

	public function test_cron_url_shape(): void {
		Functions\when( 'home_url' )->alias(
			static function ( $path = '' ) {
				return 'https://example.com' . $path;
			}
		);
		Functions\when( 'add_query_arg' )->alias(
			static function ( array $args, string $url ) {
				return $url . '?' . http_build_query( $args );
			}
		);

		$trigger = $this->trigger( $this->settings( array( 'cron_key' => self::KEY ) ), null );

		$this->assertSame( 'https://example.com/?wp-pcloud-backup=run&key=' . self::KEY, $trigger->cronUrl() );
	}

	public function test_register_adds_all_hooks(): void {
		$trigger = new Trigger( $this->settings( array() ), static function () {
			return null;
		} );

		Actions\expectAdded( Scheduler::HOOK )->once();
		Actions\expectAdded( 'init' )->once();
		Actions\expectAdded( 'admin_init' )->once();
		Actions\expectAdded( 'wp_ajax_wp_pcloud_backup_start' )->once();
		Actions\expectAdded( 'wp_ajax_wp_pcloud_backup_run' )->once();
		Actions\expectAdded( 'wp_ajax_wp_pcloud_backup_status' )->once();

		$trigger->register();

		$this->assertGreaterThan( 0, Mockery::getContainer()->mockery_getExpectationCount() );
	}

	public function test_ajax_start_runs_manual_job_then_run(): void {
		$runner  = $this->fakeRunner();
		$trigger = $this->trigger( $this->settings( array() ), $runner );
		$sent    = null;

		Functions\expect( 'check_ajax_referer' )->once()->with( 'wp_pcloud_backup', 'nonce' );
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'wp_send_json' )->alias(
			static function ( $data ) use ( &$sent ) {
				$sent = $data;
			}
		);

		$trigger->ajaxStart();

		$this->assertSame( array( 'startJob:manual', 'run' ), $runner->calls );
		$this->assertSame( 'ran', $sent['message'] );
	}

	public function test_ajax_run_and_status(): void {
		$runner  = $this->fakeRunner();
		$trigger = $this->trigger( $this->settings( array() ), $runner );

		Functions\when( 'check_ajax_referer' )->justReturn( 1 );
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'wp_send_json' )->justReturn( null );

		$trigger->ajaxRun();
		$trigger->ajaxStatus();

		$this->assertSame( array( 'run', 'status' ), $runner->calls );
	}

	public function test_ajax_without_capability_is_forbidden(): void {
		$runner  = $this->fakeRunner();
		$trigger = $this->trigger( $this->settings( array() ), $runner );

		Functions\when( 'check_ajax_referer' )->justReturn( 1 );
		Functions\expect( 'current_user_can' )->times( 3 )->with( 'manage_options' )->andReturn( false );
		Functions\expect( 'wp_send_json_error' )->times( 3 )->with( null, 403 );
		Functions\expect( 'wp_send_json' )->never();

		$trigger->ajaxStart();
		$trigger->ajaxRun();
		$trigger->ajaxStatus();

		$this->assertSame( array(), $runner->calls );
	}

	public function test_ajax_without_storage_reports_not_connected(): void {
		$trigger = $this->trigger( $this->settings( array() ), null );
		$sent    = null;

		Functions\when( 'check_ajax_referer' )->justReturn( 1 );
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'wp_send_json' )->alias(
			static function ( $data ) use ( &$sent ) {
				$sent = $data;
			}
		);

		$trigger->ajaxStart();

		$this->assertFalse( $sent['running'] );
		$this->assertSame( 'pCloud に接続されていません', $sent['message'] );
	}
	// ------------------------------------------------------------------
	// Stale detection from every entry point (real Settings on a fake wp_options).
	// ------------------------------------------------------------------

	private const NOW = 1800000000;

	/**
	 * Fake wp_options table.
	 *
	 * @var array
	 */
	private $options = array();

	/**
	 * Every wp_mail() call as array( to, subject, body ).
	 *
	 * @var array
	 */
	private $mails = array();

	/**
	 * Real Settings whose option holds $stored, plus a StaleMonitor on it.
	 *
	 * @return array{0:Settings,1:StaleMonitor}
	 */
	private function realSettings( array $stored, bool $connected = true ): array {
		$this->options = array(
			'admin_email'    => 'admin@example.com',
			Settings::OPTION => $stored + array(
				'schedule'     => 'daily',
				'notify_email' => 'admin@example.com',
				'cron_key'     => self::KEY,
			),
		);
		$this->mails   = array();

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
		Functions\when( 'home_url' )->justReturn( 'https://example.com' );
		Functions\when( 'site_url' )->justReturn( 'https://example.com' );
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_mail' )->alias(
			function ( $to, $subject, $message ) {
				$this->mails[] = array( $to, $subject, $message );
				return true;
			}
		);

		$settings = new Settings();
		$monitor  = new StaleMonitor(
			$settings,
			new Notifier( $settings, 'example.com', 'https://example.com/wp-admin/' ),
			array(
				'connected'        => $connected,
				'local_storage'    => false,
				'wp_cron_disabled' => false,
			)
		);

		return array( $settings, $monitor );
	}

	public function test_cron_with_wp_cron_off_and_a_silent_external_cron_sends_the_stalled_mail(): void {
		list( $settings, $monitor ) = $this->realSettings(
			array(
				'use_wp_cron'  => false,
				'activated_at' => self::NOW - 10 * 86400,
				'last_success' => self::NOW - 9 * 86400,
				'last_url_hit' => self::NOW - 9 * 86400,
			)
		);
		$runner                     = $this->fakeRunner();
		$trigger                    = $this->trigger( $settings, $runner, $monitor, self::NOW );

		$trigger->onCron();

		$this->assertSame( array(), $runner->calls, 'the backup itself is still left to the external cron' );
		$this->assertCount( 1, $this->mails );
		$this->assertStringContainsString( '停止中', $this->mails[0][1] );
		$this->assertStringContainsString( '外部 cron 用 URL が', $this->mails[0][2] );

		$trigger->onCron();
		$this->assertCount( 1, $this->mails, 'once a day' );
	}

	public function test_cron_without_storage_still_reports_an_undecryptable_token(): void {
		list( $settings, $monitor ) = $this->realSettings(
			array(
				'use_wp_cron'      => true,
				'activated_at'     => self::NOW - 60,
				'access_token_enc' => base64_encode( str_repeat( "\x02", 40 ) ),
			),
			false
		);
		$trigger                    = $this->trigger( $settings, null, $monitor, self::NOW );

		$trigger->onCron();

		$this->assertCount( 1, $this->mails );
		$this->assertStringContainsString( 'トークンを復号できません', $this->mails[0][2] );
		$this->assertSame( 0, $trigger->longRuns );
	}

	public function test_url_without_storage_checks_staleness_and_records_the_hit(): void {
		list( $settings, $monitor ) = $this->realSettings(
			array(
				'use_wp_cron'  => false,
				'activated_at' => self::NOW - 10 * 86400,
			),
			false
		);
		$trigger                    = $this->trigger( $settings, null, $monitor, self::NOW );
		$sent                       = null;

		Functions\expect( 'nocache_headers' )->once();
		Functions\when( 'wp_send_json' )->alias(
			static function ( $data ) use ( &$sent ) {
				$sent = $data;
			}
		);

		$trigger->handleUrl( self::KEY );

		$this->assertSame( 'pCloud に接続されていません', $sent['message'] );
		$this->assertSame( self::NOW, $this->options[ Settings::OPTION ]['last_url_hit'] );
		$this->assertCount( 1, $this->mails );
		$this->assertStringContainsString( 'pCloud に接続されていません', $this->mails[0][2] );
		$this->assertStringNotContainsString( '一度も呼ばれていません', $this->mails[0][2], 'the hit is recorded before the check' );
	}

	public function test_url_with_a_wrong_key_records_no_hit(): void {
		list( $settings, $monitor ) = $this->realSettings( array( 'activated_at' => self::NOW - 10 * 86400 ) );
		$trigger                    = $this->trigger( $settings, null, $monitor, self::NOW );

		Functions\expect( 'status_header' )->once()->with( 403 );

		try {
			$trigger->handleUrl( str_repeat( 'f', 64 ) );
			$this->fail( 'terminate() was not called' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'terminated', $e->getMessage() );
		}

		$this->assertArrayNotHasKey( 'last_url_hit', $this->options[ Settings::OPTION ] );
		$this->assertSame( array(), $this->mails );
	}

	public function test_admin_init_checks_at_most_once_an_hour(): void {
		list( $settings, $monitor ) = $this->realSettings(
			array(
				'use_wp_cron'  => true,
				'activated_at' => self::NOW - 10 * 86400,
			)
		);
		$trigger                    = $this->trigger( $settings, null, $monitor, self::NOW );
		$transients                 = array();

		Functions\when( 'get_transient' )->alias(
			static function ( $name ) use ( &$transients ) {
				return $transients[ $name ] ?? false;
			}
		);
		Functions\when( 'set_transient' )->alias(
			static function ( $name, $value, $ttl ) use ( &$transients ) {
				$transients[ $name ] = array( $value, $ttl );
				return true;
			}
		);

		$trigger->onAdminInit();
		$this->assertCount( 1, $this->mails );
		$this->assertSame( HOUR_IN_SECONDS, $transients[ Trigger::STALE_CHECK_TRANSIENT ][1] );

		// Within the hour the check does not run at all, even after the 24 h throttle is cleared.
		$this->options[ Settings::OPTION ]['last_stale_notice'] = 0;
		$trigger->onAdminInit();
		$this->assertCount( 1, $this->mails );
	}

	public function test_fresh_install_sends_nothing_from_cron(): void {
		list( $settings, $monitor ) = $this->realSettings( array( 'use_wp_cron' => false ) );
		$trigger                    = $this->trigger( $settings, null, $monitor, self::NOW );

		$trigger->onCron();

		$this->assertSame( array(), $this->mails );
		$this->assertSame( self::NOW, $this->options[ Settings::OPTION ]['activated_at'] );
	}
	// ------------------------------------------------------------------
	// Exceptions that escape the runner never become fatal errors.
	// ------------------------------------------------------------------

	private function throwingRunner(): object {
		return new class() {
			/** @var string[] */
			public $recorded = array();

			public function tick(): array {
				throw new \RuntimeException( 'Could not write job state: /var/www/html/wp-content/uploads/wp-pcloud-backup-abc/state.json.tmp' );
			}

			public function run(): array {
				throw new \Error( 'boom' );
			}

			public function startJob( string $trigger, ?int $slot = null ): bool {
				throw new \RuntimeException( 'Could not create the work directory.' );
			}

			public function status(): array {
				return array( 'running' => true, 'step' => 'zip', 'message' => 'zip', 'last_error' => null );
			}

			public function recordError( string $message ): void {
				$this->recorded[] = $message;
			}
		};
	}

	public function test_exceptions_from_the_runner_become_an_error_status(): void {
		$runner  = $this->throwingRunner();
		$trigger = $this->trigger( $this->settings( array( 'use_wp_cron' => true, 'cron_key' => self::KEY ) ), $runner );
		$sent    = array();

		Functions\when( 'check_ajax_referer' )->justReturn( 1 );
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'nocache_headers' )->justReturn( null );
		Functions\when( 'wp_send_json' )->alias(
			static function ( $data ) use ( &$sent ) {
				$sent[] = $data;
			}
		);

		$trigger->onCron();
		$trigger->handleUrl( self::KEY );
		$trigger->ajaxStart();
		$trigger->ajaxRun();

		$this->assertCount( 3, $sent );
		$this->assertSame( 'Could not write job state: state.json.tmp', $sent[0]['last_error'], 'no server path in the public URL response' );
		$this->assertSame( 'zip', $sent[0]['step'], 'the job status is still reported' );
		$this->assertStringContainsString( '実行中にエラーが発生しました', $sent[0]['message'] );
		$this->assertSame( 'Could not create the work directory.', $sent[1]['last_error'] );
		$this->assertSame( 'boom', $sent[2]['last_error'] );
		$this->assertSame(
			array( 'Could not write job state: state.json.tmp', 'Could not write job state: state.json.tmp', 'Could not create the work directory.', 'boom' ),
			$runner->recorded
		);
	}

	public function test_url_response_strips_paths_from_the_job_error(): void {
		$runner  = new class() {
			public function tick(): array {
				return array( 'running' => true, 'last_error' => 'Cannot open SQL file: ' . ABSPATH . 'wp-content/uploads/wp-pcloud-backup-abc/x-database.sql' );
			}
		};
		$trigger = $this->trigger( $this->settings( array( 'cron_key' => self::KEY ) ), $runner );
		$sent    = null;

		Functions\when( 'nocache_headers' )->justReturn( null );
		Functions\when( 'wp_send_json' )->alias(
			static function ( $data ) use ( &$sent ) {
				$sent = $data;
			}
		);

		$trigger->handleUrl( self::KEY );

		$this->assertSame( 'Cannot open SQL file: x-database.sql', $sent['last_error'] );
	}
}
