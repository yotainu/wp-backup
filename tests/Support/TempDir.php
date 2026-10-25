<?php

namespace WpPcloudBackup\Tests\Support;

/**
 * Temporary directory helper for tests.
 */
final class TempDir {

	/**
	 * Create a unique temporary directory and return its path.
	 */
	public static function create(): string {
		$dir = sys_get_temp_dir() . '/wppb-' . bin2hex( random_bytes( 8 ) );
		mkdir( $dir, 0777, true );

		return $dir;
	}

	/**
	 * Recursively remove a directory.
	 */
	public static function remove( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			return;
		}

		$items = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::CHILD_FIRST
		);

		foreach ( $items as $item ) {
			if ( $item->isDir() && ! $item->isLink() ) {
				rmdir( $item->getPathname() );
			} else {
				unlink( $item->getPathname() );
			}
		}

		rmdir( $dir );
	}
}
