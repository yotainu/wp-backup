<?php

namespace WpPcloudBackup\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use WpPcloudBackup\History;

final class HistoryTest extends TestCase {

	/**
	 * Fake wp_options table.
	 *
	 * @var array
	 */
	private $options = array();

	/**
	 * Every update_option() call as array( name, value, autoload ).
	 *
	 * @var array
	 */
	private $updates = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->options = array();
		$this->updates = array();

		Functions\when( 'get_option' )->alias(
			function ( $name, $default = false ) {
				return array_key_exists( $name, $this->options ) ? $this->options[ $name ] : $default;
			}
		);
		Functions\when( 'update_option' )->alias(
			function ( $name, $value, $autoload = null ) {
				$this->updates[]        = array( $name, $value, $autoload );
				$this->options[ $name ] = $value;
				return true;
			}
		);
		Functions\when( 'delete_option' )->alias(
			function ( $name ) {
				unset( $this->options[ $name ] );
				return true;
			}
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function entry( int $n ): array {
		return array(
			'started'  => $n,
			'finished' => $n + 10,
			'result'   => 'success',
			'bytes'    => 100 * $n,
			'files'    => $n,
			'remote'   => '/WPBackup/example.com/' . $n,
			'message'  => '',
		);
	}

	public function test_empty_by_default(): void {
		$this->assertSame( array(), ( new History() )->all() );
	}

	public function test_history_caps_at_20_newest_first(): void {
		$history = new History();
		for ( $i = 1; $i <= 25; $i++ ) {
			$history->add( $this->entry( $i ) );
		}

		$all = $history->all();
		$this->assertCount( 20, $all );
		$this->assertSame( 25, $all[0]['started'] );
		$this->assertSame( 6, $all[19]['started'] );
	}

	public function test_saved_without_autoload_under_option_name(): void {
		( new History() )->add( $this->entry( 1 ) );

		$this->assertSame( 'wp_pcloud_backup_history', $this->updates[0][0] );
		$this->assertFalse( $this->updates[0][2] );
	}

	public function test_corrupt_option_is_treated_as_empty(): void {
		$this->options['wp_pcloud_backup_history'] = 'garbage';
		$history                                   = new History();

		$this->assertSame( array(), $history->all() );
		$history->add( $this->entry( 1 ) );
		$this->assertCount( 1, $history->all() );
	}

	public function test_clear_removes_option(): void {
		$history = new History();
		$history->add( $this->entry( 1 ) );
		$history->clear();

		$this->assertSame( array(), $history->all() );
		$this->assertArrayNotHasKey( 'wp_pcloud_backup_history', $this->options );
	}
}
