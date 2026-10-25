<?php

namespace WpPcloudBackup;

/**
 * Plain-text notification emails. Bodies contain only the values passed in,
 * never credentials.
 */
final class Notifier {

	private const SUBJECT_PREFIX = '[WP pCloud Backup] ';

	private const MAX_WARNING_LINES = 50;

	/**
	 * @var Settings
	 */
	private $settings;

	/**
	 * @var string
	 */
	private $host;

	/**
	 * @var string
	 */
	private $adminUrl;

	public function __construct( Settings $settings, string $host, string $adminUrl ) {
		$this->settings = $settings;
		$this->host     = $host;
		$this->adminUrl = $adminUrl;
	}

	/**
	 * A run gave up after retries.
	 */
	public function failure( string $step, string $error, int $attempts ): bool {
		return $this->send(
			'失敗',
			array(
				/* translators: %s: step name. */
				sprintf( __( '失敗したステップ: %s', 'wp-pcloud-backup' ), $step ),
				/* translators: %d: number of attempts. */
				sprintf( __( '試行回数: %d', 'wp-pcloud-backup' ), $attempts ),
				/* translators: %s: error message. */
				sprintf( __( 'エラー: %s', 'wp-pcloud-backup' ), $error ),
			)
		);
	}

	/**
	 * No successful backup for too long.
	 *
	 * @param int|null $lastSuccess Epoch of the last success, or null if none.
	 */
	public function stalled( ?int $lastSuccess, string $cause ): bool {
		$last = null === $lastSuccess || $lastSuccess <= 0 ? __( 'なし', 'wp-pcloud-backup' ) : $this->formatTime( $lastSuccess );

		return $this->send(
			'停止中',
			array(
				__( 'バックアップが予定どおりに実行されていません。', 'wp-pcloud-backup' ),
				/* translators: %s: date and time. */
				sprintf( __( '最後に成功した日時: %s', 'wp-pcloud-backup' ), $last ),
				/* translators: %s: probable cause. */
				sprintf( __( '考えられる原因: %s', 'wp-pcloud-backup' ), $cause ),
			)
		);
	}

	/**
	 * A run finished. Sent always when there are warnings, otherwise only if "notify on success" is on.
	 *
	 * @param array    $summary  {bytes:int, files:int, duration:int (seconds), remote:string}
	 * @param string[] $warnings Warning messages.
	 */
	public function completed( array $summary, array $warnings ): bool {
		$warnings = array_values( $warnings );
		if ( array() === $warnings && ! $this->settings->get( 'notify_on_success' ) ) {
			return false;
		}

		$lines = array(
			/* translators: %s: data size. */
			sprintf( __( 'サイズ: %s', 'wp-pcloud-backup' ), $this->formatBytes( (int) ( $summary['bytes'] ?? 0 ) ) ),
			/* translators: %d: number of files. */
			sprintf( __( 'ファイル数: %d', 'wp-pcloud-backup' ), (int) ( $summary['files'] ?? 0 ) ),
			/* translators: %s: elapsed time. */
			sprintf( __( '所要時間: %s', 'wp-pcloud-backup' ), $this->formatDuration( (int) ( $summary['duration'] ?? 0 ) ) ),
			/* translators: %s: pCloud path. */
			sprintf( __( '保存先: %s', 'wp-pcloud-backup' ), (string) ( $summary['remote'] ?? '' ) ),
		);

		if ( array() === $warnings ) {
			return $this->send( '完了', $lines );
		}

		$lines[] = '';
		/* translators: %d: number of warnings. */
		$lines[] = sprintf( __( '警告 (%d 件):', 'wp-pcloud-backup' ), count( $warnings ) );
		foreach ( array_slice( $warnings, 0, self::MAX_WARNING_LINES ) as $warning ) {
			$lines[] = '- ' . $warning;
		}
		if ( count( $warnings ) > self::MAX_WARNING_LINES ) {
			/* translators: %d: number of omitted warnings. */
			$lines[] = sprintf( __( '…他 %d 件', 'wp-pcloud-backup' ), count( $warnings ) - self::MAX_WARNING_LINES );
		}

		return $this->send( '完了(警告あり)', $lines );
	}

	/**
	 * Plugin self-update was rejected or failed.
	 */
	public function updateFailed( string $reason ): bool {
		return $this->send(
			'更新失敗',
			array(
				__( 'プラグインの自動更新に失敗しました。', 'wp-pcloud-backup' ),
				/* translators: %s: reason. */
				sprintf( __( '理由: %s', 'wp-pcloud-backup' ), $reason ),
			)
		);
	}

	/**
	 * @param string   $label Subject label (after the plugin prefix).
	 * @param string[] $lines Body lines between the site line and the admin URL.
	 */
	private function send( string $label, array $lines ): bool {
		$recipients = $this->recipients();
		if ( array() === $recipients ) {
			return false;
		}

		$body = implode(
			"\n",
			array_merge(
				/* translators: %s: site host name. */
				array( sprintf( __( 'サイト: %s', 'wp-pcloud-backup' ), $this->host ), '' ),
				$lines,
				array( '', $this->adminUrl )
			)
		);

		return (bool) wp_mail( $recipients, self::SUBJECT_PREFIX . $label . ': ' . $this->host, $body );
	}

	/**
	 * @return string[]
	 */
	private function recipients(): array {
		$list = array();
		foreach ( explode( ',', (string) $this->settings->get( 'notify_email' ) ) as $email ) {
			$email = trim( $email );
			if ( '' !== $email ) {
				$list[] = $email;
			}
		}

		return $list;
	}

	private function formatBytes( int $bytes ): string {
		$units = array( 'B', 'KB', 'MB', 'GB', 'TB' );
		$size  = (float) max( 0, $bytes );
		$unit  = 0;
		while ( $size >= 1024 && $unit < count( $units ) - 1 ) {
			$size /= 1024;
			++$unit;
		}

		return 0 === $unit ? $bytes . ' B' : round( $size, 1 ) . ' ' . $units[ $unit ];
	}

	private function formatDuration( int $seconds ): string {
		$seconds = max( 0, $seconds );
		$hours   = intdiv( $seconds, 3600 );
		$minutes = intdiv( $seconds % 3600, 60 );
		$rest    = $seconds % 60;

		if ( $hours > 0 ) {
			/* translators: 1: hours, 2: minutes, 3: seconds. */
			return sprintf( __( '%1$d時間%2$d分%3$d秒', 'wp-pcloud-backup' ), $hours, $minutes, $rest );
		}
		if ( $minutes > 0 ) {
			/* translators: 1: minutes, 2: seconds. */
			return sprintf( __( '%1$d分%2$d秒', 'wp-pcloud-backup' ), $minutes, $rest );
		}

		/* translators: %d: seconds. */
		return sprintf( __( '%d秒', 'wp-pcloud-backup' ), $rest );
	}

	private function formatTime( int $timestamp ): string {
		return function_exists( 'wp_date' ) ? (string) wp_date( 'Y-m-d H:i', $timestamp ) : gmdate( 'Y-m-d H:i', $timestamp ) . ' UTC';
	}
}
