<?php
namespace GenReady\Tests\Unit;

use Brain\Monkey;
use GenReady\Fixes\FixStore;
use PHPUnit\Framework\TestCase;

class FixStoreTest extends TestCase {
	/** @var array */
	private $options = array();
	/** @var array */
	private $autoload = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->options  = array();
		$this->autoload = array();
		$opts           = &$this->options;
		$autoload       = &$this->autoload;
		Monkey\Functions\when( 'get_option' )->alias( function ( $k, $d = false ) use ( &$opts ) { return $opts[ $k ] ?? $d; } );
		Monkey\Functions\when( 'update_option' )->alias( function ( $k, $v, $a = null ) use ( &$opts, &$autoload ) { $opts[ $k ] = $v; $autoload[ $k ] = $a; return true; } );
		Monkey\Functions\when( 'delete_option' )->alias( function ( $k ) use ( &$opts ) { unset( $opts[ $k ] ); return true; } );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_absent_and_corrupt_entries_read_as_empty(): void {
		$store = new FixStore();
		$this->assertSame( array(), $store->get( 'robots' ) );
		$this->options['genready_fixes'] = 'not an array';
		$this->assertSame( array(), $store->get( 'robots' ) );
		$this->options['genready_fixes'] = array( 'robots' => 'not an array' );
		$this->assertSame( array(), $store->get( 'robots' ) );
	}

	public function test_put_is_not_autoloaded_and_keeps_other_kinds(): void {
		$store = new FixStore();
		$store->put( 'llms', array( 'content' => 'L' ) );
		$store->put( 'robots', array( 'content' => 'R' ) );
		$this->assertFalse( $this->autoload['genready_fixes'] );
		$this->assertSame( 'L', $store->get( 'llms' )['content'] );
		$this->assertSame( array(), $store->get( 'robots' )['versions'] );
	}

	public function test_put_keeps_the_last_five_earlier_records(): void {
		$store = new FixStore();
		for ( $i = 1; $i <= 7; $i++ ) {
			$store->put(
				'robots',
				array(
					'mode'        => 'virtual',
					'content'     => 'v' . $i,
					'base_sha256' => 'b' . $i,
				)
			);
		}
		$versions = $store->get( 'robots' )['versions'];
		$this->assertCount( 5, $versions );
		$this->assertSame(
			array(
				'content'     => 'v2',
				'base_sha256' => 'b2',
				'mode'        => 'virtual',
			),
			$versions[0]
		);
		$this->assertSame( 'v6', $versions[4]['content'] );
		$this->assertSame( 'v7', $store->get( 'robots' )['content'] );
	}

	public function test_put_status_does_not_add_a_version(): void {
		$store = new FixStore();
		$store->put( 'robots', array( 'content' => 'A' ) );
		$store->put( 'robots', array( 'content' => 'B' ) );
		$fix           = $store->get( 'robots' );
		$fix['status'] = 'needs_review';
		$fix['versions'] = array();
		$store->put_status( 'robots', $fix );
		$this->assertSame( 'needs_review', $store->get( 'robots' )['status'] );
		$this->assertCount( 1, $store->get( 'robots' )['versions'] );
	}

	public function test_replace_stores_the_record_as_given_and_remove_deletes_it(): void {
		$store = new FixStore();
		$store->put( 'robots', array( 'content' => 'A' ) );
		$store->put( 'robots', array( 'content' => 'B' ) );
		$store->replace(
			'robots',
			array(
				'content'  => 'A',
				'versions' => array(),
			)
		);
		$this->assertSame(
			array(
				'content'  => 'A',
				'versions' => array(),
			),
			$store->get( 'robots' )
		);
		$store->remove( 'robots' );
		$this->assertSame( array(), $store->get( 'robots' ) );
		$this->assertSame( array(), $this->options['genready_fixes'] );
	}
}
