<?php
namespace GenReady\Tests\Unit;

use Brain\Monkey;
use GenReady\Api\ApiError;
use GenReady\Api\Client;
use GenReady\Connection\OAuth;
use GenReady\Connection\TokenStore;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/fixtures/doubles.php';
require_once dirname( __DIR__ ) . '/fixtures/http-doubles.php';

class OAuthTest extends TestCase {
	use ScriptedHttp;

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
		Monkey\Functions\when( 'rest_url' )->justReturn( 'https://example.com/wp-json/genready/v1/client' );
		Monkey\Functions\when( 'admin_url' )->justReturn( 'https://example.com/wp-admin/admin.php?page=genready&genready_cb=1' );
		Monkey\Functions\when( 'home_url' )->justReturn( 'https://Example.com' );
		Monkey\Functions\when( 'wp_parse_url' )->alias( 'parse_url' );
		$this->store  = new TokenStore();
		$this->client = new FakeClient();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function oauth( ?TokenStore $store = null ): OAuth {
		return new OAuth( $store ?? $this->store, $this->client );
	}

	private function pending(): void {
		Monkey\Functions\when( 'get_transient' )->justReturn( array( 'state' => 'right', 'verifier' => 'v' ) );
		Monkey\Functions\when( 'delete_transient' )->justReturn( true );
	}

	private function tokens( array $override = array() ): array {
		return array_merge(
			array(
				'access_token'  => 'A1',
				'token_type'    => 'Bearer',
				'expires_in'    => 3600,
				'refresh_token' => 'R1',
				'scope'         => 'x',
				'domain_id'     => 'd1',
			),
			$override
		);
	}

	private function connect_oauth( TokenStore $store ): void {
		$store->save( array( 'method' => 'oauth', 'access_token' => 'A1', 'refresh_token' => 'R1', 'client_id' => 'cid' ) );
	}

	public function test_client_document_lists_only_this_sites_redirect(): void {
		$doc = $this->oauth()->client_document();
		$this->assertSame( 'https://example.com/wp-json/genready/v1/client', $doc['client_id'] );
		$this->assertSame( 'GenReady for WordPress', $doc['client_name'] );
		$this->assertSame( 'https://Example.com', $doc['client_uri'] );
		$this->assertSame( array( 'https://example.com/wp-admin/admin.php?page=genready&genready_cb=1' ), $doc['redirect_uris'] );
		$this->assertSame( array( 'authorization_code', 'refresh_token' ), $doc['grant_types'] );
		$this->assertSame( array( 'code' ), $doc['response_types'] );
		$this->assertSame( 'genready-wordpress', $doc['software_id'] );
		$this->assertSame( GENREADY_VERSION, $doc['software_version'] );
		$this->assertSame( 'none', $doc['token_endpoint_auth_method'] );
	}

	public function test_authorize_url_uses_s256_and_stores_state_per_user(): void {
		$saved = null;
		Monkey\Functions\when( 'set_transient' )->alias( function ( $k, $v, $ttl ) use ( &$saved ) { $saved = array( $k, $v, $ttl ); return true; } );
		Monkey\Functions\when( 'wp_generate_password' )->justReturn( str_repeat( 'a', 32 ) );
		$url = $this->oauth()->authorize_url( 7, false );
		parse_str( (string) parse_url( $url, PHP_URL_QUERY ), $q );
		$this->assertSame( 'S256', $q['code_challenge_method'] );
		$this->assertSame( 'genready_oauth_7', $saved[0] );
		$this->assertSame( 600, $saved[2] );
		$this->assertSame( $saved[1]['state'], $q['state'] );
		$this->assertArrayNotHasKey( 'login_hint', $q );
	}

	public function test_authorize_url_adds_login_hint_only_when_asked(): void {
		Monkey\Functions\when( 'set_transient' )->justReturn( true );
		Monkey\Functions\when( 'wp_generate_password' )->justReturn( str_repeat( 'a', 32 ) );
		Monkey\Functions\when( 'is_email' )->alias( function ( $v ) { return false !== strpos( (string) $v, '@' ); } );
		Monkey\Functions\when( 'get_userdata' )->justReturn( (object) array( 'user_email' => 'kris@example.com' ) );
		parse_str( (string) parse_url( $this->oauth()->authorize_url( 7, true ), PHP_URL_QUERY ), $with );
		parse_str( (string) parse_url( $this->oauth()->authorize_url( 7, false ), PHP_URL_QUERY ), $without );
		$this->assertSame( 'kris@example.com', $with['login_hint'] );
		$this->assertArrayNotHasKey( 'login_hint', $without );
	}

	public function test_callback_rejects_a_wrong_state(): void {
		$this->pending();
		$r = $this->oauth()->handle_callback( 7, array( 'state' => 'wrong', 'code' => 'c' ) );
		$this->assertArrayHasKey( 'error', $r );
		$this->assertSame( array(), $this->client->forms );
	}

	public function test_callback_error_param_is_checked_after_state(): void {
		$this->pending();
		$r = $this->oauth()->handle_callback( 7, array( 'state' => 'wrong', 'error' => 'access_denied', 'error_description' => 'Secret words' ) );
		$this->assertSame( 'The connection request expired. Click Connect again.', $r['error'] );
	}

	public function test_callback_shows_a_capped_error_text_when_state_matches(): void {
		$this->pending();
		$r = $this->oauth()->handle_callback( 7, array( 'state' => 'right', 'error' => 'access_denied', 'error_description' => str_repeat( 'x', 500 ) ) );
		$this->assertSame( 200, strlen( $r['error'] ) );
	}

	public function test_callback_stores_tokens_and_verification_token(): void {
		$this->pending();
		$this->client->form_result    = $this->tokens();
		$this->client->request_result = array( 'domain' => array( 'id' => 'd1', 'verificationToken' => 't1' ) );
		$r                            = $this->oauth()->handle_callback( 7, array( 'state' => 'right', 'code' => 'c' ) );
		$this->assertSame( array( 'ok' => true ), $r );
		$saved = $this->store->load();
		$this->assertSame( 'oauth', $saved['method'] );
		$this->assertSame( 'A1', $saved['access_token'] );
		$this->assertSame( 'R1', $saved['refresh_token'] );
		$this->assertSame( 'd1', $saved['domain_id'] );
		$this->assertSame( 't1', $saved['verification_token'] );
		$this->assertSame( 'https://example.com/wp-json/genready/v1/client', $saved['client_id'] );
		$this->assertGreaterThan( time() + 3000, $saved['access_expires'] );
		$this->assertSame( array( 'POST', '/api/v1/domains', array( 'hostname' => 'example.com' ) ), $this->client->requests[0] );
	}

	public function test_callback_keeps_connection_when_domains_call_fails(): void {
		$this->pending();
		$this->client->form_result    = $this->tokens();
		$this->client->request_result = new ApiError( 402, 'DOMAIN_LIMIT_REACHED', '' );
		$r                            = $this->oauth()->handle_callback( 7, array( 'state' => 'right', 'code' => 'c' ) );
		$this->assertTrue( $r['ok'] );
		$this->assertSame( 'Your plan has no free site slot. Remove a site in GenReady or upgrade.', $r['notice'] );
		$saved = $this->store->load();
		$this->assertSame( 'A1', $saved['access_token'] );
		$this->assertArrayNotHasKey( 'verification_token', $saved );
	}

	public function test_callback_drops_the_verification_token_when_domain_ids_differ(): void {
		$this->pending();
		$this->client->form_result    = $this->tokens();
		$this->client->request_result = array( 'domain' => array( 'id' => 'other', 'verificationToken' => 't1' ) );
		$this->oauth()->handle_callback( 7, array( 'state' => 'right', 'code' => 'c' ) );
		$saved = $this->store->load();
		$this->assertSame( 'd1', $saved['domain_id'] );
		$this->assertArrayNotHasKey( 'verification_token', $saved );
	}

	public function test_callback_rejects_a_malformed_token_response(): void {
		$this->pending();
		$this->client->form_result = $this->tokens( array( 'access_token' => array( 'x' ) ) );
		$r                         = $this->oauth()->handle_callback( 7, array( 'state' => 'right', 'code' => 'c' ) );
		$this->assertArrayHasKey( 'error', $r );
		$this->assertNull( $this->store->load() );
	}

	public function test_refresh_takes_the_lock_and_stores_new_tokens(): void {
		$store = new LockableStore( true );
		$this->connect_oauth( $store );
		$this->client->form_result = array( 'access_token' => 'A2', 'refresh_token' => 'R2', 'expires_in' => 3600 );
		$this->assertTrue( $this->oauth( $store )->refresh() );
		$this->assertSame( 'R2', $store->load()['refresh_token'] );
		$this->assertSame( 'A2', $store->load()['access_token'] );
		$this->assertSame( 'refresh_token', $this->client->forms[0][1]['grant_type'] );
		$this->assertSame( 'cid', $this->client->forms[0][1]['client_id'] );
		$this->assertTrue( $store->released );
	}

	public function test_refresh_skips_when_another_request_holds_the_lock(): void {
		$store = new LockableStore( false );
		$this->connect_oauth( $store );
		$this->assertFalse( $this->oauth( $store )->refresh() );
		$this->assertSame( array(), $this->client->forms );
	}

	public function test_refresh_invalid_grant_clears_store(): void {
		$store = new LockableStore( true );
		$this->connect_oauth( $store );
		$this->client->form_result = new ApiError( 400, 'invalid_grant', 'expired' );
		$this->assertFalse( $this->oauth( $store )->refresh() );
		$this->assertNull( $store->load() );
		$this->assertTrue( $store->released );
	}

	public function test_refresh_keeps_tokens_on_a_server_error(): void {
		$store = new LockableStore( true );
		$this->connect_oauth( $store );
		$this->client->form_result = new ApiError( 503, 'HTTP_503', '' );
		$this->assertFalse( $this->oauth( $store )->refresh() );
		$this->assertSame( 'R1', $store->load()['refresh_token'] );
		$this->assertTrue( $store->released );
	}

	public function test_revoke_clears_even_when_the_call_fails(): void {
		$this->connect_oauth( $this->store );
		$this->client->form_result = new ApiError( null, 'TIMEOUT', '' );
		$this->oauth()->revoke();
		$this->assertSame( array( '/oauth/revoke', array( 'token' => 'R1', 'client_id' => 'cid' ) ), $this->client->forms[0] );
		$this->assertNull( $this->store->load() );
	}

	public function test_revoke_api_key_makes_no_call(): void {
		$this->store->save( array( 'method' => 'api_key', 'api_key' => 'gr_live_x' ) );
		$this->oauth()->revoke();
		$this->assertSame( array(), $this->client->forms );
		$this->assertNull( $this->store->load() );
	}

	// Fix round A, G2: never save a record loaded before a call that can refresh.

	public function test_callback_keeps_the_tokens_a_refresh_rotated_during_the_domains_call(): void {
		$this->pending();
		$store  = new LockableStore( true );
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
				array( 200, $this->tokens() ),
				array( 401, array( 'error' => array( 'code' => 'UNAUTHORIZED', 'message' => 'expired' ) ) ),
				array( 200, array( 'access_token' => 'A2', 'refresh_token' => 'R2', 'expires_in' => 3600 ) ),
				array( 200, array( 'data' => array( 'domain' => array( 'id' => 'd1', 'verificationToken' => 't1' ) ) ) ),
			)
		);
		$this->assertSame( array( 'ok' => true ), $oauth->handle_callback( 7, array( 'state' => 'right', 'code' => 'c' ) ) );
		$this->assertSame( 'Bearer A1', $this->bearer( 1 ) );
		$this->assertSame( 'Bearer A2', $this->bearer( 3 ) );
		$saved = $store->load();
		$this->assertSame( 'A2', $saved['access_token'] );
		$this->assertSame( 'R2', $saved['refresh_token'] );
		$this->assertSame( 't1', $saved['verification_token'] );
		$this->assertSame( 'd1', $saved['domain_id'] );
	}
}
