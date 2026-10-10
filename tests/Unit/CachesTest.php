<?php
namespace GenReady\Tests\Unit;

use Brain\Monkey;
use GenReady\Compat\Caches;
use PHPUnit\Framework\TestCase;

class CachesTest extends TestCase {
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_no_cache_plugin_means_nothing_purged(): void {
		$this->assertSame( array(), ( new Caches() )->purge_all() );
		$this->assertSame( array(), ( new Caches() )->purge_url( 'https://example.com/', 0 ) );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_wp_rocket_is_purged_when_present(): void {
		require dirname( __DIR__ ) . '/fixtures/rocket-stubs.php';
		$GLOBALS['genready_purged'] = array();
		$caches                     = new Caches();
		$this->assertSame( array( 'WP Rocket' ), $caches->purge_all() );
		$this->assertSame( array( 'rocket_clean_domain' ), $GLOBALS['genready_purged'] );
		$GLOBALS['genready_purged'] = array();
		$this->assertSame( array( 'WP Rocket' ), $caches->purge_url( 'https://example.com/', 0 ) );
		$this->assertSame( array( 'rocket_clean_files:https://example.com/' ), $GLOBALS['genready_purged'] );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_litespeed_is_purged_through_its_actions(): void {
		require dirname( __DIR__ ) . '/fixtures/litespeed-stubs.php';
		Monkey\Actions\expectDone( 'litespeed_purge_all' )->once();
		Monkey\Actions\expectDone( 'litespeed_purge_url' )->once()->with( 'https://example.com/' );
		$caches = new Caches();
		$this->assertSame( array( 'LiteSpeed Cache' ), $caches->purge_all() );
		$this->assertSame( array( 'LiteSpeed Cache' ), $caches->purge_url( 'https://example.com/', 0 ) );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_failing_cache_does_not_stop_the_others(): void {
		require dirname( __DIR__ ) . '/fixtures/litespeed-stubs.php';
		eval( 'function rocket_clean_domain() { throw new \RuntimeException( "boom" ); } function rocket_clean_files( $urls ) {}' ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- defines a throwing stand-in.
		Monkey\Actions\expectDone( 'litespeed_purge_all' )->once();
		$this->assertSame( array( 'LiteSpeed Cache' ), ( new Caches() )->purge_all() );
	}
}
