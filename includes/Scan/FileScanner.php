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
	 * Files at least this large get a warning (default; see the constructor). A file is zipped
	 * within one run, so a very large one may not fit in the PHP time limit.
	 */
	public const LARGE_FILE_BYTES = 524288000;

	/**
	 * Size from which a listed file gets a "large file" warning.
	 *
	 * @var int
	 */
	private $largeFileBytes;

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
	 * @param int      $largeFileBytes Files of this size or more are listed with a warning (0 = never).
	 */
	public function __construct( string $root, array $excludes, string $listFile, array $state = array(), int $largeFileBytes = self::LARGE_FILE_BYTES ) {
		$this->root           = rtrim( $root, '/\\' );
		$this->listFile       = $listFile;
		$this->largeFileBytes = $largeFileBytes;

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
		$stat = $this->statFile( $path );
		if ( null === $stat ) {
			$this->warnings[] = 'unreadable file: ' . $relPath;
			return;
		}

		$size = $stat['size'];
		$line = json_encode(
			array(
				'p' => $relPath,
				's' => $size,
				'm' => $stat['mtime'],
			),
			JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
		);

		if ( false === $line ) {
			$this->warnings[] = 'unencodable path skipped: ' . $relPath;
			return;
		}

		$line .= "\n";
		if ( @fwrite( $handle, $line ) !== strlen( $line ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			// A lost line would silently drop the file from the backup.
			throw new \RuntimeException( 'Cannot write the file list: ' . basename( $this->listFile ) );
		}
		++$this->files;
		$this->bytes += $size;

		if ( $this->largeFileBytes > 0 && $size >= $this->largeFileBytes ) {
			$this->warnings[] = sprintf( 'large file (%d MB, may not fit in one run): %s', intdiv( $size, 1048576 ), $relPath );
		}
	}

	/**
	 * Read size and mtime of a file, tolerating it vanishing after is_file().
	 *
	 * @param string $path Absolute path.
	 * @return array|null ['size' => int, 'mtime' => int], or null if it cannot be stat'ed.
	 */
	protected function statFile( string $path ): ?array {
		$size  = @filesize( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		$mtime = @filemtime( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

		if ( false === $size || false === $mtime ) {
			return null;
		}

		return array(
			'size'  => (int) $size,
			'mtime' => (int) $mtime,
		);
	}

	/**
	 * Whether a relative path matches any exclude entry.
	 *
	 * An exclude containing a `/` is anchored at the root: it matches the path
	 * itself or anything below it; a trailing `*` makes it a prefix match.
	 * An exclude without `/` (e.g. `.git`, `node_modules`, `cache*`) matches any
	 * entry whose basename equals it (or starts with it when it ends in `*`),
	 * at any depth. Matching is case-sensitive.
	 */
	private function isExcluded( string $relPath ): bool {
		$slash    = strrpos( $relPath, '/' );
		$basename = false === $slash ? $relPath : substr( $relPath, $slash + 1 );

		foreach ( $this->excludes as $exclude ) {
			$wildcard = '*' === substr( $exclude, -1 );
			$prefix   = $wildcard ? substr( $exclude, 0, -1 ) : $exclude;

			if ( false === strpos( $exclude, '/' ) ) {
				if ( $basename === $exclude || ( $wildcard && 0 === strpos( $basename, $prefix ) ) ) {
					return true;
				}
				continue;
			}

			if ( $relPath === $exclude || 0 === strpos( $relPath, $exclude . '/' ) ) {
				return true;
			}

			if ( $wildcard && 0 === strpos( $relPath, $prefix ) ) {
				return true;
			}
		}

		return false;
	}
}
