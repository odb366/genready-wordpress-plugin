<?php
namespace GenReady\Tests\Unit;

use Brain\Monkey;
use GenReady\Onboarding\Proof;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/fixtures/rest-doubles.php';

class ProofTest extends TestCase {
	/** @var array The database rows. */
	private $options = array();
	/** @var array The per-request option cache of this request (WP_Object_Cache without a persistent cache). */
	private $cache = array();
	/** @var array */
	private $autoload = array();
	/** @var bool */
	private $delete_works = true;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->options      = array();
		$this->cache        = array();
		$this->autoload     = array();
		$this->delete_works = true;
		$opts               = &$this->options;
		$cache              = &$this->cache;
		$auto               = &$this->autoload;
		$works              = &$this->delete_works;
		Monkey\Functions\when( 'get_option' )->alias(
			function ( $k, $d = false ) use ( &$opts, &$cache ) {
				if ( ! array_key_exists( $k, $cache ) ) {
					$cache[ $k ] = $opts[ $k ] ?? $d;
				}
				return $cache[ $k ];
			}
		);
		Monkey\Functions\when( 'update_option' )->alias( function ( $k, $v, $a = null ) use ( &$opts, &$cache, &$auto ) { $opts[ $k ] = $v; $cache[ $k ] = $v; $auto[ $k ] = $a; return true; } );
		Monkey\Functions\when( 'delete_option' )->alias(
			function ( $k ) use ( &$opts, &$cache, &$works ) {
				if ( ! $works || ! isset( $opts[ $k ] ) ) {
					return false;
				}
				unset( $opts[ $k ], $cache[ $k ] );
				return true;
			}
		);
		Monkey\Functions\when( 'wp_cache_delete' )->alias( function ( $k, $group = '' ) use ( &$cache ) { if ( 'options' === $group ) { unset( $cache[ $k ] ); } return true; } );
		Monkey\Functions\when( '__' )->returnArg( 1 );
		Monkey\Functions\expect( 'wp_remote_request' )->never();
	}

	/** A new PHP request starts with an empty option cache. */
	private function next_request(): void {
		$this->cache = array();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function request( string $id ): FakeRequest {
		return new FakeRequest( array( 'id' => $id ) );
	}

	private function assert_missing( $result ): void {
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'rest_no_route', $result->get_error_code() );
		$this->assertSame( 404, $result->get_error_data()['status'] );
	}

	public function test_create_stores_the_code_and_returns_the_hash_of_the_secret(): void {
		$proof  = ( new Proof() )->create();
		$stored = $this->options[ Proof::OPTION ];
		$this->assertMatchesRegularExpression( '/^[a-f0-9]{32}$/', $proof['id'] );
		$this->assertSame( $proof['id'], $stored['id'] );
		$this->assertMatchesRegularExpression( '/^[a-f0-9]{64}$/', $stored['secret'] );
		$this->assertSame( hash( 'sha256', $stored['secret'] ), $proof['hash'] );
		$this->assertArrayNotHasKey( 'secret', $proof );
		$this->assertLessThanOrEqual( 1, abs( time() - $stored['created'] ) );
		$this->assertFalse( $this->autoload[ Proof::OPTION ], 'autoload off' );
	}

	public function test_serve_gives_the_secret_once_with_no_store(): void {
		$service = new Proof();
		$proof   = $service->create();
		$secret  = $this->options[ Proof::OPTION ]['secret'];
		$first   = $service->serve( $this->request( $proof['id'] ) );
		$this->assertInstanceOf( \WP_REST_Response::class, $first );
		$this->assertSame( 200, $first->get_status() );
		$this->assertSame( array( 'secret' => $secret ), $first->get_data() );
		$this->assertSame( 'no-store', $first->get_headers()['Cache-Control'] );
		$this->assert_missing( $service->serve( $this->request( $proof['id'] ) ) );
	}

	public function test_a_wrong_id_does_not_use_up_the_proof(): void {
		$service = new Proof();
		$proof   = $service->create();
		$this->assert_missing( $service->serve( $this->request( str_repeat( 'a', 32 ) ) ) );
		$this->assert_missing( $service->serve( $this->request( strtoupper( $proof['id'] ) ) ) );
		$this->assertArrayHasKey( Proof::OPTION, $this->options );
		$this->assertInstanceOf( \WP_REST_Response::class, $service->serve( $this->request( $proof['id'] ) ) );
	}

	public function test_an_old_proof_and_no_proof_are_404(): void {
		$service = new Proof();
		$proof   = $service->create();
		$this->options[ Proof::OPTION ]['created'] = time() - 121;
		$this->next_request();
		$this->assert_missing( $service->serve( $this->request( $proof['id'] ) ) );
		unset( $this->options[ Proof::OPTION ] );
		$this->next_request();
		$this->assert_missing( $service->serve( $this->request( $proof['id'] ) ) );
		$this->options[ Proof::OPTION ] = 'corrupt';
		$this->next_request();
		$this->assert_missing( $service->serve( $this->request( $proof['id'] ) ) );
	}

	public function test_an_expired_proof_with_the_right_id_is_deleted(): void {
		$service = new Proof();
		$proof   = $service->create();
		$this->options[ Proof::OPTION ]['created'] = time() - 121;
		$this->next_request();
		$this->assert_missing( $service->serve( $this->request( str_repeat( 'a', 32 ) ) ) );
		$this->assertArrayHasKey( Proof::OPTION, $this->options, 'a wrong ID does not touch the row' );
		$this->assert_missing( $service->serve( $this->request( $proof['id'] ) ) );
		$this->assertArrayNotHasKey( Proof::OPTION, $this->options );
	}

	public function test_the_id_pattern_refuses_a_trailing_newline(): void {
		$this->assertSame( 1, preg_match( Proof::ID, str_repeat( 'a', 32 ) ) );
		$this->assertSame( 0, preg_match( Proof::ID, str_repeat( 'a', 32 ) . "\n" ) );
	}

	// WordPress sends HEAD to the GET handler (a WAF, a CDN prefetch or a link scanner).
	public function test_head_does_not_use_up_the_proof(): void {
		$service = new Proof();
		$proof   = $service->create();
		$this->assert_missing( $service->serve( new FakeRequest( array( 'id' => $proof['id'] ), 'HEAD' ) ) );
		$this->assertArrayHasKey( Proof::OPTION, $this->options );
		$this->assertInstanceOf( \WP_REST_Response::class, $service->serve( $this->request( $proof['id'] ) ) );
	}

	// ?id[]=x: a query value wins in get_param(); the route segment is in get_url_params().
	public function test_the_id_comes_from_the_route_segment_not_the_query(): void {
		$service = new Proof();
		$proof   = $service->create();
		$this->assertInstanceOf( \WP_REST_Response::class, $service->serve( new FakeRequest( array( 'id' => array( 'x' ) ), 'GET', array( 'id' => $proof['id'] ) ) ) );
		$this->assert_missing( $service->serve( new FakeRequest( array( 'id' => array( 'x' ) ), 'GET', array( 'id' => array( 'x' ) ) ) ) );
	}

	public function test_a_failed_delete_gives_404(): void {
		$service            = new Proof();
		$proof              = $service->create();
		$this->delete_works = false; // Another request deleted the row first.
		$this->assert_missing( $service->serve( $this->request( $proof['id'] ) ) );
	}

	// Review Focus 4.
	public function test_discard_keeps_a_newer_proof(): void {
		$service = new Proof();
		$first   = $service->create();
		$second  = $service->create();
		$service->discard( $first['id'] );
		$this->assertSame( $second['id'], $this->options[ Proof::OPTION ]['id'] );
		$service->discard( $second['id'] );
		$this->assertArrayNotHasKey( Proof::OPTION, $this->options );
	}

	// Review Focus 4 across requests: A creates, B replaces the row, A discards.
	// get_option() in A would return A's own row from the request cache.
	public function test_discard_reads_the_row_not_the_request_cache(): void {
		$service = new Proof();
		$mine    = $service->create();
		$newer   = array(
			'id'      => str_repeat( 'b', 32 ),
			'secret'  => str_repeat( 'c', 64 ),
			'created' => time(),
		);
		$this->options[ Proof::OPTION ] = $newer; // Request B, with its own cache.
		$service->discard( $mine['id'] );
		$this->assertSame( $newer, $this->options[ Proof::OPTION ] );
	}

	public function test_the_route_is_public_get_only_with_a_strict_id(): void {
		$routes = array();
		Monkey\Functions\when( 'register_rest_route' )->alias( function ( $ns, $path, $args ) use ( &$routes ) { $routes[ $ns . $path ] = $args; } );
		$proof = new Proof();
		$proof->register();
		$args = $routes['genready/v1/proof/(?P<id>[a-f0-9]{32})'];
		$this->assertSame( 'GET', $args['methods'] );
		$this->assertSame( '__return_true', $args['permission_callback'] );
		$this->assertSame( array( $proof, 'serve' ), $args['callback'] );
	}
}
