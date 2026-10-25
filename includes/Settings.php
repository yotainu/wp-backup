<?php

namespace WpPcloudBackup;

/**
 * Plugin settings: defaults, wp-config constant overrides and sanitization.
 *
 * All values live in one option array. A value defined as a constant in
 * wp-config.php wins over the stored one and cannot be changed from the UI.
 */
class Settings {

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
		'next_run',
		'connection_email',
		'activated_at',
		'last_url_hit',
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
	private const TIMESTAMP_KEYS = array( 'last_scheduled', 'last_success', 'last_stale_notice', 'next_run', 'activated_at', 'last_url_hit' );

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
			'backup_root'       => BackupRoot::detect( ABSPATH, (string) site_url(), (string) home_url() ),
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
			'next_run'          => 0,
			'connection_email'  => '',
			'activated_at'      => 0,
			'last_url_hit'      => 0,
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
		$changes  = array();

		foreach ( $input as $key => $value ) {
			if ( ! in_array( $key, self::KEYS, true ) || 'access_token_enc' === $key || $this->isLocked( $key ) ) {
				continue;
			}
			$changes[ $key ] = $this->sanitizeValue( $key, $value, $defaults[ $key ] );
		}

		$this->save( $changes );

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

		$blob = $this->stored()['access_token_enc'] ?? '';

		return is_string( $blob ) && '' !== $blob ? Crypto::decrypt( $blob ) : null;
	}

	/**
	 * Whether a stored token blob exists but cannot be decrypted (AUTH_KEY / SECURE_AUTH_KEY changed).
	 * False when the wp-config constant provides the token.
	 */
	public function tokenUndecryptable(): bool {
		if ( $this->isLocked( 'access_token' ) ) {
			return false;
		}

		$blob = $this->stored()['access_token_enc'] ?? '';

		return is_string( $blob ) && '' !== $blob && null === Crypto::decrypt( $blob );
	}

	public function setAccessToken( ?string $plain ): void {
		$this->save( array( 'access_token_enc' => null === $plain || '' === $plain ? '' : Crypto::encrypt( $plain ) ) );
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

	/**
	 * Write only the given keys on top of a fresh read of the option, so a cron
	 * process and an admin save in different requests do not overwrite each other.
	 *
	 * The cached copy of the option is dropped first, because get_option() would
	 * otherwise serve the alloptions cache loaded at request start (stale in a
	 * long-running cron request). A small read-then-write window remains.
	 */
	private function save( array $changes ): void {
		if ( function_exists( 'wp_cache_delete' ) ) {
			wp_cache_delete( self::OPTION, 'options' );
			wp_cache_delete( 'alloptions', 'options' );
		}

		$option       = get_option( self::OPTION, array() );
		$this->stored = array_merge( is_array( $option ) ? $option : array(), $changes );
		update_option( self::OPTION, $this->stored, true );
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
			case 'connection_email':
				return sanitize_email( (string) $value );
			case 'root_folder':
				return $this->sanitizeRootFolder( (string) $value, $default );
			case 'site_folder':
				return $this->sanitizeSiteFolder( (string) $value, $default );
			case 'backup_root':
				return $this->sanitizeBackupRoot( (string) $value, $default );
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

	private function sanitizeBackupRoot( string $value, string $default ): string {
		$path = BackupRoot::normalize( sanitize_text_field( $value ) );

		return BackupRoot::validate( $path, $default, ABSPATH ) ? $path : $default;
	}

	private function sanitizeRootFolder( string $value, string $default ): string {
		$path = trim( rtrim( sanitize_text_field( $value ), '/' ), " \t" );
		$path = '/' . ltrim( $path, '/' );
		$path = rtrim( $path, '/' );

		if ( '' === $path || array_intersect( explode( '/', $path ), array( '.', '..' ) ) ) {
			return $default;
		}

		return $path;
	}

	private function sanitizeSiteFolder( string $value, string $default ): string {
		$name = preg_replace( '/[^A-Za-z0-9._-]/', '_', trim( $value ) );

		return '' === $name || '.' === $name || '..' === $name ? $default : $name;
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
