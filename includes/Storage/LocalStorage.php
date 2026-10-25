<?php

namespace WpPcloudBackup\Storage;

/**
 * Directory-backed storage used for tests and local runs.
 *
 * Ids are integers persisted in baseDir/.ids.json; id 0 is baseDir itself.
 * Both folders and files are recorded in the same id => relative path map.
 */
final class LocalStorage implements StorageInterface {

	private const IDS_FILE = '.ids.json';

	/**
	 * @var string
	 */
	private $baseDir;

	/**
	 * @var int
	 */
	private $next = 1;

	/**
	 * Id => path relative to baseDir.
	 *
	 * @var array<int,string>
	 */
	private $paths = array();

	public function __construct( string $baseDir ) {
		$this->baseDir = rtrim( $baseDir, '/\\' );
		if ( ! is_dir( $this->baseDir ) ) {
			mkdir( $this->baseDir, 0777, true );
		}

		$file = $this->baseDir . '/' . self::IDS_FILE;
		if ( is_file( $file ) ) {
			$data = json_decode( (string) file_get_contents( $file ), true );
			if ( is_array( $data ) ) {
				$this->next = (int) ( $data['next'] ?? 1 );
				foreach ( $data['paths'] ?? array() as $id => $path ) {
					$this->paths[ (int) $id ] = (string) $path;
				}
			}
		}
	}

	public function ensureFolder( string $path ): int {
		$segments = array();
		foreach ( explode( '/', $path ) as $segment ) {
			if ( '' === $segment ) {
				continue;
			}
			if ( '..' === $segment || '.' === $segment ) {
				throw new StorageException( 'Invalid folder path', 0, false, 'other' );
			}
			$segments[] = $segment;
		}

		$relative = implode( '/', $segments );
		if ( '' === $relative ) {
			return 0;
		}

		$absolute = $this->baseDir . '/' . $relative;
		if ( ! is_dir( $absolute ) && ! mkdir( $absolute, 0777, true ) ) {
			throw new StorageException( 'Cannot create folder', 0, false, 'other' );
		}

		$id = $this->idFor( $relative );
		$this->save();

		return $id;
	}

	public function listFolder( int $folderId ): array {
		$relative = $this->relativeFor( $folderId, true );
		$absolute = '' === $relative ? $this->baseDir : $this->baseDir . '/' . $relative;

		$items = array();
		foreach ( scandir( $absolute ) as $entry ) {
			if ( '.' === $entry || '..' === $entry || ( '' === $relative && self::IDS_FILE === $entry ) ) {
				continue;
			}
			$childRel = '' === $relative ? $entry : $relative . '/' . $entry;
			$childAbs = $absolute . '/' . $entry;
			$isFolder = is_dir( $childAbs );
			$items[]  = array(
				'id'       => $this->idFor( $childRel ),
				'name'     => $entry,
				'isfolder' => $isFolder,
				'size'     => $isFolder ? 0 : (int) filesize( $childAbs ),
				'modified' => (int) filemtime( $childAbs ),
			);
		}
		$this->save();

		return $items;
	}

	public function uploadFile( int $folderId, string $localPath, string $name ): array {
		$relative = $this->relativeFor( $folderId, true );
		if ( ! is_file( $localPath ) ) {
			throw new StorageException( 'File not found', 0, false, 'other' );
		}
		if ( '' === $name || false !== strpbrk( $name, '/\\' ) || '..' === $name ) {
			throw new StorageException( 'Invalid file name', 0, false, 'other' );
		}

		$childRel = '' === $relative ? $name : $relative . '/' . $name;
		$target   = $this->baseDir . '/' . $childRel;
		if ( ! copy( $localPath, $target ) ) {
			throw new StorageException( 'Cannot copy file', 0, false, 'other' );
		}

		$id = $this->idFor( $childRel );
		$this->save();

		return array(
			'id'   => $id,
			'size' => (int) filesize( $target ),
			'sha1' => (string) sha1_file( $target ),
		);
	}

	public function renameFolder( int $folderId, string $newName ): void {
		$relative = $this->relativeFor( $folderId, true );
		if ( '' === $relative ) {
			throw new StorageException( 'Cannot rename root folder', 0, false, 'other' );
		}
		if ( '' === $newName || false !== strpbrk( $newName, '/\\' ) || '.' === $newName || '..' === $newName ) {
			throw new StorageException( 'Invalid folder name', 0, false, 'other' );
		}

		$slash  = strrpos( $relative, '/' );
		$parent = false === $slash ? '' : substr( $relative, 0, $slash );
		$newRel = '' === $parent ? $newName : $parent . '/' . $newName;
		$newAbs = $this->baseDir . '/' . $newRel;
		if ( file_exists( $newAbs ) || ! rename( $this->baseDir . '/' . $relative, $newAbs ) ) {
			throw new StorageException( 'Cannot rename folder', 0, false, 'other' );
		}

		$prefix = $relative . '/';
		foreach ( $this->paths as $id => $path ) {
			if ( $path === $relative ) {
				$this->paths[ $id ] = $newRel;
			} elseif ( 0 === strpos( $path, $prefix ) ) {
				$this->paths[ $id ] = $newRel . '/' . substr( $path, strlen( $prefix ) );
			}
		}
		$this->save();
	}

	public function deleteFolder( int $folderId ): void {
		$relative = $this->relativeFor( $folderId, true );
		if ( '' === $relative ) {
			throw new StorageException( 'Cannot delete root folder', 0, false, 'other' );
		}

		$this->removeTree( $this->baseDir . '/' . $relative );

		$prefix = $relative . '/';
		foreach ( $this->paths as $id => $path ) {
			if ( $path === $relative || 0 === strpos( $path, $prefix ) ) {
				unset( $this->paths[ $id ] );
			}
		}
		$this->save();
	}

	public function deleteFile( int $fileId ): void {
		$relative = $this->relativeFor( $fileId, false );
		if ( ! unlink( $this->baseDir . '/' . $relative ) ) {
			throw new StorageException( 'Cannot delete file', 0, false, 'other' );
		}
		unset( $this->paths[ $fileId ] );
		$this->save();
	}

	public function checksum( int $fileId ): array {
		$absolute = $this->baseDir . '/' . $this->relativeFor( $fileId, false );

		return array(
			'sha1' => (string) sha1_file( $absolute ),
			'md5'  => (string) md5_file( $absolute ),
			'size' => (int) filesize( $absolute ),
		);
	}

	/**
	 * Get or assign the id of a relative path.
	 */
	private function idFor( string $relative ): int {
		$id = array_search( $relative, $this->paths, true );
		if ( false !== $id ) {
			return (int) $id;
		}

		$id                 = $this->next++;
		$this->paths[ $id ] = $relative;

		return $id;
	}

	/**
	 * Resolve an id to a relative path; id 0 is the root ('') for folders.
	 *
	 * @param bool $folder Expect a folder (true) or a file (false).
	 */
	private function relativeFor( int $id, bool $folder ): string {
		if ( 0 === $id && $folder ) {
			return '';
		}

		$relative = $this->paths[ $id ] ?? null;
		if ( null === $relative ) {
			throw new StorageException( 'Unknown id ' . $id, 0, false, 'other' );
		}

		$absolute = $this->baseDir . '/' . $relative;
		if ( $folder ? ! is_dir( $absolute ) : ! is_file( $absolute ) ) {
			throw new StorageException( 'Unknown id ' . $id, 0, false, 'other' );
		}

		return $relative;
	}

	private function removeTree( string $dir ): void {
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

	private function save(): void {
		file_put_contents(
			$this->baseDir . '/' . self::IDS_FILE,
			json_encode(
				array(
					'next'  => $this->next,
					'paths' => (object) $this->paths,
				)
			),
			LOCK_EX
		);
	}
}
