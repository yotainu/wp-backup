<?php

namespace WpPcloudBackup;

/**
 * Plugin settings: defaults, wp-config constant overrides and sanitization.
 *
 * All values live in one option array. A value defined as a constant in
 * wp-config.php wins over the stored one and cannot be changed from the UI.
 */
final class Settings {

	public const OPTION = 'wp_pcloud_backup_settings';

	public const KEYS = array(
		'access_token_enc',
		'root_folder',
		'site_folder',
		'backup_root',
		'schedule',
		'start_time',
		'keep',
		'notify_email',
		'notify_on_success',
		'excludes',
		'part_size_mb',
		'run_budget_sec',
		'use_wp_cron',
		'auto_update',
		'cron_key',
		'workdir_name',
		'last_scheduled',
		'last_success',
		'last_stale_notice',
	);

	public const SCHEDULES = array( 'daily', 'every3days', 'weekly', 'biweekly', 'monthly' );

	public const PART_SIZES_MB = array( 10, 25, 50, 100 );

	/**
	 * Setting key => wp-config constant name.
	 */
	private const CONSTANTS = array(
		'access_token' => 'WP_PCLOUD_BACKUP_ACCESS_TOKEN',
		'notify_email' => 'WP_PCLOUD_BACKUP_NOTIFY_EMAIL',
		'root_folder'  => 'WP_PCLOUD_BACKUP_ROOT_FOLDER',
		'schedule'     => 'WP_PCLOUD_BACKUP_SCHEDULE',
		'keep'         => 'WP_PCLOUD_BACKUP_KEEP',
	);

	/**
	 * Keys that hold UNIX timestamps.
	 */
	private const TIMESTAMP_KEYS = array( 'last_scheduled', 'last_success', 'last_stale_notice' );

	/**
	 * Keys that hold booleans.
	 */
	private const BOOLEAN_KEYS = array( 'notify_on_success', 'use_wp_cron', 'auto_update' );

	/**
	 * Stored option value, read once per instance.
	 *
	 * @var array|null
	 */
	private $stored = null;

	public function defaults(): array {
		$host = parse_url( (string) home_url(), PHP_URL_HOST );

		return array(
			'access_token_enc'  => '',
			'root_folder'       => '/WPBackup',
			'site_folder'       => $this->sanitizeSiteFolder( is_string( $host ) ? $host : '', 'site' ),
			'backup_root'       => rtrim( ABSPATH, '/' ),
			'schedule'          => 'weekly',
			'start_time'        => '03:00',
			'keep'              => 3,
			'notify_email'      => (string) get_option( 'admin_email', '' ),
			'notify_on_success' => false,
			'excludes'          => array(
				'wp-content/uploads/wp-pcloud-backup-*',
				'wp-content/cache',
				'wp-content/uploads/backup*',
				'wp-content/updraft',
				'wp-content/ai1wm-backups',
				'.git',
				'node_modules',
			),
			'part_size_mb'      => 25,
			'run_budget_sec'    => 20,
			'use_wp_cron'       => true,
			'auto_update'       => true,
			'cron_key'          => '',
			'workdir_name'      => '',
			'last_scheduled'    => 0,
			'last_success'      => 0,
			'last_stale_notice' => 0,
		);
	}

	/**
	 * Constant, then stored option, then default.
	 *
	 * @return mixed
	 */
	public function get( string $key ) {
		return $this->all()[ $key ] ?? null;
	}

	/**
	 * Effective values for every key.
	 */
	public function all(): array {
		$values = array_merge( $this->defaults(), array_intersect_key( $this->stored(), array_flip( self::KEYS ) ) );

		foreach ( self::CONSTANTS as $key => $constant ) {
			if ( 'access_token' !== $key && $this->isLocked( $key ) ) {
				$values[ $key ] = $this->sanitizeValue( $key, constant( $constant ), $values[ $key ] );
			}
		}

		return $values;
	}

	/**
	 * Sanitize and save the given values. Locked keys and unknown keys are ignored.
	 * The access token is changed only through setAccessToken().
	 *
	 * @return array All effective values after saving.
	 */
	public function update( array $input ): array {
		$defaults = $this->defaults();
		$stored   = $this->stored();

		foreach ( $input as $key => $value ) {
			if ( ! in_array( $key, self::KEYS, true ) || 'access_token_enc' === $key || $this->isLocked( $key ) ) {
				continue;
			}
			$stored[ $key ] = $this->sanitizeValue( $key, $value, $defaults[ $key ] );
		}

		$this->save( $stored );

		return $this->all();
	}

	/**
	 * Whether a wp-config constant overrides this key.
	 */
	public function isLocked( string $key ): bool {
		return isset( self::CONSTANTS[ $key ] ) && defined( self::CONSTANTS[ $key ] );
	}

	public function accessToken(): ?string {
		if ( $this->isLocked( 'access_token' ) ) {
			return (string) constant( self::CONSTANTS['access_token'] );
		}

		// Task 3: decrypt access_token_enc.
		return null;
	}

	public function setAccessToken( ?string $plain ): void {
		$stored = $this->stored();
		// Task 3: encrypt.
		$stored['access_token_enc'] = null === $plain ? '' : $plain;
		$this->save( $stored );
	}

	public function partSizeBytes(): int {
		return (int) $this->get( 'part_size_mb' ) * 1048576;
	}

	private function stored(): array {
		if ( null === $this->stored ) {
			$option       = get_option( self::OPTION, array() );
			$this->stored = is_array( $option ) ? $option : array();
		}

		return $this->stored;
	}

	private function save( array $stored ): void {
		$this->stored = $stored;
		update_option( self::OPTION, $stored, true );
	}

	/**
	 * @param mixed $value
	 * @param mixed $default Fallback when the value is not acceptable.
	 * @return mixed
	 */
	private function sanitizeValue( string $key, $value, $default ) {
		if ( in_array( $key, self::BOOLEAN_KEYS, true ) ) {
			return filter_var( $value, FILTER_VALIDATE_BOOLEAN );
		}
		if ( in_array( $key, self::TIMESTAMP_KEYS, true ) ) {
			return max( 0, (int) $value );
		}

		switch ( $key ) {
			case 'schedule':
				return in_array( $value, self::SCHEDULES, true ) ? $value : $default;
			case 'start_time':
				return is_string( $value ) && preg_match( '/^([01]\d|2[0-3]):[0-5]\d$/', $value ) ? $value : $default;
			case 'keep':
				return min( 30, max( 1, (int) $value ) );
			case 'part_size_mb':
				return in_array( (int) $value, self::PART_SIZES_MB, true ) ? (int) $value : $default;
			case 'run_budget_sec':
				return min( 120, max( 5, (int) $value ) );
			case 'notify_email':
				return $this->sanitizeEmails( (string) $value );
			case 'root_folder':
				return $this->sanitizeRootFolder( (string) $value, $default );
			case 'site_folder':
				return $this->sanitizeSiteFolder( (string) $value, $default );
			case 'backup_root':
				// Task 4: validate with BackupRoot::validate().
				return rtrim( sanitize_text_field( (string) $value ), '/' );
			case 'excludes':
				return $this->sanitizeExcludes( $value );
			default:
				return sanitize_text_field( (string) $value );
		}
	}

	private function sanitizeEmails( string $value ): string {
		$valid = array();
		foreach ( explode( ',', $value ) as $email ) {
			$email = sanitize_email( trim( $email ) );
			if ( '' !== $email ) {
				$valid[] = $email;
			}
		}

		return implode( ',', $valid );
	}

	private function sanitizeRootFolder( string $value, string $default ): string {
		$path = trim( rtrim( sanitize_text_field( $value ), '/' ), " \t" );
		$path = '/' . ltrim( $path, '/' );
		$path = rtrim( $path, '/' );

		return '' === $path ? $default : $path;
	}

	private function sanitizeSiteFolder( string $value, string $default ): string {
		$name = preg_replace( '/[^A-Za-z0-9._-]/', '_', trim( $value ) );

		return '' === $name ? $default : $name;
	}

	/**
	 * @param mixed $value Array of patterns or a newline separated string.
	 */
	private function sanitizeExcludes( $value ): array {
		$lines = is_array( $value ) ? $value : preg_split( '/\r\n|\r|\n/', (string) $value );
		$clean = array();

		foreach ( $lines as $line ) {
			$line = ltrim( trim( (string) $line ), '/' );
			if ( '' !== $line ) {
				$clean[] = $line;
			}
		}

		return $clean;
	}
}
