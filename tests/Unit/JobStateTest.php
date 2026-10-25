<?php

namespace WpPcloudBackup\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WpPcloudBackup\Job\JobState;
use WpPcloudBackup\Tests\Support\TempDir;

final class JobStateTest extends TestCase {

	/**
	 * Temporary directory.
	 *
	 * @var string
	 */
	private $dir;

	protected function setUp(): void {
		parent::setUp();
		$this->dir = TempDir::create();
	}

	protected function tearDown(): void {
		TempDir::remove( $this->dir );
		parent::tearDown();
	}

	public function test_save_is_atomic_and_reloadable(): void {
		$file = $this->dir . '/job.json';
		$data = array(
			'phase' => 'upload',
			'name'  => '日本語/slash',
			'items' => array( 1, 2, 3 ),
		);

		$state = JobState::create( $file, $data );

		$this->assertFileExists( $file );
		$this->assertFileDoesNotExist( $file . '.tmp' );
		$this->assertStringContainsString( '日本語/slash', file_get_contents( $file ) );

		$state->set( 'phase', 'done' );
		$state->save();
		$this->assertFileDoesNotExist( $file . '.tmp' );

		$loaded = JobState::load( $file );
		$this->assertNotNull( $loaded );
		$this->assertSame( array_merge( $data, array( 'phase' => 'done' ) ), $loaded->toArray() );
	}

	public function test_get_set_merge(): void {
		$state = JobState::create( $this->dir . '/job.json', array( 'a' => 1 ) );

		$this->assertSame( 1, $state->get( 'a' ) );
		$this->assertNull( $state->get( 'missing' ) );
		$this->assertSame( 'fallback', $state->get( 'missing', 'fallback' ) );

		$state->set( 'b', 2 );
		$state->merge( array( 'a' => 10, 'c' => 3 ) );

		$this->assertSame( array( 'a' => 10, 'b' => 2, 'c' => 3 ), $state->toArray() );
	}

	public function test_load_returns_null_for_missing_or_corrupt(): void {
		$this->assertNull( JobState::load( $this->dir . '/missing.json' ) );

		file_put_contents( $this->dir . '/corrupt.json', '{not json' );
		$this->assertNull( JobState::load( $this->dir . '/corrupt.json' ) );

		file_put_contents( $this->dir . '/scalar.json', '"text"' );
		$this->assertNull( JobState::load( $this->dir . '/scalar.json' ) );
	}

	public function test_delete_removes_file_and_is_idempotent(): void {
		$file  = $this->dir . '/job.json';
		$state = JobState::create( $file, array( 'a' => 1 ) );

		$state->delete();
		$this->assertFileDoesNotExist( $file );

		$state->delete();
		$this->assertFileDoesNotExist( $file );
	}

	public function test_save_throws_when_directory_missing(): void {
		$state = JobState::create( $this->dir . '/job.json', array() );
		TempDir::remove( $this->dir );

		$this->expectException( \RuntimeException::class );
		$state->save();
	}

	public function test_lock_is_exclusive(): void {
		$lockFile = $this->dir . '/job.lock';
		$state    = JobState::create( $this->dir . '/job.json', array() );

		$this->assertTrue( $state->acquireLock( $lockFile ) );

		$other = fopen( $lockFile, 'c' );
		$this->assertFalse( flock( $other, LOCK_EX | LOCK_NB ) );

		$state->releaseLock();
		$this->assertTrue( flock( $other, LOCK_EX | LOCK_NB ) );
		flock( $other, LOCK_UN );
		fclose( $other );

		$state->releaseLock();
	}

	public function test_second_acquire_fails_while_held(): void {
		$lockFile = $this->dir . '/job.lock';
		$first    = JobState::create( $this->dir . '/a.json', array() );
		$second   = JobState::create( $this->dir . '/b.json', array() );

		$this->assertTrue( $first->acquireLock( $lockFile ) );
		$this->assertFalse( $second->acquireLock( $lockFile ) );

		$first->releaseLock();
		$this->assertTrue( $second->acquireLock( $lockFile ) );
		$second->releaseLock();
	}
}
