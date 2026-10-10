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
		Monkey\Functions\when( 'date_i18n' )->alias( function ( $f, $t ) { return gmdate( 'Y-m-d', $t ); } );
		Monkey\Functions\when( 'get_option' )->justReturn( 'Y-m-d' );
		Monkey\Functions\when( 'checked' )->alias( function ( $a, $b = true, $echo = true ) { $out = $a === $b ? ' checked="checked"' : ''; if ( $echo ) { echo $out; } return $out; } );
		Monkey\Functions\when( 'wp_parse_url' )->alias( 'parse_url' );
		Monkey\Functions\when( 'wp_strip_all_tags' )->alias( 'strip_tags' );
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
		$this->assertStringContainsString( 'checked="checked"', $html, 'the email box is on by default' );
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
}
