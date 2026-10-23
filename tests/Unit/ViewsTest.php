<?php
namespace GenReady\Tests\Unit;

use Brain\Monkey;
use GenReady\Admin\Page;
use GenReady\Admin\Reports;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/fixtures/rest-doubles.php';

class ViewsTest extends TestCase {
	const XSS = '<script>alert(1)</script>';

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$escape = function ( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); };
		foreach ( array( 'esc_html', 'esc_attr', 'esc_textarea' ) as $fn ) {
			Monkey\Functions\when( $fn )->alias( $escape );
		}
		foreach ( array( 'esc_html__', 'esc_attr__' ) as $fn ) {
			Monkey\Functions\when( $fn )->alias( $escape );
		}
		Monkey\Functions\when( 'esc_html_e' )->alias( function ( $s ) use ( $escape ) { echo $escape( $s ); } );
		Monkey\Functions\when( 'esc_attr_e' )->alias( function ( $s ) use ( $escape ) { echo $escape( $s ); } );
		Monkey\Functions\when( 'esc_url' )->alias( function ( $u ) { return 1 === preg_match( '#^https?://#', (string) $u ) ? htmlspecialchars( (string) $u, ENT_QUOTES ) : ''; } );
		Monkey\Functions\when( 'esc_url_raw' )->returnArg( 1 );
		Monkey\Functions\when( '__' )->returnArg( 1 );
		Monkey\Functions\when( '_n' )->alias( function ( $one, $many, $n ) { return 1 === $n ? $one : $many; } );
		Monkey\Functions\when( 'admin_url' )->alias( function ( $p ) { return 'https://example.com/wp-admin/' . $p; } );
		Monkey\Functions\when( 'wp_date' )->alias(
			function ( $format, $time ) {
				$date = new \DateTime( '@' . $time );
				$date->setTimezone( new \DateTimeZone( 'Europe/Sofia' ) ); // The site's time zone.
				return $date->format( $format );
			}
		);
		Monkey\Functions\when( 'get_option' )->justReturn( 'Y-m-d' );
		Monkey\Functions\when( 'checked' )->alias( function ( $a, $b = true, $echo = true ) { $out = $a === $b ? ' checked="checked"' : ''; if ( $echo ) { echo $out; } return $out; } );
		Monkey\Functions\when( 'wp_parse_url' )->alias( 'parse_url' );
		Monkey\Functions\when( 'wp_strip_all_tags' )->alias( 'strip_tags' );
		Monkey\Functions\when( 'home_url' )->alias( function ( $p = '' ) { return 'https://example.com' . $p; } );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function assert_safe( string $html ): void {
		$this->assertStringNotContainsString( '<script', $html );
		$this->assertStringNotContainsString( 'onclick', strtolower( $html ) );
	}

	public function test_overview_escapes_report_text(): void {
		$report = Reports::summarize( report_fixture() );
		$html   = Page::view(
			'overview',
			array(
				'connected' => true,
				'state'     => array(
					'state'  => 'complete',
					'report' => $report,
				),
			)
		);
		$this->assert_safe( $html );
		$this->assertStringContainsString( '&lt;script&gt;alert(1)&lt;/script&gt;', $html, 'markdown and reasons are shown as text' );
		$this->assertStringContainsString( 'aria-label="AI Readiness: 72 of 100"', $html );
		$this->assertStringContainsString( 'Agents 2/8 +3', $html );
		$this->assertStringContainsString( 'AI crawlers that do not run JavaScript miss 42% of this page.', $html );
		$this->assertStringContainsString( 'aria-pressed="true"', $html );
		$this->assertStringContainsString( 'width="1280" height="800"', $html );
		$this->assertStringContainsString( '#genready-jsonld', $html );
		$this->assertStringContainsString( '#genready-llms', $html );
		$this->assertStringContainsString( 'https://genready.ai/report/11111111-2222-3333-4444-555555555555', $html );
	}

	public function test_overview_drops_a_screenshot_url_that_is_not_http(): void {
		$fixture                            = report_fixture();
		$fixture['aiView']['screenshotUrl'] = 'javascript:alert(1)';
		$report                             = Reports::summarize( $fixture );
		$this->assertNull( $report['ai_view']['screenshot'] );
		$html = Page::view(
			'overview',
			array(
				'connected' => true,
				'state'     => array(
					'state'  => 'complete',
					'report' => $report,
				),
			)
		);
		$this->assertStringNotContainsString( 'javascript:', $html );
	}

	public function test_overview_states_without_a_report(): void {
		foreach ( array( array( 'state' => 'none' ), array( 'state' => 'pending' ), array( 'state' => 'failed', 'message' => self::XSS ), array( 'state' => 'error', 'message' => self::XSS ) ) as $state ) {
			$html = Page::view(
				'overview',
				array(
					'connected' => true,
					'state'     => $state,
				)
			);
			$this->assert_safe( $html );
		}
	}

	// Spec delta 11: "Scan again" can scan any page, so the connected text names no page.
	public function test_a_running_scan_has_the_same_text_with_and_without_a_connection(): void {
		foreach ( array( true, false ) as $connected ) {
			$html = Page::view( 'overview', array( 'connected' => $connected, 'state' => array( 'state' => 'pending' ) ) );
			$this->assertStringContainsString( '<p>Scanning this page. This takes about 30 seconds.</p>', $html );
			$this->assertStringNotContainsString( 'home page', $html );
		}
	}

	// PHP L1: wp_date() shows the date in the site's time zone; date_i18n() showed the UTC date.
	public function test_the_scan_date_is_in_the_site_time_zone(): void {
		$report = Reports::summarize( report_fixture( array( 'completedAt' => '2026-10-08T22:30:00.000Z' ) ) );
		$html   = Page::view( 'overview', array( 'connected' => true, 'state' => array( 'state' => 'complete', 'report' => $report ) ) );
		$this->assertStringContainsString( 'Scanned on 2026-10-09', $html, '01:30 in Sofia' );
	}

	public function test_fixes_view_escapes_card_text(): void {
		$card = array(
			'kind'       => 'robots',
			'title'      => 'robots.txt',
			'state'      => 'applied',
			'state_text' => 'Applied',
			'message'    => self::XSS,
			'blocked'    => false,
			'applied'    => true,
			'versions'   => array( self::XSS ),
			'live'       => array(
				'state'   => 'different',
				'message' => self::XSS,
			),
			'live_check' => true,
			'mode'       => 'virtual',
			'tagline'    => '',
		);
		$llms               = $card;
		$llms['kind']       = 'llms';
		$llms['title']      = 'llms.txt';
		$llms['tagline']    = self::XSS;
		$jsonld             = $card;
		$jsonld['kind']     = 'jsonld';
		$jsonld['title']    = 'JSON-LD';
		$jsonld['live']     = null;
		$jsonld['live_check'] = false;
		$html               = Page::view(
			'fixes',
			array(
				'connected' => true,
				'cards'     => array( $card, $llms, $jsonld ),
			)
		);
		$this->assert_safe( $html );
		$this->assertStringContainsString( 'id="genready-robots"', $html );
		$this->assertStringContainsString( 'id="genready-llms"', $html );
		$this->assertStringContainsString( 'id="genready-jsonld"', $html );
		$this->assertStringContainsString( 'role="status"', $html );
		$this->assertSame( 2, substr_count( $html, 'data-genready-action="download">' ), 'robots and llms offer the download when WordPress cannot write; JSON-LD has none' );
	}

	public function test_connection_view_escapes_account_text(): void {
		$html = Page::view(
			'connection',
			array(
				'connected' => true,
				'account'   => array(
					'method'    => 'oauth',
					'plan'      => self::XSS,
					'site_name' => self::XSS,
					'hostname'  => 'example.com',
					'verified'  => false,
				),
				'tag_on'    => true,
			)
		);
		$this->assert_safe( $html );
		$this->assertStringContainsString( '&lt;script&gt;', $html );

		$html = Page::view(
			'connection',
			array(
				'connected' => false,
				'account'   => null,
				'tag_on'    => false,
			)
		);
		$this->assert_safe( $html );
		$this->assertStringContainsString( 'https://genready.ai/terms', $html );
		$this->assertStringContainsString( 'https://genready.ai/privacy', $html );
		$this->assertMatchesRegularExpression( '/<input type="checkbox" id="genready-send-email"[^>]*>/', $html );
		$this->assertDoesNotMatchRegularExpression( '/<input type="checkbox" id="genready-send-email"[^>]*checked/', $html, 'the email box is off by default' );
	}

	public function test_fixes_view_hides_the_file_action_when_the_site_may_not_write(): void {
		$llms = array(
			'kind'       => 'llms',
			'title'      => 'llms.txt',
			'state'      => 'applied',
			'state_text' => 'Applied',
			'message'    => '',
			'blocked'    => false,
			'applied'    => true,
			'versions'   => array(),
			'live'       => array(
				'state'   => 'no_route',
				'message' => 'Your server does not pass /llms.txt to WordPress.',
			),
			'live_check' => true,
			'mode'       => 'virtual',
			'tagline'    => '',
			'can_write'  => true,
			'as_file'    => false,
		);
		$html = Page::view( 'fixes', array( 'connected' => true, 'cards' => array( $llms ) ) );
		$this->assertStringContainsString( 'data-genready-action="as-file" hidden>', $html );
		$llms['as_file'] = true;
		$html            = Page::view( 'fixes', array( 'connected' => true, 'cards' => array( $llms ) ) );
		$this->assertStringContainsString( 'data-genready-action="as-file">', $html );
	}

	// Fix round A, G5 L3: the screenshot comes only from GenReady's own host.

	/**
	 * @dataProvider screenshot_urls
	 */
	public function test_screenshot_url_must_come_from_the_api_host( string $url, string $base, ?string $expected ): void {
		$this->assertSame( $expected, Reports::screenshot_url( $url, $base ) );
	}

	public function screenshot_urls(): array {
		return array(
			'same host, https'          => array( 'https://genready.ai/screenshots/a.png', 'https://genready.ai', 'https://genready.ai/screenshots/a.png' ),
			'host case'                 => array( 'https://GenReady.ai/a.png', 'https://genready.ai', 'https://GenReady.ai/a.png' ),
			'other host'                => array( 'https://evil.example/a.png', 'https://genready.ai', null ),
			'look-alike host'           => array( 'https://genready.ai.evil.example/a.png', 'https://genready.ai', null ),
			'http on an https base'     => array( 'http://genready.ai/a.png', 'https://genready.ai', null ),
			'http on an http base'      => array( 'http://127.0.0.1:5002/a.png', 'http://127.0.0.1:5002', 'http://127.0.0.1:5002/a.png' ),
			'https on an http base'     => array( 'https://127.0.0.1/a.png', 'http://127.0.0.1:5002', 'https://127.0.0.1/a.png' ),
			'javascript'                => array( 'javascript:alert(1)', 'https://genready.ai', null ),
			'no host'                   => array( '/a.png', 'https://genready.ai', null ),
		);
	}

	public function test_report_drops_a_screenshot_from_another_host(): void {
		$fixture                            = report_fixture();
		$fixture['aiView']['screenshotUrl'] = 'https://evil.example/a.png';
		$this->assertNull( Reports::summarize( $fixture )['ai_view']['screenshot'] );
		$this->assertSame( 'https://genready.ai/screenshots/a.png', Reports::summarize( report_fixture() )['ai_view']['screenshot'] );
	}

	public function test_overview_names_a_page_that_is_not_the_home_page(): void {
		$report = Reports::summarize( report_fixture( array( 'url' => 'https://example.com/pricing/' ) ) );
		$html   = Page::view( 'overview', array( 'connected' => true, 'state' => array( 'state' => 'complete', 'report' => $report ) ) );
		$this->assertStringContainsString( 'How AI sees this page', $html );
		$this->assertStringNotContainsString( 'How AI sees your homepage', $html );
		$this->assertStringContainsString( '<p class="genready-aiview__url">https://example.com/pricing/</p>', $html );
		$this->assertStringContainsString( 'alt="Screenshot of this page"', $html );
		$this->assertStringContainsString( 'data-genready-url="https://example.com/pricing/"', $html );
	}

	public function test_overview_of_the_home_page_keeps_the_homepage_texts(): void {
		$report = Reports::summarize( report_fixture() );
		$html   = Page::view( 'overview', array( 'connected' => true, 'state' => array( 'state' => 'complete', 'report' => $report ) ) );
		$this->assertStringContainsString( 'How AI sees your homepage', $html );
		$this->assertStringContainsString( 'alt="Screenshot of your homepage"', $html );
		$this->assertStringNotContainsString( 'genready-aiview__url', $html );
	}

	public function test_fix_cards_without_an_account_link_to_the_banner(): void {
		$card = array( 'kind' => 'robots', 'title' => 'robots.txt', 'state' => 'not_applied', 'state_text' => 'Not applied', 'message' => 'Create a free account first. GenReady Pro and Agency generate this file.', 'account_link' => true, 'blocked' => false, 'applied' => false, 'versions' => array(), 'live' => null, 'live_check' => false, 'can_write' => true );
		$html = Page::view( 'fixes', array( 'connected' => false, 'cards' => array( $card ) ) );
		$this->assertStringContainsString( 'Create a free account first. GenReady Pro and Agency generate this file.', $html );
		$this->assertStringContainsString( 'href="https://example.com/wp-admin/options-general.php?page=genready#genready-account">Create my free account</a>', $html );
		$this->assertMatchesRegularExpression( '/data-genready-action="preview" disabled/', $html );
		$this->assertStringNotContainsString( 'Connect GenReady to preview a fix.', $html );
	}

	public function test_the_scan_form_escapes_the_suggestions(): void {
		$html = Page::view( 'scan-form', array( 'home' => 'https://example.com/', 'suggestions' => array( 'https://example.com/a"><script>alert(1)</script>' ), 'notice' => self::XSS ) );
		$this->assert_safe( $html );
		$this->assertStringContainsString( 'list="genready-pages"', $html );
		$this->assertStringContainsString( 'novalidate', $html );
	}
}
