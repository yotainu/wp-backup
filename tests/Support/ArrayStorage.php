<?php

namespace WpPcloudBackup\Tests\Support;

use WpPcloudBackup\Storage\StorageInterface;

/**
 * In-memory listing stub: returns the given rows verbatim and records deleted folder ids.
 */
final class ArrayStorage implements StorageInterface {

	/**
	 * @var array
	 */
	private $rows;

	/**
	 * @var int[]
	 */
	public $deletedFolders = array();

	public function __construct( array $rows ) {
		$this->rows = $rows;
	}

	public function ensureFolder( string $path ): int {
		return 0;
	}

	public function listFolder( int $folderId ): array {
		return $this->rows;
	}

	public function uploadFile( int $folderId, string $localPath, string $name ): array {
		throw new \LogicException( 'not supported' );
	}

	public function renameFolder( int $folderId, string $newName ): void {
		throw new \LogicException( 'not supported' );
	}

	public function deleteFolder( int $folderId ): void {
		$this->deletedFolders[] = $folderId;
	}

	public function deleteFile( int $fileId ): void {
		throw new \LogicException( 'not supported' );
	}

	public function checksum( int $fileId ): array {
		throw new \LogicException( 'not supported' );
	}
}
