<?php

namespace WpPcloudBackup;

/**
 * Builds the manifest.json stored next to every backup generation.
 */
final class Manifest {

	public const FORMAT = 1;

	public const DATABASE_FILE = 'database.sql.gz';

	/**
	 * Build the manifest JSON.
	 *
	 * Input shape ($job); every key is optional and falls back to an empty value:
	 *
	 *   plugin_version string
	 *   created_at     int|string  Epoch seconds, or an already formatted ISO 8601 string.
	 *   timezone       \DateTimeZone|null  Site timezone used to format an epoch created_at (UTC when null).
	 *   site_url       string
	 *   home_url       string
	 *   wp_version     string
	 *   php_version    string
	 *   backup_root    string
	 *   abspath        string
	 *   files_count    int
	 *   files_bytes    int
	 *   parts          array<int, array{name:string,size:int,sha1:string,sha256:string}>
	 *   database       array{tables:string[],rows:int,sha1:string,sha256:string}  ("file" is always database.sql.gz)
	 *   warnings       string[]
	 *   excludes       string[]
	 *
	 * @param array $job Job summary, see above.
	 */
	public static function build( array $job ): string {
		$database = isset( $job['database'] ) && is_array( $job['database'] ) ? $job['database'] : array();

		$manifest = array(
			'format'         => self::FORMAT,
			'plugin_version' => (string) ( $job['plugin_version'] ?? '' ),
			'created_at'     => self::createdAt( $job['created_at'] ?? 0, $job['timezone'] ?? null ),
			'site_url'       => (string) ( $job['site_url'] ?? '' ),
			'home_url'       => (string) ( $job['home_url'] ?? '' ),
			'wp_version'     => (string) ( $job['wp_version'] ?? '' ),
			'php_version'    => (string) ( $job['php_version'] ?? '' ),
			'backup_root'    => (string) ( $job['backup_root'] ?? '' ),
			'abspath'        => (string) ( $job['abspath'] ?? '' ),
			'files_count'    => (int) ( $job['files_count'] ?? 0 ),
			'files_bytes'    => (int) ( $job['files_bytes'] ?? 0 ),
			'parts'          => self::parts( $job['parts'] ?? array() ),
			'database'       => array(
				'file'   => self::DATABASE_FILE,
				'tables' => array_values( array_map( 'strval', (array) ( $database['tables'] ?? array() ) ) ),
				'rows'   => (int) ( $database['rows'] ?? 0 ),
				'sha1'   => (string) ( $database['sha1'] ?? '' ),
				'sha256' => (string) ( $database['sha256'] ?? '' ),
			),
			'warnings'       => array_values( array_map( 'strval', (array) ( $job['warnings'] ?? array() ) ) ),
			'excludes'       => array_values( array_map( 'strval', (array) ( $job['excludes'] ?? array() ) ) ),
		);

		return (string) json_encode( $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
	}

	/**
	 * @param mixed $value    Epoch seconds or a preformatted string.
	 * @param mixed $timezone \DateTimeZone or null.
	 */
	private static function createdAt( $value, $timezone ): string {
		if ( is_string( $value ) && ! ctype_digit( $value ) ) {
			return $value;
		}

		$zone = $timezone instanceof \DateTimeZone ? $timezone : new \DateTimeZone( 'UTC' );

		return ( new \DateTimeImmutable( '@' . (int) $value ) )->setTimezone( $zone )->format( DATE_ATOM );
	}

	/**
	 * @param mixed $parts
	 */
	private static function parts( $parts ): array {
		$list = array();
		foreach ( (array) $parts as $part ) {
			$part   = (array) $part;
			$list[] = array(
				'name'   => (string) ( $part['name'] ?? '' ),
				'size'   => (int) ( $part['size'] ?? 0 ),
				'sha1'   => (string) ( $part['sha1'] ?? '' ),
				'sha256' => (string) ( $part['sha256'] ?? '' ),
			);
		}

		return $list;
	}
}
