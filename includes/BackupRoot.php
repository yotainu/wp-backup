<?php

namespace WpPcloudBackup;

/**
 * Detects and validates the folder a backup starts from.
 *
 * Usually that is ABSPATH. When WordPress lives in a subdirectory of the site
 * (site_url deeper than home_url), the site's real document root is higher up.
 * All path handling is textual so it also works for paths that do not exist.
 */
final class BackupRoot {

	/**
	 * Absolute path (no trailing slash) of the folder the site is served from.
	 *
	 * Climbs one folder per extra URL segment of site_url, but only when ABSPATH ends in
	 * exactly those segments (e.g. /var/www/html/wp for site_url .../wp). If the URL does
	 * not mirror the filesystem (rewrites, aliases), ABSPATH is returned unchanged.
	 */
	public static function detect( string $abspath, string $siteUrl, string $homeUrl ): string {
		$base = self::normalize( $abspath );
		$site = self::urlSegments( $siteUrl );
		$home = self::urlSegments( $homeUrl );

		if ( count( $home ) >= count( $site ) || array_slice( $site, 0, count( $home ) ) !== $home ) {
			return $base;
		}

		$extra    = array_slice( $site, count( $home ) );
		$segments = array_values( array_filter( explode( '/', $base ), 'strlen' ) );
		if ( count( $segments ) < count( $extra ) || array_slice( $segments, -count( $extra ) ) !== $extra ) {
			return $base;
		}

		return '/' . implode( '/', array_slice( $segments, 0, count( $segments ) - count( $extra ) ) );
	}

	/**
	 * Whether detected <= candidate <= abspath, i.e. the candidate is the detected
	 * folder or below it, and is ABSPATH or one of its ancestors.
	 */
	public static function validate( string $candidate, string $detected, string $abspath ): bool {
		$candidate = self::normalize( $candidate );

		return self::isSameOrAncestor( self::normalize( $detected ), $candidate )
			&& self::isSameOrAncestor( $candidate, self::normalize( $abspath ) );
	}

	/**
	 * Collapse duplicate slashes, resolve "." and ".." and drop the trailing slash.
	 */
	public static function normalize( string $path ): string {
		$parts = array();

		foreach ( explode( '/', $path ) as $segment ) {
			if ( '' === $segment || '.' === $segment ) {
				continue;
			}
			if ( '..' === $segment ) {
				array_pop( $parts );
				continue;
			}
			$parts[] = $segment;
		}

		return '/' . implode( '/', $parts );
	}

	/**
	 * @return string[]
	 */
	private static function urlSegments( string $url ): array {
		$path = parse_url( $url, PHP_URL_PATH );

		return array_values( array_map( 'rawurldecode', array_filter( explode( '/', trim( is_string( $path ) ? $path : '', '/' ) ), 'strlen' ) ) );
	}

	/**
	 * Segment-aware: "/a/b" is an ancestor of "/a/b/c" but not of "/a/bc".
	 */
	private static function isSameOrAncestor( string $ancestor, string $path ): bool {
		return $ancestor === $path || 0 === strpos( $path, rtrim( $ancestor, '/' ) . '/' );
	}
}
