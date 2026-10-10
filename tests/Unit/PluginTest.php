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
}
