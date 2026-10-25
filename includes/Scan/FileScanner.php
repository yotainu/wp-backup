<?php

namespace WpPcloudBackup\Scan;

/**
 * Resumable directory scanner that appends a JSONL file list.
 *
 * Pure PHP: no WordPress functions are used. Each line of the list file is
 * {"p":relativePath,"s":size,"m":mtime}. Directories themselves are not listed.
 */
class FileScanner {

	/**
	 * Absolute root directory, without trailing slash.
	 *
	 * @var string
	 */
	private $root;

	/**
	 * Normalized exclude entries (non-empty).
	 *
	 * @var string[]
	 */
	private $excludes = array();

	/**
	 * Absolute path of the JSONL list file.
	 *
	 * @var string
	 */
	private $listFile;

	/**
	 * Relative directories still to scan (used as a stack).
	 *
	 * @var string[]
	 */
	private $pending;

	/**
	 * Number of files listed so far.
	 *
	 * @var int
	 */
	private $files;

	/**
	 * Total size in bytes of the files listed so far.
	 *
	 * @var int
	 */
	private $bytes;

	/**
	 * Warning messages collected so far.
	 *
	 * @var string[]
	 */
	private $warnings;

	/**
	 * @param string   $root     Absolute root directory to scan.
	 * @param string[] $excludes Relative paths to exclude; a trailing `*` is a prefix wildcard.
	 * @param string   $listFile Absolute path of the JSONL file to append to.
	 * @param array    $state    State previously returned by state(), to resume.
	 */
	public function __construct( string $root, array $excludes, string $listFile, array $state = array() ) {
		$this->root     = rtrim( $root, '/\\' );
		$this->listFile = $listFile;

		foreach ( $excludes as $exclude ) {
			$exclude = trim( str_replace( '\\', '/', trim( (string) $exclude ) ), '/' );
			if ( '' !== $exclude ) {
				$this->excludes[] = $exclude;
			}
		}

		$this->pending  = isset( $state['pending'] ) ? array_values( $state['pending'] ) : array( '' );
		$this->files    = (int) ( $state['files'] ?? 0 );
		$this->bytes    = (int) ( $state['bytes'] ?? 0 );
		$this->warnings = isset( $state['warnings'] ) ? array_values( $state['warnings'] ) : array();
	}

	/**
	 * Scan directories until finished or until $timeUp returns true.
	 *
	 * @param callable $timeUp Returns true when the time budget is exhausted.
	 * @return bool True when the whole tree has been scanned.
	 */
	public function run( callable $timeUp ): bool {
		$handle = fopen( $this->listFile, 'ab' );
		if ( false === $handle ) {
			throw new \RuntimeException( 'Cannot open file list for writing: ' . $this->listFile );
		}

		try {
			while ( ! empty( $this->pending ) ) {
				$this->scanDirectory( array_pop( $this->pending ), $handle );

				if ( ! empty( $this->pending ) && $timeUp() ) {
					return false;
				}
			}
		} finally {
			fclose( $handle );
		}

		return true;
	}

	/**
	 * Current scan state; plain arrays so it can be JSON-serialized.
	 */
	public function state(): array {
		return array(
			'pending'  => $this->pending,
			'files'    => $this->files,
			'bytes'    => $this->bytes,
			'warnings' => $this->warnings,
		);
	}

	/**
	 * Scan one directory: append its files, queue its subdirectories.
	 *
	 * @param string   $dir    Relative directory ('' for the root).
	 * @param resource $handle Open list file handle.
	 */
	private function scanDirectory( string $dir, $handle ): void {
		$absolute = '' === $dir ? $this->root : $this->root . '/' . $dir;
		$names    = is_dir( $absolute ) ? @scandir( $absolute ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

		if ( false === $names ) {
			$this->warnings[] = 'unreadable directory: ' . $dir;
			return;
		}

		sort( $names );

		foreach ( $names as $name ) {
			if ( '.' === $name || '..' === $name ) {
				continue;
			}

			$relPath = '' === $dir ? $name : $dir . '/' . $name;

			if ( $this->isExcluded( $relPath ) ) {
				continue;
			}

			$path = $this->root . '/' . $relPath;

			if ( is_link( $path ) ) {
				$this->warnings[] = 'symlink skipped: ' . $relPath;
			} elseif ( is_dir( $path ) ) {
				$this->pending[] = $relPath;
			} elseif ( is_file( $path ) ) {
				$this->appendFile( $handle, $relPath, $path );
			}
		}
	}

	/**
	 * Append one JSONL line for a file and update counters.
	 *
	 * @param resource $handle  Open list file handle.
	 * @param string   $relPath Relative path.
	 * @param string   $path    Absolute path.
	 */
	private function appendFile( $handle, string $relPath, string $path ): void {
		$size = (int) filesize( $path );
		$line = json_encode(
			array(
				'p' => $relPath,
				's' => $size,
				'm' => (int) filemtime( $path ),
			),
			JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
		);

		if ( false === $line ) {
			$this->warnings[] = 'unencodable path skipped: ' . $relPath;
			return;
		}

		fwrite( $handle, $line . "\n" );
		++$this->files;
		$this->bytes += $size;
	}

	/**
	 * Whether a relative path matches any exclude entry.
	 */
	private function isExcluded( string $relPath ): bool {
		foreach ( $this->excludes as $exclude ) {
			if ( $relPath === $exclude || 0 === strpos( $relPath, $exclude . '/' ) ) {
				return true;
			}

			if ( '*' === substr( $exclude, -1 ) && 0 === strpos( $relPath, substr( $exclude, 0, -1 ) ) ) {
				return true;
			}
		}

		return false;
	}
}
