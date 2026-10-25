<?php

namespace WpPcloudBackup\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WpPcloudBackup\Tests\Support\TempDir;
use WpPcloudBackup\Zip\PartWriter;
use WpPcloudBackup\Zip\ZipWriter;

final class ZipWriterTest extends TestCase {

	/**
	 * Temporary root directory.
	 *
	 * @var string
	 */
	private $dir;

	/**
	 * Directory holding the part files.
	 *
	 * @var string
	 */
	private $out;

	/**
	 * Directory holding the source files.
	 *
	 * @var string
	 */
	private $src;

	/**
	 * Central directory spool file.
	 *
	 * @var string
	 */
	private $cdFile;

	protected function setUp(): void {
		$this->dir    = TempDir::create();
		$this->out    = $this->dir . '/out';
		$this->src    = $this->dir . '/src';
		$this->cdFile = $this->dir . '/central.bin';
		mkdir( $this->out );
		mkdir( $this->src );
	}

	protected function tearDown(): void {
		TempDir::remove( $this->dir );
	}

	/**
	 * Create a source file and return its absolute path.
	 */
	private function source( string $name, string $contents ): string {
		$path = $this->src . '/' . $name;
		file_put_contents( $path, $contents );

		return $path;
	}

	/**
	 * Concatenate all part files into one zip and return its path.
	 */
	private function combined(): string {
		$files = glob( $this->out . '/files.zip.*' );
		sort( $files );

		$path = $this->dir . '/combined.zip';
		$data = '';
		foreach ( $files as $file ) {
			$data .= file_get_contents( $file );
		}
		file_put_contents( $path, $data );

		return $path;
	}

	/**
	 * Open the combined archive, asserting that it is valid.
	 */
	private function open(): \ZipArchive {
		$zip = new \ZipArchive();
		$this->assertTrue( $zip->open( $this->combined(), \ZipArchive::CHECKCONS ) );

		return $zip;
	}

	/**
	 * Round-trip a state array through JSON, as the job runner persists it.
	 */
	private function persisted( array $state ): array {
		return json_decode( json_encode( $state ), true );
	}

	public function test_store_and_deflate_by_extension(): void {
		$text  = substr( str_repeat( "The quick brown fox jumps over the lazy dog.\n", 30000 ), 0, 1048576 );
		$photo = random_bytes( 102400 );
		$upper = random_bytes( 2048 );

		$sink   = new PartWriter( $this->out, 'files.zip', 10 * 1048576 );
		$writer = new ZipWriter( $sink, $this->cdFile );

		$textResult  = $writer->addFile( $this->source( 'a.txt', $text ), 'a.txt', 1761350400 );
		$photoResult = $writer->addFile( $this->source( 'b.jpg', $photo ), 'b.jpg', 1761350400 );
		$upperResult = $writer->addFile( $this->source( 'C.JPG', $upper ), 'C.JPG', 1761350400 );
		$writer->finish();

		$this->assertSame( 8, $textResult['method'] );
		$this->assertSame( 1048576, $textResult['size'] );
		$this->assertLessThan( 1048576, $textResult['compressed'] );
		$this->assertSame(
			array(
				'size'       => 102400,
				'compressed' => 102400,
				'method'     => 0,
			),
			$photoResult
		);
		$this->assertSame( 0, $upperResult['method'] );

		$zip = $this->open();
		$this->assertSame( \ZipArchive::CM_DEFLATE, $zip->statName( 'a.txt' )['comp_method'] );
		$this->assertSame( $textResult['compressed'], $zip->statName( 'a.txt' )['comp_size'] );
		$this->assertSame( \ZipArchive::CM_STORE, $zip->statName( 'b.jpg' )['comp_method'] );
		$this->assertSame( \ZipArchive::CM_STORE, $zip->statName( 'C.JPG' )['comp_method'] );
		$this->assertSame( $text, $zip->getFromName( 'a.txt' ) );
		$this->assertSame( $photo, $zip->getFromName( 'b.jpg' ) );
		$zip->close();
	}

	public function test_resulting_zip_opens_and_matches_content(): void {
		$files = array(
			'wp-content/big.txt'           => str_repeat( "line of repetitive text for deflate\n", 75000 ),
			'wp-content/uploads/photo.jpg' => random_bytes( 150000 ),
			'empty.txt'                    => '',
		);

		$sink   = new PartWriter( $this->out, 'files.zip', 65536 );
		$writer = new ZipWriter( $sink, $this->cdFile );

		$writer->addFile( $this->source( 'big.txt', $files['wp-content/big.txt'] ), 'wp-content/big.txt', 1761350400 );
		$writer->addFile( $this->source( 'photo.jpg', $files['wp-content/uploads/photo.jpg'] ), 'wp-content/uploads/photo.jpg', 1761350402 );
		$writer->addFile( $this->source( 'empty.txt', '' ), 'empty.txt', 0 );
		$this->assertNull( $writer->entryStartOffset() );
		$writer->finish();

		$this->assertGreaterThan( 1, count( $sink->completedParts() ) );

		$zip = $this->open();
		$this->assertSame( 3, $zip->numFiles );

		foreach ( $files as $name => $contents ) {
			$stat = $zip->statName( $name );
			$this->assertSame( strlen( $contents ), $stat['size'], $name );
			$this->assertSame( crc32( $contents ), $stat['crc'], $name );
			$this->assertSame( $contents, $zip->getFromName( $name ), $name );
		}

		$this->assertSame( 1761350400, $zip->statName( 'wp-content/big.txt' )['mtime'] );
		$this->assertSame( 1761350402, $zip->statName( 'wp-content/uploads/photo.jpg' )['mtime'] );
		$this->assertSame( mktime( 0, 0, 0, 1, 1, 1980 ), $zip->statName( 'empty.txt' )['mtime'] );
		$zip->close();
	}

	public function test_utf8_and_long_names(): void {
		$japanese = 'wp-content/uploads/2026/10/日本語 ファイル名.txt';
		$long     = 'wp-content/' . str_repeat( 'a', 100 ) . '/' . str_repeat( 'b', 84 ) . '.txt';
		$this->assertSame( 200, strlen( $long ) );

		$sink   = new PartWriter( $this->out, 'files.zip', 1048576 );
		$writer = new ZipWriter( $sink, $this->cdFile );
		$writer->addFile( $this->source( 'jp.txt', 'japanese' ), $japanese, 1761350400 );
		$writer->addFile( $this->source( 'long.txt', 'long' ), $long, 1761350400 );
		$writer->finish();

		$zip = $this->open();
		$this->assertSame( $japanese, $zip->statIndex( 0 )['name'] );
		$this->assertSame( $long, $zip->statIndex( 1 )['name'] );
		$this->assertSame( 'japanese', $zip->getFromName( $japanese ) );
		$this->assertSame( 'long', $zip->getFromName( $long ) );
		$zip->close();
	}

	public function test_actual_size_wins_over_declared(): void {
		$path     = $this->source( 'growing.log', str_repeat( 'x', 100 ) );
		$declared = filesize( $path );
		file_put_contents( $path, str_repeat( 'y', 200 ), FILE_APPEND );

		$sink   = new PartWriter( $this->out, 'files.zip', 1048576 );
		$writer = new ZipWriter( $sink, $this->cdFile );
		$result = $writer->addFile( $path, 'growing.log', 1761350400 );
		$writer->finish();

		$this->assertSame( 100, $declared );
		$this->assertSame( 300, $result['size'] );

		$zip = $this->open();
		$this->assertSame( 300, $zip->statName( 'growing.log' )['size'] );
		$this->assertSame( str_repeat( 'x', 100 ) . str_repeat( 'y', 200 ), $zip->getFromName( 'growing.log' ) );
		$zip->close();
	}

	public function test_resume_mid_archive(): void {
		$one   = str_repeat( 'first file ', 5000 );
		$two   = random_bytes( 30000 );
		$three = str_repeat( 'third file ', 3000 );

		$sink   = new PartWriter( $this->out, 'files.zip', 20000 );
		$writer = new ZipWriter( $sink, $this->cdFile );
		$writer->addFile( $this->source( 'one.txt', $one ), 'one.txt', 1761350400 );
		$writer->addFile( $this->source( 'two.webp', $two ), 'two.webp', 1761350400 );

		$sinkState   = $this->persisted( $sink->state() );
		$writerState = $this->persisted( $writer->state() );
		unset( $writer, $sink );

		$this->assertSame( 2, $writerState['entries'] );
		$this->assertSame( filesize( $this->cdFile ), $writerState['cd_size'] );
		$this->assertNull( $writerState['in_progress_offset'] );

		$sink   = new PartWriter( $this->out, 'files.zip', 20000, $sinkState );
		$writer = new ZipWriter( $sink, $this->cdFile, $writerState );
		$writer->addFile( $this->source( 'three.txt', $three ), 'three.txt', 1761350400 );
		$writer->finish();

		$zip = $this->open();
		$this->assertSame( 3, $zip->numFiles );
		$this->assertSame( $one, $zip->getFromName( 'one.txt' ) );
		$this->assertSame( $two, $zip->getFromName( 'two.webp' ) );
		$this->assertSame( $three, $zip->getFromName( 'three.txt' ) );
		$zip->close();
	}

	public function test_truncate_after_crash_mid_entry(): void {
		$one = str_repeat( 'kept entry ', 4000 );
		$two = str_repeat( 'retried entry ', 4000 );

		$sink   = new PartWriter( $this->out, 'files.zip', 16384 );
		$writer = new ZipWriter( $sink, $this->cdFile );
		$writer->addFile( $this->source( 'one.txt', $one ), 'one.txt', 1761350400 );

		// Simulate a process that died while writing the second entry.
		$start = $sink->offset();
		$sink->write( random_bytes( 100 ) );
		file_put_contents( $this->cdFile, 'partial central record', FILE_APPEND );

		$writerState                       = $writer->state();
		$writerState['in_progress_offset'] = $start;
		$writerState                       = $this->persisted( $writerState );
		$sinkState                         = $this->persisted( $sink->state() );
		unset( $writer, $sink );

		// What the job runner does on resume.
		$sink   = new PartWriter( $this->out, 'files.zip', 16384, $sinkState );
		$writer = new ZipWriter( $sink, $this->cdFile, $writerState );
		$this->assertSame( $start, $writer->entryStartOffset() );
		$sink->truncateTo( $writer->entryStartOffset() );

		$writer->addFile( $this->source( 'two.txt', $two ), 'two.txt', 1761350400 );
		$writer->addFile( $this->source( 'three.png', 'png bytes' ), 'three.png', 1761350400 );
		$writer->finish();

		$zip = $this->open();
		$this->assertSame( 3, $zip->numFiles );
		$this->assertSame( $one, $zip->getFromName( 'one.txt' ) );
		$this->assertSame( $two, $zip->getFromName( 'two.txt' ) );
		$this->assertSame( 'png bytes', $zip->getFromName( 'three.png' ) );
		$zip->close();
	}

	public function test_refuses_to_append_over_an_untruncated_entry(): void {
		$sink   = new PartWriter( $this->out, 'files.zip', 1048576 );
		$writer = new ZipWriter( $sink, $this->cdFile );
		$writer->addFile( $this->source( 'one.txt', 'one' ), 'one.txt', 1761350400 );

		$state                       = $writer->state();
		$state['in_progress_offset'] = $sink->offset();
		$sink->write( 'partial entry bytes' );
		$writer = new ZipWriter( $sink, $this->cdFile, $state );

		$this->expectException( \LogicException::class );
		$writer->addFile( $this->source( 'two.txt', 'two' ), 'two.txt', 1761350400 );
	}

	public function test_unreadable_file_throws_before_writing(): void {
		$sink   = new PartWriter( $this->out, 'files.zip', 1048576 );
		$writer = new ZipWriter( $sink, $this->cdFile );

		try {
			$writer->addFile( $this->src . '/missing.txt', 'missing.txt', 1761350400 );
			$this->fail( 'Expected a RuntimeException.' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 0, $sink->offset() );
			$this->assertNull( $writer->entryStartOffset() );
			$this->assertSame( 0, $writer->state()['entries'] );
		}
	}

	/**
	 * @dataProvider unsafeNames
	 */
	public function test_rejects_unsafe_names( string $name ): void {
		$sink   = new PartWriter( $this->out, 'files.zip', 1048576 );
		$writer = new ZipWriter( $sink, $this->cdFile );

		$this->expectException( \InvalidArgumentException::class );
		$writer->addFile( $this->source( 'x.txt', 'x' ), $name, 1761350400 );
	}

	/**
	 * @return array[]
	 */
	public function unsafeNames(): array {
		return array(
			'leading slash'   => array( '/etc/passwd' ),
			'parent segment'  => array( 'wp-content/../wp-config.php' ),
			'leading parent'  => array( '../outside.txt' ),
			'trailing parent' => array( 'wp-content/..' ),
			'trailing slash'  => array( 'wp-content/' ),
			'empty'           => array( '' ),
		);
	}
}
