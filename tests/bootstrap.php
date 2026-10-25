<?php
/**
 * PHPUnit bootstrap.
 *
 * wp-pcloud-backup.php is intentionally not loaded here because it calls
 * WordPress functions. Only the autoloader is loaded.
 */

require_once dirname( __DIR__ ) . '/vendor/autoload.php';
require_once dirname( __DIR__ ) . '/includes/autoload.php';

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', sys_get_temp_dir() . '/wp-abspath/' );
}
