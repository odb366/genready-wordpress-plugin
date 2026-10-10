<?php
/**
 * Connection with a one-site GenReady API key.
 *
 * @package GenReady
 */

namespace GenReady\Connection;

use GenReady\Api\ApiError;
use GenReady\Api\Client;

defined( 'ABSPATH' ) || exit;

/**
 * The fallback for sites where OAuth cannot work. The key is managed in the
 * GenReady dashboard, so nothing here revokes it.
 */
final class ApiKey {
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
	 * Check a key against GenReady and store it.
	 *
	 * @param string $key      API key typed by the admin.
	 * @param string $hostname Site hostname. Empty means this site.
	 * @return array array( 'ok' => true ) or array( 'error' => string ).
	 */
	public function connect( string $key, string $hostname = '' ): array {
		$key = trim( $key );
		if ( 1 !== preg_match( '/^gr_live_[A-Za-z0-9_-]{16,128}$/', $key ) ) {
			return array( 'error' => __( 'That is not a GenReady API key. Keys start with gr_live_.', 'genready' ) );
		}
		if ( null !== $this->store->load() ) {
			return array( 'error' => __( 'Disconnect GenReady first, then add the API key.', 'genready' ) );
		}
		if ( '' === $hostname ) {
			$hostname = Site::hostname();
		}
		$this->store->save(
			array(
				'method'  => 'api_key',
				'api_key' => $key,
			)
		);
		$result = $this->client->request( 'POST', '/api/v1/domains', array( 'hostname' => $hostname ) );
		if ( $result instanceof ApiError ) {
			$this->store->clear();
			return array( 'error' => $result->user_message() );
		}
		$domain = is_array( $result['domain'] ?? null ) ? $result['domain'] : array();
		if ( ! is_string( $domain['id'] ?? null ) || '' === $domain['id'] ) {
			$this->store->clear();
			return array( 'error' => __( 'GenReady could not do this. Try again.', 'genready' ) );
		}
		$connection = array(
			'method'    => 'api_key',
			'api_key'   => $key,
			'domain_id' => sanitize_text_field( $domain['id'] ),
		);
		if ( is_string( $domain['verificationToken'] ?? null ) && '' !== $domain['verificationToken'] ) {
			$connection['verification_token'] = sanitize_text_field( $domain['verificationToken'] );
		}
		$this->store->save( $connection );
		return array( 'ok' => true );
	}
}
