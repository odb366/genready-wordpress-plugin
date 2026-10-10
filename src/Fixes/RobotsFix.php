<?php
/**
 * The robots.txt fix.
 *
 * @package GenReady
 */

namespace GenReady\Fixes;

use GenReady\Api\ApiError;
use GenReady\Api\Client;
use GenReady\Compat\Caches;

defined( 'ABSPATH' ) || exit;

/**
 * Preview, apply, serve and undo the robots.txt that the GenReady server
 * merged and checked. The server refuses any merge that changes access for a
 * crawler that is not an AI crawler. The plugin serves a stored file only for
 * the exact base the server checked, so crawlers never get a mix nobody
 * checked. The front-end filter uses local data only and fails safe. The
 * class is not final so a test can observe the Content-Type guard of base().
 */
class RobotsFix {
	/** Fix kind in the store. */
	const KIND = 'robots';
	/** File name in the site root. */
	const FILE = 'robots.txt';
	/** Largest base the server accepts, in bytes. */
	const MAX_BASE_BYTES = 512000;
	/** Largest file the plugin applies, in bytes. */
	const MAX_CONTENT_BYTES = 600000;

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
	 * API client.
	 *
	 * @var Client
	 */
	private $client;
	/**
	 * Page cache purger.
	 *
	 * @var Caches
	 */
	private $caches;
	/**
	 * GenReady domain ID, or a callable that returns it when preview() runs.
	 *
	 * @var string|callable
	 */
	private $domain_id;
	/**
	 * True while base() runs do_robots() to record WordPress's own output.
	 *
	 * @var bool
	 */
	private $capturing = false;
	/**
	 * Output recorded by the filter in capture mode.
	 *
	 * @var string
	 */
	private $captured = '';
	/**
	 * Content-Type header line sent before base() ran do_robots(), or null.
	 *
	 * @var string|null
	 */
	private $content_type = null;

	/**
	 * Build the fix.
	 *
	 * @param FixStore        $store     Fix storage.
	 * @param FileWriter      $files     Files in the site root.
	 * @param Client          $client    API client.
	 * @param Caches          $caches    Page caches.
	 * @param string|callable $domain_id Domain ID, or a callable that returns it. A callable keeps the
	 *                                   front end from loading (and decrypting) the connection.
	 */
	public function __construct( FixStore $store, FileWriter $files, Client $client, Caches $caches, $domain_id ) {
		$this->store     = $store;
		$this->files     = $files;
		$this->client    = $client;
		$this->caches    = $caches;
		$this->domain_id = is_string( $domain_id ) || is_callable( $domain_id ) ? $domain_id : '';
	}

	/**
	 * How this site serves robots.txt.
	 *
	 * @return string 'hidden' (search engines asked not to index), 'subdir' (WordPress is not at the
	 *                root of the host), 'unknown' (the root folder cannot be found), 'file' (a physical
	 *                robots.txt) or 'virtual' (made by WordPress).
	 */
	public function mode(): string {
		$blog_public = get_option( 'blog_public' );
		if ( is_scalar( $blog_public ) && '0' === (string) $blog_public ) {
			return 'hidden';
		}
		$path = wp_parse_url( home_url(), PHP_URL_PATH );
		if ( is_string( $path ) && '' !== $path && '/' !== $path ) {
			return 'subdir';
		}
		if ( ! $this->files->root_known() ) {
			return 'unknown';
		}
		return $this->files->exists( self::FILE ) ? 'file' : 'virtual';
	}

	/**
	 * The robots.txt that WordPress or the physical file gives now, without
	 * this fix. In virtual mode it runs do_robots() while the filter records
	 * its input and changes nothing.
	 */
	public function base(): string {
		if ( 'file' === $this->mode() ) {
			return (string) $this->files->read( self::FILE );
		}
		if ( ! function_exists( 'do_robots' ) ) {
			return '';
		}
		$level = ob_get_level();
		// do_robots() sends a text/plain Content-Type. In a REST request that would
		// replace the JSON Content-Type the REST server already sent.
		$this->remember_content_type();
		$this->capturing = true;
		$this->captured  = '';
		ob_start();
		try {
			do_robots();
		} finally {
			while ( ob_get_level() > $level ) {
				ob_end_clean();
			}
			$this->capturing = false;
			$this->restore_content_type();
		}
		return $this->captured;
	}

	/**
	 * Record the Content-Type header that is set now, before do_robots() runs.
	 */
	protected function remember_content_type(): void {
		$this->content_type = null;
		foreach ( headers_list() as $line ) {
			if ( 0 === stripos( $line, 'Content-Type:' ) ) {
				$this->content_type = $line;
			}
		}
	}

	/**
	 * Put the recorded Content-Type back, or remove the one do_robots() set.
	 */
	protected function restore_content_type(): void {
		if ( headers_sent() ) {
			return;
		}
		if ( null !== $this->content_type ) {
			header( $this->content_type, true );
			return;
		}
		header_remove( 'Content-Type' );
	}

	/**
	 * Ask GenReady to merge the AI crawler rules into the current robots.txt.
	 *
	 * @param bool $content_signal Add the Content-Signal line.
	 * @return array|ApiError The preview: content, diff, summary, notes, changed, needsHumanInput,
	 *                        external, live, base_sha256 and mode. array( 'error' => string ) when the
	 *                        plugin refuses before or after the call. ApiError when the call failed.
	 */
	public function preview( bool $content_signal ) {
		$mode    = $this->mode();
		$refusal = $this->mode_refusal( $mode );
		if ( null !== $refusal ) {
			return array( 'error' => $refusal );
		}
		$domain_id = is_string( $this->domain_id ) ? $this->domain_id : call_user_func( $this->domain_id );
		if ( ! is_string( $domain_id ) || '' === $domain_id ) {
			return array( 'error' => __( 'Reconnect GenReady.', 'genready' ) );
		}
		if ( 'file' === $mode && null === $this->files->read( self::FILE ) ) {
			return array( 'error' => $this->unreadable() );
		}
		$base = $this->base();
		if ( strlen( $base ) > self::MAX_BASE_BYTES ) {
			return array( 'error' => __( 'robots.txt is too large for GenReady to check.', 'genready' ) );
		}
		$result = $this->client->request(
			'POST',
			'/api/v1/artifacts/robots_txt/merge',
			array(
				'domainId' => $domain_id,
				'base'     => array(
					'mode'    => $mode,
					'content' => $base,
				),
				'options'  => array( 'contentSignal' => $content_signal ),
			),
			30
		);
		if ( $result instanceof ApiError ) {
			return $result;
		}
		$base_sha256 = hash( 'sha256', $base );
		$server_hash = $result['baseSha256'] ?? null;
		if ( ! is_string( $server_hash ) || ! hash_equals( $base_sha256, $server_hash ) ) {
			return array( 'error' => __( 'robots.txt changed during the preview. Try again.', 'genready' ) );
		}
		$content = $result['content'] ?? null;
		if ( ! is_string( $content ) || '' === $content || strlen( $content ) > self::MAX_CONTENT_BYTES ) {
			return array( 'error' => __( 'GenReady could not do this. Try again.', 'genready' ) );
		}
		$live = is_array( $result['live'] ?? null ) ? $result['live'] : array();
		return array(
			'content'         => $content,
			'diff'            => is_string( $result['diff'] ?? null ) ? $result['diff'] : '',
			'summary'         => $this->strings( $result['summary'] ?? null ),
			'notes'           => $this->strings( $result['notes'] ?? null ),
			'changed'         => true === ( $result['changed'] ?? false ),
			'needsHumanInput' => false !== ( $result['needsHumanInput'] ?? true ),
			'external'        => $this->is_external( $mode, $live ),
			'live'            => array(
				'sha256'          => is_string( $live['sha256'] ?? null ) ? $live['sha256'] : null,
				'differsFromBase' => true === ( $live['differsFromBase'] ?? false ),
			),
			'base_sha256'     => $base_sha256,
			'mode'            => $mode,
		);
	}

	/**
	 * Apply a preview that preview() returned. The caller keeps the preview on
	 * the server; content from the browser is never trusted.
	 *
	 * @param array $preview The preview array.
	 * @return array array( 'ok' => true ) or array( 'error' => string ).
	 */
	public function apply( array $preview ): array {
		$mode    = $this->mode();
		$refusal = $this->mode_refusal( $mode );
		if ( null !== $refusal ) {
			return array( 'error' => $refusal );
		}
		$changed = __( 'robots.txt changed after the preview. Preview again.', 'genready' );
		if ( ! $this->is_valid_preview( $preview, $mode ) ) {
			return array( 'error' => $changed );
		}
		if ( $preview['needsHumanInput'] ) {
			return array( 'error' => __( 'GenReady needs more information before it can apply this robots.txt. Check the notes in the preview.', 'genready' ) );
		}
		if ( $preview['external'] ) {
			return array( 'error' => __( 'Your host or CDN serves its own robots.txt, so changes in WordPress do not reach visitors. Change it where your host or CDN manages it.', 'genready' ) );
		}
		if ( ! hash_equals( $preview['base_sha256'], hash( 'sha256', $this->base() ) ) ) {
			return array( 'error' => $changed );
		}
		if ( 'file' === $mode ) {
			$result = $this->apply_file( $preview );
			if ( isset( $result['error'] ) ) {
				return $result;
			}
		} else {
			$this->store->put(
				self::KIND,
				array(
					'mode'        => 'virtual',
					'content'     => $preview['content'],
					'base_sha256' => $preview['base_sha256'],
					'status'      => 'applied',
				)
			);
		}
		$this->purge();
		return array( 'ok' => true );
	}

	/**
	 * Undo the fix. It needs no connection.
	 *
	 * Virtual mode: restore the previous record exactly (with its own base
	 * hash), or remove the fix when there is none. This is safe because the
	 * filter serves a stored file only while the live base has that hash.
	 *
	 * File mode: one step back to the file as it was before GenReady first
	 * wrote it, and only while the file still holds what GenReady wrote. The
	 * file record and its versions are then removed.
	 *
	 * @return array array( 'ok' => true ) or array( 'error' => string ).
	 */
	public function undo(): array {
		$fix = $this->store->get( self::KIND );
		if ( array() === $fix ) {
			return array( 'error' => __( 'There is nothing to undo.', 'genready' ) );
		}
		if ( 'file' === ( $fix['mode'] ?? '' ) ) {
			$result = $this->undo_file( $fix );
			if ( isset( $result['error'] ) ) {
				return $result;
			}
		} else {
			$this->undo_virtual( $fix );
		}
		$this->purge();
		return array( 'ok' => true );
	}

	/**
	 * The robots_txt filter, at priority PHP_INT_MAX. It serves the stored file
	 * only when the incoming output is the exact base the server checked. It
	 * never calls the API, never purges and never writes files.
	 *
	 * @param mixed $output      Robots.txt that WordPress and other plugins made.
	 * @param mixed $site_public The blog_public option. A hidden site changes the output, so the hash guard covers it.
	 * @return mixed
	 */
	public function filter( $output, $site_public ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- part of the robots_txt filter signature.
		try {
			if ( ! is_scalar( $output ) && null !== $output ) {
				return $output;
			}
			if ( $this->capturing ) {
				$this->captured = (string) $output;
				return $output;
			}
			$fix         = $this->store->get( self::KIND );
			$content     = $fix['content'] ?? null;
			$base_sha256 = $fix['base_sha256'] ?? null;
			if ( ! is_string( $content ) || '' === $content || ! is_string( $base_sha256 ) || 'virtual' !== ( $fix['mode'] ?? '' ) ) {
				return $output;
			}
			if ( ! hash_equals( $base_sha256, hash( 'sha256', (string) $output ) ) ) {
				// Something else changed robots.txt after the server checked the
				// fix. Serve that output as it is: never a mix nobody checked.
				if ( 'needs_review' !== ( $fix['status'] ?? '' ) ) {
					$fix['status'] = 'needs_review';
					$this->store->put_status( self::KIND, $fix );
				}
				return $output;
			}
			if ( 'needs_review' === ( $fix['status'] ?? '' ) ) {
				// The base is back to the one the server checked.
				$fix['status'] = 'applied';
				$this->store->put_status( self::KIND, $fix );
			}
			if ( ! headers_sent() ) {
				header( 'Cache-Control: public, max-age=300' );
			}
			return $content;
		} catch ( \Throwable $e ) {
			return $output;
		}
	}

	/**
	 * Write the file in file mode. The original is stored before the write.
	 * When a file record exists and the file still holds what GenReady wrote,
	 * the first original (the file before GenReady) is kept.
	 *
	 * @param array $preview Checked preview.
	 * @return array array( 'ok' => true ) or array( 'error' => string, 'code' => CANNOT_WRITE|WRITE_FAILED ).
	 */
	private function apply_file( array $preview ): array {
		if ( ! $this->files->can_write( self::FILE ) ) {
			return array(
				'error' => __( "WordPress cannot write robots.txt on this server. Download the file and upload it to your site's root folder.", 'genready' ),
				'code'  => 'CANNOT_WRITE',
			);
		}
		$previous = $this->store->get( self::KIND );
		$current  = $this->files->read( self::FILE );
		if ( null === $current && $this->files->exists( self::FILE ) ) {
			// Never replace a file whose content GenReady could not read and the server did not check.
			return array( 'error' => $this->unreadable() );
		}
		$original = $current;
		if ( 'file' === ( $previous['mode'] ?? '' ) && array_key_exists( 'original', $previous ) && $this->holds_written( $current, $previous ) ) {
			$original = is_string( $previous['original'] ) ? $previous['original'] : null;
		}
		$this->store->put(
			self::KIND,
			array(
				'mode'           => 'file',
				'content'        => $preview['content'],
				'base_sha256'    => $preview['base_sha256'],
				'original'       => $original,
				'written_sha256' => hash( 'sha256', $preview['content'] ),
				'status'         => 'applied',
			)
		);
		if ( $this->files->write( self::FILE, $preview['content'] ) ) {
			return array( 'ok' => true );
		}
		// The write failed or left other bytes. Put the old file back (a
		// truncated robots.txt opens the site to every crawler) and the old record.
		if ( null === $current ) {
			$this->files->delete( self::FILE );
		} elseif ( $this->files->read( self::FILE ) !== $current ) {
			$this->files->write( self::FILE, $current );
		}
		if ( array() === $previous ) {
			$this->store->remove( self::KIND );
		} else {
			$this->store->replace( self::KIND, $previous );
		}
		return array(
			'error' => __( "WordPress could not write robots.txt correctly. Check the file on your server, then download the new file and upload it to your site's root folder.", 'genready' ),
			'code'  => 'WRITE_FAILED',
		);
	}

	/**
	 * Put the file back as it was before GenReady first wrote it.
	 *
	 * @param array $fix Stored file record.
	 * @return array array( 'ok' => true ) or array( 'error' => string ).
	 */
	private function undo_file( array $fix ): array {
		if ( ! $this->files->root_known() ) {
			return array( 'error' => $this->root_unknown() );
		}
		if ( ! $this->holds_written( $this->files->read( self::FILE ), $fix ) ) {
			return array( 'error' => __( 'robots.txt was edited after GenReady changed it, so GenReady did not undo it. Check the file yourself.', 'genready' ) );
		}
		$original = $fix['original'] ?? null;
		$done     = is_string( $original ) ? $this->files->write( self::FILE, $original ) : $this->files->delete( self::FILE );
		if ( ! $done ) {
			return array( 'error' => __( 'WordPress could not change robots.txt, so GenReady did not undo it. Check the file yourself.', 'genready' ) );
		}
		$this->store->remove( self::KIND );
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
			// virtual site cannot serve): go back to WordPress's own output.
			$this->store->remove( self::KIND );
			return;
		}
		$this->store->replace(
			self::KIND,
			array(
				'mode'        => 'virtual',
				'content'     => $previous['content'],
				'base_sha256' => $previous['base_sha256'],
				'status'      => 'applied',
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

	/**
	 * Check that a preview has the shape preview() gives, for the current mode.
	 *
	 * @param array  $preview Preview to check.
	 * @param string $mode    Current mode.
	 */
	private function is_valid_preview( array $preview, string $mode ): bool {
		$content = $preview['content'] ?? null;
		$hash    = $preview['base_sha256'] ?? null;
		return is_string( $content ) && '' !== $content && strlen( $content ) <= self::MAX_CONTENT_BYTES
			&& is_string( $hash ) && 1 === preg_match( '/^[a-f0-9]{64}$/', $hash )
			&& ( $preview['mode'] ?? null ) === $mode
			&& is_bool( $preview['needsHumanInput'] ?? null )
			&& is_bool( $preview['external'] ?? null );
	}

	/**
	 * The text that refuses a fix for this mode, or null. The fix card shows it.
	 *
	 * @param string $mode Current mode.
	 */
	public function mode_refusal( string $mode ): ?string {
		if ( 'hidden' === $mode ) {
			return __( 'This site asks search engines not to index it (Settings → Reading), so GenReady does not open it to AI crawlers.', 'genready' );
		}
		if ( 'subdir' === $mode ) {
			return __( 'robots.txt works only at the root of a domain. This site lives in a subfolder, so GenReady cannot change robots.txt from WordPress.', 'genready' );
		}
		if ( 'unknown' === $mode ) {
			return $this->root_unknown();
		}
		return null;
	}

	/**
	 * True when the host or a CDN serves its own robots.txt. GenReady's own
	 * applied fix also differs from the base, so it does not count.
	 *
	 * @param string $mode Current mode.
	 * @param array  $live The server's live check.
	 */
	private function is_external( string $mode, array $live ): bool {
		$live_sha256 = $live['sha256'] ?? null;
		if ( 'virtual' !== $mode || true !== ( $live['differsFromBase'] ?? false ) || ! is_string( $live_sha256 ) ) {
			return false;
		}
		$fix = $this->store->get( self::KIND );
		if ( 'virtual' === ( $fix['mode'] ?? '' ) && is_string( $fix['content'] ?? null ) && '' !== $fix['content'] ) {
			return ! hash_equals( hash( 'sha256', $fix['content'] ), $live_sha256 );
		}
		return true;
	}

	/**
	 * Keep only the strings of a list.
	 *
	 * @param mixed $values Value from the server.
	 * @return string[]
	 */
	private function strings( $values ): array {
		return is_array( $values ) ? array_values( array_filter( $values, 'is_string' ) ) : array();
	}

	/**
	 * Text for a site whose root folder cannot be found.
	 */
	private function root_unknown(): string {
		return __( "GenReady cannot find this site's root folder, so it does not change robots.txt.", 'genready' );
	}

	/**
	 * Text for a robots.txt file that exists but cannot be read.
	 */
	private function unreadable(): string {
		return __( 'WordPress cannot read robots.txt on this server, so GenReady does not change it. Check the file permissions.', 'genready' );
	}

	/**
	 * Purge page caches for robots.txt.
	 */
	private function purge(): void {
		$this->caches->purge_url( home_url( '/robots.txt' ) );
	}
}
