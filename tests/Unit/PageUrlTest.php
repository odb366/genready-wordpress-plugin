<?php
namespace GenReady\Tests\Unit;

use Brain\Monkey;
use GenReady\Onboarding\PageUrl;
use PHPUnit\Framework\TestCase;

class PageUrlTest extends TestCase {
	/** @var string */
	private $permalinks = '/%postname%/';
	/** @var string */
	private $home = 'https://example.com';

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->permalinks = '/%postname%/';
		$this->home       = 'https://example.com';
		$links            = &$this->permalinks;
		$home             = &$this->home;
		Monkey\Functions\when( 'home_url' )->alias( function ( $path = '' ) use ( &$home ) { return $home . $path; } );
		Monkey\Functions\when( 'wp_parse_url' )->alias( function ( $url, $component = -1 ) { return parse_url( $url, $component ); } );
		Monkey\Functions\when( 'get_option' )->alias( function ( $k, $d = false ) use ( &$links ) { return 'permalink_structure' === $k ? $links : $d; } );
		Monkey\Functions\when( '__' )->returnArg( 1 );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_accepts_pages_on_this_site_with_or_without_www(): void {
		$this->assertTrue( PageUrl::is_on_site( 'https://example.com/pricing/' ) );
		$this->assertTrue( PageUrl::is_on_site( 'http://www.example.com/a' ) );
		$this->assertTrue( PageUrl::is_on_site( 'https://WWW.EXAMPLE.COM/a' ) );
		$this->home = 'https://www.example.com';
		$this->assertTrue( PageUrl::is_on_site( 'https://example.com/' ) );
	}

	public function test_refuses_other_hosts_schemes_and_long_addresses(): void {
		foreach ( array( '', '/pricing/', 'https://example.org/', 'https://shop.example.com/', 'https://example.com.evil.test/', 'ftp://example.com/', 'javascript:alert(1)', 'https://example.com/' . str_repeat( 'a', 2048 ) ) as $url ) {
			$this->assertFalse( PageUrl::is_on_site( $url ), $url );
		}
	}

	// Review Focus 5.
	public function test_home_test_ignores_scheme_www_case_slash_query_and_fragment(): void {
		foreach ( array( 'https://example.com/', 'https://example.com', 'http://www.EXAMPLE.com/', 'https://example.com/?utm_source=x', 'https://example.com/#top' ) as $url ) {
			$this->assertTrue( PageUrl::is_home( $url ), $url );
		}
		foreach ( array( 'https://example.com/pricing/', 'https://example.org/', 'not a url' ) as $url ) {
			$this->assertFalse( PageUrl::is_home( $url ), $url );
		}
	}

	public function test_home_test_with_a_home_in_a_subfolder(): void {
		$this->home = 'https://example.com/blog';
		$this->assertTrue( PageUrl::is_home( 'https://example.com/blog/' ) );
		$this->assertFalse( PageUrl::is_home( 'https://example.com/' ) );
	}

	// Review Focus 2.
	public function test_plain_permalink_pages_are_not_the_home_page(): void {
		$this->permalinks = '';
		$this->assertTrue( PageUrl::is_on_site( 'https://example.com/?page_id=2' ) );
		$this->assertFalse( PageUrl::is_home( 'https://example.com/?page_id=2' ) );
		$this->assertTrue( PageUrl::is_home( 'https://example.com/' ) );
		$this->assertSame( '/?page_id=2', PageUrl::label( 'https://example.com/?page_id=2' ) );
	}

	public function test_labels(): void {
		$this->assertSame( 'Home page', PageUrl::label( 'https://example.com/' ) );
		$this->assertSame( '/pricing/', PageUrl::label( 'https://example.com/pricing/' ) );
	}

	public function test_suggestions_are_published_pages_and_posts_without_a_password(): void {
		$args = null;
		Monkey\Functions\expect( 'get_posts' )->once()->andReturnUsing(
			function ( $a ) use ( &$args ) {
				$args = $a;
				return array( 3, 4, 5, 6 );
			}
		);
		Monkey\Functions\when( 'get_permalink' )->alias(
			function ( $id ) {
				$map = array(
					3 => 'https://example.com/about/',
					4 => 'https://example.com/about/',
					5 => 'https://cdn.example.net/x/',
					6 => false,
				);
				return $map[ $id ];
			}
		);
		Monkey\Functions\expect( 'wp_remote_request' )->never();
		$this->assertSame( array( 'https://example.com/about/' ), PageUrl::suggestions() );
		$this->assertSame( array( 'page', 'post' ), $args['post_type'] );
		$this->assertSame( 'publish', $args['post_status'] );
		$this->assertFalse( $args['has_password'] );
		$this->assertSame( 50, $args['numberposts'] );
		$this->assertSame( 'modified', $args['orderby'] );
		$this->assertSame( 'DESC', $args['order'] );
		$this->assertSame( 'ids', $args['fields'] );
	}
}
