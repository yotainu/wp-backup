<?php

namespace WpPcloudBackup\Zip;

/**
 * Streams files into a ZIP64 archive written through a PartWriter.
 *
 * Every entry is written in ZIP64 form, whatever its size, so one code path
 * serves small and huge archives:
 *
 * - Local file header: version needed 45, flags bit 3 (data descriptor) and
 *   bit 11 (UTF-8 name), crc 0, both sizes 0xFFFFFFFF, and a ZIP64 extra
 *   field holding zero placeholders. This is the layout Info-ZIP `zip -fz`
 *   produces when streaming. Verified with libzip 1.7 (ZipArchive with
 *   CHECKCONS), Info-ZIP `unzip -t`, Python zipfile, bsdtar (seekable and
 *   streaming), macOS ditto and Archive Utility. Windows is not verified.
 * - Data descriptor: ZIP64 form (signature, crc, 8-byte sizes; 24 bytes).
 * - Central directory record: crc, sizes and local header offset set to
 *   0xFFFFFFFF, with all three real values always present in the ZIP64
 *   extra field (uncompressed size, compressed size, offset). Always
 *   including the three fields keeps the 32-bit markers and the extra
 *   field consistent and exercises the ZIP64 path on every archive.
 * - End: ZIP64 end of central directory record, ZIP64 locator, and an end
 *   of central directory record whose fields are all 0xFFFF / 0xFFFFFFFF.
 *
 * Central directory records are appended to a spool file as each entry
 * completes (never held in memory) and copied into the stream by finish().
 * Pure PHP (zlib, hash): no WordPress functions are used.
 */
class ZipWriter {

	/**
	 * Extensions (lower case) stored without compression; everything else is deflated.
	 */
	const STORE_EXTENSIONS = array( 'jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'heic', 'mp4', 'mov', 'm4v', 'mp3', 'm4a', 'ogg', 'webm', 'zip', 'gz', 'bz2', 'xz', '7z', 'rar', 'woff', 'woff2', 'pdf' );

	/**
	 * Bytes read from a source file (or the spool file) per iteration.
	 */
	const CHUNK_SIZE = 1048576;

	const VERSION          = 45;
	const VERSION_MADE_BY  = 0x032D; // Unix, 4.5.
	const FLAGS            = 0x0808; // Bit 3: data descriptor; bit 11: UTF-8 name.
	const METHOD_STORE     = 0;
	const METHOD_DEFLATE   = 8;
	const MAX16            = 0xFFFF;
	const MAX32            = 0xFFFFFFFF;
	const ZIP64_EXTRA_ID   = 0x0001;
	const FILE_ATTRIBUTES  = 0100644; // Regular file, rw-r--r--.

	/**
	 * Destination stream.
	 *
	 * @var PartWriter
	 */
	private $sink;

	/**
	 * Central directory spool file.
	 *
	 * @var string
	 */
	private $centralDirFile;

	/**
	 * Number of completed entries.
	 *
	 * @var int
	 */
	private $entries = 0;

	/**
	 * Bytes of central directory records spooled so far.
	 *
	 * @var int
	 */
	private $cdSize = 0;

	/**
	 * Sink offset at the start of the entry being written, or null when idle.
	 *
	 * @var int|null
	 */
	private $inProgressOffset = null;

	/**
	 * Create a writer, optionally restoring a previously saved state.
	 *
	 * @param PartWriter $sink           Destination stream.
	 * @param string     $centralDirFile Spool file for central directory records.
	 * @param array      $state          A value returned by state(), to resume from.
	 *
	 * @throws \RuntimeException If the spool file is shorter than recorded or cannot be trimmed.
	 */
	public function __construct( PartWriter $sink, string $centralDirFile, array $state = array() ) {
		$this->sink           = $sink;
		$this->centralDirFile = $centralDirFile;

		$this->entries          = (int) ( $state['entries'] ?? 0 );
		$this->cdSize           = (int) ( $state['cd_size'] ?? 0 );
		$this->inProgressOffset = isset( $state['in_progress_offset'] ) ? (int) $state['in_progress_offset'] : null;

		$this->trimCentralDirFile();
	}

	/**
	 * Append one file to the archive.
	 *
	 * Sizes are the bytes actually read, not the size seen when the file was listed.
	 *
	 * @param string $absPath Absolute path of the source file.
	 * @param string $relPath Entry name: relative, forward slashes, UTF-8.
	 * @param int    $mtime   Modification time (Unix timestamp).
	 *
	 * @return array{size: int, compressed: int, method: int}
	 *
	 * @throws \InvalidArgumentException If the entry name is unsafe.
	 * @throws \LogicException           If a previous entry was interrupted and the sink was not truncated.
	 * @throws \RuntimeException         If the file cannot be read (before anything is written when it cannot be opened).
	 */
	public function addFile( string $absPath, string $relPath, int $mtime ): array {
		$this->assertSafeName( $relPath );
		$this->assertIdle();

		$handle = is_file( $absPath ) && is_readable( $absPath ) ? @fopen( $absPath, 'rb' ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

		if ( false === $handle ) {
			throw new \RuntimeException( sprintf( 'Could not open file: %s', $absPath ) );
		}

		try {
			$this->inProgressOffset = $this->sink->offset();

			$method = $this->methodFor( $relPath );
			list( $dosTime, $dosDate ) = $this->dosDateTime( $mtime );

			$this->sink->write( $this->localHeader( $relPath, $method, $dosTime, $dosDate ) );
			list( $crc, $size, $compressed ) = $this->writeData( $handle, $method, $absPath );
			$this->sink->write( pack( 'VVPP', 0x08074b50, $crc, $compressed, $size ) );
		} finally {
			fclose( $handle );
		}

		$this->appendCentralRecord( $this->centralRecord( $relPath, $method, $dosTime, $dosDate, $crc, $size, $compressed, $this->inProgressOffset ) );
		++$this->entries;
		$this->inProgressOffset = null;

		return array(
			'size'       => $size,
			'compressed' => $compressed,
			'method'     => $method,
		);
	}

	/**
	 * Sink offset at the start of the entry being written, or null when no entry is in progress.
	 *
	 * Non-null after addFile() failed mid-entry or after resuming a state saved
	 * mid-entry; the runner then calls `$sink->truncateTo( entryStartOffset() )`.
	 */
	public function entryStartOffset(): ?int {
		return $this->inProgressOffset;
	}

	/**
	 * Write the central directory and the end records, then finish the sink.
	 *
	 * @throws \LogicException   If an interrupted entry was not truncated.
	 * @throws \RuntimeException If the spool file cannot be read.
	 */
	public function finish(): void {
		$this->assertIdle();

		$cdOffset = $this->sink->offset();
		$this->copyCentralDirectory();
		$eocd64Offset = $this->sink->offset();

		$this->sink->write(
			pack( 'VPvvVVPPPP', 0x06064b50, 44, self::VERSION, self::VERSION, 0, 0, $this->entries, $this->entries, $this->cdSize, $cdOffset )
			. pack( 'VVPV', 0x07064b50, 0, $eocd64Offset, 1 )
			. pack( 'VvvvvVVv', 0x06054b50, self::MAX16, self::MAX16, self::MAX16, self::MAX16, self::MAX32, self::MAX32, 0 )
		);

		$this->sink->finish();
	}

	/**
	 * Snapshot for persistence (plain, JSON-safe array).
	 *
	 * @return array{entries: int, cd_size: int, in_progress_offset: int|null}
	 */
	public function state(): array {
		return array(
			'entries'            => $this->entries,
			'cd_size'            => $this->cdSize,
			'in_progress_offset' => $this->inProgressOffset,
		);
	}

	/**
	 * Reject names that are empty, absolute, contain ".." segments, end in a slash or are not UTF-8.
	 *
	 * @throws \InvalidArgumentException If the name is unsafe.
	 */
	private function assertSafeName( string $name ): void {
		$unsafe = '' === $name
			|| '/' === $name[0]
			|| '/' === substr( $name, -1 )
			|| in_array( '..', explode( '/', $name ), true )
			|| strlen( $name ) > self::MAX16
			|| 1 !== preg_match( '//u', $name );

		if ( $unsafe ) {
			throw new \InvalidArgumentException( sprintf( 'Unsafe entry name: %s', $name ) );
		}
	}

	/**
	 * Ensure no interrupted entry is left in the sink.
	 *
	 * @throws \LogicException If an entry is in progress and the sink holds bytes past its start.
	 */
	private function assertIdle(): void {
		if ( null !== $this->inProgressOffset && $this->sink->offset() !== $this->inProgressOffset ) {
			throw new \LogicException( 'An interrupted entry is still in the stream; truncate the sink to entryStartOffset() first.' );
		}
	}

	/**
	 * Compression method for an entry name, by extension (case-insensitive).
	 */
	private function methodFor( string $name ): int {
		$extension = strtolower( pathinfo( $name, PATHINFO_EXTENSION ) );

		return in_array( $extension, self::STORE_EXTENSIONS, true ) ? self::METHOD_STORE : self::METHOD_DEFLATE;
	}

	/**
	 * MS-DOS time and date words for a timestamp, clamped to 1980-01-01 .. 2107-12-31.
	 *
	 * @return int[] Time word, date word.
	 */
	private function dosDateTime( int $mtime ): array {
		list( $year, $month, $day, $hour, $minute, $second ) = array_map( 'intval', explode( ' ', date( 'Y n j G i s', $mtime ) ) );

		if ( $year < 1980 ) {
			return array( 0, ( 1 << 5 ) | 1 );
		}

		if ( $year > 2107 ) {
			return array( ( 23 << 11 ) | ( 59 << 5 ) | 29, ( 127 << 9 ) | ( 12 << 5 ) | 31 );
		}

		return array(
			( $hour << 11 ) | ( $minute << 5 ) | intdiv( $second, 2 ),
			( ( $year - 1980 ) << 9 ) | ( $month << 5 ) | $day,
		);
	}

	/**
	 * Local file header with a ZIP64 extra field holding zero placeholders.
	 */
	private function localHeader( string $name, int $method, int $dosTime, int $dosDate ): string {
		return pack( 'VvvvvvVVVvv', 0x04034b50, self::VERSION, self::FLAGS, $method, $dosTime, $dosDate, 0, self::MAX32, self::MAX32, strlen( $name ), 20 )
			. $name
			. pack( 'vvPP', self::ZIP64_EXTRA_ID, 16, 0, 0 );
	}

	/**
	 * Central directory record; sizes and offset live in the ZIP64 extra field.
	 */
	private function centralRecord( string $name, int $method, int $dosTime, int $dosDate, int $crc, int $size, int $compressed, int $offset ): string {
		return pack( 'VvvvvvvVVVvvvvvVV', 0x02014b50, self::VERSION_MADE_BY, self::VERSION, self::FLAGS, $method, $dosTime, $dosDate, $crc, self::MAX32, self::MAX32, strlen( $name ), 28, 0, 0, 0, self::FILE_ATTRIBUTES << 16, self::MAX32 )
			. $name
			. pack( 'vvPPP', self::ZIP64_EXTRA_ID, 24, $size, $compressed, $offset );
	}

	/**
	 * Stream the file's data into the sink.
	 *
	 * @param resource $handle  Open source file.
	 * @param int      $method  Compression method.
	 * @param string   $absPath Source path, for error messages.
	 *
	 * @return int[] CRC-32, bytes read, bytes written.
	 *
	 * @throws \RuntimeException If reading or compressing fails.
	 */
	private function writeData( $handle, int $method, string $absPath ): array {
		$hash       = hash_init( 'crc32b' );
		$deflate    = self::METHOD_DEFLATE === $method ? deflate_init( ZLIB_ENCODING_RAW, array( 'level' => 6 ) ) : null;
		$size       = 0;
		$compressed = 0;

		if ( false === $deflate ) {
			throw new \RuntimeException( 'Could not initialise deflate.' );
		}

		while ( ! feof( $handle ) ) {
			$chunk = fread( $handle, self::CHUNK_SIZE );

			if ( false === $chunk ) {
				throw new \RuntimeException( sprintf( 'Could not read file: %s', $absPath ) );
			}

			if ( '' === $chunk ) {
				continue;
			}

			$size += strlen( $chunk );
			hash_update( $hash, $chunk );
			$compressed += $this->writeChunk( $deflate, $chunk, ZLIB_NO_FLUSH );
		}

		if ( null !== $deflate ) {
			$compressed += $this->writeChunk( $deflate, '', ZLIB_FINISH );
		}

		$crc = unpack( 'N', hash_final( $hash, true ) )[1];

		return array( $crc, $size, $compressed );
	}

	/**
	 * Write one chunk (deflated when a context is given) and return the bytes written.
	 *
	 * @param resource|object|null $deflate Deflate context, or null to store.
	 *
	 * @throws \RuntimeException If compression fails.
	 */
	private function writeChunk( $deflate, string $chunk, int $flush ): int {
		$output = null === $deflate ? $chunk : deflate_add( $deflate, $chunk, $flush );

		if ( false === $output ) {
			throw new \RuntimeException( 'Could not deflate data.' );
		}

		$this->sink->write( $output );

		return strlen( $output );
	}

	/**
	 * Append a central directory record to the spool file.
	 *
	 * @throws \RuntimeException If the spool file cannot be written.
	 */
	private function appendCentralRecord( string $record ): void {
		$written = file_put_contents( $this->centralDirFile, $record, FILE_APPEND );

		if ( strlen( $record ) !== $written ) {
			throw new \RuntimeException( sprintf( 'Could not write central directory file: %s', $this->centralDirFile ) );
		}

		$this->cdSize += $written;
	}

	/**
	 * Copy cd_size bytes of the spool file into the sink.
	 *
	 * @throws \RuntimeException If the spool file cannot be read in full.
	 */
	private function copyCentralDirectory(): void {
		if ( 0 === $this->cdSize ) {
			return;
		}

		$handle = @fopen( $this->centralDirFile, 'rb' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

		if ( false === $handle ) {
			throw new \RuntimeException( sprintf( 'Could not open central directory file: %s', $this->centralDirFile ) );
		}

		try {
			$remaining = $this->cdSize;

			while ( $remaining > 0 ) {
				$chunk = fread( $handle, min( self::CHUNK_SIZE, $remaining ) );

				if ( false === $chunk || '' === $chunk ) {
					throw new \RuntimeException( sprintf( 'Central directory file is shorter than recorded: %s', $this->centralDirFile ) );
				}

				$this->sink->write( $chunk );
				$remaining -= strlen( $chunk );
			}
		} finally {
			fclose( $handle );
		}
	}

	/**
	 * Create the spool file if needed and drop bytes past cd_size (from a crashed run).
	 *
	 * @throws \RuntimeException If the file is shorter than recorded or cannot be opened or trimmed.
	 */
	private function trimCentralDirFile(): void {
		$handle = @fopen( $this->centralDirFile, 'cb' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

		if ( false === $handle ) {
			throw new \RuntimeException( sprintf( 'Could not open central directory file: %s', $this->centralDirFile ) );
		}

		$actual = fstat( $handle )['size'];

		if ( $actual < $this->cdSize ) {
			fclose( $handle );
			throw new \RuntimeException( sprintf( 'Central directory file is shorter than recorded: %s', $this->centralDirFile ) );
		}

		$trimmed = $actual === $this->cdSize || ftruncate( $handle, $this->cdSize );
		fclose( $handle );
		clearstatcache( true, $this->centralDirFile );

		if ( ! $trimmed ) {
			throw new \RuntimeException( sprintf( 'Could not trim central directory file: %s', $this->centralDirFile ) );
		}
	}
}
