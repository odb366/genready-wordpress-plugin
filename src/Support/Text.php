<?php
/**
 * Text helpers.
 *
 * @package GenReady
 */

namespace GenReady\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Text helpers. cap() uses only functions WordPress polyfills (mb_substr,
 * mb_strlen) and PCRE with the /u modifier, so it works without mbstring.
 */
final class Text {
	/** A character outside the Basic Multilingual Plane: two UTF-16 code units. */
	const ASTRAL = '/[\x{10000}-\x{10FFFF}]/u';

	/**
	 * Cut a text so its UTF-16 length is at most $max_units, then trim it.
	 * The GenReady server checks lengths in JavaScript, which counts UTF-16
	 * code units: a character above U+FFFF (most emoji) counts as 2. The cut
	 * falls between code points, so no character is split.
	 *
	 * @param string $text      UTF-8 text.
	 * @param int    $max_units Longest UTF-16 length.
	 * @return string The cut text. '' for invalid UTF-8 or a limit below 1.
	 */
	public static function cap( string $text, int $max_units ): string {
		if ( $max_units < 1 || 1 !== preg_match( '//u', $text ) ) {
			return '';
		}
		$text  = mb_substr( trim( $text ), 0, $max_units, 'UTF-8' );
		$units = mb_strlen( $text, 'UTF-8' ) + (int) preg_match_all( self::ASTRAL, $text );
		while ( $units > $max_units ) {
			$last   = mb_substr( $text, -1, 1, 'UTF-8' );
			$text   = mb_substr( $text, 0, -1, 'UTF-8' );
			$units -= 1 === preg_match( self::ASTRAL, $last ) ? 2 : 1;
		}
		return trim( $text );
	}

	/**
	 * Turn a WordPress text into plain text on one line: decode entities, remove
	 * tags, join white space, trim, and cut it to the server's UTF-16 length limit.
	 *
	 * @param string $text Text.
	 * @param int    $max  Longest length in UTF-16 code units.
	 */
	public static function clean( string $text, int $max ): string {
		$text = wp_strip_all_tags( html_entity_decode( $text, ENT_QUOTES, 'UTF-8' ) );
		$text = trim( (string) preg_replace( '/\s+/u', ' ', $text ) );
		return self::cap( $text, $max );
	}

	/**
	 * Keep only the strings of a list, for example a list from the server.
	 *
	 * @param mixed $values Value.
	 * @return string[]
	 */
	public static function strings( $values ): array {
		return is_array( $values ) ? array_values( array_filter( $values, 'is_string' ) ) : array();
	}
}
