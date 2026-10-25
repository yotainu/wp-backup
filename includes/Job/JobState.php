<?php

namespace WpPcloudBackup\Job;

/**
 * Job state persisted as JSON, with an exclusive run lock.
 */
class JobState {

	private const JSON_FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT;

	/**
	 * State file path.
	 *
	 * @var string
	 */
	private $file;

	/**
	 * State values.
	 *
	 * @var array
	 */
	private $data;

	/**
	 * Open lock file handle, if the lock is held.
	 *
	 * @var resource|null
	 */
	private $lockHandle = null;

	private function __construct( string $file, array $data ) {
		$this->file = $file;
		$this->data = $data;
	}

	/**
	 * Create a new state and save it immediately.
	 */
	public static function create( string $file, array $data ): JobState {
		$state = new self( $file, $data );
		$state->save();

		return $state;
	}

	/**
	 * Load a state; null when the file is missing or not a JSON object/array.
	 */
	public static function load( string $file ): ?JobState {
		if ( ! is_file( $file ) ) {
			return null;
		}

		$json = file_get_contents( $file );
		if ( false === $json ) {
			return null;
		}

		$data = json_decode( $json, true );
		if ( ! is_array( $data ) ) {
			return null;
		}

		return new self( $file, $data );
	}

	/**
	 * @param mixed $default Returned when the key is not set.
	 * @return mixed
	 */
	public function get( string $key, $default = null ) {
		return array_key_exists( $key, $this->data ) ? $this->data[ $key ] : $default;
	}

	/**
	 * @param mixed $value Value to store.
	 */
	public function set( string $key, $value ): void {
		$this->data[ $key ] = $value;
	}

	public function merge( array $values ): void {
		$this->data = array_merge( $this->data, $values );
	}

	/**
	 * Write the state atomically (temp file, then rename).
	 *
	 * @throws \RuntimeException When the state cannot be written.
	 */
	public function save(): void {
		$tmp  = $this->file . '.tmp';
		$json = json_encode( $this->data, self::JSON_FLAGS );

		if ( false === $json ) {
			// No JSON_INVALID_UTF8_SUBSTITUTE: a silently altered cursor is worse than a failed save.
			throw new \RuntimeException( 'Could not encode job state: ' . json_last_error_msg() );
		}

		$written = @file_put_contents( $tmp, $json, LOCK_EX ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( strlen( $json ) !== $written ) {
			// Do not leave a partial temp file behind (it holds job details and wastes space).
			@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			throw new \RuntimeException( 'Could not write job state: ' . $tmp );
		}

		if ( ! @rename( $tmp, $this->file ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			throw new \RuntimeException( 'Could not replace job state: ' . $this->file );
		}
	}

	public function delete(): void {
		if ( is_file( $this->file ) ) {
			@unlink( $this->file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
	}

	public function toArray(): array {
		return $this->data;
	}

	/**
	 * Try to take the exclusive run lock without blocking.
	 */
	public function acquireLock( string $lockFile ): bool {
		if ( null !== $this->lockHandle ) {
			return true;
		}

		$handle = @fopen( $lockFile, 'c' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( false === $handle ) {
			return false;
		}

		if ( ! flock( $handle, LOCK_EX | LOCK_NB ) ) {
			fclose( $handle );
			return false;
		}

		$this->lockHandle = $handle;

		return true;
	}

	public function releaseLock(): void {
		if ( null === $this->lockHandle ) {
			return;
		}

		flock( $this->lockHandle, LOCK_UN );
		fclose( $this->lockHandle );
		$this->lockHandle = null;
	}
}
