<?php
/**
 * Apply and undo for a fix that can live in a file in the site root.
 *
 * @package GenReady
 */

namespace GenReady\Fixes;

defined( 'ABSPATH' ) || exit;

/**
 * The record and file work that the robots.txt and llms.txt fixes share.
 *
 * File mode: the original file is stored before GenReady writes, and the
 * write is refused when that backup did not save. Re-applying keeps the
 * first original (the file before GenReady) while the file still holds
 * what GenReady wrote. A failed write puts the old file and the old record
 * back. Undo goes back to that original, and only while the file still
 * holds what GenReady wrote.
 *
 * Virtual mode: undo restores the previous virtual record exactly (with its
 * own base hash), or removes the fix.
 *
 * The owning fix passes its own texts, so each message names its file.
 */
class FileBackedFix {
	/**
	 * Fix storage.
	 *
	 * @var FixStore
	 */
	private $store;
	/**
	 * Files in the site root.
	 *
	 * @var FileWriter
	 */
	private $files;
	/**
	 * Fix kind in the store.
	 *
	 * @var string
	 */
	private $kind;
	/**
	 * File name in the site root.
	 *
	 * @var string
	 */
	private $file;
	/**
	 * Messages of the owning fix: cannot_write, unreadable, backup, write_failed, root_unknown, edited, undo_failed.
	 *
	 * @var string[]
	 */
	private $texts;

	/**
	 * Build the shared part of a fix.
	 *
	 * @param FixStore   $store Fix storage.
	 * @param FileWriter $files Files in the site root.
	 * @param string     $kind  Fix kind in the store.
	 * @param string     $file  File name in the site root.
	 * @param string[]   $texts Messages that name the file (see $texts).
	 */
	public function __construct( FixStore $store, FileWriter $files, string $kind, string $file, array $texts ) {
		$this->store = $store;
		$this->files = $files;
		$this->kind  = $kind;
		$this->file  = $file;
		$this->texts = $texts;
	}

	/**
	 * Store a virtual fix.
	 *
	 * @param string $content     The checked file.
	 * @param string $base_sha256 Hash of the base the fix was built from.
	 * @return array array( 'ok' => true ) or array( 'error' => string ).
	 */
	public function apply_virtual( string $content, string $base_sha256 ): array {
		$saved = $this->store->put(
			$this->kind,
			array(
				'mode'        => 'virtual',
				'content'     => $content,
				'base_sha256' => $base_sha256,
			)
		);
		return $saved ? array( 'ok' => true ) : array( 'error' => __( 'GenReady could not save the fix. Try again.', 'genready' ) );
	}

	/**
	 * Write the file in file mode. The original is stored before the write.
	 *
	 * @param array $preview Checked preview with 'content' and 'base_sha256'.
	 * @return array array( 'ok' => true ) or array( 'error' => string, 'code'? => CANNOT_WRITE|WRITE_FAILED ).
	 */
	public function apply_file( array $preview ): array {
		if ( ! $this->files->can_write( $this->file ) ) {
			return array(
				'error' => $this->texts['cannot_write'],
				'code'  => 'CANNOT_WRITE',
			);
		}
		$previous = $this->store->get( $this->kind );
		$current  = $this->files->read( $this->file );
		if ( null === $current && $this->files->exists( $this->file ) ) {
			// Never replace a file whose content GenReady could not read and the server did not check.
			return array( 'error' => $this->texts['unreadable'] );
		}
		$original = $current;
		if ( 'file' === ( $previous['mode'] ?? '' ) && array_key_exists( 'original', $previous ) && $this->holds_written( $current, $previous ) ) {
			$original = is_string( $previous['original'] ) ? $previous['original'] : null;
		}
		$saved = $this->store->put(
			$this->kind,
			array(
				'mode'           => 'file',
				'content'        => $preview['content'],
				'base_sha256'    => $preview['base_sha256'],
				'original'       => $original,
				'written_sha256' => hash( 'sha256', $preview['content'] ),
			)
		);
		if ( ! $saved ) {
			// Without the stored original, undo could not put the file back.
			return array( 'error' => $this->texts['backup'] );
		}
		if ( $this->files->write( $this->file, $preview['content'] ) ) {
			return array( 'ok' => true );
		}
		$this->roll_back( $current, $previous );
		return array(
			'error' => $this->texts['write_failed'],
			'code'  => 'WRITE_FAILED',
		);
	}

	/**
	 * Undo the stored fix: file mode or virtual mode. It needs no connection.
	 *
	 * @return array array( 'ok' => true ) or array( 'error' => string ).
	 */
	public function undo(): array {
		$fix = $this->store->get( $this->kind );
		if ( array() === $fix ) {
			return array( 'error' => __( 'There is nothing to undo.', 'genready' ) );
		}
		if ( 'file' === ( $fix['mode'] ?? '' ) ) {
			return $this->undo_file( $fix );
		}
		$this->undo_virtual( $fix );
		return array( 'ok' => true );
	}

	/**
	 * After a failed or bad write, put the old file back (a truncated
	 * robots.txt opens the site to every crawler) and the old record.
	 *
	 * @param string|null $current  The file before the write, or null when there was none.
	 * @param array       $previous The record before the write.
	 */
	private function roll_back( ?string $current, array $previous ): void {
		if ( null === $current ) {
			$this->files->delete( $this->file );
		} elseif ( $this->files->read( $this->file ) !== $current ) {
			$this->files->write( $this->file, $current );
		}
		if ( array() === $previous ) {
			$this->store->remove( $this->kind );
		} else {
			$this->store->replace( $this->kind, $previous );
		}
	}

	/**
	 * Put the file back as it was before GenReady first wrote it.
	 *
	 * @param array $fix Stored file record.
	 * @return array array( 'ok' => true ) or array( 'error' => string ).
	 */
	private function undo_file( array $fix ): array {
		if ( ! $this->files->root_known() ) {
			return array( 'error' => $this->texts['root_unknown'] );
		}
		if ( ! $this->holds_written( $this->files->read( $this->file ), $fix ) ) {
			return array( 'error' => $this->texts['edited'] );
		}
		$original = $fix['original'] ?? null;
		$done     = is_string( $original ) ? $this->files->write( $this->file, $original ) : $this->files->delete( $this->file );
		if ( ! $done ) {
			return array( 'error' => $this->texts['undo_failed'] );
		}
		$this->store->remove( $this->kind );
		return array( 'ok' => true );
	}

	/**
	 * Restore the previous virtual record, or remove the fix.
	 *
	 * @param array $fix Stored record.
	 */
	private function undo_virtual( array $fix ): void {
		$versions = is_array( $fix['versions'] ?? null ) ? array_values( $fix['versions'] ) : array();
		$previous = array_pop( $versions );
		$valid    = is_array( $previous )
			&& 'virtual' === ( $previous['mode'] ?? '' )
			&& is_string( $previous['content'] ?? null ) && '' !== $previous['content']
			&& is_string( $previous['base_sha256'] ?? null ) && '' !== $previous['base_sha256'];
		if ( ! $valid ) {
			// No earlier virtual record (or an earlier file record, which a
			// virtual site cannot serve): go back to no fix.
			$this->store->remove( $this->kind );
			return;
		}
		$this->store->replace(
			$this->kind,
			array(
				'mode'        => 'virtual',
				'content'     => $previous['content'],
				'base_sha256' => $previous['base_sha256'],
				'versions'    => $versions,
			)
		);
	}

	/**
	 * Tell whether the file holds exactly what GenReady wrote.
	 *
	 * @param string|null $current Current file content.
	 * @param array       $fix     Stored file record.
	 */
	private function holds_written( ?string $current, array $fix ): bool {
		$written = $fix['written_sha256'] ?? null;
		return null !== $current && is_string( $written ) && hash_equals( $written, hash( 'sha256', $current ) );
	}
}
