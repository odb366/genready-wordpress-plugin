<?php
namespace GenReady\Tests\Unit;

use Brain\Monkey;
use GenReady\Admin\FixCards;
use GenReady\Admin\Page;
use GenReady\Admin\Reports;
use GenReady\Api\ApiError;
use GenReady\Compat\SeoPlugins;
use GenReady\Connection\TokenStore;
use GenReady\Fixes\FixStore;
use GenReady\Fixes\JsonLdFix;
use GenReady\Fixes\LlmsTxtFix;
use GenReady\Fixes\RobotsFix;
use GenReady\Onboarding\AnonymousScans;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/fixtures/doubles.php';
require_once dirname( __DIR__ ) . '/fixtures/fix-doubles.php';
require_once dirname( __DIR__ ) . '/fixtures/rest-doubles.php';

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
	/** @var FakeClient */
	private $client;
	/** @var FixCards|null The cards of page(), or null for the real ones. */
	private $cards;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Monkey\Functions\when( 'wp_kses' )->returnArg( 1 );
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
		Monkey\Functions\when( 'delete_option' )->alias( function ( $k ) use ( &$opts ) { unset( $opts[ $k ] ); return true; } );
		Monkey\Functions\when( 'set_transient' )->alias( function ( $k, $v ) use ( &$trans ) { $trans[ $k ] = $v; return true; } );
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
		Monkey\Functions\when( 'esc_url_raw' )->returnArg( 1 );
		$this->store  = new TokenStore();
		$this->client = new FakeClient();
		$this->cards  = null;
	}

	protected function tearDown(): void {
		$_GET = array();
		Monkey\tearDown();
		parent::tearDown();
	}

	private function scans(): AnonymousScans {
		return new AnonymousScans( $this->store, $this->client );
	}

	private function page(): Page {
		$files  = new MemoryFiles();
		$fixes  = new FixStore();
		$caches = new RecordingCaches();
		$seo    = new SeoPlugins( $files );
		$scans  = $this->scans();
		return new Page(
			$this->store,
			new Reports( $this->store, $this->client, $scans ),
			$this->cards ?? new FixCards(
				$fixes,
				new RobotsFix( $fixes, $files, $this->client, $caches, 'd1' ),
				new LlmsTxtFix( $fixes, $files, $this->client, $caches, $seo, 'd1' ),
				new JsonLdFix( $fixes, $this->client, $caches, $seo, 'd1' ),
				$files,
				function (): bool {
					return null !== $this->store->load();
				}
			),
			$scans
		);
	}

	/** Stubs for render(): the escaping of ViewsTest and the WordPress data the views read. */
	private function render( array $get = array(), bool $enqueue_first = false ): string {
		$_GET   = $get;
		$escape = function ( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); };
		foreach ( array( 'esc_html', 'esc_attr', 'esc_textarea', 'esc_html__', 'esc_attr__' ) as $fn ) {
			Monkey\Functions\when( $fn )->alias( $escape );
		}
		Monkey\Functions\when( 'esc_html_e' )->alias( function ( $s ) use ( $escape ) { echo $escape( $s ); } );
		Monkey\Functions\when( 'esc_attr_e' )->alias( function ( $s ) use ( $escape ) { echo $escape( $s ); } );
		Monkey\Functions\when( 'esc_url' )->alias( function ( $u ) { return 1 === preg_match( '#^https?://#', (string) $u ) ? htmlspecialchars( (string) $u, ENT_QUOTES ) : ''; } );
		Monkey\Functions\when( 'esc_url_raw' )->returnArg( 1 );
		Monkey\Functions\when( '__' )->returnArg( 1 );
		Monkey\Functions\when( '_n' )->alias( function ( $one, $many, $n ) { return 1 === $n ? $one : $many; } );
		Monkey\Functions\when( 'sanitize_key' )->alias( function ( $k ) { return strtolower( (string) preg_replace( '/[^a-z0-9_\-]/i', '', (string) $k ) ); } );
		Monkey\Functions\when( 'wp_unslash' )->returnArg( 1 );
		Monkey\Functions\when( 'home_url' )->alias( function ( $p = '' ) { return 'https://example.com' . $p; } );
		Monkey\Functions\when( 'wp_parse_url' )->alias( function ( $u, $c = -1 ) { return parse_url( $u, $c ); } );
		Monkey\Functions\when( 'get_posts' )->justReturn( array( 2 ) );
		Monkey\Functions\when( 'get_permalink' )->justReturn( 'https://example.com/sample-page/' );
		Monkey\Functions\when( 'wp_get_current_user' )->justReturn( (object) array( 'user_email' => 'admin@example.com' ) );
		Monkey\Functions\when( 'is_email' )->alias( function ( $e ) { return false !== filter_var( $e, FILTER_VALIDATE_EMAIL ) ? $e : false; } );
		Monkey\Functions\when( 'wp_date' )->alias(
			function ( $format, $time ) {
				$date = new \DateTime( '@' . $time );
				$date->setTimezone( new \DateTimeZone( 'Europe/Sofia' ) ); // The site's time zone.
				return $date->format( $format );
			}
		);
		$this->options['date_format']         = 'Y-m-d';
		Monkey\Functions\when( 'wp_strip_all_tags' )->alias( 'strip_tags' );
		$this->options['permalink_structure'] = '/%postname%/';
		$page = $this->page();
		if ( $enqueue_first ) {
			$page->enqueue( 'settings_page_genready' ); // WordPress runs admin_enqueue_scripts before the page callback.
		}
		ob_start();
		$page->render();
		return (string) ob_get_clean();
	}


	public function test_assets_load_only_on_the_genready_page(): void {
		Monkey\Functions\when( 'get_user_meta' )->justReturn( '1' );
		$page = $this->page();
		foreach ( array( 'index.php', 'options-general.php', 'settings_page_other', 'plugins.php' ) as $hook ) {
			$page->enqueue( $hook );
		}
		$this->assertSame( array(), $this->scripts );

		$page->enqueue( 'settings_page_genready' );
		$this->assertSame( array( 'style:genready-admin', 'genready-admin', 'genready-onboarding' ), $this->scripts );
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
		$this->assertStringContainsString( 'See how AI search engines and agents read your site. No account needed.', $html );
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
		$page   = new Page( $this->store, new Reports( $this->store, $client ), $cards, new AnonymousScans( $this->store, $client ) );
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

	const SCAN1 = '11111111-1111-4111-8111-111111111111';
	const SCAN2 = '22222222-2222-4222-8222-222222222222';
	const TOKEN = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQ';

	public function test_the_first_screen_without_a_connection_or_a_scan_makes_no_call(): void {
		Monkey\Functions\expect( 'wp_remote_request' )->never();
		$html = $this->render();
		$this->assertStringContainsString( '<h2 id="genready-start-title">See how AI search engines and agents read your site</h2>', $html );
		$this->assertStringContainsString( '<label for="genready-page-url" class="genready-scan-form__label">Page to analyze</label>', $html );
		$this->assertStringContainsString( 'Enter the address of any public page on this site.', $html );
		$this->assertStringContainsString( 'type="url" id="genready-page-url"', $html );
		$this->assertStringContainsString( 'value="https://example.com/"', $html );
		$this->assertStringContainsString( '<option value="https://example.com/sample-page/"></option>', $html );
		$this->assertStringContainsString( 'Check AI readiness', $html );
		$this->assertStringContainsString( 'Sends your site address to genready.ai. No account needed.', $html );
		$this->assertStringContainsString( 'https://genready.ai/terms', $html );
		$this->assertStringContainsString( 'Already have a GenReady account? Connect', $html );
		$this->assertStringContainsString( '<div id="genready-connect-panel" hidden>', $html );
		$this->assertStringContainsString( 'Connect to GenReady', $html );
		$this->assertStringNotContainsString( 'genready-account-form', $html );
		$this->assertSame( array(), $this->client->publics );
	}

	public function test_a_stored_scan_shows_the_banner_the_scan_form_and_the_report(): void {
		$this->scans()->remember( self::SCAN1, 'https://example.com/', self::TOKEN );
		$this->client->public_result = report_fixture( array( 'reportId' => self::SCAN1 ) );
		$html                        = $this->render();
		$this->assertStringContainsString( 'id="genready-account"', $html );
		$this->assertStringContainsString( 'Save your site and track your score', $html );
		$this->assertStringContainsString( '<label for="genready-account-email">Email</label>', $html );
		$this->assertStringContainsString( 'type="email" id="genready-account-email" class="regular-text" value="admin@example.com"', $html );
		$this->assertStringContainsString( 'Create my free account', $html );
		$this->assertStringContainsString( 'Creates a GenReady account for this email and adds this site to it. GenReady emails you a link to set your password.', $html );
		$this->assertStringContainsString( 'Check AI readiness', $html );
		$this->assertStringNotContainsString( 'Recent scans', $html, 'only with 2 or more scans' );
		$this->assertStringContainsString( 'How AI sees your homepage', $html );
		$this->assertSame( self::TOKEN, $this->client->publics[0][3] );
	}

	public function test_the_recent_list_marks_the_open_scan(): void {
		$this->scans()->remember( self::SCAN1, 'https://example.com/', self::TOKEN );
		$this->scans()->remember( self::SCAN2, 'https://example.com/pricing/', self::TOKEN );
		$this->client->public_result = report_fixture( array( 'reportId' => self::SCAN1 ) );
		$html                        = $this->render( array( 'scan' => self::SCAN1 ) );
		$this->assertStringContainsString( 'Recent scans', $html );
		$this->assertStringContainsString( '<span aria-current="true">Home page</span>', $html );
		$this->assertStringContainsString( 'options-general.php?page=genready&amp;scan=' . self::SCAN2 . '">/pricing/</a>', $html );
		$this->assertSame( '/api/v1/wp/scans/' . self::SCAN1, $this->client->publics[0][1] );
	}

	// PHP L1: 00:30 in Sofia is the day before in UTC; the list shows the site's date.
	public function test_the_recent_list_dates_are_in_the_site_time_zone(): void {
		$this->scans()->remember( self::SCAN1, 'https://example.com/', self::TOKEN );
		$this->scans()->remember( self::SCAN2, 'https://example.com/pricing/', self::TOKEN );
		$at = ( new \DateTime( 'today 00:30', new \DateTimeZone( 'Europe/Sofia' ) ) )->getTimestamp();
		$this->options[ AnonymousScans::OPTION ][0]['at'] = $at;
		$this->client->public_result                      = report_fixture( array( 'reportId' => self::SCAN2 ) );
		$html                                             = $this->render();
		$site_date                                        = ( new \DateTime( '@' . $at ) )->setTimezone( new \DateTimeZone( 'Europe/Sofia' ) )->format( 'Y-m-d' );
		$this->assertNotSame( gmdate( 'Y-m-d', $at ), $site_date );
		$this->assertStringContainsString( '<span class="genready-recent__date">' . $site_date . '</span>', $html );
	}

	// Spec 11 err.gone: the 404 empties the list while the scripts load, so render() shows the first screen.
	public function test_the_first_screen_says_when_the_only_scan_is_gone(): void {
		Monkey\Functions\when( 'get_user_meta' )->justReturn( '1' );
		$this->scans()->remember( self::SCAN1, 'https://example.com/', self::TOKEN );
		$this->client->public_result = new ApiError( 404, 'NOT_FOUND', 'Scan not found' );
		$html                        = $this->render( array(), true );
		$this->assertStringContainsString( '<h2 id="genready-start-title">', $html );
		$this->assertMatchesRegularExpression( '#<div id="genready-scan-result" class="genready-result genready-result--error"[^>]*><p>This scan is no longer available\. Check the page again\.</p></div>#', $html );
		$this->assertCount( 1, $this->client->publics, 'one call, while the scripts load' );
	}

	public function test_the_first_screen_has_an_empty_result_without_a_gone_scan(): void {
		$html = $this->render( array(), true );
		$this->assertStringContainsString( '<div id="genready-scan-result" class="genready-result" role="status" aria-live="polite" tabindex="-1"></div>', $html );
	}

	public function test_connect_only_while_the_onboarding_is_paused(): void {
		$this->scans()->remember( self::SCAN1, 'https://example.com/', self::TOKEN );
		$this->transients['genready_anon_off'] = 1;
		$html                                  = $this->render();
		$this->assertStringContainsString( 'GenReady paused free scans for now. Connect with a GenReady account.', $html );
		$this->assertStringContainsString( 'Connect to GenReady', $html );
		$this->assertStringContainsString( 'id="genready-account"', $html, 'the fix card link still has a target' );
		$this->assertStringNotContainsString( 'Check this page', $html );
		$this->assertStringNotContainsString( 'genready-account-form', $html );
		$this->assertSame( array(), $this->client->publics );
	}

	/** The Fixes tab without a connection, with one card as FixCards::without_account() makes it. */
	private function render_fixes(): string {
		$files       = new MemoryFiles();
		$fixes       = new FixStore();
		$caches      = new RecordingCaches();
		$seo         = new SeoPlugins( $files );
		$this->cards = new class( $fixes, new RobotsFix( $fixes, $files, $this->client, $caches, 'd1' ), new LlmsTxtFix( $fixes, $files, $this->client, $caches, $seo, 'd1' ), new JsonLdFix( $fixes, $this->client, $caches, $seo, 'd1' ), $files ) extends FixCards {
			public function all( array $jsonld_target ): array {
				return array( self::without_account( array( 'kind' => 'robots', 'title' => 'robots.txt', 'state' => 'not_applied', 'state_text' => 'Not applied', 'applied' => false ) ) );
			}
			public function jsonld_target( ?string $report_url ): array {
				return array();
			}
		};
		return $this->render( array( 'tab' => 'fixes' ) );
	}

	public function test_fix_cards_without_a_stored_scan_link_to_the_first_screen(): void {
		$html = $this->render_fixes();
		$this->assertStringContainsString( 'href="https://example.com/wp-admin/options-general.php?page=genready">Check this page</a>', $html );
		$this->assertStringNotContainsString( '#genready-account', $html );
	}

	public function test_fix_cards_with_a_stored_scan_link_to_the_banner(): void {
		$this->scans()->remember( self::SCAN1, 'https://example.com/', self::TOKEN );
		$html = $this->render_fixes();
		$this->assertStringContainsString( 'href="https://example.com/wp-admin/options-general.php?page=genready#genready-account">Create my free account</a>', $html );
		$this->assertStringNotContainsString( 'Check this page', $html );
	}

	// While GenReady paused free scans the Overview is the connect form, which has id="genready-account".
	public function test_fix_cards_while_free_scans_are_paused_link_to_the_connect_form(): void {
		$this->transients['genready_anon_off'] = 1;
		$html                                  = $this->render_fixes();
		$this->assertStringContainsString( 'href="https://example.com/wp-admin/options-general.php?page=genready#genready-account">Create my free account</a>', $html );
		$this->assertStringNotContainsString( 'Check this page', $html );
	}

	// Review Focus 3.
	public function test_overview_shows_the_first_screen_after_the_salts_change(): void {
		$this->scans()->remember( self::SCAN1, 'https://example.com/', self::TOKEN );
		$this->assertArrayHasKey( AnonymousScans::OPTION, $this->options );
		Monkey\Functions\when( 'wp_salt' )->justReturn( str_repeat( 'x', 64 ) );
		$html = $this->render();
		$this->assertStringContainsString( '<h2 id="genready-start-title">', $html );
		$this->assertStringNotContainsString( 'genready-account-form', $html, 'not the anonymous Overview' );
		$this->assertSame( array(), $this->client->publics );
		$this->assertArrayNotHasKey( AnonymousScans::OPTION, $this->options, 'the broken entry is dropped' );
	}

	public function test_the_account_notice_shows_two_lines_and_skips_the_setup(): void {
		$this->store->save( array( 'method' => 'api_key', 'api_key' => 'gr_live_abcdefghijklmnop', 'domain_id' => 'd1' ) );
		$this->transients['genready_notice_7'] = array( 'ok' => true, 'account' => true, 'email' => 'owner@example.com' );
		$page                                  = $this->page();
		Monkey\Functions\when( 'get_user_meta' )->justReturn( '1' );
		$page->enqueue( 'settings_page_genready' );
		$this->assertStringContainsString( '"justConnected":false', $this->inline['genready-admin'] );
		$this->transients['genready_notice_7'] = array( 'ok' => true, 'account' => true, 'email' => 'owner@example.com' );
		$html                                  = $this->render();
		$this->assertStringContainsString( 'Your free GenReady account is ready. This site is connected and verified.', $html );
		$this->assertStringContainsString( 'GenReady sent a link to owner@example.com. Use it to set your password.', $html );
		$this->assertStringNotContainsString( 'GenReady is connected.', $html );
	}

	public function test_the_onboarding_script_loads_only_without_a_connection(): void {
		Monkey\Functions\when( 'get_user_meta' )->justReturn( '1' );
		$this->page()->enqueue( 'settings_page_genready' );
		$this->assertContains( 'genready-onboarding', $this->scripts );
		$this->scripts = array();
		$this->store->save( array( 'method' => 'api_key', 'api_key' => 'gr_live_abcdefghijklmnop', 'domain_id' => 'd1' ) );
		$this->page()->enqueue( 'settings_page_genready' );
		$this->assertNotContains( 'genready-onboarding', $this->scripts );
	}

	public function test_plugins_notice_hides_after_a_stored_scan(): void {
		Monkey\Functions\when( 'get_user_meta' )->justReturn( '' );
		Monkey\Functions\when( 'get_current_screen' )->justReturn( (object) array( 'id' => 'plugins' ) );
		$this->scans()->remember( self::SCAN1, 'https://example.com/', self::TOKEN );
		ob_start();
		$this->page()->plugins_notice();
		$this->assertSame( '', ob_get_clean() );
	}
}
