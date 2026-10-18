<?php
/**
 * Argument callbacks of the onboarding REST routes.
 *
 * @package GenReady
 */

namespace GenReady\Onboarding;

defined( 'ABSPATH' ) || exit;

/**
 * The sanitize and validate callbacks of the url, scan and email arguments
 * of /scan, /report and /account (Admin\Rest registers the routes).
 * WordPress runs validate_callback on the raw value, then sanitize_callback.
 */
final class RestArgs {
	/**
	 * What sanitize_page_url() returns for a value that is not empty but is
	 * not an http or https address (ftp:, javascript:, mailto:). The /scan
	 * route refuses it. An empty value means the home page.
	 */
	const NOT_A_PAGE = 'genready:not-a-page';
	/** Longest email field accepted. AccountCreator checks the address itself. */
	const MAX_EMAIL_FIELD = 320;

	/**
	 * Clean a page address. Only http and https stay.
	 *
	 * @param mixed $value Raw value.
	 */
	public static function sanitize_page_url( $value ): string {
		if ( ! is_string( $value ) ) {
			return '';
		}
		$value = trim( $value );
		$clean = esc_url_raw( $value, array( 'http', 'https' ) );
		return '' === $clean && '' !== $value ? self::NOT_A_PAGE : $clean;
	}

	/**
	 * Check a page address.
	 *
	 * @param mixed $value Raw value.
	 */
	public static function validate_page_url( $value ): bool {
		return is_string( $value ) && strlen( $value ) <= PageUrl::MAX_LENGTH;
	}

	/**
	 * Check a scan ID: a UUID, or empty.
	 *
	 * @param mixed $value Raw value.
	 */
	public static function validate_scan_id( $value ): bool {
		return is_string( $value ) && ( '' === $value || 1 === preg_match( AnonymousScans::UUID, $value ) );
	}

	/**
	 * Check an email field. AccountCreator checks the address itself.
	 *
	 * @param mixed $value Raw value.
	 */
	public static function validate_email_text( $value ): bool {
		return is_string( $value ) && strlen( $value ) <= self::MAX_EMAIL_FIELD;
	}
}
