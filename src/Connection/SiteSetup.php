<?php
/**
 * Verification and first scan, run from admin actions.
 *
 * @package GenReady
 */

namespace GenReady\Connection;

use GenReady\Api\ApiError;
use GenReady\Api\Client;
use GenReady\Compat\Caches;
use GenReady\Verification\MetaTag;

defined( 'ABSPATH' ) || exit;

/**
 * Steps an admin triggers after connecting: prove site ownership, then scan.
 */
final class SiteSetup {
	/** Option holding the plugin settings. */
	const SETTINGS = 'genready_settings';
	/** Transient holding the cached report summary. */
	const REPORT_CACHE = 'genready_report_cache';

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
	 * Verification meta tag.
	 *
	 * @var MetaTag
	 */
	private $meta;
	/**
	 * Page cache purger.
	 *
	 * @var Caches
	 */
	private $caches;

	/**
	 * Build the service.
	 *
	 * @param TokenStore $store  Token storage.
	 * @param Client     $client API client.
	 * @param MetaTag    $meta   Meta tag.
	 * @param Caches     $caches Page caches.
	 */
	public function __construct( TokenStore $store, Client $client, MetaTag $meta, Caches $caches ) {
		$this->store  = $store;
		$this->client = $client;
		$this->meta   = $meta;
		$this->caches = $caches;
	}

	/**
	 * Show the meta tag, clear page caches for the home page, and ask GenReady
	 * to check the tag.
	 *
	 * @return array array( 'verified' => bool, 'detail' => string ) or array( 'error' => string ).
	 */
	public function verify(): array {
		$connection = $this->store->load();
		$domain_id  = is_array( $connection ) ? ( $connection['domain_id'] ?? '' ) : '';
		if ( ! is_string( $domain_id ) || '' === $domain_id ) {
			return array( 'error' => __( 'Reconnect GenReady.', 'genready' ) );
		}
		$token = $connection['verification_token'] ?? '';
		if ( ! MetaTag::is_valid( $token ) ) {
			$token = $this->fetch_token( $connection );
			if ( is_array( $token ) ) {
				return $token;
			}
		}
		$this->meta->enable( $token );
		$this->caches->purge_url( home_url( '/' ) );
		$result = $this->client->request( 'POST', '/api/v1/domains/' . rawurlencode( $domain_id ) . '/verify', array( 'method' => 'meta' ) );
		if ( $result instanceof ApiError ) {
			return array( 'error' => $result->user_message() );
		}
		return array(
			'verified' => true === ( $result['verified'] ?? false ),
			'detail'   => sanitize_text_field( is_string( $result['detail'] ?? null ) ? $result['detail'] : '' ),
		);
	}

	/**
	 * Start a full scan of the home page and remember the report.
	 *
	 * @return array array( 'report_id' => string, 'estimated_seconds' => int ) or array( 'error' => string ).
	 */
	public function scan(): array {
		$result = $this->client->request(
			'POST',
			'/api/v1/analyze',
			array(
				'url'   => home_url( '/' ),
				'scope' => 'full',
			)
		);
		if ( $result instanceof ApiError ) {
			return array( 'error' => $result->user_message() );
		}
		$report_id = $result['reportId'] ?? null;
		if ( ! is_string( $report_id ) || 1 !== preg_match( '/^[A-Za-z0-9-]{1,64}$/', $report_id ) ) {
			return array( 'error' => __( 'GenReady could not do this. Try again.', 'genready' ) );
		}
		$settings                   = get_option( self::SETTINGS );
		$settings                   = is_array( $settings ) ? $settings : array();
		$settings['last_report_id'] = $report_id;
		update_option( self::SETTINGS, $settings, false );
		delete_transient( self::REPORT_CACHE );
		$seconds = $result['estimatedSeconds'] ?? 0;
		return array(
			'report_id'         => $report_id,
			'estimated_seconds' => is_numeric( $seconds ) ? max( 0, (int) $seconds ) : 0,
		);
	}

	/**
	 * Ask GenReady for the verification token and keep it with the connection.
	 *
	 * @param array $connection Stored connection.
	 * @return string|array The token, or array( 'error' => string ).
	 */
	private function fetch_token( array $connection ) {
		$result = $this->client->request( 'POST', '/api/v1/domains', array( 'hostname' => Site::hostname() ) );
		if ( $result instanceof ApiError ) {
			return array( 'error' => $result->user_message() );
		}
		$domain = is_array( $result['domain'] ?? null ) ? $result['domain'] : array();
		$token  = $domain['verificationToken'] ?? null;
		if ( ! MetaTag::is_valid( $token ) ) {
			return array( 'error' => __( 'GenReady could not do this. Try again.', 'genready' ) );
		}
		$this->store->save( array_merge( $connection, array( 'verification_token' => $token ) ) );
		return $token;
	}
}
