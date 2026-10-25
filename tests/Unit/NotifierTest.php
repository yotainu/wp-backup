<?php

namespace WpPcloudBackup\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use WpPcloudBackup\Notifier;
use WpPcloudBackup\Settings;

final class NotifierTest extends TestCase {

	private const ADMIN_URL = 'https://example.com/wp-admin/options-general.php?page=wp-pcloud-backup';

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

		$this->mails = array();
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

	private function notifier( array $values = array() ): Notifier {
		$values += array(
			'notify_email'      => 'a@example.com, b@example.com',
			'notify_on_success' => false,
		);

		$settings = new class( $values ) extends Settings {
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

		return new Notifier( $settings, 'example.com', self::ADMIN_URL );
	}

	private function summary(): array {
		return array(
			'bytes'    => 1536000,
			'files'    => 321,
			'duration' => 125,
			'remote'   => '/WPBackup/example.com/2026-10-25_031700',
		);
	}

	public function test_failure_subject_and_recipients(): void {
		$sent = $this->notifier()->failure( 'upload', 'pCloud error 2008: User is over quota', 3 );

		$this->assertTrue( $sent );
		$this->assertCount( 1, $this->mails );
		list( $to, $subject, $body ) = $this->mails[0];

		$this->assertSame( array( 'a@example.com', 'b@example.com' ), $to );
		$this->assertSame( '[WP pCloud Backup] 失敗: example.com', $subject );
		$this->assertStringStartsWith( 'サイト: example.com', $body );
		$this->assertStringContainsString( 'upload', $body );
		$this->assertStringContainsString( 'pCloud error 2008: User is over quota', $body );
		$this->assertStringContainsString( '3', $body );
		$this->assertStringEndsWith( self::ADMIN_URL, rtrim( $body ) );
		$this->assertStringNotContainsStringIgnoringCase( 'Bearer', $body );
		$this->assertStringNotContainsStringIgnoringCase( 'token', $body );
	}

	public function test_recipients_are_trimmed_and_empties_dropped(): void {
		$this->notifier( array( 'notify_email' => ' a@example.com ,, b@example.com , ' ) )->failure( 'zip', 'x', 1 );

		$this->assertSame( array( 'a@example.com', 'b@example.com' ), $this->mails[0][0] );
	}

	public function test_no_recipients_sends_nothing(): void {
		$notifier = $this->notifier( array( 'notify_email' => ' , ' ) );

		$this->assertFalse( $notifier->failure( 'zip', 'x', 1 ) );
		$this->assertFalse( $notifier->stalled( null, 'x' ) );
		$this->assertFalse( $notifier->completed( $this->summary(), array( 'warn' ) ) );
		$this->assertFalse( $notifier->updateFailed( 'x' ) );
		$this->assertSame( array(), $this->mails );
	}

	public function test_returns_wp_mail_result(): void {
		Functions\when( 'wp_mail' )->justReturn( false );

		$this->assertFalse( $this->notifier()->failure( 'zip', 'x', 1 ) );
	}

	public function test_stalled(): void {
		$last = ( new \DateTimeImmutable( '2026-10-01 03:00:00', new \DateTimeZone( 'UTC' ) ) )->getTimestamp();
		$this->notifier()->stalled( $last, 'WP-Cron が動作していません' );
		$this->notifier()->stalled( null, 'cause' );

		$this->assertSame( '[WP pCloud Backup] 停止中: example.com', $this->mails[0][1] );
		$this->assertStringStartsWith( 'サイト: example.com', $this->mails[0][2] );
		$this->assertStringContainsString( '2026-10-01', $this->mails[0][2] );
		$this->assertStringContainsString( 'WP-Cron が動作していません', $this->mails[0][2] );
		$this->assertStringEndsWith( self::ADMIN_URL, rtrim( $this->mails[0][2] ) );
		$this->assertStringContainsString( 'cause', $this->mails[1][2] );
	}

	public function test_completed_without_warnings_respects_setting(): void {
		$this->assertFalse( $this->notifier( array( 'notify_on_success' => false ) )->completed( $this->summary(), array() ) );
		$this->assertSame( array(), $this->mails );

		$this->assertTrue( $this->notifier( array( 'notify_on_success' => true ) )->completed( $this->summary(), array() ) );
		$this->assertCount( 1, $this->mails );
		list( , $subject, $body ) = $this->mails[0];

		$this->assertSame( '[WP pCloud Backup] 完了: example.com', $subject );
		$this->assertStringStartsWith( 'サイト: example.com', $body );
		$this->assertStringContainsString( '1.5 MB', $body );
		$this->assertStringContainsString( '321', $body );
		$this->assertStringContainsString( '2分5秒', $body );
		$this->assertStringContainsString( '/WPBackup/example.com/2026-10-25_031700', $body );
		$this->assertStringEndsWith( self::ADMIN_URL, rtrim( $body ) );
	}

	public function test_completed_with_warnings_always_sends(): void {
		$sent = $this->notifier( array( 'notify_on_success' => false ) )->completed( $this->summary(), array( '読めないファイル: a.jpg', 'vanished: b.txt' ) );

		$this->assertTrue( $sent );
		list( , $subject, $body ) = $this->mails[0];
		$this->assertSame( '[WP pCloud Backup] 完了(警告あり): example.com', $subject );
		$this->assertStringContainsString( '読めないファイル: a.jpg', $body );
		$this->assertStringContainsString( 'vanished: b.txt', $body );
	}

	public function test_warnings_are_capped_at_50_lines(): void {
		$warnings = array();
		for ( $i = 1; $i <= 53; $i++ ) {
			$warnings[] = "warning-{$i}-end";
		}

		$this->notifier()->completed( $this->summary(), $warnings );
		$body = $this->mails[0][2];

		$this->assertStringContainsString( 'warning-50-end', $body );
		$this->assertStringNotContainsString( 'warning-51-end', $body );
		$this->assertStringContainsString( '…他 3 件', $body );
		$this->assertStringEndsWith( self::ADMIN_URL, rtrim( $body ) );
	}

	public function test_update_failed(): void {
		$this->assertTrue( $this->notifier()->updateFailed( 'チェックサムが一致しません' ) );

		list( , $subject, $body ) = $this->mails[0];
		$this->assertSame( '[WP pCloud Backup] 更新失敗: example.com', $subject );
		$this->assertStringStartsWith( 'サイト: example.com', $body );
		$this->assertStringContainsString( 'チェックサムが一致しません', $body );
		$this->assertStringEndsWith( self::ADMIN_URL, rtrim( $body ) );
	}
}
