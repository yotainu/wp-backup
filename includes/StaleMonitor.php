<?php

namespace WpPcloudBackup;

/**
 * "Backups have stopped" detection (spec 8.3), independent of storage and of the job runner.
 *
 * Called from every entry point (WP-Cron even when an external cron drives the backup,
 * the cron URL even when not connected, JobRunner::tick and, throttled, admin_init), so
 * a silent death is noticed wherever the site still executes PHP.
 *
 * Stale means: now - max(last_success, activated_at) > two periods (62 days for monthly).
 * activated_at is the baseline for a site that never succeeded; it is set on activation
 * and, when missing (installed before the key existed), on the first check.
 * A stored token that cannot be decrypted is reported as well, stale or not.
 * All mails share one throttle: at most one per STALE_NOTICE_INTERVAL (last_stale_notice).
 */
class StaleMonitor {

	public const STALE_NOTICE_INTERVAL = 86400;

	/**
	 * An external cron that has not called the URL for this long counts as stopped.
	 */
	private const URL_SILENCE = 86400;

	/**
	 * @var Settings
	 */
	private $settings;

	/**
	 * @var Notifier
	 */
	private $notifier;

	/**
	 * @var array
	 */
	private $env;

	/**
	 * @param Settings $settings Plugin settings.
	 * @param Notifier $notifier Mail notifier.
	 * @param array    $env      Overrides: wp_cron_disabled (bool, DISABLE_WP_CRON), local_storage (bool,
	 *                           development storage in use), connected (bool or callable returning bool).
	 */
	public function __construct( Settings $settings, Notifier $notifier, array $env = array() ) {
		$this->settings = $settings;
		$this->notifier = $notifier;
		$this->env      = $env;
	}

	/**
	 * Send the stalled mail when due.
	 *
	 * @return bool True when a mail was sent.
	 */
	public function check( int $now ): bool {
		$tokenBroken = $this->tokenUndecryptable();
		$stale       = $this->isStale( $now );

		if ( ! $tokenBroken && ! $stale ) {
			return false;
		}
		if ( $now - (int) $this->settings->get( 'last_stale_notice' ) < self::STALE_NOTICE_INTERVAL ) {
			return false;
		}

		$lastSuccess = (int) $this->settings->get( 'last_success' );
		$this->settings->update( array( 'last_stale_notice' => $now ) );
		$this->notifier->stalled( $lastSuccess > 0 ? $lastSuccess : null, $this->probableCause( $now ) );

		return true;
	}

	/**
	 * Whether no backup succeeded for more than two periods since max(last_success, activated_at).
	 */
	public function isStale( int $now ): bool {
		return Scheduler::isStale( $this->baseline( $now ), (string) $this->settings->get( 'schedule' ), $now );
	}

	/**
	 * Probable causes, most specific first, joined by " / ".
	 */
	public function probableCause( int $now ): string {
		$causes = array();

		if ( $this->tokenUndecryptable() ) {
			$causes[] = __( 'トークンを復号できません（AUTH_KEY/SECURE_AUTH_KEY が変更された可能性）', 'wp-pcloud-backup' );
		} elseif ( ! $this->isConnected() ) {
			$causes[] = __( 'pCloud に接続されていません', 'wp-pcloud-backup' );
		}

		if ( $this->settings->get( 'use_wp_cron' ) ) {
			if ( $this->wpCronDisabled() ) {
				$causes[] = __( 'DISABLE_WP_CRON が定義されているため、WP-Cron はアクセス時に実行されません（サーバーの cron から wp-cron.php が呼ばれていない可能性）', 'wp-pcloud-backup' );
			}
		} else {
			$lastHit = (int) $this->settings->get( 'last_url_hit' );
			if ( $lastHit <= 0 ) {
				$causes[] = __( 'WP-Cron を使わない設定ですが、外部 cron 用 URL が一度も呼ばれていません', 'wp-pcloud-backup' );
			} elseif ( $now - $lastHit > self::URL_SILENCE ) {
				/* translators: %s: date and time. */
				$causes[] = sprintf( __( 'WP-Cron を使わない設定ですが、外部 cron 用 URL が %s 以降呼ばれていません', 'wp-pcloud-backup' ), $this->formatTime( $lastHit ) );
			}
		}

		if ( array() === $causes ) {
			$causes[] = __( 'バックアップが周期の2倍以上成功していません', 'wp-pcloud-backup' );
		}

		return implode( ' / ', $causes );
	}

	/**
	 * A token blob is stored (and not overridden by the wp-config constant) but cannot be decrypted.
	 */
	public function tokenUndecryptable(): bool {
		return ! $this->localStorage() && $this->settings->tokenUndecryptable();
	}

	/**
	 * max(last_success, activated_at); a missing activated_at is stored first
	 * (last_success when there is one, so an existing alert is not postponed; otherwise now).
	 */
	private function baseline( int $now ): int {
		$lastSuccess = (int) $this->settings->get( 'last_success' );
		$activatedAt = (int) $this->settings->get( 'activated_at' );

		if ( $activatedAt <= 0 ) {
			$activatedAt = $lastSuccess > 0 ? $lastSuccess : $now;
			$this->settings->update( array( 'activated_at' => $activatedAt ) );
		}

		return max( $lastSuccess, $activatedAt );
	}

	private function isConnected(): bool {
		if ( array_key_exists( 'connected', $this->env ) ) {
			$connected = $this->env['connected'];

			return (bool) ( is_callable( $connected ) ? $connected() : $connected );
		}

		return $this->localStorage() || null !== $this->settings->accessToken();
	}

	private function wpCronDisabled(): bool {
		if ( array_key_exists( 'wp_cron_disabled', $this->env ) ) {
			return (bool) $this->env['wp_cron_disabled'];
		}

		return defined( 'DISABLE_WP_CRON' ) && (bool) constant( 'DISABLE_WP_CRON' );
	}

	private function localStorage(): bool {
		if ( array_key_exists( 'local_storage', $this->env ) ) {
			return (bool) $this->env['local_storage'];
		}

		return null !== Plugin::devStorageDir();
	}

	private function formatTime( int $timestamp ): string {
		return function_exists( 'wp_date' ) ? (string) wp_date( 'Y-m-d H:i', $timestamp ) : gmdate( 'Y-m-d H:i', $timestamp ) . ' UTC';
	}
}
