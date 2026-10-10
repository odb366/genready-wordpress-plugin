<?php
namespace GenReady\Tests\Unit;

use Brain\Monkey;
use GenReady\Admin\FixCards;
use GenReady\Admin\Page;
use GenReady\Admin\Reports;
use GenReady\Compat\SeoPlugins;
use GenReady\Connection\TokenStore;
use GenReady\Fixes\FixStore;
use GenReady\Fixes\JsonLdFix;
use GenReady\Fixes\LlmsTxtFix;
use GenReady\Fixes\RobotsFix;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/fixtures/doubles.php';
require_once dirname( __DIR__ ) . '/fixtures/fix-doubles.php';

class PageTest extends TestCase {
	/** @var array */
	private $options = array();
	/** @var array */
	private $transients = array();
	/** @var array */
	private $scripts = array();
	/** @var array */
	private $inline = array();
	/** @var TokenStore */
	private $store;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->options    = array();
		$this->transients = array();
		$this->scripts    = array();
		$this->inline     = array();
		$opts             = &$this->options;
		$trans            = &$this->transients;
		$scripts          = &$this->scripts;
		$inline           = &$this->inline;
		Monkey\Functions\when( 'wp_salt' )->justReturn( str_repeat( 's', 64 ) );
		Monkey\Functions\when( 'get_option' )->alias( function ( $k, $d = false ) use ( &$opts ) { return $opts[ $k ] ?? $d; } );
		Monkey\Functions\when( 'update_option' )->alias( function ( $k, $v ) use ( &$opts ) { $opts[ $k ] = $v; return true; } );
		Monkey\Functions\when( 'get_transient' )->alias( function ( $k ) use ( &$trans ) { return $trans[ $k ] ?? false; } );
		Monkey\Functions\when( 'delete_transient' )->alias( function ( $k ) use ( &$trans ) { unset( $trans[ $k ] ); return true; } );
		Monkey\Functions\when( 'wp_enqueue_script' )->alias( function ( $h ) use ( &$scripts ) { $scripts[] = $h; } );
		Monkey\Functions\when( 'wp_enqueue_style' )->alias( function ( $h ) use ( &$scripts ) { $scripts[] = 'style:' . $h; } );
		Monkey\Functions\when( 'wp_add_inline_script' )->alias( function ( $h, $js ) use ( &$inline ) { $inline[ $h ] = $js; } );
		Monkey\Functions\when( 'wp_set_script_translations' )->justReturn( true );
		Monkey\Functions\when( 'plugins_url' )->alias( function ( $p ) { return 'https://example.com/wp-content/plugins/genready/' . $p; } );
		Monkey\Functions\when( 'admin_url' )->alias( function ( $p ) { return 'https://example.com/wp-admin/' . $p; } );
		Monkey\Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Monkey\Functions\when( 'current_user_can' )->justReturn( true );
		Monkey\Functions\when( 'get_current_user_id' )->justReturn( 7 );
		Monkey\Functions\when( 'esc_html__' )->returnArg( 1 );
		Monkey\Functions\when( 'esc_url' )->returnArg( 1 );
		$this->store = new TokenStore();
	}

	protected function tearDown(): void {
		$_GET = array();
		Monkey\tearDown();
		parent::tearDown();
	}

	private function page(): Page {
		$client = new FakeClient();
		$files  = new MemoryFiles();
		$fixes  = new FixStore();
		$caches = new RecordingCaches();
		$seo    = new SeoPlugins( $files );
		return new Page(
			$this->store,
			new Reports( $this->store, $client ),
			new FixCards( $fixes, new RobotsFix( $fixes, $files, $client, $caches, 'd1' ), new LlmsTxtFix( $fixes, $files, $client, $caches, $seo, 'd1' ), new JsonLdFix( $fixes, $client, $caches, $seo, 'd1' ), $files )
		);
	}

	public function test_assets_load_only_on_the_genready_page(): void {
		Monkey\Functions\when( 'get_user_meta' )->justReturn( '1' );
		$page = $this->page();
		foreach ( array( 'index.php', 'options-general.php', 'settings_page_other', 'plugins.php' ) as $hook ) {
			$page->enqueue( $hook );
		}
		$this->assertSame( array(), $this->scripts );

		$page->enqueue( 'settings_page_genready' );
		$this->assertSame( array( 'style:genready-admin', 'genready-admin' ), $this->scripts );
		$this->assertStringStartsWith( 'window.genreadyAdmin = ', $this->inline['genready-admin'] );
		$this->assertStringContainsString( '"connected":false', $this->inline['genready-admin'] );
	}

	public function test_plugins_notice_shows_only_on_the_plugins_screen_until_dismissed(): void {
		$dismissed = '';
		Monkey\Functions\when( 'get_user_meta' )->alias( function () use ( &$dismissed ) { return $dismissed; } );
		$screen = (object) array( 'id' => 'dashboard' );
		Monkey\Functions\when( 'get_current_screen' )->alias( function () use ( &$screen ) { return $screen; } );
		$page = $this->page();

		ob_start();
		$page->plugins_notice();
		$this->assertSame( '', ob_get_clean() );

		$screen = (object) array( 'id' => 'plugins' );
		ob_start();
		$page->plugins_notice();
		$html = ob_get_clean();
		$this->assertStringContainsString( 'Connect GenReady to see your AI readiness score.', $html );
		$this->assertStringContainsString( 'is-dismissible', $html );

		$page->enqueue( 'plugins.php' );
		$this->assertSame( array( 'wp-api-fetch' ), $this->scripts );
		$this->assertStringContainsString( '/genready/v1/notice/dismiss', $this->inline['wp-api-fetch'] );

		$dismissed = '1';
		ob_start();
		$page->plugins_notice();
		$this->assertSame( '', ob_get_clean() );
	}

	public function test_plugins_notice_hides_after_a_connection(): void {
		Monkey\Functions\when( 'get_user_meta' )->justReturn( '' );
		Monkey\Functions\when( 'get_current_screen' )->justReturn( (object) array( 'id' => 'plugins' ) );
		$this->store->save( array( 'method' => 'api_key', 'api_key' => 'gr_live_abcdefghijklmnop', 'domain_id' => 'd1' ) );
		ob_start();
		$this->page()->plugins_notice();
		$this->assertSame( '', ob_get_clean() );
	}

	public function test_callback_notice_is_read_once_and_starts_the_setup(): void {
		$this->store->save( array( 'method' => 'oauth', 'access_token' => 'a', 'refresh_token' => 'r', 'domain_id' => 'd1' ) );
		$this->transients['genready_notice_7'] = array( 'ok' => true );
		$page                                  = $this->page();
		$page->enqueue( 'settings_page_genready' );
		$this->assertStringContainsString( '"justConnected":true', $this->inline['genready-admin'] );
		$this->assertArrayNotHasKey( 'genready_notice_7', $this->transients );
	}

	// Fix round A, G3: the robots.txt card runs do_robots(), which sends a header, so the
	// Fixes tab builds its cards on the load hook, before wp-admin prints anything.

	public function test_fixes_tab_builds_the_cards_before_any_output(): void {
		$hooks = array();
		Monkey\Functions\when( 'add_action' )->alias( function ( $hook, $callback ) use ( &$hooks ) { $hooks[ $hook ] = $callback; } );
		$client = new FakeClient();
		$files  = new MemoryFiles();
		$fixes  = new FixStore();
		$caches = new RecordingCaches();
		$seo    = new SeoPlugins( $files );
		$cards  = new class( $fixes, new RobotsFix( $fixes, $files, $client, $caches, 'd1' ), new LlmsTxtFix( $fixes, $files, $client, $caches, $seo, 'd1' ), new JsonLdFix( $fixes, $client, $caches, $seo, 'd1' ), $files ) extends FixCards {
			/** @var int */
			public $built = 0;
			public function all( array $jsonld_target ): array {
				++$this->built;
				return array();
			}
			public function jsonld_target( ?string $report_url ): array {
				return array();
			}
		};
		$page   = new Page( $this->store, new Reports( $this->store, $client ), $cards );
		$page->register();
		$this->assertSame( array( $page, 'prepare' ), $hooks['load-settings_page_genready'] );

		$_GET = array( 'tab' => 'fixes' );
		Monkey\Functions\when( 'sanitize_key' )->returnArg( 1 );
		Monkey\Functions\when( 'wp_unslash' )->returnArg( 1 );
		Monkey\Functions\when( '__' )->returnArg( 1 );
		Monkey\Functions\when( 'esc_attr__' )->returnArg( 1 );
		Monkey\Functions\when( 'esc_html' )->returnArg( 1 );
		Monkey\Functions\when( 'esc_html_e' )->alias( function ( $t ) { echo $t; } );
		$page->prepare();
		$this->assertSame( 1, $cards->built );
		ob_start();
		$page->render();
		$html = (string) ob_get_clean();
		$this->assertSame( 1, $cards->built, 'render reuses the cards built on the load hook' );
		$this->assertStringContainsString( 'genready-fixes', $html );
	}
}
