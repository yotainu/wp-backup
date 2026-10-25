<?php

namespace WpPcloudBackup\Admin;

use WpPcloudBackup\Crypto;
use WpPcloudBackup\WorkDir;

/**
 * Server and environment checks shown on the connection tab.
 */
final class Diagnostics {

	private const MIN_FREE_BYTES = 536870912;

	/**
	 * @var WorkDir
	 */
	private $workDir;

	/**
	 * @var callable
	 */
	private $hasExtension;

	/**
	 * @var bool
	 */
	private $cronDisabled;

	/**
	 * @param WorkDir  $workDir      Work directory.
	 * @param callable $hasExtension Receives an extension name, returns bool.
	 * @param bool     $cronDisabled Whether DISABLE_WP_CRON is on.
	 */
	public function __construct( WorkDir $workDir, callable $hasExtension, bool $cronDisabled ) {
		$this->workDir      = $workDir;
		$this->hasExtension = $hasExtension;
		$this->cronDisabled = $cronDisabled;
	}

	/**
	 * Each entry: {value:mixed, ok:bool|null}; null means informational only.
	 *
	 * @return array<string,array{value:mixed,ok:?bool}>
	 */
	public function all(): array {
		$dir  = null;
		$free = null;
		try {
			$dir  = $this->workDir->ensure();
			$free = $this->workDir->freeBytes();
		} catch ( \Throwable $e ) {
			unset( $e );
		}

		$writable   = null !== $dir && is_dir( $dir ) && is_writable( $dir );
		$htaccess   = null !== $dir && is_file( $dir . '/.htaccess' );
		$incomplete = null !== $dir && is_file( $dir . '/state.json' );
		$apache     = $this->workDir->isWebServerApache();
		$zlib       = (bool) ( $this->hasExtension )( 'zlib' );
		$curl       = (bool) ( $this->hasExtension )( 'curl' );
		$openssl    = (bool) ( $this->hasExtension )( 'openssl' ) && Crypto::isAvailable();

		return array(
			'php_version'        => $this->entry( PHP_VERSION, version_compare( PHP_VERSION, '7.4', '>=' ) ),
			'max_execution_time' => $this->entry( (string) ini_get( 'max_execution_time' ), null ),
			'memory_limit'       => $this->entry( (string) ini_get( 'memory_limit' ), null ),
			'ext_zlib'           => $this->entry( $zlib, $zlib ),
			'ext_curl'           => $this->entry( $curl, $curl ),
			'ext_openssl'        => $this->entry( $openssl, $openssl ),
			'workdir_writable'   => $this->entry( $writable, $writable ),
			'workdir_htaccess'   => $this->entry( $htaccess, $htaccess ),
			'server_apache'      => $this->entry( $apache, $apache ),
			'disable_wp_cron'    => $this->entry( $this->cronDisabled, ! $this->cronDisabled ),
			'free_bytes'         => $this->entry( $free, null === $free ? null : $free >= self::MIN_FREE_BYTES ),
			'incomplete_job'     => $this->entry( $incomplete, null ),
		);
	}

	/**
	 * @param mixed $value Raw value.
	 * @return array{value:mixed,ok:?bool}
	 */
	private function entry( $value, ?bool $ok ): array {
		return array(
			'value' => $value,
			'ok'    => $ok,
		);
	}
}
