<?php

namespace WpPcloudBackup;

/**
 * Web-inaccessible work directory under the uploads directory.
 */
class WorkDir {

	private const PREFIX = 'wp-pcloud-backup-';

	private const HTACCESS = "# WP pCloud Backup work directory - deny all web access\n"
		. "<IfModule mod_authz_core.c>\n"
		. "\tRequire all denied\n"
		. "</IfModule>\n"
		. "<IfModule !mod_authz_core.c>\n"
		. "\tDeny from all\n"
		. "</IfModule>\n";

	/**
	 * Settings store holding the generated directory name.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * Absolute uploads base directory, without trailing slash.
	 *
	 * @var string
	 */
	private $uploadsBaseDir;

	public function __construct( Settings $settings, string $uploadsBaseDir ) {
		$this->settings       = $settings;
		$this->uploadsBaseDir = rtrim( $uploadsBaseDir, '/\\' );
	}

	/**
	 * Create the work directory if needed and return its absolute path.
	 *
	 * @throws \RuntimeException When the directory cannot be created.
	 */
	public function ensure(): string {
		$name = (string) $this->settings->get( 'workdir_name' );
		$dir  = '' === $name ? '' : $this->uploadsBaseDir . '/' . $name;

		if ( '' === $dir || ! is_dir( $dir ) ) {
			$name = self::PREFIX . $this->randomSuffix( 16 );
			$dir  = $this->uploadsBaseDir . '/' . $name;

			$created = function_exists( 'wp_mkdir_p' ) ? wp_mkdir_p( $dir ) : mkdir( $dir, 0755, true );
			if ( ! $created && ! is_dir( $dir ) ) {
				throw new \RuntimeException( 'Could not create the work directory.' );
			}

			$this->settings->update( array( 'workdir_name' => $name ) );
		}

		$this->writeIfMissing( $dir . '/.htaccess', self::HTACCESS );
		$this->writeIfMissing( $dir . '/index.php', '' );

		return $dir;
	}

	/**
	 * Absolute path of the work directory, or of a file inside it.
	 *
	 * Callers must call ensure() first.
	 *
	 * @throws \RuntimeException When no work directory has been created yet.
	 */
	public function path( string $file = '' ): string {
		$name = (string) $this->settings->get( 'workdir_name' );
		if ( '' === $name ) {
			throw new \RuntimeException( 'Work directory has not been created yet.' );
		}

		$dir = $this->uploadsBaseDir . '/' . $name;

		return '' === $file ? $dir : $dir . '/' . ltrim( $file, '/' );
	}

	/**
	 * Read-only view of the work directory for diagnostics: never creates or repairs anything.
	 *
	 * @return array{exists:bool,writable:bool,htaccess:bool,index:bool,state:bool}
	 *         writable: the directory is writable, or (not created yet) the uploads directory is.
	 */
	public function inspect(): array {
		$name = (string) $this->settings->get( 'workdir_name' );
		$dir  = '' === $name ? '' : $this->uploadsBaseDir . '/' . $name;

		if ( '' === $dir || ! is_dir( $dir ) ) {
			return array(
				'exists'   => false,
				'writable' => is_dir( $this->uploadsBaseDir ) && is_writable( $this->uploadsBaseDir ),
				'htaccess' => false,
				'index'    => false,
				'state'    => false,
			);
		}

		return array(
			'exists'   => true,
			'writable' => is_writable( $dir ),
			'htaccess' => is_file( $dir . '/.htaccess' ),
			'index'    => is_file( $dir . '/index.php' ),
			'state'    => is_file( $dir . '/state.json' ),
		);
	}

	/**
	 * Whether the web server honours .htaccess files.
	 */
	public function isWebServerApache(): bool {
		$software = (string) ( $_SERVER['SERVER_SOFTWARE'] ?? '' );

		return false !== stripos( $software, 'apache' ) || false !== stripos( $software, 'litespeed' );
	}

	/**
	 * Free disk space in bytes, or null when unknown.
	 */
	public function freeBytes(): ?int {
		$name = (string) $this->settings->get( 'workdir_name' );
		if ( '' === $name ) {
			return null;
		}

		$dir = $this->uploadsBaseDir . '/' . $name;
		if ( ! is_dir( $dir ) ) {
			return null;
		}

		$free = @disk_free_space( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( false === $free ) {
			return null;
		}

		return (int) $free;
	}

	private function writeIfMissing( string $file, string $contents ): void {
		if ( is_file( $file ) ) {
			return;
		}

		if ( false === file_put_contents( $file, $contents ) ) {
			throw new \RuntimeException( 'Could not write ' . basename( $file ) . ' in the work directory.' );
		}
	}

	private function randomSuffix( int $length ): string {
		$chars = 'abcdefghijklmnopqrstuvwxyz0123456789';
		$max   = strlen( $chars ) - 1;
		$out   = '';

		for ( $i = 0; $i < $length; $i++ ) {
			$out .= $chars[ random_int( 0, $max ) ];
		}

		return $out;
	}
}
