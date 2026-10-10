<?php
namespace GenReady\Tests\Unit;

use Brain\Monkey;
use GenReady\Fixes\SchemaNode;
use PHPUnit\Framework\TestCase;

/** The pure node checks, moved out of JsonLdFix with SchemaNode. JsonLdFixTest covers them through preview(). */
class SchemaNodeTest extends TestCase {
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Monkey\Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_clean_keeps_lists_and_replaces_brackets(): void {
		$this->assertSame(
			array( '@type' => 'A', 'k' => array( "\u{2039}b\u{203A}", 2 ) ),
			SchemaNode::clean( array( '@type' => 'A', 'k' => array( '<b>', 2 ) ) )
		);
		$this->assertNull( SchemaNode::clean( array( 'bad key' => 'v' ) ) );
		$this->assertSame( array(), SchemaNode::clean( array() ) );
	}

	public function test_todo_detection_is_recursive(): void {
		$this->assertTrue( SchemaNode::is_todo( 'TODO: logo' ) );
		$this->assertFalse( SchemaNode::is_todo( 'Logo' ) );
		$this->assertTrue( SchemaNode::has_todo( array( 'a' => array( 'b' => 'TODO: x' ) ) ) );
		$this->assertFalse( SchemaNode::has_todo( array( 'a' => array( 'b' => 'x' ) ) ) );
	}

	public function test_size_cap(): void {
		$this->assertTrue( SchemaNode::fits( array( 'a' => str_repeat( 'x', 32000 ) ) ) );
		$this->assertFalse( SchemaNode::fits( array( 'a' => str_repeat( 'x', 32768 ) ) ) );
	}

	public function test_dates_need_an_offset(): void {
		$this->assertTrue( SchemaNode::valid_date( '2026-10-01T10:00:00+00:00' ) );
		$this->assertFalse( SchemaNode::valid_date( '2026-10-01T10:00:00Z' ) );
		$this->assertFalse( SchemaNode::valid_date( '2026-02-30T10:00:00+00:00' ) );
	}
}
