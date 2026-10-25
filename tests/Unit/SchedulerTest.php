<?php

namespace WpPcloudBackup\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use WpPcloudBackup\Scheduler;

final class SchedulerTest extends TestCase {

	/**
	 * @var \DateTimeZone
	 */
	private $jst;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\stubTranslationFunctions();
		$this->jst = new \DateTimeZone( 'Asia/Tokyo' );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function jst( string $datetime ): int {
		return ( new \DateTimeImmutable( $datetime, $this->jst ) )->getTimestamp();
	}

	private function format( int $epoch, ?\DateTimeZone $tz = null ): string {
		return ( new \DateTimeImmutable( '@' . $epoch ) )->setTimezone( $tz ?? $this->jst )->format( 'Y-m-d H:i' );
	}

	public function test_period_days(): void {
		$this->assertSame( 1, Scheduler::periodDays( 'daily' ) );
		$this->assertSame( 3, Scheduler::periodDays( 'every3days' ) );
		$this->assertSame( 7, Scheduler::periodDays( 'weekly' ) );
		$this->assertSame( 14, Scheduler::periodDays( 'biweekly' ) );
		$this->assertSame( 0, Scheduler::periodDays( 'monthly' ) );
	}

	public function test_first_run_today_or_tomorrow(): void {
		$before = $this->jst( '2026-10-25 02:00:00' );
		$after  = $this->jst( '2026-10-25 04:00:00' );

		$this->assertSame( '2026-10-25 03:17', $this->format( Scheduler::nextRun( 'weekly', '03:00', 17, null, $before, $this->jst ) ) );
		$this->assertSame( '2026-10-26 03:17', $this->format( Scheduler::nextRun( 'weekly', '03:00', 17, null, $after, $this->jst ) ) );
		$this->assertSame( '2026-10-26 03:17', $this->format( Scheduler::nextRun( 'monthly', '03:00', 17, null, $after, $this->jst ) ) );
	}

	public function test_first_run_exactly_at_scheduled_time_moves_to_tomorrow(): void {
		$now = $this->jst( '2026-10-25 03:17:00' );

		$this->assertSame( '2026-10-26 03:17', $this->format( Scheduler::nextRun( 'daily', '03:00', 17, null, $now, $this->jst ) ) );
	}

	public function test_zero_last_scheduled_is_treated_as_never(): void {
		$now = $this->jst( '2026-10-25 02:00:00' );

		$this->assertSame(
			Scheduler::nextRun( 'weekly', '03:00', 17, null, $now, $this->jst ),
			Scheduler::nextRun( 'weekly', '03:00', 17, 0, $now, $this->jst )
		);
	}

	public function test_grid_advances_without_drift(): void {
		$last = $this->jst( '2026-10-18 03:17:00' );
		$now  = $this->jst( '2026-10-27 12:00:00' );

		// 10/25 is already past; the next grid point is 11/1, not "now + 7 days".
		$this->assertSame( '2026-11-01 03:17', $this->format( Scheduler::nextRun( 'weekly', '03:00', 17, $last, $now, $this->jst ) ) );
	}

	public function test_next_run_is_strictly_after_now(): void {
		$last = $this->jst( '2026-10-18 03:17:00' );
		$now  = $this->jst( '2026-10-25 03:17:00' );

		$this->assertSame( '2026-11-01 03:17', $this->format( Scheduler::nextRun( 'weekly', '03:00', 17, $last, $now, $this->jst ) ) );
	}

	public function test_periods_other_than_weekly(): void {
		$last = $this->jst( '2026-10-01 03:00:00' );
		$now  = $this->jst( '2026-10-02 12:00:00' );

		$this->assertSame( '2026-10-03 03:00', $this->format( Scheduler::nextRun( 'daily', '03:00', 0, $last, $now, $this->jst ) ) );
		$this->assertSame( '2026-10-04 03:00', $this->format( Scheduler::nextRun( 'every3days', '03:00', 0, $last, $now, $this->jst ) ) );
		$this->assertSame( '2026-10-15 03:00', $this->format( Scheduler::nextRun( 'biweekly', '03:00', 0, $last, $now, $this->jst ) ) );
		$this->assertSame( '2026-11-01 03:00', $this->format( Scheduler::nextRun( 'monthly', '03:00', 0, $last, $now, $this->jst ) ) );
	}

	public function test_monthly_end_of_month(): void {
		$last = $this->jst( '2026-01-31 03:00:00' );
		$now  = $this->jst( '2026-02-01 00:00:00' );

		$this->assertSame( '2026-02-28 03:00', $this->format( Scheduler::nextRun( 'monthly', '03:00', 0, $last, $now, $this->jst ) ) );
	}

	public function test_monthly_leap_year_and_year_rollover(): void {
		$tz = $this->jst;

		$leap = $this->jst( '2028-01-31 03:00:00' );
		$this->assertSame( '2028-02-29 03:00', $this->format( Scheduler::nextRun( 'monthly', '03:00', 0, $leap, $leap + 3600, $tz ) ) );

		$dec = $this->jst( '2026-12-15 03:00:00' );
		$this->assertSame( '2027-01-15 03:00', $this->format( Scheduler::nextRun( 'monthly', '03:00', 0, $dec, $dec + 3600, $tz ) ) );
	}

	public function test_timezone_conversion(): void {
		$now  = ( new \DateTimeImmutable( '2026-10-24 12:00:00', new \DateTimeZone( 'UTC' ) ) )->getTimestamp();
		$next = Scheduler::nextRun( 'daily', '03:00', 0, null, $now, $this->jst );

		// JST 03:00 on 10/25 is 18:00 UTC on 10/24.
		$this->assertSame( '2026-10-24 18:00', $this->format( $next, new \DateTimeZone( 'UTC' ) ) );
		$this->assertSame( ( new \DateTimeImmutable( '2026-10-24 18:00:00', new \DateTimeZone( 'UTC' ) ) )->getTimestamp(), $next );
	}

	public function test_offset_is_stable_and_in_range(): void {
		$offset = Scheduler::offsetMinutes( 'example.com' );

		$this->assertSame( $offset, Scheduler::offsetMinutes( 'example.com' ) );
		$this->assertSame( crc32( 'example.com' ) % 60, $offset );
		foreach ( array( 'example.com', 'blog.example.jp', 'localhost', '', 'a' ) as $site ) {
			$this->assertGreaterThanOrEqual( 0, Scheduler::offsetMinutes( $site ) );
			$this->assertLessThan( 60, Scheduler::offsetMinutes( $site ) );
		}
	}

	public function test_is_stale(): void {
		$now = $this->jst( '2026-10-25 12:00:00' );

		$this->assertTrue( Scheduler::isStale( $now - 15 * 86400, 'weekly', $now ) );
		$this->assertFalse( Scheduler::isStale( $now - 13 * 86400, 'weekly', $now ) );
		$this->assertFalse( Scheduler::isStale( $now - 14 * 86400, 'weekly', $now ) );
		$this->assertFalse( Scheduler::isStale( null, 'weekly', $now ) );
		$this->assertFalse( Scheduler::isStale( 0, 'weekly', $now ) );
		$this->assertFalse( Scheduler::isStale( 0, 'monthly', $now ) );
		$this->assertTrue( Scheduler::isStale( $now - 3 * 86400, 'daily', $now ) );
		$this->assertTrue( Scheduler::isStale( $now - 63 * 86400, 'monthly', $now ) );
		$this->assertFalse( Scheduler::isStale( $now - 62 * 86400, 'monthly', $now ) );
	}

	public function test_register_cron_adds_schedule_and_event_when_missing(): void {
		$schedules = null;
		Filters\expectAdded( 'cron_schedules' )->once()->whenHappen(
			function ( $callback ) use ( &$schedules ) {
				$schedules = $callback( array( 'hourly' => array( 'interval' => 3600, 'display' => 'Hourly' ) ) );
			}
		);
		Functions\expect( 'wp_next_scheduled' )->once()->with( 'wp_pcloud_backup_tick' )->andReturn( false );
		Functions\expect( 'wp_schedule_event' )->once()->with(
			\Mockery::type( 'int' ),
			'wp_pcloud_backup_minutely',
			'wp_pcloud_backup_tick'
		);

		Scheduler::registerCron();

		$this->assertSame( 60, $schedules['wp_pcloud_backup_minutely']['interval'] );
		$this->assertNotSame( '', $schedules['wp_pcloud_backup_minutely']['display'] );
		$this->assertArrayHasKey( 'hourly', $schedules );
	}

	public function test_register_cron_does_not_reschedule_existing_event(): void {
		Filters\expectAdded( 'cron_schedules' )->once();
		Functions\expect( 'wp_next_scheduled' )->once()->with( 'wp_pcloud_backup_tick' )->andReturn( 1792000000 );
		Functions\expect( 'wp_schedule_event' )->never();

		Scheduler::registerCron();

		$this->addToAssertionCount( 1 ); // Mockery verifies the expectations in tearDown.
	}

	public function test_unregister_cron_clears_hook(): void {
		Functions\expect( 'wp_clear_scheduled_hook' )->once()->with( 'wp_pcloud_backup_tick' );

		Scheduler::unregisterCron();

		$this->addToAssertionCount( 1 ); // Mockery verifies the expectation in tearDown.
	}
}
