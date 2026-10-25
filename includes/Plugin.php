<?php

namespace WpPcloudBackup;

use WpPcloudBackup\Job\JobRunner;
use WpPcloudBackup\Storage\CurlTransport;
use WpPcloudBackup\Storage\LocalStorage;
use WpPcloudBackup\Storage\PcloudClient;
use WpPcloudBackup\Storage\StorageInterface;

/**
 * Plugin bootstrapper and small service container.
 *
 * Services are built lazily on first access so that a request that only needs
 * the hooks does not construct storage clients or touch the filesystem.
 */
final class Plugin {

	/**
	 * @var Plugin|null
	 */
	private static $instance = null;

	/**
	 * @var Settings|null
	 */
	private $settings = null;

	/**
	 * @var StorageInterface|null
	 */
	private $storage = null;

	/**
	 * @var bool
	 */
	private $storageBuilt = false;

	/**
	 * @var JobRunner|null
	 */
	private $runner = null;

	/**
	 * @var Trigger|null
	 */
	private $trigger = null;

	/**
	 * @var History|null
	 */
	private $history = null;

	/**
	 * @var Notifier|null
	 */
	private $notifier = null;

	/**
	 * @var WorkDir|null
	 */
	private $workDir = null;

	/**
	 * @var StaleMonitor|null
	 */
	private $staleMonitor = null;

	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Hooked on plugins_loaded.
	 */
	public static function boot(): void {
		$plugin = self::instance();

		load_plugin_textdomain(
			'wp-pcloud-backup',
			false,
			dirname( plugin_basename( WP_PCLOUD_BACKUP_FILE ) ) . '/languages'
		);

		// The cron_schedules filter must exist on every request, or WP-Cron cannot resolve our interval.
		Scheduler::registerCron();

		$plugin->trigger()->register();

		if ( is_admin() ) {
			( new Admin\AdminPage( $plugin ) )->register();
		}

		// Registered in every context: auto-updates run from WP-Cron.
		( new Updater(
			$plugin->settings(),
			WP_PCLOUD_BACKUP_UPDATE_URL,
			plugin_basename( WP_PCLOUD_BACKUP_FILE ),
			WP_PCLOUD_BACKUP_VERSION,
			function () use ( $plugin ) {
				$runner = $plugin->runner();
				return $runner ? (bool) $runner->status()['running'] : false;
			},
			$plugin->notifier()
		) )->register();
	}

	/**
	 * Activation hook: persist settings, make sure the cron key exists, create the work dir and schedule the tick.
	 */
	public static function activate(): void {
		$missing = self::missingRequirements();
		if ( array() !== $missing ) {
			deactivate_plugins( plugin_basename( WP_PCLOUD_BACKUP_FILE ) );
			wp_die(
				esc_html(
					'WP pCloud Backup を有効化できません。次の要件を満たしていません: ' . implode( '、', $missing )
					. '。サーバーの PHP 設定（または契約プラン）を確認してください。'
				),
				'',
				array( 'back_link' => true )
			);
		}

		$settings = new Settings();
		// Baseline for the stale check of a site that has not succeeded yet (reset on every activation).
		$settings->update( array( 'activated_at' => time() ) );

		if ( '' === (string) $settings->get( 'cron_key' ) ) {
			( new Trigger(
				$settings,
				static function () {
					return null;
				}
			) )->regenerateKey();
		}

		self::workDirFor( $settings )->ensure();
		Scheduler::registerCron();
	}

	/**
	 * Environment requirements that are not met.
	 *
	 * @param callable|null $extensionLoaded Seam for extension_loaded(); receives the extension name.
	 * @param int           $phpVersionId    PHP_VERSION_ID of the running interpreter.
	 * @param int           $intSize         PHP_INT_SIZE of the running interpreter (ZIP64 offsets need pack('P')).
	 * @return string[] Human readable names: "PHP 7.4", "64-bit PHP" and/or missing extension names. Empty when all is fine.
	 */
	public static function missingRequirements( ?callable $extensionLoaded = null, int $phpVersionId = PHP_VERSION_ID, int $intSize = PHP_INT_SIZE ): array {
		$extensionLoaded = $extensionLoaded ?? 'extension_loaded';
		$missing         = array();

		if ( $phpVersionId < 70400 ) {
			$missing[] = 'PHP 7.4';
		}

		if ( 8 !== $intSize ) {
			$missing[] = '64-bit PHP';
		}

		foreach ( array( 'zlib', 'curl', 'openssl' ) as $extension ) {
			if ( ! $extensionLoaded( $extension ) ) {
				$missing[] = $extension;
			}
		}

		return $missing;
	}

	/**
	 * Deactivation hook.
	 */
	public static function deactivate(): void {
		Scheduler::unregisterCron();
	}

	/**
	 * Storage selection. A local directory (development/test mode) wins over a token.
	 *
	 * @param string|null $token    pCloud access token.
	 * @param string|null $localDir Development-mode directory (see devStorageDir()), or null.
	 * @param string      $apiHost  pCloud API host.
	 * @return StorageInterface|null Null when not connected.
	 */
	public static function buildStorage( ?string $token, ?string $localDir, string $apiHost = 'api.pcloud.com' ): ?StorageInterface {
		if ( is_string( $localDir ) && '' !== $localDir ) {
			return new LocalStorage( $localDir );
		}

		if ( is_string( $token ) && '' !== $token ) {
			return new PcloudClient( new CurlTransport(), $token, $apiHost );
		}

		return null;
	}

	/**
	 * Development-mode storage directory (WP_PCLOUD_BACKUP_STORAGE_DIR), or null for pCloud.
	 *
	 * The single rule for every caller: an undefined or empty constant means pCloud. wp-env
	 * overrides cannot remove a constant, so an empty string is how development mode is turned off.
	 */
	public static function devStorageDir(): ?string {
		return self::devStorageDirFrom( defined( 'WP_PCLOUD_BACKUP_STORAGE_DIR' ) ? constant( 'WP_PCLOUD_BACKUP_STORAGE_DIR' ) : null );
	}

	/**
	 * @param mixed $value Raw constant value, or null when undefined.
	 */
	public static function devStorageDirFrom( $value ): ?string {
		if ( ! is_scalar( $value ) || '' === trim( (string) $value ) ) {
			return null;
		}

		return (string) $value;
	}

	public function settings(): Settings {
		if ( null === $this->settings ) {
			$this->settings = new Settings();
		}

		return $this->settings;
	}

	public function storage(): ?StorageInterface {
		if ( ! $this->storageBuilt ) {
			$this->storage = self::buildStorage(
				$this->settings()->accessToken(),
				self::devStorageDir(),
				defined( 'WP_PCLOUD_BACKUP_API_HOST' ) ? (string) WP_PCLOUD_BACKUP_API_HOST : 'api.pcloud.com'
			);
			$this->storageBuilt = true;
		}

		return $this->storage;
	}

	public function isConnected(): bool {
		return null !== $this->storage();
	}

	/**
	 * Null when no storage can be built (not connected).
	 */
	public function runner(): ?JobRunner {
		if ( null === $this->runner ) {
			$storage = $this->storage();
			if ( null === $storage ) {
				return null;
			}

			$this->runner = new JobRunner(
				$this->settings(),
				$this->workDir(),
				$storage,
				$this->notifier(),
				$this->history(),
				$GLOBALS['wpdb'],
				null,
				wp_timezone(),
				array(
					'site_url'       => site_url(),
					'home_url'       => home_url(),
					'wp_version'     => get_bloginfo( 'version' ),
					'plugin_version' => WP_PCLOUD_BACKUP_VERSION,
					'abspath'        => ABSPATH,
				),
				$this->staleMonitor()
			);
		}

		return $this->runner;
	}

	public function trigger(): Trigger {
		if ( null === $this->trigger ) {
			$this->trigger = new Trigger(
				$this->settings(),
				function () {
					return $this->runner();
				},
				$this->staleMonitor()
			);
		}

		return $this->trigger;
	}

	public function history(): History {
		if ( null === $this->history ) {
			$this->history = new History();
		}

		return $this->history;
	}

	public function notifier(): Notifier {
		if ( null === $this->notifier ) {
			$host           = parse_url( home_url(), PHP_URL_HOST );
			$this->notifier = new Notifier(
				$this->settings(),
				is_string( $host ) && '' !== $host ? $host : 'site',
				admin_url( 'options-general.php?page=wp-pcloud-backup' )
			);
		}

		return $this->notifier;
	}

	public function staleMonitor(): StaleMonitor {
		if ( null === $this->staleMonitor ) {
			$this->staleMonitor = new StaleMonitor(
				$this->settings(),
				$this->notifier(),
				array(
					'connected' => function (): bool {
						return $this->isConnected();
					},
				)
			);
		}

		return $this->staleMonitor;
	}

	public function workDir(): WorkDir {
		if ( null === $this->workDir ) {
			$this->workDir = self::workDirFor( $this->settings() );
		}

		return $this->workDir;
	}

	private static function workDirFor( Settings $settings ): WorkDir {
		$uploads = wp_upload_dir();

		return new WorkDir( $settings, (string) $uploads['basedir'] );
	}
}
