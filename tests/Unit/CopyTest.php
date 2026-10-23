<?php
namespace GenReady\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * wordpress.org rule 9 and the house style: no UI string says "unlock", and
 * no UI string has an em dash (spec 4, 14.2).
 */
class CopyTest extends TestCase {
	/**
	 * The text of every gettext call in src/ and assets/.
	 *
	 * @return array[] array( file, text ) each.
	 */
	private function ui_strings(): array {
		$root  = dirname( __DIR__, 2 );
		$files = array_merge( glob( $root . '/src/*.php' ), glob( $root . '/src/*/*.php' ), glob( $root . '/src/*/*/*.php' ), glob( $root . '/assets/*.js' ) );
		$found = array();
		foreach ( $files as $file ) {
			preg_match_all( '/\b(?:__|_e|_n|_x|esc_html__|esc_html_e|esc_attr__|esc_attr_e)\(\s*([\'"])((?:\\\\.|(?!\1).)*)\1/s', (string) file_get_contents( $file ), $matches );
			foreach ( $matches[2] as $text ) {
				$found[] = array( basename( $file ), $text );
			}
		}
		return $found;
	}

	public function test_the_check_finds_the_ui_strings(): void {
		$texts = array_column( $this->ui_strings(), 1 );
		$this->assertGreaterThan( 150, count( $texts ) );
		$this->assertContains( 'Check this page', $texts );
		$this->assertContains( 'Create my free account', $texts );
	}

	public function test_no_ui_string_says_unlock_or_has_an_em_dash(): void {
		foreach ( $this->ui_strings() as $item ) {
			list( $file, $text ) = $item;
			$this->assertDoesNotMatchRegularExpression( '/\bunlock/i', $text, $file . ': ' . $text );
			$this->assertStringNotContainsString( "\u{2014}", $text, $file . ': ' . $text );
		}
	}
}
