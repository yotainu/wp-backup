<?php

namespace WpPcloudBackup\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WpPcloudBackup\Db\DbDumper;
use WpPcloudBackup\Tests\Support\FakeWpdb;
use WpPcloudBackup\Tests\Support\TempDir;

final class DbDumperTest extends TestCase {

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

	private function sqlFile(): string {
		return $this->dir . '/db.sql';
	}

	private function gzFile(): string {
		return $this->dir . '/db.sql.gz';
	}

	private function noLimit(): callable {
		return static function () {
			return false;
		};
	}

	private function stopNow(): callable {
		return static function () {
			return true;
		};
	}

	/**
	 * A small table with a primary key.
	 */
	private function simpleTable( array $rows ): array {
		return array(
			'create'  => 'CREATE TABLE `wp_things` (`id` bigint(20) NOT NULL, `name` varchar(50) DEFAULT NULL, PRIMARY KEY (`id`))',
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
			'rows'    => $rows,
		);
	}

	public function test_header_and_create_table(): void {
		$wpdb   = new FakeWpdb( array( 'wp_things' => $this->simpleTable( array( array( 'id' => '1', 'name' => 'a' ) ) ) ) );
		$dumper = new DbDumper( $wpdb, $this->sqlFile(), $this->gzFile() );

		$this->assertTrue( $dumper->dump( $this->noLimit() ) );

		$sql = file_get_contents( $this->sqlFile() );
		$this->assertSame(
			"-- WP pCloud Backup SQL dump\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\nSET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\n"
			. "DROP TABLE IF EXISTS `wp_things`;\n"
			. "CREATE TABLE `wp_things` (`id` bigint(20) NOT NULL, `name` varchar(50) DEFAULT NULL, PRIMARY KEY (`id`));\n"
			. "INSERT INTO `wp_things` (`id`, `name`) VALUES\n(1, 'a');\n"
			. "SET FOREIGN_KEY_CHECKS=1;\n",
			$sql
		);
		$this->assertSame( 'compress', $dumper->state()['phase'] );
		$this->assertSame( 1, $dumper->state()['rows'] );
	}

	public function test_insert_batches_and_escaping(): void {
		$rows = array(
			array(
				'id'   => '1',
				'name' => "O'Reilly",
				'data' => "\x00\xff",
				'note' => 'a\\b',
			),
			array(
				'id'   => '2',
				'name' => "line\nbreak",
				'data' => '',
				'note' => "cr\r \"q\" nul\0 sub\x1a",
			),
			array(
				'id'   => '3',
				'name' => '寿司 🍣',
				'data' => null,
				'note' => null,
			),
		);
		for ( $i = 4; $i <= 2500; $i++ ) {
			$rows[] = array(
				'id'   => (string) $i,
				'name' => 'n' . $i,
				'data' => 'x',
				'note' => '1.5e3',
			);
		}

		$wpdb = new FakeWpdb(
			array(
				'wp_big' => array(
					'create'  => 'CREATE TABLE `wp_big` (...)',
					'columns' => array(
						array(
							'Field' => 'id',
							'Type'  => 'int(10) unsigned',
							'Key'   => 'PRI',
						),
						array(
							'Field' => 'name',
							'Type'  => 'varchar(50)',
							'Key'   => '',
						),
						array(
							// varbinary: hex literals, without the smaller page of blob/text tables.
							'Field' => 'data',
							'Type'  => 'varbinary(255)',
							'Key'   => '',
						),
						array(
							'Field' => 'note',
							'Type'  => 'decimal(10,2)',
							'Key'   => '',
						),
					),
					'rows'    => $rows,
				),
			)
		);

		$dumper = new DbDumper( $wpdb, $this->sqlFile(), $this->gzFile(), array(), 1000 );
		$this->assertTrue( $dumper->dump( $this->noLimit() ) );

		$sql = file_get_contents( $this->sqlFile() );
		$this->assertSame( 3, preg_match_all( '/^INSERT INTO `wp_big` \(`id`, `name`, `data`, `note`\) VALUES$/m', $sql ) );
		$this->assertSame( 2500, $dumper->state()['rows'] );

		// Quote, binary and NULL handling (a decimal-typed value that is not a plain number is quoted).
		$this->assertStringContainsString( "(1, 'O\\'Reilly', 0x00ff, 'a\\\\b'),\n", $sql );
		$this->assertStringContainsString( "(2, 'line\\nbreak', '', 'cr\\r \\\"q\\\" nul\\0 sub\\Z'),\n", $sql );
		$this->assertStringContainsString( "(3, '寿司 🍣', NULL, NULL),\n", $sql );
		// A numeric column holding a number is emitted verbatim, binary 'x' as hex.
		$this->assertStringContainsString( "(4, 'n4', 0x78, 1.5e3),\n", $sql );

		// Statements end with ";" exactly at batch boundaries.
		$this->assertStringContainsString( "(1000, 'n1000', 0x78, 1.5e3);\nINSERT INTO", $sql );
		$this->assertStringContainsString( "(2000, 'n2000', 0x78, 1.5e3);\nINSERT INTO", $sql );
		$this->assertStringContainsString( "(2500, 'n2500', 0x78, 1.5e3);\nSET FOREIGN_KEY_CHECKS=1;", $sql );
	}

	public function test_table_without_primary_key_and_empty_table(): void {
		$wpdb = new FakeWpdb(
			array(
				'wp_empty'  => $this->simpleTable( array() ),
				'wp_nopk'   => array(
					'create'  => 'CREATE TABLE `wp_nopk` (`v` text)',
					'columns' => array(
						array(
							'Field' => 'v',
							'Type'  => 'text',
							'Key'   => '',
						),
					),
					'rows'    => array( array( 'v' => 'x' ) ),
				),
				'wp_twopks' => array(
					'create'  => 'CREATE TABLE `wp_twopks` (...)',
					'columns' => array(
						array(
							'Field' => 'a',
							'Type'  => 'int(11)',
							'Key'   => 'PRI',
						),
						array(
							'Field' => 'b',
							'Type'  => 'int(11)',
							'Key'   => 'PRI',
						),
					),
					'rows'    => array(),
				),
			)
		);

		$dumper = new DbDumper( $wpdb, $this->sqlFile(), $this->gzFile() );
		$this->assertTrue( $dumper->dump( $this->noLimit() ) );

		$selects = array_values(
			array_filter(
				$wpdb->queries,
				static function ( $q ) {
					return 0 === strpos( $q, 'SELECT' );
				}
			)
		);
		$this->assertSame(
			array(
				'SELECT * FROM `wp_empty` ORDER BY `id` LIMIT 1000',
				'SELECT * FROM `wp_nopk` LIMIT 200 OFFSET 0', // A text column: smaller pages.
				'SELECT * FROM `wp_twopks` ORDER BY `a`, `b` LIMIT 1000',
			),
			$selects
		);

		$sql = file_get_contents( $this->sqlFile() );
		$this->assertStringContainsString( "DROP TABLE IF EXISTS `wp_empty`;\nCREATE TABLE `wp_things`", $sql );
		$this->assertSame( 1, preg_match_all( '/^INSERT INTO/m', $sql ) );
		$this->assertStringContainsString( "INSERT INTO `wp_nopk` (`v`) VALUES\n('x');\n", $sql );
	}

	public function test_transients_excluded_from_options(): void {
		$wpdb = new FakeWpdb(
			array(
				'wp_options' => array(
					'create'  => 'CREATE TABLE `wp_options` (...)',
					'columns' => array(
						array(
							'Field' => 'option_id',
							'Type'  => 'bigint(20)',
							'Key'   => 'PRI',
						),
						array(
							'Field' => 'option_name',
							'Type'  => 'varchar(191)',
							'Key'   => 'UNI',
						),
					),
					'rows'    => array(
						array(
							'option_id'   => '1',
							'option_name' => 'siteurl',
						),
						array(
							'option_id'   => '2',
							'option_name' => '_transient_foo',
						),
						array(
							'option_id'   => '3',
							'option_name' => '_site_transient_bar',
						),
						array(
							'option_id'   => '4',
							'option_name' => 'blogname',
						),
					),
				),
				'wp_things'  => $this->simpleTable( array() ),
			)
		);

		$dumper = new DbDumper( $wpdb, $this->sqlFile(), $this->gzFile() );
		$this->assertTrue( $dumper->dump( $this->noLimit() ) );

		$this->assertContains(
			"SELECT * FROM `wp_options` WHERE option_name NOT LIKE '\\_transient\\_%' AND option_name NOT LIKE '\\_site\\_transient\\_%' ORDER BY `option_id` LIMIT 1000",
			$wpdb->queries
		);
		// Other tables are not filtered.
		$this->assertContains( 'SELECT * FROM `wp_things` ORDER BY `id` LIMIT 1000', $wpdb->queries );

		$sql = file_get_contents( $this->sqlFile() );
		$this->assertStringContainsString( "'siteurl'", $sql );
		$this->assertStringContainsString( "'blogname'", $sql );
		$this->assertStringNotContainsString( 'transient', $sql );
	}

	public function test_resume_by_table_and_offset(): void {
		$rows = array();
		for ( $i = 1; $i <= 7; $i++ ) {
			$rows[] = array(
				'id'   => (string) $i,
				'name' => 'n' . $i,
			);
		}
		$tables = array(
			'wp_b' => $this->simpleTable( $rows ),
			'wp_a' => $this->simpleTable( array_slice( $rows, 0, 3 ) ),
		);

		// Reference: one uninterrupted run.
		$reference = new DbDumper( new FakeWpdb( $tables ), $this->dir . '/ref.sql', $this->gzFile(), array(), 3 );
		$this->assertTrue( $reference->dump( $this->noLimit() ) );

		// Interrupted after every page, resuming from a JSON round-tripped state each time.
		$state = array();
		$calls = 0;
		do {
			$dumper = new DbDumper( new FakeWpdb( $tables ), $this->sqlFile(), $this->gzFile(), $state, 3 );
			$done   = $dumper->dump( $this->stopNow() );
			$state  = json_decode( json_encode( $dumper->state() ), true );
			++$calls;
			$this->assertLessThan( 20, $calls );
		} while ( ! $done );

		$this->assertGreaterThan( 3, $calls );
		$this->assertSame( file_get_contents( $this->dir . '/ref.sql' ), file_get_contents( $this->sqlFile() ) );
		$this->assertSame( array( 'wp_a', 'wp_b' ), $state['tables'] );
		$this->assertSame( 10, $state['rows'] );
		$this->assertSame( 'compress', $state['phase'] );
		$this->assertSame( 1, substr_count( file_get_contents( $this->sqlFile() ), 'SET NAMES' ) );
	}

	public function test_resume_continues_from_saved_offset(): void {
		$rows = array();
		for ( $i = 1; $i <= 5; $i++ ) {
			$rows[] = array(
				'id'   => (string) $i,
				'name' => 'n' . $i,
			);
		}
		$wpdb   = new FakeWpdb( array( 'wp_things' => $this->simpleTable( $rows ) ) );
		$dumper = new DbDumper( $wpdb, $this->sqlFile(), $this->gzFile(), array(), 2 );

		$this->assertFalse( $dumper->dump( $this->stopNow() ) );
		$state = $dumper->state();
		$this->assertSame( 2, $state['offset'] );
		$this->assertSame( 0, $state['table_index'] );
		$this->assertSame( 'dump', $state['phase'] );

		$wpdb2   = new FakeWpdb( array( 'wp_things' => $this->simpleTable( $rows ) ) );
		$resumed = new DbDumper( $wpdb2, $this->sqlFile(), $this->gzFile(), $state, 2 );
		$this->assertTrue( $resumed->dump( $this->noLimit() ) );

		// The resumed run continues after the last dumped key and does not re-read the table's metadata.
		$this->assertSame( array( base64_encode( '2' ) ), $state['last_key'] );
		$this->assertSame( 'SELECT * FROM `wp_things` WHERE `id` > 2 ORDER BY `id` LIMIT 2', $wpdb2->queries[0] );
		$this->assertNotContains( 'SHOW CREATE TABLE `wp_things`', $wpdb2->queries );
	}

	public function test_compress_produces_valid_multi_member_gzip(): void {
		// ~2.5 MiB of semi-random but reproducible text, so it needs several 1 MiB chunks.
		$sql = '';
		mt_srand( 42 );
		while ( strlen( $sql ) < 2600000 ) {
			$sql .= md5( (string) mt_rand() ) . "\n";
		}
		file_put_contents( $this->sqlFile(), $sql );

		$state = array( 'phase' => 'compress' );
		$runs  = 0;
		do {
			$dumper = new DbDumper( new FakeWpdb( array() ), $this->sqlFile(), $this->gzFile(), $state );
			$done   = $dumper->compress( $this->stopNow() );
			$state  = $dumper->state();
			++$runs;
			// Brain Monkey's Patchwork stream wrapper (active once any earlier test used it) caps fread() at 8 KiB, so allow many runs.
			$this->assertLessThan( 1000, $runs );
		} while ( ! $done );

		$this->assertGreaterThanOrEqual( 3, $runs );
		$this->assertFileDoesNotExist( $this->sqlFile() );
		$this->assertSame( 'done', $state['phase'] );

		$handle = gzopen( $this->gzFile(), 'rb' );
		$out    = '';
		while ( ! gzeof( $handle ) ) {
			$out .= gzread( $handle, 65536 );
		}
		gzclose( $handle );

		$this->assertSame( strlen( $sql ), strlen( $out ) );
		$this->assertTrue( $sql === $out );
	}

	/**
	 * Read a whole (possibly multi-member) gzip file.
	 */
	private function gunzipFile( string $file ): string {
		$handle = gzopen( $file, 'rb' );
		$out    = '';
		while ( ! gzeof( $handle ) ) {
			$out .= gzread( $handle, 65536 );
		}
		gzclose( $handle );

		return $out;
	}

	/**
	 * A table of many rows with long values, so the SQL is larger than one gzip chunk.
	 */
	private function bigTables( int $count ): array {
		$rows = array();
		for ( $i = 1; $i <= $count; $i++ ) {
			$rows[] = array(
				'id'   => (string) $i,
				'name' => md5( (string) $i ) . str_repeat( 'x', 1000 ),
			);
		}

		return array( 'wp_things' => $this->simpleTable( $rows ) );
	}

	public function test_failed_page_select_throws_instead_of_truncating(): void {
		$wpdb = new FakeWpdb( array( 'wp_things' => $this->simpleTable( array( array( 'id' => '1', 'name' => 'a' ) ) ) ) );
		$wpdb->failNext( 'Lost connection', 'SELECT * FROM' );
		$dumper = new DbDumper( $wpdb, $this->sqlFile(), $this->gzFile() );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'Query failed: Lost connection' );
		$dumper->dump( $this->noLimit() );
	}

	public function test_failed_show_tables_throws(): void {
		$wpdb = new FakeWpdb( array( 'wp_things' => $this->simpleTable( array() ) ) );
		$wpdb->failNext( 'Server has gone away', 'SHOW FULL TABLES' );
		$dumper = new DbDumper( $wpdb, $this->sqlFile(), $this->gzFile() );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'Query failed: Server has gone away' );
		$dumper->dump( $this->noLimit() );
	}

	public function test_views_are_not_dumped(): void {
		$wpdb = new FakeWpdb(
			array(
				'wp_things' => $this->simpleTable( array( array( 'id' => '1', 'name' => 'a' ) ) ),
				'wp_view'   => array( 'type' => 'VIEW' ),
			)
		);
		$dumper = new DbDumper( $wpdb, $this->sqlFile(), $this->gzFile() );

		$this->assertTrue( $dumper->dump( $this->noLimit() ) );

		$this->assertSame( array( 'wp_things' ), $dumper->state()['tables'] );
		$this->assertStringNotContainsString( 'wp_view', file_get_contents( $this->sqlFile() ) );
		$this->assertContains( 'SHOW FULL TABLES', $wpdb->queries );
	}

	public function test_dump_then_compress_in_same_process_keeps_whole_sql(): void {
		$tables = $this->bigTables( 1500 );

		$reference = new DbDumper( new FakeWpdb( $tables ), $this->dir . '/ref.sql', $this->dir . '/ref.gz', array(), 100 );
		$this->assertTrue( $reference->dump( $this->noLimit() ) );
		$expected = file_get_contents( $this->dir . '/ref.sql' );
		$this->assertGreaterThan( 1048576, strlen( $expected ) );

		$dumper = new DbDumper( new FakeWpdb( $tables ), $this->sqlFile(), $this->gzFile(), array(), 100 );
		$this->assertFalse( $dumper->dump( $this->stopNow() ) );
		// Prime PHP's stat cache with a size that is too small once the dump completes.
		$this->assertLessThan( strlen( $expected ), filesize( $this->sqlFile() ) );
		$this->assertTrue( $dumper->dump( $this->noLimit() ) );
		$this->assertTrue( $dumper->compress( $this->noLimit() ) );

		$this->assertFileDoesNotExist( $this->sqlFile() );
		$this->assertTrue( $expected === $this->gunzipFile( $this->gzFile() ) );
	}

	public function test_resume_discards_bytes_written_after_saved_state(): void {
		$tables = $this->bigTables( 7 );

		$reference = new DbDumper( new FakeWpdb( $tables ), $this->dir . '/ref.sql', $this->gzFile(), array(), 3 );
		$this->assertTrue( $reference->dump( $this->noLimit() ) );

		$first = new DbDumper( new FakeWpdb( $tables ), $this->sqlFile(), $this->gzFile(), array(), 3 );
		$this->assertFalse( $first->dump( $this->stopNow() ) );
		$state = $first->state();
		$this->assertSame( filesize( $this->sqlFile() ), $state['sql_bytes'] );

		// A run that was killed after appending a page but before its state was saved.
		file_put_contents( $this->sqlFile(), "INSERT INTO `wp_things` (`id`) VALUES\n(999);\n", FILE_APPEND );

		$resumed = new DbDumper( new FakeWpdb( $tables ), $this->sqlFile(), $this->gzFile(), $state, 3 );
		$this->assertTrue( $resumed->dump( $this->noLimit() ) );

		$this->assertSame( file_get_contents( $this->dir . '/ref.sql' ), file_get_contents( $this->sqlFile() ) );
	}

	public function test_resume_throws_when_sql_file_is_shorter_than_state(): void {
		$tables = $this->bigTables( 7 );
		$first  = new DbDumper( new FakeWpdb( $tables ), $this->sqlFile(), $this->gzFile(), array(), 3 );
		$first->dump( $this->stopNow() );
		$state = $first->state();

		file_put_contents( $this->sqlFile(), 'short' );

		$this->expectException( \RuntimeException::class );
		new DbDumper( new FakeWpdb( $tables ), $this->sqlFile(), $this->gzFile(), $state, 3 );
	}

	public function test_compress_resume_discards_gzip_bytes_written_after_saved_state(): void {
		$sql = '';
		mt_srand( 7 );
		while ( strlen( $sql ) < 2600000 ) {
			$sql .= md5( (string) mt_rand() ) . "\n";
		}
		file_put_contents( $this->sqlFile(), $sql );

		$first = new DbDumper( new FakeWpdb( array() ), $this->sqlFile(), $this->gzFile(), array( 'phase' => 'compress' ) );
		$this->assertFalse( $first->compress( $this->stopNow() ) );
		$state = $first->state();
		$this->assertSame( filesize( $this->gzFile() ), $state['gz_bytes'] );

		file_put_contents( $this->gzFile(), 'junk from a killed run', FILE_APPEND );

		do {
			$dumper = new DbDumper( new FakeWpdb( array() ), $this->sqlFile(), $this->gzFile(), $state );
			$done   = $dumper->compress( $this->stopNow() );
			$state  = $dumper->state();
		} while ( ! $done );

		$this->assertTrue( $sql === $this->gunzipFile( $this->gzFile() ) );
	}

	public function test_compress_before_dump_finished_is_a_logic_error(): void {
		$dumper = new DbDumper( new FakeWpdb( array() ), $this->sqlFile(), $this->gzFile() );

		$this->expectException( \LogicException::class );
		$dumper->compress( $this->noLimit() );
	}
	/**
	 * A longtext table whose rows hold $bytes characters each.
	 */
	private function largeRowTable( int $rows, int $bytes ): array {
		$data = array();
		for ( $i = 1; $i <= $rows; $i++ ) {
			$data[] = array(
				'id'   => (string) $i,
				'body' => str_repeat( chr( 96 + $i % 26 ), $bytes ),
			);
		}

		return array(
			'create'  => 'CREATE TABLE `wp_posts` (...)',
			'columns' => array(
				array(
					'Field' => 'id',
					'Type'  => 'bigint(20) unsigned',
					'Key'   => 'PRI',
				),
				array(
					'Field' => 'body',
					'Type'  => 'longtext',
					'Key'   => '',
				),
			),
			'rows'    => $data,
		);
	}

	public function test_large_rows_are_split_into_statements_of_about_one_mib(): void {
		$rowBytes = 1048576;
		$wpdb     = new FakeWpdb( array( 'wp_posts' => $this->largeRowTable( 7, $rowBytes ) ) );
		$dumper   = new DbDumper( $wpdb, $this->sqlFile(), $this->gzFile() );

		$this->assertTrue( $dumper->dump( $this->noLimit() ) );

		$this->assertContains( 'SELECT * FROM `wp_posts` ORDER BY `id` LIMIT 200', $wpdb->queries, 'longtext: pages of 200 rows' );

		$sql        = file_get_contents( $this->sqlFile() );
		$statements = array_values(
			array_filter(
				explode( ";\n", $sql ),
				static function ( string $statement ): bool {
					return 0 === strpos( $statement, 'INSERT INTO' );
				}
			)
		);
		$this->assertCount( 7, $statements, '1 MiB rows: one row per statement' );
		foreach ( $statements as $statement ) {
			$this->assertLessThanOrEqual( DbDumper::MAX_STATEMENT_BYTES + $rowBytes + 100, strlen( $statement ) );
		}
		$this->assertSame( 7, $dumper->state()['rows'] );
		for ( $i = 1; $i <= 7; $i++ ) {
			$this->assertSame( 1, substr_count( $sql, '(' . $i . ", '" . str_repeat( chr( 96 + $i % 26 ), $rowBytes ) . "')" ), 'row ' . $i . ' is dumped exactly once' );
		}
	}

	public function test_statements_pack_rows_up_to_one_mib(): void {
		$rowBytes = 300000;
		$wpdb     = new FakeWpdb( array( 'wp_posts' => $this->largeRowTable( 10, $rowBytes ) ) );
		$dumper   = new DbDumper( $wpdb, $this->sqlFile(), $this->gzFile() );

		$this->assertTrue( $dumper->dump( $this->noLimit() ) );

		$sql = file_get_contents( $this->sqlFile() );
		// Three 300 KB rows fit in 1 MiB, a fourth does not: 3 + 3 + 3 + 1.
		$this->assertSame( 4, preg_match_all( '/^INSERT INTO `wp_posts`/m', $sql ) );
		foreach ( explode( ";\n", $sql ) as $statement ) {
			$this->assertLessThanOrEqual( DbDumper::MAX_STATEMENT_BYTES, strlen( $statement ) );
		}
		$this->assertSame( 10, preg_match_all( '/^\(\d+, \'/m', $sql ) );
	}

	public function test_large_row_page_is_kept_across_resume_and_bounded_by_batch(): void {
		$wpdb   = new FakeWpdb( array( 'wp_posts' => $this->largeRowTable( 5, 10 ) ) );
		$dumper = new DbDumper( $wpdb, $this->sqlFile(), $this->gzFile(), array(), 2 );
		$this->assertFalse( $dumper->dump( $this->stopNow() ) );
		$this->assertSame( 2, $dumper->state()['page'], 'never above the batch size' );

		$wpdb2   = new FakeWpdb( array( 'wp_posts' => $this->largeRowTable( 5, 10 ) ) );
		$resumed = new DbDumper( $wpdb2, $this->sqlFile(), $this->gzFile(), json_decode( json_encode( $dumper->state() ), true ), 2 );
		$this->assertTrue( $resumed->dump( $this->noLimit() ) );
		$this->assertSame( 5, $resumed->state()['rows'] );
	}
	/**
	 * wp_things with ids 2, 4, ..., 20.
	 */
	private function evenRows(): array {
		$rows = array();
		for ( $i = 2; $i <= 20; $i += 2 ) {
			$rows[] = array(
				'id'   => (string) $i,
				'name' => 'n' . $i,
			);
		}

		return $rows;
	}

	/**
	 * Dump the first page (3 rows), let $change modify the live table, then resume to the end.
	 *
	 * @return int[] Dumped ids in file order.
	 */
	private function dumpAcrossAChange( callable $change ): array {
		$wpdb   = new FakeWpdb( array( 'wp_things' => $this->simpleTable( $this->evenRows() ) ) );
		$dumper = new DbDumper( $wpdb, $this->sqlFile(), $this->gzFile(), array(), 3 );
		$this->assertFalse( $dumper->dump( $this->stopNow() ) );
		$this->assertSame( array( base64_encode( '6' ) ), $dumper->state()['last_key'] );

		$change( $wpdb );

		$resumed = new DbDumper( $wpdb, $this->sqlFile(), $this->gzFile(), json_decode( json_encode( $dumper->state() ), true ), 3 );
		$this->assertTrue( $resumed->dump( $this->noLimit() ) );

		preg_match_all( "/^\\((\\d+), 'n\\d+'\\)[,;]$/m", (string) file_get_contents( $this->sqlFile() ), $m );

		return array_map( 'intval', $m[1] );
	}

	public function test_row_inserted_below_the_cursor_between_pages_causes_no_duplicate(): void {
		$ids = $this->dumpAcrossAChange(
			static function ( FakeWpdb $wpdb ): void {
				$wpdb->insertRow(
					'wp_things',
					array(
						'id'   => '1',
						'name' => 'n1',
					)
				);
			}
		);

		// With OFFSET 3 the second page would have started at id 6 again.
		$this->assertSame( array( 2, 4, 6, 8, 10, 12, 14, 16, 18, 20 ), $ids );
	}

	public function test_row_deleted_below_the_cursor_between_pages_causes_no_skip(): void {
		$ids = $this->dumpAcrossAChange(
			static function ( FakeWpdb $wpdb ): void {
				$wpdb->deleteRows(
					'wp_things',
					static function ( array $row ): bool {
						return '4' === $row['id'];
					}
				);
			}
		);

		// With OFFSET 3 the second page would have started at id 10, skipping 8.
		$this->assertSame( array( 2, 4, 6, 8, 10, 12, 14, 16, 18, 20 ), $ids );
	}

	public function test_rows_inserted_above_the_cursor_are_dumped_in_key_order(): void {
		$ids = $this->dumpAcrossAChange(
			static function ( FakeWpdb $wpdb ): void {
				$wpdb->insertRow(
					'wp_things',
					array(
						'id'   => '7',
						'name' => 'n7',
					)
				);
			}
		);

		$this->assertSame( array( 2, 4, 6, 7, 8, 10, 12, 14, 16, 18, 20 ), $ids );
	}

	public function test_composite_and_string_keys_use_a_row_constructor(): void {
		$rows = array();
		foreach ( array( 'a', "o'b", 'z' ) as $name ) {
			for ( $i = 1; $i <= 2; $i++ ) {
				$rows[] = array(
					'grp'  => $name,
					'n'    => (string) $i,
				);
			}
		}
		$table = array(
			'create'  => 'CREATE TABLE `wp_meta` (...)',
			'columns' => array(
				array(
					'Field' => 'grp',
					'Type'  => 'varchar(20)',
					'Key'   => 'PRI',
				),
				array(
					'Field' => 'n',
					'Type'  => 'int(11)',
					'Key'   => 'PRI',
				),
			),
			'rows'    => $rows,
		);
		$wpdb  = new FakeWpdb( array( 'wp_meta' => $table ) );

		$state = array();
		do {
			$dumper = new DbDumper( $wpdb, $this->sqlFile(), $this->gzFile(), $state, 4 );
			$done   = $dumper->dump( $this->stopNow() );
			$state  = json_decode( json_encode( $dumper->state() ), true );
		} while ( ! $done );

		$this->assertContains( "SELECT * FROM `wp_meta` WHERE (`grp`, `n`) > ('o\\'b', 2) ORDER BY `grp`, `n` LIMIT 4", $wpdb->queries );
		$this->assertSame( 6, $state['rows'] );
		$sql = (string) file_get_contents( $this->sqlFile() );
		$this->assertSame( 1, substr_count( $sql, "('o\\'b', 2)" ) );
		$this->assertSame( 1, substr_count( $sql, "('z', 2)" ) );
	}

	public function test_binary_primary_keys_survive_the_state_round_trip(): void {
		$keys  = array( "\x00\x01" . str_repeat( "\xff", 14 ), "\x7f" . str_repeat( "\xfe", 15 ), str_repeat( "\xff", 16 ) );
		$rows  = array();
		foreach ( $keys as $i => $key ) {
			$rows[] = array(
				'hash' => $key,
				'n'    => (string) ( 10 + $i ),
			);
		}
		$table = array(
			'create'  => 'CREATE TABLE `wp_filemods` (...)',
			'columns' => array(
				array(
					'Field' => 'hash',
					'Type'  => 'binary(16)',
					'Key'   => 'PRI',
				),
				array(
					'Field' => 'n',
					'Type'  => 'int(11)',
					'Key'   => '',
				),
			),
			'rows'    => $rows,
		);
		$wpdb  = new FakeWpdb( array( 'wp_filemods' => $table ) );

		$state = array();
		$runs  = 0;
		do {
			$dumper = new DbDumper( $wpdb, $this->sqlFile(), $this->gzFile(), $state, 2 );
			$done   = $dumper->dump( $this->stopNow() );
			$json   = json_encode( $dumper->state() );
			$this->assertNotFalse( $json, 'the state is valid JSON: ' . json_last_error_msg() );
			$state = json_decode( $json, true );
			++$runs;
		} while ( ! $done && $runs < 10 );

		$this->assertTrue( $done );
		$this->assertContains( 'SELECT * FROM `wp_filemods` WHERE `hash` > 0x7f' . str_repeat( 'fe', 15 ) . ' ORDER BY `hash` LIMIT 2', $wpdb->queries );
		$this->assertSame( 3, $state['rows'] );
		$sql = (string) file_get_contents( $this->sqlFile() );
		foreach ( $keys as $i => $key ) {
			$this->assertSame( 1, substr_count( $sql, '(0x' . bin2hex( $key ) . ', ' . ( 10 + $i ) . ')' ), "row $i dumped exactly once" );
		}
	}

	public function test_numeric_and_string_keys_keep_their_literals_after_encoding(): void {
		$rows = array();
		foreach ( array( 'é', 'ü' ) as $name ) {
			for ( $i = 9; $i <= 10; $i++ ) {
				$rows[] = array(
					'grp' => $name,
					'n'   => (string) $i,
				);
			}
		}
		$table = array(
			'create'  => 'CREATE TABLE `wp_meta` (...)',
			'columns' => array(
				array(
					'Field' => 'grp',
					'Type'  => 'varchar(20)',
					'Key'   => 'PRI',
				),
				array(
					'Field' => 'n',
					'Type'  => 'bigint(20)',
					'Key'   => 'PRI',
				),
			),
			'rows'    => $rows,
		);
		$wpdb  = new FakeWpdb( array( 'wp_meta' => $table ) );

		$state = array();
		do {
			$dumper = new DbDumper( $wpdb, $this->sqlFile(), $this->gzFile(), $state, 1 );
			$done   = $dumper->dump( $this->stopNow() );
			$state  = json_decode( (string) json_encode( $dumper->state() ), true );
		} while ( ! $done );

		// Numbers stay unquoted (numeric comparison: 10 after 9), strings quoted.
		$this->assertContains( "SELECT * FROM `wp_meta` WHERE (`grp`, `n`) > ('é', 9) ORDER BY `grp`, `n` LIMIT 1", $wpdb->queries );
		$this->assertContains( "SELECT * FROM `wp_meta` WHERE (`grp`, `n`) > ('é', 10) ORDER BY `grp`, `n` LIMIT 1", $wpdb->queries );
		$this->assertSame( 4, $state['rows'] );
	}

	public function test_a_corrupted_key_cursor_is_refused(): void {
		$wpdb   = new FakeWpdb( array( 'wp_things' => $this->simpleTable( $this->evenRows() ) ) );
		$dumper = new DbDumper( $wpdb, $this->sqlFile(), $this->gzFile(), array(), 3 );
		$this->assertFalse( $dumper->dump( $this->stopNow() ) );

		$state             = $dumper->state();
		$state['last_key'] = array( '***' );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'Invalid key cursor in the dump state.' );
		( new DbDumper( $wpdb, $this->sqlFile(), $this->gzFile(), $state, 3 ) )->dump( $this->noLimit() );
	}

	public function test_state_saved_before_keyset_paging_resumes_with_its_offset(): void {
		$wpdb   = new FakeWpdb( array( 'wp_things' => $this->simpleTable( $this->evenRows() ) ) );
		$dumper = new DbDumper( $wpdb, $this->sqlFile(), $this->gzFile(), array(), 3 );
		$this->assertFalse( $dumper->dump( $this->stopNow() ) );

		// A job started by the previous version: offset only, no last_key.
		$state = $dumper->state();
		unset( $state['last_key'], $state['page'] );

		$resumed = new DbDumper( $wpdb, $this->sqlFile(), $this->gzFile(), $state, 3 );
		$this->assertTrue( $resumed->dump( $this->noLimit() ) );

		$this->assertContains( 'SELECT * FROM `wp_things` ORDER BY `id` LIMIT 3 OFFSET 3', $wpdb->queries );
		$this->assertContains( 'SELECT * FROM `wp_things` WHERE `id` > 12 ORDER BY `id` LIMIT 3', $wpdb->queries );
		$this->assertSame( 10, $resumed->state()['rows'] );
	}
}
