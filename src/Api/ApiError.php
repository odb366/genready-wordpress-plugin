<?php
/**
 * A failed GenReady API call.
 *
 * @package GenReady
 */

namespace GenReady\Api;

defined( 'ABSPATH' ) || exit;

/**
 * Error value returned by the API client. It carries the server details and
 * turns them into one plain sentence for the admin screen.
 */
final class ApiError {
	/**
	 * HTTP status, or null when no response arrived.
	 *
	 * @var int|null
	 */
	public $status;
	/**
	 * Error code from the server, or a client code such as TIMEOUT.
	 *
	 * @var string
	 */
	public $code;
	/**
	 * Message from the server. It is not shown, except for UNSAFE_MERGE.
	 *
	 * @var string
	 */
	public $message;
	/**
	 * Extra data from the server.
	 *
	 * @var array
	 */
	public $details;

	/**
	 * Build an error.
	 *
	 * @param int|null $status  HTTP status, or null.
	 * @param string   $code    Error code.
	 * @param string   $message Server message.
	 * @param array    $details Extra data.
	 */
	public function __construct( $status, string $code, string $message, array $details = array() ) {
		$this->status  = $status;
		$this->code    = $code;
		$this->message = $message;
		$this->details = $details;
	}

	/**
	 * Plain text for the user. The text is not escaped: callers escape it.
	 */
	public function user_message(): string {
		if ( 'TIMEOUT' === $this->code ) {
			return __( 'GenReady did not answer. Try again.', 'genready' );
		}
		if ( 'INSECURE_BASE' === $this->code ) {
			return __( "GenReady's address must use HTTPS.", 'genready' );
		}
		switch ( $this->status ) {
			case 401:
				return __( 'Reconnect GenReady.', 'genready' );
			case 402:
				if ( 'PLAN_REQUIRED' === $this->code ) {
					return __( 'GenReady Pro and Agency generate this file.', 'genready' );
				}
				if ( 'DOMAIN_LIMIT_REACHED' === $this->code ) {
					return __( 'Your plan has no free site slot. Remove a site in GenReady or upgrade.', 'genready' );
				}
				if ( 'CREDITS_EXHAUSTED' === $this->code ) {
					return __( 'Your GenReady plan has no scans left for now.', 'genready' );
				}
				break;
			case 404:
				return __( 'This site was removed from your GenReady account. Reconnect to add it again.', 'genready' );
			case 409:
				if ( 'VERIFIED_ELSEWHERE' === $this->code ) {
					return __( 'This site is connected to another GenReady account.', 'genready' );
				}
				if ( 'NOT_VERIFIED' === $this->code ) {
					return __( 'Verify this site first.', 'genready' );
				}
				break;
			case 422:
				if ( 'UNSAFE_MERGE' === $this->code && '' !== $this->message ) {
					return $this->message;
				}
				break;
			case 429:
				return $this->busy_message();
		}
		return __( 'GenReady could not do this. Try again.', 'genready' );
	}

	/**
	 * Text for a 429, with the wait time when the server gave one.
	 */
	private function busy_message(): string {
		$seconds = $this->details['retryAfterSeconds'] ?? 0;
		if ( ! is_int( $seconds ) || $seconds <= 0 ) {
			return __( 'GenReady is busy. Try again in a few minutes.', 'genready' );
		}
		$minutes = max( 1, (int) ceil( $seconds / 60 ) );
		return sprintf(
			/* translators: %d: number of minutes to wait. */
			_n( 'GenReady is busy. Try again in %d minute.', 'GenReady is busy. Try again in %d minutes.', $minutes, 'genready' ),
			$minutes
		);
	}
}
