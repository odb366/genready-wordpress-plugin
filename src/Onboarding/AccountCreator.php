<?php
/**
 * "Create my free account".
 *
 * @package GenReady
 */

namespace GenReady\Onboarding;

use GenReady\Api\ApiError;
use GenReady\Api\Client;
use GenReady\Compat\Caches;
use GenReady\Connection\SiteSetup;
use GenReady\Connection\TokenStore;
use GenReady\Verification\MetaTag;

defined( 'ABSPATH' ) || exit;

/**
 * Makes a free GenReady account for an email, proves that this site is the
 * caller's (spec 7), and connects the plugin with the one-site key that
 * GenReady returns (spec 10.1). The earlier scans move into the account.
 */
final class AccountCreator {
	/** Seconds to wait for the account answer (spec R21). */
	const TIMEOUT = 20;
	/** Longest email accepted. */
	const MAX_EMAIL = 254;
	/** Pattern of a GenReady API key (as ApiKey::connect()). */
	const KEY = '/^gr_live_[A-Za-z0-9_-]{16,128}$/';

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
	 * The one-time proof.
	 *
	 * @var Proof
	 */
	private $proof;
	/**
	 * Scans made without an account.
	 *
	 * @var AnonymousScans
	 */
	private $scans;
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
	 * @param TokenStore     $store  Token storage.
	 * @param Client         $client API client.
	 * @param Proof          $proof  The one-time proof.
	 * @param AnonymousScans $scans  Scans made without an account.
	 * @param MetaTag        $meta   Verification meta tag.
	 * @param Caches         $caches Page caches.
	 */
	public function __construct( TokenStore $store, Client $client, Proof $proof, AnonymousScans $scans, MetaTag $meta, Caches $caches ) {
		$this->store  = $store;
		$this->client = $client;
		$this->proof  = $proof;
		$this->scans  = $scans;
		$this->meta   = $meta;
		$this->caches = $caches;
	}

	/**
	 * Make the account and connect the plugin.
	 *
	 * @param string $email Email typed in the banner.
	 * @return array array( 'ok' => true, 'email' => string ), or array( 'error' => string, 'code' => string, 'status' => int|null ).
	 */
	public function create( string $email ): array {
		if ( null !== $this->store->load() ) {
			return self::refusal( __( 'Disconnect GenReady first.', 'genready' ), 'CONNECTED', null );
		}
		$email = sanitize_email( trim( $email ) );
		if ( '' === $email || strlen( $email ) > self::MAX_EMAIL || ! is_email( $email ) ) {
			return self::refusal( __( 'Enter a valid email address.', 'genready' ), 'INVALID_EMAIL', null );
		}
		if ( AnonymousScans::paused() ) {
			return self::refusal( __( 'GenReady paused scans without an account. Connect with a GenReady account.', 'genready' ), 'WP_ONBOARDING_OFF', 503 );
		}
		$proof = $this->proof->create();
		try {
			$result = $this->client->call_public(
				'POST',
				'/api/v1/wp/accounts',
				array(
					'email'      => $email,
					'siteUrl'    => home_url( '/' ),
					'proofId'    => $proof['id'],
					'proofHash'  => $proof['hash'],
					'scanTokens' => $this->scans->tokens(),
				),
				null,
				self::TIMEOUT
			);
		} finally {
			$this->proof->discard( $proof['id'] );
		}
		if ( $result instanceof ApiError ) {
			return $this->failure( $result );
		}
		return $this->connect( $result, $email );
	}

	/**
	 * Store the connection of the new account and turn on the tag.
	 *
	 * @param array  $data  The `data` of the 201 answer.
	 * @param string $email The account email.
	 */
	private function connect( array $data, string $email ): array {
		$key    = $data['apiKey'] ?? null;
		$domain = $data['domainId'] ?? null;
		$token  = $data['verificationToken'] ?? null;
		if ( ! is_string( $key ) || 1 !== preg_match( self::KEY, $key ) || ! is_string( $domain )
			|| 1 !== preg_match( AnonymousScans::UUID, $domain ) || ! MetaTag::is_valid( $token ) ) {
			return self::refusal( __( 'GenReady could not do this. Try again.', 'genready' ), 'BAD_RESPONSE', null );
		}
		$newest = $this->newest_adopted( $data['adoptedScanIds'] ?? null );
		$this->store->save(
			array(
				'method'             => 'api_key',
				'api_key'            => $key,
				'domain_id'          => $domain,
				'verification_token' => $token,
			)
		);
		// The weekly re-check reads the tag (spec 8.4), so it must reach the cached home page too.
		$this->meta->enable( $token );
		$this->caches->purge_url( home_url( '/' ) );
		$this->set_last_report( $newest );
		$this->scans->clear();
		delete_transient( SiteSetup::REPORT_CACHE );
		set_transient(
			'genready_notice_' . get_current_user_id(),
			array(
				'ok'      => true,
				'account' => true,
				'email'   => $email,
			),
			60
		);
		return array(
			'ok'    => true,
			'email' => $email,
		);
	}

	/**
	 * The newest stored scan that GenReady adopted, or ''.
	 *
	 * @param mixed $adopted adoptedScanIds from the answer.
	 */
	private function newest_adopted( $adopted ): string {
		$ids = array_map( 'strtolower', array_filter( is_array( $adopted ) ? $adopted : array(), 'is_string' ) );
		foreach ( $this->scans->all() as $scan ) {
			if ( in_array( strtolower( $scan['id'] ), $ids, true ) ) {
				return $scan['id'];
			}
		}
		return '';
	}

	/**
	 * Point the Overview at the adopted report, or at nothing.
	 *
	 * @param string $report_id Report ID, or ''.
	 */
	private function set_last_report( string $report_id ): void {
		$settings = get_option( SiteSetup::SETTINGS );
		$settings = is_array( $settings ) ? $settings : array();
		if ( '' === $report_id ) {
			unset( $settings['last_report_id'] );
		} else {
			$settings['last_report_id'] = $report_id;
		}
		update_option( SiteSetup::SETTINGS, $settings, false );
	}

	/**
	 * A failed account call as a refusal (spec 4.6 and 11).
	 *
	 * @param ApiError $error The failure.
	 */
	private function failure( ApiError $error ): array {
		AnonymousScans::note( $error );
		if ( 429 === $error->status ) {
			return self::refusal( __( 'Too many account requests today. Try again tomorrow.', 'genready' ), $error->code, 429 );
		}
		return self::refusal( $error->user_message(), $error->code, $error->status );
	}

	/**
	 * A refusal array.
	 *
	 * @param string   $message Plain text for the admin.
	 * @param string   $code    Machine code for the script.
	 * @param int|null $status  HTTP status from GenReady, if any.
	 */
	private static function refusal( string $message, string $code, $status ): array {
		return array(
			'error'  => $message,
			'code'   => $code,
			'status' => $status,
		);
	}
}
