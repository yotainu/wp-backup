<?php

namespace WpPcloudBackup\Admin;

use WpPcloudBackup\BackupRoot;
use WpPcloudBackup\Plugin;
use WpPcloudBackup\Scheduler;
use WpPcloudBackup\Settings;
use WpPcloudBackup\Storage\CurlTransport;
use WpPcloudBackup\Storage\PcloudClient;
use WpPcloudBackup\Storage\StorageException;
use WpPcloudBackup\Trigger;

/**
 * Settings > pCloud Backup screen: status, settings and connection tabs.
 *
 * This class holds the admin-post handlers and prepares the data; the markup
 * lives in views/*.php.
 */
class AdminPage {

	public const SLUG = 'wp-pcloud-backup';

	public const HOOK_SUFFIX = 'settings_page_wp-pcloud-backup';

	public const NONCE_ACTION = 'wp_pcloud_backup_admin';

	public const NOTICE_TRANSIENT = 'wp_pcloud_backup_notice';

	private const REVEAL_PREFIX = 'wp_pcloud_backup_reveal_';

	private const TABS = array( 'status', 'settings', 'connection' );

	/**
	 * admin-post action suffix => handler method.
	 */
	private const POST_ACTIONS = array(
		'save_settings'  => 'handleSaveSettings',
		'save_token'     => 'handleSaveToken',
		'exchange_code'  => 'handleExchangeCode',
		'reveal_token'   => 'handleRevealToken',
		'disconnect'     => 'handleDisconnect',
		'regenerate_key' => 'handleRegenerateKey',
		'detect_root'    => 'handleDetectRoot',
		'abort'          => 'handleAbort',
	);

	/**
	 * Settings keys the form may change (cron_key, workdir_name and timestamps never).
	 */
	private const EDITABLE = array(
		'root_folder',
		'site_folder',
		'backup_root',
		'schedule',
		'start_time',
		'keep',
		'notify_email',
		'notify_on_success',
		'excludes',
		'part_size_mb',
		'run_budget_sec',
		'use_wp_cron',
		'auto_update',
	);

	private const CHECKBOXES = array( 'notify_on_success', 'use_wp_cron', 'auto_update' );

	/**
	 * @var Plugin
	 */
	private $plugin;

	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'addMenu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );

		foreach ( self::POST_ACTIONS as $action => $method ) {
			add_action( 'admin_post_wp_pcloud_backup_' . $action, array( $this, $method ) );
		}
	}

	public function addMenu(): void {
		add_options_page( 'pCloud Backup', 'pCloud Backup', 'manage_options', self::SLUG, array( $this, 'render' ) );
	}

	/**
	 * @param string $hook Current admin page hook suffix.
	 */
	public function enqueue( $hook ): void {
		if ( self::HOOK_SUFFIX !== $hook ) {
			return;
		}

		$plugin_file = dirname( __DIR__, 2 ) . '/wp-pcloud-backup.php';
		$version     = defined( 'WP_PCLOUD_BACKUP_VERSION' ) ? WP_PCLOUD_BACKUP_VERSION : false;

		wp_enqueue_style( 'wp-pcloud-backup-admin', plugins_url( 'includes/Admin/assets/admin.css', $plugin_file ), array(), $version );
		wp_enqueue_script( 'wp-pcloud-backup-admin', plugins_url( 'includes/Admin/assets/admin.js', $plugin_file ), array(), $version, true );
		wp_localize_script(
			'wp-pcloud-backup-admin',
			'wpPcloudBackup',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( Trigger::NONCE_ACTION ),
				'i18n'    => array(
					'starting'       => __( 'バックアップを開始しています…', 'wp-pcloud-backup' ),
					'requestFailed'  => __( '通信に失敗しました。再試行します…', 'wp-pcloud-backup' ),
					'gaveUp'         => __( '通信に失敗しました。ページを再読み込みしてください。', 'wp-pcloud-backup' ),
					'copied'         => __( 'コピーしました', 'wp-pcloud-backup' ),
					'copy'           => __( 'コピー', 'wp-pcloud-backup' ),
					'needClientId'   => __( '先に Client ID を入力してください。', 'wp-pcloud-backup' ),
					'confirmAbort'   => __( 'バックアップを中止しますか？', 'wp-pcloud-backup' ),
					'confirmDisc'    => __( 'pCloud との接続を解除しますか？', 'wp-pcloud-backup' ),
					'confirmRegen'   => __( 'URL を再生成すると、古い URL は使えなくなります。続行しますか？', 'wp-pcloud-backup' ),
					'keepPageOpen'   => __( 'この画面を開いたままにしてください。閉じると、サーバー側の定期実行（WP-Cron または外部 cron）が続きを処理します。', 'wp-pcloud-backup' ),
				),
			)
		);
	}

	/**
	 * Output the settings page.
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'このページにアクセスする権限がありません。', 'wp-pcloud-backup' ), '', array( 'response' => 403 ) );
			return;
		}

		$tab  = $this->currentTab();
		$data = array(
			'tab'    => $tab,
			'tabs'   => $this->tabLinks( $tab ),
			'notice' => $this->takeNotice(),
			'fields' => function ( string $action ): void {
				echo '<input type="hidden" name="action" value="' . esc_attr( 'wp_pcloud_backup_' . $action ) . '">';
				wp_nonce_field( self::NONCE_ACTION );
			},
			'post_url' => admin_url( 'admin-post.php' ),
			'format_time' => function ( int $timestamp, string $format = 'Y-m-d H:i' ): string {
				return $timestamp > 0 ? $this->formatTime( $timestamp, $format ) : '-';
			},
		);

		if ( 'settings' === $tab ) {
			$data += $this->settingsData();
		} elseif ( 'connection' === $tab ) {
			$data += $this->connectionData();
		} else {
			$data += $this->statusData();
		}

		include __DIR__ . '/views/layout.php';
	}

	/**
	 * Server and environment checks. Each entry: {value:mixed, ok:bool|null} (null = informational).
	 *
	 * @return array<string,array{value:mixed,ok:?bool}>
	 */
	public function diagnostics(): array {
		return ( new Diagnostics(
			$this->plugin->workDir(),
			function ( string $name ): bool {
				return $this->hasExtension( $name );
			},
			$this->isWpCronDisabled()
		) )->all();
	}

	// === admin-post handlers ===

	public function handleSaveSettings(): void {
		if ( ! $this->authorize() ) {
			return;
		}

		$settings = $this->plugin->settings();
		$post     = $this->post();
		$input    = array();

		foreach ( self::EDITABLE as $key ) {
			if ( $settings->isLocked( $key ) ) {
				continue;
			}

			if ( in_array( $key, self::CHECKBOXES, true ) ) {
				$input[ $key ] = ! empty( $post[ $key ] );
			} elseif ( 'excludes' === $key ) {
				if ( isset( $post[ $key ] ) && is_string( $post[ $key ] ) ) {
					$input[ $key ] = preg_split( '/\r\n|\r|\n/', $post[ $key ] );
				}
			} elseif ( isset( $post[ $key ] ) && is_scalar( $post[ $key ] ) ) {
				$input[ $key ] = (string) $post[ $key ];
			}
		}

		// A new schedule invalidates the stored slot; the runner recomputes the first one.
		foreach ( array( 'schedule', 'start_time' ) as $key ) {
			if ( isset( $input[ $key ] ) && (string) $settings->get( $key ) !== $input[ $key ] ) {
				$input['next_run'] = 0;
			}
		}

		$settings->update( $input );

		$this->finish( 'settings', 'success', __( '設定を保存しました。', 'wp-pcloud-backup' ) );
	}

	public function handleSaveToken(): void {
		if ( ! $this->authorize() ) {
			return;
		}

		$this->connect( trim( $this->postString( 'access_token' ) ) );
	}

	public function handleExchangeCode(): void {
		if ( ! $this->authorize() ) {
			return;
		}

		$id     = trim( $this->postString( 'client_id' ) );
		$secret = trim( $this->postString( 'client_secret' ) );
		$code   = trim( $this->postString( 'code' ) );

		if ( '' === $id || '' === $secret || '' === $code ) {
			$this->finish( 'connection', 'error', __( 'Client ID、Client Secret、認可コードをすべて入力してください。', 'wp-pcloud-backup' ) );
			return;
		}

		try {
			$token = $this->exchange( $id, $secret, $code );
		} catch ( StorageException $e ) {
			$this->finish(
				'connection',
				'error',
				sprintf(
					/* translators: %s: error message from pCloud. */
					__( 'トークンを取得できませんでした: %s', 'wp-pcloud-backup' ),
					$this->scrub( $e->getMessage(), array( $id, $secret, $code ) )
				)
			);
			return;
		}

		// Client ID and secret are never stored.
		$this->connect( trim( $token ) );
	}

	public function handleRevealToken(): void {
		if ( ! $this->authorize() ) {
			return;
		}

		if ( $this->plugin->settings()->isLocked( 'access_token' ) ) {
			$this->finish( 'connection', 'error', __( 'アクセストークンは wp-config.php の定数で設定されているため、ここには表示できません。', 'wp-pcloud-backup' ) );
			return;
		}

		$user = wp_get_current_user();
		if ( ! wp_check_password( $this->postString( 'current_password' ), (string) $user->user_pass, get_current_user_id() ) ) {
			$this->finish( 'connection', 'error', __( 'パスワードが正しくありません。', 'wp-pcloud-backup' ) );
			return;
		}

		$key = wp_generate_password( 32, false );
		set_transient( self::REVEAL_PREFIX . get_current_user_id(), $key, 60 );

		$this->redirect( $this->tabUrl( 'connection', array( 'reveal' => $key ) ) );
	}

	public function handleDisconnect(): void {
		if ( ! $this->authorize() ) {
			return;
		}

		$settings = $this->plugin->settings();
		if ( $settings->isLocked( 'access_token' ) ) {
			$this->finish( 'connection', 'error', __( 'アクセストークンは wp-config.php の定数で設定されているため、解除できません。', 'wp-pcloud-backup' ) );
			return;
		}

		$settings->setAccessToken( null );
		$settings->update( array( 'connection_email' => '' ) );

		$this->finish( 'connection', 'success', __( 'pCloud との接続を解除しました。', 'wp-pcloud-backup' ) );
	}

	public function handleRegenerateKey(): void {
		if ( ! $this->authorize() ) {
			return;
		}

		$this->plugin->trigger()->regenerateKey();

		$this->finish( 'connection', 'success', __( 'cron URL を再生成しました。外部 cron に新しい URL を設定してください。', 'wp-pcloud-backup' ) );
	}

	public function handleDetectRoot(): void {
		if ( ! $this->authorize() ) {
			return;
		}

		$this->plugin->settings()->update( array( 'backup_root' => BackupRoot::detect( ABSPATH, site_url(), home_url() ) ) );

		$this->finish( 'settings', 'success', __( 'バックアップ対象フォルダを自動検出の値に戻しました。', 'wp-pcloud-backup' ) );
	}

	public function handleAbort(): void {
		if ( ! $this->authorize() ) {
			return;
		}

		$runner = $this->runner();
		if ( null !== $runner && ! empty( $runner->status()['running'] ) ) {
			if ( ! $runner->abort( __( '管理画面から中止', 'wp-pcloud-backup' ) ) ) {
				// A run holds the lock (it is uploading a part, for example).
				$this->finish( 'status', 'error', __( '実行中のため中止できませんでした。少し待って再試行してください', 'wp-pcloud-backup' ) );
				return;
			}
			$this->finish( 'status', 'success', __( 'バックアップを中止しました。', 'wp-pcloud-backup' ) );
			return;
		}

		$this->finish( 'status', 'error', __( '実行中のバックアップはありません。', 'wp-pcloud-backup' ) );
	}

	// === seams ===

	/**
	 * pCloud client used to validate a token. Overridden in tests.
	 */
	protected function makeClient( string $token ): PcloudClient {
		return new PcloudClient( new CurlTransport(), $token, $this->apiHost() );
	}

	/**
	 * Exchange an OAuth code for a token. Overridden in tests.
	 *
	 * @throws StorageException On failure.
	 */
	protected function exchange( string $id, string $secret, string $code ): string {
		return PcloudClient::exchangeCode( new CurlTransport(), $id, $secret, $code, $this->apiHost() );
	}

	/**
	 * The job runner, or null when not connected. Overridden in tests (JobRunner is final).
	 *
	 * @return object|null
	 */
	protected function runner() {
		return $this->plugin->runner();
	}

	protected function hasExtension( string $name ): bool {
		return extension_loaded( $name );
	}

	protected function isWpCronDisabled(): bool {
		return defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON;
	}

	/**
	 * Local-directory storage (development mode) needs no pCloud token validation.
	 *
	 * Same rule as storage selection: see Plugin::devStorageDir().
	 */
	protected function isDevStorage(): bool {
		return null !== Plugin::devStorageDir();
	}

	protected function redirect( string $url ): void {
		wp_safe_redirect( $url );
		$this->terminate();
	}

	/**
	 * End the request. Overridden in tests.
	 */
	protected function terminate(): void {
		exit;
	}

	// === internals ===

	/**
	 * Site-timezone date; plain UTC when wp_date() is unavailable.
	 */
	private function formatTime( int $timestamp, string $format ): string {
		return function_exists( 'wp_date' ) ? (string) wp_date( $format, $timestamp ) : gmdate( $format, $timestamp );
	}

	private function apiHost(): string {
		return defined( 'WP_PCLOUD_BACKUP_API_HOST' ) ? (string) WP_PCLOUD_BACKUP_API_HOST : 'api.pcloud.com';
	}

	/**
	 * Capability and nonce check shared by every handler.
	 */
	private function authorize(): bool {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'この操作を行う権限がありません。', 'wp-pcloud-backup' ), '', array( 'response' => 403 ) );
			return false;
		}

		check_admin_referer( self::NONCE_ACTION );

		return true;
	}

	/**
	 * Validate the token against pCloud (unless in dev mode), then store it.
	 */
	private function connect( string $token ): void {
		$settings = $this->plugin->settings();

		if ( $settings->isLocked( 'access_token' ) ) {
			$this->finish( 'connection', 'error', __( 'アクセストークンは wp-config.php の定数で設定済みです。', 'wp-pcloud-backup' ) );
			return;
		}

		if ( '' === $token ) {
			$this->finish( 'connection', 'error', __( 'アクセストークンを入力してください。', 'wp-pcloud-backup' ) );
			return;
		}

		$email = '';
		if ( ! $this->isDevStorage() ) {
			try {
				$email = (string) ( $this->makeClient( $token )->userInfo()['email'] ?? '' );
			} catch ( StorageException $e ) {
				$this->finish(
					'connection',
					'error',
					sprintf(
						/* translators: %s: error message from pCloud. */
						__( 'pCloud に接続できませんでした: %s', 'wp-pcloud-backup' ),
						$this->scrub( $e->getMessage(), array( $token ) )
					)
				);
				return;
			}
		}

		try {
			$settings->setAccessToken( $token );
		} catch ( \RuntimeException $e ) {
			$this->finish( 'connection', 'error', __( 'トークンを暗号化して保存できませんでした。サーバーの OpenSSL 設定を確認してください。', 'wp-pcloud-backup' ) );
			return;
		}
		$settings->update( array( 'connection_email' => $email ) );

		$this->finish( 'connection', 'success', __( 'pCloud に接続しました。', 'wp-pcloud-backup' ) );
	}

	/**
	 * Remember a notice for the next page load and go back to a tab.
	 */
	private function finish( string $tab, string $type, string $message, array $args = array() ): void {
		set_transient( self::NOTICE_TRANSIENT, array( 'type' => $type, 'message' => $message ), 60 );
		$this->redirect( $this->tabUrl( $tab, $args ) );
	}

	/**
	 * @param string[] $secrets Values to remove from the message.
	 */
	private function scrub( string $message, array $secrets ): string {
		foreach ( $secrets as $secret ) {
			if ( '' !== $secret ) {
				$message = str_replace( $secret, '***', $message );
			}
		}

		return $message;
	}

	private function post(): array {
		return isset( $_POST ) && is_array( $_POST ) ? (array) wp_unslash( $_POST ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked in authorize().
	}

	private function postString( string $key ): string {
		$post = $this->post();

		return isset( $post[ $key ] ) && is_string( $post[ $key ] ) ? $post[ $key ] : '';
	}

	private function currentTab(): string {
		$tab = isset( $_GET['tab'] ) && is_string( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'status'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only navigation.

		return in_array( $tab, self::TABS, true ) ? $tab : 'status';
	}

	private function tabUrl( string $tab, array $args = array() ): string {
		return add_query_arg(
			array_merge( array( 'page' => self::SLUG, 'tab' => $tab ), $args ),
			admin_url( 'options-general.php' )
		);
	}

	/**
	 * @return array<string,array{label:string,url:string,current:bool}>
	 */
	private function tabLinks( string $current ): array {
		$labels = array(
			'status'     => __( '状態', 'wp-pcloud-backup' ),
			'settings'   => __( '設定', 'wp-pcloud-backup' ),
			'connection' => __( '接続', 'wp-pcloud-backup' ),
		);
		$links  = array();

		foreach ( $labels as $tab => $label ) {
			$links[ $tab ] = array(
				'label'   => $label,
				'url'     => $this->tabUrl( $tab ),
				'current' => $tab === $current,
			);
		}

		return $links;
	}

	/**
	 * One-shot notice left by a handler.
	 *
	 * @return array{type:string,message:string}|null
	 */
	private function takeNotice(): ?array {
		$notice = get_transient( self::NOTICE_TRANSIENT );
		if ( false === $notice ) {
			return null;
		}
		delete_transient( self::NOTICE_TRANSIENT );

		return is_array( $notice ) && isset( $notice['type'], $notice['message'] ) ? $notice : null;
	}

	private function statusData(): array {
		$settings = $this->plugin->settings();
		$runner   = $this->runner();
		$status   = null === $runner ? Trigger::notConnectedStatus() : $runner->status();

		return array(
			'connected'    => $this->plugin->isConnected(),
			'status'       => $status,
			'last_success' => (int) $settings->get( 'last_success' ),
			'next_run'     => null === $runner ? 0 : $runner->nextRun(),
			'history'      => array_slice( $this->plugin->history()->all(), 0, 20 ),
		);
	}

	private function settingsData(): array {
		$settings = $this->plugin->settings();
		$locked   = array();

		foreach ( self::EDITABLE as $key ) {
			$locked[ $key ] = $settings->isLocked( $key );
		}

		return array(
			'values'        => $settings->all(),
			'locked'        => $locked,
			'schedules'     => array(
				'daily'      => __( '毎日', 'wp-pcloud-backup' ),
				'every3days' => __( '3 日ごと', 'wp-pcloud-backup' ),
				'weekly'     => __( '毎週', 'wp-pcloud-backup' ),
				'biweekly'   => __( '2 週間ごと', 'wp-pcloud-backup' ),
				'monthly'    => __( '毎月', 'wp-pcloud-backup' ),
			),
			'part_sizes_mb' => Settings::PART_SIZES_MB,
		);
	}

	private function connectionData(): array {
		$settings = $this->plugin->settings();
		$user_id  = get_current_user_id();
		$locked   = $settings->isLocked( 'access_token' );
		$revealed = null;

		// One-time view: the key in the URL must match the transient, which is deleted on first use.
		$requested = isset( $_GET['reveal'] ) && is_string( $_GET['reveal'] ) ? (string) wp_unslash( $_GET['reveal'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- one-time key is the credential.
		if ( '' !== $requested && ! $locked ) {
			$stored = get_transient( self::REVEAL_PREFIX . $user_id );
			delete_transient( self::REVEAL_PREFIX . $user_id );
			if ( is_string( $stored ) && '' !== $stored && hash_equals( $stored, $requested ) ) {
				$revealed = $settings->accessToken();
			}
		}

		$next_cron = wp_next_scheduled( Scheduler::HOOK );

		return array(
			'connected'    => $this->plugin->isConnected(),
			'email'        => (string) $settings->get( 'connection_email' ),
			'token_locked' => $locked,
			'dev_storage'  => $this->isDevStorage(),
			'revealed'     => $revealed,
			'cron_url'     => $this->plugin->trigger()->cronUrl(),
			'use_wp_cron'  => (bool) $settings->get( 'use_wp_cron' ),
			'next_cron'    => false === $next_cron ? 0 : (int) $next_cron,
			'diagnostics'  => $this->diagnostics(),
		);
	}
}
