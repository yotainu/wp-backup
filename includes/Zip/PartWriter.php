<?php

namespace WpPcloudBackup\Zip;

/**
 * Writes one logical byte stream as a series of fixed-size part files.
 *
 * Parts are named "{baseName}.{NNN}" (001, 002, ...) and are a plain byte
 * split, so restoring is `cat files.zip.* > files.zip`. A part is hashed
 * once, when it completes. Pure PHP: no WordPress functions are used.
 */
class PartWriter {

	/**
	 * Output directory, without trailing slash.
	 *
	 * @var string
	 */
	private $dir;

	/**
	 * Base file name of the parts, e.g. "files.zip".
	 *
	 * @var string
	 */
	private $baseName;

	/**
	 * Maximum size in bytes of one part.
	 *
	 * @var int
	 */
	private $partSize;

	/**
	 * Completed parts, ordered by index.
	 *
	 * @var array[]
	 */
	private $parts = array();

	/**
	 * Index of the part being written (or the next one to be created).
	 *
	 * @var int
	 */
	private $currentIndex = 1;

	/**
	 * Bytes written to the current part so far.
	 *
	 * @var int
	 */
	private $currentSize = 0;

	/**
	 * Open handle of the current part, or null when none is open.
	 *
	 * @var resource|null
	 */
	private $handle = null;

	/**
	 * Create a writer, optionally restoring a previously saved state.
	 *
	 * @param string $dir      Output directory.
	 * @param string $baseName Base file name of the parts.
	 * @param int    $partSize Maximum part size in bytes (must be positive).
	 * @param array  $state    A value returned by state(), to resume from.
	 *
	 * @throws \InvalidArgumentException If the part size is not positive.
	 * @throws \RuntimeException         If the saved current part is shorter than recorded or missing.
	 */
	public function __construct( string $dir, string $baseName, int $partSize, array $state = array() ) {
		if ( $partSize < 1 ) {
			throw new \InvalidArgumentException( 'Part size must be positive.' );
		}

		$this->dir      = rtrim( $dir, '/' );
		$this->baseName = $baseName;
		$this->partSize = $partSize;

		if ( array() === $state ) {
			return;
		}

		$this->parts        = array_values( $state['parts'] ?? array() );
		$this->currentIndex = (int) ( $state['current_index'] ?? 1 );
		$this->currentSize  = (int) ( $state['current_size'] ?? 0 );

		if ( $this->currentSize > 0 ) {
			$path = $this->pathFor( $this->currentIndex );
			clearstatcache( true, $path );
			$actual = is_file( $path ) ? filesize( $path ) : false;

			if ( false === $actual || $actual < $this->currentSize ) {
				throw new \RuntimeException( sprintf( 'Part file is missing or shorter than recorded: %s', $path ) );
			}

			if ( $actual > $this->currentSize && ! $this->truncateFile( $path, $this->currentSize ) ) {
				throw new \RuntimeException( sprintf( 'Could not trim part file: %s', $path ) );
			}

			$this->openCurrent();
		}
	}

	public function __destruct() {
		if ( null !== $this->handle ) {
			fclose( $this->handle );
			$this->handle = null;
		}
	}

	/**
	 * Append bytes to the stream, rolling over to the next part at the size limit.
	 *
	 * @param string $bytes Raw bytes.
	 *
	 * @throws \RuntimeException If a part cannot be opened or written.
	 */
	public function write( string $bytes ): void {
		$length   = strlen( $bytes );
		$position = 0;

		while ( $position < $length ) {
			if ( null === $this->handle ) {
				$this->openCurrent();
			}

			$chunk = substr( $bytes, $position, $this->partSize - $this->currentSize );
			$this->writeAll( $chunk );

			$position          += strlen( $chunk );
			$this->currentSize += strlen( $chunk );

			if ( $this->currentSize >= $this->partSize ) {
				$this->completeCurrent();
			}
		}
	}

	/**
	 * Total logical bytes written (completed parts plus the current part).
	 */
	public function offset(): int {
		$total = $this->currentSize;
		foreach ( $this->parts as $part ) {
			$total += $part['size'];
		}

		return $total;
	}

	/**
	 * Close and hash the current part (even if smaller than the part size). Idempotent.
	 */
	public function finish(): void {
		if ( null !== $this->handle ) {
			$this->completeCurrent();
		}
	}

	/**
	 * Roll the stream back to a logical offset.
	 *
	 * Parts after the offset are deleted and the part containing it is
	 * truncated and reopened for appending. An offset exactly at the end of a
	 * completed part keeps that part as it is.
	 *
	 * @param int $offset Logical offset, 0 <= offset <= offset().
	 *
	 * @throws \InvalidArgumentException If the offset is out of range.
	 * @throws \RuntimeException         If the truncation would touch an uploaded part, or a part file is missing.
	 */
	public function truncateTo( int $offset ): void {
		if ( $offset < 0 || $offset > $this->offset() ) {
			throw new \InvalidArgumentException( sprintf( 'Offset %d is out of range.', $offset ) );
		}

		if ( $offset === $this->offset() ) {
			return;
		}

		// Segments: completed parts plus the (unfinished) current part, with their start offsets.
		$segments = array();
		$start    = 0;
		foreach ( $this->parts as $part ) {
			$segments[] = array(
				'index'     => $part['index'],
				'start'     => $start,
				'size'      => $part['size'],
				'completed' => true,
				'uploaded'  => ! empty( $part['uploaded'] ),
			);
			$start     += $part['size'];
		}
		if ( $this->currentSize > 0 ) {
			$segments[] = array(
				'index'     => $this->currentIndex,
				'start'     => $start,
				'size'      => $this->currentSize,
				'completed' => false,
				'uploaded'  => false,
			);
		}

		// Find the segment to keep (possibly truncated): the last one starting before the offset.
		$keep = -1;
		foreach ( $segments as $position => $segment ) {
			if ( $segment['start'] < $offset ) {
				$keep = $position;
			}
		}

		$keepIntact = -1 !== $keep
			&& $segments[ $keep ]['completed']
			&& $offset === $segments[ $keep ]['start'] + $segments[ $keep ]['size'];

		// Validate before touching anything.
		foreach ( $segments as $position => $segment ) {
			$removed   = $position > $keep;
			$truncated = $position === $keep && ! $keepIntact;

			if ( ( $removed || $truncated ) && $segment['uploaded'] ) {
				throw new \RuntimeException( sprintf( 'Cannot truncate into uploaded part %d.', $segment['index'] ) );
			}

			if ( $truncated && ! is_file( $this->pathFor( $segment['index'] ) ) ) {
				throw new \RuntimeException( sprintf( 'Part file is missing: %s', $this->pathFor( $segment['index'] ) ) );
			}
		}

		$this->closeHandle();

		foreach ( $segments as $position => $segment ) {
			if ( $position > $keep ) {
				$path = $this->pathFor( $segment['index'] );
				if ( file_exists( $path ) && ! unlink( $path ) ) {
					throw new \RuntimeException( sprintf( 'Could not delete part file: %s', $path ) );
				}
			}
		}

		if ( -1 === $keep ) {
			$this->parts        = array();
			$this->currentIndex = 1;
			$this->currentSize  = 0;
			return;
		}

		$kept = $segments[ $keep ];

		if ( $keepIntact ) {
			$this->parts        = array_slice( $this->parts, 0, $keep + 1 );
			$this->currentIndex = $kept['index'] + 1;
			$this->currentSize  = 0;
			return;
		}

		$path = $this->pathFor( $kept['index'] );
		if ( ! $this->truncateFile( $path, $offset - $kept['start'] ) ) {
			throw new \RuntimeException( sprintf( 'Could not truncate part file: %s', $path ) );
		}

		$this->parts        = array_slice( $this->parts, 0, $keep );
		$this->currentIndex = $kept['index'];
		$this->currentSize  = $offset - $kept['start'];
		$this->openCurrent();
	}

	/**
	 * All completed parts (uploaded or not), ordered by index.
	 *
	 * @return array[]
	 */
	public function completedParts(): array {
		return $this->parts;
	}

	/**
	 * Completed parts that have not been marked as uploaded.
	 *
	 * @return array[]
	 */
	public function pendingUploads(): array {
		return array_values(
			array_filter(
				$this->parts,
				static function ( array $part ): bool {
					return empty( $part['uploaded'] );
				}
			)
		);
	}

	/**
	 * Flag a completed part as uploaded.
	 *
	 * @param int $index Part index.
	 *
	 * @throws \RuntimeException If no completed part has that index.
	 */
	public function markUploaded( int $index ): void {
		foreach ( $this->parts as $position => $part ) {
			if ( $part['index'] === $index ) {
				$this->parts[ $position ]['uploaded'] = true;
				return;
			}
		}

		throw new \RuntimeException( sprintf( 'Unknown part index: %d', $index ) );
	}

	/**
	 * Snapshot for persistence (plain, JSON-safe arrays).
	 *
	 * @return array
	 */
	public function state(): array {
		if ( null !== $this->handle ) {
			fflush( $this->handle );
		}

		return array(
			'current_index' => $this->currentIndex,
			'current_size'  => $this->currentSize,
			'offset'        => $this->offset(),
			'parts'         => $this->parts,
		);
	}

	/**
	 * Absolute path of the part with the given index.
	 */
	private function pathFor( int $index ): string {
		return $this->dir . '/' . $this->nameFor( $index );
	}

	/**
	 * File name of the part with the given index.
	 */
	private function nameFor( int $index ): string {
		return sprintf( '%s.%03d', $this->baseName, $index );
	}

	/**
	 * Open the current part for appending.
	 *
	 * @throws \RuntimeException If the file cannot be opened.
	 */
	private function openCurrent(): void {
		$path   = $this->pathFor( $this->currentIndex );
		$handle = fopen( $path, 'ab' );

		if ( false === $handle ) {
			throw new \RuntimeException( sprintf( 'Could not open part file: %s', $path ) );
		}

		$this->handle = $handle;
	}

	/**
	 * Close the current handle, if any.
	 */
	private function closeHandle(): void {
		if ( null !== $this->handle ) {
			fclose( $this->handle );
			$this->handle = null;
		}
	}

	/**
	 * Write all bytes to the current handle, handling short writes.
	 *
	 * @throws \RuntimeException If the write fails.
	 */
	private function writeAll( string $chunk ): void {
		$length  = strlen( $chunk );
		$written = 0;

		while ( $written < $length ) {
			$result = fwrite( $this->handle, substr( $chunk, $written ) );

			if ( false === $result || 0 === $result ) {
				throw new \RuntimeException( sprintf( 'Could not write to part file: %s', $this->pathFor( $this->currentIndex ) ) );
			}

			$written += $result;
		}
	}

	/**
	 * Close, hash and record the current part, then advance to the next index.
	 *
	 * @throws \RuntimeException If the file cannot be hashed.
	 */
	private function completeCurrent(): void {
		$this->closeHandle();

		$path = $this->pathFor( $this->currentIndex );
		clearstatcache( true, $path );
		$sha1   = hash_file( 'sha1', $path );
		$sha256 = hash_file( 'sha256', $path );

		if ( false === $sha1 || false === $sha256 ) {
			throw new \RuntimeException( sprintf( 'Could not hash part file: %s', $path ) );
		}

		$this->parts[] = array(
			'index'    => $this->currentIndex,
			'name'     => $this->nameFor( $this->currentIndex ),
			'path'     => $path,
			'size'     => $this->currentSize,
			'sha1'     => $sha1,
			'sha256'   => $sha256,
			'uploaded' => false,
		);

		++$this->currentIndex;
		$this->currentSize = 0;
	}

	/**
	 * Truncate a file to the given size.
	 */
	private function truncateFile( string $path, int $size ): bool {
		$handle = fopen( $path, 'r+b' );

		if ( false === $handle ) {
			return false;
		}

		$result = ftruncate( $handle, $size );
		fclose( $handle );
		clearstatcache( true, $path );

		return $result;
	}
}
