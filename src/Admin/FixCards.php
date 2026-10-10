<?php
/**
 * What each fix card shows.
 *
 * @package GenReady
 */

namespace GenReady\Admin;

use GenReady\Fixes\FileWriter;
use GenReady\Fixes\FixStore;
use GenReady\Fixes\JsonLdFix;
use GenReady\Fixes\LlmsTxtFix;
use GenReady\Fixes\RobotsFix;

defined( 'ABSPATH' ) || exit;

/**
 * Builds the state of the robots.txt, llms.txt and JSON-LD cards (spec 7.6)
 * from local data only. The REST layer returns the same arrays after each
 * action, so the page and the script show the same words.
 */
class FixCards {
	/** The fix kinds, in card order. */
	const KINDS = array( 'robots', 'llms', 'jsonld' );

	/**
	 * Fix storage.
	 *
	 * @var FixStore
	 */
	private $store;
	/**
	 * The robots.txt fix.
	 *
	 * @var RobotsFix
	 */
	private $robots;
	/**
	 * The llms.txt fix.
	 *
	 * @var LlmsTxtFix
	 */
	private $llms;
	/**
	 * The JSON-LD fix.
	 *
	 * @var JsonLdFix
	 */
	private $jsonld;

	/**
	 * Files in the site root.
	 *
	 * @var FileWriter
	 */
	private $files;

	/**
	 * Build the cards.
	 *
	 * @param FixStore   $store  Fix storage.
	 * @param RobotsFix  $robots The robots.txt fix.
	 * @param LlmsTxtFix $llms   The llms.txt fix.
	 * @param JsonLdFix  $jsonld The JSON-LD fix.
	 * @param FileWriter $files  Files in the site root, to tell whether WordPress can write them.
	 */
	public function __construct( FixStore $store, RobotsFix $robots, LlmsTxtFix $llms, JsonLdFix $jsonld, FileWriter $files ) {
		$this->store  = $store;
		$this->robots = $robots;
		$this->llms   = $llms;
		$this->jsonld = $jsonld;
		$this->files  = $files;
	}

	/**
	 * Fix storage.
	 */
	public function store(): FixStore {
		return $this->store;
	}

	/**
	 * The robots.txt fix.
	 */
	public function robots(): RobotsFix {
		return $this->robots;
	}

	/**
	 * The llms.txt fix.
	 */
	public function llms(): LlmsTxtFix {
		return $this->llms;
	}

	/**
	 * The JSON-LD fix.
	 */
	public function jsonld(): JsonLdFix {
		return $this->jsonld;
	}

	/**
	 * The page the JSON-LD fix is for: the scanned page, or the home page.
	 *
	 * @param string|null $report_url URL of the last scan, if known.
	 */
	public function jsonld_target( ?string $report_url ): array {
		return $this->jsonld->target_for( null === $report_url ? home_url( '/' ) : $report_url );
	}

	/**
	 * Tell whether a fix of this kind is applied.
	 *
	 * @param string $kind          Fix kind.
	 * @param array  $jsonld_target Target of the JSON-LD fix.
	 */
	public function is_applied( string $kind, array $jsonld_target = array() ): bool {
		if ( 'jsonld' === $kind ) {
			return null !== $this->jsonld->stored_for( $jsonld_target );
		}
		$content = $this->store->get( $kind )['content'] ?? null;
		return is_string( $content ) && '' !== $content;
	}

	/**
	 * All three cards.
	 *
	 * @param array $jsonld_target Target of the JSON-LD fix.
	 * @return array[]
	 */
	public function all( array $jsonld_target ): array {
		$cards = array();
		foreach ( self::KINDS as $kind ) {
			$cards[] = $this->card( $kind, $jsonld_target );
		}
		return $cards;
	}

	/**
	 * One card. The robots.txt card runs do_robots() to tell whether the fix
	 * needs review, so build it before any output (the admin page builds its
	 * cards on the load hook).
	 *
	 * @param string $kind          'robots', 'llms' or 'jsonld'.
	 * @param array  $jsonld_target Target of the JSON-LD fix.
	 * @return array kind, title, state, state_text, message, blocked, applied, versions, live, live_check, mode,
	 *               tagline, can_write (false in file mode when WordPress cannot write the file) and as_file
	 *               (true when this request may write llms.txt as a physical file).
	 */
	public function card( string $kind, array $jsonld_target = array() ): array {
		if ( 'jsonld' === $kind ) {
			return $this->jsonld_card( $jsonld_target );
		}
		$fix      = 'robots' === $kind ? $this->robots : $this->llms;
		$mode     = $fix->mode();
		$refusal  = 'robots' === $kind ? $this->robots->mode_refusal( $mode ) : $this->llms->refusal( $mode );
		$record   = $this->store->get( $kind );
		$applied  = $this->is_applied( $kind );
		$live     = $applied ? $this->live_state( $kind, $record ) : null;
		$card     = array(
			'kind'       => $kind,
			'title'      => 'robots' === $kind ? 'robots.txt' : 'llms.txt',
			'state'      => $applied ? 'applied' : 'not_applied',
			'state_text' => $applied ? __( 'Applied', 'genready' ) : __( 'Not applied', 'genready' ),
			'message'    => null === $refusal ? '' : $refusal,
			'blocked'    => null !== $refusal,
			'applied'    => $applied,
			'versions'   => $this->versions( $record ),
			'live'       => $live,
			'live_check' => $applied,
			'mode'       => $mode,
			'tagline'    => 'llms' === $kind ? wp_strip_all_tags( (string) get_bloginfo( 'description' ) ) : '',
			'can_write'  => 'file' !== $mode || $this->files->can_write( 'robots' === $kind ? RobotsFix::FILE : LlmsTxtFix::FILE ),
			'as_file'    => 'llms' === $kind && $this->files->may_write(),
		);
		$conflict = $applied && 'llms' === $kind && 'virtual' === ( $record['mode'] ?? '' ) ? $this->llms_conflict() : null;
		if ( $applied && 'robots' === $kind && $this->robots->needs_review() ) {
			$card['state']      = 'needs_review';
			$card['state_text'] = __( 'Needs review', 'genready' );
			$card['message']    = __( 'Another plugin or a setting changed robots.txt after GenReady checked the fix, so WordPress serves robots.txt without it. Preview again.', 'genready' );
		} elseif ( null !== $conflict ) {
			$card['state']      = 'needs_review';
			$card['state_text'] = __( 'Needs review', 'genready' );
			$card['message']    = $conflict;
		} elseif ( null !== $live && 'live' === $live['state'] ) {
			$card['state_text'] = __( 'Applied, live', 'genready' );
		} elseif ( null !== $live && 'cdn_old' === $live['state'] ) {
			$card['state_text'] = __( 'Applied, CDN shows an old copy', 'genready' );
		}
		return $card;
	}

	/**
	 * Why an applied virtual llms.txt is not served now, or null. serve() yields
	 * to an SEO plugin that makes llms.txt, and when that check fails.
	 */
	private function llms_conflict(): ?string {
		$check = $this->llms->seo_owner();
		if ( $check['unknown'] ) {
			return __( "GenReady could not check whether another plugin makes llms.txt, so WordPress does not serve GenReady's file now. Try again later.", 'genready' );
		}
		if ( ! is_array( $check['owner'] ) ) {
			return null;
		}
		return sprintf(
			/* translators: 1: SEO plugin name, 2: where to turn its llms.txt off. */
			__( "%1\$s makes llms.txt now, so visitors get its file, not GenReady's. To use GenReady's file, turn this off: %2\$s.", 'genready' ),
			(string) ( $check['owner']['plugin'] ?? '' ),
			(string) ( $check['owner']['setting'] ?? '' )
		);
	}

	/**
	 * The result of the last live check of a fix record, in words.
	 *
	 * @param string $kind   'robots' or 'llms'.
	 * @param array  $record The fix record with its 'live' result.
	 * @return array|null array( 'state' => live|unreachable|no_route|cdn_old|different, 'message' => string,
	 *                    'checked_at' => int ), or null when there is no result.
	 */
	public function live_state( string $kind, array $record ): ?array {
		$live = $record['live'] ?? null;
		if ( 'jsonld' === $kind || ! is_array( $live ) ) {
			return null;
		}
		$status = $live['status'] ?? null;
		$cache  = is_array( $live['cache'] ?? null ) ? $live['cache'] : array();
		$age    = $cache['age'] ?? null;
		$hit    = is_string( $cache['cfCacheStatus'] ?? null ) && 'HIT' === strtoupper( $cache['cfCacheStatus'] );
		if ( true === ( $live['match'] ?? false ) ) {
			$state   = 'live';
			$message = __( 'Live', 'genready' );
		} elseif ( ! is_int( $status ) ) {
			$state   = 'unreachable';
			$message = __( 'GenReady could not reach your site.', 'genready' );
		} elseif ( 'llms' === $kind && 'virtual' === ( $record['mode'] ?? '' ) && 404 === $status ) {
			$state   = 'no_route';
			$message = __( 'Your server does not pass /llms.txt to WordPress.', 'genready' );
		} elseif ( ( is_int( $age ) && $age > 0 ) || $hit ) {
			$state   = 'cdn_old';
			$message = __( 'Applied, CDN shows an old copy. Purge it in your CDN or wait 5 minutes.', 'genready' );
		} else {
			$state   = 'different';
			$message = __( 'Applied, but the live file is different. Check your host or CDN.', 'genready' );
		}
		return array(
			'state'      => $state,
			'message'    => $message,
			'checked_at' => is_int( $record['live_at'] ?? null ) ? $record['live_at'] : 0,
		);
	}

	/**
	 * The JSON-LD card.
	 *
	 * @param array $target Target of the fix.
	 */
	private function jsonld_card( array $target ): array {
		$record  = array() === $target ? null : $this->jsonld->stored_for( $target );
		$applied = null !== $record;
		$message = '';
		if ( array() === $target ) {
			$message = __( 'GenReady adds JSON-LD only to a post, a page or the home page of this site.', 'genready' );
		} elseif ( $applied ) {
			$message = sprintf(
				/* translators: %s: schema.org type, for example Article. */
				__( 'GenReady adds %s markup to the scanned page.', 'genready' ),
				(string) $record['type']
			);
		}
		return array(
			'kind'       => 'jsonld',
			'title'      => 'JSON-LD',
			'state'      => $applied ? 'applied' : 'not_applied',
			'state_text' => $applied ? __( 'Applied', 'genready' ) : __( 'Not applied', 'genready' ),
			'message'    => $message,
			'blocked'    => array() === $target,
			'applied'    => $applied,
			'versions'   => $applied ? $this->jsonld_versions( $record ) : array(),
			'live'       => null,
			'live_check' => false,
			'mode'       => '',
			'tagline'    => '',
			'can_write'  => true,
			'as_file'    => false,
		);
	}

	/**
	 * The earlier versions of a robots.txt or llms.txt record, newest first.
	 *
	 * @param array $record Fix record.
	 * @return string[]
	 */
	private function versions( array $record ): array {
		$versions = is_array( $record['versions'] ?? null ) ? array_values( $record['versions'] ) : array();
		$lines    = array();
		$count    = count( $versions );
		foreach ( array_reverse( $versions ) as $index => $version ) {
			$content = is_array( $version ) && is_string( $version['content'] ?? null ) ? $version['content'] : '';
			$total   = '' === $content ? 0 : substr_count( rtrim( $content, "\n" ), "\n" ) + 1;
			$lines[] = sprintf(
				/* translators: 1: version number, 2: number of lines in the file. */
				_n( 'Version %1$d: %2$d line', 'Version %1$d: %2$d lines', $total, 'genready' ),
				$count - $index,
				$total
			);
		}
		return $lines;
	}

	/**
	 * The earlier versions of a JSON-LD record, newest first.
	 *
	 * @param array $record Stored record.
	 * @return string[]
	 */
	private function jsonld_versions( array $record ): array {
		$versions = is_array( $record['versions'] ?? null ) ? array_values( $record['versions'] ) : array();
		$lines    = array();
		$count    = count( $versions );
		foreach ( array_reverse( $versions ) as $index => $version ) {
			$type    = is_array( $version ) && is_string( $version['type'] ?? null ) ? $version['type'] : '';
			$lines[] = sprintf(
				/* translators: 1: version number, 2: schema.org type, for example Article. */
				__( 'Version %1$d: %2$s', 'genready' ),
				$count - $index,
				$type
			);
		}
		return $lines;
	}
}
