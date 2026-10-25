<?php
/**
 * Removes all local data of WP pCloud Backup when the plugin is deleted.
 *
 * Only data on this site is touched. Backups stored in pCloud are never deleted.
 *
 * @package WpPcloudBackup
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/**
 * Recursively delete a directory. Symlinks are removed, never followed.
 *
 * @param string $dir Absolute directory path.
 */
function wp_pcloud_backup_uninstall_remove_dir( $dir ) {
	if ( is_link( $dir ) || ! is_dir( $dir ) ) {
		return;
	}

	$entries = scandir( $dir );
	if ( false === $entries ) {
		return;
	}

	foreach ( $entries as $entry ) {
		if ( '.' === $entry || '..' === $entry ) {
			continue;
		}

		$path = $dir . '/' . $entry;
		if ( is_dir( $path ) && ! is_link( $path ) ) {
			wp_pcloud_backup_uninstall_remove_dir( $path );
		} else {
			@unlink( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink
		}
	}

	@rmdir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.rmdir_rmdir
}

$wp_pcloud_backup_settings = get_option( 'wp_pcloud_backup_settings' );
$wp_pcloud_backup_workdir  = is_array( $wp_pcloud_backup_settings ) && isset( $wp_pcloud_backup_settings['workdir_name'] )
	? (string) $wp_pcloud_backup_settings['workdir_name']
	: '';

// Only ever delete a directory whose name is exactly what the plugin generates.
if ( 1 === preg_match( '/^wp-pcloud-backup-[a-z0-9]{16}$/', $wp_pcloud_backup_workdir ) ) {
	$wp_pcloud_backup_uploads = wp_upload_dir();
	if ( ! empty( $wp_pcloud_backup_uploads['basedir'] ) ) {
		wp_pcloud_backup_uninstall_remove_dir( rtrim( $wp_pcloud_backup_uploads['basedir'], '/\\' ) . '/' . $wp_pcloud_backup_workdir );
	}
}

delete_option( 'wp_pcloud_backup_settings' );
delete_option( 'wp_pcloud_backup_history' );

delete_site_transient( 'wp_pcloud_backup_update_manifest' );
delete_site_transient( 'wp_pcloud_backup_update_fail_notified' );
delete_transient( 'wp_pcloud_backup_notice' );

// One-time token reveal transients are keyed by user ID, so remove them by prefix.
global $wpdb;
$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
		$wpdb->esc_like( '_transient_wp_pcloud_backup_reveal_' ) . '%',
		$wpdb->esc_like( '_transient_timeout_wp_pcloud_backup_reveal_' ) . '%'
	)
);

wp_clear_scheduled_hook( 'wp_pcloud_backup_tick' );
