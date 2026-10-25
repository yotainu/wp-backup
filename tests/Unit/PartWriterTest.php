<?php

namespace WpPcloudBackup\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WpPcloudBackup\Tests\Support\TempDir;
use WpPcloudBackup\Zip\PartWriter;

final class PartWriterTest extends TestCase {

	/**
	 * Temporary output directory.
	 *
	 * @var string
	 */
	private $dir;

	protected function setUp(): void {
		$this->dir = TempDir::create();
	}

	protected function tearDown(): void {
		TempDir::remove( $this->dir );
	}

	/**
	 * Concatenate all part files found in the directory, in name order.
	 */
	private function concatenated(): string {
		$files = glob( $this->dir . '/files.zip.*' );
		sort( $files );

		$data = '';
		foreach ( $files as $file ) {
			$data .= file_get_contents( $file );
		}

		return $data;
	}

	/**
	 * Sorted basenames of the part files in the directory.
	 *
	 * @return string[]
	 */
	private function names(): array {
		$files = array_map( 'basename', glob( $this->dir . '/files.zip.*' ) );
		sort( $files );

		return $files;
	}

	public function test_splits_exactly_at_part_size(): void {
		$data   = '0123456789abcdefghijklmno';
		$writer = new PartWriter( $this->dir, 'files.zip', 10 );
		$writer->write( $data );

		$this->assertSame( 10, filesize( $this->dir . '/files.zip.001' ) );
		$this->assertSame( 10, filesize( $this->dir . '/files.zip.002' ) );
		$this->assertCount( 2, $writer->completedParts() );
		$this->assertSame( 25, $writer->offset() );

		$writer->finish();

		$this->assertSame( 5, filesize( $this->dir . '/files.zip.003' ) );
		$this->assertCount( 3, $writer->completedParts() );
		$this->assertSame( $data, $this->concatenated() );
		$this->assertSame( 25, $writer->offset() );
	}

	public function test_does_not_create_empty_part_at_exact_boundary(): void {
		$writer = new PartWriter( $this->dir, 'files.zip', 10 );
		$writer->write( str_repeat( 'a', 20 ) );
		$writer->finish();

		$this->assertSame( array( 'files.zip.001', 'files.zip.002' ), $this->names() );
		$this->assertCount( 2, $writer->completedParts() );
	}

	public function test_finish_without_writes_creates_nothing_and_is_idempotent(): void {
		$writer = new PartWriter( $this->dir, 'files.zip', 10 );
		$writer->finish();
		$writer->finish();

		$this->assertSame( array(), $this->names() );
		$this->assertSame( array(), $writer->completedParts() );
		$this->assertSame( 0, $writer->offset() );

		$writer->write( 'abc' );
		$writer->finish();
		$writer->finish();

		$this->assertCount( 1, $writer->completedParts() );
	}

	public function test_many_small_writes_form_the_same_stream(): void {
		$data   = random_bytes( 1000 );
		$writer = new PartWriter( $this->dir, 'files.zip', 64 );
		foreach ( str_split( $data, 7 ) as $chunk ) {
			$writer->write( $chunk );
		}
		$writer->finish();

		$this->assertSame( $data, $this->concatenated() );
		$this->assertCount( 16, $writer->completedParts() );
		$this->assertSame( 1000, $writer->offset() );
	}

	public function test_hashes_computed_on_completion(): void {
		$writer = new PartWriter( $this->dir, 'files.zip', 10 );
		$writer->write( '0123456789abcde' );

		$parts = $writer->completedParts();
		$this->assertCount( 1, $parts );
		$this->assertSame( 1, $parts[0]['index'] );
		$this->assertSame( 'files.zip.001', $parts[0]['name'] );
		$this->assertSame( $this->dir . '/files.zip.001', $parts[0]['path'] );
		$this->assertSame( 10, $parts[0]['size'] );
		$this->assertSame( hash_file( 'sha1', $parts[0]['path'] ), $parts[0]['sha1'] );
		$this->assertSame( hash_file( 'sha256', $parts[0]['path'] ), $parts[0]['sha256'] );

		$writer->finish();
		$parts = $writer->completedParts();
		$this->assertSame( 5, $parts[1]['size'] );
		$this->assertSame( hash_file( 'sha1', $parts[1]['path'] ), $parts[1]['sha1'] );
		$this->assertSame( hash_file( 'sha256', $parts[1]['path'] ), $parts[1]['sha256'] );
	}

	public function test_truncate_across_parts(): void {
		$writer = new PartWriter( $this->dir, 'files.zip', 10 );
		$writer->write( '0123456789abcdefghijklmno' );

		$writer->truncateTo( 7 );

		$this->assertSame( array( 'files.zip.001' ), $this->names() );
		$this->assertSame( 7, filesize( $this->dir . '/files.zip.001' ) );
		$this->assertSame( 7, $writer->offset() );
		$this->assertSame( array(), $writer->completedParts() );

		$writer->write( 'XYZABCDEFGHIJ' );
		$writer->finish();

		$this->assertSame( '0123456XYZABCDEFGHIJ', $this->concatenated() );
		$this->assertSame( 20, $writer->offset() );
		$this->assertCount( 2, $writer->completedParts() );
	}

	public function test_truncate_at_part_boundary_keeps_completed_part(): void {
		$writer = new PartWriter( $this->dir, 'files.zip', 10 );
		$writer->write( '0123456789abcdefghijklmno' );
		$writer->markUploaded( 1 );

		$writer->truncateTo( 10 );

		$this->assertSame( array( 'files.zip.001' ), $this->names() );
		$this->assertSame( 10, $writer->offset() );
		$this->assertCount( 1, $writer->completedParts() );

		$writer->write( 'XYZ' );
		$writer->finish();

		$this->assertSame( '0123456789XYZ', $this->concatenated() );
		$this->assertSame( array( 'files.zip.001', 'files.zip.002' ), $this->names() );
	}

	public function test_truncate_to_zero_removes_everything(): void {
		$writer = new PartWriter( $this->dir, 'files.zip', 10 );
		$writer->write( '0123456789abcde' );

		$writer->truncateTo( 0 );

		$this->assertSame( array(), $this->names() );
		$this->assertSame( 0, $writer->offset() );
		$this->assertSame( array(), $writer->completedParts() );

		$writer->write( 'hello' );
		$writer->finish();
		$this->assertSame( 'hello', $this->concatenated() );
	}

	public function test_truncate_within_current_part_and_to_current_offset(): void {
		$writer = new PartWriter( $this->dir, 'files.zip', 10 );
		$writer->write( '0123456789abcde' );

		$writer->truncateTo( 15 );
		$this->assertSame( 15, $writer->offset() );

		$writer->truncateTo( 12 );
		$this->assertSame( 12, $writer->offset() );
		$this->assertSame( 2, filesize( $this->dir . '/files.zip.002' ) );

		$writer->write( 'XY' );
		$writer->finish();
		$this->assertSame( '0123456789abXY', $this->concatenated() );
	}

	public function test_truncate_into_uploaded_part_throws_and_changes_nothing(): void {
		$writer = new PartWriter( $this->dir, 'files.zip', 10 );
		$writer->write( '0123456789abcde' );
		$writer->markUploaded( 1 );

		try {
			$writer->truncateTo( 5 );
			$this->fail( 'Expected RuntimeException.' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 15, $writer->offset() );
			$this->assertSame( array( 'files.zip.001', 'files.zip.002' ), $this->names() );
			$this->assertSame( '0123456789abcde', $this->concatenated() );
		}
	}

	public function test_truncate_throws_when_part_file_is_missing(): void {
		$writer = new PartWriter( $this->dir, 'files.zip', 10 );
		$writer->write( '0123456789abcde' );
		unlink( $this->dir . '/files.zip.001' );

		$this->expectException( \RuntimeException::class );
		$writer->truncateTo( 5 );
	}

	public function test_truncate_rejects_out_of_range_offsets(): void {
		$writer = new PartWriter( $this->dir, 'files.zip', 10 );
		$writer->write( 'abc' );

		try {
			$writer->truncateTo( 4 );
			$this->fail( 'Expected exception for offset beyond end.' );
		} catch ( \InvalidArgumentException $e ) {
			$this->assertSame( 3, $writer->offset() );
		}

		$this->expectException( \InvalidArgumentException::class );
		$writer->truncateTo( -1 );
	}

	public function test_mark_uploaded_and_pending_uploads(): void {
		$writer = new PartWriter( $this->dir, 'files.zip', 10 );
		$writer->write( str_repeat( 'a', 25 ) );

		$this->assertCount( 2, $writer->pendingUploads() );
		$writer->markUploaded( 1 );

		$pending = $writer->pendingUploads();
		$this->assertCount( 1, $pending );
		$this->assertSame( 2, $pending[0]['index'] );
		$this->assertCount( 2, $writer->completedParts() );
		$this->assertTrue( $writer->completedParts()[0]['uploaded'] );

		$writer->finish();
		$this->assertCount( 2, $writer->pendingUploads() );

		$this->expectException( \RuntimeException::class );
		$writer->markUploaded( 99 );
	}

	public function test_resume_from_state(): void {
		$first = new PartWriter( $this->dir, 'files.zip', 10 );
		$first->write( '0123456789abcde' );
		$first->markUploaded( 1 );
		$state = json_decode( json_encode( $first->state() ), true );
		unset( $first );

		$this->assertSame( 2, $state['current_index'] );
		$this->assertSame( 5, $state['current_size'] );
		$this->assertSame( 15, $state['offset'] );
		$this->assertCount( 1, $state['parts'] );

		$second = new PartWriter( $this->dir, 'files.zip', 10, $state );
		$this->assertSame( 15, $second->offset() );
		$this->assertCount( 1, $second->completedParts() );
		$this->assertCount( 0, $second->pendingUploads() );

		$second->write( 'fghijklmno' );
		$second->finish();

		$this->assertSame( '0123456789abcdefghijklmno', $this->concatenated() );
		$this->assertSame( 25, $second->offset() );
		$this->assertCount( 3, $second->completedParts() );
	}

	public function test_resume_at_part_boundary_opens_next_part_lazily(): void {
		$first = new PartWriter( $this->dir, 'files.zip', 10 );
		$first->write( '0123456789' );
		$state = $first->state();
		unset( $first );

		$this->assertSame( 2, $state['current_index'] );
		$this->assertSame( 0, $state['current_size'] );

		$second = new PartWriter( $this->dir, 'files.zip', 10, $state );
		$this->assertSame( array( 'files.zip.001' ), $this->names() );
		$second->write( 'abc' );
		$second->finish();

		$this->assertSame( '0123456789abc', $this->concatenated() );
	}

	public function test_resume_trims_bytes_written_after_the_saved_state(): void {
		$first = new PartWriter( $this->dir, 'files.zip', 10 );
		$first->write( '01234' );
		$state = $first->state();
		$first->write( 'XXX' );
		unset( $first );

		$second = new PartWriter( $this->dir, 'files.zip', 10, $state );
		$this->assertSame( 5, $second->offset() );
		$this->assertSame( 5, filesize( $this->dir . '/files.zip.001' ) );

		$second->write( 'abcde' );
		$this->assertSame( '01234abcde', $this->concatenated() );
	}

	public function test_resume_throws_when_current_part_is_shorter_or_missing(): void {
		$first = new PartWriter( $this->dir, 'files.zip', 10 );
		$first->write( '01234' );
		$state = $first->state();
		unset( $first );

		file_put_contents( $this->dir . '/files.zip.001', '012' );

		try {
			new PartWriter( $this->dir, 'files.zip', 10, $state );
			$this->fail( 'Expected RuntimeException for short file.' );
		} catch ( \RuntimeException $e ) {
			unlink( $this->dir . '/files.zip.001' );
		}

		$this->expectException( \RuntimeException::class );
		new PartWriter( $this->dir, 'files.zip', 10, $state );
	}

	public function test_part_index_beyond_999_uses_four_digits(): void {
		$writer = new PartWriter( $this->dir, 'files.zip', 1 );
		$writer->write( str_repeat( 'a', 1001 ) );

		$this->assertFileExists( $this->dir . '/files.zip.999' );
		$this->assertFileExists( $this->dir . '/files.zip.1000' );
		$this->assertFileExists( $this->dir . '/files.zip.1001' );
		$this->assertCount( 1001, $writer->completedParts() );
	}
}
