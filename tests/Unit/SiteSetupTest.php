<?php
namespace GenReady\Tests\Unit;

use Brain\Monkey;
use GenReady\Api\ApiError;
use GenReady\Api\Client;
use GenReady\Compat\Caches;
use GenReady\Connection\OAuth;
use GenReady\Connection\SiteSetup;
use GenReady\Connection\TokenStore;
use GenReady\Verification\MetaTag;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/fixtures/doubles.php';
require_once dirname( __DIR__ ) . '/fixtures/http-doubles.php';

class SiteSetupTest extends TestCase {
	use ScriptedHttp;

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
		$this->assertSame( array( 'keep' => 1, 'last_report_id' => 'abc-123', 'last_report_url' => 'https://example.com/' ), $this->options['genready_settings'] );
		$this->assertContains( 'transient:genready_report_cache', $this->log );
	}

	public function test_scan_maps_credits_exhausted(): void {
		$this->connect();
		$this->client->request_result = new ApiError( 402, 'CREDITS_EXHAUSTED', '' );
		$this->assertSame( array( 'error' => 'Your GenReady plan has no scans left for now.', 'code' => 'CREDITS_EXHAUSTED', 'status' => 402 ), $this->setup_object()->scan() );
		$this->assertArrayNotHasKey( 'genready_settings', $this->options );
	}

	// Fix round A, G2: never save a record loaded before a call that can refresh.

	public function test_verify_keeps_the_tokens_a_refresh_rotated_while_fetching_the_token(): void {
		$store = new LockableStore( true );
		$store->save( array( 'method' => 'oauth', 'access_token' => 'A1', 'refresh_token' => 'R1', 'client_id' => 'cid', 'domain_id' => 'd1' ) );
		$oauth  = null;
		$client = new Client(
			$store,
			function () use ( &$oauth ): bool {
				return $oauth->refresh();
			}
		);
		$oauth  = new OAuth( $store, $client );
		$this->scripted_http(
			array(
				array( 401, array( 'error' => array( 'code' => 'UNAUTHORIZED', 'message' => 'expired' ) ) ),
				array( 200, array( 'access_token' => 'A2', 'refresh_token' => 'R2', 'expires_in' => 3600 ) ),
				array( 200, array( 'data' => array( 'domain' => array( 'id' => 'd1', 'verificationToken' => 'fetched12345' ) ) ) ),
				array( 200, array( 'data' => array( 'verified' => true, 'detail' => '' ) ) ),
			)
		);
		$result = ( new SiteSetup( $store, $client, new MetaTag(), new Caches() ) )->verify();
		$this->assertTrue( $result['verified'] );
		$this->assertSame( 'Bearer A2', $this->bearer( 3 ) );
		$saved = $store->load();
		$this->assertSame( 'A2', $saved['access_token'] );
		$this->assertSame( 'R2', $saved['refresh_token'] );
		$this->assertSame( 'fetched12345', $saved['verification_token'] );
	}

	public function test_verify_does_not_bring_back_a_connection_that_ended_during_the_token_call(): void {
		$this->connect( array( 'verification_token' => '' ) );
		$this->client->on_request     = function () {
			$this->store->clear();
		};
		$this->client->request_result = array( 'domain' => array( 'id' => 'd1', 'verificationToken' => 'fetched12345' ) );
		$result                       = $this->setup_object()->verify();
		$this->assertSame( 'Reconnect GenReady.', $result['error'] );
		$this->assertNull( $this->store->load() );
		$this->assertCount( 1, $this->client->requests );
	}

	public function test_scan_uses_a_page_on_this_site_and_falls_back_to_the_home_page(): void {
		$this->connect();
		$this->client->request_result = array( 'reportId' => '11111111-2222-3333-4444-555555555555' );
		$this->setup_object()->scan( 'https://example.com/pricing/' );
		$this->assertSame( 'https://example.com/pricing/', $this->options['genready_settings']['last_report_url'], 'the URL it sent' );
		$this->setup_object()->scan( 'https://evil.test/' );
		$this->assertSame( 'https://example.com/', $this->options['genready_settings']['last_report_url'] );
		$this->assertSame( 'https://example.com/pricing/', $this->client->requests[0][2]['url'] );
		$this->assertSame( 'https://example.com/', $this->client->requests[1][2]['url'] );
	}
}
