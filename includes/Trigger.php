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
	 * @var Settings
	 */
	private $settings;

	/**
	 * @var callable
	 */
	private $runnerProvider;

	/**
	 * @param Settings $settings       Plugin settings.
	 * @param callable $runnerProvider Returns the JobRunner (or any object with tick/run/startJob/status), or null if not connected.
	 */
	public function __construct( Settings $settings, callable $runnerProvider ) {
		$this->settings       = $settings;
		$this->runnerProvider = $runnerProvider;
	}

	public function register(): void {
		add_action( Scheduler::HOOK, array( $this, 'onCron' ) );
		add_action( 'init', array( $this, 'onInit' ) );
		add_action( 'wp_ajax_wp_pcloud_backup_start', array( $this, 'ajaxStart' ) );
		add_action( 'wp_ajax_wp_pcloud_backup_run', array( $this, 'ajaxRun' ) );
		add_action( 'wp_ajax_wp_pcloud_backup_status', array( $this, 'ajaxStatus' ) );
	}

	/**
	 * WP-Cron tick (every minute). Skipped when an external cron drives the backup instead.
	 */
	public function onCron(): void {
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

		$runner = $this->runner();
		if ( null === $runner ) {
			wp_send_json( self::notConnectedStatus() );
			return;
		}

		$this->beforeLongRun();
		wp_send_json( $runner->tick() );
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
