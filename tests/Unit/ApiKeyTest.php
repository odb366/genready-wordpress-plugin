<?php
namespace GenReady\Tests\Unit;

use Brain\Monkey;
use GenReady\Api\ApiError;
use GenReady\Connection\ApiKey;
use GenReady\Connection\TokenStore;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/fixtures/doubles.php';

class ApiKeyTest extends TestCase {
	const KEY = 'gr_live_abcdefghijklmnop1234';

	/** @var array */
	private $options = array();
	/** @var TokenStore */
	private $store;
	/** @var FakeClient */
	private $client;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->options = array();
		$opts          = &$this->options;
		Monkey\Functions\when( 'wp_salt' )->justReturn( str_repeat( 's', 64 ) );
		Monkey\Functions\when( 'get_option' )->alias( function ( $k, $d = false ) use ( &$opts ) { return $opts[ $k ] ?? $d; } );
		Monkey\Functions\when( 'update_option' )->alias( function ( $k, $v ) use ( &$opts ) { $opts[ $k ] = $v; return true; } );
		Monkey\Functions\when( 'delete_option' )->alias( function ( $k ) use ( &$opts ) { unset( $opts[ $k ] ); return true; } );
		Monkey\Functions\when( '__' )->returnArg( 1 );
		Monkey\Functions\when( 'sanitize_text_field' )->alias( function ( $v ) { return trim( strip_tags( (string) $v ) ); } );
		Monkey\Functions\when( 'home_url' )->justReturn( 'https://example.com' );
		Monkey\Functions\when( 'wp_parse_url' )->alias( 'parse_url' );
		$this->store  = new TokenStore();
		$this->client = new FakeClient();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_connect_stores_the_domain_and_token(): void {
		$this->client->request_result = array( 'domain' => array( 'id' => 'd9', 'verificationToken' => 't9' ) );
		$r                            = ( new ApiKey( $this->store, $this->client ) )->connect( '  ' . self::KEY . ' ', 'example.com' );
		$this->assertSame( array( 'ok' => true ), $r );
		$saved = $this->store->load();
		$this->assertSame( 'api_key', $saved['method'] );
		$this->assertSame( self::KEY, $saved['api_key'] );
		$this->assertSame( 'd9', $saved['domain_id'] );
		$this->assertSame( 't9', $saved['verification_token'] );
		$this->assertSame( array( 'POST', '/api/v1/domains', array( 'hostname' => 'example.com' ) ), $this->client->requests[0] );
	}

	public function test_a_402_clears_the_store_and_explains(): void {
		$this->client->request_result = new ApiError( 402, 'DOMAIN_LIMIT_REACHED', '' );
		$r                            = ( new ApiKey( $this->store, $this->client ) )->connect( self::KEY, 'example.com' );
		$this->assertSame( 'Your plan has no free site slot. Remove a site in GenReady or upgrade.', $r['error'] );
		$this->assertNull( $this->store->load() );
	}

	public function test_rejects_a_malformed_key_without_network(): void {
		foreach ( array( '', 'sk_live_abcdefghijklmnop1234', 'gr_live_short', 'gr_live_' . str_repeat( 'a', 129 ), 'gr_live_abcdefghijklmnop 1234' ) as $bad ) {
			$r = ( new ApiKey( $this->store, $this->client ) )->connect( $bad, 'example.com' );
			$this->assertSame( 'That is not a GenReady API key. Keys start with gr_live_.', $r['error'] );
		}
		$this->assertSame( array(), $this->client->requests );
		$this->assertNull( $this->store->load() );
	}

	public function test_refuses_when_already_connected(): void {
		Monkey\Functions\expect( 'wp_remote_request' )->never();
		$this->store->save( array( 'method' => 'oauth', 'access_token' => 'A1', 'refresh_token' => 'R1', 'client_id' => 'cid' ) );
		$r = ( new ApiKey( $this->store, $this->client ) )->connect( self::KEY, 'example.com' );
		$this->assertSame( 'Disconnect GenReady first, then add the API key.', $r['error'] );
		$this->assertSame( array(), $this->client->requests );
		$saved = $this->store->load();
		$this->assertSame( 'oauth', $saved['method'] );
		$this->assertSame( 'R1', $saved['refresh_token'] );
	}
}
