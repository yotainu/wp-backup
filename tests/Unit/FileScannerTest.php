<?php

namespace WpPcloudBackup\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WpPcloudBackup\Scan\FileScanner;
use WpPcloudBackup\Tests\Support\TempDir;

final class FileScannerTest extends TestCase {

	/**
	 * Temporary site root.
	 *
	 * @var string
	 */
	private $root;

	/**
	 * Temporary directory holding the file list.
	 *
	 * @var string
	 */
	private $out;

	protected function setUp(): void {
		$this->root = TempDir::create();
		$this->out  = TempDir::create();

		$files = array(
			'a.txt',
			'wp-content/uploads/x.jpg',
			'wp-content/cache/c.txt',
			'wp-content/uploads/backup-old/z.zip',
			'日本語 名前.txt',
		);

		foreach ( $files as $file ) {
			$path = $this->root . '/' . $file;
			if ( ! is_dir( dirname( $path ) ) ) {
				mkdir( dirname( $path ), 0755, true );
			}
			file_put_contents( $path, 'data-' . $file );
		}
	}

	protected function tearDown(): void {
		TempDir::remove( $this->root );
		TempDir::remove( $this->out );
	}

	/**
	 * Read the JSONL list file as decoded rows.
	 */
	private function readList(): array {
		$lines = file( $this->out . '/filelist.jsonl', FILE_IGNORE_NEW_LINES );
		$this->assertNotContains( '', $lines, 'no blank lines' );

		return array_map(
			static function ( string $line ): array {
				return json_decode( $line, true );
			},
			$lines
		);
	}

	public function test_lists_all_files_except_excludes(): void {
		$scanner = new FileScanner(
			$this->root,
			array( 'wp-content/cache', 'wp-content/uploads/backup-old' ),
			$this->out . '/filelist.jsonl'
		);

		$this->assertTrue(
			$scanner->run(
				static function (): bool {
					return false;
				}
			)
		);

		$paths = array_column( $this->readList(), 'p' );
		sort( $paths );
		$expected = array( 'a.txt', 'wp-content/uploads/x.jpg', '日本語 名前.txt' );
		sort( $expected );
		$this->assertSame( $expected, $paths );

		$raw = file_get_contents( $this->out . '/filelist.jsonl' );
		$this->assertStringContainsString( '"p":"日本語 名前.txt"', $raw );
		$this->assertStringContainsString( '"p":"wp-content/uploads/x.jpg"', $raw );

		$rows = array_column( $this->readList(), null, 'p' );
		$this->assertSame( strlen( 'data-a.txt' ), $rows['a.txt']['s'] );
		$this->assertSame( filemtime( $this->root . '/a.txt' ), $rows['a.txt']['m'] );

		$state = $scanner->state();
		$this->assertSame( array(), $state['pending'] );
		$this->assertSame( 3, $state['files'] );
		$this->assertSame(
			strlen( 'data-a.txt' ) + strlen( 'data-wp-content/uploads/x.jpg' ) + strlen( 'data-日本語 名前.txt' ),
			$state['bytes']
		);
		$this->assertSame( array(), $state['warnings'] );
	}

	public function test_resumes_from_state(): void {
		$list    = $this->out . '/filelist.jsonl';
		$scanner = new FileScanner( $this->root, array(), $list );

		$calls  = 0;
		$timeUp = static function () use ( &$calls ): bool {
			++$calls;
			return $calls > 1;
		};

		$this->assertFalse( $scanner->run( $timeUp ) );
		$state = json_decode( json_encode( $scanner->state() ), true );
		$this->assertNotEmpty( $state['pending'] );
		$this->assertLessThan( 5, count( $this->readList() ) );

		$resumed = new FileScanner( $this->root, array(), $list, $state );
		$this->assertTrue(
			$resumed->run(
				static function (): bool {
					return false;
				}
			)
		);

		$paths = array_column( $this->readList(), 'p' );
		$this->assertCount( 5, $paths );
		$this->assertSame( $paths, array_values( array_unique( $paths ) ) );
		$this->assertSame( 5, $resumed->state()['files'] );
		$this->assertSame( array(), $resumed->state()['pending'] );
	}

	public function test_symlink_is_skipped_with_warning(): void {
		symlink( $this->root . '/a.txt', $this->root . '/link.txt' );
		symlink( $this->root . '/wp-content', $this->root . '/linkdir' );

		$scanner = new FileScanner( $this->root, array(), $this->out . '/filelist.jsonl' );
		$this->assertTrue(
			$scanner->run(
				static function (): bool {
					return false;
				}
			)
		);

		$paths = array_column( $this->readList(), 'p' );
		$this->assertNotContains( 'link.txt', $paths );
		$this->assertCount( 5, $paths );

		$warnings = $scanner->state()['warnings'];
		$this->assertContains( 'symlink skipped: link.txt', $warnings );
		$this->assertContains( 'symlink skipped: linkdir', $warnings );
	}

	public function test_wildcard_and_normalized_excludes(): void {
		$scanner = new FileScanner(
			$this->root,
			array( ' /wp-content/cac* ', '', 'a.tx' ),
			$this->out . '/filelist.jsonl'
		);
		$scanner->run(
			static function (): bool {
				return false;
			}
		);

		$paths = array_column( $this->readList(), 'p' );
		$this->assertNotContains( 'wp-content/cache/c.txt', $paths );
		$this->assertContains( 'a.txt', $paths );
		$this->assertCount( 4, $paths );
	}

	public function test_unreadable_directory_warns(): void {
		$scanner = new FileScanner(
			$this->root,
			array(),
			$this->out . '/filelist.jsonl',
			array(
				'pending' => array( 'missing-dir' ),
			)
		);
		$this->assertTrue(
			$scanner->run(
				static function (): bool {
					return false;
				}
			)
		);
		$this->assertContains( 'unreadable directory: missing-dir', $scanner->state()['warnings'] );
	}

	/**
	 * Create files below the test root.
	 *
	 * @param string[] $files Relative paths.
	 */
	private function addFiles( array $files ): void {
		foreach ( $files as $file ) {
			$path = $this->root . '/' . $file;
			if ( ! is_dir( dirname( $path ) ) ) {
				mkdir( dirname( $path ), 0755, true );
			}
			file_put_contents( $path, 'x' );
		}
	}

	public function test_bare_excludes_match_at_any_depth(): void {
		$this->addFiles(
			array(
				'wp-content/themes/x/.git/config',
				'wp-content/plugins/p/node_modules/a.js',
				'.github/workflows/ci.yml',
				'other/wp-content/cache/x',
				'wp-content/plugins/p/cache-tmp/y',
			)
		);

		$scanner = new FileScanner(
			$this->root,
			array( '.git', 'node_modules', 'wp-content/cache', 'cache-*' ),
			$this->out . '/filelist.jsonl'
		);
		$scanner->run(
			static function (): bool {
				return false;
			}
		);

		$paths = array_column( $this->readList(), 'p' );
		$this->assertNotContains( 'wp-content/themes/x/.git/config', $paths );
		$this->assertNotContains( 'wp-content/plugins/p/node_modules/a.js', $paths );
		$this->assertNotContains( 'wp-content/plugins/p/cache-tmp/y', $paths );
		$this->assertContains( '.github/workflows/ci.yml', $paths );
		$this->assertContains( 'other/wp-content/cache/x', $paths );
		$this->assertNotContains( 'wp-content/cache/c.txt', $paths );
	}

	public function test_file_that_cannot_be_stated_is_warned_and_skipped(): void {
		$scanner = new class( $this->root, array(), $this->out . '/filelist.jsonl' ) extends FileScanner {
			protected function statFile( string $path ): ?array {
				if ( substr( $path, -6 ) === '/a.txt' ) {
					return null;
				}

				return parent::statFile( $path );
			}
		};
		$this->assertTrue(
			$scanner->run(
				static function (): bool {
					return false;
				}
			)
		);

		$paths = array_column( $this->readList(), 'p' );
		$this->assertNotContains( 'a.txt', $paths );
		$this->assertCount( 4, $paths );
		$this->assertContains( 'unreadable file: a.txt', $scanner->state()['warnings'] );
		$this->assertSame( 4, $scanner->state()['files'] );
	}
}
