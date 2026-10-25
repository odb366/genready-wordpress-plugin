<?php
namespace GenReady\Tests\Unit;

use Brain\Monkey;
use GenReady\Support\Markdown;
use PHPUnit\Framework\TestCase;

/**
 * The text of a scanned page is untrusted. wp_kses is the identity here, so
 * these tests prove that Parsedown's safe mode alone keeps the HTML safe.
 */
class MarkdownTest extends TestCase {
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Monkey\Functions\when( 'wp_kses' )->returnArg( 1 );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_formats_common_markdown(): void {
		$html = Markdown::to_html( "Some **bold** and *italic* text.\n\n- one\n- two\n\n1. first\n\n> quote\n\n`code`\n\n---" );
		$this->assertStringContainsString( '<strong>bold</strong>', $html );
		$this->assertStringContainsString( '<em>italic</em>', $html );
		$this->assertStringContainsString( '<ul>', $html );
		$this->assertStringContainsString( '<ol>', $html );
		$this->assertStringContainsString( '<blockquote>', $html );
		$this->assertStringContainsString( '<code>code</code>', $html );
		$this->assertStringContainsString( '<hr', $html );
	}

	public function test_tables(): void {
		$html = Markdown::to_html( "| A | B |\n| --- | --- |\n| 1 | 2 |" );
		$this->assertStringContainsString( '<table>', $html );
		$this->assertStringContainsString( '<th>A</th>', $html );
		$this->assertStringContainsString( '<td>2</td>', $html );
	}

	public function test_headings_move_down_two_levels_and_stop_at_h6(): void {
		$html = Markdown::to_html( "# One\n\n## Two\n\n#### Four\n\n###### Six" );
		$this->assertStringContainsString( '<h3>One</h3>', $html );
		$this->assertStringContainsString( '<h4>Two</h4>', $html );
		$this->assertStringContainsString( '<h6>Four</h6>', $html );
		$this->assertStringContainsString( '<h6>Six</h6>', $html );
		$this->assertStringNotContainsString( '<h1', $html );
		$this->assertStringNotContainsString( '<h2', $html );
	}

	public function test_links_open_in_a_new_tab_without_referrer(): void {
		$html = Markdown::to_html( '[Docs](https://example.com/docs)' );
		$this->assertStringContainsString( '<a target="_blank" rel="nofollow noopener noreferrer" href="https://example.com/docs">Docs</a>', $html );
	}

	public function test_html_in_the_text_is_shown_as_text(): void {
		$html = Markdown::to_html( "<script>alert(1)</script>\n\n<img src=x onerror=alert(1)>" );
		$this->assertStringNotContainsString( '<script', $html );
		$this->assertStringNotContainsString( '<img', $html );
		$this->assertStringContainsString( '&lt;script&gt;', $html );
	}

	public function test_javascript_links_are_not_links(): void {
		$html = Markdown::to_html( '[click](javascript:alert(1))' );
		$this->assertStringNotContainsString( 'href="javascript:', $html );
	}

	public function test_images_become_text_and_never_load(): void {
		$html = Markdown::to_html( "![Team photo](https://example.com/a.png)\n\n![](https://example.com/b.png)" );
		$this->assertStringNotContainsString( '<img', $html );
		$this->assertStringNotContainsString( 'example.com/a.png', $html );
		$this->assertStringContainsString( '[Image: Team photo]', $html );
		$this->assertStringContainsString( '[Image]', $html );
	}

	public function test_linked_image_keeps_the_link_and_drops_the_image(): void {
		$html = Markdown::to_html( '[![Logo](https://example.com/logo.png)](https://example.com/)' );
		$this->assertStringNotContainsString( '<img', $html );
		$this->assertStringContainsString( 'href="https://example.com/"', $html );
		$this->assertStringContainsString( '[Image: Logo]', $html );
	}

	public function test_empty_text_gives_empty_html(): void {
		$this->assertSame( '', Markdown::to_html( '' ) );
		$this->assertSame( '', Markdown::to_html( "  \n " ) );
	}
}
