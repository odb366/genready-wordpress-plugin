<?php
namespace GenReady\Tests\Unit;

use Brain\Monkey;
use GenReady\Api\ApiError;
use GenReady\Compat\Caches;
use GenReady\Connection\TokenStore;
use GenReady\Onboarding\AccountCreator;
use GenReady\Onboarding\AnonymousScans;
use GenReady\Onboarding\Proof;
use GenReady\Verification\MetaTag;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/fixtures/doubles.php';

class AccountCreatorTest extends TestCase {
	const ID1    = '11111111-1111-4111-8111-111111111111';
	const ID2    = '22222222-2222-4222-8222-222222222222';
	const TOKEN1 = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQ';
	const TOKEN2 = 'ZYXWVUTSRQPONMLKJIHGFEDCBAzyxwvutsrqponmlkj';
	const DOMAIN = '33333333-3333-4333-8333-333333333333';

	/** @var array */
	private $options = array();
	/** @var array */
	private $transients = array();
	/** @var TokenStore */
	private $store;
	/** @var FakeClient */
	private $client;
	/** @var Proof */
	private $proof;
	/** @var AnonymousScans */
	private $scans;
	/** @var Caches */
	private $caches;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->options    = array();
		$this->transients = array();
		$opts             = &$this->options;
		$trans            = &$this->transients;
		Monkey\Functions\when( 'wp_salt' )->justReturn( str_repeat( 's', 64 ) );
		Monkey\Functions\when( 'get_option' )->alias( function ( $k, $d = false ) use ( &$opts ) { return $opts[ $k ] ?? $d; } );
		Monkey\Functions\when( 'update_option' )->alias( function ( $k, $v ) use ( &$opts ) { $opts[ $k ] = $v; return true; } );
		Monkey\Functions\when( 'delete_option' )->alias( function ( $k ) use ( &$opts ) { $had = isset( $opts[ $k ] ); unset( $opts[ $k ] ); return $had; } );
		Monkey\Functions\when( 'get_transient' )->alias( function ( $k ) use ( &$trans ) { return $trans[ $k ] ?? false; } );
		Monkey\Functions\when( 'set_transient' )->alias( function ( $k, $v ) use ( &$trans ) { $trans[ $k ] = $v; return true; } );
		Monkey\Functions\when( 'delete_transient' )->alias( function ( $k ) use ( &$trans ) { unset( $trans[ $k ] ); return true; } );
		Monkey\Functions\when( 'home_url' )->alias( function ( $path = '' ) { return 'https://example.com' . $path; } );
		Monkey\Functions\when( 'wp_parse_url' )->alias( function ( $url, $component = -1 ) { return parse_url( $url, $component ); } );
		Monkey\Functions\when( 'esc_url_raw' )->returnArg( 1 );
		Monkey\Functions\when( 'sanitize_email' )->alias( function ( $e ) { return trim( (string) $e ); } );
		Monkey\Functions\when( 'is_email' )->alias( function ( $e ) { return false !== filter_var( $e, FILTER_VALIDATE_EMAIL ) ? $e : false; } );
		Monkey\Functions\when( 'get_current_user_id' )->justReturn( 7 );
		Monkey\Functions\when( 'wp_cache_delete' )->justReturn( true ); // The option doubles keep no request cache.
		Monkey\Functions\when( '__' )->returnArg( 1 );
		$this->store  = new TokenStore();
		$this->client = new FakeClient();
		$this->proof  = new Proof();
		$this->scans  = new AnonymousScans( $this->store, $this->client );
		$this->caches = new class() extends Caches {
			/** @var array */
			public $calls = array();
			/** @var mixed */
			public $tag_at_purge = null;
			public function purge_url( string $url, int $post_id = 0 ): array {
				$this->calls[]      = $url;
				$this->tag_at_purge = get_option( MetaTag::OPTION );
				return array();
			}
		};
		$this->scans->remember( self::ID1, 'https://example.com/', self::TOKEN1 );
		$this->scans->remember( self::ID2, 'https://example.com/pricing/', self::TOKEN2 );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function creator(): AccountCreator {
		return new AccountCreator( $this->store, $this->client, $this->proof, $this->scans, new MetaTag(), $this->caches );
	}

	private function success( array $adopted = array( self::ID1, self::ID2 ) ): array {
		return array(
			'apiKey'            => 'gr_live_' . str_repeat( 'k', 32 ),
			'domainId'          => self::DOMAIN,
			'verificationToken' => str_repeat( 'f', 48 ),
			'adoptedScanIds'    => $adopted,
		);
	}

	public function test_request_has_no_credentials_and_holds_the_email_site_proof_and_tokens(): void {
		$this->client->public_result = $this->success();
		$this->client->on_public     = function () {
			// While the call runs, the stored secret matches the hash in the request.
			$call = end( $this->client->publics );
			$this->assertSame( hash( 'sha256', $this->options[ Proof::OPTION ]['secret'] ), $call[2]['proofHash'] );
		};
		$this->creator()->create( ' owner@example.com ' );
		list( $method, $path, $body, $bearer, $timeout ) = $this->client->publics[0];
		$this->assertSame( 'POST', $method );
		$this->assertSame( '/api/v1/wp/accounts', $path );
		$this->assertNull( $bearer );
		$this->assertSame( 20, $timeout );
		$this->assertSame( 'owner@example.com', $body['email'] );
		$this->assertSame( 'https://example.com/', $body['siteUrl'] );
		$this->assertMatchesRegularExpression( '/^[a-f0-9]{32}$/', $body['proofId'] );
		$this->assertMatchesRegularExpression( '/^[a-f0-9]{64}$/', $body['proofHash'] );
		$this->assertSame( array( self::TOKEN2, self::TOKEN1 ), $body['scanTokens'] );
		$this->assertArrayNotHasKey( 'secret', $body );
	}

	public function test_success_connects_verifies_and_clears_the_scans(): void {
		$this->client->public_result                   = $this->success();
		$this->transients['genready_report_cache'] = array( 'id' => 'old' );
		$result                                        = $this->creator()->create( 'owner@example.com' );
		$this->assertSame( array( 'ok' => true, 'email' => 'owner@example.com' ), $result );
		$connection = $this->store->load();
		$this->assertSame( 'api_key', $connection['method'] );
		$this->assertSame( 'gr_live_' . str_repeat( 'k', 32 ), $connection['api_key'] );
		$this->assertSame( self::DOMAIN, $connection['domain_id'] );
		$this->assertSame( str_repeat( 'f', 48 ), $connection['verification_token'] );
		$this->assertStringNotContainsString( 'gr_live_', serialize( $this->options ), 'the key is stored encrypted' );
		$this->assertSame( str_repeat( 'f', 48 ), $this->options[ MetaTag::OPTION ] );
		$this->assertSame( array( 'https://example.com/' ), $this->caches->calls );
		$this->assertSame( str_repeat( 'f', 48 ), $this->caches->tag_at_purge, 'the tag is on before the purge' );
		$this->assertSame( self::ID2, $this->options['genready_settings']['last_report_id'] );
		$this->assertSame( 'https://example.com/pricing/', $this->options['genready_settings']['last_report_url'] );
		$this->assertArrayNotHasKey( AnonymousScans::OPTION, $this->options );
		$this->assertArrayNotHasKey( Proof::OPTION, $this->options );
		$this->assertArrayNotHasKey( 'genready_report_cache', $this->transients );
		$this->assertSame( array( 'ok' => true, 'account' => true, 'email' => 'owner@example.com' ), $this->transients['genready_notice_7'] );
	}

	public function test_last_report_is_the_newest_adopted_scan_that_the_plugin_stored(): void {
		$this->client->public_result = $this->success( array( self::ID1, '99999999-9999-4999-8999-999999999999' ) );
		$this->creator()->create( 'owner@example.com' );
		$this->assertSame( self::ID1, $this->options['genready_settings']['last_report_id'] );
	}

	public function test_no_adopted_scan_leaves_no_last_report(): void {
		$this->options['genready_settings'] = array( 'last_report_id' => '44444444-4444-4444-8444-444444444444', 'last_report_url' => 'https://example.com/old/' );
		$this->client->public_result        = $this->success( array() );
		$this->creator()->create( 'owner@example.com' );
		$this->assertArrayNotHasKey( 'last_report_id', $this->options['genready_settings'] );
		$this->assertArrayNotHasKey( 'last_report_url', $this->options['genready_settings'] );
	}

	public function test_the_proof_is_gone_after_an_error_and_after_an_exception(): void {
		$this->client->public_result = new ApiError( 409, 'PROOF_FAILED', '', array( 'reason' => 'not_found' ) );
		$this->creator()->create( 'owner@example.com' );
		$this->assertArrayNotHasKey( Proof::OPTION, $this->options );

		$this->client->on_public = function () {
			throw new \RuntimeException( 'network library failed' );
		};
		try {
			$this->creator()->create( 'owner@example.com' );
			$this->fail( 'the exception must reach the caller' );
		} catch ( \RuntimeException $e ) {
			$this->assertArrayNotHasKey( Proof::OPTION, $this->options );
		}
	}

	/**
	 * @dataProvider server_errors
	 */
	public function test_each_server_error_gets_its_text_and_code( $status, string $code, string $text ): void {
		$this->client->public_result = new ApiError( $status, $code, 'server text' );
		$result                      = $this->creator()->create( 'owner@example.com' );
		$this->assertSame( $text, $result['error'] );
		$this->assertSame( $code, $result['code'] );
		$this->assertSame( $status, $result['status'] );
		$this->assertNull( $this->store->load() );
	}

	public function server_errors(): array {
		return array(
			'email exists'    => array( 409, 'ACCOUNT_EXISTS', 'This email already has a GenReady account.' ),
			'other account'   => array( 409, 'VERIFIED_ELSEWHERE', 'This site is verified in another GenReady account. Sign in with that account, or remove the site from it and try again.' ),
			'proof failed'    => array( 409, 'PROOF_FAILED', 'GenReady could not confirm that this site is yours. Connect with a GenReady account instead.' ),
			'limit'           => array( 429, 'RATE_LIMITED', 'Too many account requests today. Try again tomorrow.' ),
			'switch off'      => array( 503, 'WP_ONBOARDING_OFF', 'GenReady paused free scans for now. Connect with a GenReady account.' ),
			'no answer'       => array( null, 'TIMEOUT', 'GenReady did not answer. Try again.' ),
			'bad body'        => array( 400, 'VALIDATION_ERROR', 'GenReady could not do this. Try again.' ),
			'no login here'   => array( 401, 'UNAUTHORIZED', 'GenReady could not do this. Try again.' ),
			'not found'       => array( 404, 'NOT_FOUND', 'GenReady could not do this. Try again.' ),
			'plan'            => array( 402, 'PLAN_REQUIRED', 'GenReady could not do this. Try again.' ),
		);
	}

	// Review Focus 1.
	public function test_proof_failed_keeps_the_scans_and_stores_nothing(): void {
		$this->client->public_result = new ApiError( 409, 'PROOF_FAILED', '', array( 'reason' => 'bad_answer' ) );
		$this->creator()->create( 'owner@example.com' );
		$this->assertCount( 2, $this->scans->all() );
		$this->assertArrayNotHasKey( 'genready_connection', $this->options );
		$this->assertArrayNotHasKey( MetaTag::OPTION, $this->options );
		$this->assertSame( array(), $this->caches->calls );
		$this->assertArrayNotHasKey( 'genready_notice_7', $this->transients );
	}

	public function test_a_503_pauses_the_onboarding(): void {
		$this->client->public_result = new ApiError( 503, 'WP_ONBOARDING_OFF', '' );
		$this->creator()->create( 'owner@example.com' );
		$this->assertTrue( AnonymousScans::paused() );
		$this->assertSame( 'WP_ONBOARDING_OFF', $this->creator()->create( 'owner@example.com' )['code'] );
		$this->assertCount( 1, $this->client->publics, 'no call while paused' );
	}

	public function test_refuses_when_connected_without_a_call(): void {
		$this->store->save( array( 'method' => 'api_key', 'api_key' => 'gr_live_abcdefghijklmnop', 'domain_id' => 'd1' ) );
		$result = $this->creator()->create( 'owner@example.com' );
		$this->assertSame( 'Disconnect GenReady first.', $result['error'] );
		$this->assertSame( array(), $this->client->publics );
		$this->assertArrayNotHasKey( Proof::OPTION, $this->options );
	}

	public function test_a_bad_email_makes_no_call(): void {
		foreach ( array( '', 'not-an-email', str_repeat( 'a', 250 ) . '@x.io' ) as $email ) {
			$result = $this->creator()->create( $email );
			$this->assertSame( 'Enter a valid email address.', $result['error'], $email );
			$this->assertSame( 'INVALID_EMAIL', $result['code'] );
		}
		$this->assertSame( array(), $this->client->publics );
		$this->assertArrayNotHasKey( Proof::OPTION, $this->options );
	}

	// sanitize_email() removes characters it does not allow, so "jo hn@example.com" would become john@example.com.
	public function test_an_email_that_sanitize_email_changes_is_refused_without_a_call(): void {
		Monkey\Functions\when( 'sanitize_email' )->alias( function ( $e ) { return str_replace( ' ', '', trim( (string) $e ) ); } );
		foreach ( array( 'jo hn@example.com', 'kris@hd robots.com' ) as $email ) {
			$result = $this->creator()->create( $email );
			$this->assertSame( 'Enter a valid email address.', $result['error'], $email );
			$this->assertSame( 'INVALID_EMAIL', $result['code'] );
		}
		$this->assertSame( array(), $this->client->publics );
		$this->assertArrayNotHasKey( Proof::OPTION, $this->options );
		$this->client->public_result = $this->success();
		$this->assertTrue( $this->creator()->create( ' owner@example.com ' )['ok'], 'spaces around the address are fine' );
	}

	public function test_a_bad_success_answer_stores_nothing(): void {
		$answer                      = $this->success();
		$answer['apiKey']            = 'sk_not_a_genready_key';
		$this->client->public_result = $answer;
		$this->assertSame( 'BAD_RESPONSE', $this->creator()->create( 'owner@example.com' )['code'] );
		$this->assertNull( $this->store->load() );
		$this->assertCount( 2, $this->scans->all() );
	}

	// Review Focus 4.
	public function test_a_second_create_during_the_call_keeps_its_proof(): void {
		$other                       = null;
		$this->client->public_result = new ApiError( 409, 'PROOF_FAILED', '' );
		$this->client->on_public     = function () use ( &$other ) {
			if ( null === $other ) {
				$other = $this->proof->create(); // A second admin clicks while this call waits.
			}
		};
		$this->creator()->create( 'owner@example.com' );
		$this->assertSame( $other['id'], $this->options[ Proof::OPTION ]['id'] );
	}
}
