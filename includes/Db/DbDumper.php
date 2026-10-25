<?php

namespace WpPcloudBackup\Db;

/**
 * Resumable pure-PHP SQL dump, followed by a resumable gzip stage.
 *
 * Both stages work in short runs: dump() and compress() return false when the
 * time budget is used up, and state() returns what is needed to continue
 * with a new instance. The dump walks tables in name order and pages each
 * table with LIMIT/OFFSET (ordered by primary key when there is one), writing
 * one INSERT statement per page. Pure PHP: no WordPress functions are used.
 */
class DbDumper {

	/**
	 * Bytes read from the SQL file per gzip chunk.
	 */
	const CHUNK_SIZE = 1048576;

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
	 * Maximum rows per INSERT statement (and per SELECT page).
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
			),
			$state
		);
	}

	/**
	 * Progress state, to be persisted and passed to a new instance.
	 *
	 * Keys: tables, table_index, offset, phase ('dump'|'compress'|'done'),
	 * compress_offset, rows, columns, pk, types.
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
			$tables = $this->wpdb->get_col( 'SHOW TABLES' );
			if ( ! is_array( $tables ) ) {
				throw new \RuntimeException( 'SHOW TABLES failed.' );
			}
			sort( $tables );
			$this->state['tables'] = array_values( $tables );
		}

		if ( 0 === $this->state['table_index'] && 0 === $this->state['offset'] && ! file_exists( $this->sqlFile ) ) {
			$this->append( "-- WP pCloud Backup SQL dump\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\nSET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\n" );
		}

		while ( $this->state['table_index'] < count( $this->state['tables'] ) ) {
			$table = $this->state['tables'][ $this->state['table_index'] ];

			if ( 0 === $this->state['offset'] ) {
				$this->startTable( $table );
			}

			$rows = $this->fetchPage( $table );
			$n    = count( $rows );

			if ( $n > 0 ) {
				$this->append( $this->insertStatement( $table, $rows ) );
				$this->state['offset'] += $n;
				$this->state['rows']   += $n;
			}

			if ( $n < $this->batch ) {
				++$this->state['table_index'];
				$this->state['offset']  = 0;
				$this->state['columns'] = array();
				$this->state['pk']      = array();
				$this->state['types']   = array();
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

		$in = fopen( $this->sqlFile, 'rb' );
		if ( false === $in ) {
			throw new \RuntimeException( 'Cannot open SQL file: ' . $this->sqlFile );
		}

		$gz = gzopen( $this->gzFile, 'ab' );
		if ( false === $gz ) {
			fclose( $in );
			throw new \RuntimeException( 'Cannot open gzip file: ' . $this->gzFile );
		}

		$size     = filesize( $this->sqlFile );
		$finished = true;

		fseek( $in, $this->state['compress_offset'] );
		while ( $this->state['compress_offset'] < $size ) {
			$chunk = fread( $in, self::CHUNK_SIZE );
			if ( false === $chunk || '' === $chunk ) {
				break;
			}

			if ( gzwrite( $gz, $chunk ) !== strlen( $chunk ) ) {
				fclose( $in );
				gzclose( $gz );
				throw new \RuntimeException( 'Cannot write gzip file: ' . $this->gzFile );
			}
			$this->state['compress_offset'] += strlen( $chunk );

			if ( $this->state['compress_offset'] < $size && $timeUp() ) {
				$finished = false;
				break;
			}
		}

		fclose( $in );
		gzclose( $gz );

		if ( ! $finished ) {
			return false;
		}

		unlink( $this->sqlFile );
		$this->state['phase'] = 'done';

		return true;
	}

	/**
	 * Write DROP and CREATE for a table and cache its column metadata in the state.
	 */
	private function startTable( string $table ): void {
		$create = $this->wpdb->get_row( 'SHOW CREATE TABLE ' . $this->ident( $table ), 'ARRAY_A' );
		if ( ! is_array( $create ) || ! isset( $create['Create Table'] ) ) {
			throw new \RuntimeException( 'SHOW CREATE TABLE failed for ' . $table );
		}

		$columns = $this->wpdb->get_results( 'SHOW COLUMNS FROM ' . $this->ident( $table ), 'ARRAY_A' );
		if ( ! is_array( $columns ) ) {
			throw new \RuntimeException( 'SHOW COLUMNS failed for ' . $table );
		}

		$this->state['columns'] = array();
		$this->state['pk']      = array();
		$this->state['types']   = array();
		foreach ( $columns as $column ) {
			$this->state['columns'][] = $column['Field'];
			if ( 'PRI' === $column['Key'] ) {
				$this->state['pk'][] = $column['Field'];
			}
			$this->state['types'][ $column['Field'] ] = $this->classify( $column['Type'] );
		}

		$this->append( 'DROP TABLE IF EXISTS ' . $this->ident( $table ) . ";\n" . $create['Create Table'] . ";\n" );
	}

	/**
	 * Select the next page of rows of the current table.
	 *
	 * @return array[]
	 */
	private function fetchPage( string $table ): array {
		$sql = 'SELECT * FROM ' . $this->ident( $table );

		if ( $table === $this->wpdb->options ) {
			$sql .= " WHERE option_name NOT LIKE '\\_transient\\_%' AND option_name NOT LIKE '\\_site\\_transient\\_%'";
		}

		if ( array() !== $this->state['pk'] ) {
			$sql .= ' ORDER BY ' . implode( ', ', array_map( array( $this, 'ident' ), $this->state['pk'] ) );
		}

		$sql .= ' LIMIT ' . $this->batch . ' OFFSET ' . $this->state['offset'];

		$rows = $this->wpdb->get_results( $sql, 'ARRAY_A' );
		if ( ! is_array( $rows ) ) {
			throw new \RuntimeException( 'SELECT failed for ' . $table );
		}

		return $rows;
	}

	/**
	 * Build one multi-row INSERT statement.
	 *
	 * @param array[] $rows Rows as column => value maps.
	 */
	private function insertStatement( string $table, array $rows ): string {
		$columns = $this->state['columns'];
		$tuples  = array();

		foreach ( $rows as $row ) {
			$values = array();
			foreach ( $columns as $column ) {
				$values[] = $this->literal( $row[ $column ] ?? null, $this->state['types'][ $column ] );
			}
			$tuples[] = '(' . implode( ', ', $values ) . ')';
		}

		return 'INSERT INTO ' . $this->ident( $table )
			. ' (' . implode( ', ', array_map( array( $this, 'ident' ), $columns ) ) . ") VALUES\n"
			. implode( ",\n", $tuples ) . ";\n";
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
	 * Append text to the SQL file.
	 *
	 * @throws \RuntimeException If the write fails.
	 */
	private function append( string $text ): void {
		if ( false === file_put_contents( $this->sqlFile, $text, FILE_APPEND ) ) {
			throw new \RuntimeException( 'Cannot write SQL file: ' . $this->sqlFile );
		}
	}
}
