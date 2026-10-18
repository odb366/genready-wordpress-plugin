<?php
namespace GenReady\Tests\Unit;

use Brain\Monkey;
use GenReady\Admin\FixCards;
use GenReady\Api\ApiError;
use GenReady\Onboarding\AnonymousScans;

require_once __DIR__ . '/RestTestCase.php';

class RestOnboardingTest extends RestTestCase {
	// Plan C2: without an account.

	const SCAN  = '66666666-6666-4666-8666-666666666666';
	const SCAN2 = '77777777-7777-4777-8777-777777777777';
	const TOKEN = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQ';

	public function test_scan_without_a_connection_starts_an_anonymous_scan(): void {
		$this->client->public_result = array( 'scanId' => self::SCAN, 'scanToken' => self::TOKEN );
		$this->assertSame( array( 'scan_id' => self::SCAN ), $this->rest()->scan( new FakeRequest( array( 'url' => 'https://example.com/pricing/' ) ) ) );
		$this->assertSame( 'https://example.com/pricing/', $this->client->publics[0][2]['pageUrl'] );
		$this->assertSame( array(), $this->client->requests, 'no call with a key' );
	}

	public function test_scan_without_a_url_scans_the_home_page(): void {
		$this->client->public_result = array( 'scanId' => self::SCAN, 'scanToken' => self::TOKEN );
		$this->rest()->scan( new FakeRequest() );
		$this->assertSame( 'https://example.com/', $this->client->publics[0][2]['pageUrl'] );
	}

	public function test_scan_with_a_connection_scans_the_given_page_with_the_key(): void {
		$this->connect();
		$this->client->request_result = array( 'reportId' => self::REPORT_ID );
		$this->assertSame( array( 'report_id' => self::REPORT_ID ), $this->rest()->scan( new FakeRequest( array( 'url' => 'https://example.com/pricing/' ) ) ) );
		$this->assertSame( array( 'POST', '/api/v1/analyze', array( 'url' => 'https://example.com/pricing/', 'scope' => 'full' ) ), $this->client->requests[0] );
		$this->assertSame( array(), $this->client->publics );
	}

	public function test_scan_on_another_site_is_refused_without_a_call(): void {
		$this->assert_error( $this->rest()->scan( new FakeRequest( array( 'url' => 'https://example.org/' ) ) ), 'Enter an address on this site.' );
		$this->assertSame( array(), $this->client->publics );
	}

	public function test_scan_limit_is_a_429_with_the_limit_text(): void {
		$this->client->public_result = new ApiError( 429, 'RATE_LIMITED', '', array( 'retryAfterSeconds' => 0 ) );
		$this->assert_error( $this->rest()->scan( new FakeRequest() ), 'Scan again tomorrow, or create a free account.', 429 );
	}

	public function test_report_without_a_connection_reads_the_stored_scan_with_its_token(): void {
		$this->scans()->remember( self::SCAN, 'https://example.com/', self::TOKEN );
		$this->client->public_result = report_fixture( array( 'reportId' => self::SCAN ) );
		$result                      = $this->rest()->report( new FakeRequest( array( 'scan' => self::SCAN ) ) );
		$this->assertSame( 'complete', $result['state'] );
		$this->assertSame( self::SCAN, $result['scan_id'] );
		$this->assertSame( array( 'GET', '/api/v1/wp/scans/' . self::SCAN, null, self::TOKEN, 15 ), $this->client->publics[0] );
		$this->rest()->report( new FakeRequest( array( 'scan' => self::SCAN ) ) );
		$this->assertCount( 1, $this->client->publics, 'cached for 5 minutes' );
	}

	public function test_report_uses_the_newest_scan_when_the_id_is_not_in_the_list(): void {
		$this->scans()->remember( self::SCAN, 'https://example.com/', self::TOKEN );
		$this->scans()->remember( self::SCAN2, 'https://example.com/a/', self::TOKEN );
		$this->client->public_result = array( 'reportId' => self::SCAN2, 'status' => 'analyzing' );
		$result                      = $this->rest()->report( new FakeRequest( array( 'scan' => 'ffffffff-ffff-4fff-8fff-ffffffffffff' ) ) );
		$this->assertSame( array( 'state' => 'pending', 'scan_id' => self::SCAN2 ), $result );
		$this->assertSame( '/api/v1/wp/scans/' . self::SCAN2, $this->client->publics[0][1] );
	}

	public function test_report_of_a_gone_scan_says_so_and_drops_it(): void {
		$this->scans()->remember( self::SCAN, 'https://example.com/', self::TOKEN );
		$this->client->public_result = new ApiError( 404, 'NOT_FOUND', 'Scan not found' );
		$result                      = $this->rest()->report( new FakeRequest() );
		$this->assertSame( 'gone', $result['state'] );
		$this->assertSame( 'This scan is no longer available. Check the page again.', $result['message'] );
		$this->assertSame( array(), $this->scans()->all() );
	}

	public function test_report_without_a_connection_or_a_scan_is_none_without_a_call(): void {
		$this->options['genready_settings'] = array( 'last_report_id' => self::REPORT_ID );
		$this->assertSame( array( 'state' => 'none' ), $this->rest()->report( new FakeRequest() ) );
		$this->assertSame( array(), $this->client->publics );
		$this->assertSame( array(), $this->client->requests );
	}

	public function test_account_route_creates_the_account(): void {
		$this->client->public_result = array(
			'apiKey'            => 'gr_live_' . str_repeat( 'k', 32 ),
			'domainId'          => '33333333-3333-4333-8333-333333333333',
			'verificationToken' => str_repeat( 'f', 48 ),
			'adoptedScanIds'    => array(),
		);
		$this->transients['genready_account_cache'] = array( 'plan' => 'old' );
		$this->assertSame( array( 'ok' => true ), $this->rest()->account( new FakeRequest( array( 'email' => 'owner@example.com' ) ) ) );
		$this->assertNotNull( $this->store->load() );
		$this->assertArrayNotHasKey( 'genready_account_cache', $this->transients );
	}

	public function test_account_errors_keep_their_code(): void {
		$cases = array(
			array( new ApiError( 409, 'PROOF_FAILED', '' ), 'PROOF_FAILED', 400 ),
			array( new ApiError( 409, 'ACCOUNT_EXISTS', '' ), 'ACCOUNT_EXISTS', 400 ),
			array( new ApiError( 429, 'RATE_LIMITED', '' ), 'RATE_LIMITED', 429 ),
			array( new ApiError( 503, 'WP_ONBOARDING_OFF', '' ), 'WP_ONBOARDING_OFF', 400 ),
		);
		foreach ( $cases as $case ) {
			unset( $this->transients['genready_anon_off'] );
			$this->client->public_result = $case[0];
			$result                      = $this->rest()->account( new FakeRequest( array( 'email' => 'owner@example.com' ) ) );
			$this->assertSame( $case[1], $result->get_error_data()['genready_code'] );
			$this->assertSame( $case[2], $result->get_error_data()['status'] );
		}
	}

	public function test_connect_key_and_disconnect_clear_the_anonymous_scans(): void {
		$this->scans()->remember( self::SCAN, 'https://example.com/', self::TOKEN );
		$this->client->request_result = array( 'domain' => array( 'id' => 'd1', 'verificationToken' => 'tok12345678' ) );
		$this->rest()->connect_key( new FakeRequest( array( 'key' => 'gr_live_abcdefghijklmnop' ) ) );
		$this->assertArrayNotHasKey( AnonymousScans::OPTION, $this->options );

		$this->scans()->remember( self::SCAN, 'https://example.com/', self::TOKEN );
		$this->rest()->disconnect( new FakeRequest() );
		$this->assertArrayNotHasKey( AnonymousScans::OPTION, $this->options );
	}

	public function test_the_new_routes_check_their_arguments(): void {
		$routes = array();
		Monkey\Functions\when( 'register_rest_route' )->alias( function ( $ns, $path, $args ) use ( &$routes ) { $routes[ $path ] = $args; } );
		$rest = $this->rest();
		$rest->register();
		$this->assertArrayHasKey( '/account', $routes );
		$this->assertSame( 'POST', $routes['/account']['methods'] );
		$validate_scan = $routes['/report']['args']['scan']['validate_callback'];
		$this->assertTrue( call_user_func( $validate_scan, self::SCAN ) );
		$this->assertTrue( call_user_func( $validate_scan, '' ) );
		$this->assertFalse( call_user_func( $validate_scan, '../../x' ) );
		$validate_url = $routes['/scan']['args']['url']['validate_callback'];
		$this->assertFalse( call_user_func( $validate_url, str_repeat( 'a', 2049 ) ) );
		$this->assertFalse( call_user_func( $validate_url, array() ) );
	}

	public function test_cards_without_a_connection_ask_for_an_account(): void {
		$card = FixCards::without_account( array( 'applied' => true, 'message' => 'a refusal' ) );
		$this->assertSame( 'Create a free account first. GenReady Pro and Agency generate this file. Undo works without a connection.', $card['message'] );
		$this->assertTrue( $card['account_link'] );
		$this->assertSame( 'Create a free account first. GenReady Pro and Agency generate this file.', FixCards::without_account( array( 'applied' => false ) )['message'] );
	}

	public function test_undo_without_a_connection_returns_the_account_text(): void {
		$this->fixes->put( 'robots', array( 'mode' => 'virtual', 'content' => "User-agent: GPTBot\n", 'base_sha256' => str_repeat( 'a', 64 ), 'status' => 'applied' ) );
		$result = $this->fix( $this->rest(), 'robots', 'undo' );
		$this->assertSame( 'Create a free account first. GenReady Pro and Agency generate this file.', $result['card']['message'] );
		$this->assertTrue( $result['card']['account_link'] );
	}
}
