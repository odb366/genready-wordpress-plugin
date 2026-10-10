<?php
/**
 * Page cache purging.
 *
 * @package GenReady
 */

namespace GenReady\Compat;

defined( 'ABSPATH' ) || exit;

/**
 * Purges the page caches of the plugins and hosts that are present. Every
 * name below was checked against the vendor's source or documentation.
 * Detection uses the function, class or constant of the cache itself, never
 * has_action(). Run it only from admin actions, never on the front end.
 */
final class Caches {
	/**
	 * Purge every page cache that is present.
	 *
	 * @return string[] Names of the caches purged.
	 */
	public function purge_all(): array {
		return $this->run(
			function ( array $cache ): void {
				call_user_func( $cache['all'] );
			}
		);
	}

	/**
	 * Purge one URL. A cache that cannot purge one URL purges everything.
	 *
	 * @param string $url     Page URL.
	 * @param int    $post_id Post ID, or 0 when the URL is not a post.
	 * @return string[] Names of the caches purged.
	 */
	public function purge_url( string $url, int $post_id = 0 ): array {
		return $this->run(
			function ( array $cache ) use ( $url, $post_id ): void {
				call_user_func( $cache['url'], $url, $post_id );
			}
		);
	}

	/**
	 * Call the purge of each detected cache. One failure stops no other cache.
	 *
	 * @param callable $purge Receives the cache entry.
	 * @return string[]
	 */
	private function run( callable $purge ): array {
		$done = array();
		foreach ( $this->caches() as $name => $cache ) {
			try {
				if ( ! call_user_func( $cache['detect'] ) ) {
					continue;
				}
				$purge( $cache );
				$done[] = $name;
			} catch ( \Throwable $e ) {
				continue;
			}
		}
		return $done;
	}

	/**
	 * Cache definitions: how to detect, purge all, and purge one URL.
	 *
	 * Sources (read 2026-10-10):
	 * WP Rocket: github.com/wp-media/wp-rocket inc/functions/files.php.
	 * LiteSpeed: docs.litespeedtech.com/lscache/lscwp/api/ and litespeed-cache.php (LSCWP_V).
	 * W3 Total Cache: w3-total-cache-api.php. WP Super Cache: wp-cache-phase2.php.
	 * SiteGround Optimizer: helpers/helpers.php. WP Fastest Cache: wpfastestcache.com tutorial.
	 * WP Engine: wpengine.com/support/cache/.
	 *
	 * @return array<string, array{detect: callable, all: callable, url: callable}>
	 */
	private function caches(): array {
		return array(
			'WP Rocket'            => array(
				'detect' => function (): bool {
					return function_exists( 'rocket_clean_domain' ) && function_exists( 'rocket_clean_files' );
				},
				'all'    => function (): void {
					rocket_clean_domain();
				},
				'url'    => function ( string $url, int $post_id ): void {
					if ( $post_id > 0 && function_exists( 'rocket_clean_post' ) ) {
						rocket_clean_post( $post_id );
						return;
					}
					rocket_clean_files( array( $url ) );
				},
			),
			'LiteSpeed Cache'      => array(
				'detect' => function (): bool {
					return defined( 'LSCWP_V' );
				},
				'all'    => function (): void {
					// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- LiteSpeed's own public API hook.
					do_action( 'litespeed_purge_all' );
				},
				'url'    => function ( string $url ): void {
					// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- LiteSpeed's own public API hook.
					do_action( 'litespeed_purge_url', $url );
				},
			),
			'W3 Total Cache'       => array(
				'detect' => function (): bool {
					return function_exists( 'w3tc_flush_all' ) && function_exists( 'w3tc_flush_url' );
				},
				'all'    => function (): void {
					w3tc_flush_all();
				},
				'url'    => function ( string $url ): void {
					w3tc_flush_url( $url );
				},
			),
			'WP Super Cache'       => array(
				'detect' => function (): bool {
					return function_exists( 'wp_cache_clear_cache' ) && function_exists( 'wp_cache_post_change' );
				},
				'all'    => function (): void {
					wp_cache_clear_cache();
				},
				'url'    => function ( string $url, int $post_id ): void {
					if ( $post_id > 0 ) {
						wp_cache_post_change( $post_id );
						return;
					}
					wp_cache_clear_cache();
				},
			),
			'SiteGround Optimizer' => array(
				'detect' => function (): bool {
					return function_exists( 'sg_cachepress_purge_cache' ) && function_exists( 'sg_cachepress_purge_everything' );
				},
				'all'    => function (): void {
					sg_cachepress_purge_everything();
				},
				'url'    => function ( string $url ): void {
					sg_cachepress_purge_cache( $url );
				},
			),
			'WP Fastest Cache'     => array(
				'detect' => function (): bool {
					return function_exists( 'wpfc_clear_all_cache' ) && function_exists( 'wpfc_clear_post_cache_by_id' );
				},
				'all'    => function (): void {
					wpfc_clear_all_cache();
				},
				'url'    => function ( string $url, int $post_id ): void {
					if ( $post_id > 0 ) {
						wpfc_clear_post_cache_by_id( $post_id );
						return;
					}
					wpfc_clear_all_cache();
				},
			),
			'WP Engine'            => array(
				'detect' => function (): bool {
					return class_exists( '\WpeCommon' ) && method_exists( '\WpeCommon', 'purge_varnish_cache' );
				},
				'all'    => function (): void {
					\WpeCommon::purge_varnish_cache();
				},
				'url'    => function ( string $url, int $post_id ): void {
					// With a post ID the call purges that post's URL. Without one it purges the whole domain.
					if ( $post_id > 0 ) {
						\WpeCommon::purge_varnish_cache( $post_id );
						return;
					}
					\WpeCommon::purge_varnish_cache();
				},
			),
		);
	}
}
