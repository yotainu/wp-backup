<?php

namespace WpPcloudBackup;

/**
 * Plugin bootstrapper. Hooks are registered here as features are added.
 */
final class Plugin {

	public static function boot(): void {
		load_plugin_textdomain(
			'wp-pcloud-backup',
			false,
			dirname( plugin_basename( WP_PCLOUD_BACKUP_FILE ) ) . '/languages'
		);
	}
}
