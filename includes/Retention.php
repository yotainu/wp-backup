<?php

namespace WpPcloudBackup;

use WpPcloudBackup\Storage\StorageInterface;

/**
 * Lists backup generations in a site folder and prunes old ones.
 */
final class Retention {

	private const COMPLETE_PATTERN   = '/^\d{4}-\d{2}-\d{2}_\d{6}$/';
	private const INCOMPLETE_PATTERN = '/^\.incomplete-\d{4}-\d{2}-\d{2}_\d{6}$/';

	/**
	 * @var StorageInterface
	 */
	private $storage;

	/**
	 * @var int
	 */
	private $siteFolderId;

	/**
	 * @var int
	 */
	private $incompleteTtl;

	public function __construct( StorageInterface $storage, int $siteFolderId, int $incompleteTtl = 172800 ) {
		$this->storage       = $storage;
		$this->siteFolderId  = $siteFolderId;
		$this->incompleteTtl = $incompleteTtl;
	}

	/**
	 * Completed generations, oldest first.
	 *
	 * @return array<int, array{id:int,name:string,modified:int}>
	 */
	public function generations(): array {
		return $this->folders( self::COMPLETE_PATTERN );
	}

	/**
	 * In-progress (or abandoned) generations, oldest first.
	 *
	 * @return array<int, array{id:int,name:string,modified:int}>
	 */
	public function incompleteFolders(): array {
		return $this->folders( self::INCOMPLETE_PATTERN );
	}

	/**
	 * Delete completed generations beyond $keep (oldest first) and stale incomplete folders.
	 *
	 * @param int $keep Number of completed generations to keep (at least 1).
	 * @param int $now  Current UNIX time.
	 * @return string[] Names of the deleted folders.
	 */
	public function prune( int $keep, int $now ): array {
		$keep    = max( 1, $keep );
		$deleted = array();

		$generations = $this->generations();
		foreach ( array_slice( $generations, 0, max( 0, count( $generations ) - $keep ) ) as $folder ) {
			$this->storage->deleteFolder( $folder['id'] );
			$deleted[] = $folder['name'];
		}

		foreach ( $this->incompleteFolders() as $folder ) {
			if ( $folder['modified'] < $now - $this->incompleteTtl ) {
				$this->storage->deleteFolder( $folder['id'] );
				$deleted[] = $folder['name'];
			}
		}

		return $deleted;
	}

	/**
	 * @return array<int, array{id:int,name:string,modified:int}>
	 */
	private function folders( string $pattern ): array {
		$folders = array();
		foreach ( $this->storage->listFolder( $this->siteFolderId ) as $item ) {
			if ( empty( $item['isfolder'] ) || ! preg_match( $pattern, (string) $item['name'] ) ) {
				continue;
			}
			$folders[] = array(
				'id'       => (int) $item['id'],
				'name'     => (string) $item['name'],
				'modified' => (int) $item['modified'],
			);
		}

		usort(
			$folders,
			static function ( array $a, array $b ): int {
				return strcmp( $a['name'], $b['name'] );
			}
		);

		return $folders;
	}
}
