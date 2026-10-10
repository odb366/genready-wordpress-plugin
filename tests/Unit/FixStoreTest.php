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
	/** @var string[] Options whose writes the database drops. */
	private $broken = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->options  = array();
		$this->autoload = array();
		$this->broken   = array();
		$opts           = &$this->options;
		$autoload       = &$this->autoload;
		$broken         = &$this->broken;
		Monkey\Functions\when( 'get_option' )->alias( function ( $k, $d = false ) use ( &$opts ) { return $opts[ $k ] ?? $d; } );
		Monkey\Functions\when( 'update_option' )->alias(
			function ( $k, $v, $a = null ) use ( &$opts, &$autoload, &$broken ) {
				if ( in_array( $k, $broken, true ) ) {
					return false;
				}
				$opts[ $k ]     = $v;
				$autoload[ $k ] = $a;
				return true;
			}
		);
		Monkey\Functions\when( 'delete_option' )->alias(
			function ( $k ) use ( &$opts, &$broken ) {
				if ( in_array( $k, $broken, true ) ) {
					return false;
				}
				unset( $opts[ $k ] );
				return true;
			}
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_absent_and_corrupt_records_read_as_empty(): void {
		$store = new FixStore();
		$this->assertSame( array(), $store->get( 'robots' ) );
		$this->options['genready_fix_robots'] = 'not an array';
		$this->assertSame( array(), $store->get( 'robots' ) );
		$this->assertSame( array(), $store->get( 'unknown' ) );
	}

	public function test_each_kind_has_its_own_option_that_is_not_autoloaded(): void {
		$store = new FixStore();
		$this->assertTrue( $store->put( 'llms', array( 'content' => 'L' ) ) );
		$this->assertTrue( $store->put( 'robots', array( 'content' => 'R' ) ) );
		$this->assertTrue( $store->put( 'jsonld_home', array( 'content' => 'J' ) ) );
		$this->assertSame( array( 'genready_fix_llms', 'genready_fix_robots', 'genready_fix_jsonld_home' ), array_keys( $this->options ) );
		$this->assertSame( array( false, false, false ), array_values( $this->autoload ) );
		$this->assertSame( 'L', $store->get( 'llms' )['content'] );
		$this->assertSame( array(), $store->get( 'robots' )['versions'] );
		$this->assertFalse( $store->put( 'other', array( 'content' => 'X' ) ), 'only known kinds are stored' );
		$this->assertCount( 3, $this->options );
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
		$fix             = $store->get( 'robots' );
		$fix['status']   = 'needs_review';
		$fix['versions'] = array();
		$this->assertTrue( $store->put_status( 'robots', $fix ) );
		$this->assertSame( 'needs_review', $store->get( 'robots' )['status'] );
		$this->assertCount( 1, $store->get( 'robots' )['versions'] );
	}

	public function test_replace_stores_the_record_as_given_and_remove_deletes_it(): void {
		$store = new FixStore();
		$store->put( 'robots', array( 'content' => 'A' ) );
		$store->put( 'robots', array( 'content' => 'B' ) );
		$this->assertTrue(
			$store->replace(
				'robots',
				array(
					'content'  => 'A',
					'versions' => array(),
				)
			)
		);
		$this->assertSame(
			array(
				'content'  => 'A',
				'versions' => array(),
			),
			$store->get( 'robots' )
		);
		$this->assertTrue( $store->remove( 'robots' ) );
		$this->assertSame( array(), $store->get( 'robots' ) );
		$this->assertArrayNotHasKey( 'genready_fix_robots', $this->options );
		$this->assertTrue( $store->remove( 'robots' ), 'removing a missing record is fine' );
	}

	public function test_a_write_the_database_drops_returns_false(): void {
		$store = new FixStore();
		$store->put( 'robots', array( 'content' => 'A' ) );
		$this->broken = array( 'genready_fix_robots' );
		$this->assertFalse( $store->put( 'robots', array( 'content' => 'B' ) ) );
		$this->assertFalse( $store->put_status( 'robots', array( 'content' => 'B' ) ) );
		$this->assertFalse( $store->replace( 'robots', array( 'content' => 'B' ) ) );
		$this->assertFalse( $store->remove( 'robots' ) );
		$this->assertSame( 'A', $store->get( 'robots' )['content'] );
	}

	public function test_an_unchanged_record_counts_as_saved(): void {
		// update_option() returns false when the value does not change; the read-back decides.
		Monkey\Functions\when( 'update_option' )->justReturn( false );
		$this->options['genready_fix_llms'] = array(
			'content'  => 'L',
			'versions' => array(),
		);
		$this->assertTrue( ( new FixStore() )->replace( 'llms', array( 'content' => 'L' ) ) );
	}
}
