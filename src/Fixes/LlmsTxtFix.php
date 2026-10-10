<?php
/**
 * The llms.txt fix.
 *
 * @package GenReady
 */

namespace GenReady\Fixes;

use GenReady\Api\ApiError;
use GenReady\Api\Client;
use GenReady\Compat\Caches;
use GenReady\Compat\SeoPlugins;
use GenReady\Connection\Site;
use GenReady\Support\Text;

defined( 'ABSPATH' ) || exit;

/**
 * Preview, apply, serve and undo the llms.txt that the GenReady server builds
 * from the site's name, tagline and public pages. In virtual mode the plugin
 * answers /llms.txt itself. When a physical file exists (or the admin asks for
 * a file after a live 404), the fix works on the file like the robots.txt fix.
 * An SEO plugin that makes its own llms.txt always wins. The front-end path
 * (serve) uses local data only, never the connection and never the API. The
 * class is not final so a test can replace emit().
 */
class LlmsTxtFix {
	/** Fix kind in the store. */
	const KIND = 'llms';
	/** File name in the site root. */
	const FILE = 'llms.txt';
	/** Largest base the plugin sends, in bytes. */
	const MAX_BASE_BYTES = 512000;
	/** Largest file the plugin applies, in bytes. */
	const MAX_CONTENT_BYTES = 600000;
	/** Limits of the server's request check. */
	const MAX_NAME    = 200;
	const MAX_TAGLINE = 500;
	const MAX_TITLE   = 300;
	const MAX_URL     = 2048;
	const MAX_ITEMS   = 200;
	const MAX_PAGES   = 150;
	const MAX_POSTS   = 50;

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
	 * SEO plugin check.
	 *
	 * @var SeoPlugins
	 */
	private $seo;
	/**
	 * GenReady domain ID, or a callable that returns it when preview() runs.
	 *
	 * @var string|callable
	 */
	private $domain_id;

	/**
	 * Build the fix.
	 *
	 * @param FixStore        $store     Fix storage.
	 * @param FileWriter      $files     Files in the site root.
	 * @param Client          $client    API client.
	 * @param Caches          $caches    Page caches.
	 * @param SeoPlugins      $seo       SEO plugin check.
	 * @param string|callable $domain_id Domain ID, or a callable that returns it. A callable keeps the
	 *                                   front end from loading (and decrypting) the connection.
	 */
	public function __construct( FixStore $store, FileWriter $files, Client $client, Caches $caches, SeoPlugins $seo, $domain_id ) {
		$this->store     = $store;
		$this->files     = $files;
		$this->client    = $client;
		$this->caches    = $caches;
		$this->seo       = $seo;
		$this->domain_id = is_string( $domain_id ) || is_callable( $domain_id ) ? $domain_id : '';
	}

	/**
	 * How this site serves llms.txt.
	 *
	 * @return string 'unknown' (the root folder cannot be found, so a physical file cannot be ruled
	 *                out), 'network' (a physical llms.txt that this multisite request may not change),
	 *                'file' (a physical llms.txt) or 'virtual' (made by this plugin).
	 */
	public function mode(): string {
		if ( ! $this->files->root_known() ) {
			return 'unknown';
		}
		if ( ! $this->files->exists( self::FILE ) ) {
			return 'virtual';
		}
		return $this->files->may_write() ? 'file' : 'network';
	}


	/**
	 * The llms.txt that the fix starts from. In file mode it is the physical file. In virtual mode
	 * it is the virtual fix that is stored now, or null.
	 *
	 * @return string|null
	 */
	public function base() {
		if ( 'file' === $this->mode() ) {
			return $this->files->read( self::FILE );
		}
		$fix     = $this->store->get( self::KIND );
		$content = $fix['content'] ?? null;
		return 'virtual' === ( $fix['mode'] ?? '' ) && is_string( $content ) && '' !== $content ? $content : null;
	}

	/**
	 * The site facts the server needs, cleaned so the server never rejects the request.
	 *
	 * @param string|null $tagline Tagline to use instead of the site tagline.
	 * @return array array( 'name' => string, 'tagline' => string, 'pages' => array[] ).
	 */
	public function site_input( ?string $tagline = null ): array {
		$name = $this->clean( (string) get_bloginfo( 'name' ), self::MAX_NAME );
		if ( '' === $name ) {
			$name = Site::hostname();
		}
		$tagline = $this->clean( null === $tagline ? (string) get_bloginfo( 'description' ) : $tagline, self::MAX_TAGLINE );
		$pages   = array();
		$sources = array(
			'page' => get_pages(
				array(
					'post_status' => 'publish',
					'number'      => self::MAX_PAGES,
				)
			),
			'post' => get_posts(
				array(
					'numberposts' => self::MAX_POSTS,
					'post_status' => 'publish',
				)
			),
		);
		foreach ( $sources as $type => $items ) {
			foreach ( is_array( $items ) ? $items : array() as $item ) {
				$page = $this->page( $item, $type );
				if ( null !== $page ) {
					$pages[] = $page;
				}
			}
		}
		return array(
			'name'    => $name,
			'tagline' => $tagline,
			'pages'   => array_slice( $pages, 0, self::MAX_ITEMS ),
		);
	}

	/**
	 * Tell whether another SEO plugin makes llms.txt.
	 *
	 * @return array array( 'owner' => array|null, 'unknown' => bool ). 'owner' has the keys 'plugin'
	 *               and 'setting'. 'unknown' is true when the check threw: that is never "no owner".
	 */
	public function seo_owner(): array {
		try {
			$owner = $this->seo->llms_txt_owner();
			return array(
				'owner'   => is_array( $owner ) ? $owner : null,
				'unknown' => false,
			);
		} catch ( \Throwable $e ) {
			return array(
				'owner'   => null,
				'unknown' => true,
			);
		}
	}

	/**
	 * Ask GenReady to build llms.txt from this site.
	 *
	 * @param array $overrides Only 'tagline' is used.
	 * @return array|ApiError The preview: content, diff, summary, notes, changed, needsHumanInput,
	 *                        missing, base_sha256 and mode. array( 'error' => string ) when the plugin
	 *                        refuses before or after the call. ApiError when the call failed.
	 */
	public function preview( array $overrides ) {
		$mode    = $this->mode();
		$refusal = $this->refusal( $mode );
		if ( null !== $refusal ) {
			return array( 'error' => $refusal );
		}
		$domain_id = is_string( $this->domain_id ) ? $this->domain_id : call_user_func( $this->domain_id );
		if ( ! is_string( $domain_id ) || '' === $domain_id ) {
			return array( 'error' => __( 'Reconnect GenReady.', 'genready' ) );
		}
		$base = $this->base();
		if ( 'file' === $mode && null === $base ) {
			return array( 'error' => $this->unreadable() );
		}
		if ( null !== $base && strlen( $base ) > self::MAX_BASE_BYTES ) {
			return array( 'error' => __( 'llms.txt is too large for GenReady to check.', 'genready' ) );
		}
		$tagline = isset( $overrides['tagline'] ) && is_string( $overrides['tagline'] ) ? sanitize_textarea_field( $overrides['tagline'] ) : null;
		$result  = $this->client->request(
			'POST',
			'/api/v1/artifacts/llms_txt/merge',
			array(
				'domainId' => $domain_id,
				'base'     => array(
					'mode'    => $mode,
					'content' => $base,
				),
				'site'     => $this->site_input( $tagline ),
			),
			30
		);
		if ( $result instanceof ApiError ) {
			return $result;
		}
		$content = $result['content'] ?? null;
		if ( ! is_string( $content ) || '' === $content || strlen( $content ) > self::MAX_CONTENT_BYTES ) {
			return array( 'error' => __( 'GenReady could not do this. Try again.', 'genready' ) );
		}
		return array(
			'content'         => $content,
			'diff'            => is_string( $result['diff'] ?? null ) ? $result['diff'] : '',
			'summary'         => $this->strings( $result['summary'] ?? null ),
			'notes'           => $this->strings( $result['notes'] ?? null ),
			'changed'         => true === ( $result['changed'] ?? false ),
			'needsHumanInput' => false !== ( $result['needsHumanInput'] ?? true ),
			'missing'         => array_values( array_intersect( $this->strings( $result['missing'] ?? null ), array( 'summary', 'pages' ) ) ),
			'base_sha256'     => hash( 'sha256', (string) $base ),
			'mode'            => $mode,
		);
	}

	/**
	 * Apply a preview that preview() returned. The caller keeps the preview on
	 * the server; content from the browser is never trusted.
	 *
	 * @param array $preview The preview array.
	 * @param bool  $as_file Write a physical file. Only when no file exists yet, for a site whose web
	 *                       server never passes a missing .txt file to WordPress.
	 * @return array array( 'ok' => true ) or array( 'error' => string ).
	 */
	public function apply( array $preview, bool $as_file = false ): array {
		$mode    = $this->mode();
		$refusal = $this->refusal( $mode );
		if ( null !== $refusal ) {
			return array( 'error' => $refusal );
		}
		if ( $as_file && ! $this->files->may_write() ) {
			return array( 'error' => $this->network() );
		}
		$content = $preview['content'] ?? null;
		if ( ! is_string( $content ) || '' === $content || strlen( $content ) > self::MAX_CONTENT_BYTES ) {
			return array( 'error' => __( 'GenReady could not do this. Try again.', 'genready' ) );
		}
		$needs = $this->needs_input( $preview );
		if ( null !== $needs ) {
			return array( 'error' => $needs );
		}
		$changed = __( 'llms.txt changed after the preview. Preview again.', 'genready' );
		$hash    = $preview['base_sha256'] ?? null;
		if ( ! is_string( $hash ) || 1 !== preg_match( '/^[a-f0-9]{64}$/', $hash ) || ( $preview['mode'] ?? null ) !== $mode ) {
			return array( 'error' => $changed );
		}
		$base = $this->base();
		if ( 'file' === $mode && null === $base ) {
			return array( 'error' => $this->unreadable() );
		}
		if ( ! hash_equals( $hash, hash( 'sha256', (string) $base ) ) ) {
			return array( 'error' => $changed );
		}
		$preview['content'] = $content;
		if ( 'file' === $mode || $as_file ) {
			$result = $this->apply_file( $preview );
			if ( isset( $result['error'] ) ) {
				return $result;
			}
		} else {
			$saved = $this->store->put(
				self::KIND,
				array(
					'mode'        => 'virtual',
					'content'     => $content,
					'base_sha256' => $hash,
					'status'      => 'applied',
				)
			);
			if ( ! $saved ) {
				return array( 'error' => __( 'GenReady could not save the fix. Try again.', 'genready' ) );
			}
		}
		$this->purge();
		return array( 'ok' => true );
	}

	/**
	 * Undo the fix. It needs no connection.
	 *
	 * Virtual mode: restore the previous record, or remove the fix when there
	 * is none. File mode: put the file back as it was before GenReady wrote it
	 * (or delete it, when GenReady created it), and only while the file still
	 * holds what GenReady wrote.
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
	 * The parse_request action, at priority 0. It answers GET and HEAD requests
	 * for exactly /llms.txt under the home path, and only while a virtual fix is
	 * stored. It uses local data only: no connection, no API, no writes.
	 *
	 * @param mixed $wp The WP object. Not used.
	 */
	public function serve( $wp ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- part of the parse_request signature.
		try {
			$method = isset( $_SERVER['REQUEST_METHOD'] ) && is_string( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : '';
			if ( 'GET' !== $method && 'HEAD' !== $method ) {
				return;
			}
			$uri  = isset( $_SERVER['REQUEST_URI'] ) && is_string( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
			$path = (string) wp_parse_url( $uri, PHP_URL_PATH );
			$home = rtrim( (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ), '/' );
			if ( $path !== $home . '/' . self::FILE ) {
				return;
			}
			$fix     = $this->store->get( self::KIND );
			$content = $fix['content'] ?? null;
			if ( 'virtual' !== ( $fix['mode'] ?? '' ) || ! is_string( $content ) || '' === $content ) {
				return;
			}
			$this->emit( $content, 'HEAD' !== $method );
		} catch ( \Throwable $e ) {
			return;
		}
	}

	/**
	 * Send the file and end the request. Tests replace it.
	 *
	 * @param string $content   File content.
	 * @param bool   $with_body False for a HEAD request: send the headers only.
	 */
	protected function emit( string $content, bool $with_body = true ): void {
		status_header( 200 );
		header( 'Content-Type: text/plain; charset=utf-8' );
		header( 'Cache-Control: public, max-age=300' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Content-Length: ' . strlen( $content ) );
		if ( $with_body ) {
			echo $content; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- text/plain body.
		}
		exit;
	}

	/**
	 * Write the file. The original is stored before the write. When a file
	 * record exists and the file still holds what GenReady wrote, the first
	 * original (the file before GenReady) is kept.
	 *
	 * @param array $preview Checked preview.
	 * @return array array( 'ok' => true ) or array( 'error' => string, 'code' => CANNOT_WRITE|WRITE_FAILED ).
	 */
	private function apply_file( array $preview ): array {
		if ( ! $this->files->can_write( self::FILE ) ) {
			return array(
				'error' => __( "WordPress cannot write llms.txt on this server. Download the file and upload it to your site's root folder.", 'genready' ),
				'code'  => 'CANNOT_WRITE',
			);
		}
		$previous = $this->store->get( self::KIND );
		$current  = $this->files->read( self::FILE );
		if ( null === $current && $this->files->exists( self::FILE ) ) {
			return array( 'error' => $this->unreadable() );
		}
		$original = $current;
		if ( 'file' === ( $previous['mode'] ?? '' ) && array_key_exists( 'original', $previous ) && $this->holds_written( $current, $previous ) ) {
			$original = is_string( $previous['original'] ) ? $previous['original'] : null;
		}
		$saved = $this->store->put(
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
		if ( ! $saved ) {
			// Without the stored original, undo could not put the file back.
			return array( 'error' => __( 'GenReady could not save its backup of llms.txt, so it did not change the file.', 'genready' ) );
		}
		if ( $this->files->write( self::FILE, $preview['content'] ) ) {
			return array( 'ok' => true );
		}
		// The write failed or left other bytes. Put the old file back and the old record.
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
			'error' => __( "WordPress could not write llms.txt correctly. Check the file on your server, then download the new file and upload it to your site's root folder.", 'genready' ),
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
			return array( 'error' => __( 'llms.txt was edited after GenReady changed it, so GenReady did not undo it. Check the file yourself.', 'genready' ) );
		}
		$original = $fix['original'] ?? null;
		$done     = is_string( $original ) ? $this->files->write( self::FILE, $original ) : $this->files->delete( self::FILE );
		if ( ! $done ) {
			return array( 'error' => __( 'WordPress could not change llms.txt, so GenReady did not undo it. Check the file yourself.', 'genready' ) );
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
	 * The text that refuses the fix now (unknown root, another plugin's file,
	 * a failed plugin check), or null. The fix card shows it.
	 *
	 * @param string $mode Current mode.
	 */
	public function refusal( string $mode ): ?string {
		if ( 'unknown' === $mode ) {
			return $this->root_unknown();
		}
		if ( 'network' === $mode ) {
			return $this->network();
		}
		$check = $this->seo_owner();
		if ( $check['unknown'] ) {
			return __( 'Could not check whether another plugin makes llms.txt. Try again.', 'genready' );
		}
		if ( is_array( $check['owner'] ) ) {
			return sprintf(
				/* translators: 1: SEO plugin name, 2: where to turn its llms.txt off. */
				__( "%1\$s already makes your llms.txt. To use GenReady's file, turn this off: %2\$s.", 'genready' ),
				(string) ( $check['owner']['plugin'] ?? '' ),
				(string) ( $check['owner']['setting'] ?? '' )
			);
		}
		return null;
	}

	/**
	 * The text that says what the preview still needs, or null.
	 *
	 * @param array $preview Preview.
	 */
	private function needs_input( array $preview ): ?string {
		$missing = is_array( $preview['missing'] ?? null ) ? $preview['missing'] : array();
		if ( in_array( 'summary', $missing, true ) ) {
			return __( 'Add a site summary first.', 'genready' );
		}
		if ( in_array( 'pages', $missing, true ) ) {
			return __( 'Publish a page first, or add the pages you want AI agents to read.', 'genready' );
		}
		if ( array() !== $missing || false !== ( $preview['needsHumanInput'] ?? true ) ) {
			return __( 'GenReady needs more information before it can apply this llms.txt. Check the notes in the preview.', 'genready' );
		}
		return null;
	}

	/**
	 * One page entry for the server, or null when the item must be left out.
	 *
	 * @param mixed  $item Post object.
	 * @param string $type 'page' or 'post'.
	 * @return array|null
	 */
	private function page( $item, string $type ): ?array {
		if ( ! is_object( $item ) || '' !== (string) ( $item->post_password ?? '' ) ) {
			return null;
		}
		$url   = get_permalink( $item );
		$title = $this->clean( (string) get_the_title( $item ), self::MAX_TITLE );
		if ( '' === $title || ! is_string( $url ) || strlen( $url ) > self::MAX_URL || 1 !== preg_match( '#^https?://#i', $url ) || false === wp_http_validate_url( $url ) ) {
			return null;
		}
		return array(
			'title' => $title,
			'url'   => $url,
			'type'  => $type,
		);
	}

	/**
	 * Turn a WordPress text into plain text on one line: decode entities, remove
	 * tags, trim, and cut it to the server's UTF-16 length limit.
	 *
	 * @param string $text Text.
	 * @param int    $max  Longest length in UTF-16 code units.
	 */
	private function clean( string $text, int $max ): string {
		$text = wp_strip_all_tags( html_entity_decode( $text, ENT_QUOTES, 'UTF-8' ) );
		$text = trim( (string) preg_replace( '/\s+/u', ' ', $text ) );
		return Text::cap( $text, $max );
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
		return __( "GenReady cannot find this site's root folder, so it does not change llms.txt.", 'genready' );
	}

	/**
	 * Text for a multisite request that may not change files in the shared root folder.
	 */
	private function network(): string {
		return __( 'On a multisite network, only a network admin on the main site can change llms.txt in the site root.', 'genready' );
	}

	/**
	 * Text for an llms.txt file that exists but cannot be read.
	 */
	private function unreadable(): string {
		return __( 'WordPress cannot read llms.txt on this server, so GenReady does not change it. Check the file permissions.', 'genready' );
	}

	/**
	 * Purge page caches for llms.txt.
	 */
	private function purge(): void {
		$this->caches->purge_url( home_url( '/' . self::FILE ) );
	}
}
