<?php

namespace WpPcloudBackup;

/**
 * Self-hosted updater: reads update.json from GitHub and plugs into the
 * WordPress plugin update flow. Packages are verified against a published
 * SHA-256 before WordPress is allowed to install them.
 *
 * Nothing in here may throw into WordPress' update machinery.
 */
class Updater {

	private const TRANSIENT = 'wp_pcloud_backup_update_manifest';

	private const NOTIFIED_TRANSIENT = 'wp_pcloud_backup_update_fail_notified';

	private const TIMEOUT = 15;

	private const REDIRECTION = 5;

	/**
	 * @var Settings
	 */
	private $settings;

	/**
	 * @var string
	 */
	private $updateUrl;

	/**
	 * @var string
	 */
	private $pluginBasename;

	/**
	 * @var string
	 */
	private $slug;

	/**
	 * @var string
	 */
	private $currentVersion;

	/**
	 * @var callable
	 */
	private $jobRunning;

	/**
	 * @var Notifier
	 */
	private $notifier;

	public function __construct(
		Settings $settings,
		string $updateUrl,
		string $pluginBasename,
		string $currentVersion,
		callable $jobRunning,
		Notifier $notifier
	) {
		$this->settings       = $settings;
		$this->updateUrl      = $updateUrl;
		$this->pluginBasename = $pluginBasename;
		$this->slug           = dirname( $pluginBasename );
		$this->currentVersion = $currentVersion;
		$this->jobRunning     = $jobRunning;
		$this->notifier       = $notifier;
	}

	public function register(): void {
		add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'injectUpdate' ) );
		add_filter( 'plugins_api', array( $this, 'pluginInfo' ), 10, 3 );
		add_filter( 'auto_update_plugin', array( $this, 'shouldAutoUpdate' ), 10, 2 );
		add_filter( 'upgrader_pre_download', array( $this, 'verifyPackage' ), 10, 4 );
		add_filter( 'upgrader_source_selection', array( $this, 'fixSourceDir' ), 10, 4 );
	}

	/**
	 * Remote manifest, cached for 12 hours. Null when unavailable or invalid.
	 *
	 * @return array|null
	 */
	public function fetchManifest( bool $force = false ): ?array {
		if ( ! $force ) {
			$cached = get_site_transient( self::TRANSIENT );
			if ( is_array( $cached ) ) {
				return isset( $cached['invalid'] ) ? null : $cached;
			}
		}

		$headers = array( 'Accept' => 'application/json' );
		if ( defined( 'WP_PCLOUD_BACKUP_GITHUB_TOKEN' ) && '' !== (string) WP_PCLOUD_BACKUP_GITHUB_TOKEN ) {
			$headers['Authorization'] = 'token ' . WP_PCLOUD_BACKUP_GITHUB_TOKEN;
		}

		$response = $this->httpGet( $this->updateUrl, $headers );
		if ( null === $response || 200 !== $response['status'] ) {
			return null;
		}

		$data = json_decode( $response['body'], true );
		if ( ! is_array( $data ) ) {
			return null;
		}

		if ( ! $this->isValidManifest( $data ) ) {
			set_site_transient( self::TRANSIENT, array( 'invalid' => true ), HOUR_IN_SECONDS );
			return null;
		}

		set_site_transient( self::TRANSIENT, $data, 12 * HOUR_IN_SECONDS );

		return $data;
	}

	/**
	 * Filter pre_set_site_transient_update_plugins.
	 *
	 * @param mixed $transient
	 * @return mixed
	 */
	public function injectUpdate( $transient ) {
		if ( ! is_object( $transient ) ) {
			return $transient;
		}

		$manifest = $this->fetchManifest();
		if ( null === $manifest ) {
			return $transient;
		}

		$available = version_compare( $manifest['version'], $this->currentVersion, '>' );
		$entry     = (object) array(
			'slug'          => $this->slug,
			'plugin'        => $this->pluginBasename,
			'new_version'   => $available ? $manifest['version'] : $this->currentVersion,
			'package'       => $manifest['download_url'],
			'url'           => $manifest['homepage'] ?? '',
			'requires'      => $manifest['requires'] ?? '',
			'requires_php'  => $manifest['requires_php'] ?? '',
			'tested'        => $manifest['tested'] ?? '',
			'icons'         => array(),
			'banners'       => array(),
			'banners_rtl'   => array(),
			'compatibility' => new \stdClass(),
		);

		if ( ! isset( $transient->response ) || ! is_array( $transient->response ) ) {
			$transient->response = array();
		}
		if ( ! isset( $transient->no_update ) || ! is_array( $transient->no_update ) ) {
			$transient->no_update = array();
		}

		if ( $available ) {
			$transient->response[ $this->pluginBasename ] = $entry;
			unset( $transient->no_update[ $this->pluginBasename ] );
		} else {
			unset( $transient->response[ $this->pluginBasename ] );
			$transient->no_update[ $this->pluginBasename ] = $entry;
		}

		return $transient;
	}

	/**
	 * Filter plugins_api: data for the "View details" modal.
	 *
	 * @param mixed  $result
	 * @param string $action
	 * @param mixed  $args
	 * @return mixed
	 */
	public function pluginInfo( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || ! is_object( $args ) || ( $args->slug ?? '' ) !== $this->slug ) {
			return $result;
		}

		$manifest = $this->fetchManifest();
		if ( null === $manifest ) {
			return $result;
		}

		return (object) array(
			'name'          => 'WP pCloud Backup',
			'slug'          => $this->slug,
			'version'       => $manifest['version'],
			'author'        => '',
			'homepage'      => $manifest['homepage'] ?? '',
			'requires'      => $manifest['requires'] ?? '',
			'requires_php'  => $manifest['requires_php'] ?? '',
			'tested'        => $manifest['tested'] ?? '',
			'download_link' => $manifest['download_url'],
			'sections'      => array(
				'changelog' => str_replace( array( "\r\n", "\r", "\n" ), '<br>', esc_html( (string) ( $manifest['changelog'] ?? '' ) ) ),
			),
		);
	}

	/**
	 * Filter auto_update_plugin.
	 *
	 * @param mixed $update
	 * @param mixed $item
	 * @return mixed
	 */
	public function shouldAutoUpdate( $update, $item ) {
		if ( is_object( $item ) && isset( $item->plugin ) && $item->plugin === $this->pluginBasename ) {
			if ( ! (bool) $this->settings->get( 'auto_update' ) ) {
				return false;
			}

			try {
				return ! (bool) ( $this->jobRunning )();
			} catch ( \Throwable $e ) {
				return false; // Unknown job state: assume running, do not update.
			}
		}

		return $update;
	}

	/**
	 * Filter upgrader_pre_download: download our package ourselves and check its SHA-256.
	 *
	 * Fails closed: for this plugin every problem (no checksum, mismatch, bad URL) is a
	 * WP_Error, never an unverified install.
	 *
	 * @param mixed  $reply
	 * @param string $package
	 * @param mixed  $upgrader
	 * @param array  $hookExtra
	 * @return mixed String path of the verified file, WP_Error, or $reply untouched for other plugins.
	 */
	public function verifyPackage( $reply, $package, $upgrader = null, $hookExtra = array() ) {
		if ( ! is_array( $hookExtra ) || ( $hookExtra['plugin'] ?? '' ) !== $this->pluginBasename ) {
			return $reply;
		}

		if ( ! is_string( $package ) || 0 !== stripos( $package, 'https://' ) ) {
			return $this->verificationFailed( null, 'wp_pcloud_backup_checksum_unavailable', $this->unavailableMessage() );
		}

		$manifest    = $this->fetchManifest();
		$checksumUrl = null !== $manifest && $manifest['download_url'] === $package ? $manifest['sha256_url'] : $package . '.sha256';

		$tmp = download_url( $package, 300 );
		if ( is_wp_error( $tmp ) ) {
			return $tmp;
		}
		$tmp = (string) $tmp;

		$expected = $this->fetchChecksum( $checksumUrl );
		if ( null === $expected ) {
			return $this->verificationFailed( $tmp, 'wp_pcloud_backup_checksum_unavailable', $this->unavailableMessage() );
		}

		$actual = is_file( $tmp ) ? hash_file( 'sha256', $tmp ) : false;
		if ( ! is_string( $actual ) || ! hash_equals( $expected, strtolower( $actual ) ) ) {
			return $this->verificationFailed(
				$tmp,
				'wp_pcloud_backup_bad_checksum',
				__( '更新パッケージの検証に失敗しました（SHA-256 不一致）', 'wp-pcloud-backup' )
			);
		}

		return $tmp;
	}

	/**
	 * Filter upgrader_source_selection: make the unpacked folder name match the plugin slug.
	 *
	 * @param mixed  $source
	 * @param string $remoteSource
	 * @param mixed  $upgrader
	 * @param array  $hookExtra
	 * @return mixed
	 */
	public function fixSourceDir( $source, $remoteSource = '', $upgrader = null, $hookExtra = array() ) {
		try {
			if ( ! is_array( $hookExtra ) || ( $hookExtra['plugin'] ?? '' ) !== $this->pluginBasename || ! is_string( $source ) ) {
				return $source;
			}

			$trimmed = rtrim( $source, '/\\' );
			if ( basename( $trimmed ) === $this->slug ) {
				return $source;
			}

			$target = rtrim( (string) $remoteSource, '/\\' ) . '/' . $this->slug;
			if ( file_exists( $target ) ) {
				return $source;
			}

			$fs    = $GLOBALS['wp_filesystem'] ?? null;
			$moved = is_object( $fs ) && method_exists( $fs, 'move' )
				? (bool) $fs->move( $trimmed, $target )
				: rename( $trimmed, $target ); // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename

			return $moved ? $target . '/' : $source;
		} catch ( \Throwable $e ) {
			return $source;
		}
	}

	/**
	 * First 64 hex characters of the published checksum file (lowercase), or null.
	 */
	private function fetchChecksum( string $url ): ?string {
		$response = $this->httpGet( $url );
		if ( null === $response || 200 !== $response['status'] ) {
			return null;
		}

		if ( 1 !== preg_match( '/^[0-9a-f]{64}/i', trim( $response['body'] ), $match ) ) {
			return null;
		}

		return strtolower( $match[0] );
	}

	private function unavailableMessage(): string {
		return __( '更新パッケージの検証用チェックサムを取得できませんでした', 'wp-pcloud-backup' );
	}

	/**
	 * Remove the temporary download, send one (throttled) mail and build the error.
	 */
	private function verificationFailed( ?string $tmp, string $code, string $message ): \WP_Error {
		if ( null !== $tmp ) {
			@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink
		}

		if ( false === get_site_transient( self::NOTIFIED_TRANSIENT ) ) {
			try {
				$this->notifier->updateFailed( $message );
			} catch ( \Throwable $e ) {
				unset( $e ); // A failed mail must not hide the verification error.
			}
			set_site_transient( self::NOTIFIED_TRANSIENT, 1, 6 * HOUR_IN_SECONDS );
		}

		return new \WP_Error( $code, $message );
	}

	/**
	 * GET through the WordPress HTTP API (follows redirects, which GitHub release downloads need).
	 * Seam for tests.
	 *
	 * @param array<string,string> $headers
	 * @return array{status:int,body:string}|null Null on transport failure.
	 */
	protected function httpGet( string $url, array $headers = array() ): ?array {
		$response = wp_remote_get(
			$url,
			array(
				'timeout'     => self::TIMEOUT,
				'redirection' => self::REDIRECTION,
				'headers'     => $headers,
			)
		);
		if ( is_wp_error( $response ) ) {
			return null;
		}

		return array(
			'status' => (int) wp_remote_retrieve_response_code( $response ),
			'body'   => (string) wp_remote_retrieve_body( $response ),
		);
	}

	private function isValidManifest( array $data ): bool {
		foreach ( array( 'version', 'download_url', 'sha256_url' ) as $key ) {
			if ( ! isset( $data[ $key ] ) || ! is_string( $data[ $key ] ) || '' === $data[ $key ] ) {
				return false;
			}
		}

		return 1 === preg_match( '/^\d+(\.\d+){1,3}([-+.][0-9A-Za-z.+-]+)?$/', $data['version'] )
			&& 0 === stripos( $data['download_url'], 'https://' )
			&& 0 === stripos( $data['sha256_url'], 'https://' );
	}
}
