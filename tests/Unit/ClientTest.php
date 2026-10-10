<?php
namespace GenReady\Tests\Unit;

use Brain\Monkey;
use GenReady\Api\ApiError;
use GenReady\Api\Client;
use GenReady\Connection\TokenStore;
use PHPUnit\Framework\TestCase;

class ClientTest extends TestCase {
	/** @var array */
	private $options = array();
	/** @var TokenStore */
	private $store;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->options = array();
		$opts          = &$this->options;
		Monkey\Functions\when( 'wp_salt' )->justReturn( str_repeat( 's', 64 ) );
		Monkey\Functions\when( 'get_option' )->alias( function ( $k, $d = false ) use ( &$opts ) { return $opts[ $k ] ?? $d; } );
		Monkey\Functions\when( 'update_option' )->alias( function ( $k, $v ) use ( &$opts ) { $opts[ $k ] = $v; return true; } );
		Monkey\Functions\when( 'delete_option' )->alias( function ( $k ) use ( &$opts ) { unset( $opts[ $k ] ); return true; } );
		Monkey\Functions\when( 'get_bloginfo' )->justReturn( '6.8' );
		Monkey\Functions\when( '__' )->returnArg( 1 );
		Monkey\Functions\when( '_n' )->alias( function ( $single, $plural, $number ) { return 1 === $number ? $single : $plural; } );
		Monkey\Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Monkey\Functions\when( 'is_wp_error' )->alias( function ( $v ) { return $v instanceof \WP_Error; } );
		Monkey\Functions\when( 'wp_remote_retrieve_response_code' )->alias( function ( $r ) { return $r['response']['code']; } );
		Monkey\Functions\when( 'wp_remote_retrieve_body' )->alias( function ( $r ) { return $r['body']; } );
		Monkey\Functions\when( 'wp_remote_retrieve_header' )->alias( function ( $r, $name ) { return 'retry-after' === strtolower( $name ) ? ( $r['headers']['Retry-After'] ?? '' ) : ''; } );
		$this->store = new TokenStore();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function response( int $code, array $body, array $headers = array() ): array {
		return array(
			'response' => array( 'code' => $code ),
			'body'     => json_encode( $body ),
			'headers'  => $headers,
		);
	}

	private function connect( array $connection ): void {
		$this->store->save( $connection );
	}

	private function error_for( int $status, array $error, array $headers = array() ): ApiError {
		$this->connect( array( 'method' => 'api_key', 'api_key' => 'k' ) );
		Monkey\Functions\when( 'wp_remote_request' )->justReturn( $this->response( $status, array( 'error' => $error, 'meta' => array() ), $headers ) );
		$result = ( new Client( $this->store ) )->request( 'GET', '/x' );
		$this->assertInstanceOf( ApiError::class, $result );
		return $result;
	}

	public function test_sends_bearer_and_returns_data(): void {
		$this->connect( array( 'method' => 'api_key', 'api_key' => 'gr_live_x' ) );
		Monkey\Functions\expect( 'wp_remote_request' )->once()->andReturnUsing( function ( $url, $args ) {
			$this->assertSame( 'https://genready.ai/api/v1/domains', $url );
			$this->assertSame( 'Bearer gr_live_x', $args['headers']['Authorization'] );
			$this->assertSame( 0, $args['redirection'] );
			return $this->response( 200, array( 'data' => array( 'domains' => array() ), 'meta' => array() ) );
		} );
		$this->assertSame( array( 'domains' => array() ), ( new Client( $this->store ) )->request( 'GET', '/api/v1/domains' ) );
	}

	public function test_ignores_missing_success_field(): void {
		$this->connect( array( 'method' => 'api_key', 'api_key' => 'k' ) );
		Monkey\Functions\when( 'wp_remote_request' )->justReturn( $this->response( 200, array( 'data' => array( 'x' => 1 ), 'meta' => array() ) ) );
		$this->assertSame( array( 'x' => 1 ), ( new Client( $this->store ) )->request( 'GET', '/x' ) );
	}

	public function test_non_json_2xx_is_bad_response(): void {
		$this->connect( array( 'method' => 'api_key', 'api_key' => 'k' ) );
		Monkey\Functions\when( 'wp_remote_request' )->justReturn( array( 'response' => array( 'code' => 200 ), 'body' => '<html>', 'headers' => array() ) );
		$result = ( new Client( $this->store ) )->request( 'GET', '/x' );
		$this->assertInstanceOf( ApiError::class, $result );
		$this->assertSame( 'BAD_RESPONSE', $result->code );
		$this->assertSame( 'GenReady could not do this. Try again.', $result->user_message() );
	}

	public function test_redirect_is_an_http_error(): void {
		$this->connect( array( 'method' => 'api_key', 'api_key' => 'k' ) );
		Monkey\Functions\when( 'wp_remote_request' )->justReturn( array( 'response' => array( 'code' => 302 ), 'body' => '', 'headers' => array() ) );
		$result = ( new Client( $this->store ) )->request( 'GET', '/x' );
		$this->assertSame( 'HTTP_302', $result->code );
	}

	public function test_refreshes_once_on_401_then_retries(): void {
		$this->connect( array( 'method' => 'oauth', 'access_token' => 'old' ) );
		$calls = 0;
		Monkey\Functions\when( 'wp_remote_request' )->alias( function () use ( &$calls ) {
			$calls++;
			return 1 === $calls
				? $this->response( 401, array() )
				: $this->response( 200, array( 'data' => array( 'ok' => 1 ), 'meta' => array() ) );
		} );
		$refreshed = 0;
		$client    = new Client( $this->store, function () use ( &$refreshed ) { $refreshed++; return true; } );
		$this->assertSame( array( 'ok' => 1 ), $client->request( 'GET', '/api/v1/usage' ) );
		$this->assertSame( 1, $refreshed );
	}

	public function test_asks_to_reconnect_after_a_second_401(): void {
		$this->connect( array( 'method' => 'oauth', 'access_token' => 'old' ) );
		Monkey\Functions\when( 'wp_remote_request' )->justReturn( $this->response( 401, array() ) );
		$client = new Client( $this->store, function () { return true; } );
		$this->assertSame( 'RECONNECT', $client->request( 'GET', '/x' )->code );
	}

	public function test_api_key_401_does_not_refresh(): void {
		$this->connect( array( 'method' => 'api_key', 'api_key' => 'k' ) );
		Monkey\Functions\when( 'wp_remote_request' )->justReturn( $this->response( 401, array() ) );
		$refreshed = 0;
		$client    = new Client( $this->store, function () use ( &$refreshed ) { $refreshed++; return true; } );
		$result    = $client->request( 'GET', '/x' );
		$this->assertSame( 0, $refreshed );
		$this->assertSame( 401, $result->status );
		$this->assertSame( 'Reconnect GenReady.', $result->user_message() );
	}

	public function test_no_connection_returns_reconnect_without_network(): void {
		Monkey\Functions\expect( 'wp_remote_request' )->never();
		$result = ( new Client( $this->store ) )->request( 'GET', '/x' );
		$this->assertSame( 'RECONNECT', $result->code );
	}

	public function test_maps_plan_required(): void {
		$result = $this->error_for( 402, array( 'code' => 'PLAN_REQUIRED', 'message' => 'x' ) );
		$this->assertSame( 'GenReady Pro and Agency generate this file.', $result->user_message() );
	}

	public function test_maps_credits_exhausted(): void {
		$result = $this->error_for( 402, array( 'code' => 'CREDITS_EXHAUSTED', 'message' => 'x' ) );
		$this->assertSame( 'Your GenReady plan has no scans left for now.', $result->user_message() );
	}

	public function test_not_verified_message(): void {
		$result = $this->error_for( 409, array( 'code' => 'NOT_VERIFIED', 'message' => 'x' ) );
		$this->assertSame( 'Verify this site first.', $result->user_message() );
	}

	public function test_verified_elsewhere_message(): void {
		$result = $this->error_for( 409, array( 'code' => 'VERIFIED_ELSEWHERE', 'message' => 'x' ) );
		$this->assertSame( 'This site is connected to another GenReady account.', $result->user_message() );
	}

	public function test_unsafe_merge_without_details_uses_server_message(): void {
		$result = $this->error_for( 422, array( 'code' => 'UNSAFE_MERGE', 'message' => 'The file is too large or complex to check.' ) );
		$this->assertSame( 'The file is too large or complex to check.', $result->user_message() );
	}

	public function test_unsafe_merge_with_empty_message_uses_other_text(): void {
		$result = $this->error_for( 422, array( 'code' => 'UNSAFE_MERGE', 'message' => '' ) );
		$this->assertSame( 'GenReady could not do this. Try again.', $result->user_message() );
	}

	public function test_429_uses_retry_after_seconds(): void {
		$result = $this->error_for( 429, array( 'code' => 'RATE_LIMITED', 'message' => 'x', 'details' => array( 'retryAfterSeconds' => 125 ) ) );
		$this->assertSame( 'GenReady is busy. Try again in 3 minutes.', $result->user_message() );
	}

	public function test_429_falls_back_to_retry_after_header(): void {
		$result = $this->error_for( 429, array( 'code' => 'RATE_LIMITED', 'message' => 'x' ), array( 'Retry-After' => '60' ) );
		$this->assertSame( 'GenReady is busy. Try again in 1 minute.', $result->user_message() );
	}

	public function test_429_without_any_hint(): void {
		$result = $this->error_for( 429, array( 'code' => 'RATE_LIMITED', 'message' => 'x' ) );
		$this->assertSame( 'GenReady is busy. Try again in a few minutes.', $result->user_message() );
	}

	public function test_maps_404_and_other(): void {
		$this->assertSame( 'This site was removed from your GenReady account. Reconnect to add it again.', $this->error_for( 404, array( 'code' => 'NOT_FOUND', 'message' => 'x' ) )->user_message() );
		$this->assertSame( 'GenReady could not do this. Try again.', $this->error_for( 500, array( 'code' => 'BOOM', 'message' => 'x' ) )->user_message() );
	}

	public function test_maps_a_timeout(): void {
		$this->connect( array( 'method' => 'api_key', 'api_key' => 'k' ) );
		Monkey\Functions\when( 'wp_remote_request' )->justReturn( new \WP_Error( 'http_request_failed', 'timed out' ) );
		$result = ( new Client( $this->store ) )->request( 'GET', '/x' );
		$this->assertSame( 'TIMEOUT', $result->code );
		$this->assertSame( 'GenReady did not answer. Try again.', $result->user_message() );
	}

	public function test_post_form_clamps_timeout_and_sends_no_authorization(): void {
		$this->connect( array( 'method' => 'oauth', 'access_token' => 'AT' ) );
		Monkey\Functions\expect( 'wp_remote_post' )->never();
		Monkey\Functions\expect( 'wp_remote_request' )->once()->andReturnUsing( function ( $url, $args ) {
			$this->assertSame( 'https://genready.ai/oauth/token', $url );
			$this->assertSame( 20, $args['timeout'] );
			$this->assertSame( 0, $args['redirection'] );
			$this->assertArrayNotHasKey( 'Authorization', $args['headers'] );
			$this->assertSame( 'application/x-www-form-urlencoded', $args['headers']['Content-Type'] );
			$this->assertSame( 'application/json', $args['headers']['Accept'] );
			$this->assertStringStartsWith( 'GenReady-WordPress/', $args['headers']['User-Agent'] );
			$this->assertSame( 'grant_type=refresh_token&refresh_token=RT', $args['body'] );
			return $this->response( 200, array( 'access_token' => 'new' ) );
		} );
		$result = ( new Client( $this->store ) )->post_form( '/oauth/token', array( 'grant_type' => 'refresh_token', 'refresh_token' => 'RT' ), 60 );
		$this->assertSame( array( 'access_token' => 'new' ), $result );
	}

	public function test_post_form_maps_oauth_errors(): void {
		Monkey\Functions\when( 'wp_remote_request' )->justReturn( $this->response( 400, array( 'error' => 'invalid_grant', 'error_description' => 'Expired' ) ) );
		$result = ( new Client( $this->store ) )->post_form( '/oauth/token', array() );
		$this->assertSame( 'invalid_grant', $result->code );
		$this->assertSame( 'Expired', $result->message );
		$this->assertSame( 400, $result->status );
	}

	public function test_post_form_timeout(): void {
		Monkey\Functions\when( 'wp_remote_request' )->justReturn( new \WP_Error( 'http_request_failed', 'timed out' ) );
		$this->assertSame( 'TIMEOUT', ( new Client( $this->store ) )->post_form( '/oauth/token', array() )->code );
	}

	// Fix round A, G5 L6: an API address without HTTPS works only on a local or development site.

	private function client_for( string $base ): Client {
		$client       = new class( $this->store ) extends Client {
			/** @var string */
			public $base = '';
			protected function api_base(): string {
				return $this->base;
			}
		};
		$client->base = $base;
		return $client;
	}

	/**
	 * @dataProvider production_types
	 */
	public function test_http_base_is_refused_on_a_live_site( string $type ): void {
		$this->connect( array( 'method' => 'api_key', 'api_key' => 'k' ) );
		Monkey\Functions\when( 'wp_get_environment_type' )->justReturn( $type );
		$calls = 0;
		Monkey\Functions\when( 'wp_remote_request' )->alias( function () use ( &$calls ) { ++$calls; return $this->response( 200, array( 'data' => array() ) ); } );
		$client = $this->client_for( 'http://genready.example' );
		foreach ( array( $client->request( 'GET', '/api/v1/usage' ), $client->post_form( '/oauth/token', array() ) ) as $result ) {
			$this->assertInstanceOf( ApiError::class, $result );
			$this->assertNull( $result->status );
			$this->assertSame( 'INSECURE_BASE', $result->code );
			$this->assertSame( "GenReady's address must use HTTPS.", $result->user_message() );
		}
		$this->assertSame( 0, $calls );
	}

	public function production_types(): array {
		return array(
			'production' => array( 'production' ),
			'staging'    => array( 'staging' ),
		);
	}

	public function test_http_base_works_on_a_local_or_development_site(): void {
		$this->connect( array( 'method' => 'api_key', 'api_key' => 'k' ) );
		$urls = array();
		Monkey\Functions\when( 'wp_remote_request' )->alias( function ( $url ) use ( &$urls ) { $urls[] = $url; return $this->response( 200, array( 'data' => array( 'ok' => 1 ) ) ); } );
		foreach ( array( 'local', 'development' ) as $type ) {
			Monkey\Functions\when( 'wp_get_environment_type' )->justReturn( $type );
			$this->assertSame( array( 'ok' => 1 ), $this->client_for( 'http://127.0.0.1:5002' )->request( 'GET', '/api/v1/usage' ) );
		}
		$this->assertSame( array( 'http://127.0.0.1:5002/api/v1/usage', 'http://127.0.0.1:5002/api/v1/usage' ), $urls );
	}

	public function test_https_base_never_asks_for_the_environment(): void {
		$this->connect( array( 'method' => 'api_key', 'api_key' => 'k' ) );
		Monkey\Functions\expect( 'wp_get_environment_type' )->never();
		Monkey\Functions\when( 'wp_remote_request' )->justReturn( $this->response( 200, array( 'data' => array( 'ok' => 1 ) ) ) );
		$this->assertSame( array( 'ok' => 1 ), $this->client_for( 'https://genready.ai' )->request( 'GET', '/x' ) );
	}
}
