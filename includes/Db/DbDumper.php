<?php

namespace WpPcloudBackup\Db;

/**
 * Resumable pure-PHP SQL dump, followed by a resumable gzip stage.
 *
 * Both stages work in short runs: dump() and compress() return false when the
 * time budget is used up, and state() returns what is needed to continue
 * with a new instance. The dump walks tables in name order and pages each
 * table by primary key (keyset: WHERE (pk...) > (last key...) ORDER BY pk LIMIT n,
 * so rows inserted or deleted between runs neither repeat nor shift later rows),
 * or with LIMIT/OFFSET when the table has no primary key, writing
 * INSERT statements of at most MAX_STATEMENT_BYTES (one per page for small rows).
 * Tables with text/blob/json columns use pages of LARGE_ROW_PAGE rows.
 * Pure PHP: no WordPress functions are used.
 */
class DbDumper {

	/**
	 * Bytes read from the SQL file per gzip chunk.
	 */
	const CHUNK_SIZE = 1048576;

	/**
	 * An INSERT statement is closed once it reaches this size, so a page of large rows never
	 * becomes one huge statement (memory while dumping, max_allowed_packet when restoring).
	 * A single row larger than this still becomes one statement of its own.
	 */
	const MAX_STATEMENT_BYTES = 1048576;

	/**
	 * Rows per SELECT page for tables with text, blob or json columns (rows may be large).
	 */
	const LARGE_ROW_PAGE = 200;

	/**
	 * Database access object.
	 *
	 * @var \wpdb
	 */
	private $wpdb;

	/**
	 * Path of the plain SQL file.
	 *
	 * @var string
	 */
	private $sqlFile;

	/**
	 * Path of the gzip file.
	 *
	 * @var string
	 */
	private $gzFile;

	/**
	 * Maximum rows per SELECT page (and per INSERT statement).
	 *
	 * @var int
	 */
	private $batch;

	/**
	 * Progress state.
	 *
	 * @var array
	 */
	private $state;

	/**
	 * Create a dumper, optionally restoring a previously saved state.
	 *
	 * @param \wpdb  $wpdb    Database access object.
	 * @param string $sqlFile Path of the plain SQL file (appended to).
	 * @param string $gzFile  Path of the gzip file (appended to).
	 * @param array  $state   A value returned by state(), to resume from.
	 * @param int    $batch   Rows per INSERT statement (must be positive).
	 *
	 * @throws \InvalidArgumentException If the batch size is not positive.
	 * @throws \RuntimeException         If an output file is shorter than the saved state says.
	 */
	public function __construct( $wpdb, string $sqlFile, string $gzFile, array $state = array(), int $batch = 1000 ) {
		if ( $batch < 1 ) {
			throw new \InvalidArgumentException( 'Batch size must be positive.' );
		}

		$this->wpdb    = $wpdb;
		$this->sqlFile = $sqlFile;
		$this->gzFile  = $gzFile;
		$this->batch   = $batch;
		$this->state   = array_merge(
			array(
				'tables'          => array(),
				'table_index'     => 0,
				'offset'          => 0,
				'phase'           => 'dump',
				'compress_offset' => 0,
				'rows'            => 0,
				'columns'         => array(),
				'pk'              => array(),
				'types'           => array(),
				'page'            => 0,
				'last_key'        => null,
				'sql_bytes'       => 0,
				'gz_bytes'        => 0,
			),
			$state
		);

		if ( 'dump' === $this->state['phase'] ) {
			$this->truncateTo( $this->sqlFile, $this->state['sql_bytes'] );
		} elseif ( 'compress' === $this->state['phase'] ) {
			$this->truncateTo( $this->gzFile, $this->state['gz_bytes'] );
		}
	}

	/**
	 * Progress state, to be persisted and passed to a new instance.
	 *
	 * Keys: tables, table_index, offset, phase ('dump'|'compress'|'done'),
	 * compress_offset, rows, columns, pk, types, page (rows per SELECT of the
	 * current table), last_key (primary key values of the last dumped row of the
	 * current table, null before the first page), sql_bytes (bytes written to
	 * the SQL file), gz_bytes (bytes of the gzip file after the last run).
	 * On resume, output files are truncated back to these sizes.
	 */
	public function state(): array {
		return $this->state;
	}

	/**
	 * Dump tables until finished or the time budget is used up.
	 *
	 * @param callable $timeUp Returns true when the run should stop; checked after each page.
	 * @return bool True when every table and the footer have been written.
	 *
	 * @throws \RuntimeException On a database or file error.
	 */
	public function dump( callable $timeUp ): bool {
		if ( 'dump' !== $this->state['phase'] ) {
			return true;
		}

		if ( array() === $this->state['tables'] ) {
			$tables = array();
			foreach ( $this->results( 'SHOW FULL TABLES' ) as $row ) {
				$row = array_values( $row );
				if ( isset( $row[1] ) && 'BASE TABLE' === $row[1] ) {
					$tables[] = (string) $row[0];
				}
			}
			sort( $tables );
			$this->state['tables'] = $tables;
		}

		if ( 0 === $this->state['table_index'] && 0 === $this->state['offset'] && 0 === $this->state['sql_bytes'] ) {
			$this->append( "-- WP pCloud Backup SQL dump\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\nSET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\n" );
		}

		while ( $this->state['table_index'] < count( $this->state['tables'] ) ) {
			$table = $this->state['tables'][ $this->state['table_index'] ];

			if ( 0 === $this->state['offset'] ) {
				$this->startTable( $table );
			}

			$page = $this->pageSize();
			$rows = $this->fetchPage( $table, $page );
			$n    = count( $rows );

			if ( $n > 0 ) {
				$this->appendInserts( $table, $rows );
				$this->state['offset'] += $n;
				$this->state['rows']   += $n;

				if ( array() !== $this->state['pk'] ) {
					$last = $rows[ $n - 1 ];
					$key  = array();
					foreach ( $this->state['pk'] as $column ) {
						if ( ! isset( $last[ $column ] ) ) {
							throw new \RuntimeException( 'Primary key column missing in the result of ' . $table );
						}
						$key[] = (string) $last[ $column ];
					}
					$this->state['last_key'] = $key;
				}
			}
			unset( $rows );

			if ( $n < $page ) {
				++$this->state['table_index'];
				$this->state['offset']  = 0;
				$this->state['columns'] = array();
				$this->state['pk']      = array();
				$this->state['types']   = array();
				$this->state['page']     = 0;
				$this->state['last_key'] = null;
			}

			if ( $timeUp() ) {
				return false;
			}
		}

		$this->append( "SET FOREIGN_KEY_CHECKS=1;\n" );
		$this->state['phase'] = 'compress';

		return true;
	}

	/**
	 * Gzip the SQL file in 1 MiB chunks, appending one gzip member per run.
	 *
	 * Removes the SQL file once it has been written completely.
	 *
	 * @param callable $timeUp Returns true when the run should stop; checked between chunks.
	 * @return bool True when the whole file is compressed and the SQL file is removed.
	 *
	 * @throws \RuntimeException On a file error.
	 */
	public function compress( callable $timeUp ): bool {
		if ( 'done' === $this->state['phase'] ) {
			return true;
		}

		if ( 'compress' !== $this->state['phase'] ) {
			throw new \LogicException( 'compress() called before the dump finished.' );
		}

		clearstatcache( true, $this->sqlFile );

		$in = fopen( $this->sqlFile, 'rb' );
		if ( false === $in ) {
			throw new \RuntimeException( 'Cannot open SQL file: ' . $this->sqlFile );
		}

		$gz = gzopen( $this->gzFile, 'ab' );
		if ( false === $gz ) {
			fclose( $in );
			throw new \RuntimeException( 'Cannot open gzip file: ' . $this->gzFile );
		}

		fseek( $in, $this->state['compress_offset'] );

		$reachedEof = false;
		while ( true ) {
			$chunk = fread( $in, self::CHUNK_SIZE );
			if ( false === $chunk || ( '' === $chunk && ! feof( $in ) ) ) {
				fclose( $in );
				gzclose( $gz );
				throw new \RuntimeException( 'Cannot read SQL file: ' . $this->sqlFile );
			}

			if ( '' !== $chunk ) {
				if ( gzwrite( $gz, $chunk ) !== strlen( $chunk ) ) {
					fclose( $in );
					gzclose( $gz );
					throw new \RuntimeException( 'Cannot write gzip file: ' . $this->gzFile );
				}
				$this->state['compress_offset'] += strlen( $chunk );
			}

			if ( feof( $in ) ) {
				$reachedEof = true;
				break;
			}

			if ( $timeUp() ) {
				break;
			}
		}

		fclose( $in );
		gzclose( $gz );

		clearstatcache( true, $this->gzFile );
		$this->state['gz_bytes'] = (int) filesize( $this->gzFile );

		if ( ! $reachedEof ) {
			return false;
		}

		clearstatcache( true, $this->sqlFile );
		if ( filesize( $this->sqlFile ) !== $this->state['compress_offset'] ) {
			throw new \RuntimeException( 'SQL file changed while compressing: ' . $this->sqlFile );
		}

		unlink( $this->sqlFile );
		$this->state['phase'] = 'done';

		return true;
	}

	/**
	 * Write DROP and CREATE for a table and cache its column metadata in the state.
	 */
	private function startTable( string $table ): void {
		$create = $this->row( 'SHOW CREATE TABLE ' . $this->ident( $table ) );
		if ( ! isset( $create['Create Table'] ) ) {
			throw new \RuntimeException( 'SHOW CREATE TABLE failed for ' . $table );
		}

		$columns = $this->results( 'SHOW COLUMNS FROM ' . $this->ident( $table ) );

		$this->state['columns'] = array();
		$this->state['pk']      = array();
		$this->state['types']   = array();
		$this->state['page']     = $this->batch;
		$this->state['last_key'] = null;
		foreach ( $columns as $column ) {
			$this->state['columns'][] = $column['Field'];
			if ( 'PRI' === $column['Key'] ) {
				$this->state['pk'][] = $column['Field'];
			}
			$this->state['types'][ $column['Field'] ] = $this->classify( $column['Type'] );
			if ( preg_match( '/(text|blob|json)\b/i', (string) $column['Type'] ) ) {
				$this->state['page'] = min( $this->batch, self::LARGE_ROW_PAGE );
			}
		}

		$this->append( 'DROP TABLE IF EXISTS ' . $this->ident( $table ) . ";\n" . $create['Create Table'] . ";\n" );
	}

	/**
	 * Rows per SELECT page of the current table (chosen in startTable(), never above $batch).
	 */
	private function pageSize(): int {
		$page = (int) $this->state['page'];

		return $page > 0 ? min( $page, $this->batch ) : $this->batch;
	}

	/**
	 * Select the next page of rows of the current table.
	 *
	 * @return array[]
	 */
	private function fetchPage( string $table, int $page ): array {
		$sql   = 'SELECT * FROM ' . $this->ident( $table );
		$where = array();
		$pk    = $this->state['pk'];
		$key   = $this->state['last_key'];

		if ( $table === $this->wpdb->options ) {
			$where[] = "option_name NOT LIKE '\\_transient\\_%' AND option_name NOT LIKE '\\_site\\_transient\\_%'";
		}

		$keyset = array() !== $pk && is_array( $key ) && count( $key ) === count( $pk );
		if ( $keyset ) {
			$where[] = $this->keyCondition( $pk, $key );
		}

		if ( array() !== $where ) {
			$sql .= ' WHERE ' . implode( ' AND ', $where );
		}

		if ( array() !== $pk ) {
			$sql .= ' ORDER BY ' . implode( ', ', array_map( array( $this, 'ident' ), $pk ) );
		}

		$sql .= ' LIMIT ' . $page;

		// Without a primary key (or for a state saved before keyset paging) the offset is the cursor.
		if ( ! $keyset && ( array() === $pk || $this->state['offset'] > 0 ) ) {
			$sql .= ' OFFSET ' . $this->state['offset'];
		}

		return $this->results( $sql );
	}

	/**
	 * "`a` > 1" or "(`a`, `b`) > (1, 'x')": rows after the last dumped key, in ORDER BY pk order.
	 *
	 * @param string[] $pk  Primary key columns.
	 * @param string[] $key Values of those columns in the last dumped row.
	 */
	private function keyCondition( array $pk, array $key ): string {
		$columns = array_map( array( $this, 'ident' ), $pk );
		$values  = array();
		foreach ( array_values( $pk ) as $i => $column ) {
			$values[] = $this->literal( $key[ $i ], $this->state['types'][ $column ] ?? 'string' );
		}

		if ( 1 === count( $pk ) ) {
			return $columns[0] . ' > ' . $values[0];
		}

		return '(' . implode( ', ', $columns ) . ') > (' . implode( ', ', $values ) . ')';
	}

	/**
	 * Append the rows as multi-row INSERT statements, starting a new statement before one
	 * would grow past MAX_STATEMENT_BYTES.
	 *
	 * @param array[] $rows Rows as column => value maps.
	 */
	private function appendInserts( string $table, array $rows ): void {
		$columns = $this->state['columns'];
		$head    = 'INSERT INTO ' . $this->ident( $table )
			. ' (' . implode( ', ', array_map( array( $this, 'ident' ), $columns ) ) . ") VALUES\n";
		$buffer  = '';

		foreach ( $rows as $row ) {
			$values = array();
			foreach ( $columns as $column ) {
				$values[] = $this->literal( $row[ $column ] ?? null, $this->state['types'][ $column ] );
			}
			$tuple = '(' . implode( ', ', $values ) . ')';
			unset( $values );

			if ( '' !== $buffer && strlen( $buffer ) + 2 + strlen( $tuple ) > self::MAX_STATEMENT_BYTES ) {
				$this->append( $buffer . ";\n" );
				$buffer = '';
			}

			$buffer .= '' === $buffer ? $head . $tuple : ",\n" . $tuple;
		}

		if ( '' !== $buffer ) {
			$this->append( $buffer . ";\n" );
		}
	}

	/**
	 * Classify a SHOW COLUMNS type as 'numeric', 'binary' or 'string'.
	 */
	private function classify( string $type ): string {
		if ( preg_match( '/^(tinyblob|blob|mediumblob|longblob|binary|varbinary|bit)\b/i', $type ) ) {
			return 'binary';
		}

		if ( preg_match( '/^(tinyint|smallint|mediumint|int|integer|bigint|decimal|numeric|float|double|real|year)\b/i', $type ) ) {
			return 'numeric';
		}

		return 'string';
	}

	/**
	 * Format one value as an SQL literal.
	 *
	 * @param string|null $value Value as returned by the database.
	 * @param string      $type  One of 'numeric', 'binary', 'string'.
	 */
	private function literal( $value, string $type ): string {
		if ( null === $value ) {
			return 'NULL';
		}

		$value = (string) $value;

		if ( 'numeric' === $type && preg_match( '/^-?\d+(\.\d+)?([eE][-+]?\d+)?$/', $value ) ) {
			return $value;
		}

		if ( 'binary' === $type ) {
			return '' === $value ? "''" : '0x' . bin2hex( $value );
		}

		return "'" . strtr(
			$value,
			array(
				'\\'   => '\\\\',
				"'"    => "\\'",
				'"'    => '\\"',
				"\n"   => '\\n',
				"\r"   => '\\r',
				"\0"   => '\\0',
				"\x1a" => '\\Z',
			)
		) . "'";
	}

	/**
	 * Quote an identifier with backticks.
	 */
	private function ident( string $name ): string {
		return '`' . str_replace( '`', '``', $name ) . '`';
	}

	/**
	 * Run get_results() and fail loudly on a query error.
	 *
	 * @return array[]
	 *
	 * @throws \RuntimeException If the query failed.
	 */
	private function results( string $sql ): array {
		$rows = $this->wpdb->get_results( $sql, 'ARRAY_A' );
		$this->assertNoError();

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Run get_row() and fail loudly on a query error.
	 *
	 * @throws \RuntimeException If the query failed.
	 */
	private function row( string $sql ): array {
		$row = $this->wpdb->get_row( $sql, 'ARRAY_A' );
		$this->assertNoError();

		return is_array( $row ) ? $row : array();
	}

	/**
	 * Wpdb returns an empty result on error, so check last_error after every query.
	 *
	 * @throws \RuntimeException If the last query failed.
	 */
	private function assertNoError(): void {
		$error = (string) $this->wpdb->last_error;
		if ( '' !== $error ) {
			throw new \RuntimeException( 'Query failed: ' . $error );
		}
	}

	/**
	 * Cut an output file back to the size recorded in the state.
	 *
	 * @throws \RuntimeException If the file is missing or shorter than recorded.
	 */
	private function truncateTo( string $file, int $bytes ): void {
		clearstatcache( true, $file );

		if ( ! file_exists( $file ) ) {
			if ( $bytes > 0 ) {
				throw new \RuntimeException( 'Output file is missing: ' . $file );
			}
			return;
		}

		$size = filesize( $file );
		if ( $size < $bytes ) {
			throw new \RuntimeException( 'Output file is shorter than the saved state: ' . $file );
		}

		if ( $size > $bytes ) {
			$handle = fopen( $file, 'r+b' );
			if ( false === $handle || ! ftruncate( $handle, $bytes ) ) {
				throw new \RuntimeException( 'Cannot truncate output file: ' . $file );
			}
			fclose( $handle );
		}
	}

	/**
	 * Append text to the SQL file.
	 *
	 * @throws \RuntimeException If the write fails.
	 */
	private function append( string $text ): void {
		$written = file_put_contents( $this->sqlFile, $text, FILE_APPEND );
		if ( strlen( $text ) !== $written ) {
			throw new \RuntimeException( 'Cannot write SQL file: ' . $this->sqlFile );
		}

		$this->state['sql_bytes'] += $written;
	}
}
