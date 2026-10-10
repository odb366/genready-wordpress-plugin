<?php
/**
 * OAuth connection to GenReady (authorization code with PKCE).
 *
 * @package GenReady
 */

namespace GenReady\Connection;

use GenReady\Api\ApiError;
use GenReady\Api\Client;

defined( 'ABSPATH' ) || exit;

/**
 * Builds the authorize URL, finishes the callback, refreshes and revokes the
 * tokens. Each site is its own OAuth client: the client_id is a URL on the
 * site that serves the client document.
 */
final class OAuth {
	/** Software id sent in the client document. */
	const SOFTWARE_ID = 'genready-wordpress';
	/** Longest token we accept, in bytes. */
	const MAX_TOKEN_LENGTH = 4096;
	/** Longest error text from the query that we show, in characters. */
	const MAX_ERROR_LENGTH = 200;

	/**
	 * Token storage.
	 *
	 * @var TokenStore
	 */
	private $store;
	/**
	 * API client.
	 *
	 * @var Client
	 */
	private $client;

	/**
	 * Build the connector.
	 *
	 * @param TokenStore $store  Token storage.
	 * @param Client     $client API client.
	 */
	public function __construct( TokenStore $store, Client $client ) {
		$this->store  = $store;
		$this->client = $client;
	}

	/**
	 * The OAuth client_id: the URL of the client document.
	 */
	public function client_id(): string {
		return rest_url( 'genready/v1/client' );
	}

	/**
	 * The only redirect URI of this client.
	 */
	public function redirect_uri(): string {
		return admin_url( 'admin.php?page=genready&genready_cb=1' );
	}

	/**
	 * The client document GenReady fetches from client_id.
	 */
	public function client_document(): array {
		return array(
			'client_id'                  => $this->client_id(),
			'client_name'                => 'GenReady for WordPress',
			'client_uri'                 => home_url(),
			'redirect_uris'              => array( $this->redirect_uri() ),
			'grant_types'                => array( 'authorization_code', 'refresh_token' ),
			'response_types'             => array( 'code' ),
			'token_endpoint_auth_method' => 'none',
			'software_id'                => self::SOFTWARE_ID,
			'software_version'           => GENREADY_VERSION,
		);
	}

	/**
	 * Build the URL that sends the admin to GenReady, and remember the PKCE
	 * verifier and the state for this user.
	 *
	 * @param int  $user_id    Current user.
	 * @param bool $send_email True to pre-fill the sign-in email.
	 */
	public function authorize_url( int $user_id, bool $send_email ): string {
		// phpcs:disable WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- PKCE base64url, RFC 7636.
		$verifier  = rtrim( strtr( base64_encode( random_bytes( 32 ) ), '+/', '-_' ), '=' );
		$challenge = rtrim( strtr( base64_encode( hash( 'sha256', $verifier, true ) ), '+/', '-_' ), '=' );
		// phpcs:enable
		$state = wp_generate_password( 32, false, false );
		set_transient(
			'genready_oauth_' . $user_id,
			array(
				'state'    => $state,
				'verifier' => $verifier,
			),
			600
		);
		$args = array(
			'response_type'         => 'code',
			'client_id'             => $this->client_id(),
			'redirect_uri'          => $this->redirect_uri(),
			'code_challenge'        => $challenge,
			'code_challenge_method' => 'S256',
			'state'                 => $state,
		);
		if ( $send_email ) {
			$user = get_userdata( $user_id );
			if ( $user && is_email( $user->user_email ) && strlen( $user->user_email ) <= 320 ) {
				$args['login_hint'] = $user->user_email;
			}
		}
		return GENREADY_API_BASE . '/oauth/authorize?' . http_build_query( $args, '', '&', PHP_QUERY_RFC3986 );
	}

	/**
	 * Finish the connection after GenReady sends the admin back.
	 *
	 * @param int   $user_id Current user.
	 * @param array $query   The state, code, error and error_description values.
	 * @return array array( 'ok' => true ) with an optional 'notice', or array( 'error' => string ).
	 */
	public function handle_callback( int $user_id, array $query ): array {
		$pending = get_transient( 'genready_oauth_' . $user_id );
		delete_transient( 'genready_oauth_' . $user_id );
		$state = isset( $query['state'] ) && is_string( $query['state'] ) ? sanitize_text_field( $query['state'] ) : '';
		if ( ! is_array( $pending ) || ! is_string( $pending['state'] ?? null ) || ! hash_equals( $pending['state'], $state ) ) {
			return array( 'error' => __( 'The connection request expired. Click Connect again.', 'genready' ) );
		}
		if ( isset( $query['error'] ) ) {
			$text = $query['error_description'] ?? $query['error'];
			$text = is_string( $text ) ? substr( sanitize_text_field( $text ), 0, self::MAX_ERROR_LENGTH ) : '';
			return array( 'error' => '' !== $text ? $text : __( 'GenReady did not finish the connection. Click Connect again.', 'genready' ) );
		}
		$code = isset( $query['code'] ) && is_string( $query['code'] ) ? sanitize_text_field( $query['code'] ) : '';
		if ( '' === $code ) {
			return array( 'error' => __( 'GenReady did not finish the connection. Click Connect again.', 'genready' ) );
		}
		$tokens = $this->client->post_form(
			'/oauth/token',
			array(
				'grant_type'    => 'authorization_code',
				'code'          => $code,
				'code_verifier' => (string) ( $pending['verifier'] ?? '' ),
				'client_id'     => $this->client_id(),
				'redirect_uri'  => $this->redirect_uri(),
			),
			30
		);
		if ( ! is_array( $tokens )
			|| ! $this->is_token( $tokens['access_token'] ?? null )
			|| ! $this->is_token( $tokens['refresh_token'] ?? null )
			|| ! is_string( $tokens['domain_id'] ?? null ) || '' === $tokens['domain_id'] ) {
			return array( 'error' => __( 'GenReady did not finish the connection. Click Connect again.', 'genready' ) );
		}
		$connection = array(
			'method'         => 'oauth',
			'access_token'   => $tokens['access_token'],
			'refresh_token'  => $tokens['refresh_token'],
			'access_expires' => time() + $this->lifetime( $tokens ),
			'client_id'      => $this->client_id(),
			'domain_id'      => sanitize_text_field( $tokens['domain_id'] ),
		);
		$this->store->save( $connection );

		// The token response has no verification token. POST /api/v1/domains returns it, also for an existing site.
		$domain = $this->client->request( 'POST', '/api/v1/domains', array( 'hostname' => Site::hostname() ) );
		if ( $domain instanceof ApiError ) {
			return array(
				'ok'     => true,
				'notice' => $domain->user_message(),
			);
		}
		$info = is_array( $domain['domain'] ?? null ) ? $domain['domain'] : array();
		if ( ( $info['id'] ?? null ) === $connection['domain_id'] && is_string( $info['verificationToken'] ?? null ) && '' !== $info['verificationToken'] ) {
			$connection['verification_token'] = sanitize_text_field( $info['verificationToken'] );
			$this->store->save( $connection );
		}
		return array( 'ok' => true );
	}

	/**
	 * Get a new access token with the refresh token. Only the request that
	 * holds the lock may do this, because a refresh token is single use.
	 *
	 * @return bool True when a usable token is stored afterwards.
	 */
	public function refresh(): bool {
		$before = $this->store->load();
		if ( ! $this->store->acquire_lock() ) {
			$after = $this->store->load();
			return is_array( $after ) && is_array( $before ) && ( $after['access_token'] ?? '' ) !== ( $before['access_token'] ?? '' );
		}
		try {
			$current = $this->store->load();
			if ( ! is_array( $current ) || empty( $current['refresh_token'] ) ) {
				return false;
			}
			if ( is_array( $before ) && ( $current['access_token'] ?? '' ) !== ( $before['access_token'] ?? '' ) ) {
				return true; // Another request refreshed while we waited.
			}
			$tokens = $this->client->post_form(
				'/oauth/token',
				array(
					'grant_type'    => 'refresh_token',
					'refresh_token' => $current['refresh_token'],
					'client_id'     => $current['client_id'] ?? $this->client_id(),
				),
				15
			);
			if ( $tokens instanceof ApiError ) {
				if ( 'invalid_grant' === $tokens->code ) {
					// The grant is gone. The admin must reconnect.
					$this->store->clear();
				}
				return false;
			}
			if ( ! is_array( $tokens ) || ! $this->is_token( $tokens['access_token'] ?? null ) ) {
				return false;
			}
			$current['access_token']   = $tokens['access_token'];
			$current['refresh_token']  = $this->is_token( $tokens['refresh_token'] ?? null ) ? $tokens['refresh_token'] : $current['refresh_token'];
			$current['access_expires'] = time() + $this->lifetime( $tokens );
			$this->store->save( $current );
			return true;
		} finally {
			$this->store->release_lock();
		}
	}

	/**
	 * End the connection. An OAuth grant is revoked at GenReady first. The
	 * local tokens go away even when that call fails.
	 */
	public function revoke(): void {
		$current = $this->store->load();
		if ( is_array( $current ) && 'oauth' === ( $current['method'] ?? '' ) ) {
			$token = ! empty( $current['refresh_token'] ) ? $current['refresh_token'] : ( $current['access_token'] ?? '' );
			if ( '' !== $token ) {
				$this->client->post_form(
					'/oauth/revoke',
					array(
						'token'     => $token,
						'client_id' => $current['client_id'] ?? $this->client_id(),
					),
					10
				);
			}
		}
		$this->store->clear();
	}

	/**
	 * True for a non-empty opaque token of a sane length.
	 *
	 * @param mixed $value Value from a token response.
	 */
	private function is_token( $value ): bool {
		return is_string( $value ) && '' !== $value && strlen( $value ) <= self::MAX_TOKEN_LENGTH;
	}

	/**
	 * Access token lifetime in seconds, between 60 and 86400.
	 *
	 * @param array $tokens Token response.
	 */
	private function lifetime( array $tokens ): int {
		return max( 60, min( (int) ( $tokens['expires_in'] ?? 3600 ), 86400 ) );
	}
}
