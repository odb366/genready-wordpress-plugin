<?php
/**
 * The site verification meta tag.
 *
 * @package GenReady
 */

namespace GenReady\Verification;

defined( 'ABSPATH' ) || exit;

/**
 * Prints the tag GenReady looks for on the home page. Only the token is
 * stored, in its own autoloaded option, so the front end never loads the
 * connection.
 */
final class MetaTag {
	/** Option holding the token. */
	const OPTION = 'genready_meta';
	/** Pattern a token must match. */
	const PATTERN = '/^[A-Za-z0-9_-]{8,128}$/';

	/**
	 * Tell whether a value is a usable token.
	 *
	 * @param mixed $token Value to check.
	 */
	public static function is_valid( $token ): bool {
		return is_string( $token ) && 1 === preg_match( self::PATTERN, $token );
	}

	/**
	 * Store the token so the tag is printed. A malformed token is ignored.
	 *
	 * @param string $token Verification token.
	 */
	public function enable( string $token ): void {
		if ( self::is_valid( $token ) ) {
			update_option( self::OPTION, $token, true );
		}
	}

	/**
	 * Stop printing the tag.
	 */
	public function disable(): void {
		delete_option( self::OPTION );
	}

	/**
	 * Print the tag on the front page. Hooked on wp_head. It never throws.
	 */
	public function render(): void {
		try {
			if ( ! is_front_page() ) {
				return;
			}
			$token = get_option( self::OPTION );
			if ( ! self::is_valid( $token ) ) {
				return;
			}
			echo '<meta name="genready-site-verification" content="' . esc_attr( $token ) . '">' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the only variable part is escaped.
		} catch ( \Throwable $e ) {
			return;
		}
	}
}
