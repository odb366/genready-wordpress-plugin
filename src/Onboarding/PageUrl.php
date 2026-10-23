<?php
/**
 * Addresses of pages on this site.
 *
 * @package GenReady
 */

namespace GenReady\Onboarding;

defined( 'ABSPATH' ) || exit;

/**
 * The local checks of a page address (spec 4.1), the home page test (spec
 * 4.3) and the list of the URL field. It uses local data only and makes no
 * network call.
 */
final class PageUrl {
	/** Longest page address, in characters. */
	const MAX_LENGTH = 2048;
	/** Most entries in the list of the URL field. */
	const SUGGESTIONS = 50;

	/**
	 * Tell whether an address is an http or https page on this site's host and port.
	 * A user part or a backslash is refused: PHP and the WHATWG URL parser of
	 * the server read such an address as different hosts.
	 *
	 * @param string $url Page address.
	 */
	public static function is_on_site( string $url ): bool {
		if ( '' === $url || strlen( $url ) > self::MAX_LENGTH || false !== strpos( $url, '\\' ) ) {
			return false;
		}
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || ! isset( $parts['scheme'], $parts['host'] ) || isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			return false;
		}
		if ( ! in_array( strtolower( $parts['scheme'] ), array( 'http', 'https' ), true ) ) {
			return false;
		}
		$home = wp_parse_url( home_url() );
		return is_array( $home ) && is_string( $home['host'] ?? null ) && self::host( $parts['host'] ) === self::host( $home['host'] )
			&& ( $parts['port'] ?? null ) === ( $home['port'] ?? null );
	}

	/**
	 * Tell whether an address is the home page.
	 *
	 * @param string $url Page address, for example the URL of a report.
	 */
	public static function is_home( string $url ): bool {
		$page = self::bare( $url );
		return '' !== $page && self::bare( home_url( '/' ) ) === $page;
	}

	/**
	 * The name of a page in the recent list. Plain text: callers escape it.
	 *
	 * @param string $url Page address.
	 */
	public static function label( string $url ): string {
		if ( self::is_home( $url ) ) {
			return __( 'Home page', 'genready' );
		}
		$parts = wp_parse_url( $url );
		$path  = is_array( $parts ) && isset( $parts['path'] ) && '' !== $parts['path'] ? $parts['path'] : '/';
		$query = is_array( $parts ) && isset( $parts['query'] ) && '' !== $parts['query'] ? '?' . $parts['query'] : '';
		return $path . $query;
	}

	/**
	 * Up to 50 public pages and posts for the datalist, newest change first.
	 * The list comes from the database. It makes no network call.
	 *
	 * @return string[]
	 */
	public static function suggestions(): array {
		$ids  = get_posts(
			array(
				'post_type'     => array( 'page', 'post' ),
				'post_status'   => 'publish',
				'has_password'  => false,
				'orderby'       => 'modified',
				'order'         => 'DESC',
				'numberposts'   => self::SUGGESTIONS,
				'fields'        => 'ids',
				'no_found_rows' => true,
			)
		);
		$urls = array();
		foreach ( is_array( $ids ) ? $ids : array() as $id ) {
			$url = get_permalink( $id );
			if ( is_string( $url ) && self::is_on_site( $url ) && ! in_array( $url, $urls, true ) ) {
				$urls[] = $url;
			}
		}
		return $urls;
	}

	/**
	 * A host in lower case without a leading www., as the server's normalizeHostname().
	 *
	 * @param string $host Host name.
	 */
	private static function host( string $host ): string {
		$host = strtolower( $host );
		return 0 === strpos( $host, 'www.' ) ? substr( $host, 4 ) : $host;
	}

	/**
	 * The address without the scheme, a leading www., the fragment, a trailing
	 * slash and the query. With plain permalinks the query names the page, so
	 * it stays.
	 *
	 * @param string $url Address.
	 */
	private static function bare( string $url ): string {
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || ! isset( $parts['host'] ) ) {
			return '';
		}
		$port  = isset( $parts['port'] ) ? ':' . $parts['port'] : '';
		$path  = rtrim( (string) ( $parts['path'] ?? '' ), '/' );
		$query = '' === (string) get_option( 'permalink_structure' ) && isset( $parts['query'] ) ? '?' . $parts['query'] : '';
		return self::host( $parts['host'] ) . $port . $path . $query;
	}
}
