<?php

namespace WpPcloudBackup\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use Mockery;
use PHPUnit\Framework\TestCase;
use WpPcloudBackup\Scheduler;
use WpPcloudBackup\Settings;
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

		return $settings;
	}

	/**
	 * Trigger whose terminate() throws instead of exiting, and whose beforeLongRun() is recorded.
	 */
	private function trigger( Settings $settings, ?object $runner ): Trigger {
		return new class( $settings, static function () use ( $runner ) {
			return $runner;
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
}
