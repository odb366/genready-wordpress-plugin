<?php
namespace GenReady\Tests\Unit;

use Brain\Monkey;
use GenReady\Support\Text;
use PHPUnit\Framework\TestCase;

class TextTest extends TestCase {
	/** UTF-16 length, the way JavaScript counts string length. */
	private function units( string $text ): int {
		return intdiv( strlen( (string) iconv( 'UTF-8', 'UTF-16LE', $text ) ), 2 );
	}

	public function test_ascii(): void {
		$this->assertSame( 'abc', Text::cap( 'abcdef', 3 ) );
		$this->assertSame( 'abcdef', Text::cap( 'abcdef', 10 ) );
		$this->assertSame( 'ab', Text::cap( '  ab  ', 10 ) );
		$this->assertSame( 'ab', Text::cap( 'ab cd', 3 ) );
	}

	public function test_accented_letters_count_once(): void {
		$this->assertSame( str_repeat( 'é', 300 ), Text::cap( str_repeat( 'é', 400 ), 300 ) );
	}

	public function test_emoji_count_twice_and_are_never_split(): void {
		$this->assertSame( '😀😀', Text::cap( '😀😀😀', 5 ) );
		$this->assertSame( '😀😀', Text::cap( '😀😀😀', 4 ) );
		$this->assertSame( 'a😀', Text::cap( 'a😀😀', 4 ) );
		$this->assertSame( 'ab', Text::cap( 'ab😀', 3 ) );
		$out = Text::cap( str_repeat( 'n😀', 200 ), 200 );
		$this->assertLessThanOrEqual( 200, $this->units( $out ) );
		$this->assertSame( 199, $this->units( $out ) );
		$this->assertSame( 1, preg_match( '//u', $out ) );
	}

	public function test_max_zero_or_less_gives_empty(): void {
		$this->assertSame( '', Text::cap( 'abc', 0 ) );
		$this->assertSame( '', Text::cap( 'abc', -1 ) );
		$this->assertSame( '', Text::cap( '', 5 ) );
	}

	public function test_invalid_utf8_gives_empty(): void {
		$this->assertSame( '', Text::cap( "ab\xC3\x28cd", 10 ) );
		$this->assertSame( '', Text::cap( "\xF0\x9F\x98", 10 ) );
	}

	public function test_clean_makes_one_line_of_plain_text(): void {
		Monkey\setUp();
		try {
			Monkey\Functions\when( 'wp_strip_all_tags' )->alias( function ( $s ) { return trim( strip_tags( $s ) ); } );
			$this->assertSame( 'Tom & Jerry say hi', Text::clean( "  <b>Tom</b> &amp; Jerry\n\t say   hi ", 100 ) );
			$this->assertSame( 'Tom', Text::clean( 'Tom &amp; Jerry', 3 ) );
		} finally {
			Monkey\tearDown();
		}
	}

	public function test_strings_keeps_only_the_strings_of_a_list(): void {
		$this->assertSame( array( 'a', 'b' ), Text::strings( array( 'a', 1, null, array( 'x' ), 'k' => 'b' ) ) );
		$this->assertSame( array(), Text::strings( 'not a list' ) );
		$this->assertSame( array(), Text::strings( null ) );
	}
}
