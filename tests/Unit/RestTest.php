<?php
namespace GenReady\Tests\Unit;

use Brain\Monkey;
use GenReady\Admin\FixCards;
use GenReady\Admin\Reports;
use GenReady\Admin\Rest;
use GenReady\Api\ApiError;
use GenReady\Compat\SeoPlugins;
use GenReady\Connection\ApiKey;
use GenReady\Connection\OAuth;
use GenReady\Connection\SiteSetup;
use GenReady\Connection\TokenStore;
use GenReady\Fixes\FixStore;
use GenReady\Fixes\JsonLdFix;
use GenReady\Fixes\LlmsTxtFix;
use GenReady\Fixes\RobotsFix;
use GenReady\Verification\MetaTag;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/fixtures/doubles.php';
require_once dirname( __DIR__ ) . '/fixtures/fix-doubles.php';
require_once dirname( __DIR__ ) . '/fixtures/rest-doubles.php';

class RestTest extends TestCase {
	const REPORT_ID = '11111111-2222-3333-4444-555555555555';
	const BASE      = "User-agent: *\nDisallow: /wp-admin/\n";

	/** @var array */
	private $options = array();
	/** @var array */
	private $transients = array();
	/** @var array */
	private $user_meta = array();
	/** @var TokenStore */
	private $store;
	/** @var FakeClient */
	private $client;
	/** @var MemoryFiles */
	private $files;
	/** @var FixStore */
	private $fixes;
	/** @var RecordingCaches */
	private $caches;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->options    = array( 'blog_public' => '1' );
		$this->transients = array();
		$this->user_meta  = array();
		$opts             = &$this->options;
		$trans            = &$this->transients;
		$meta             = &$this->user_meta;
		Monkey\Functions\when( 'wp_salt' )->justReturn( str_repeat( 's', 64 ) );
		Monkey\Functions\when( 'get_option' )->alias( function ( $k, $d = false ) use ( &$opts ) { return $opts[ $k ] ?? $d; } );
		Monkey\Functions\when( 'update_option' )->alias( function ( $k, $v ) use ( &$opts ) { $opts[ $k ] = $v; return true; } );
		Monkey\Functions\when( 'delete_option' )->alias( function ( $k ) use ( &$opts ) { unset( $opts[ $k ] ); return true; } );
		Monkey\Functions\when( 'get_transient' )->alias( function ( $k ) use ( &$trans ) { return $trans[ $k ] ?? false; } );
		Monkey\Functions\when( 'set_transient' )->alias( function ( $k, $v ) use ( &$trans ) { $trans[ $k ] = $v; return true; } );
		Monkey\Functions\when( 'delete_transient' )->alias( function ( $k ) use ( &$trans ) { unset( $trans[ $k ] ); return true; } );
		Monkey\Functions\when( 'update_user_meta' )->alias( function ( $u, $k, $v ) use ( &$meta ) { $meta[ $u ][ $k ] = $v; return true; } );
		Monkey\Functions\when( '__' )->returnArg( 1 );
		Monkey\Functions\when( 'sanitize_text_field' )->alias( function ( $v ) { return trim( strip_tags( (string) $v ) ); } );
		Monkey\Functions\when( 'sanitize_textarea_field' )->alias( function ( $v ) { return trim( strip_tags( (string) $v ) ); } );
		Monkey\Functions\when( 'home_url' )->alias( function ( $path = '' ) { return 'https://example.com' . $path; } );
		Monkey\Functions\when( 'wp_parse_url' )->alias( 'parse_url' );
		Monkey\Functions\when( 'get_current_user_id' )->justReturn( 7 );
		Monkey\Functions\when( 'current_user_can' )->justReturn( true );
		Monkey\Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Monkey\Functions\when( 'rest_url' )->alias( function ( $p ) { return 'https://example.com/wp-json/' . $p; } );
		Monkey\Functions\when( 'admin_url' )->alias( function ( $p ) { return 'https://example.com/wp-admin/' . $p; } );
		Monkey\Functions\when( 'esc_url_raw' )->returnArg( 1 );
		Monkey\Functions\when( 'wp_strip_all_tags' )->alias( 'strip_tags' );
		Monkey\Functions\when( 'get_bloginfo' )->justReturn( 'Example' );
		Monkey\Functions\when( '_n' )->alias( function ( $one, $many, $n ) { return 1 === $n ? $one : $many; } );
		$this->store  = new TokenStore();
		$this->client = new FakeClient();
		$this->files  = new MemoryFiles();
		$this->fixes  = new FixStore();
		$this->caches = new RecordingCaches();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function rest(): Rest {
		$domain = function (): string {
			$connection = $this->store->load();
			return is_array( $connection ) ? (string) ( $connection['domain_id'] ?? '' ) : '';
		};
		$meta   = new MetaTag();
		$seo    = new SeoPlugins( $this->files );
		$robots = new RobotsFix( $this->fixes, $this->files, $this->client, $this->caches, $domain );
		$llms   = new LlmsTxtFix( $this->fixes, $this->files, $this->client, $this->caches, $seo, $domain );
		$jsonld = new JsonLdFix( $this->fixes, $this->client, $this->caches, $seo, $domain );
		return new Rest(
			$this->store,
			$this->client,
			new OAuth( $this->store, $this->client ),
			new ApiKey( $this->store, $this->client ),
			new SiteSetup( $this->store, $this->client, $meta, $this->caches ),
			$meta,
			$this->caches,
			new Reports( $this->store, $this->client ),
			new FixCards( $this->fixes, $robots, $llms, $jsonld, $this->files )
		);
	}

	private function connect(): void {
		$this->store->save(
			array(
				'method'    => 'api_key',
				'api_key'   => 'gr_live_abcdefghijklmnop',
				'domain_id' => 'd1',
			)
		);
	}

	private function robots_merge( string $content ): array {
		return array(
			'content'         => $content,
			'diff'            => "--- a\n+++ b\n+User-agent: GPTBot",
			'summary'         => array( 'Allows GPTBot.' ),
			'notes'           => array(),
			'changed'         => true,
			'needsHumanInput' => false,
			'baseSha256'      => hash( 'sha256', self::BASE ),
			'live'            => null,
		);
	}

	private function fix( Rest $rest, string $kind, string $action, array $params = array() ) {
		return $rest->fix( new FakeRequest( array_merge( array( 'kind' => $kind, 'action' => $action ), $params ) ) );
	}

	private function assert_error( $result, string $message, int $status = 400 ): void {
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( $message, $result->get_error_message() );
		$this->assertSame( $status, $result->get_error_data()['status'] );
	}

	public function test_every_admin_route_requires_manage_options(): void {
		$routes = array();
		Monkey\Functions\when( 'register_rest_route' )->alias( function ( $ns, $path, $args ) use ( &$routes ) { $routes[ $path ] = $args; } );
		$this->rest()->register();
		Monkey\Functions\when( 'current_user_can' )->justReturn( false );
		$this->assertNotEmpty( $routes );
		foreach ( $routes as $path => $args ) {
			$this->assertFalse( call_user_func( $args['permission_callback'] ), $path );
		}
		$this->assertArrayNotHasKey( '/client', $routes ); // the public route lives in OAuth, not here
	}

	public function test_permission_callbacks_ask_for_manage_options(): void {
		$routes = array();
		$asked  = array();
		Monkey\Functions\when( 'register_rest_route' )->alias( function ( $ns, $path, $args ) use ( &$routes ) { $routes[ $path ] = $args; } );
		Monkey\Functions\when( 'current_user_can' )->alias( function ( $cap ) use ( &$asked ) { $asked[] = $cap; return true; } );
		$this->rest()->register();
		foreach ( $routes as $path => $args ) {
			$this->assertTrue( call_user_func( $args['permission_callback'] ), $path );
		}
		$this->assertSame( array( 'manage_options' ), array_values( array_unique( $asked ) ) );
	}

	public function test_fix_route_only_accepts_known_kinds(): void {
		$routes = array();
		Monkey\Functions\when( 'register_rest_route' )->alias( function ( $ns, $path ) use ( &$routes ) { $routes[] = $path; } );
		$this->rest()->register();
		$pattern = '#^' . current( preg_grep( '#^/fixes/.*<action>#', $routes ) ) . '$#';
		$this->assertSame( 1, preg_match( $pattern, '/fixes/robots/preview' ) );
		$this->assertSame( 1, preg_match( $pattern, '/fixes/llms/live-check' ) );
		$this->assertSame( 0, preg_match( $pattern, '/fixes/../preview' ) );
		$this->assertSame( 0, preg_match( $pattern, '/fixes/robots/delete' ) );
	}

	public function test_download_route_accepts_only_robots_and_llms(): void {
		$routes = array();
		Monkey\Functions\when( 'register_rest_route' )->alias( function ( $ns, $path ) use ( &$routes ) { $routes[] = $path; } );
		$this->rest()->register();
		$pattern = '#^' . current( preg_grep( '#/download$#', $routes ) ) . '$#';
		$this->assertSame( 1, preg_match( $pattern, '/fixes/robots/download' ) );
		$this->assertSame( 1, preg_match( $pattern, '/fixes/llms/download' ) );
		$this->assertSame( 0, preg_match( $pattern, '/fixes/jsonld/download' ) );
		$this->assertSame( 0, preg_match( $pattern, '/fixes/../download' ) );
	}

	public function test_download_returns_the_stored_preview_content(): void {
		$this->transients['genready_preview_robots_7'] = array( 'content' => "User-agent: GPTBot\nAllow: /\n", 'base_sha256' => str_repeat( 'a', 64 ) );
		$result = $this->rest()->download( new FakeRequest( array( 'kind' => 'robots', 'content' => 'evil' ) ) );
		$this->assertSame(
			array(
				'filename' => 'robots.txt',
				'content'  => "User-agent: GPTBot\nAllow: /\n",
			),
			$result
		);
		$this->assertArrayHasKey( 'genready_preview_robots_7', $this->transients, 'the preview stays for apply' );
		$this->assertSame( array(), $this->client->requests );
	}

	public function test_download_without_a_preview_says_it_expired(): void {
		$this->assert_error( $this->rest()->download( new FakeRequest( array( 'kind' => 'llms' ) ) ), 'The preview expired. Preview again.' );
		$this->assertInstanceOf( \WP_Error::class, $this->rest()->download( new FakeRequest( array( 'kind' => 'jsonld' ) ) ) );
	}

	public function test_apply_write_refusal_keeps_its_code_and_the_preview(): void {
		$this->connect();
		$this->files->put( 'robots.txt', self::BASE );
		$this->client->request_result = $this->robots_merge( self::BASE . "User-agent: GPTBot\nAllow: /\n" );
		$rest = $this->rest();
		$this->fix( $rest, 'robots', 'preview' );
		$this->files->writable = false;
		$result                = $this->fix( $rest, 'robots', 'apply' );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'CANNOT_WRITE', $result->get_error_data()['genready_code'] );
		$this->assertSame( 400, $result->get_error_data()['status'] );
		$this->assertArrayHasKey( 'genready_preview_robots_7', $this->transients, 'the admin can still download it' );
	}

	public function test_credits_exhausted_scan_returns_402(): void {
		$this->connect();
		$this->client->request_result = new ApiError( 402, 'CREDITS_EXHAUSTED', '' );
		$result                       = $this->rest()->scan( new FakeRequest() );
		$this->assert_error( $result, 'Your GenReady plan has no scans left for now.', 402 );
		$this->assertSame( 'CREDITS_EXHAUSTED', $result->get_error_data()['genready_code'] );
	}

	public function test_verify_rate_limit_returns_429(): void {
		$this->store->save( array( 'method' => 'api_key', 'api_key' => 'gr_live_abcdefghijklmnop', 'domain_id' => 'd1', 'verification_token' => 'tok12345678' ) );
		Monkey\Functions\when( 'update_option' )->justReturn( true );
		$this->client->request_result = new ApiError( 429, 'RATE_LIMITED', '' );
		$result                       = $this->rest()->verify( new FakeRequest() );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 429, $result->get_error_data()['status'] );
		$this->assertSame( 'RATE_LIMITED', $result->get_error_data()['genready_code'] );
	}

	public function test_agent_standard_not_found_is_a_warning(): void {
		$report = Reports::summarize( report_fixture() );
		$this->assertSame( 'warn', $report['sections']['agents'][0]['verdict'] );
		$fixture = report_fixture();
		$fixture['scores']['agentReadiness']['standards']['webmcp']['status'] = 'detected';
		$this->assertSame( 'pass', Reports::summarize( $fixture )['sections']['agents'][0]['verdict'] );
	}

	public function test_apply_uses_the_stored_preview_not_the_request_body(): void {
		$this->connect();
		$this->files->put( 'robots.txt', self::BASE );
		$this->client->request_result = $this->robots_merge( self::BASE . "User-agent: GPTBot\nAllow: /\n" );
		$rest    = $this->rest();
		$preview = $this->fix( $rest, 'robots', 'preview', array( 'content_signal' => true ) );
		$this->assertIsArray( $preview );
		$this->assertArrayNotHasKey( 'content', $preview, 'the file content stays on the server' );
		$this->assertArrayHasKey( 'genready_preview_robots_7', $this->transients );

		$result = $this->fix(
			$rest,
			'robots',
			'apply',
			array(
				'content'     => "User-agent: *\nDisallow: /\n",
				'base_sha256' => hash( 'sha256', self::BASE ),
				'preview'     => array( 'content' => 'evil' ),
			)
		);
		$this->assertTrue( $result['ok'] );
		$this->assertSame( self::BASE . "User-agent: GPTBot\nAllow: /\n", $this->files->files['robots.txt'] );
		$this->assertArrayNotHasKey( 'genready_preview_robots_7', $this->transients );
	}

	public function test_apply_without_a_preview_says_it_expired(): void {
		$this->connect();
		$this->files->put( 'robots.txt', self::BASE );
		$this->assert_error( $this->fix( $this->rest(), 'robots', 'apply' ), 'The preview expired. Preview again.' );
		$this->assertSame( self::BASE, $this->files->files['robots.txt'] );
	}

	public function test_preview_error_maps_402_to_status_402(): void {
		$this->connect();
		$this->files->put( 'robots.txt', self::BASE );
		$this->client->request_result = new ApiError( 402, 'PLAN_REQUIRED', 'Upgrade' );
		$result                       = $this->fix( $this->rest(), 'robots', 'preview' );
		$this->assert_error( $result, 'GenReady Pro and Agency generate this file.', 402 );
		$this->assertSame( 'PLAN_REQUIRED', $result->get_error_data()['genready_code'] );
		$this->assertArrayNotHasKey( 'genready_preview_robots_7', $this->transients );
	}

	public function test_plan_required_with_an_applied_fix_says_the_file_does_not_update(): void {
		$this->connect();
		$this->files->put( 'robots.txt', self::BASE );
		$this->fixes->put( 'robots', array( 'mode' => 'virtual', 'content' => 'x', 'base_sha256' => str_repeat( 'a', 64 ), 'status' => 'applied' ) );
		$this->client->request_result = new ApiError( 402, 'PLAN_REQUIRED', '' );
		$this->assert_error( $this->fix( $this->rest(), 'robots', 'preview' ), 'This file does not update on the Free plan.', 402 );
	}

	public function test_api_errors_never_return_401_or_403(): void {
		$this->connect();
		$this->files->put( 'robots.txt', self::BASE );
		$cases = array(
			array( new ApiError( 401, 'RECONNECT', '' ), 400 ),
			array( new ApiError( 403, 'FORBIDDEN', '' ), 400 ),
			array( new ApiError( 404, 'NOT_FOUND', '' ), 400 ),
			array( new ApiError( 402, 'DOMAIN_LIMIT_REACHED', '' ), 402 ),
			array( new ApiError( 402, 'CREDITS_EXHAUSTED', '' ), 402 ),
			array( new ApiError( 409, 'NOT_VERIFIED', '' ), 400 ),
			array( new ApiError( 422, 'UNSAFE_MERGE', 'Stopped for Googlebot.' ), 400 ),
			array( new ApiError( 429, 'RATE_LIMITED', '', array( 'retryAfterSeconds' => 120 ) ), 429 ),
			array( new ApiError( 500, 'INTERNAL_ERROR', '' ), 400 ),
			array( new ApiError( null, 'TIMEOUT', 'cURL error 28' ), 400 ),
		);
		foreach ( $cases as $case ) {
			$this->client->request_result = $case[0];
			$result                       = $this->fix( $this->rest(), 'robots', 'preview' );
			$this->assertInstanceOf( \WP_Error::class, $result );
			$data = $result->get_error_data();
			$this->assertSame( $case[1], $data['status'], $case[0]->code );
			$this->assertNotContains( $data['status'], array( 401, 403 ) );
			$this->assertSame( $case[0]->code, $data['genready_code'] );
			$this->assertSame( $case[0]->user_message(), $result->get_error_message() );
		}
	}

	public function test_report_404_clears_last_report_id_without_site_removed_text(): void {
		$this->connect();
		$this->options['genready_settings']        = array( 'last_report_id' => self::REPORT_ID );
		$this->transients['genready_report_cache'] = array( 'id' => 'other' );
		$this->client->request_result              = new ApiError( 404, 'NOT_FOUND', 'Report not found' );
		$result                                    = $this->rest()->report( new FakeRequest() );
		$this->assertSame( array( 'state' => 'none' ), $result );
		$this->assertArrayNotHasKey( 'last_report_id', $this->options['genready_settings'] );
		$this->assertArrayNotHasKey( 'genready_report_cache', $this->transients );
		$this->assertStringNotContainsString( 'removed', (string) json_encode( $result ) );
		$this->assertSame( array( 'GET', '/api/v1/reports/' . self::REPORT_ID, null ), $this->client->requests[0] );
	}

	public function test_report_pending_is_not_cached(): void {
		$this->connect();
		$this->options['genready_settings'] = array( 'last_report_id' => self::REPORT_ID );
		$this->client->request_result       = array(
			'reportId'  => self::REPORT_ID,
			'status'    => 'analyzing',
			'statusUrl' => '/api/v1/reports/' . self::REPORT_ID . '/status',
		);
		$this->assertSame( array( 'state' => 'pending' ), $this->rest()->report( new FakeRequest() ) );
		$this->assertArrayNotHasKey( 'genready_report_cache', $this->transients );
	}

	public function test_report_complete_is_cached_and_trimmed(): void {
		$this->connect();
		$this->options['genready_settings'] = array( 'last_report_id' => self::REPORT_ID );
		$this->client->request_result       = report_fixture();
		$result                             = $this->rest()->report( new FakeRequest() );
		$this->assertSame( 'complete', $result['state'] );
		$this->assertSame( 72, $result['report']['overall'] );
		$this->assertSame( 'https://genready.ai/report/' . self::REPORT_ID, $result['report']['report_link'] );
		$this->assertArrayNotHasKey( 'fixChecklist', $result['report'] );
		$this->assertSame( self::REPORT_ID, $this->transients['genready_report_cache']['id'] );
		// A second load uses the cache: no second call.
		$this->rest()->report( new FakeRequest() );
		$this->assertCount( 1, $this->client->requests );
	}

	public function test_report_failed_shows_the_server_message(): void {
		$this->connect();
		$this->options['genready_settings'] = array( 'last_report_id' => self::REPORT_ID );
		$this->client->request_result       = new ApiError( 422, 'ANALYSIS_FAILED', 'The site did not answer.' );
		$this->assertSame(
			array(
				'state'   => 'failed',
				'message' => 'The site did not answer.',
			),
			$this->rest()->report( new FakeRequest() )
		);
	}

	public function test_report_without_a_report_id_is_none_without_a_call(): void {
		$this->connect();
		$this->assertSame( array( 'state' => 'none' ), $this->rest()->report( new FakeRequest() ) );
		$this->options['genready_settings'] = array( 'last_report_id' => '../../etc' );
		$this->assertSame( array( 'state' => 'none' ), $this->rest()->report( new FakeRequest() ) );
		$this->assertSame( array(), $this->client->requests );
	}

	public function test_undo_works_without_a_connection(): void {
		$this->fixes->put( 'robots', array( 'mode' => 'virtual', 'content' => "User-agent: GPTBot\n", 'base_sha256' => str_repeat( 'a', 64 ), 'status' => 'applied' ) );
		$result = $this->fix( $this->rest(), 'robots', 'undo' );
		$this->assertTrue( $result['ok'] );
		$this->assertSame( array(), $this->fixes->get( 'robots' ) );
		$this->assertSame( array(), $this->client->requests );
	}

	public function test_preview_without_connection_makes_no_call(): void {
		$this->files->put( 'robots.txt', self::BASE );
		$this->options['genready_settings'] = array( 'last_report_id' => self::REPORT_ID );
		foreach ( array( 'robots', 'llms', 'jsonld' ) as $kind ) {
			$this->assert_error( $this->fix( $this->rest(), $kind, 'preview' ), 'Reconnect GenReady.' );
			$this->assert_error( $this->fix( $this->rest(), $kind, 'live-check' ), 'Reconnect GenReady.' );
		}
		$this->assertSame( array(), $this->client->requests );
	}

	public function test_disconnect_keeps_applied_fixes(): void {
		$this->connect();
		$record = array( 'mode' => 'virtual', 'content' => "User-agent: GPTBot\n", 'base_sha256' => str_repeat( 'a', 64 ), 'status' => 'applied' );
		$this->fixes->put( 'robots', $record );
		$fixes_before                                  = $this->options['genready_fix_robots'];
		$this->options['genready_meta']                = 'tok12345678';
		$this->options['genready_settings']            = array( 'last_report_id' => self::REPORT_ID, 'other' => 1 );
		$this->transients['genready_report_cache']     = array( 'id' => self::REPORT_ID );
		$this->transients['genready_account_cache']    = array( 'plan' => 'pro' );
		$this->transients['genready_preview_robots_7'] = array( 'content' => 'x' );
		$this->transients['genready_preview_llms_7']   = array( 'content' => 'x' );

		$this->assertSame( array( 'ok' => true ), $this->rest()->disconnect( new FakeRequest() ) );

		$this->assertNull( $this->store->load() );
		$this->assertSame( $fixes_before, $this->options['genready_fix_robots'] );
		$this->assertSame( 'tok12345678', $this->options['genready_meta'], 'the verification tag stays' );
		$this->assertSame( array( 'other' => 1 ), $this->options['genready_settings'] );
		$this->assertSame( array(), $this->transients );
		$this->assertSame( array(), $this->client->forms, 'an API key connection needs no revoke call' );
	}

	public function test_live_check_stores_the_result_in_the_fix_record(): void {
		$this->connect();
		$this->fixes->put( 'robots', array( 'mode' => 'virtual', 'content' => 'X', 'base_sha256' => str_repeat( 'a', 64 ), 'status' => 'applied' ) );
		$this->client->request_result = array(
			'status' => 200,
			'match'  => true,
			'cache'  => array(
				'age'           => null,
				'cfCacheStatus' => null,
			),
		);
		$result = $this->fix( $this->rest(), 'robots', 'live-check' );
		$this->assertSame( array( 'POST', '/api/v1/domains/d1/live-check', array( 'path' => '/robots.txt', 'sha256' => hash( 'sha256', 'X' ) ) ), $this->client->requests[0] );
		$record = $this->fixes->get( 'robots' );
		$this->assertSame(
			array(
				'status' => 200,
				'match'  => true,
				'cache'  => array(
					'age'           => null,
					'cfCacheStatus' => null,
				),
			),
			$record['live']
		);
		$this->assertIsInt( $record['live_at'] );
		$this->assertSame( 'X', $record['content'] );
		$this->assertSame( 'live', $result['card']['live']['state'] );
	}

	public function test_llms_live_check_uses_the_home_path(): void {
		$this->connect();
		Monkey\Functions\when( 'home_url' )->alias( function ( $path = '' ) { return 'https://example.com/blog' . $path; } );
		$this->fixes->put( 'llms', array( 'mode' => 'virtual', 'content' => 'L', 'base_sha256' => str_repeat( 'a', 64 ), 'status' => 'applied' ) );
		$this->client->request_result = array( 'status' => 404, 'match' => false, 'cache' => array( 'age' => null, 'cfCacheStatus' => null ) );
		$result                       = $this->fix( $this->rest(), 'llms', 'live-check' );
		$this->assertSame( '/blog/llms.txt', $this->client->requests[0][2]['path'] );
		$this->assertSame( 'no_route', $result['card']['live']['state'] );
	}

	public function test_live_check_is_not_offered_for_jsonld_or_an_unapplied_fix(): void {
		$this->connect();
		$this->assertInstanceOf( \WP_Error::class, $this->fix( $this->rest(), 'jsonld', 'live-check' ) );
		$this->assert_error( $this->fix( $this->rest(), 'robots', 'live-check' ), 'Apply the fix first.' );
		$this->assertSame( array(), $this->client->requests );
	}

	public function test_live_check_not_verified_keeps_its_code(): void {
		$this->connect();
		$this->fixes->put( 'robots', array( 'mode' => 'virtual', 'content' => 'X', 'base_sha256' => str_repeat( 'a', 64 ), 'status' => 'applied' ) );
		$this->client->request_result = new ApiError( 409, 'NOT_VERIFIED', 'Verify this site first.' );
		$result                       = $this->fix( $this->rest(), 'robots', 'live-check' );
		$this->assert_error( $result, 'Verify this site first.' );
		$this->assertSame( 'NOT_VERIFIED', $result->get_error_data()['genready_code'] );
	}

	/**
	 * @dataProvider live_states
	 */
	public function test_live_state_texts( string $kind, string $mode, array $live, string $state ): void {
		$cards = new FixCards( $this->fixes, new RobotsFix( $this->fixes, $this->files, $this->client, $this->caches, 'd1' ), new LlmsTxtFix( $this->fixes, $this->files, $this->client, $this->caches, new SeoPlugins( $this->files ), 'd1' ), new JsonLdFix( $this->fixes, $this->client, $this->caches, new SeoPlugins( $this->files ), 'd1' ), $this->files );
		$this->assertSame( $state, $cards->live_state( $kind, array( 'mode' => $mode, 'live' => $live ) )['state'] );
	}

	public function live_states(): array {
		$cache = function ( $age, $cf ) {
			return array(
				'age'           => $age,
				'cfCacheStatus' => $cf,
			);
		};
		return array(
			'match'           => array( 'robots', 'virtual', array( 'status' => 200, 'match' => true, 'cache' => $cache( null, null ) ), 'live' ),
			'unreachable'     => array( 'robots', 'virtual', array( 'status' => null, 'match' => false, 'cache' => $cache( null, null ) ), 'unreachable' ),
			'llms 404'        => array( 'llms', 'virtual', array( 'status' => 404, 'match' => false, 'cache' => $cache( null, null ) ), 'no_route' ),
			'llms file 404'   => array( 'llms', 'file', array( 'status' => 404, 'match' => false, 'cache' => $cache( null, null ) ), 'different' ),
			'cdn age'         => array( 'robots', 'virtual', array( 'status' => 200, 'match' => false, 'cache' => $cache( 120, null ) ), 'cdn_old' ),
			'cloudflare hit'  => array( 'llms', 'virtual', array( 'status' => 200, 'match' => false, 'cache' => $cache( null, 'HIT' ) ), 'cdn_old' ),
			'other content'   => array( 'robots', 'file', array( 'status' => 200, 'match' => false, 'cache' => $cache( 0, 'MISS' ) ), 'different' ),
		);
	}

	public function test_jsonld_apply_fills_values_on_the_stored_preview(): void {
		$this->connect();
		Monkey\Functions\when( 'url_to_postid' )->justReturn( 0 );
		Monkey\Functions\when( 'get_bloginfo' )->justReturn( 'Example' );
		Monkey\Functions\when( 'wp_strip_all_tags' )->alias( 'strip_tags' );
		$this->options['genready_settings'] = array( 'last_report_id' => self::REPORT_ID );
		$this->client->request_result       = array(
			'type'     => 'Organization',
			'node'     => array( '@type' => 'Organization', 'name' => 'Example', 'logo' => 'TODO: logo' ),
			'missing'  => array( 'logo' ),
			'changed'  => true,
			'summary'  => array(),
			'notes'    => array(),
			'existing' => array( 'types' => array(), 'missingProps' => array() ),
		);
		$rest    = $this->rest();
		$preview = $this->fix( $rest, 'jsonld', 'preview' );
		$this->assertSame( array( 'logo' ), $preview['fillable'] );
		$this->assertArrayNotHasKey( 'node', $preview );
		$this->assertSame( '/api/v1/artifacts/jsonld/merge', $this->client->requests[0][1] );

		// A node from the browser is ignored; only the listed form values are used.
		Monkey\Functions\when( 'esc_url_raw' )->returnArg( 1 );
		Monkey\Functions\when( 'wp_http_validate_url' )->returnArg( 1 );
		$result = $this->fix(
			$rest,
			'jsonld',
			'apply',
			array(
				'values' => array( 'logo' => 'https://example.com/logo.png' ),
				'node'   => array( '@type' => 'Organization', 'name' => 'Evil' ),
			)
		);
		$this->assertTrue( $result['ok'] );
		$home = $this->fixes->get( 'jsonld_home' );
		$this->assertSame( 'Example', $home['node']['name'] );
		$this->assertSame( 'https://example.com/logo.png', $home['node']['logo'] );
	}

	public function test_connect_key_error_is_a_wp_error(): void {
		$result = $this->rest()->connect_key( new FakeRequest( array( 'key' => 'nope' ) ) );
		$this->assert_error( $result, 'That is not a GenReady API key. Keys start with gr_live_.' );
		$this->assertSame( array(), $this->client->requests );
	}

	public function test_connect_oauth_returns_the_authorize_url(): void {
		Monkey\Functions\when( 'wp_generate_password' )->justReturn( str_repeat( 'k', 32 ) );
		$result = $this->rest()->connect_oauth( new FakeRequest( array( 'send_email' => false ) ) );
		$this->assertStringStartsWith( 'https://genready.ai/oauth/authorize?', $result['url'] );
		$this->assertStringNotContainsString( 'login_hint', $result['url'] );
	}

	public function test_remove_tag_disables_the_meta_and_purges_the_home_page(): void {
		$this->options['genready_meta'] = 'tok12345678';
		$this->assertSame( array( 'ok' => true ), $this->rest()->remove_tag( new FakeRequest() ) );
		$this->assertArrayNotHasKey( 'genready_meta', $this->options );
		$this->assertSame( array( 'https://example.com/' ), $this->caches->calls );
	}

	public function test_dismiss_notice_stores_user_meta(): void {
		$this->assertSame( array( 'ok' => true ), $this->rest()->dismiss_notice( new FakeRequest() ) );
		$this->assertSame( 1, $this->user_meta[7]['genready_notice_dismissed'] );
	}

	// Fix round A.

	private function cards(): FixCards {
		$seo = new SeoPlugins( $this->files );
		return new FixCards(
			$this->fixes,
			new RobotsFix( $this->fixes, $this->files, $this->client, $this->caches, 'd1' ),
			new LlmsTxtFix( $this->fixes, $this->files, $this->client, $this->caches, $seo, 'd1' ),
			new JsonLdFix( $this->fixes, $this->client, $this->caches, $seo, 'd1' ),
			$this->files
		);
	}

	public function test_sub_site_cards_show_the_network_refusal_and_no_file_action(): void {
		$this->files->may = false;
		$this->files->put( 'robots.txt', 'NETWORK FILE' );
		$this->files->put( 'llms.txt', 'NETWORK FILE' );
		$cards  = $this->cards();
		$robots = $cards->card( 'robots' );
		$llms   = $cards->card( 'llms' );
		$this->assertSame( 'network', $robots['mode'] );
		$this->assertTrue( $robots['blocked'] );
		$this->assertSame( 'On a multisite network, only a network admin on the main site can change robots.txt in the site root.', $robots['message'] );
		$this->assertTrue( $llms['blocked'] );
		$this->assertSame( 'On a multisite network, only a network admin on the main site can change llms.txt in the site root.', $llms['message'] );
		$this->assertFalse( $llms['as_file'] );
		unset( $this->files->files['llms.txt'] );
		$this->assertFalse( $cards->card( 'llms' )['as_file'], 'a sub-site never writes llms.txt as a file' );
		$this->files->may = true;
		$this->assertTrue( $cards->card( 'llms' )['as_file'] );
		$this->assertFalse( $cards->card( 'robots' )['as_file'] );
	}

	public function test_live_check_drops_the_result_when_the_fix_was_undone_during_the_call(): void {
		$this->connect();
		$this->fixes->put( 'robots', array( 'mode' => 'virtual', 'content' => 'X', 'base_sha256' => str_repeat( 'a', 64 ), 'status' => 'applied' ) );
		$this->client->on_request     = function () {
			$this->fixes->remove( 'robots' );
		};
		$this->client->request_result = array( 'status' => 200, 'match' => true, 'cache' => array( 'age' => null, 'cfCacheStatus' => null ) );
		$result                       = $this->fix( $this->rest(), 'robots', 'live-check' );
		$this->assertTrue( $result['ok'] );
		$this->assertSame( array(), $this->fixes->get( 'robots' ), 'the undone record stays removed' );
		$this->assertFalse( $result['card']['applied'] );
	}

	public function test_live_check_drops_the_result_when_the_fix_changed_during_the_call(): void {
		$this->connect();
		$this->fixes->put( 'llms', array( 'mode' => 'virtual', 'content' => 'OLD', 'base_sha256' => str_repeat( 'a', 64 ), 'status' => 'applied' ) );
		$this->client->on_request     = function () {
			$this->fixes->put( 'llms', array( 'mode' => 'virtual', 'content' => 'NEW', 'base_sha256' => str_repeat( 'b', 64 ), 'status' => 'applied' ) );
		};
		$this->client->request_result = array( 'status' => 200, 'match' => true, 'cache' => array( 'age' => null, 'cfCacheStatus' => null ) );
		$this->fix( $this->rest(), 'llms', 'live-check' );
		$record = $this->fixes->get( 'llms' );
		$this->assertSame( 'NEW', $record['content'] );
		$this->assertArrayNotHasKey( 'live', $record, 'a result for OLD must not be stored on NEW' );
	}

	// Fix round A, G3: the card computes "needs review" in the admin.

	public function test_robots_card_needs_review_when_the_base_changed_since_the_check(): void {
		$robots = new RobotsFix( $this->fixes, $this->files, $this->client, $this->caches, 'd1' );
		$output = self::BASE;
		Monkey\Functions\when( 'do_robots' )->alias(
			function () use ( $robots, &$output ) {
				echo $robots->filter( $output, '1' );
			}
		);
		$seo   = new SeoPlugins( $this->files );
		$cards = new FixCards( $this->fixes, $robots, new LlmsTxtFix( $this->fixes, $this->files, $this->client, $this->caches, $seo, 'd1' ), new JsonLdFix( $this->fixes, $this->client, $this->caches, $seo, 'd1' ), $this->files );
		$this->fixes->put( 'robots', array( 'mode' => 'virtual', 'content' => 'NEW', 'base_sha256' => hash( 'sha256', self::BASE ), 'status' => 'applied' ) );
		$this->assertSame( 'applied', $cards->card( 'robots' )['state'] );
		$output = "User-agent: *\nDisallow: /\n";
		$card   = $cards->card( 'robots' );
		$this->assertSame( 'needs_review', $card['state'] );
		$this->assertSame( 'Needs review', $card['state_text'] );
		$this->assertStringContainsString( 'Preview again', $card['message'] );
		$this->assertSame( 'applied', $this->fixes->get( 'robots' )['status'], 'the card writes nothing' );
	}
}
