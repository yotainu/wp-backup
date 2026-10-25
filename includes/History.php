<?php

namespace WpPcloudBackup;

/**
 * Recent backup runs, stored in a single non-autoloaded option (newest first).
 *
 * Entry: {started:int, finished:int, result:'success'|'warning'|'failed'|'updated',
 *         bytes:int, files:int, remote:string, message:string}
 */
final class History {

	public const OPTION = 'wp_pcloud_backup_history';

	public const LIMIT = 20;

	public function add( array $entry ): void {
		$entries = $this->all();
		array_unshift( $entries, $entry );

		update_option( self::OPTION, array_slice( $entries, 0, self::LIMIT ), false );
	}

	public function all(): array {
		$entries = get_option( self::OPTION, array() );

		return is_array( $entries ) ? array_values( $entries ) : array();
	}

	public function clear(): void {
		delete_option( self::OPTION );
	}
}
