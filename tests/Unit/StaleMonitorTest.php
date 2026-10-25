<?php

namespace WpPcloudBackup\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use WpPcloudBackup\Notifier;
use WpPcloudBackup\Settings;
use WpPcloudBackup\StaleMonitor;

/**
 * StaleMonitor against the real Settings class on a fake wp_options.
 */
final class StaleMonitorTest extends TestCase {

	private const NOW = 1800000000;

	private const DAY = 86400;

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

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\stubTranslationFunctions();

		$this->options = array( 'admin_email' => 'admin@example.com' );
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
		Functions\when( 'wp_mail' )->alias(
			function ( $to, $subject, $message ) {
				$this->mails[] = array( $to, $subject, $message );
				return true;
			}
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * @param array $stored Stored option values.
	 * @param array $env    StaleMonitor overrides.
	 */
	private function monitor( array $stored, array $env = array() ): StaleMonitor {
		$this->options[ Settings::OPTION ] = $stored + array(
			'schedule'     => 'daily',
			'notify_email' => 'admin@example.com',
		);
		$settings                          = new Settings();

		return new StaleMonitor(
			$settings,
			new Notifier( $settings, 'example.com', 'https://example.com/wp-admin/' ),
			$env + array(
				'connected'        => true,
				'local_storage'    => false,
				'wp_cron_disabled' => false,
			)
		);
	}

	private function stored( string $key ) {
		return $this->options[ Settings::OPTION ][ $key ] ?? null;
	}

	public function test_fresh_install_before_two_periods_sends_nothing(): void {
		$monitor = $this->monitor( array( 'use_wp_cron' => true, 'activated_at' => self::NOW - self::DAY ) );

		$this->assertFalse( $monitor->check( self::NOW ) );
		$this->assertFalse( $monitor->check( self::NOW + self::DAY - 1 ), 'exactly two periods is not stale yet' );
		$this->assertSame( array(), $this->mails );
	}

	public function test_never_succeeded_site_past_two_periods_after_activation_is_stale(): void {
		$monitor = $this->monitor( array( 'use_wp_cron' => true, 'activated_at' => self::NOW - 2 * self::DAY - 1 ) );

		$this->assertTrue( $monitor->check( self::NOW ) );
		$this->assertCount( 1, $this->mails );
		$this->assertStringContainsString( '停止中', $this->mails[0][1] );
		$this->assertStringContainsString( '最後に成功した日時: なし', $this->mails[0][2] );
		$this->assertStringContainsString( 'バックアップが周期の2倍以上成功していません', $this->mails[0][2] );
		$this->assertSame( self::NOW, $this->stored( 'last_stale_notice' ) );
	}

	public function test_missing_activated_at_is_stored_and_starts_the_clock(): void {
		$monitor = $this->monitor( array( 'use_wp_cron' => true ) );

		$this->assertFalse( $monitor->check( self::NOW ) );
		$this->assertSame( self::NOW, $this->stored( 'activated_at' ) );

		$this->assertFalse( $monitor->check( self::NOW + 2 * self::DAY ) );
		$this->assertTrue( $monitor->check( self::NOW + 2 * self::DAY + 1 ) );
	}

	public function test_missing_activated_at_does_not_postpone_an_old_last_success(): void {
		$monitor = $this->monitor( array( 'use_wp_cron' => true, 'last_success' => self::NOW - 5 * self::DAY ) );

		$this->assertTrue( $monitor->check( self::NOW ) );
		$this->assertSame( self::NOW - 5 * self::DAY, $this->stored( 'activated_at' ) );
	}

	public function test_a_later_activation_resets_the_baseline(): void {
		$monitor = $this->monitor(
			array(
				'use_wp_cron'  => true,
				'last_success' => self::NOW - 30 * self::DAY,
				'activated_at' => self::NOW - self::DAY,
			)
		);

		$this->assertFalse( $monitor->check( self::NOW ) );
	}

	public function test_notice_is_throttled_to_once_a_day(): void {
		$monitor = $this->monitor( array( 'use_wp_cron' => true, 'activated_at' => self::NOW - 10 * self::DAY ) );

		$this->assertTrue( $monitor->check( self::NOW ) );
		$this->assertFalse( $monitor->check( self::NOW + self::DAY - 1 ) );
		$this->assertTrue( $monitor->check( self::NOW + self::DAY ) );
		$this->assertCount( 2, $this->mails );
	}

	public function test_undecryptable_token_is_reported_even_when_not_stale(): void {
		// A blob of the right shape whose tag cannot verify, whatever AUTH_KEY is.
		$blob    = base64_encode( str_repeat( "\x01", 40 ) );
		$monitor = $this->monitor(
			array(
				'use_wp_cron'      => true,
				'activated_at'     => self::NOW - 60,
				'access_token_enc' => $blob,
			),
			array( 'connected' => false )
		);

		$this->assertTrue( $monitor->tokenUndecryptable() );
		$this->assertTrue( $monitor->check( self::NOW ) );
		$this->assertCount( 1, $this->mails );
		$this->assertStringContainsString( 'トークンを復号できません（AUTH_KEY/SECURE_AUTH_KEY が変更された可能性）', $this->mails[0][2] );
		$this->assertStringNotContainsString( 'pCloud に接続されていません', $this->mails[0][2], 'the specific cause replaces the generic one' );

		$this->assertFalse( $monitor->check( self::NOW + 3600 ), 'same 24 h throttle' );
	}

	public function test_undecryptable_token_is_ignored_in_development_storage_mode(): void {
		$monitor = $this->monitor(
			array(
				'use_wp_cron'      => true,
				'activated_at'     => self::NOW - 60,
				'access_token_enc' => base64_encode( str_repeat( "\x01", 40 ) ),
			),
			array( 'local_storage' => true )
		);

		$this->assertFalse( $monitor->check( self::NOW ) );
	}

	public function test_cause_names_an_external_cron_that_never_called(): void {
		$monitor = $this->monitor( array( 'use_wp_cron' => false, 'activated_at' => self::NOW - 3 * self::DAY ) );

		$this->assertTrue( $monitor->check( self::NOW ) );
		$this->assertStringContainsString( '外部 cron 用 URL が一度も呼ばれていません', $this->mails[0][2] );
	}

	public function test_cause_names_an_external_cron_that_stopped(): void {
		$monitor = $this->monitor(
			array(
				'use_wp_cron'  => false,
				'activated_at' => self::NOW - 3 * self::DAY,
				'last_url_hit' => self::NOW - 2 * self::DAY,
			)
		);

		$cause = $monitor->probableCause( self::NOW );
		$this->assertStringContainsString( '外部 cron 用 URL が', $cause );
		$this->assertStringContainsString( '以降呼ばれていません', $cause );
	}

	public function test_cause_names_disable_wp_cron_and_not_connected(): void {
		$monitor = $this->monitor(
			array(
				'use_wp_cron'  => true,
				'activated_at' => self::NOW - 3 * self::DAY,
			),
			array(
				'connected'        => false,
				'wp_cron_disabled' => true,
			)
		);

		$cause = $monitor->probableCause( self::NOW );
		$this->assertStringContainsString( 'pCloud に接続されていません', $cause );
		$this->assertStringContainsString( 'DISABLE_WP_CRON', $cause );
	}

	public function test_connected_may_be_a_callable(): void {
		$calls   = 0;
		$monitor = $this->monitor(
			array( 'use_wp_cron' => true ),
			array(
				'connected' => static function () use ( &$calls ): bool {
					++$calls;
					return false;
				},
			)
		);

		$this->assertStringContainsString( 'pCloud に接続されていません', $monitor->probableCause( self::NOW ) );
		$this->assertSame( 1, $calls );
	}
}
