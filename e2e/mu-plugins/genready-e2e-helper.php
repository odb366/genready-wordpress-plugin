<?php
/**
 * Plugin Name: GenReady e2e helper
 * Description: Test-only helper for the Playground end-to-end specs. Never shipped (.distignore excludes e2e/).
 *
 * Routes (namespace genready-e2e/v1, manage_options only, cookie + X-WP-Nonce):
 *   POST /option       { name, value?, delete?, merge? }  set, merge (arrays) or delete one option
 *   GET  /option       ?name=                      one option value (never genready_connection)
 *   GET  /options      ?prefix=genready            option names that start with the prefix
 *   GET  /file         ?name=robots.txt|llms.txt   file in the site root (content or null)
 *   POST /file         { name, content }           write a file in the site root
 *   GET  /http-log                                 outbound HTTP requests seen since the last reset
 *   POST /http-log/reset
 *   POST /lock-probe                               take, re-take and release the refresh lock (SQLite proof)
 *   POST /oauth-refresh                            run the plugin's OAuth refresh once
 *
 * It also logs every outbound HTTP request (URL and whether the request was a front-end page view)
 * to the option e2e_http_log, so the no-frontend-tracking spec can prove the plugin made no call.
 *
 * @package GenReady
 */

defined( 'ABSPATH' ) || exit;

const GENREADY_E2E_FILES = array( 'robots.txt', 'llms.txt' );

add_filter(
	'pre_http_request',
	static function ( $pre, $args, $url ) {
		$front = ! is_admin() && ! wp_doing_ajax() && ! wp_doing_cron() && ! ( defined( 'REST_REQUEST' ) && REST_REQUEST );
		$log   = get_option( 'e2e_http_log', array() );
		$log   = is_array( $log ) ? $log : array();
		$log[] = array(
			'url'   => (string) $url,
			'front' => $front,
			'page'  => isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '',
		);
		update_option( 'e2e_http_log', array_slice( $log, -200 ), false );
		return $pre;
	},
	1,
	3
);

add_action(
	'rest_api_init',
	static function () {
		$admin = static function () {
			return current_user_can( 'manage_options' );
		};
		register_rest_route(
			'genready-e2e/v1',
			'/option',
			array(
				'methods'             => 'POST',
				'permission_callback' => $admin,
				'callback'            => static function ( WP_REST_Request $request ) {
					$name = (string) $request->get_param( 'name' );
					if ( '' === $name ) {
						return new WP_Error( 'e2e', 'name required', array( 'status' => 400 ) );
					}
					if ( $request->get_param( 'delete' ) ) {
						delete_option( $name );
					} elseif ( $request->get_param( 'merge' ) ) {
						$current = get_option( $name, array() );
						update_option( $name, array_merge( is_array( $current ) ? $current : array(), (array) $request->get_param( 'value' ) ) );
					} else {
						update_option( $name, $request->get_param( 'value' ) );
					}
					return array( 'ok' => true );
				},
			)
		);
		register_rest_route(
			'genready-e2e/v1',
			'/option',
			array(
				'methods'             => 'GET',
				'permission_callback' => $admin,
				'callback'            => static function ( WP_REST_Request $request ) {
					$name = (string) $request->get_param( 'name' );
					// Never hand out the connection: it holds the encrypted tokens.
					if ( '' === $name || 'genready_connection' === $name ) {
						return new WP_Error( 'e2e', 'not allowed', array( 'status' => 400 ) );
					}
					wp_cache_delete( $name, 'options' );
					return array( 'value' => get_option( $name, null ) );
				},
			)
		);
		register_rest_route(
			'genready-e2e/v1',
			'/options',
			array(
				'methods'             => 'GET',
				'permission_callback' => $admin,
				'callback'            => static function ( WP_REST_Request $request ) {
					global $wpdb;
					$prefix = (string) $request->get_param( 'prefix' );
					$like   = $wpdb->esc_like( $prefix ) . '%';
					wp_cache_flush();
					return array_values( $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s ORDER BY option_name", $like ) ) );
				},
			)
		);
		register_rest_route(
			'genready-e2e/v1',
			'/file',
			array(
				array(
					'methods'             => 'GET',
					'permission_callback' => $admin,
					'callback'            => static function ( WP_REST_Request $request ) {
						$name = (string) $request->get_param( 'name' );
						if ( ! in_array( $name, GENREADY_E2E_FILES, true ) ) {
							return new WP_Error( 'e2e', 'bad name', array( 'status' => 400 ) );
						}
						$path = ABSPATH . $name;
						return array( 'content' => file_exists( $path ) ? file_get_contents( $path ) : null );
					},
				),
				array(
					'methods'             => 'POST',
					'permission_callback' => $admin,
					'callback'            => static function ( WP_REST_Request $request ) {
						$name = (string) $request->get_param( 'name' );
						if ( ! in_array( $name, GENREADY_E2E_FILES, true ) ) {
							return new WP_Error( 'e2e', 'bad name', array( 'status' => 400 ) );
						}
						file_put_contents( ABSPATH . $name, (string) $request->get_param( 'content' ) );
						return array( 'ok' => true );
					},
				),
			)
		);
		register_rest_route(
			'genready-e2e/v1',
			'/http-log',
			array(
				'methods'             => 'GET',
				'permission_callback' => $admin,
				'callback'            => static function () {
					wp_cache_delete( 'e2e_http_log', 'options' );
					$log = get_option( 'e2e_http_log', array() );
					return is_array( $log ) ? $log : array();
				},
			)
		);
		register_rest_route(
			'genready-e2e/v1',
			'/lock-probe',
			array(
				'methods'             => 'POST',
				'permission_callback' => $admin,
				'callback'            => static function () {
					global $wpdb;
					$store  = new \GenReady\Connection\TokenStore();
					$first  = $store->acquire_lock( 0 );
					$second = $store->acquire_lock( 0 );
					$store->release_lock();
					$row_after_release = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name = %s", \GenReady\Connection\TokenStore::LOCK ) );
					$third = $store->acquire_lock( 0 );
					$store->release_lock();
					return array(
						'first'             => $first,
						'second'            => $second,
						'row_after_release' => (int) $row_after_release,
						'third'             => $third,
					);
				},
			)
		);
		register_rest_route(
			'genready-e2e/v1',
			'/oauth-refresh',
			array(
				'methods'             => 'POST',
				'permission_callback' => $admin,
				'callback'            => static function () {
					global $wpdb;
					$refreshed = \GenReady\Plugin::instance()->oauth()->refresh();
					$lock_rows = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name = %s", \GenReady\Connection\TokenStore::LOCK ) );
					return array(
						'refreshed' => $refreshed,
						'lock_rows' => (int) $lock_rows,
					);
				},
			)
		);
		register_rest_route(
			'genready-e2e/v1',
			'/http-log/reset',
			array(
				'methods'             => 'POST',
				'permission_callback' => $admin,
				'callback'            => static function () {
					delete_option( 'e2e_http_log' );
					return array( 'ok' => true );
				},
			)
		);
	}
);
