<?php
/**
 * Plugin Name:       WP pCloud Backup
 * Description:       Back up your WordPress site and upload it to pCloud.
 * Version:           0.1.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Text Domain:       wp-pcloud-backup
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 */

defined( 'ABSPATH' ) || exit;

define( 'WP_PCLOUD_BACKUP_VERSION', '0.1.0' );
define( 'WP_PCLOUD_BACKUP_FILE', __FILE__ );
define( 'WP_PCLOUD_BACKUP_DIR', __DIR__ );

if ( ! defined( 'WP_PCLOUD_BACKUP_UPDATE_URL' ) ) {
	// Update manifest published from the yotainu/wp-pcloud-backup GitHub repository.
	define( 'WP_PCLOUD_BACKUP_UPDATE_URL', 'https://raw.githubusercontent.com/yotainu/wp-pcloud-backup/main/update.json' );
}

require_once __DIR__ . '/includes/autoload.php';

add_action( 'plugins_loaded', array( \WpPcloudBackup\Plugin::class, 'boot' ) );

register_activation_hook( __FILE__, array( \WpPcloudBackup\Plugin::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( \WpPcloudBackup\Plugin::class, 'deactivate' ) );
