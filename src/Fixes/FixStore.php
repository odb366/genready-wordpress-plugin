<?php
/**
 * Storage for applied fixes.
 *
 * @package GenReady
 */

namespace GenReady\Fixes;

defined( 'ABSPATH' ) || exit;

/**
 * Keeps one record per fix kind, each in its own option that is not
 * autoloaded, so one large record never blocks the others. Each record keeps
 * its last 5 earlier records so undo needs no connection. Every write reads
 * the option back and tells whether the record was really stored.
 *
 * A record holds the fix (mode, content, base_sha256 and, in file mode,
 * original and written_sha256), its versions and the last live check. It
 * stores no state: the admin card works the state out. Older records can
 * still have a 'status' field. Nothing reads it.
 */
class FixStore {
	/** The option of each fix kind. */
	const OPTIONS = array(
		'robots'      => 'genready_fix_robots',
		'llms'        => 'genready_fix_llms',
		'jsonld_home' => 'genready_fix_jsonld_home',
	);
	/** Earlier records kept per kind. */
	const MAX_VERSIONS = 5;

	/**
	 * Read the record of one kind.
	 *
	 * @param string $kind Fix kind, for example 'robots'.
	 * @return array An empty array when nothing is stored, the kind is unknown or the option is corrupt.
	 */
	public function get( string $kind ): array {
		$option = self::OPTIONS[ $kind ] ?? null;
		$fix    = null === $option ? null : get_option( $option );
		return is_array( $fix ) ? $fix : array();
	}

	/**
	 * Store a new record. The previous record (content, base_sha256, mode)
	 * goes to the end of 'versions', and only the last 5 are kept.
	 *
	 * @param string $kind Fix kind.
	 * @param array  $fix  New record. Its own 'versions' value is ignored.
	 * @return bool True when the record is stored.
	 */
	public function put( string $kind, array $fix ): bool {
		$previous = $this->get( $kind );
		$versions = $this->versions( $previous );
		if ( is_string( $previous['content'] ?? null ) && '' !== $previous['content'] ) {
			$versions[] = array(
				'content'     => $previous['content'],
				'base_sha256' => is_string( $previous['base_sha256'] ?? null ) ? $previous['base_sha256'] : '',
				'mode'        => is_string( $previous['mode'] ?? null ) ? $previous['mode'] : '',
			);
		}
		$fix['versions'] = array_slice( $versions, -self::MAX_VERSIONS );
		return $this->save( $kind, $fix );
	}

	/**
	 * Update a record without a new version, for example to add a live check.
	 * The stored versions stay as they are.
	 *
	 * @param string $kind Fix kind.
	 * @param array  $fix  Updated record.
	 * @return bool True when the record is stored.
	 */
	public function update( string $kind, array $fix ): bool {
		$fix['versions'] = $this->versions( $this->get( $kind ) );
		return $this->save( $kind, $fix );
	}

	/**
	 * Store a record exactly as given, its versions included. Undo uses it to
	 * step back one version without adding a new one.
	 *
	 * @param string $kind Fix kind.
	 * @param array  $fix  Record to store.
	 * @return bool True when the record is stored.
	 */
	public function replace( string $kind, array $fix ): bool {
		$fix['versions'] = $this->versions( $fix );
		return $this->save( $kind, $fix );
	}

	/**
	 * Delete the record of one kind.
	 *
	 * @param string $kind Fix kind.
	 * @return bool True when no record is stored afterwards.
	 */
	public function remove( string $kind ): bool {
		$option = self::OPTIONS[ $kind ] ?? null;
		if ( null === $option ) {
			return false;
		}
		delete_option( $option );
		return null === get_option( $option, null );
	}

	/**
	 * Write one record into its option and read it back. update_option()
	 * returns false also when the value did not change, so only the read-back
	 * decides.
	 *
	 * @param string $kind Fix kind.
	 * @param array  $fix  Record.
	 * @return bool True when the option holds exactly the record.
	 */
	public function save( string $kind, array $fix ): bool {
		$option = self::OPTIONS[ $kind ] ?? null;
		if ( null === $option ) {
			return false;
		}
		update_option( $option, $fix, false );
		return get_option( $option, null ) === $fix;
	}

	/**
	 * The well-formed earlier records of a record.
	 *
	 * @param array $fix Record.
	 * @return array[]
	 */
	private function versions( array $fix ): array {
		$versions = is_array( $fix['versions'] ?? null ) ? $fix['versions'] : array();
		return array_values( array_filter( $versions, 'is_array' ) );
	}
}
