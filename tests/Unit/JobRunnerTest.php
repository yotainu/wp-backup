<?php

namespace WpPcloudBackup\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use WpPcloudBackup\History;
use WpPcloudBackup\Job\JobRunner;
use WpPcloudBackup\Job\JobState;
use WpPcloudBackup\Notifier;
use WpPcloudBackup\Scheduler;
use WpPcloudBackup\Settings;
use WpPcloudBackup\Storage\LocalStorage;
use WpPcloudBackup\Storage\StorageException;
use WpPcloudBackup\Storage\StorageInterface;
use WpPcloudBackup\Tests\Support\FakeWpdb;
use WpPcloudBackup\Tests\Support\TempDir;
use WpPcloudBackup\WorkDir;

final class JobRunnerTest extends TestCase {

	private const PART_SIZE = 524288;

	private const BUDGET = 5;

	private const MAX_RUNS = 500;

	/**
	 * Temporary base directory (site, uploads, remote).
	 *
	 * @var string
	 */
	private $base;

	/**
	 * Fixture site root (backup_root).
	 *
	 * @var string
	 */
	private $site;

	/**
	 * LocalStorage base directory.
	 *
	 * @var string
	 */
	private $remote;

	/**
	 * Fixture files: relative path => contents.
	 *
	 * @var array<string,string>
	 */
	private $files = array();

	/**
	 * Fake current time.
	 *
	 * @var int
	 */
	private $now;

	/**
	 * Seconds the clock advances on every read.
	 *
	 * @var int
	 */
	private $tick = 2;

	/**
	 * Number of clock reads.
	 *
	 * @var int
	 */
	private $clockReads = 0;

	/**
	 * Option store for History.
	 *
	 * @var array
	 */
	private $options = array();

	/**
	 * Every wp_mail() call as array( to, subject, body ).
	 *
	 * @var array
	 */
	private $mails = array();

	/**
	 * Settings values shared with the Settings stub.
	 *
	 * @var \ArrayObject
	 */
	private $values;

	/**
	 * @var Settings
	 */
	private $settings;

	/**
	 * @var WorkDir
	 */
	private $workDir;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\stubTranslationFunctions();

		$this->options = array();
		$this->mails   = array();
		Functions\when( 'get_option' )->alias(
			function ( $name, $default = false ) {
				return array_key_exists( $name, $this->options ) ? $this->options[ $name ] : $default;
			}
		);
		Functions\when( 'update_option' )->alias(
			function ( $name, $value ) {
				$this->options[ $name ] = $value;
				return true;
			}
		);
		Functions\when( 'wp_mail' )->alias(
			function ( $to, $subject, $message ) {
				$this->mails[] = array( $to, $subject, $message );
				return true;
			}
		);

		$this->base   = TempDir::create();
		$this->site   = $this->base . '/site';
		$this->remote = $this->base . '/remote';
		mkdir( $this->base . '/uploads' );
		$this->makeFixture();

		$this->now        = time();
		$this->tick       = 2;
		$this->clockReads = 0;

		$this->values   = new \ArrayObject(
			array(
				'backup_root'       => $this->site,
				'excludes'          => array( 'cache' ),
				'root_folder'       => '/WPBackup',
				'site_folder'       => 'example.com',
				'schedule'          => 'daily',
				'start_time'        => '03:00',
				'keep'              => 3,
				'run_budget_sec'    => self::BUDGET,
				'notify_email'      => 'admin@example.com',
				'notify_on_success' => false,
				'workdir_name'      => '',
				'last_scheduled'    => 0,
				'last_success'      => 0,
				'last_stale_notice' => 0,
				'use_wp_cron'       => true,
			)
		);
		$this->settings = new class( $this->values ) extends Settings {
			/**
			 * @var \ArrayObject
			 */
			private $values;

			public function __construct( \ArrayObject $values ) {
				$this->values = $values;
			}

			public function get( string $key ) {
				return $this->values[ $key ] ?? null;
			}

			public function update( array $input ): array {
				foreach ( $input as $key => $value ) {
					$this->values[ $key ] = $value;
				}
				return $this->values->getArrayCopy();
			}

			public function partSizeBytes(): int {
				return 524288;
			}
		};
		$this->workDir  = new WorkDir( $this->settings, $this->base . '/uploads' );
	}

	protected function tearDown(): void {
		TempDir::remove( $this->base );
		// PHPUnit keeps every test case alive until the end: drop the big fixtures.
		$this->files    = array();
		$this->values   = null;
		$this->settings = null;
		$this->workDir  = null;
		Monkey\tearDown();
		parent::tearDown();
	}

	// ------------------------------------------------------------------
	// Fixture helpers.
	// ------------------------------------------------------------------

	/**
	 * About 50 files in nested folders, about 3 MB; four incompressible "jpg" files of 300-700 KB.
	 */
	private function makeFixture(): void {
		$this->files = array();
		for ( $i = 0; $i < 46; $i++ ) {
			$dir  = sprintf( 'dir%02d/sub%d', $i % 10, $i % 3 );
			$name = sprintf( '%s/file%02d.txt', $dir, $i );

			$this->files[ $name ] = str_repeat( sprintf( "line %d of file %d\n", $i, $i ), 1500 + $i * 40 );
		}
		foreach ( array( 300, 450, 600, 700 ) as $n => $kb ) {
			$this->files[ sprintf( 'wp-content/uploads/photo%d.jpg', $n ) ] = random_bytes( $kb * 1024 );
		}
		$this->files['日本語/ファイル.txt'] = 'こんにちは';
		$this->files['empty.txt']          = '';

		foreach ( $this->files as $relative => $contents ) {
			$path = $this->site . '/' . $relative;
			if ( ! is_dir( dirname( $path ) ) ) {
				mkdir( dirname( $path ), 0777, true );
			}
			file_put_contents( $path, $contents );
		}

		// Excluded by the "cache" pattern.
		mkdir( $this->site . '/wp-content/cache', 0777, true );
		file_put_contents( $this->site . '/wp-content/cache/page.html', 'cached' );
	}

	private function table( string $name, int $rows ): array {
		$data = array();
		for ( $i = 1; $i <= $rows; $i++ ) {
			$data[] = array(
				'id'   => (string) $i,
				'name' => $name . '-' . $i,
			);
		}

		return array(
			'create'  => 'CREATE TABLE `' . $name . '` (`id` bigint(20) NOT NULL, `name` varchar(50) DEFAULT NULL, PRIMARY KEY (`id`))',
			'columns' => array(
				array(
					'Field' => 'id',
					'Type'  => 'bigint(20)',
					'Key'   => 'PRI',
				),
				array(
					'Field' => 'name',
					'Type'  => 'varchar(50)',
					'Key'   => '',
				),
			),
			'rows'    => $data,
		);
	}

	private function wpdb( array $tables = null ): FakeWpdb {
		return new FakeWpdb(
			$tables ?? array(
				'wp_comments' => $this->table( 'wp_comments', 30 ),
				'wp_posts'    => $this->table( 'wp_posts', 12 ),
			)
		);
	}

	private function clock(): callable {
		return function (): int {
			++$this->clockReads;
			$time       = $this->now;
			$this->now += $this->tick;

			return $time;
		};
	}

	private function runner( StorageInterface $storage = null, FakeWpdb $wpdb = null, callable $clock = null ): JobRunner {
		return new JobRunner(
			$this->settings,
			$this->workDir,
			$storage ?? new LocalStorage( $this->remote ),
			new Notifier( $this->settings, 'example.com', 'https://example.com/wp-admin/' ),
			new History(),
			$wpdb ?? $this->wpdb(),
			$clock ?? $this->clock(),
			new \DateTimeZone( 'Asia/Tokyo' ),
			array(
				'site_url'       => 'https://example.com',
				'home_url'       => 'https://example.com',
				'wp_version'     => '6.6',
				'plugin_version' => '1.0.0-test',
				'abspath'        => $this->site . '/',
			)
		);
	}

	/**
	 * Call run() until the job is gone; fail after MAX_RUNS.
	 *
	 * @param callable|null $after Called after each run with (status, runNumber).
	 * @return int Number of runs.
	 */
	private function runToEnd( JobRunner $runner, callable $after = null ): int {
		for ( $i = 1; $i <= self::MAX_RUNS; $i++ ) {
			$status = $runner->run();
			if ( null !== $after ) {
				$after( $status, $i );
			}
			if ( ! $status['running'] ) {
				return $i;
			}
		}

		$this->fail( 'Job did not finish within ' . self::MAX_RUNS . ' runs' );
	}

	private function stateFile(): string {
		return $this->workDir->path( 'state.json' );
	}

	private function readState(): array {
		return json_decode( (string) file_get_contents( $this->stateFile() ), true );
	}

	private function writeState( array $data ): void {
		file_put_contents( $this->stateFile(), json_encode( $data ) );
	}

	private function siteRemote(): string {
		return $this->remote . '/WPBackup/example.com';
	}

	/**
	 * Names of the folders in the remote site folder.
	 *
	 * @return string[]
	 */
	private function remoteFolders(): array {
		if ( ! is_dir( $this->siteRemote() ) ) {
			return array();
		}

		return array_values( array_diff( scandir( $this->siteRemote() ), array( '.', '..' ) ) );
	}

	private function onlyGeneration(): string {
		$folders = $this->remoteFolders();
		$this->assertCount( 1, $folders, 'exactly one generation folder' );
		$this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2}_\d{6}$/', $folders[0] );

		return $this->siteRemote() . '/' . $folders[0];
	}

	/**
	 * Concatenate the uploaded parts and open the result with ZipArchive.
	 */
	private function openArchive( string $generation ): \ZipArchive {
		$parts = glob( $generation . '/files.zip.*' );
		sort( $parts );
		$this->assertNotEmpty( $parts );

		$joined = $this->base . '/joined.zip';
		$out    = fopen( $joined, 'wb' );
		foreach ( $parts as $part ) {
			$this->assertLessThanOrEqual( self::PART_SIZE, filesize( $part ) );
			fwrite( $out, (string) file_get_contents( $part ) );
		}
		fclose( $out );

		$zip = new \ZipArchive();
		$this->assertTrue( true === $zip->open( $joined, \ZipArchive::CHECKCONS ), 'archive opens with CHECKCONS' );

		return $zip;
	}

	/**
	 * Assert the archive holds exactly the expected files with identical contents.
	 *
	 * @param array<string,string> $expected
	 */
	private function assertArchiveMatches( string $generation, array $expected ): void {
		$zip = $this->openArchive( $generation );
		$this->assertSame( count( $expected ), $zip->numFiles );
		foreach ( $expected as $relative => $contents ) {
			$this->assertSame( $contents, $zip->getFromName( $relative ), 'entry ' . $relative );
		}
		$zip->close();
	}

	/**
	 * Job files left in the work directory (everything but .htaccess, index.php and the kept runner.lock).
	 *
	 * @return string[]
	 */
	private function leftoverJobFiles(): array {
		return array_values( array_diff( scandir( $this->workDir->path() ), array( '.', '..', '.htaccess', 'index.php', 'runner.lock' ) ) );
	}

	/**
	 * Storage decorator delegating to LocalStorage, with a hook on uploadFile.
	 */
	/**
	 * @param callable      $onUpload fn( string $name ): void, called before each upload.
	 * @param callable|null $onRename fn( StorageInterface $inner, int $id, string $name ): void, replaces renameFolder.
	 * @param callable|null $onEnsure fn( string $path ): void, called before each ensureFolder.
	 */
	private function decoratedStorage( callable $onUpload, ?callable $onRename = null, ?callable $onEnsure = null ): StorageInterface {
		return new class( new LocalStorage( $this->remote ), $onUpload, $onRename, $onEnsure ) implements StorageInterface {
			/**
			 * @var StorageInterface
			 */
			private $inner;

			/**
			 * @var callable
			 */
			private $onUpload;

			/**
			 * @var callable|null
			 */
			private $onRename;

			/**
			 * @var callable|null
			 */
			private $onEnsure;

			public function __construct( StorageInterface $inner, callable $onUpload, ?callable $onRename, ?callable $onEnsure ) {
				$this->inner    = $inner;
				$this->onUpload = $onUpload;
				$this->onRename = $onRename;
				$this->onEnsure = $onEnsure;
			}

			public function ensureFolder( string $path ): int {
				if ( null !== $this->onEnsure ) {
					( $this->onEnsure )( $path );
				}
				return $this->inner->ensureFolder( $path );
			}

			public function listFolder( int $folderId ): array {
				return $this->inner->listFolder( $folderId );
			}

			public function uploadFile( int $folderId, string $localPath, string $name ): array {
				( $this->onUpload )( $name );
				return $this->inner->uploadFile( $folderId, $localPath, $name );
			}

			public function renameFolder( int $folderId, string $newName ): void {
				if ( null !== $this->onRename ) {
					( $this->onRename )( $this->inner, $folderId, $newName );
					return;
				}
				$this->inner->renameFolder( $folderId, $newName );
			}

			public function deleteFolder( int $folderId ): void {
				$this->inner->deleteFolder( $folderId );
			}

			public function deleteFile( int $fileId ): void {
				$this->inner->deleteFile( $fileId );
			}

			public function checksum( int $fileId ): array {
				return $this->inner->checksum( $fileId );
			}
		};
	}

	/**
	 * Storage whose part uploads take a minute of the test clock, so a run stops after one part
	 * even when the clock does not tick on its own.
	 */
	private function slowPartStorage(): StorageInterface {
		return $this->decoratedStorage(
			function ( string $name ) {
				if ( 0 === strpos( $name, 'files.zip.' ) ) {
					$this->now += 60;
				}
			}
		);
	}

	// ------------------------------------------------------------------
	// Tests.
	// ------------------------------------------------------------------

	public function test_full_job_completes_over_many_runs(): void {
		$runner = $this->runner();

		$this->assertTrue( $runner->startJob( 'manual' ) );
		$this->assertFalse( $runner->startJob( 'manual' ), 'a second job cannot start while one exists' );

		$status = $runner->status();
		$this->assertTrue( $status['running'] );
		$this->assertSame( 'prepare', $status['step'] );

		$steps    = array();
		$progress = array();
		$runs     = $this->runToEnd(
			$runner,
			function ( array $status ) use ( &$steps, &$progress ) {
				if ( $status['running'] ) {
					$steps[ $status['step'] ] = true;
					$progress[]               = $status['progress'];
				}
			}
		);

		$this->assertGreaterThan( 10, $runs, 'the job is spread over many runs' );
		$this->assertArrayHasKey( 'scan', $steps );
		$this->assertArrayHasKey( 'zip', $steps );
		$this->assertArrayHasKey( 'upload', $steps );
		foreach ( $progress as $value ) {
			$this->assertGreaterThanOrEqual( 0.0, $value );
			$this->assertLessThanOrEqual( 1.0, $value );
		}

		$status = $runner->status();
		$this->assertFalse( $status['running'] );
		$this->assertNull( $status['step'] );

		$generation = $this->onlyGeneration();
		$this->assertSame( array(), glob( $this->siteRemote() . '/.incomplete-*' ) );
		$this->assertFileExists( $generation . '/manifest.json' );
		$this->assertFileExists( $generation . '/database.sql.gz' );
		$this->assertGreaterThan( 3, count( glob( $generation . '/files.zip.*' ) ), 'several 512 KB parts' );

		$this->assertArchiveMatches( $generation, $this->files );

		$manifest = json_decode( (string) file_get_contents( $generation . '/manifest.json' ), true );
		$this->assertSame( count( $this->files ), $manifest['files_count'] );
		$this->assertSame( array_sum( array_map( 'strlen', $this->files ) ), $manifest['files_bytes'] );
		$this->assertSame( array(), $manifest['warnings'] );
		$this->assertSame( array( 'wp_comments', 'wp_posts' ), $manifest['database']['tables'] );
		$this->assertSame( 42, $manifest['database']['rows'] );
		$this->assertSame( sha1_file( $generation . '/database.sql.gz' ), $manifest['database']['sha1'] );
		$this->assertSame( 'https://example.com', $manifest['site_url'] );
		$this->assertSame( '1.0.0-test', $manifest['plugin_version'] );
		$this->assertSame( array( 'cache' ), $manifest['excludes'] );
		$this->assertCount( count( glob( $generation . '/files.zip.*' ) ), $manifest['parts'] );
		foreach ( $manifest['parts'] as $part ) {
			$this->assertSame( sha1_file( $generation . '/' . $part['name'] ), $part['sha1'] );
			$this->assertSame( hash_file( 'sha256', $generation . '/' . $part['name'] ), $part['sha256'] );
		}

		$sql = gzdecode( (string) file_get_contents( $generation . '/database.sql.gz' ) );
		$this->assertStringContainsString( 'CREATE TABLE `wp_posts`', $sql );
		$this->assertStringContainsString( "(30, 'wp_comments-30')", $sql );

		$history = ( new History() )->all();
		$this->assertCount( 1, $history );
		$this->assertSame( 'success', $history[0]['result'] );
		$this->assertSame( count( $this->files ), $history[0]['files'] );
		$this->assertArrayNotHasKey( 'path', $manifest['parts'][0], 'local paths stay out of the manifest' );
		$this->assertSame( '/WPBackup/example.com/' . basename( $generation ), $history[0]['remote'] );

		$this->assertSame( array(), $this->mails, 'no mail for a clean success when notify_on_success is off' );
		$this->assertGreaterThan( 0, $this->values['last_success'] );
		$this->assertSame( 0, $this->values['last_scheduled'], 'a manual job does not move the schedule' );
		$this->assertSame( array(), $this->leftoverJobFiles() );
		$this->assertFileExists( $this->workDir->path( 'runner.lock' ), 'the lock file is never deleted' );
	}

	public function test_local_storage_dir_inside_backup_root_is_excluded(): void {
		// Development mode with WP_PCLOUD_BACKUP_STORAGE_DIR under the site: earlier generations must not be backed up again.
		$this->remote = $this->site . '/wp-content/wppb-remote/';
		mkdir( $this->site . '/wp-content/wppb-remote/WPBackup/example.com/2020-01-01_000000', 0777, true );
		file_put_contents( $this->site . '/wp-content/wppb-remote/WPBackup/example.com/2020-01-01_000000/files.zip.001', 'old generation' );

		$runner = new JobRunner(
			$this->settings,
			$this->workDir,
			new LocalStorage( $this->remote ),
			new Notifier( $this->settings, 'example.com', 'https://example.com/wp-admin/' ),
			new History(),
			$this->wpdb(),
			$this->clock(),
			new \DateTimeZone( 'Asia/Tokyo' ),
			array(
				'site_url'       => 'https://example.com',
				'home_url'       => 'https://example.com',
				'wp_version'     => '6.6',
				'plugin_version' => '1.0.0-test',
				'abspath'        => $this->site . '/',
				'storage_dir'    => $this->remote,
			)
		);

		$this->assertTrue( $runner->startJob( 'manual' ) );
		$this->runToEnd( $runner );

		$generations = glob( $this->siteRemote() . '/2*', GLOB_ONLYDIR );
		$this->assertCount( 2, $generations );
		$this->assertArchiveMatches( end( $generations ), $this->files );
	}

	public function test_one_run_keeps_zipping_and_uploading_while_the_budget_allows(): void {
		$this->values['run_budget_sec'] = 100000;
		$uploads                        = array();
		$storage                        = $this->decoratedStorage(
			function ( string $name ) use ( &$uploads ) {
				if ( 0 === strpos( $name, 'files.zip.' ) ) {
					$uploads[] = $name;
				}
			}
		);
		$runner = $this->runner( $storage );
		$runner->startJob( 'manual' );

		$status = $runner->run();

		$this->assertGreaterThanOrEqual( 2, count( $uploads ), 'several parts are uploaded in a single run' );
		$this->assertFalse( $status['running'], 'with enough budget the whole job finishes in one run' );
		$this->assertArchiveMatches( $this->onlyGeneration(), $this->files );
	}

	public function test_each_run_respects_budget_except_upload(): void {
		$slowUploads = 0;
		$storage     = $this->decoratedStorage(
			function ( string $name ) use ( &$slowUploads ) {
				if ( 0 === strpos( $name, 'files.zip.' ) ) {
					++$slowUploads;
					$this->now += 60; // A slow upload, far beyond the 5 s budget.
				}
			}
		);
		$runner = $this->runner( $storage );
		$runner->startJob( 'manual' );

		$timeStopped = array(
			'scan' => 0,
			'zip'  => 0,
		);
		$uploadRuns  = 0;
		for ( $i = 0; $i < self::MAX_RUNS; $i++ ) {
			$before = $runner->status();
			if ( ! $before['running'] ) {
				break;
			}
			$start = $this->now;
			$after = $runner->run();

			$elapsed = $this->now - $start;
			if ( isset( $timeStopped[ $before['step'] ] ) && $after['running'] && $before['step'] === $after['step'] && $before['parts_uploaded'] === $after['parts_uploaded'] ) {
				// The step did not finish and nothing was uploaded: the run must have stopped on the clock.
				$this->assertGreaterThanOrEqual( self::BUDGET, $elapsed, 'run stopped by budget in ' . $before['step'] );
				$this->assertLessThan( self::BUDGET + 10, $elapsed, 'run stopped soon after the budget in ' . $before['step'] );
				++$timeStopped[ $before['step'] ];
			}
			if ( 'upload' === $before['step'] ) {
				++$uploadRuns;
				$this->assertSame( $before['parts_uploaded'] + 1, $after['running'] ? $after['parts_uploaded'] : $before['parts_uploaded'] + 1, 'exactly one part per upload run' );
				$this->assertGreaterThan( self::BUDGET, $elapsed, 'the upload ran past the budget instead of stopping' );
			}
		}

		$this->assertFalse( $runner->status()['running'] );
		$this->assertGreaterThan( 0, $timeStopped['scan'], 'scan was cut by the budget at least once' );
		$this->assertGreaterThan( 0, $timeStopped['zip'], 'zip was cut by the budget at least once' );
		$this->assertGreaterThan( 0, $uploadRuns );
		$this->assertGreaterThan( 3, $slowUploads );
		$this->assertArchiveMatches( $this->onlyGeneration(), $this->files );
	}

	public function test_unreadable_file_becomes_warning(): void {
		$runner = $this->runner();
		$runner->startJob( 'manual' );

		for ( $i = 0; $i < self::MAX_RUNS && in_array( $runner->status()['step'], array( 'prepare', 'scan' ), true ); $i++ ) {
			$runner->run();
		}
		$status = $runner->status();
		$this->assertContains( $status['step'], array( 'dump', 'compress', 'zip', 'upload' ), 'scan finished' );
		$this->assertLessThan( $status['files_total'], $status['files_done'], 'zip has not reached the end of the list' );

		// The last listed file cannot have been zipped yet.
		$lines   = file( $this->workDir->path( 'filelist.jsonl' ), FILE_IGNORE_NEW_LINES );
		$removed = json_decode( end( $lines ), true )['p'];
		unlink( $this->site . '/' . $removed );

		$this->runToEnd( $runner );

		$generation = $this->onlyGeneration();
		$expected   = $this->files;
		unset( $expected[ $removed ] );
		$this->assertArchiveMatches( $generation, $expected );

		$manifest = json_decode( (string) file_get_contents( $generation . '/manifest.json' ), true );
		$this->assertSame( array( 'unreadable file: ' . $removed ), $manifest['warnings'] );
		$this->assertSame( count( $expected ), $manifest['files_count'] );

		$history = ( new History() )->all();
		$this->assertSame( 'warning', $history[0]['result'] );

		$this->assertCount( 1, $this->mails, 'warnings are mailed even with notify_on_success off' );
		$this->assertStringContainsString( '完了(警告あり)', $this->mails[0][1] );
		$this->assertStringContainsString( 'unreadable file: ' . $removed, $this->mails[0][2] );
	}

	public function test_retryable_error_retries_then_fails_after_5(): void {
		$uploads = 0;
		$storage = $this->decoratedStorage(
			static function () use ( &$uploads ) {
				++$uploads;
				throw new StorageException( 'connection reset', 0, true, 'network' );
			}
		);
		$runner  = $this->runner( $storage );
		$runner->startJob( 'manual' );

		$errors = array();
		$this->runToEnd(
			$runner,
			function ( array $status ) use ( &$errors ) {
				if ( $status['running'] && null !== $status['last_error'] ) {
					$errors[] = $status['last_error'];
				}
			}
		);

		$this->assertSame( 5, $uploads, 'four retries after the first failure, then abort' );
		$this->assertCount( 4, $errors );
		$this->assertSame( 'connection reset', $errors[0] );

		$this->assertSame( array(), $this->remoteFolders(), 'the .incomplete folder is deleted' );
		$this->assertSame( array(), $this->leftoverJobFiles(), 'local job files are deleted' );
		$this->assertCount( 1, $this->mails );
		$this->assertStringContainsString( '失敗', $this->mails[0][1] );
		$this->assertStringContainsString( 'upload', $this->mails[0][2] );
		$this->assertStringContainsString( '試行回数: 5', $this->mails[0][2] );

		$history = ( new History() )->all();
		$this->assertCount( 1, $history );
		$this->assertSame( 'failed', $history[0]['result'] );
		$this->assertSame( 'connection reset', $history[0]['message'] );
		$this->assertSame( 0, $this->values['last_success'] );
	}

	public function test_fatal_error_aborts_immediately(): void {
		$uploads = 0;
		$storage = $this->decoratedStorage(
			static function () use ( &$uploads ) {
				++$uploads;
				throw new StorageException( 'User is over quota', 2008, false, 'quota' );
			}
		);
		$runner  = $this->runner( $storage );
		$runner->startJob( 'manual' );

		$this->runToEnd( $runner );

		$this->assertSame( 1, $uploads );
		$this->assertSame( array(), $this->remoteFolders() );
		$this->assertSame( array(), $this->leftoverJobFiles() );
		$this->assertCount( 1, $this->mails );
		$this->assertStringContainsString( '失敗', $this->mails[0][1] );
		$this->assertStringContainsString( 'User is over quota', $this->mails[0][2] );
		$this->assertSame( 'failed', ( new History() )->all()[0]['result'] );
	}

	public function test_lock_prevents_concurrent_run(): void {
		$runner = $this->runner();
		$runner->startJob( 'manual' );

		$other = JobState::load( $this->stateFile() );
		$this->assertTrue( $other->acquireLock( $this->workDir->path( 'runner.lock' ) ) );

		$before = file_get_contents( $this->stateFile() );
		$status = $runner->run();
		$this->assertTrue( $status['running'] );
		$this->assertSame( 'prepare', $status['step'] );
		$this->assertSame( $before, file_get_contents( $this->stateFile() ), 'nothing was touched while locked' );
		$this->assertSame( array(), $this->remoteFolders() );

		$other->releaseLock();
		$this->assertNotSame( 'prepare', $runner->run()['step'] );
	}

	public function test_rapid_fire_guard(): void {
		$runner = $this->runner( $this->slowPartStorage() );
		$runner->startJob( 'manual' );
		$this->tick = 0;

		$start = $this->now;
		$runner->run();
		$state = $this->readState();
		$this->assertSame( $start, $state['last_run_started'] );
		$this->assertSame( $this->now, $state['last_run_finished'] );

		// A finished run does not block the next one, even right after it.
		$runner->run();
		$state = $this->readState();
		$this->assertGreaterThan( $start, $state['last_run_started'] );

		// Simulate a run that started now and died without finishing.
		$state['last_run_started']  = $this->now;
		$state['last_run_finished'] = $this->now - 1;
		$this->writeState( $state );
		$before = file_get_contents( $this->stateFile() );

		$runner->run();
		$this->assertSame( $before, file_get_contents( $this->stateFile() ), 'second run at the same time does nothing' );

		$this->now += self::BUDGET * 3 - 1;
		$runner->run();
		$this->assertSame( $before, file_get_contents( $this->stateFile() ), 'still inside budget x 3' );

		$this->now += 1;
		$start = $this->now;
		$runner->run();
		$this->assertNotSame( $before, file_get_contents( $this->stateFile() ), 'runs again after budget x 3' );
		$this->assertSame( $start, $this->readState()['last_run_started'] );
	}

	public function test_tick_on_fresh_install_sets_next_run_and_starts_nothing(): void {
		$runner     = $this->runner();
		$this->tick = 0;

		$status = $runner->tick();

		$expected = Scheduler::nextRun( 'daily', '03:00', Scheduler::offsetMinutes( 'example.com' ), null, $this->now, new \DateTimeZone( 'Asia/Tokyo' ) );
		$this->assertFalse( $status['running'] );
		$this->assertSame( $expected, $this->values['next_run'], 'next start time + offset' );
		$this->assertGreaterThan( $this->now, $this->values['next_run'] );
		$this->assertSame( $expected, $runner->nextRun() );
		$this->assertSame( 0, $this->values['last_scheduled'] );
		$this->assertSame( array(), $this->mails );
	}

	public function test_tick_starts_job_when_due_and_sends_stale_notice(): void {
		$tz         = new \DateTimeZone( 'Asia/Tokyo' );
		$offset     = Scheduler::offsetMinutes( 'example.com' );
		$runner     = $this->runner( $this->slowPartStorage() );
		$this->tick = 0;

		$start                          = $this->now;
		$slot                           = $this->now - 60;
		$this->values['next_run']       = $slot;
		$this->values['last_success']   = $this->now - 4 * 86400;

		$status = $runner->tick();
		$this->assertTrue( $status['running'], 'a due next_run starts a job' );
		$this->assertNotSame( 'prepare', $status['step'], 'tick ran the first run' );
		$state = $this->readState();
		$this->assertSame( 'schedule', $state['trigger'] );
		$this->assertSame( $slot, $state['slot'] );
		$this->assertSame( $slot, $this->values['last_scheduled'] );
		$this->assertSame( Scheduler::nextRun( 'daily', '03:00', $offset, $slot, $start, $tz ), $this->values['next_run'] );
		$this->assertGreaterThan( $start, $this->values['next_run'] );

		$this->assertCount( 1, $this->mails );
		$this->assertStringContainsString( '停止中', $this->mails[0][1] );
		$this->assertStringContainsString( 'バックアップが周期の2倍以上成功していません', $this->mails[0][2] );
		$this->assertSame( $start, $this->values['last_stale_notice'] );

		// Within 24 h: no second stale notice, and the running job is continued, not restarted.
		$this->now += 3600;
		$runner->tick();
		$this->assertCount( 1, $this->mails );
		$this->assertSame( $state['started'], $this->readState()['started'] );

		// Next day, still stale: one more notice.
		$this->now += 86400;
		$runner->tick();
		$this->assertCount( 2, $this->mails );
		$this->assertStringContainsString( '停止中', $this->mails[1][1] );
	}

	public function test_tick_overdue_by_days_starts_exactly_one_job(): void {
		$tz     = new \DateTimeZone( 'Asia/Tokyo' );
		$offset = Scheduler::offsetMinutes( 'example.com' );
		$runner = $this->runner();

		$slot                     = Scheduler::nextRun( 'daily', '03:00', $offset, null, $this->now - 3 * 86400, $tz );
		$this->values['next_run'] = $slot;

		$this->assertTrue( $runner->tick()['running'] );
		$this->assertSame( $slot, $this->values['last_scheduled'] );
		$this->assertGreaterThan( $this->now, $this->values['next_run'], 'missed slots are skipped' );
		$this->assertLessThanOrEqual( $this->now + 86400, $this->values['next_run'] );

		$this->runToEnd( $runner );
		$this->assertFalse( $runner->tick()['running'], 'nothing is due any more' );
		$this->assertCount( 1, ( new History() )->all() );
		$this->assertCount( 1, $this->remoteFolders() );
	}

	public function test_crash_mid_entry_is_truncated_on_resume(): void {
		$runner = $this->runner();
		$runner->startJob( 'manual' );

		$junk = 64;
		for ( $i = 0; $i < self::MAX_RUNS; $i++ ) {
			$state = $this->readState();
			if ( 'zip' === $state['step'] && ( $state['zip']['entries'] ?? 0 ) >= 3 && $state['parts']['current_size'] + $junk <= self::PART_SIZE ) {
				break;
			}
			$runner->run();
		}
		$this->assertSame( 'zip', $state['step'] );

		// Pretend the last run died while writing an entry: junk after the entry start,
		// saved in the state with in_progress_offset pointing at the entry start.
		$parts = $state['parts'];
		$path  = $this->workDir->path( sprintf( 'files.zip.%03d', $parts['current_index'] ) );
		file_put_contents( $path, random_bytes( $junk ), FILE_APPEND );
		$state['zip']['in_progress_offset'] = $parts['offset'];
		$state['parts']['current_size']    += $junk;
		$state['parts']['offset']          += $junk;
		$this->writeState( $state );

		$this->runToEnd( $runner );

		$this->assertArchiveMatches( $this->onlyGeneration(), $this->files );
		$this->assertSame( 'success', ( new History() )->all()[0]['result'] );
	}

	public function test_scan_resume_drops_lines_written_after_the_saved_state(): void {
		$runner = $this->runner();
		$runner->startJob( 'manual' );

		for ( $i = 0; $i < self::MAX_RUNS; $i++ ) {
			$runner->run();
			$state = $this->readState();
			if ( 'scan' === $state['step'] && ( $state['scan_lines'] ?? 0 ) > 0 ) {
				break;
			}
		}
		$this->assertSame( 'scan', $state['step'], 'scan spans several runs' );

		// A run appended a line and died before saving the state.
		$list = $this->workDir->path( 'filelist.jsonl' );
		file_put_contents( $list, '{"p":"empty.txt","s":0,"m":0}' . "\n", FILE_APPEND );
		$this->assertGreaterThan( $state['scan_bytes'], filesize( $list ) );

		$this->runToEnd( $runner );

		$this->assertArchiveMatches( $this->onlyGeneration(), $this->files );
	}

	public function test_report_is_not_repeated_after_a_crash_in_the_mail(): void {
		$this->values['notify_on_success'] = true;
		$snapshot                          = $this->base . '/state-at-mail.json';
		$failed                            = false;
		Functions\when( 'wp_mail' )->alias(
			function ( $to, $subject, $message ) use ( $snapshot, &$failed ) {
				if ( ! $failed ) {
					// The process "dies" here: keep the state as it was at this moment.
					$failed = true;
					copy( $this->stateFile(), $snapshot );
					throw new \RuntimeException( 'smtp down' );
				}
				$this->mails[] = array( $to, $subject, $message );
				return true;
			}
		);
		$runner = $this->runner();
		$runner->startJob( 'manual' );

		$this->runToEnd( $runner );
		$this->assertTrue( $failed );
		$this->assertSame( array(), $this->mails, 'no failure mail for a complete generation' );
		$this->assertCount( 1, ( new History() )->all() );
		$this->assertSame( 'success', ( new History() )->all()[0]['result'] );
		$this->assertGreaterThan( 0, $this->values['last_success'] );

		// Resume from the state saved just before the mail, as after a real crash.
		copy( $snapshot, $this->stateFile() );
		$this->now += self::BUDGET * 3;
		$this->runToEnd( $runner );

		$this->assertCount( 1, ( new History() )->all(), 'the history entry is not repeated' );
		$this->assertCount( 1, $this->mails, 'only the missing mail is sent' );
		$this->assertStringContainsString( '完了', $this->mails[0][1] );
		$this->assertFalse( $runner->status()['running'] );
		$this->assertArchiveMatches( $this->onlyGeneration(), $this->files );
	}

	/**
	 * Run until the job reaches the zip step, then rewind it to compress with database.sql gone.
	 */
	private function rewindToCompressWithoutSql( JobRunner $runner ): void {
		for ( $i = 0; $i < self::MAX_RUNS && 'zip' !== $this->readState()['step']; $i++ ) {
			$runner->run();
		}
		$state = $this->readState();
		$this->assertSame( 'zip', $state['step'] );
		$this->assertFileDoesNotExist( $this->workDir->path( 'database.sql' ) );

		$state['step']                  = 'compress';
		$state['db']['phase']           = 'compress';
		$state['db']['compress_offset'] = $state['db']['sql_bytes'];
		$this->writeState( $state );
	}

	public function test_compress_shortcut_accepts_a_complete_gzip(): void {
		$runner = $this->runner();
		$runner->startJob( 'manual' );
		$this->rewindToCompressWithoutSql( $runner );

		$this->runToEnd( $runner );

		$this->assertSame( 'success', ( new History() )->all()[0]['result'] );
		$generation = $this->onlyGeneration();
		$this->assertStringContainsString( 'CREATE TABLE `wp_posts`', gzdecode( (string) file_get_contents( $generation . '/database.sql.gz' ) ) );
		$this->assertArchiveMatches( $generation, $this->files );
	}

	public function test_compress_shortcut_rejects_a_truncated_gzip(): void {
		$runner = $this->runner();
		$runner->startJob( 'manual' );
		$this->rewindToCompressWithoutSql( $runner );
		file_put_contents( $this->workDir->path( 'database.sql.gz' ), gzencode( "-- WP pCloud Backup SQL dump\n" ) );

		$this->runToEnd( $runner );

		$history = ( new History() )->all();
		$this->assertSame( 'failed', $history[0]['result'], 'retried, then aborted' );
		$this->assertStringContainsString( 'does not hold the whole dump', $history[0]['message'] );
		$this->assertSame( array(), $this->remoteFolders() );
	}

	public function test_deadline_is_ignored_once_the_manifest_is_uploaded(): void {
		$renames = 0;
		$storage = $this->decoratedStorage(
			static function () {
			},
			static function ( StorageInterface $inner, int $id, string $name ) use ( &$renames ) {
				if ( 1 === ++$renames ) {
					throw new StorageException( 'timeout', 0, true, 'network' );
				}
				$inner->renameFolder( $id, $name );
			}
		);
		$runner  = $this->runner( $storage );
		$runner->startJob( 'manual' );

		for ( $i = 0; $i < self::MAX_RUNS && empty( $this->readState()['manifest_uploaded'] ); $i++ ) {
			$runner->run();
		}
		$this->assertSame( 'finalize', $this->readState()['step'] );

		$this->now += 172800 + 60;
		$this->runToEnd( $runner );

		$this->assertSame( 2, $renames );
		$this->assertSame( 'success', ( new History() )->all()[0]['result'] );
		$this->assertArchiveMatches( $this->onlyGeneration(), $this->files );
	}

	public function test_abort_after_an_unrecorded_rename_keeps_the_generation(): void {
		$storage = $this->decoratedStorage(
			static function () {
			},
			static function ( StorageInterface $inner, int $id, string $name ) {
				$inner->renameFolder( $id, $name );
				throw new \RuntimeException( 'died right after the rename' );
			}
		);
		$runner  = $this->runner( $storage );
		$runner->startJob( 'manual' );

		for ( $i = 0; $i < self::MAX_RUNS && null === $this->readState()['last_error']; $i++ ) {
			$runner->run();
		}
		$this->assertSame( 'finalize', $this->readState()['step'] );
		$this->assertEmpty( $this->readState()['renamed'] ?? null );

		$runner->abort( 'cancelled' );

		$this->assertSame( 'failed', ( new History() )->all()[0]['result'] );
		$this->assertArchiveMatches( $this->onlyGeneration(), $this->files );
	}

	public function test_finalize_requires_every_part_uploaded(): void {
		$dbUploads = 0;
		$storage   = $this->decoratedStorage(
			static function ( string $name ) use ( &$dbUploads ) {
				if ( 'database.sql.gz' === $name && 1 === ++$dbUploads ) {
					throw new StorageException( 'timeout', 0, true, 'network' );
				}
			}
		);
		$runner    = $this->runner( $storage );
		$runner->startJob( 'manual' );

		for ( $i = 0; $i < self::MAX_RUNS && 0 === $dbUploads; $i++ ) {
			$runner->run();
		}
		$state = $this->readState();
		$this->assertSame( 'finalize', $state['step'] );

		$state['parts']['parts'][0]['uploaded'] = false;
		$this->writeState( $state );

		$this->runToEnd( $runner );

		$history = ( new History() )->all();
		$this->assertSame( 'failed', $history[0]['result'] );
		$this->assertSame( 'Not every archive part has been uploaded.', $history[0]['message'] );
		$this->assertSame( array(), $this->remoteFolders(), 'no manifest, no rename: the .incomplete folder is removed' );
	}

	public function test_dump_without_tables_aborts(): void {
		$runner = $this->runner( null, $this->wpdb( array() ) );
		$runner->startJob( 'manual' );

		$this->runToEnd( $runner );

		$history = ( new History() )->all();
		$this->assertSame( 'failed', $history[0]['result'] );
		$this->assertSame( 'no tables found', $history[0]['message'] );
		$this->assertSame( array(), $this->remoteFolders() );
	}

	public function test_deadline_aborts_with_timeout(): void {
		$runner = $this->runner();
		$runner->startJob( 'manual' );
		$runner->run();

		$this->now += 172800 + 60;
		$status = $runner->run();

		$this->assertFalse( $status['running'] );
		$this->assertSame( 'timeout', ( new History() )->all()[0]['message'] );
		$this->assertSame( array(), $this->remoteFolders() );
	}

	public function test_status_when_idle(): void {
		$status = $this->runner()->status();

		$this->assertSame(
			array(
				'running'        => false,
				'step'           => null,
				'progress'       => 0.0,
				'files_done'     => 0,
				'files_total'    => 0,
				'parts_uploaded' => 0,
				'started'        => null,
				'message'        => 'idle',
				'last_error'     => null,
			),
			$status
		);
	}
	/**
	 * Emulate a process that dies at the moment $snapshot was taken: put that state back on disk
	 * (last_run_finished never written) and move the clock past the rapid-fire guard.
	 */
	private function dieAt( string $snapshot ): void {
		$this->assertFileExists( $snapshot );
		copy( $snapshot, $this->stateFile() );
		unlink( $snapshot );
		$this->now += self::BUDGET * 3;
	}

	public function test_runs_that_die_are_counted_as_attempts_and_abort_after_five(): void {
		$snapshot = $this->base . '/state-at-death.json';
		$deaths   = 0;
		$seen     = array();
		$storage  = $this->decoratedStorage(
			static function () {
			},
			null,
			function () use ( $snapshot, &$deaths, &$seen ) {
				// The process "dies" in the first remote call of every run: dieAt() later puts
				// this state back, discarding whatever the run did afterwards.
				if ( is_file( $snapshot ) ) {
					return;
				}
				++$deaths;
				$state  = $this->readState();
				$seen[] = array( (int) ( $state['attempts']['prepare'] ?? 0 ), $state['last_error'] );
				copy( $this->stateFile(), $snapshot );
			}
		);
		$runner   = $this->runner( $storage );
		$runner->startJob( 'manual' );

		$runner->run();
		$this->dieAt( $snapshot );
		$runner->run();
		$this->dieAt( $snapshot );
		$runner->run();
		$this->dieAt( $snapshot );

		$this->assertSame( 2, (int) $this->readState()['attempts']['prepare'], 'two runs died after the first one' );
		$this->assertSame( array( 0, null ), $seen[0] );
		$this->assertSame( 1, $seen[1][0] );
		$this->assertSame( '前回の実行が途中で終了しました（時間制限/メモリ不足の可能性。処理中: prepare）', $seen[1][1] );

		$runner->run();
		$this->dieAt( $snapshot );
		$runner->run();
		$this->dieAt( $snapshot );
		$this->assertSame( 4, (int) $this->readState()['attempts']['prepare'] );
		$this->assertSame( array(), $this->mails );

		// The fifth unfinished run aborts the job before anything else runs.
		$status = $runner->run();

		$this->assertSame( 5, $deaths );
		$this->assertFalse( $status['running'] );
		$this->assertSame( array(), $this->leftoverJobFiles() );
		$this->assertCount( 1, $this->mails );
		$this->assertStringContainsString( '試行回数: 5', $this->mails[0][2] );
		$this->assertStringContainsString( '前回の実行が途中で終了しました', $this->mails[0][2] );
		$history = ( new History() )->all();
		$this->assertSame( 'failed', $history[0]['result'] );
		$this->assertStringContainsString( '処理中: prepare', $history[0]['message'] );
	}

	public function test_a_run_that_dies_while_zipping_names_the_file(): void {
		$snapshot = $this->base . '/state-in-addfile.json';
		$inFlight = array();
		$runner   = null;
		$clock    = function () use ( $snapshot, &$inFlight ): int {
			$time       = $this->now;
			$this->now += $this->tick;

			// timeUp() after an entry: the state on disk is the one saved just before its addFile().
			if ( is_file( $this->stateFile() ) ) {
				$state = $this->readState();
				if ( 'zip' === $state['step'] && null !== ( $state['in_progress_file'] ?? null ) ) {
					$inFlight[] = $state['in_progress_file'];
					if ( ! is_file( $snapshot ) && count( $inFlight ) >= 5 ) {
						copy( $this->stateFile(), $snapshot );
					}
				}
			}

			return $time;
		};
		$runner   = $this->runner( null, null, $clock );
		$runner->startJob( 'manual' );

		for ( $i = 0; $i < self::MAX_RUNS && ! is_file( $snapshot ); $i++ ) {
			$runner->run();
		}
		$this->assertFileExists( $snapshot );
		foreach ( $inFlight as $path ) {
			$this->assertArrayHasKey( $path, $this->files, 'in_progress_file is a listed file' );
		}
		$dying = json_decode( (string) file_get_contents( $snapshot ), true )['in_progress_file'];

		$this->dieAt( $snapshot );
		$status = $runner->run();

		$this->assertStringContainsString( '処理中: ' . $dying . '）', (string) $this->readState()['last_error'] );
		$this->assertSame( 1, (int) $this->readState()['attempts']['zip'] );
		$this->assertTrue( $status['running'] );

		$this->runToEnd( $runner );
		$this->assertArchiveMatches( $this->onlyGeneration(), $this->files );
		$this->assertSame( 'success', ( new History() )->all()[0]['result'] );
	}

	public function test_fatal_error_is_recorded_without_paths_and_reported_by_the_next_run(): void {
		$seen    = array();
		$storage = $this->decoratedStorage(
			static function () {
			},
			null,
			function () use ( &$seen ) {
				$seen[] = $this->readState();
			}
		);
		$runner  = $this->runner( $storage );
		$runner->startJob( 'manual' );

		// A run started and hit a fatal error: last_run_finished was never written.
		$state                      = $this->readState();
		$state['last_run_started']  = $this->now;
		$state['last_run_finished'] = 0;
		$this->writeState( $state );

		$runner->recordFatal( array( 'type' => E_WARNING, 'message' => 'not fatal', 'file' => '/x/y.php', 'line' => 1 ) );
		$this->assertArrayNotHasKey( 'last_fatal', $this->readState() );

		$runner->recordFatal(
			array(
				'type'    => E_ERROR,
				'message' => 'Allowed memory size of 134217728 bytes exhausted in /var/www/html/wp-content/plugins/wp-pcloud-backup/includes/Zip/ZipWriter.php',
				'file'    => '/var/www/html/wp-content/plugins/wp-pcloud-backup/includes/Zip/ZipWriter.php',
				'line'    => 301,
			)
		);
		$fatal = $this->readState()['last_fatal'];
		$this->assertSame( E_ERROR, $fatal['type'] );
		$this->assertSame( 'Allowed memory size of 134217728 bytes exhausted in ZipWriter.php', $fatal['message'] );
		$this->assertSame( 'ZipWriter.php:301', $fatal['file'] );
		$this->assertSame( 0, $this->readState()['last_run_finished'], 'the next run still sees an unfinished run' );

		$this->now += self::BUDGET * 3;
		$runner->run();

		$this->assertNotEmpty( $seen );
		$this->assertSame( 1, (int) $seen[0]['attempts']['prepare'] );
		$this->assertSame(
			'前回の実行が途中で終了しました（時間制限/メモリ不足の可能性。処理中: prepare） Allowed memory size of 134217728 bytes exhausted in ZipWriter.php (ZipWriter.php:301)',
			$seen[0]['last_error']
		);
		$this->assertNull( $seen[0]['last_fatal'], 'used once' );
	}

	public function test_without_paths_keeps_basenames_only(): void {
		$this->assertSame( "Cannot open SQL file: database.sql", JobRunner::withoutPaths( 'Cannot open SQL file: /home/u/www/wp-content/uploads/wp-pcloud-backup-abc/database.sql' ) );
		$this->assertSame( 'Part file is missing: x.001', JobRunner::withoutPaths( 'Part file is missing: /tmp/x.001' ) );
		$this->assertSame( '1/2 done, HTTP 500', JobRunner::withoutPaths( '1/2 done, HTTP 500' ) );
	}

	public function test_record_error_sets_last_error_of_the_job(): void {
		$runner = $this->runner();
		$runner->recordError( 'nothing to record' ); // No job: no error.
		$runner->startJob( 'manual' );

		$runner->recordError( 'escaped' );

		$this->assertSame( 'escaped', $this->readState()['last_error'] );
	}
}
