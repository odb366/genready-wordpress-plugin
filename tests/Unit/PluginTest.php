<?php
namespace GenReady\Tests\Unit;

use Brain\Monkey;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/fixtures/http-doubles.php';

class PluginTest extends TestCase {
	use ScriptedHttp;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		$_GET = array();
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_boots_without_network(): void {
		Monkey\Functions\expect( 'wp_remote_request' )->never();
		Monkey\Functions\when( 'add_action' )->justReturn( true );
		Monkey\Functions\when( 'add_filter' )->justReturn( true );
		\GenReady\Plugin::instance()->boot();
		$this->assertSame( '1.0.0', GENREADY_VERSION );
		$this->assertSame( 'https://genready.ai', GENREADY_API_BASE );
	}

	public function test_boot_registers_hooks_and_exposes_oauth(): void {
		Monkey\Functions\when( 'add_filter' )->justReturn( true );
		Monkey\Functions\expect( 'add_action' )->atLeast()->twice();
		$plugin = \GenReady\Plugin::instance();
		$plugin->boot();
		// The browser tests' helper plugin refreshes the token through oauth().
		$this->assertInstanceOf( \GenReady\Connection\OAuth::class, $plugin->oauth() );
	}

	public function test_only_the_oauth_accessor_is_public(): void {
		foreach ( array( 'store', 'client', 'api_key', 'meta_tag', 'caches', 'site_setup', 'fixes', 'files', 'robots', 'llms', 'jsonld' ) as $accessor ) {
			$this->assertFalse( method_exists( \GenReady\Plugin::class, $accessor ), $accessor );
		}
	}

	public function test_boot_hooks_the_robots_filter_last_without_loading_the_connection(): void {
		Monkey\Functions\expect( 'get_option' )->never();
		Monkey\Filters\expectAdded( 'robots_txt' )->once()->with( \Mockery::type( 'array' ), PHP_INT_MAX, 2 );
		\GenReady\Plugin::instance()->boot();
		$this->addToAssertionCount( \Mockery::getContainer()->mockery_getExpectationCount() );
	}

	public function test_boot_hooks_llms_serving_first_without_loading_the_connection(): void {
		Monkey\Functions\expect( 'get_option' )->never();
		Monkey\Functions\when( 'add_filter' )->justReturn( true );
		Monkey\Actions\expectAdded( 'parse_request' )->once()->with( \Mockery::type( 'array' ), 0 );
		\GenReady\Plugin::instance()->boot();
		$this->addToAssertionCount( \Mockery::getContainer()->mockery_getExpectationCount() );
	}

	public function test_boot_hooks_the_jsonld_output_without_loading_the_connection(): void {
		Monkey\Functions\expect( 'get_option' )->never();
		Monkey\Functions\expect( 'wp_remote_request' )->never();
		Monkey\Actions\expectAdded( 'wp_head' )->once()->with( \Mockery::type( 'array' ), 1 );
		Monkey\Actions\expectAdded( 'wp_head' )->once()->with( \Mockery::type( 'array' ), 20 );
		Monkey\Actions\expectAdded( 'init' )->once()->with( \Mockery::type( 'array' ) );
		Monkey\Filters\expectAdded( 'wpseo_schema_graph' )->once()->with( \Mockery::type( 'array' ), 99 );
		Monkey\Filters\expectAdded( 'rank_math/json_ld' )->once()->with( \Mockery::type( 'array' ), 99 );
		Monkey\Filters\expectAdded( 'aioseo_schema_output' )->once()->with( \Mockery::type( 'array' ), 99 );
		\GenReady\Plugin::instance()->boot();
		$this->addToAssertionCount( \Mockery::getContainer()->mockery_getExpectationCount() );
	}

	public function test_boot_registers_the_admin_page_and_routes_without_loading_the_connection(): void {
		Monkey\Functions\expect( 'get_option' )->never();
		Monkey\Functions\expect( 'wp_remote_request' )->never();
		Monkey\Functions\when( 'add_filter' )->justReturn( true );
		Monkey\Actions\expectAdded( 'rest_api_init' )->times( 3 );
		Monkey\Actions\expectAdded( 'admin_menu' )->once();
		Monkey\Actions\expectAdded( 'admin_enqueue_scripts' )->once();
		Monkey\Actions\expectAdded( 'admin_notices' )->once();
		\GenReady\Plugin::instance()->boot();
		$this->assertTrue( true );
	}

	public function test_callback_stores_the_notice_and_redirects(): void {
		Monkey\Functions\when( 'add_action' )->justReturn( true );
		Monkey\Functions\when( 'sanitize_text_field' )->returnArg( 1 );
		Monkey\Functions\when( 'wp_unslash' )->returnArg( 1 );
		Monkey\Functions\when( '__' )->returnArg( 1 );
		Monkey\Functions\when( 'current_user_can' )->justReturn( true );
		Monkey\Functions\when( 'get_current_user_id' )->justReturn( 7 );
		Monkey\Functions\when( 'get_transient' )->justReturn( false );
		Monkey\Functions\when( 'delete_transient' )->justReturn( true );
		Monkey\Functions\when( 'admin_url' )->alias( function ( $p ) { return 'https://example.com/wp-admin/' . $p; } );
		$notice   = null;
		$redirect = null;
		Monkey\Functions\when( 'set_transient' )->alias( function ( $k, $v, $ttl ) use ( &$notice ) { $notice = array( $k, $v, $ttl ); return true; } );
		Monkey\Functions\when( 'wp_safe_redirect' )->alias( function ( $url ) use ( &$redirect ) { $redirect = $url; return true; } );
		$_GET = array( 'page' => 'genready', 'genready_cb' => '1', 'state' => 'x', 'unrelated' => 'y' );
		$plugin = \GenReady\Plugin::instance();
		$plugin->boot();
		$plugin->maybe_handle_oauth_callback();
		$this->assertSame( 'genready_notice_7', $notice[0] );
		$this->assertArrayHasKey( 'error', $notice[1] );
		$this->assertSame( 60, $notice[2] );
		$this->assertSame( 'https://example.com/wp-admin/admin.php?page=genready', $redirect );
	}

	public function test_callback_ignores_other_pages_and_users_without_rights(): void {
		Monkey\Functions\when( 'add_action' )->justReturn( true );
		Monkey\Functions\when( 'sanitize_text_field' )->returnArg( 1 );
		Monkey\Functions\when( 'wp_unslash' )->returnArg( 1 );
		Monkey\Functions\when( 'current_user_can' )->justReturn( false );
		Monkey\Functions\expect( 'wp_safe_redirect' )->never();
		$plugin = \GenReady\Plugin::instance();
		$plugin->boot();
		$_GET = array( 'page' => 'other', 'genready_cb' => '1' );
		$plugin->maybe_handle_oauth_callback();
		$_GET = array( 'page' => 'genready', 'genready_cb' => '1' );
		$plugin->maybe_handle_oauth_callback();
		$this->assertTrue( true );
	}

	public function test_privacy_text_is_registered(): void {
		Monkey\Functions\when( '__' )->returnArg( 1 );
		Monkey\Functions\when( 'wpautop' )->alias( function ( $t ) { return '<p>' . $t . '</p>'; } );
		Monkey\Functions\when( 'wp_kses_post' )->returnArg( 1 );
		$given = null;
		Monkey\Functions\when( 'wp_add_privacy_policy_content' )->alias( function ( $name, $text ) use ( &$given ) { $given = array( $name, $text ); } );
		\GenReady\Plugin::instance()->register_privacy_text();
		$this->assertSame( 'GenReady', $given[0] );
		$this->assertStringContainsString( 'genready.ai', $given[1] );
		$this->assertStringContainsString( 'no cookies', $given[1] );
		$this->assertStringContainsString( 'Site administrators who use the plugin send site data to genready.ai, as the plugin readme describes.', $given[1] );
	}

	public function test_boot_registers_the_privacy_text_on_admin_init(): void {
		Monkey\Functions\when( 'add_filter' )->justReturn( true );
		// The OAuth callback handler and the privacy text both use admin_init.
		Monkey\Actions\expectAdded( 'admin_init' )->twice();
		\GenReady\Plugin::instance()->boot();
		$this->assertTrue( true );
	}

	// Fix round A, G5 L5: the client document is public only while it is needed.

	private function booted_with_options( array &$options ): \GenReady\Plugin {
		Monkey\Functions\when( 'add_action' )->justReturn( true );
		Monkey\Functions\when( 'add_filter' )->justReturn( true );
		Monkey\Functions\when( 'get_option' )->alias( function ( $k, $d = false ) use ( &$options ) { return $options[ $k ] ?? $d; } );
		Monkey\Functions\when( 'update_option' )->alias( function ( $k, $v ) use ( &$options ) { $options[ $k ] = $v; return true; } );
		Monkey\Functions\when( 'wp_salt' )->justReturn( str_repeat( 's', 64 ) );
		Monkey\Functions\when( '__' )->returnArg( 1 );
		Monkey\Functions\when( 'rest_url' )->justReturn( 'https://example.com/wp-json/genready/v1/client' );
		Monkey\Functions\when( 'admin_url' )->justReturn( 'https://example.com/wp-admin/admin.php?page=genready&genready_cb=1' );
		Monkey\Functions\when( 'home_url' )->justReturn( 'https://example.com' );
		$plugin = \GenReady\Plugin::instance();
		$plugin->boot();
		return $plugin;
	}

	public function test_client_document_is_404_when_no_connection_is_pending(): void {
		$options = array();
		$plugin  = $this->booted_with_options( $options );
		foreach ( array( null, time() - 901, 'x' ) as $pending ) {
			$options['genready_oauth_pending'] = $pending;
			$result                            = $plugin->serve_client_document();
			$this->assertInstanceOf( \WP_Error::class, $result );
			$this->assertSame( 'rest_no_route', $result->get_error_code() );
			$this->assertSame( array( 'status' => 404 ), $result->get_error_data() );
		}
	}

	public function test_client_document_is_served_during_a_connect_and_while_connected(): void {
		$options = array( 'genready_oauth_pending' => time() - 60 );
		$plugin  = $this->booted_with_options( $options );
		$result  = $plugin->serve_client_document();
		$this->assertInstanceOf( \WP_REST_Response::class, $result );
		$this->assertSame( 'https://example.com/wp-json/genready/v1/client', $result->get_data()['client_id'] );

		$options = array();
		$plugin  = $this->booted_with_options( $options );
		( new \GenReady\Connection\TokenStore() )->save( array( 'method' => 'oauth', 'access_token' => 'A', 'refresh_token' => 'R', 'domain_id' => 'd1' ) );
		$this->assertInstanceOf( \WP_REST_Response::class, $plugin->serve_client_document() );
	}

	public function test_oauth_callback_success_clears_the_anonymous_scans(): void {
		$options = array( 'genready_anonymous_scans' => array( array( 'id' => 'x' ) ) );
		$plugin  = $this->booted_with_options( $options );
		Monkey\Functions\when( 'delete_option' )->alias( function ( $k ) use ( &$options ) { unset( $options[ $k ] ); return true; } );
		Monkey\Functions\when( 'get_transient' )->justReturn( array( 'state' => 'st', 'verifier' => 'v' ) );
		Monkey\Functions\when( 'delete_transient' )->justReturn( true );
		Monkey\Functions\when( 'set_transient' )->justReturn( true );
		Monkey\Functions\when( 'sanitize_text_field' )->returnArg( 1 );
		Monkey\Functions\when( 'wp_unslash' )->returnArg( 1 );
		Monkey\Functions\when( 'current_user_can' )->justReturn( true );
		Monkey\Functions\when( 'get_current_user_id' )->justReturn( 7 );
		Monkey\Functions\when( 'wp_parse_url' )->alias( function ( $u, $c = -1 ) { return parse_url( $u, $c ); } );
		Monkey\Functions\when( 'wp_safe_redirect' )->justReturn( true );
		$this->scripted_http(
			array(
				array( 200, array( 'access_token' => 'AT', 'refresh_token' => 'RT', 'expires_in' => 3600, 'domain_id' => 'd1' ) ),
				array( 200, array( 'data' => array( 'domain' => array( 'id' => 'd1', 'verificationToken' => 'tok12345678' ) ), 'meta' => array() ) ),
			)
		);
		$_GET = array( 'page' => 'genready', 'genready_cb' => '1', 'state' => 'st', 'code' => 'c' );
		$plugin->maybe_handle_oauth_callback();
		$this->assertArrayHasKey( 'genready_connection', $options );
		$this->assertArrayNotHasKey( 'genready_anonymous_scans', $options );
	}
}
