<?php
namespace GenReady\Tests\Unit;

use Brain\Monkey;
use GenReady\Verification\MetaTag;
use PHPUnit\Framework\TestCase;

class MetaTagTest extends TestCase {
	/** @var array */
	private $options = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->options = array();
		$opts          = &$this->options;
		Monkey\Functions\when( 'get_option' )->alias( function ( $k, $d = false ) use ( &$opts ) { return $opts[ $k ] ?? $d; } );
		Monkey\Functions\when( 'update_option' )->alias( function ( $k, $v ) use ( &$opts ) { $opts[ $k ] = $v; return true; } );
		Monkey\Functions\when( 'delete_option' )->alias( function ( $k ) use ( &$opts ) { unset( $opts[ $k ] ); return true; } );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_prints_the_tag_only_on_the_front_page(): void {
		$this->options['genready_meta'] = 'abcDEF12345';
		Monkey\Functions\when( 'esc_attr' )->returnArg();
		Monkey\Functions\when( 'is_front_page' )->justReturn( false );
		ob_start();
		( new MetaTag() )->render();
		$this->assertSame( '', ob_get_clean() );
		Monkey\Functions\when( 'is_front_page' )->justReturn( true );
		ob_start();
		( new MetaTag() )->render();
		$this->assertSame( '<meta name="genready-site-verification" content="abcDEF12345">' . "\n", ob_get_clean() );
	}

	public function test_ignores_a_malformed_token(): void {
		$this->options['genready_meta'] = '"><script>';
		Monkey\Functions\when( 'is_front_page' )->justReturn( true );
		ob_start();
		( new MetaTag() )->render();
		$this->assertSame( '', ob_get_clean() );
	}

	public function test_render_swallows_errors(): void {
		$this->options['genready_meta'] = 'abcDEF12345';
		Monkey\Functions\when( 'is_front_page' )->alias(
			function () {
				throw new \RuntimeException( 'boom' );
			}
		);
		ob_start();
		( new MetaTag() )->render();
		$this->assertSame( '', ob_get_clean() );
	}

	public function test_enable_and_disable_store_and_remove_the_token(): void {
		$meta = new MetaTag();
		$meta->enable( 'abcDEF12345' );
		$this->assertSame( 'abcDEF12345', $this->options['genready_meta'] );
		$meta->disable();
		$this->assertArrayNotHasKey( 'genready_meta', $this->options );
	}
}
