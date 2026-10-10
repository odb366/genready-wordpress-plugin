<?php
/**
 * Checks of one JSON-LD node.
 *
 * @package GenReady
 */

namespace GenReady\Fixes;

use GenReady\Support\Text;

defined( 'ABSPATH' ) || exit;

/**
 * Pure checks of a JSON-LD node from GenReady and of the values the preview
 * form fills in: allowed keys, no angle brackets, a size cap, TODO values,
 * and the form value rules.
 */
final class SchemaNode {
	/** Longest title or author name the server accepts, in UTF-16 code units. */
	const MAX_TEXT = 300;
	/** Longest description the form accepts. */
	const MAX_DESCRIPTION = 500;
	/** Prefix of a value the server could not fill. */
	const TODO = 'TODO: ';
	/** The only keys a node may have, at every depth. List indexes are not keys. */
	const KEY_PATTERN = '/^@?[A-Za-z][A-Za-z0-9_:-]{0,63}$/D';
	/** Largest node, in bytes of wp_json_encode() output. */
	const MAX_NODE_BYTES = 32768;

	/**
	 * Check every key of a node and make every string safe inside a script tag.
	 * Keys must match KEY_PATTERN at every depth; a list keeps its indexes.
	 * In every string, '<' becomes '‹' (U+2039) and '>' becomes '›' (U+203A).
	 *
	 * @param array $value A node, an object inside it, or a list.
	 * @return array|null The clean value, or null when a key does not match.
	 */
	public static function clean( array $value ): ?array {
		$is_list = array_keys( $value ) === range( 0, count( $value ) - 1 );
		$clean   = array();
		foreach ( $value as $key => $child ) {
			if ( ! $is_list && ( ! is_string( $key ) || 1 !== preg_match( self::KEY_PATTERN, $key ) ) ) {
				return null;
			}
			if ( is_array( $child ) ) {
				$child = self::clean( $child );
				if ( null === $child ) {
					return null;
				}
			} elseif ( is_string( $child ) ) {
				$child = self::angle_free( $child );
			}
			$clean[ $key ] = $child;
		}
		return $clean;
	}

	/**
	 * Replace '<' with '‹' (U+2039) and '>' with '›' (U+203A).
	 *
	 * @param string $text Text.
	 */
	public static function angle_free( string $text ): string {
		return str_replace( array( '<', '>' ), array( "\u{2039}", "\u{203A}" ), $text );
	}

	/**
	 * Tell whether a node is at most MAX_NODE_BYTES as JSON.
	 *
	 * @param mixed $node Node.
	 */
	public static function fits( $node ): bool {
		$json = wp_json_encode( $node );
		return is_string( $json ) && strlen( $json ) <= self::MAX_NODE_BYTES;
	}

	/**
	 * Tell whether a value is a TODO string.
	 *
	 * @param mixed $value Value.
	 */
	public static function is_todo( $value ): bool {
		return is_string( $value ) && 0 === strpos( $value, self::TODO );
	}

	/**
	 * Tell whether a value is, or holds at any depth, a TODO string.
	 *
	 * @param mixed $value Value.
	 */
	public static function has_todo( $value ): bool {
		if ( is_array( $value ) ) {
			foreach ( $value as $child ) {
				if ( self::has_todo( $child ) ) {
					return true;
				}
			}
			return false;
		}
		return self::is_todo( $value );
	}

	/**
	 * Clean one form value.
	 *
	 * @param string $rule  'text', 'textarea', 'date' or 'url'.
	 * @param string $value Raw value.
	 * @return string|null The clean value ('' when empty), or null when it is not valid.
	 */
	public static function form_value( string $rule, string $value ): ?string {
		if ( 'textarea' === $rule ) {
			return Text::cap( sanitize_textarea_field( $value ), self::MAX_DESCRIPTION );
		}
		if ( 'text' === $rule ) {
			return Text::cap( sanitize_text_field( $value ), self::MAX_TEXT );
		}
		$value = trim( $value );
		if ( '' === $value ) {
			return '';
		}
		if ( 'date' === $rule ) {
			return self::valid_date( $value ) ? $value : null;
		}
		$url = esc_url_raw( $value, array( 'http', 'https' ) );
		return '' !== $url && 1 === preg_match( '#^https?://#i', $url ) && false !== wp_http_validate_url( $url ) ? $url : null;
	}

	/**
	 * The error text for a value that is not valid.
	 *
	 * @param string $rule 'date' or 'url'.
	 * @param string $prop Property name.
	 */
	public static function bad_value( string $rule, string $prop ): string {
		if ( 'date' === $rule ) {
			return sprintf(
				/* translators: %s: schema.org property, for example datePublished. */
				__( 'Enter %s as a date and time with a time zone, like 2026-10-01T10:00:00+00:00.', 'genready' ),
				$prop
			);
		}
		return __( 'Enter the logo as a full web address that starts with https:// or http://.', 'genready' );
	}

	/**
	 * Tell whether a date is ISO 8601 with an offset, in the exact form the
	 * server accepts (for example 2026-10-01T10:00:00+00:00).
	 *
	 * @param string $value Date.
	 */
	public static function valid_date( string $value ): bool {
		if ( 1 !== preg_match( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/', $value ) ) {
			return false;
		}
		$date = \DateTime::createFromFormat( DATE_ATOM, $value );
		return false !== $date && $date->format( DATE_ATOM ) === $value;
	}
}
