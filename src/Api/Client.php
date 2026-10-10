<?php
/**
 * HTTP client for the GenReady API.
 *
 * @package GenReady
 */

namespace GenReady\Api;

use GenReady\Connection\TokenStore;

defined( 'ABSPATH' ) || exit;

/**
 * Sends authenticated requests, refreshes an OAuth token once on a 401, and
 * returns either the `data` of the response or an ApiError.
 */
class Client {
	/** Longest timeout, in seconds, for a form post under the refresh lock. */
	const FORM_MAX_TIMEOUT = 20;

	/**
	 * Token storage.
	 *
	 * @var TokenStore
	 */
	private $store;
	/**
	 * Callable that refreshes the OAuth token and returns true on success.
	 *
	 * @var callable|null
	 */
	private $refresh;

	/**
	 * Build a client.
	 *
	 * @param TokenStore    $store   Token storage.
	 * @param callable|null $refresh Refresh callable.
	 */
	public function __construct( TokenStore $store, ?callable $refresh = null ) {
		$this->store   = $store;
		$this->refresh = $refresh;
	}

	/**
	 * Call the API with the stored credentials.
	 *
	 * @param string     $method  HTTP method.
	 * @param string     $path    Path that starts with a slash.
	 * @param array|null $body    JSON body.
	 * @param int        $timeout Timeout in seconds.
	 * @return array|ApiError The `data` of the response, or an error.
	 */
	public function request( string $method, string $path, ?array $body = null, int $timeout = 15 ) {
		$result = $this->send( $method, $path, $body, $timeout );
		if ( $result instanceof ApiError && 401 === $result->status && $this->can_refresh() ) {
			if ( call_user_func( $this->refresh ) ) {
				$result = $this->send( $method, $path, $body, $timeout );
			}
			if ( $result instanceof ApiError && 401 === $result->status ) {
				return new ApiError( 401, 'RECONNECT', '' );
			}
		}
		return $result;
	}

	/**
	 * Post a form without credentials, for the OAuth endpoints.
	 *
	 * @param string $path    Path that starts with a slash.
	 * @param array  $form    Form fields.
	 * @param int    $timeout Timeout in seconds. At most 20.
	 * @return array|ApiError Decoded JSON, without an envelope, or an error.
	 */
	public function post_form( string $path, array $form, int $timeout = 15 ) {
		$args     = array(
			'method'      => 'POST',
			'timeout'     => min( $timeout, self::FORM_MAX_TIMEOUT ),
			'redirection' => 0,
			'sslverify'   => true,
			'headers'     => array(
				'Content-Type' => 'application/x-www-form-urlencoded',
				'Accept'       => 'application/json',
				'User-Agent'   => $this->user_agent(),
			),
			'body'        => http_build_query( $form ),
		);
		$response = wp_remote_request( GENREADY_API_BASE . $path, $args );
		if ( is_wp_error( $response ) ) {
			return new ApiError( null, 'TIMEOUT', $response->get_error_message() );
		}
		$status  = (int) wp_remote_retrieve_response_code( $response );
		$decoded = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( $status >= 200 && $status < 300 && is_array( $decoded ) ) {
			return $decoded;
		}
		$error       = is_array( $decoded ) ? ( $decoded['error'] ?? null ) : null;
		$description = is_array( $decoded ) ? ( $decoded['error_description'] ?? null ) : null;
		return new ApiError(
			$status,
			is_string( $error ) && '' !== $error ? $error : 'HTTP_' . $status,
			is_string( $description ) ? $description : ''
		);
	}

	/**
	 * True when a 401 can be fixed by a refresh.
	 */
	private function can_refresh(): bool {
		$connection = $this->store->load();
		return null !== $this->refresh && is_array( $connection ) && 'oauth' === ( $connection['method'] ?? '' );
	}

	/**
	 * Send one authenticated request.
	 *
	 * @param string     $method  HTTP method.
	 * @param string     $path    Path.
	 * @param array|null $body    JSON body.
	 * @param int        $timeout Timeout in seconds.
	 * @return array|ApiError
	 */
	private function send( string $method, string $path, ?array $body, int $timeout ) {
		$connection = $this->store->load();
		if ( null === $connection ) {
			return new ApiError( 401, 'RECONNECT', '' );
		}
		$secret = 'oauth' === ( $connection['method'] ?? '' ) ? ( $connection['access_token'] ?? '' ) : ( $connection['api_key'] ?? '' );
		$args   = array(
			'method'      => $method,
			'timeout'     => $timeout,
			'redirection' => 0,
			'sslverify'   => true,
			'headers'     => array(
				'Authorization' => 'Bearer ' . $secret,
				'Content-Type'  => 'application/json',
				'Accept'        => 'application/json',
				'User-Agent'    => $this->user_agent(),
			),
		);
		if ( null !== $body ) {
			$args['body'] = wp_json_encode( $body );
		}
		$response = wp_remote_request( GENREADY_API_BASE . $path, $args );
		if ( is_wp_error( $response ) ) {
			return new ApiError( null, 'TIMEOUT', $response->get_error_message() );
		}
		$status  = (int) wp_remote_retrieve_response_code( $response );
		$decoded = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( $status >= 200 && $status < 300 ) {
			if ( ! is_array( $decoded ) ) {
				return new ApiError( $status, 'BAD_RESPONSE', '' );
			}
			return is_array( $decoded['data'] ?? null ) ? $decoded['data'] : array();
		}
		$error   = is_array( $decoded ) && is_array( $decoded['error'] ?? null ) ? $decoded['error'] : array();
		$details = is_array( $error['details'] ?? null ) ? $error['details'] : array();
		if ( 429 === $status && ! isset( $details['retryAfterSeconds'] ) ) {
			$header = wp_remote_retrieve_header( $response, 'retry-after' );
			if ( is_string( $header ) && ctype_digit( $header ) && (int) $header > 0 ) {
				$details['retryAfterSeconds'] = (int) $header;
			}
		}
		return new ApiError(
			$status,
			is_string( $error['code'] ?? null ) && '' !== $error['code'] ? $error['code'] : 'HTTP_' . $status,
			is_string( $error['message'] ?? null ) ? $error['message'] : '',
			$details
		);
	}

	/**
	 * User-Agent header value.
	 */
	private function user_agent(): string {
		return 'GenReady-WordPress/' . GENREADY_VERSION . '; WordPress/' . get_bloginfo( 'version' );
	}
}
