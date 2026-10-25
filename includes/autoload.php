<?php
/**
 * PSR-4 style autoloader for the WpPcloudBackup\ namespace.
 */

if ( ! defined( 'WP_PCLOUD_BACKUP_SLUG' ) ) {
	define( 'WP_PCLOUD_BACKUP_SLUG', 'wp-pcloud-backup' );
}

spl_autoload_register(
	static function ( string $class ): void {
		$prefix = 'WpPcloudBackup\\';

		if ( strncmp( $class, $prefix, strlen( $prefix ) ) !== 0 ) {
			return;
		}

		$relative = substr( $class, strlen( $prefix ) );
		$file     = __DIR__ . '/' . str_replace( '\\', '/', $relative ) . '.php';

		if ( is_file( $file ) ) {
			require $file;
		}
	}
);
