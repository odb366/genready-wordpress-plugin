<?php
/**
 * The recent scans of a connected site.
 *
 * @package GenReady
 */

namespace GenReady\Admin;

use GenReady\Onboarding\PageUrl;

defined( 'ABSPATH' ) || exit;

/**
 * The 10 newest reports that the plugin started with a connection, newest
 * first, with the score of each report once the plugin has read it. The
 * Overview lists them, and opens only a report from this list.
 */
final class RecentReports {
	/** Option with the list. Autoload off. */
	const OPTION = 'genready_recent_reports';
	/** Most reports kept. */
	const MAX = 10;

	/**
	 * The reports, newest first. Malformed entries are skipped.
	 *
	 * @return array[] array( 'id' => string, 'url' => string, 'at' => int, 'score' => int|null ) each.
	 */
	public function all(): array {
		$stored = get_option( self::OPTION );
		$list   = array();
		foreach ( is_array( $stored ) ? $stored : array() as $item ) {
			$entry = self::entry( $item );
			if ( null !== $entry && count( $list ) < self::MAX ) {
				$list[] = $entry;
			}
		}
		return $list;
	}

	/**
	 * Whether a report is in the list.
	 *
	 * @param string $id Report ID.
	 */
	public function has( string $id ): bool {
		return in_array( strtolower( $id ), array_column( $this->all(), 'id' ), true );
	}

	/**
	 * The newest scan of the page that a listed report scanned. It is the
	 * report itself when no later scan of that page is in the list.
	 *
	 * @param string $id Report ID.
	 * @return array|null The entry, or null when the report is not in the list.
	 */
	public function newest_of_page( string $id ): ?array {
		$id   = strtolower( $id );
		$list = $this->all();
		$url  = null;
		foreach ( $list as $entry ) {
			if ( $entry['id'] === $id ) {
				$url = $entry['url'];
				break;
			}
		}
		if ( null === $url ) {
			return null;
		}
		foreach ( $list as $entry ) {
			// The list is newest first, so the first entry of the page is its newest scan.
			if ( $entry['id'] === $id || PageUrl::same_page( $entry['url'], $url ) ) {
				return $entry;
			}
		}
		return null;
	}

	/**
	 * Put a new scan at the top. A scan already in the list moves up and loses its old score.
	 *
	 * @param string $id  Report ID (a UUID).
	 * @param string $url The scanned page.
	 */
	public function add( string $id, string $url ): void {
		if ( 1 !== preg_match( Reports::UUID, $id ) ) {
			return;
		}
		$entry = self::entry(
			array(
				'id'    => strtolower( $id ),
				'url'   => esc_url_raw( $url ),
				'at'    => time(),
				'score' => null,
			)
		);
		if ( null === $entry ) {
			return;
		}
		$list = array( $entry );
		foreach ( $this->all() as $item ) {
			if ( $item['id'] !== $entry['id'] ) {
				$list[] = $item;
			}
		}
		$this->save( array_slice( $list, 0, self::MAX ) );
	}

	/**
	 * Keep the score of a report in the list. Other reports and scores outside 0 to 100 are ignored.
	 *
	 * @param string $id    Report ID.
	 * @param int    $score The AI readiness score.
	 */
	public function set_score( string $id, int $score ): void {
		if ( $score < 0 || $score > 100 ) {
			return;
		}
		$id      = strtolower( $id );
		$list    = $this->all();
		$changed = false;
		foreach ( $list as $index => $item ) {
			if ( $item['id'] === $id && $item['score'] !== $score ) {
				$list[ $index ]['score'] = $score;
				$changed                 = true;
			}
		}
		if ( $changed ) {
			$this->save( $list );
		}
	}

	/**
	 * Add a report from before the list as the oldest entry, once, while the list has room.
	 *
	 * @param string   $id    Report ID (a UUID).
	 * @param string   $url   The scanned page.
	 * @param int      $at    Unix time of the scan, or 0 when it is not known.
	 * @param int|null $score The AI readiness score, or null.
	 */
	public function append( string $id, string $url, int $at, ?int $score ): void {
		$list = $this->all();
		if ( count( $list ) >= self::MAX || $this->has( $id ) ) {
			return;
		}
		$list[] = array(
			'id'    => strtolower( $id ),
			'url'   => esc_url_raw( $url ),
			'at'    => $at,
			'score' => $score,
		);
		$this->replace( $list );
	}

	/**
	 * Remove one report.
	 *
	 * @param string $id Report ID.
	 */
	public function forget( string $id ): void {
		$id   = strtolower( $id );
		$list = array();
		foreach ( $this->all() as $item ) {
			if ( $item['id'] !== $id ) {
				$list[] = $item;
			}
		}
		$this->save( $list );
	}

	/**
	 * Replace the list, for example with the scans that a new account adopted.
	 *
	 * @param array[] $entries Entries as all() returns them, newest first.
	 */
	public function replace( array $entries ): void {
		$list = array();
		foreach ( $entries as $item ) {
			$entry = self::entry( $item );
			if ( null !== $entry && count( $list ) < self::MAX && ! in_array( $entry['id'], array_column( $list, 'id' ), true ) ) {
				$list[] = $entry;
			}
		}
		$this->save( $list );
	}

	/**
	 * Forget every report.
	 */
	public function clear(): void {
		delete_option( self::OPTION );
	}

	/**
	 * Store the list, or delete the option when it is empty.
	 *
	 * @param array[] $entries Valid entries.
	 */
	private function save( array $entries ): void {
		if ( array() === $entries ) {
			$this->clear();
			return;
		}
		update_option( self::OPTION, $entries, false );
	}

	/**
	 * One valid entry, or null.
	 *
	 * @param mixed $item Stored entry.
	 */
	private static function entry( $item ): ?array {
		if ( ! is_array( $item ) || ! is_string( $item['id'] ?? null ) || 1 !== preg_match( Reports::UUID, $item['id'] )
			|| ! is_string( $item['url'] ?? null ) || '' === $item['url'] || ! is_int( $item['at'] ?? null ) ) {
			return null;
		}
		$score = $item['score'] ?? null;
		return array(
			'id'    => strtolower( $item['id'] ),
			'url'   => $item['url'],
			'at'    => $item['at'],
			'score' => is_int( $score ) && $score >= 0 && $score <= 100 ? $score : null,
		);
	}
}
