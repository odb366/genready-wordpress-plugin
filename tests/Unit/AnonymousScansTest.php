<?php
namespace GenReady\Tests\Unit;

use Brain\Monkey;
use GenReady\Api\ApiError;
use GenReady\Connection\TokenStore;
use GenReady\Onboarding\AnonymousScans;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/fixtures/doubles.php';

class AnonymousScansTest extends TestCase {
	const ID1    = '11111111-1111-4111-8111-111111111111';
	const ID2    = '22222222-2222-4222-8222-222222222222';
	const TOKEN1 = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQ';
	const TOKEN2 = 'ZYXWVUTSRQPONMLKJIHGFEDCBAzyxwvutsrqponmlkj';

	/** @var array */
	private $options = array();
	/** @var array */
	private $transients = array();
	/** @var array */
	private $ttl = array();
	/** @var FakeClient */
	private $client;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->options    = array(
			'time_format'         => 'H:i',
			'permalink_structure' => '/%postname%/',
		);
		$this->transients = array();
		$this->ttl        = array();
		$opts             = &$this->options;
		$trans            = &$this->transients;
		$ttl              = &$this->ttl;
		Monkey\Functions\when( 'wp_salt' )->justReturn( str_repeat( 's', 64 ) );
		Monkey\Functions\when( 'get_option' )->alias( function ( $k, $d = false ) use ( &$opts ) { return $opts[ $k ] ?? $d; } );
		Monkey\Functions\when( 'update_option' )->alias( function ( $k, $v ) use ( &$opts ) { $opts[ $k ] = $v; return true; } );
		Monkey\Functions\when( 'delete_option' )->alias( function ( $k ) use ( &$opts ) { unset( $opts[ $k ] ); return true; } );
		Monkey\Functions\when( 'get_transient' )->alias( function ( $k ) use ( &$trans ) { return $trans[ $k ] ?? false; } );
		Monkey\Functions\when( 'set_transient' )->alias( function ( $k, $v, $t = 0 ) use ( &$trans, &$ttl ) { $trans[ $k ] = $v; $ttl[ $k ] = $t; return true; } );
		Monkey\Functions\when( 'home_url' )->alias( function ( $path = '' ) { return 'https://example.com' . $path; } );
		Monkey\Functions\when( 'wp_parse_url' )->alias( function ( $url, $component = -1 ) { return parse_url( $url, $component ); } );
		Monkey\Functions\when( 'esc_url_raw' )->returnArg( 1 );
		Monkey\Functions\when( '__' )->returnArg( 1 );
		Monkey\Functions\when( 'wp_date' )->alias(
			function ( $format, $time ) {
				$date = new \DateTime( '@' . $time );
				$date->setTimezone( new \DateTimeZone( 'Europe/Sofia' ) );
				return $date->format( $format );
			}
		);
		$this->client = new FakeClient();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function scans(): AnonymousScans {
		return new AnonymousScans( new TokenStore(), $this->client );
	}

	private static function id( int $n ): string {
		return sprintf( '%08d-0000-4000-8000-%012d', $n, $n );
	}

	public function test_start_sends_the_site_the_page_and_the_version_then_stores_the_scan(): void {
		$this->client->public_result = array( 'scanId' => self::ID1, 'scanToken' => self::TOKEN1 );
		$this->assertSame( array( 'scan_id' => self::ID1 ), $this->scans()->start( 'https://example.com/pricing/' ) );
		$this->assertSame(
			array( 'POST', '/api/v1/wp/scans', array( 'siteUrl' => 'https://example.com/', 'pageUrl' => 'https://example.com/pricing/', 'pluginVersion' => GENREADY_VERSION ), null, 15 ),
			$this->client->publics[0]
		);
		$all = $this->scans()->all();
		$this->assertSame( self::ID1, $all[0]['id'] );
		$this->assertSame( 'https://example.com/pricing/', $all[0]['url'] );
		$this->assertIsInt( $all[0]['at'] );
		$this->assertArrayNotHasKey( 'token', $all[0] );
		$this->assertStringNotContainsString( self::TOKEN1, serialize( $this->options ), 'the token is stored encrypted' );
		$this->assertSame( array( self::TOKEN1 ), $this->scans()->tokens() );
	}

	public function test_start_refuses_another_site_without_a_call(): void {
		$result = $this->scans()->start( 'https://example.org/' );
		$this->assertSame( 'Enter an address on this site.', $result['error'] );
		$this->assertSame( 'NOT_THIS_SITE', $result['code'] );
		$this->assertSame( array(), $this->client->publics );
	}

	public function test_start_maps_a_page_that_is_not_public_and_stores_nothing(): void {
		$this->client->public_result = new ApiError( 400, 'INVALID_URL', 'private address' );
		$result                      = $this->scans()->start( 'https://example.com/' );
		$this->assertSame( 'GenReady can check only pages that anyone can open on the internet.', $result['error'] );
		$this->assertSame( 'INVALID_URL', $result['code'] );
		$this->assertArrayNotHasKey( AnonymousScans::OPTION, $this->options );
	}

	public function test_a_bad_server_answer_is_not_stored(): void {
		$this->client->public_result = array( 'scanId' => 'x', 'scanToken' => 'short' );
		$this->assertSame( 'BAD_RESPONSE', $this->scans()->start( 'https://example.com/' )['code'] );
		$this->assertArrayNotHasKey( AnonymousScans::OPTION, $this->options );
	}

	public function test_limit_text_uses_the_time_on_the_same_day_and_tomorrow_after_midnight(): void {
		$morning = ( new \DateTime( '2026-10-16 10:00:00', new \DateTimeZone( 'Europe/Sofia' ) ) )->getTimestamp();
		$this->assertSame( 'Scan again after 11:00, or create a free account.', AnonymousScans::limit_message( 3600, $morning ) );
		// 23:30 in Sofia is still 2026-10-16 in UTC (20:30), but the wait ends on 2026-10-17 in the site's time zone.
		$night = ( new \DateTime( '2026-10-16 23:30:00', new \DateTimeZone( 'Europe/Sofia' ) ) )->getTimestamp();
		$this->assertSame( 'Scan again tomorrow, or create a free account.', AnonymousScans::limit_message( 3600, $night ) );
		$this->assertSame( 'Scan again tomorrow, or create a free account.', AnonymousScans::limit_message( 0, $morning ) );
	}

	public function test_start_429_gives_the_limit_text(): void {
		$this->client->public_result = new ApiError( 429, 'RATE_LIMITED', '', array( 'retryAfterSeconds' => 60 ) );
		$result                      = $this->scans()->start( 'https://example.com/' );
		$this->assertStringStartsWith( 'Scan again ', $result['error'] );
		$this->assertStringEndsWith( ', or create a free account.', $result['error'] );
		$this->assertSame( 429, $result['status'] );
	}

	// A 401 or 404 here has no account behind it: "Reconnect GenReady." would be wrong.
	public function test_start_shows_account_texts_only_for_codes_that_make_sense_without_an_account(): void {
		foreach ( array( new ApiError( 401, 'UNAUTHORIZED', '' ), new ApiError( 404, 'NOT_FOUND', '' ), new ApiError( 402, 'PLAN_REQUIRED', '' ) ) as $error ) {
			$this->client->public_result = $error;
			$result                      = $this->scans()->start( 'https://example.com/' );
			$this->assertSame( 'GenReady could not do this. Try again.', $result['error'], $error->code );
			$this->assertSame( $error->code, $result['code'] );
		}
		$this->client->public_result = new ApiError( null, 'TIMEOUT', '' );
		$this->assertSame( 'GenReady did not answer. Try again.', $this->scans()->start( 'https://example.com/' )['error'] );
	}

	public function test_start_429_reads_a_wait_that_is_a_string_or_a_float(): void {
		Monkey\Functions\when( 'wp_date' )->alias( function ( $format ) { return 'Y-m-d' === $format ? '2026-10-16' : '11:00'; } );
		foreach ( array( '3600', 3600.0, 3600 ) as $wait ) {
			$this->client->public_result = new ApiError( 429, 'RATE_LIMITED', '', array( 'retryAfterSeconds' => $wait ) );
			$this->assertSame( 'Scan again after 11:00, or create a free account.', $this->scans()->start( 'https://example.com/' )['error'], var_export( $wait, true ) );
		}
	}

	public function test_503_pauses_the_scans_for_an_hour(): void {
		$this->client->public_result = new ApiError( 503, 'WP_ONBOARDING_OFF', '' );
		$result                      = $this->scans()->start( 'https://example.com/' );
		$this->assertSame( 'GenReady paused scans without an account. Connect with a GenReady account.', $result['error'] );
		$this->assertTrue( AnonymousScans::paused() );
		$this->assertSame( 3600, $this->ttl[ AnonymousScans::OFF ] );
		$second = $this->scans()->start( 'https://example.com/' );
		$this->assertSame( 'WP_ONBOARDING_OFF', $second['code'] );
		$this->assertCount( 1, $this->client->publics, 'no call while paused' );
	}

	public function test_keeps_the_ten_newest_scans_newest_first(): void {
		$scans = $this->scans();
		for ( $n = 1; $n <= 12; $n++ ) {
			$scans->remember( self::id( $n ), 'https://example.com/p' . $n . '/', self::TOKEN1 );
		}
		$all = $scans->all();
		$this->assertCount( 10, $all );
		$this->assertSame( self::id( 12 ), $all[0]['id'] );
		$this->assertSame( self::id( 3 ), $all[9]['id'] );
	}

	public function test_remembering_a_scan_again_moves_it_to_the_top(): void {
		$scans = $this->scans();
		$scans->remember( self::ID1, 'https://example.com/', self::TOKEN1 );
		$scans->remember( self::ID2, 'https://example.com/a/', self::TOKEN2 );
		$scans->remember( self::ID1, 'https://example.com/', self::TOKEN1 );
		$this->assertSame( array( self::ID1, self::ID2 ), array_column( $scans->all(), 'id' ) );
	}

	public function test_remember_ignores_a_malformed_id_or_token(): void {
		$this->scans()->remember( '../x', 'https://example.com/', self::TOKEN1 );
		$this->scans()->remember( self::ID1, 'https://example.com/', 'short' );
		$this->assertSame( array(), $this->scans()->all() );
	}

	public function test_drops_scans_older_than_30_days(): void {
		$scans = $this->scans();
		$scans->remember( self::ID1, 'https://example.com/', self::TOKEN1 );
		$this->options[ AnonymousScans::OPTION ][0]['at'] = time() - 31 * 86400;
		$this->assertSame( array(), $scans->all() );
		$this->assertArrayNotHasKey( AnonymousScans::OPTION, $this->options );
	}

	public function test_an_entry_that_does_not_decrypt_is_dropped(): void {
		$scans = $this->scans();
		$scans->remember( self::ID1, 'https://example.com/', self::TOKEN1 );
		$this->options[ AnonymousScans::OPTION ][] = array( 'id' => self::ID2, 'url' => 'https://example.com/a/', 'at' => time(), 'token' => 'garbage' );
		$this->assertSame( array( self::ID1 ), array_column( $scans->all(), 'id' ) );
		$this->assertCount( 1, $this->options[ AnonymousScans::OPTION ] );
	}

	// Review Focus 3.
	public function test_salt_change_drops_every_entry_and_sends_no_token(): void {
		$this->scans()->remember( self::ID1, 'https://example.com/', self::TOKEN1 );
		Monkey\Functions\when( 'wp_salt' )->justReturn( str_repeat( 'x', 64 ) );
		$scans = $this->scans();
		$this->assertSame( array(), $scans->all() );
		$this->assertSame( array(), $scans->tokens() );
		$this->assertSame( 404, $scans->fetch( self::ID1 )->status );
		$this->assertSame( array(), $this->client->publics );
	}

	public function test_fetch_sends_the_token_as_bearer(): void {
		$scans = $this->scans();
		$scans->remember( self::ID1, 'https://example.com/', self::TOKEN1 );
		$this->client->public_result = array( 'scores' => array( 'overall' => 50 ) );
		$this->assertSame( array( 'scores' => array( 'overall' => 50 ) ), $scans->fetch( self::ID1 ) );
		$this->assertSame( array( 'GET', '/api/v1/wp/scans/' . self::ID1, null, self::TOKEN1, 15 ), $this->client->publics[0] );
	}

	public function test_fetch_404_removes_the_scan(): void {
		$scans = $this->scans();
		$scans->remember( self::ID1, 'https://example.com/', self::TOKEN1 );
		$this->client->public_result = new ApiError( 404, 'NOT_FOUND', 'Scan not found' );
		$this->assertSame( 404, $scans->fetch( self::ID1 )->status );
		$this->assertSame( array(), $scans->all() );
	}

	public function test_fetch_of_an_id_outside_the_list_makes_no_call(): void {
		$this->assertSame( 404, $this->scans()->fetch( self::ID2 )->status );
		$this->assertSame( array(), $this->client->publics );
	}

	public function test_clear_empties_the_option(): void {
		$scans = $this->scans();
		$scans->remember( self::ID1, 'https://example.com/', self::TOKEN1 );
		$scans->clear();
		$this->assertArrayNotHasKey( AnonymousScans::OPTION, $this->options );
	}
}
