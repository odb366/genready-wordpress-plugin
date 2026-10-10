<?php
/**
 * Encrypted credential storage and the refresh lock.
 *
 * @package GenReady
 */

namespace GenReady\Connection;

defined( 'ABSPATH' ) || exit;

/**
 * Credentials at rest. Tokens are encrypted with a key derived from the
 * site's own salts, so a database dump alone does not hand them over.
 */
class TokenStore {
	/** Option holding the connection. */
	const OPTION = 'genready_connection';
	/** Option name of the refresh lock row. */
	const LOCK = 'genready_refresh_lock';
	/** Keys that are encrypted at rest. */
	const SECRET_KEYS = array( 'access_token', 'refresh_token', 'api_key' );
	/** Seconds after which a lock counts as stale. */
	const LOCK_TTL = 30;

	/**
	 * Encrypt the secret fields and store the connection.
	 *
	 * @param array $connection Connection data.
	 */
	public function save( array $connection ): void {
		foreach ( self::SECRET_KEYS as $key ) {
			if ( isset( $connection[ $key ] ) && '' !== $connection[ $key ] ) {
				$connection[ $key ] = $this->encrypt( (string) $connection[ $key ] );
			}
		}
		update_option( self::OPTION, $connection, false );
	}

	/**
	 * Load and decrypt the connection.
	 *
	 * @return array|null Null when nothing is stored or the salts changed.
	 */
	public function load(): ?array {
		$stored = get_option( self::OPTION );
		if ( ! is_array( $stored ) ) {
			return null;
		}
		foreach ( self::SECRET_KEYS as $key ) {
			if ( isset( $stored[ $key ] ) && '' !== $stored[ $key ] ) {
				$plain = $this->decrypt( (string) $stored[ $key ] );
				if ( null === $plain ) {
					return null;
				}
				$stored[ $key ] = $plain;
			}
		}
		return $stored;
	}

	/**
	 * Delete the stored connection.
	 */
	public function clear(): void {
		delete_option( self::OPTION );
	}

	/**
	 * Refresh tokens are single use, and a reused one ends the grant. Only the
	 * request that holds this lock may refresh.
	 *
	 * The function add_option() is not a test-and-set (it runs INSERT ... ON
	 * DUPLICATE KEY UPDATE), so the lock uses INSERT IGNORE like
	 * WP_Upgrader::create_lock(). The caller holds the lock only when exactly
	 * one row was inserted.
	 *
	 * @param int $wait_seconds Seconds to wait for a held lock.
	 */
	public function acquire_lock( int $wait_seconds = 5 ): bool {
		$deadline = time() + $wait_seconds;
		do {
			if ( $this->insert_lock() ) {
				return true;
			}
			$since = $this->read_lock();
			if ( null !== $since && time() - (int) $since > self::LOCK_TTL ) {
				// Delete only the stale row we read, so a fresh lock is never removed.
				$this->delete_lock_if( $since );
				if ( $this->insert_lock() ) {
					return true;
				}
			}
			if ( $wait_seconds > 0 ) {
				usleep( 250000 );
			}
		} while ( time() < $deadline );
		return false;
	}

	/**
	 * Release the refresh lock.
	 */
	public function release_lock(): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- atomic lock; must bypass the options cache.
		$wpdb->delete( $wpdb->options, array( 'option_name' => self::LOCK ) );
	}

	/**
	 * Try to take the lock row. True only when one row was inserted.
	 */
	private function insert_lock(): bool {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- atomic lock; must bypass the options cache. Table name is $wpdb->options.
		return 1 === $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'off')", self::LOCK, (string) time() ) );
	}

	/**
	 * Read the lock time, or null when no lock row exists.
	 */
	private function read_lock(): ?string {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- atomic lock; must bypass the options cache. Table name is $wpdb->options.
		$value = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::LOCK ) );
		return null === $value ? null : (string) $value;
	}

	/**
	 * Delete the lock row only while it still holds the given value.
	 *
	 * @param string $value Lock time read earlier.
	 */
	private function delete_lock_if( string $value ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- atomic lock; must bypass the options cache. Table name is $wpdb->options.
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", self::LOCK, $value ) );
	}

	/**
	 * Derive the encryption key from the site salts.
	 */
	private function key(): string {
		return sodium_crypto_generichash( wp_salt( 'auth' ) . 'genready-token-store', '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES );
	}

	/**
	 * Encrypt a value as base64( nonce . cipher ).
	 *
	 * @param string $plain Plain text.
	 */
	private function encrypt( string $plain ): string {
		$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- encodes ciphertext for storage.
		return base64_encode( $nonce . sodium_crypto_secretbox( $plain, $nonce, $this->key() ) );
	}

	/**
	 * Decrypt a stored value, or null when it does not decrypt.
	 *
	 * @param string $stored Stored text.
	 */
	private function decrypt( string $stored ): ?string {
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- decodes stored ciphertext.
		$raw = base64_decode( $stored, true );
		if ( false === $raw || strlen( $raw ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
			return null;
		}
		$nonce = substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$plain = sodium_crypto_secretbox_open( substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ), $nonce, $this->key() );
		return false === $plain ? null : $plain;
	}
}
