<?php

namespace WpPcloudBackup\Tests\Support;

/**
 * Minimal in-memory stand-in for \wpdb, understanding only the SQL that DbDumper issues.
 *
 * Tables are described as:
 * array( 'name' => array( 'create' => 'CREATE TABLE ...', 'columns' => array( array( 'Field' => ..., 'Type' => ..., 'Key' => ... ) ), 'rows' => array( array( col => value ) ) ) )
 * An optional 'type' => 'VIEW' marks a view (default 'BASE TABLE').
 *
 * Like the real wpdb, a failing query sets $last_error and returns an empty result.
 */
final class FakeWpdb {

	/**
	 * Options table name.
	 *
	 * @var string
	 */
	public $options = 'wp_options';

	/**
	 * Table prefix.
	 *
	 * @var string
	 */
	public $prefix = 'wp_';

	/**
	 * Error message of the last query, as on \wpdb ('' when it succeeded).
	 *
	 * @var string
	 */
	public $last_error = '';

	/**
	 * Every SQL string received, in order.
	 *
	 * @var string[]
	 */
	public $queries = array();

	/**
	 * Table definitions.
	 *
	 * @var array
	 */
	private $tables;

	/**
	 * Scripted failure for an upcoming query: array( message, SQL substring ), or null.
	 *
	 * @var array|null
	 */
	private $failure = null;

	/**
	 * @param array $tables Table definitions keyed by table name.
	 */
	public function __construct( array $tables ) {
		$this->tables = $tables;
	}

	/**
	 * Make the next query whose SQL contains $matching fail with $message.
	 *
	 * @param string $message  Error message to expose in $last_error.
	 * @param string $matching SQL substring selecting the query ('' = the next one).
	 */
	public function failNext( string $message, string $matching = '' ): void {
		$this->failure = array( $message, $matching );
	}

	/**
	 * Record a query and apply a scripted failure.
	 *
	 * @return bool True when the query should fail.
	 */
	private function begin( string $sql ): bool {
		$this->queries[]  = $sql;
		$this->last_error = '';

		if ( null !== $this->failure && false !== strpos( $sql, $this->failure[1] ) ) {
			$this->last_error = $this->failure[0];
			$this->failure    = null;

			return true;
		}

		return false;
	}

	/**
	 * Handle SHOW CREATE TABLE.
	 *
	 * @param string $sql    SQL.
	 * @param string $output Output type (ignored; always associative).
	 * @return array|null
	 */
	public function get_row( $sql, $output = 'OBJECT' ) {
		if ( $this->begin( $sql ) ) {
			return null;
		}

		if ( preg_match( '/^SHOW CREATE TABLE `([^`]+)`$/', $sql, $m ) && isset( $this->tables[ $m[1] ] ) ) {
			return array(
				'Table'        => $m[1],
				'Create Table' => $this->tables[ $m[1] ]['create'],
			);
		}

		throw new \LogicException( 'Unexpected get_row SQL: ' . $sql );
	}

	/**
	 * Handle SHOW FULL TABLES, SHOW COLUMNS and the paged SELECTs.
	 *
	 * @param string $sql    SQL.
	 * @param string $output Output type (ignored; always associative).
	 * @return array
	 */
	public function get_results( $sql, $output = 'OBJECT' ) {
		if ( $this->begin( $sql ) ) {
			return array();
		}

		if ( 'SHOW FULL TABLES' === $sql ) {
			$result = array();
			foreach ( $this->tables as $name => $table ) {
				$result[] = array(
					'Tables_in_fake' => $name,
					'Table_type'     => $table['type'] ?? 'BASE TABLE',
				);
			}

			return $result;
		}

		if ( preg_match( '/^SHOW COLUMNS FROM `([^`]+)`$/', $sql, $m ) && isset( $this->tables[ $m[1] ] ) ) {
			return $this->tables[ $m[1] ]['columns'];
		}

		if ( preg_match( '/^SELECT \* FROM `([^`]+)`(?: WHERE (.+?))?(?: ORDER BY (.+?))? LIMIT (\d+)(?: OFFSET (\d+))?$/', $sql, $m ) && isset( $this->tables[ $m[1] ] ) ) {
			$rows  = $this->tables[ $m[1] ]['rows'];
			$where = $m[2] ?? '';
			$order = array();

			if ( '' !== ( $m[3] ?? '' ) ) {
				preg_match_all( '/`([^`]+)`/', $m[3], $columns );
				$order = $columns[1];
				usort(
					$rows,
					static function ( array $a, array $b ) use ( $order ): int {
						return self::compareKeys( self::keyOf( $a, $order ), self::keyOf( $b, $order ) );
					}
				);
			}

			// Keyset condition: "`a` > lit" or "(`a`, `b`) > (lit, lit)".
			if ( preg_match( '/(?:^| AND )\(?((?:`[^`]+`(?:, )?)+)\)? > \(?(.+?)\)?$/', $where, $k ) ) {
				preg_match_all( '/`([^`]+)`/', $k[1], $keyColumns );
				$bound = self::parseLiterals( $k[2] );
				$rows  = array_values(
					array_filter(
						$rows,
						static function ( array $row ) use ( $keyColumns, $bound ): bool {
							return self::compareKeys( self::keyOf( $row, $keyColumns[1] ), $bound ) > 0;
						}
					)
				);
			}

			if ( '' !== $where && false !== strpos( $where, '_transient' ) ) {
				$rows = array_values(
					array_filter(
						$rows,
						static function ( $row ) {
							return 0 !== strpos( $row['option_name'], '_transient_' )
								&& 0 !== strpos( $row['option_name'], '_site_transient_' );
						}
					)
				);
			}

			return array_slice( $rows, (int) ( $m[5] ?? 0 ), (int) $m[4] );
		}

		throw new \LogicException( 'Unexpected get_results SQL: ' . $sql );
	}

	/**
	 * Add a row to a table (simulates a write by the live site between runs).
	 */
	public function insertRow( string $table, array $row ): void {
		$this->tables[ $table ]['rows'][] = $row;
	}

	/**
	 * Remove the rows for which $match returns true.
	 *
	 * @param callable $match fn( array $row ): bool.
	 */
	public function deleteRows( string $table, callable $match ): void {
		$this->tables[ $table ]['rows'] = array_values(
			array_filter(
				$this->tables[ $table ]['rows'],
				static function ( array $row ) use ( $match ): bool {
					return ! $match( $row );
				}
			)
		);
	}

	/**
	 * @param string[] $columns
	 * @return array
	 */
	private static function keyOf( array $row, array $columns ): array {
		$key = array();
		foreach ( $columns as $column ) {
			$key[] = $row[ $column ] ?? null;
		}

		return $key;
	}

	/**
	 * Row-constructor comparison: numbers numerically, everything else as binary strings.
	 */
	private static function compareKeys( array $a, array $b ): int {
		foreach ( $a as $i => $value ) {
			$other = $b[ $i ] ?? null;
			$cmp   = is_numeric( $value ) && is_numeric( $other ) ? ( $value + 0 <=> $other + 0 ) : strcmp( (string) $value, (string) $other );
			if ( 0 !== $cmp ) {
				return $cmp;
			}
		}

		return 0;
	}

	/**
	 * Parse a comma separated list of the SQL literals DbDumper writes (numbers, quoted strings, 0x hex).
	 *
	 * @return array
	 */
	private static function parseLiterals( string $list ): array {
		preg_match_all( "/\\s*(-?\\d+(?:\\.\\d+)?(?:[eE][-+]?\\d+)?|'(?:[^'\\\\]|\\\\.)*'|0x[0-9a-f]*)\\s*(?:,|$)/", $list, $m );

		return array_map(
			static function ( string $literal ) {
				if ( "'" === $literal[0] ) {
					return strtr(
						substr( $literal, 1, -1 ),
						array(
							'\\\\' => '\\',
							"\\'"  => "'",
							'\\"'  => '"',
							'\\n'  => "\n",
							'\\r'  => "\r",
							'\\0'  => "\0",
							'\\Z'  => "\x1a",
						)
					);
				}
				if ( 0 === strpos( $literal, '0x' ) ) {
					return (string) hex2bin( substr( $literal, 2 ) );
				}

				return $literal;
			},
			$m[1]
		);
	}
}
