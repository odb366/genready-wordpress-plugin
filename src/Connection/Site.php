<?php
/**
 * Facts about this WordPress site.
 *
 * @package GenReady
 */

namespace GenReady\Connection;

defined( 'ABSPATH' ) || exit;

/**
 * Site facts shared by both connection paths.
 */
final class Site {
	/**
	 * Lower-case hostname of the site. It matches the host of the OAuth
	 * client_id, because rest_url() is built on home_url().
	 */
	public static function hostname(): string {
		return strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
	}
}
