<?php
namespace GenReady\Tests\Unit;

use Brain\Monkey;
use GenReady\Api\ApiError;
use GenReady\Compat\Caches;
use GenReady\Connection\SiteSetup;
use GenReady\Connection\TokenStore;
use GenReady\Verification\MetaTag;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/fixtures/doubles.php';

class SiteSetupTest extends TestCase {
	/** @var array */
	private $options = array();
	/** @var array */
	private $log = array();
	/** @var TokenStore */
	private $store;
	/** @var FakeClient */
	private $client;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->options = array();
		$this->log     = array();
		$opts          = &$this->options;
		$log           = &$this->log;
		Monkey\Functions\when( 'wp_salt' )->justReturn( str_repeat( 's', 64 ) );
		Monkey\Functions\when( 'get_option' )->alias( function ( $k, $d = false ) use ( &$opts ) { return $opts[ $k ] ?? $d; } );
		Monkey\Functions\when( 'update_option' )->alias( function ( $k, $v ) use ( &$opts, &$log ) { $opts[ $k ] = $v; $log[] = 'option:' . $k; return true; } );
		Monkey\Functions\when( 'delete_option' )->alias( function ( $k ) use ( &$opts ) { unset( $opts[ $k ] ); return true; } );
		Monkey\Functions\when( 'delete_transient' )->alias( function ( $k ) use ( &$log ) { $log[] = 'transient:' . $k; return true; } );
		Monkey\Functions\when( '__' )->returnArg( 1 );
		Monkey\Functions\when( 'sanitize_text_field' )->alias( function ( $v ) { return trim( strip_tags( (string) $v ) ); } );
		Monkey\Functions\when( 'home_url' )->justReturn( 'https://example.com/' );
		Monkey\Functions\when( 'wp_parse_url' )->alias( 'parse_url' );
		$this->store  = new TokenStore();
		$this->client = new FakeClient();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function setup_object(): SiteSetup {
		return new SiteSetup( $this->store, $this->client, new MetaTag(), new Caches() );
	}

	private function connect( array $extra = array() ): void {
		$this->store->save( array_merge( array( 'method' => 'api_key', 'api_key' => 'gr_live_x', 'domain_id' => 'd1', 'verification_token' => 'tok12345678' ), $extra ) );
	}

	public function test_verify_sets_meta_before_calling_the_api(): void {
		$this->connect();
		$meta   = &$this->options;
		$seen   = null;
		$client = new class() extends FakeClient {
			/** @var callable */
			public $on_request;
			public function request( string $method, string $path, ?array $body = null, int $timeout = 15 ) {
				( $this->on_request )();
				return parent::request( $method, $path, $body, $timeout );
			}
		};
		$client->on_request    = function () use ( &$meta, &$seen ) { $seen = $meta['genready_meta'] ?? null; };
		$client->request_result = array( 'verified' => true, 'detail' => 'ok' );
		$result                 = ( new SiteSetup( $this->store, $client, new MetaTag(), new Caches() ) )->verify();
		$this->assertSame( 'tok12345678', $seen );
		$this->assertSame( array( 'POST', '/api/v1/domains/d1/verify', array( 'method' => 'meta' ) ), $client->requests[0] );
		$this->assertSame( array( 'verified' => true, 'detail' => 'ok' ), $result );
	}

	public function test_verify_returns_server_detail_on_failure(): void {
		$this->connect();
		$this->client->request_result = array( 'verified' => false, 'detail' => 'Tag not found on the home page.' );
		$this->assertSame( array( 'verified' => false, 'detail' => 'Tag not found on the home page.' ), $this->setup_object()->verify() );
	}

	public function test_verify_maps_api_errors(): void {
		$this->connect();
		$this->client->request_result = new ApiError( 409, 'VERIFIED_ELSEWHERE', '' );
		$this->assertSame( array( 'error' => 'This site is connected to another GenReady account.', 'code' => 'VERIFIED_ELSEWHERE', 'status' => 409 ), $this->setup_object()->verify() );
	}

	public function test_verify_fetches_missing_token_first(): void {
		$this->store->save( array( 'method' => 'oauth', 'access_token' => 'A', 'refresh_token' => 'R', 'domain_id' => 'd1' ) );
		$this->client->request_result = array( 'domain' => array( 'id' => 'd1', 'verificationToken' => 'fetched12345' ), 'verified' => true, 'detail' => '' );
		$r                            = $this->setup_object()->verify();
		$this->assertSame( array( 'POST', '/api/v1/domains', array( 'hostname' => 'example.com' ) ), $this->client->requests[0] );
		$this->assertSame( array( 'POST', '/api/v1/domains/d1/verify', array( 'method' => 'meta' ) ), $this->client->requests[1] );
		$this->assertSame( 'fetched12345', $this->options['genready_meta'] );
		$saved = $this->store->load();
		$this->assertSame( 'fetched12345', $saved['verification_token'] );
		$this->assertSame( 'R', $saved['refresh_token'] );
		$this->assertTrue( $r['verified'] );
	}

	public function test_verify_without_domain_id_needs_reconnect_and_no_network(): void {
		$this->store->save( array( 'method' => 'api_key', 'api_key' => 'gr_live_x' ) );
		$this->assertSame( array( 'error' => 'Reconnect GenReady.' ), $this->setup_object()->verify() );
		$this->assertSame( array(), $this->client->requests );
	}

	public function test_scan_stores_report_id_and_clears_cache(): void {
		$this->connect();
		$this->options['genready_settings'] = array( 'keep' => 1 );
		$this->client->request_result       = array( 'reportId' => 'abc-123', 'estimatedSeconds' => 40, 'status' => 'queued' );
		$r                                  = $this->setup_object()->scan();
		$this->assertSame( array( 'report_id' => 'abc-123', 'estimated_seconds' => 40 ), $r );
		$this->assertSame( array( 'POST', '/api/v1/analyze', array( 'url' => 'https://example.com/', 'scope' => 'full' ) ), $this->client->requests[0] );
		$this->assertSame( array( 'keep' => 1, 'last_report_id' => 'abc-123' ), $this->options['genready_settings'] );
		$this->assertContains( 'transient:genready_report_cache', $this->log );
	}

	public function test_scan_maps_credits_exhausted(): void {
		$this->connect();
		$this->client->request_result = new ApiError( 402, 'CREDITS_EXHAUSTED', '' );
		$this->assertSame( array( 'error' => 'Your GenReady plan has no scans left for now.', 'code' => 'CREDITS_EXHAUSTED', 'status' => 402 ), $this->setup_object()->scan() );
		$this->assertArrayNotHasKey( 'genready_settings', $this->options );
	}
}
