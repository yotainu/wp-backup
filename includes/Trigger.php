<?php

namespace WpPcloudBackup;

/**
 * Entry points that advance the backup: WP-Cron, the secret external URL and
 * the admin AJAX endpoints.
 *
 * The runner is resolved lazily through a provider so that WordPress hooks can be
 * registered on every request without building storage clients. The provider
 * returns the JobRunner (tick/run/startJob/status), or null when the site is not
 * connected to pCloud.
 */
class Trigger {

	public const NONCE_ACTION = 'wp_pcloud_backup';

	/**
	 * Transient throttling the stale check on admin_init to once per hour.
	 */
	public const STALE_CHECK_TRANSIENT = 'wp_pcloud_backup_stale_check';

	/**
	 * last_url_hit is written at most this often (seconds).
	 */
	private const URL_HIT_RESOLUTION = 60;

	/**
	 * @var Settings
	 */
	private $settings;

	/**
	 * @var callable
	 */
	private $runnerProvider;

	/**
	 * @var StaleMonitor|null
	 */
	private $staleMonitor;

	/**
	 * @var callable
	 */
	private $clock;

	/**
	 * @param Settings          $settings       Plugin settings.
	 * @param callable          $runnerProvider Returns the JobRunner (or any object with tick/run/startJob/status), or null if not connected.
	 * @param StaleMonitor|null $staleMonitor   Stale detection run from every entry point; null disables it.
	 * @param callable|null     $clock          fn(): int, defaults to time().
	 */
	public function __construct( Settings $settings, callable $runnerProvider, ?StaleMonitor $staleMonitor = null, ?callable $clock = null ) {
		$this->settings       = $settings;
		$this->runnerProvider = $runnerProvider;
		$this->staleMonitor   = $staleMonitor;
		$this->clock          = $clock ?? 'time';
	}

	public function register(): void {
		add_action( Scheduler::HOOK, array( $this, 'onCron' ) );
		add_action( 'init', array( $this, 'onInit' ) );
		add_action( 'admin_init', array( $this, 'onAdminInit' ) );
		add_action( 'wp_ajax_wp_pcloud_backup_start', array( $this, 'ajaxStart' ) );
		add_action( 'wp_ajax_wp_pcloud_backup_run', array( $this, 'ajaxRun' ) );
		add_action( 'wp_ajax_wp_pcloud_backup_status', array( $this, 'ajaxStatus' ) );
	}

	/**
	 * WP-Cron tick (every minute). The stale check always runs; the backup itself is
	 * skipped when an external cron drives it instead.
	 */
	public function onCron(): void {
		$this->checkStale();

		if ( ! $this->settings->get( 'use_wp_cron' ) ) {
			return;
		}

		$runner = $this->runner();
		if ( null === $runner ) {
			return;
		}

		$this->beforeLongRun();
		$runner->tick();
	}

	public function onInit(): void {
		if ( ! isset( $_GET['wp-pcloud-backup'] ) || 'run' !== $_GET['wp-pcloud-backup'] ) {
			return;
		}

		$key = isset( $_GET['key'] ) && is_string( $_GET['key'] ) ? $_GET['key'] : '';

		$this->handleUrl( $key );
	}

	/**
	 * External cron URL. Only a tick can be triggered; a wrong or missing key gets 403.
	 */
	public function handleUrl( string $key ): void {
		$stored = $this->settings->get( 'cron_key' );

		if ( ! is_string( $stored ) || '' === $stored || ! hash_equals( $stored, $key ) ) {
			status_header( 403 );
			$this->terminate();
			return;
		}

		nocache_headers();
		$this->recordUrlHit();
		$this->checkStale();

		$runner = $this->runner();
		if ( null === $runner ) {
			wp_send_json( self::notConnectedStatus() );
			return;
		}

		$this->beforeLongRun();
		wp_send_json( $runner->tick() );
	}

	/**
	 * Stale check from the admin screens, at most once per hour.
	 */
	public function onAdminInit(): void {
		if ( null === $this->staleMonitor || false !== get_transient( self::STALE_CHECK_TRANSIENT ) ) {
			return;
		}

		set_transient( self::STALE_CHECK_TRANSIENT, 1, HOUR_IN_SECONDS );
		$this->checkStale();
	}

	public function ajaxStart(): void {
		if ( ! $this->authorizeAjax() ) {
			return;
		}

		$runner = $this->runner();
		if ( null === $runner ) {
			wp_send_json( self::notConnectedStatus() );
			return;
		}

		$this->beforeLongRun();
		$runner->startJob( 'manual' );
		wp_send_json( $runner->run() );
	}

	public function ajaxRun(): void {
		if ( ! $this->authorizeAjax() ) {
			return;
		}

		$runner = $this->runner();
		if ( null === $runner ) {
			wp_send_json( self::notConnectedStatus() );
			return;
		}

		$this->beforeLongRun();
		wp_send_json( $runner->run() );
	}

	public function ajaxStatus(): void {
		if ( ! $this->authorizeAjax() ) {
			return;
		}

		$runner = $this->runner();
		wp_send_json( null === $runner ? self::notConnectedStatus() : $runner->status() );
	}

	public function cronUrl(): string {
		return add_query_arg(
			array(
				'wp-pcloud-backup' => 'run',
				'key'              => $this->settings->get( 'cron_key' ),
			),
			home_url( '/' )
		);
	}

	/**
	 * Replace the secret of the external cron URL.
	 *
	 * @return string The new key (64 hex characters).
	 */
	public function regenerateKey(): string {
		$key = bin2hex( random_bytes( 32 ) );
		$this->settings->update( array( 'cron_key' => $key ) );

		return $key;
	}

	/**
	 * Status shape returned when no storage is configured.
	 */
	public static function notConnectedStatus(): array {
		return array(
			'running'        => false,
			'step'           => null,
			'progress'       => 0.0,
			'files_done'     => 0,
			'files_total'    => 0,
			'parts_uploaded' => 0,
			'started'        => null,
			'message'        => __( 'pCloud に接続されていません', 'wp-pcloud-backup' ),
			'last_error'     => null,
		);
	}

	/**
	 * Lift PHP limits for a request that may run for a while.
	 */
	protected function beforeLongRun(): void {
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 0 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
		ignore_user_abort( true );
	}

	/**
	 * Run the stale check; a failure (mail, options) never stops the entry point.
	 */
	private function checkStale(): void {
		if ( null === $this->staleMonitor ) {
			return;
		}

		try {
			$this->staleMonitor->check( $this->now() );
		} catch ( \Throwable $e ) {
			unset( $e );
		}
	}

	/**
	 * Remember when the cron URL was last called with a valid key (for the stale mail's cause).
	 */
	private function recordUrlHit(): void {
		$now = $this->now();
		if ( $now - (int) $this->settings->get( 'last_url_hit' ) >= self::URL_HIT_RESOLUTION ) {
			$this->settings->update( array( 'last_url_hit' => $now ) );
		}
	}

	private function now(): int {
		return (int) ( $this->clock )();
	}

	/**
	 * End the request. Overridden in tests.
	 */
	protected function terminate(): void {
		exit;
	}

	/**
	 * Nonce and capability check for the AJAX endpoints; sends a 403 error response on failure.
	 */
	private function authorizeAjax(): bool {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( null, 403 );
			return false;
		}

		return true;
	}

	/**
	 * @return object|null
	 */
	private function runner() {
		return ( $this->runnerProvider )();
	}
}
