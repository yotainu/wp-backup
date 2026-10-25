<?php

namespace WpPcloudBackup\Storage;

/**
 * Storage failure carrying a category and a retry hint.
 */
final class StorageException extends \RuntimeException {

	/**
	 * Whether retrying the operation may succeed.
	 *
	 * @var bool
	 */
	private $retryable;

	/**
	 * One of 'network', 'auth', 'quota', 'server', 'other'.
	 *
	 * @var string
	 */
	private $category;

	public function __construct( string $message, int $code, bool $retryable, string $category ) {
		parent::__construct( $message, $code );
		$this->retryable = $retryable;
		$this->category  = $category;
	}

	/**
	 * Build a retryable connection/timeout failure.
	 */
	public static function network( string $message ): self {
		return new self( $message, 0, true, 'network' );
	}

	public function isRetryable(): bool {
		return $this->retryable;
	}

	public function category(): string {
		return $this->category;
	}
}
