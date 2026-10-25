<?php

namespace WpPcloudBackup;

/**
 * Backup schedule math (pure functions) and WP-Cron registration.
 */
final class Scheduler {

	public const HOOK = 'wp_pcloud_backup_tick';

	public const INTERVAL = 'wp_pcloud_backup_minutely';

	private const PERIOD_DAYS = array(
		'daily'      => 1,
		'every3days' => 3,
		'weekly'     => 7,
		'biweekly'   => 14,
		'monthly'    => 0,
	);

	private const MONTHLY_STALE_DAYS = 62;

	/**
	 * Days between runs; 0 means calendar month.
	 */
	public static function periodDays( string $schedule ): int {
		return self::PERIOD_DAYS[ $schedule ] ?? 0;
	}

	/**
	 * Stable per-site minute offset (0-59) so many sites do not start at the same minute.
	 */
	public static function offsetMinutes( string $siteFolder ): int {
		return crc32( $siteFolder ) % 60;
	}

	/**
	 * Next run time on the schedule grid.
	 *
	 * Without a previous slot (null or 0) the next occurrence of start time + offset
	 * is used. Otherwise the period is added to the previous slot until the result is
	 * after $now, so a late run does not shift the grid.
	 *
	 * @param string        $schedule      One of Settings::SCHEDULES.
	 * @param string        $startTime     "HH:MM" in $tz.
	 * @param int           $offsetMinutes Minutes added to the start time.
	 * @param int|null      $lastScheduled Previous slot (epoch), or null/0 if none.
	 * @param int           $now           Current epoch.
	 * @param \DateTimeZone $tz            Site timezone.
	 * @return int Epoch.
	 */
	public static function nextRun( string $schedule, string $startTime, int $offsetMinutes, ?int $lastScheduled, int $now, \DateTimeZone $tz ): int {
		if ( null === $lastScheduled || $lastScheduled <= 0 ) {
			list( $hour, $minute ) = array_map( 'intval', explode( ':', $startTime ) + array( 0, 0 ) );

			$slot = ( new \DateTimeImmutable( '@' . $now ) )->setTimezone( $tz )->setTime( $hour, $minute )->modify( '+' . $offsetMinutes . ' minutes' );
			if ( $slot->getTimestamp() <= $now ) {
				$slot = $slot->modify( '+1 day' );
			}

			return $slot->getTimestamp();
		}

		$days = self::periodDays( $schedule );
		$slot = ( new \DateTimeImmutable( '@' . $lastScheduled ) )->setTimezone( $tz );
		do {
			$slot = 0 === $days ? self::addMonth( $slot ) : $slot->modify( '+' . $days . ' days' );
		} while ( $slot->getTimestamp() <= $now );

		return $slot->getTimestamp();
	}

	/**
	 * Whether the last successful backup is too old (more than two periods).
	 *
	 * Null or 0 (the Settings default) means "never succeeded", which is not stale.
	 */
	public static function isStale( ?int $lastSuccess, string $schedule, int $now ): bool {
		if ( null === $lastSuccess || $lastSuccess <= 0 ) {
			return false;
		}

		$days = self::periodDays( $schedule );
		$days = 0 === $days ? self::MONTHLY_STALE_DAYS : 2 * $days;

		return $now - $lastSuccess > $days * 86400;
	}

	/**
	 * Register the one-minute cron interval and the recurring tick event.
	 */
	public static function registerCron(): void {
		add_filter(
			'cron_schedules',
			static function ( $schedules ) {
				$schedules[ self::INTERVAL ] = array(
					'interval' => 60,
					'display'  => __( 'Every minute (WP pCloud Backup)', 'wp-pcloud-backup' ),
				);

				return $schedules;
			}
		);

		if ( false === wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + 60, self::INTERVAL, self::HOOK );
		}
	}

	public static function unregisterCron(): void {
		wp_clear_scheduled_hook( self::HOOK );
	}

	/**
	 * Same wall-clock time one calendar month later; the day is clamped to the month end.
	 */
	private static function addMonth( \DateTimeImmutable $date ): \DateTimeImmutable {
		$year  = (int) $date->format( 'Y' );
		$month = (int) $date->format( 'n' ) + 1;
		if ( $month > 12 ) {
			$month = 1;
			++$year;
		}

		$day = min( (int) $date->format( 'j' ), (int) ( new \DateTimeImmutable( sprintf( '%04d-%02d-01', $year, $month ) ) )->format( 't' ) );

		return $date->setDate( $year, $month, $day );
	}
}
