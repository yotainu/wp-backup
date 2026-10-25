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

require_once __DIR__ . '/includes/autoload.php';

add_action( 'plugins_loaded', array( \WpPcloudBackup\Plugin::class, 'boot' ) );
