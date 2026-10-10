<?php
namespace GenReady\Tests\Unit;

use Brain\Monkey;
use PHPUnit\Framework\TestCase;

class PluginTest extends TestCase {
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

	public function test_boot_registers_hooks_and_exposes_the_connectors(): void {
		Monkey\Functions\when( 'add_filter' )->justReturn( true );
		Monkey\Functions\expect( 'add_action' )->atLeast()->twice();
		$plugin = \GenReady\Plugin::instance();
		$plugin->boot();
		$this->assertInstanceOf( \GenReady\Connection\OAuth::class, $plugin->oauth() );
		$this->assertInstanceOf( \GenReady\Connection\ApiKey::class, $plugin->api_key() );
		$this->assertInstanceOf( \GenReady\Api\Client::class, $plugin->client() );
		$this->assertInstanceOf( \GenReady\Connection\TokenStore::class, $plugin->store() );
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
}
