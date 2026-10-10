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
use GenReady\Support\Text;

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
	 * True when the last base() saw do_robots() print more than the filter
	 * output, for example text a plugin prints on the do_robotstxt action.
	 *
	 * @var bool
	 */
	private $extra_output = false;

	/**
	 * Shared file and record work, built on first use.
	 *
	 * @var FileBackedFix|null
	 */
	private $file_fix = null;

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
	 *                root of the host), 'unknown' (the root folder cannot be found), 'network' (a physical
	 *                robots.txt that this multisite request may not change), 'file' (a physical
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
		if ( ! $this->files->exists( self::FILE ) ) {
			return 'virtual';
		}
		return $this->files->may_write() ? 'file' : 'network';
	}

	/**
	 * The robots.txt that WordPress or the physical file gives now, without
	 * this fix. In virtual mode it runs do_robots() while the filter records
	 * its input and changes nothing.
	 */
	public function base(): string {
		$this->extra_output = false;
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
			while ( ob_get_level() > $level + 1 ) {
				ob_end_flush();
			}
			// Crawlers get everything do_robots() prints, so it must be the checked base and nothing more.
			$this->extra_output = (string) ob_get_contents() !== $this->captured;
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
		if ( $this->extra_output ) {
			return array( 'error' => $this->extra_text() );
		}
		if ( strlen( $base ) > self::MAX_BASE_BYTES ) {
			return array( 'error' => __( 'robots.txt is too large for GenReady to check.', 'genready' ) );
		}
		$result = $this->request_merge( $domain_id, $mode, $base, $content_signal );
		return $result instanceof ApiError ? $result : $this->checked_preview( $result, $mode, $base );
	}

	/**
	 * Ask the server to merge the AI crawler rules into the base.
	 *
	 * @param string $domain_id      GenReady domain ID.
	 * @param string $mode           Current mode.
	 * @param string $base           The robots.txt the merge starts from.
	 * @param bool   $content_signal Add the Content-Signal line.
	 * @return array|ApiError The server's answer.
	 */
	private function request_merge( string $domain_id, string $mode, string $base, bool $content_signal ) {
		return $this->client->request(
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
	}

	/**
	 * Check the server's merge against the base it was sent and keep only the
	 * fields the plugin uses.
	 *
	 * @param array  $result The server's answer.
	 * @param string $mode   Current mode.
	 * @param string $base   The robots.txt that was sent.
	 * @return array The preview (see preview()), or array( 'error' => string ).
	 */
	private function checked_preview( array $result, string $mode, string $base ): array {
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
			'summary'         => Text::strings( $result['summary'] ?? null ),
			'notes'           => Text::strings( $result['notes'] ?? null ),
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
		$base = $this->base();
		if ( $this->extra_output ) {
			return array( 'error' => $this->extra_text() );
		}
		if ( ! hash_equals( $preview['base_sha256'], hash( 'sha256', $base ) ) ) {
			return array( 'error' => $changed );
		}
		$result = 'file' === $mode ? $this->file_fix()->apply_file( $preview ) : $this->file_fix()->apply_virtual( $preview['content'], $preview['base_sha256'] );
		if ( isset( $result['error'] ) ) {
			return $result;
		}
		$this->purge();
		return array( 'ok' => true );
	}

	/**
	 * Tell whether the applied virtual fix is no longer served because the
	 * robots.txt WordPress makes changed after the server checked the fix. It
	 * runs do_robots(), which sends a header, so call it before any output.
	 * It writes nothing.
	 */
	public function needs_review(): bool {
		$fix         = $this->store->get( self::KIND );
		$base_sha256 = $fix['base_sha256'] ?? null;
		if ( 'virtual' !== ( $fix['mode'] ?? '' ) || ! is_string( $base_sha256 ) || 'virtual' !== $this->mode() ) {
			return false;
		}
		return ! hash_equals( $base_sha256, hash( 'sha256', $this->base() ) );
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
		$result = $this->file_fix()->undo();
		if ( isset( $result['error'] ) ) {
			return $result;
		}
		$this->purge();
		return array( 'ok' => true );
	}

	/**
	 * The robots_txt filter, at priority PHP_INT_MAX. It serves the stored file
	 * only when the incoming output is the exact base the server checked. It
	 * never calls the API, never purges and never writes files or options.
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
				// fix. Serve that output as it is: never a mix nobody checked. The
				// admin card shows "Needs review" (see needs_review()).
				return $output;
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
		if ( 'network' === $mode ) {
			return __( 'On a multisite network, only a network admin on the main site can change robots.txt in the site root.', 'genready' );
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
	 * The file and record work shared with the other file-backed fix. It is
	 * built on first use, so its texts are translated only when needed.
	 */
	private function file_fix(): FileBackedFix {
		if ( null === $this->file_fix ) {
			$this->file_fix = new FileBackedFix(
				$this->store,
				$this->files,
				self::KIND,
				self::FILE,
				array(
					'cannot_write' => __( "WordPress cannot write robots.txt on this server. Download the file and upload it to your site's root folder.", 'genready' ),
					'unreadable'   => $this->unreadable(),
					'backup'       => __( 'GenReady could not save its backup of robots.txt, so it did not change the file.', 'genready' ),
					'write_failed' => __( "WordPress could not write robots.txt correctly. Check the file on your server, then download the new file and upload it to your site's root folder.", 'genready' ),
					'root_unknown' => $this->root_unknown(),
					'edited'       => __( 'robots.txt was edited after GenReady changed it, so GenReady did not undo it. Check the file yourself.', 'genready' ),
					'undo_failed'  => __( 'WordPress could not change robots.txt, so GenReady did not undo it. Check the file yourself.', 'genready' ),
				)
			);
		}
		return $this->file_fix;
	}

	/**
	 * Text for a site whose root folder cannot be found.
	 */
	private function root_unknown(): string {
		return __( "GenReady cannot find this site's root folder, so it does not change robots.txt.", 'genready' );
	}

	/**
	 * Text for a robots.txt that another plugin adds text to outside the filter.
	 */
	private function extra_text(): string {
		return __( 'Another plugin adds text to robots.txt, so GenReady cannot check the result.', 'genready' );
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
