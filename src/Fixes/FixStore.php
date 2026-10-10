<?php
/**
 * Storage for applied fixes.
 *
 * @package GenReady
 */

namespace GenReady\Fixes;

defined( 'ABSPATH' ) || exit;

/**
 * Keeps one record per fix kind in a single option that is not autoloaded.
 * Each record keeps its last 5 earlier records so undo needs no connection.
 */
class FixStore {
	/** Option holding all fix records. */
	const OPTION = 'genready_fixes';
	/** Earlier records kept per kind. */
	const MAX_VERSIONS = 5;

	/**
	 * Read the record of one kind.
	 *
	 * @param string $kind Fix kind, for example 'robots'.
	 * @return array An empty array when nothing is stored or the option is corrupt.
	 */
	public function get( string $kind ): array {
		$fix = $this->all()[ $kind ] ?? null;
		return is_array( $fix ) ? $fix : array();
	}

	/**
	 * Store a new record. The previous record (content, base_sha256, mode)
	 * goes to the end of 'versions', and only the last 5 are kept.
	 *
	 * @param string $kind Fix kind.
	 * @param array  $fix  New record. Its own 'versions' value is ignored.
	 */
	public function put( string $kind, array $fix ): void {
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
		$this->save( $kind, $fix );
	}

	/**
	 * Update a record without a new version, for example to change its status.
	 * The stored versions stay as they are.
	 *
	 * @param string $kind Fix kind.
	 * @param array  $fix  Updated record.
	 */
	public function put_status( string $kind, array $fix ): void {
		$fix['versions'] = $this->versions( $this->get( $kind ) );
		$this->save( $kind, $fix );
	}

	/**
	 * Store a record exactly as given, its versions included. Undo uses it to
	 * step back one version without adding a new one.
	 *
	 * @param string $kind Fix kind.
	 * @param array  $fix  Record to store.
	 */
	public function replace( string $kind, array $fix ): void {
		$fix['versions'] = $this->versions( $fix );
		$this->save( $kind, $fix );
	}

	/**
	 * Delete the record of one kind.
	 *
	 * @param string $kind Fix kind.
	 */
	public function remove( string $kind ): void {
		$all = $this->all();
		unset( $all[ $kind ] );
		update_option( self::OPTION, $all, false );
	}

	/**
	 * All records, or an empty array when the option is not an array.
	 */
	private function all(): array {
		$all = get_option( self::OPTION );
		return is_array( $all ) ? $all : array();
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

	/**
	 * Write one record into the option.
	 *
	 * @param string $kind Fix kind.
	 * @param array  $fix  Record.
	 */
	private function save( string $kind, array $fix ): void {
		$all          = $this->all();
		$all[ $kind ] = $fix;
		update_option( self::OPTION, $all, false );
	}
}
