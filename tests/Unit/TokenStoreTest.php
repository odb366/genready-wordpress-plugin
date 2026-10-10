<?php
namespace GenReady\Tests\Unit;

use Brain\Monkey;
use GenReady\Connection\TokenStore;
use GenReady\Tests\Fixtures\FakeWpdb;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/fixtures/FakeWpdb.php';

class TokenStoreTest extends TestCase {
	/** @var array */
	private $options = array();
	/** @var FakeWpdb */
	private $db;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->options = array();
		$this->db      = new FakeWpdb();
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- test double for $wpdb.
		$GLOBALS['wpdb'] = $this->db;
		$opts            = &$this->options;
		Monkey\Functions\when( 'wp_salt' )->justReturn( str_repeat( 's', 64 ) );
		Monkey\Functions\when( 'get_option' )->alias( function ( $k, $d = false ) use ( &$opts ) { return $opts[ $k ] ?? $d; } );
		Monkey\Functions\when( 'update_option' )->alias( function ( $k, $v ) use ( &$opts ) { $opts[ $k ] = $v; return true; } );
		Monkey\Functions\when( 'delete_option' )->alias( function ( $k ) use ( &$opts ) { unset( $opts[ $k ] ); return true; } );
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_round_trips_and_never_stores_plain_tokens(): void {
		$store = new TokenStore();
		$store->save( array( 'method' => 'oauth', 'access_token' => 'AT-secret', 'refresh_token' => 'RT-secret', 'domain_id' => 'd1' ) );
		$this->assertStringNotContainsString( 'AT-secret', serialize( $this->options ) );
		$this->assertStringNotContainsString( 'RT-secret', serialize( $this->options ) );
		$this->assertSame( 'RT-secret', $store->load()['refresh_token'] );
		$this->assertSame( 'd1', $store->load()['domain_id'] );
	}

	public function test_returns_null_when_salts_change(): void {
		( new TokenStore() )->save( array( 'method' => 'oauth', 'access_token' => 'AT' ) );
		Monkey\Functions\when( 'wp_salt' )->justReturn( str_repeat( 'x', 64 ) );
		$this->assertNull( ( new TokenStore() )->load() );
	}

	public function test_second_refresh_waits_for_lock(): void {
		$first = new TokenStore();
		$this->assertTrue( $first->acquire_lock( 0 ) );
		$this->assertFalse( ( new TokenStore() )->acquire_lock( 0 ) );
		$first->release_lock();
		$this->assertTrue( ( new TokenStore() )->acquire_lock( 0 ) );
	}

	public function test_breaks_a_stale_lock(): void {
		$this->db->rows[ TokenStore::LOCK ] = (string) ( time() - 31 );
		$this->assertTrue( ( new TokenStore() )->acquire_lock( 0 ) );
		$this->assertGreaterThan( time() - 5, (int) $this->db->rows[ TokenStore::LOCK ] );
	}

	public function test_lock_is_not_taken_over_by_add_option_semantics(): void {
		$held = (string) ( time() - 5 );
		$this->db->rows[ TokenStore::LOCK ] = $held;
		$this->assertFalse( ( new TokenStore() )->acquire_lock( 0 ) );
		$this->assertSame( $held, $this->db->rows[ TokenStore::LOCK ] );
	}

	public function test_stale_break_does_not_delete_a_fresh_lock(): void {
		$this->db->rows[ TokenStore::LOCK ] = (string) ( time() - 31 );
		$fresh                              = (string) time();
		// Another request breaks the stale lock and takes a fresh one between our read and our delete.
		$this->db->after_get_var = function ( FakeWpdb $db ) use ( $fresh ) {
			$db->rows[ TokenStore::LOCK ] = $fresh;
		};
		$this->assertFalse( ( new TokenStore() )->acquire_lock( 0 ) );
		$this->assertSame( $fresh, $this->db->rows[ TokenStore::LOCK ] );
	}

	public function test_lock_never_touches_the_options_api(): void {
		Monkey\Functions\when( 'add_option' )->alias( function () { throw new \RuntimeException( 'add_option used' ); } );
		$store = new TokenStore();
		$this->assertTrue( $store->acquire_lock( 0 ) );
		$store->release_lock();
		$this->assertArrayNotHasKey( TokenStore::LOCK, $this->db->rows );
	}
}
