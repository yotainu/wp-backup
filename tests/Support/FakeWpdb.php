<?php

namespace WpPcloudBackup\Tests\Support;

/**
 * Minimal in-memory stand-in for \wpdb, understanding only the SQL that DbDumper issues.
 *
 * Tables are described as:
 * array( 'name' => array( 'create' => 'CREATE TABLE ...', 'columns' => array( array( 'Field' => ..., 'Type' => ..., 'Key' => ... ) ), 'rows' => array( array( col => value ) ) ) )
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
	 * @param array $tables Table definitions keyed by table name.
	 */
	public function __construct( array $tables ) {
		$this->tables = $tables;
	}

	/**
	 * Handle SHOW TABLES.
	 *
	 * @param string $sql SQL.
	 * @return string[]
	 */
	public function get_col( $sql ) {
		$this->queries[] = $sql;

		if ( 'SHOW TABLES' === $sql ) {
			return array_keys( $this->tables );
		}

		throw new \LogicException( 'Unexpected get_col SQL: ' . $sql );
	}

	/**
	 * Handle SHOW CREATE TABLE.
	 *
	 * @param string $sql    SQL.
	 * @param string $output Output type (ignored; always associative).
	 * @return array|null
	 */
	public function get_row( $sql, $output = 'OBJECT' ) {
		$this->queries[] = $sql;

		if ( preg_match( '/^SHOW CREATE TABLE `([^`]+)`$/', $sql, $m ) && isset( $this->tables[ $m[1] ] ) ) {
			return array(
				'Table'        => $m[1],
				'Create Table' => $this->tables[ $m[1] ]['create'],
			);
		}

		throw new \LogicException( 'Unexpected get_row SQL: ' . $sql );
	}

	/**
	 * Handle SHOW COLUMNS and the paged SELECTs.
	 *
	 * @param string $sql    SQL.
	 * @param string $output Output type (ignored; always associative).
	 * @return array
	 */
	public function get_results( $sql, $output = 'OBJECT' ) {
		$this->queries[] = $sql;

		if ( preg_match( '/^SHOW COLUMNS FROM `([^`]+)`$/', $sql, $m ) && isset( $this->tables[ $m[1] ] ) ) {
			return $this->tables[ $m[1] ]['columns'];
		}

		if ( preg_match( '/^SELECT \* FROM `([^`]+)`(?: WHERE (.+?))?(?: ORDER BY (.+?))? LIMIT (\d+) OFFSET (\d+)$/', $sql, $m ) && isset( $this->tables[ $m[1] ] ) ) {
			$rows = $this->tables[ $m[1] ]['rows'];

			if ( '' !== $m[2] && false !== strpos( $m[2], '_transient' ) ) {
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

			return array_slice( $rows, (int) $m[5], (int) $m[4] );
		}

		throw new \LogicException( 'Unexpected get_results SQL: ' . $sql );
	}
}
