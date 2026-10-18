<?php
/**
 * Scans made without a GenReady account.
 *
 * @package GenReady
 */

namespace GenReady\Onboarding;

use GenReady\Api\ApiError;
use GenReady\Api\Client;
use GenReady\Connection\TokenStore;

defined( 'ABSPATH' ) || exit;

/**
 * Starts a scan of a public page of this site without an account, keeps the
 * random scan token encrypted, and reads the result with that token. The
 * server decides every limit. This class counts nothing (wordpress.org rule 5).
 */
final class AnonymousScans {
	/** Option with the stored scans. Autoload off. */
	const OPTION = 'genready_anonymous_scans';
	/** Most scans kept. */
	const MAX = 10;
	/** Seconds a scan stays readable without an account. */
	const TTL = 30 * DAY_IN_SECONDS;
	/** Transient set for 1 hour after a 503 WP_ONBOARDING_OFF (spec R19). */
	const OFF = 'genready_anon_off';
	/** Pattern of a scan ID (the report UUID). */
	const UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';
	/** Pattern of a scan token: 32 random bytes, base64url without padding. */
	const TOKEN = '/^[A-Za-z0-9_-]{43}$/';

	/**
	 * Encryption with the connection's key.
	 *
	 * @var TokenStore
	 */
	private $crypto;
	/**
	 * API client.
	 *
	 * @var Client
	 */
	private $client;

	/**
	 * Build the store.
	 *
	 * @param TokenStore $crypto Encryption with the connection's key.
	 * @param Client     $client API client.
	 */
	public function __construct( TokenStore $crypto, Client $client ) {
		$this->crypto = $crypto;
		$this->client = $client;
	}

	/**
	 * POST /api/v1/wp/scans for a page on this site, then store the scan.
	 *
	 * @param string $page_url Page address.
	 * @return array array( 'scan_id' => string ), or array( 'error' => string, 'code' => string, 'status' => int|null ).
	 */
	public function start( string $page_url ): array {
		if ( self::paused() ) {
			return self::refusal( __( 'GenReady paused scans without an account. Connect with a GenReady account.', 'genready' ), 'WP_ONBOARDING_OFF', 503 );
		}
		if ( ! PageUrl::is_on_site( $page_url ) ) {
			return self::refusal( __( 'Enter an address on this site.', 'genready' ), 'NOT_THIS_SITE', null );
		}
		$result = $this->client->call_public(
			'POST',
			'/api/v1/wp/scans',
			array(
				'siteUrl'       => home_url( '/' ),
				'pageUrl'       => $page_url,
				'pluginVersion' => GENREADY_VERSION,
			)
		);
		if ( $result instanceof ApiError ) {
			self::note( $result );
			if ( 429 === $result->status ) {
				$wait = $result->details['retryAfterSeconds'] ?? 0;
				return self::refusal( self::limit_message( is_int( $wait ) ? $wait : 0 ), $result->code, 429 );
			}
			return self::refusal( $result->user_message(), $result->code, $result->status );
		}
		$id    = $result['scanId'] ?? null;
		$token = $result['scanToken'] ?? null;
		if ( ! is_string( $id ) || 1 !== preg_match( self::UUID, $id ) || ! is_string( $token ) || 1 !== preg_match( self::TOKEN, $token ) ) {
			return self::refusal( __( 'GenReady could not do this. Try again.', 'genready' ), 'BAD_RESPONSE', null );
		}
		$this->remember( $id, $page_url, $token );
		return array( 'scan_id' => $id );
	}

	/**
	 * GET /api/v1/wp/scans/:id with its token. A 404 removes the scan from the list.
	 *
	 * @param string $scan_id Scan ID from this list.
	 * @return array|ApiError The `data` of the answer, or an error. An ID outside the list is a 404 with no call.
	 */
	public function fetch( string $scan_id ) {
		$entry = null;
		foreach ( $this->entries() as $candidate ) {
			if ( $candidate['id'] === $scan_id ) {
				$entry = $candidate;
				break;
			}
		}
		if ( null === $entry ) {
			return new ApiError( 404, 'NOT_FOUND', '' );
		}
		$result = $this->client->call_public( 'GET', '/api/v1/wp/scans/' . rawurlencode( $scan_id ), null, $entry['token'] );
		if ( $result instanceof ApiError ) {
			self::note( $result );
			if ( 404 === $result->status ) {
				$this->forget( $scan_id );
			}
		}
		return $result;
	}

	/**
	 * The stored scans without tokens, newest first.
	 *
	 * @return array[] array( 'id' => string, 'url' => string, 'at' => int ) each.
	 */
	public function all(): array {
		$list = array();
		foreach ( $this->entries() as $entry ) {
			$list[] = array(
				'id'  => $entry['id'],
				'url' => $entry['url'],
				'at'  => $entry['at'],
			);
		}
		return $list;
	}

	/**
	 * The decrypted tokens, newest first, for the account call.
	 *
	 * @return string[]
	 */
	public function tokens(): array {
		return array_column( $this->entries(), 'token' );
	}

	/**
	 * Store a scan at the top of the list. A malformed ID or token is ignored.
	 *
	 * @param string $scan_id  Scan ID.
	 * @param string $page_url Page address.
	 * @param string $token    Scan token from GenReady.
	 */
	public function remember( string $scan_id, string $page_url, string $token ): void {
		if ( 1 !== preg_match( self::UUID, $scan_id ) || 1 !== preg_match( self::TOKEN, $token ) ) {
			return;
		}
		$this->entries(); // Drops expired and broken entries first.
		$stored = get_option( self::OPTION );
		$list   = array(
			array(
				'id'    => $scan_id,
				'url'   => esc_url_raw( $page_url ),
				'at'    => time(),
				'token' => $this->crypto->encrypt( $token ),
			),
		);
		foreach ( is_array( $stored ) ? $stored : array() as $item ) {
			if ( count( $list ) < self::MAX && is_array( $item ) && ( $item['id'] ?? null ) !== $scan_id ) {
				$list[] = $item;
			}
		}
		update_option( self::OPTION, $list, false );
	}

	/**
	 * Forget every stored scan.
	 */
	public function clear(): void {
		delete_option( self::OPTION );
	}

	/**
	 * True for 1 hour after GenReady said that scans without an account are off.
	 */
	public static function paused(): bool {
		return false !== get_transient( self::OFF );
	}

	/**
	 * Remember a 503 WP_ONBOARDING_OFF for 1 hour. The plugin cannot know the
	 * switch state without a call (spec R19).
	 *
	 * @param ApiError $error A failed call.
	 */
	public static function note( ApiError $error ): void {
		if ( 503 === $error->status && 'WP_ONBOARDING_OFF' === $error->code ) {
			set_transient( self::OFF, 1, HOUR_IN_SECONDS );
		}
	}

	/**
	 * The text after a 429 (spec 4.4, R18). "tomorrow" only when the wait ends
	 * on a later date in the site's time zone, else the time of day.
	 *
	 * @param int      $seconds Seconds to wait. 0 or less means unknown.
	 * @param int|null $now     Unix time now. Null means time().
	 */
	public static function limit_message( int $seconds, ?int $now = null ): string {
		$now = null === $now ? time() : $now;
		$end = $now + $seconds;
		if ( $seconds <= 0 || wp_date( 'Y-m-d', $end ) !== wp_date( 'Y-m-d', $now ) ) {
			return __( 'Scan again tomorrow, or create a free account.', 'genready' );
		}
		return sprintf(
			/* translators: %s: time of day of the next possible scan, in the site's time format. */
			__( 'Scan again after %s, or create a free account.', 'genready' ),
			wp_date( (string) get_option( 'time_format' ), $end )
		);
	}

	/**
	 * Remove one scan from the list.
	 *
	 * @param string $scan_id Scan ID.
	 */
	private function forget( string $scan_id ): void {
		$stored = get_option( self::OPTION );
		$list   = array();
		foreach ( is_array( $stored ) ? $stored : array() as $item ) {
			if ( is_array( $item ) && ( $item['id'] ?? null ) !== $scan_id ) {
				$list[] = $item;
			}
		}
		if ( array() === $list ) {
			delete_option( self::OPTION );
			return;
		}
		update_option( self::OPTION, $list, false );
	}

	/**
	 * The stored entries with their tokens decrypted. Entries that are
	 * expired, malformed or do not decrypt (the salts changed) are dropped,
	 * and the option is written back without them.
	 *
	 * @return array[] array( 'id', 'url', 'at', 'token' ) each.
	 */
	private function entries(): array {
		$raw   = get_option( self::OPTION );
		$raw   = is_array( $raw ) ? array_values( $raw ) : array();
		$valid = array();
		$kept  = array();
		foreach ( $raw as $item ) {
			$entry = $this->decode( $item );
			if ( null === $entry || count( $valid ) >= self::MAX ) {
				continue;
			}
			$valid[] = $entry;
			$kept[]  = $item;
		}
		if ( count( $kept ) !== count( $raw ) ) {
			if ( array() === $kept ) {
				delete_option( self::OPTION );
			} else {
				update_option( self::OPTION, $kept, false );
			}
		}
		return $valid;
	}

	/**
	 * One stored entry with its token decrypted, or null when it is not usable.
	 *
	 * @param mixed $item Stored entry.
	 */
	private function decode( $item ): ?array {
		if ( ! is_array( $item ) || ! is_string( $item['id'] ?? null ) || 1 !== preg_match( self::UUID, $item['id'] )
			|| ! is_string( $item['url'] ?? null ) || ! is_int( $item['at'] ?? null ) || ! is_string( $item['token'] ?? null ) ) {
			return null;
		}
		if ( time() - $item['at'] > self::TTL ) {
			return null;
		}
		$token = $this->crypto->decrypt( $item['token'] );
		if ( null === $token || 1 !== preg_match( self::TOKEN, $token ) ) {
			return null;
		}
		return array(
			'id'    => $item['id'],
			'url'   => $item['url'],
			'at'    => $item['at'],
			'token' => $token,
		);
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
