<?php
/**
 * The one-time proof of site control for "Create my free account".
 *
 * @package GenReady
 */

namespace GenReady\Onboarding;

defined( 'ABSPATH' ) || exit;

/**
 * The plugin makes a random ID and a random secret, sends only the ID and the
 * hash of the secret to GenReady, and GenReady reads the secret back from
 * the site's REST API (spec 7). The route gives the secret once, within 2
 * minutes, and then answers like a missing route.
 */
final class Proof {
	/** Option with the pending proof. Autoload off. */
	const OPTION = 'genready_proof';
	/** Seconds a proof stays valid. */
	const TTL = 120;
	/** Pattern of a proof ID. \z: a trailing newline does not match. */
	const ID = '/^[a-f0-9]{32}\z/';

	/**
	 * Register the proof route. Hooked on rest_api_init.
	 */
	public function register(): void {
		register_rest_route(
			'genready/v1',
			'/proof/(?P<id>[a-f0-9]{32})',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'serve' ),
				// Public on purpose: GenReady calls it with no credentials, and it gives only a code that the plugin made in the last 2 minutes, once.
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Make a new proof. It replaces an older one.
	 *
	 * @return array array( 'id' => 32 hex characters, 'hash' => SHA-256 hex of the secret ).
	 */
	public function create(): array {
		$id     = bin2hex( random_bytes( 16 ) );
		$secret = bin2hex( random_bytes( 32 ) );
		update_option(
			self::OPTION,
			array(
				'id'      => $id,
				'secret'  => $secret,
				'created' => time(),
			),
			false
		);
		return array(
			'id'   => $id,
			'hash' => hash( 'sha256', $secret ),
		);
	}

	/**
	 * Delete the proof with this ID. A newer proof of another request stays.
	 *
	 * @param string $id Proof ID from create().
	 */
	public function discard( string $id ): void {
		// get_option() would return this request's cached copy, which still holds
		// our own proof after another request replaced the row (Review Focus 4).
		wp_cache_delete( self::OPTION, 'options' );
		$stored = get_option( self::OPTION );
		if ( is_array( $stored ) && is_string( $stored['id'] ?? null ) && hash_equals( $stored['id'], $id ) ) {
			delete_option( self::OPTION );
		}
	}

	/**
	 * Give the secret once. Anything else answers like a missing route.
	 *
	 * @param \WP_REST_Request $request Request with the id from the route.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function serve( $request ) {
		if ( 'GET' !== $request->get_method() ) {
			// WordPress sends HEAD to this handler too. Only a GET may use up the proof.
			return self::missing();
		}
		// The route segment, not get_param(): a query value such as ?id[]=x would win there.
		$params = $request->get_url_params();
		$id     = is_string( $params['id'] ?? null ) ? $params['id'] : '';
		$stored = get_option( self::OPTION );
		if ( 1 !== preg_match( self::ID, $id ) || ! is_array( $stored ) || ! is_string( $stored['id'] ?? null )
			|| ! is_string( $stored['secret'] ?? null ) || ! is_int( $stored['created'] ?? null ) ) {
			return self::missing();
		}
		if ( ! hash_equals( $stored['id'], $id ) ) {
			return self::missing();
		}
		$age = time() - $stored['created'];
		if ( $age > self::TTL ) {
			delete_option( self::OPTION ); // It lives 2 minutes at most.
			return self::missing();
		}
		if ( $age < 0 ) {
			return self::missing();
		}
		// The single-use guard: the database deletes the row for one request only.
		if ( ! delete_option( self::OPTION ) ) {
			return self::missing();
		}
		return new \WP_REST_Response( array( 'secret' => $stored['secret'] ), 200, array( 'Cache-Control' => 'no-store' ) );
	}

	/**
	 * The answer of a missing route, as the client document route gives it.
	 */
	private static function missing(): \WP_Error {
		return new \WP_Error( 'rest_no_route', __( 'No route was found matching the URL and request method.', 'genready' ), array( 'status' => 404 ) );
	}
}
