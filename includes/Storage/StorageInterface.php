<?php

namespace WpPcloudBackup\Storage;

/**
 * Remote (or local) backup storage.
 */
interface StorageInterface {

	/**
	 * Create the whole folder path, including intermediate levels.
	 *
	 * @return int Folder id.
	 */
	public function ensureFolder( string $path ): int;

	/**
	 * List the contents of a folder.
	 *
	 * @return array<int, array{id:int,name:string,isfolder:bool,size:int,modified:int}>
	 */
	public function listFolder( int $folderId ): array;

	/**
	 * Upload a local file into a folder and return its id, size and SHA-1.
	 *
	 * @return array{id:int,size:int,sha1:string}
	 */
	public function uploadFile( int $folderId, string $localPath, string $name ): array;

	public function renameFolder( int $folderId, string $newName ): void;

	public function deleteFolder( int $folderId ): void;

	public function deleteFile( int $fileId ): void;

	/**
	 * @return array{sha1:string,md5:string,size:int}
	 */
	public function checksum( int $fileId ): array;
}
