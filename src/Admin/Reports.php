<?php
/**
 * The latest report and the account facts, read from GenReady.
 *
 * @package GenReady
 */

namespace GenReady\Admin;

use GenReady\Api\ApiError;
use GenReady\Api\Client;
use GenReady\Connection\SiteSetup;
use GenReady\Connection\TokenStore;
use GenReady\Onboarding\AnonymousScans;
use GenReady\Support\Text;

defined( 'ABSPATH' ) || exit;

/**
 * Loads the last scan report and the plan, keeps them for 5 minutes, and trims
 * the report to the fields the admin page shows. Every value that comes from
 * the server is plain text here; the views escape it.
 */
class Reports {
	/** Transient holding the account facts. */
	const ACCOUNT_CACHE = 'genready_account_cache';
	/** Seconds a report or the account facts are kept. */
	const TTL = 300;
	/** Pattern of a report ID (the server accepts UUIDs only). */
	const UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';
	/** Longest reason text shown for one check. */
	const MAX_REASON = 500;
	/** Checks that a fix card solves, by detail key. */
	const FIX_FOR = array(
		'robots_txt'           => 'robots',
		'ai_usage_preferences' => 'robots',
		'llm_txt'              => 'llms',
		'json_ld_schema'       => 'jsonld',
	);
	/** Detail keys of the crawlability section, for details without a category. */
	const CRAWLABILITY_KEYS = array( 'https', 'robots_txt', 'waf_detection', 'ttfb', 'xml_sitemap', 'ai_meta_directives', 'html_size', 'paywall', 'llm_txt', 'alt_text', 'internal_links' );

	/**
	 * Token storage.
	 *
	 * @var TokenStore
	 */
	private $store;
	/**
	 * API client.
	 *
	 * @var Client
	 */
	private $client;
	/**
	 * Scans made without an account, or null.
	 *
	 * @var AnonymousScans|null
	 */
	private $scans;

	/**
	 * Build the reader.
	 *
	 * @param TokenStore          $store  Token storage.
	 * @param Client              $client API client.
	 * @param AnonymousScans|null $scans  Scans made without an account.
	 */
	public function __construct( TokenStore $store, Client $client, ?AnonymousScans $scans = null ) {
		$this->store  = $store;
		$this->client = $client;
		$this->scans  = $scans;
	}

	/**
	 * The ID of the last scan, or '' when there is none or it is not a UUID.
	 */
	public function last_report_id(): string {
		$settings = get_option( SiteSetup::SETTINGS );
		$id       = is_array( $settings ) ? ( $settings['last_report_id'] ?? '' ) : '';
		return is_string( $id ) && 1 === preg_match( self::UUID, $id ) ? $id : '';
	}

	/**
	 * The URL of the cached report, else the page of the last scan, or null.
	 * The cache lives 5 minutes; the stored URL keeps the JSON-LD fix on the
	 * scanned page after that.
	 */
	public function cached_url(): ?string {
		$cache = get_transient( SiteSetup::REPORT_CACHE );
		$url   = is_array( $cache ) && is_array( $cache['report'] ?? null ) ? ( $cache['report']['url'] ?? null ) : null;
		if ( is_string( $url ) && '' !== $url ) {
			return $url;
		}
		$settings = get_option( SiteSetup::SETTINGS );
		$stored   = is_array( $settings ) ? ( $settings['last_report_url'] ?? null ) : null;
		return is_string( $stored ) && '' !== $stored ? $stored : null;
	}

	/**
	 * The state of the last scan. Without a connection, the state of a stored
	 * scan: $scan_id when it is in the plugin's own list, else the newest.
	 *
	 * @param string $scan_id Scan ID of a stored scan, or ''. Ignored with a connection.
	 * @return array|ApiError array( 'state' => 'none' ), array( 'state' => 'pending' ),
	 *                        array( 'state' => 'failed', 'message' => string ),
	 *                        array( 'state' => 'gone', 'message' => string ) or
	 *                        array( 'state' => 'complete', 'report' => array ). Without a connection each
	 *                        state but 'none' also has 'scan_id'. ApiError for other failures.
	 */
	public function state( string $scan_id = '' ) {
		if ( null !== $this->scans && null === $this->store->load() ) {
			return $this->anonymous_state( $scan_id );
		}
		$id = $this->last_report_id();
		if ( '' === $id ) {
			return array( 'state' => 'none' );
		}
		$cache = get_transient( SiteSetup::REPORT_CACHE );
		if ( is_array( $cache ) && ( $cache['id'] ?? '' ) === $id && is_array( $cache['report'] ?? null ) ) {
			return array(
				'state'  => 'complete',
				'report' => $cache['report'],
			);
		}
		$result = $this->client->request( 'GET', '/api/v1/reports/' . $id );
		if ( $result instanceof ApiError ) {
			return $this->state_for_error( $result );
		}
		return $this->complete( $id, $result );
	}

	/**
	 * The state of a stored scan, read with its token.
	 *
	 * @param string $scan_id Requested scan ID, or ''.
	 * @return array|ApiError
	 */
	private function anonymous_state( string $scan_id ) {
		$ids = array_column( $this->scans->all(), 'id' );
		if ( array() === $ids ) {
			return array( 'state' => 'none' );
		}
		$id    = in_array( $scan_id, $ids, true ) ? $scan_id : $ids[0];
		$cache = get_transient( SiteSetup::REPORT_CACHE );
		if ( is_array( $cache ) && ( $cache['id'] ?? '' ) === $id && is_array( $cache['report'] ?? null ) ) {
			return array(
				'state'   => 'complete',
				'report'  => $cache['report'],
				'scan_id' => $id,
			);
		}
		$result = $this->scans->fetch( $id );
		if ( $result instanceof ApiError ) {
			if ( 404 === $result->status ) {
				// Expired, adopted or unknown. AnonymousScans removed it from the list.
				return array(
					'state'   => 'gone',
					'message' => __( 'This scan is no longer available. Check the page again.', 'genready' ),
					'scan_id' => $id,
				);
			}
			$state = $this->state_for_error( $result );
			return is_array( $state ) ? $state + array( 'scan_id' => $id ) : $state;
		}
		return $this->complete( $id, $result ) + array( 'scan_id' => $id );
	}

	/**
	 * A 2xx answer as a state: pending while the scan runs, else the trimmed
	 * report, cached for 5 minutes.
	 *
	 * @param string $id     Report ID.
	 * @param array  $result The `data` of the answer.
	 */
	private function complete( string $id, array $result ): array {
		if ( ! is_array( $result['scores'] ?? null ) || isset( $result['statusUrl'] ) ) {
			// 202: the scan still runs. The client returns the data of any 2xx.
			return array( 'state' => 'pending' );
		}
		$report = self::summarize( $result );
		set_transient(
			SiteSetup::REPORT_CACHE,
			array(
				'id'     => $id,
				'report' => $report,
			),
			self::TTL
		);
		return array(
			'state'  => 'complete',
			'report' => $report,
		);
	}

	/**
	 * Forget the last scan.
	 */
	public function forget(): void {
		$settings = get_option( SiteSetup::SETTINGS );
		if ( is_array( $settings ) && ( array_key_exists( 'last_report_id', $settings ) || array_key_exists( 'last_report_url', $settings ) ) ) {
			unset( $settings['last_report_id'], $settings['last_report_url'] );
			update_option( SiteSetup::SETTINGS, $settings, false );
		}
		delete_transient( SiteSetup::REPORT_CACHE );
	}

	/**
	 * Plan and site facts for the Connection tab. GenReady has no API that
	 * returns the account email, so the tab does not show it.
	 *
	 * @return array|null array( 'method', 'plan', 'site_name', 'hostname', 'verified' ), or null when not connected.
	 */
	public function account(): ?array {
		$connection = $this->store->load();
		if ( null === $connection ) {
			return null;
		}
		$cache = get_transient( self::ACCOUNT_CACHE );
		if ( is_array( $cache ) ) {
			return $cache;
		}
		$account = array(
			'method'    => 'oauth' === ( $connection['method'] ?? '' ) ? 'oauth' : 'api_key',
			'plan'      => null,
			'site_name' => null,
			'hostname'  => null,
			'verified'  => null,
		);
		$usage   = $this->client->request( 'GET', '/api/v1/usage' );
		if ( is_array( $usage ) && is_string( $usage['plan'] ?? null ) ) {
			$account['plan'] = sanitize_text_field( $usage['plan'] );
		}
		$domains = $this->client->request( 'GET', '/api/v1/domains' );
		$list    = is_array( $domains ) && is_array( $domains['domains'] ?? null ) ? $domains['domains'] : array();
		foreach ( $list as $domain ) {
			if ( is_array( $domain ) && ( $domain['id'] ?? null ) === ( $connection['domain_id'] ?? '' ) ) {
				$account['site_name'] = is_string( $domain['displayName'] ?? null ) ? sanitize_text_field( $domain['displayName'] ) : null;
				$account['hostname']  = is_string( $domain['hostname'] ?? null ) ? sanitize_text_field( $domain['hostname'] ) : null;
				$account['verified']  = 'verified' === ( $domain['verificationStatus'] ?? '' );
			}
		}
		if ( is_array( $usage ) || is_array( $domains ) ) {
			set_transient( self::ACCOUNT_CACHE, $account, self::TTL );
		}
		return $account;
	}

	/**
	 * Forget the cached account facts.
	 */
	public function forget_account(): void {
		delete_transient( self::ACCOUNT_CACHE );
	}

	/**
	 * Trim a GET /api/v1/reports/:id answer to the fields the page shows.
	 *
	 * @param array $data The `data` of a complete report.
	 * @return array id, url, scanned_at (Unix time or 0), overall, content, crawlability, band,
	 *               agents (array( 'detected', 'bonus' ) or null), sections (content, crawlability,
	 *               agents: lists of array( 'name', 'verdict', 'reason', 'fix' )), ai_view
	 *               (markdown, truncated, screenshot, gap) and report_link.
	 */
	public static function summarize( array $data ): array {
		$scores = is_array( $data['scores'] ?? null ) ? $data['scores'] : array();
		$id     = is_string( $data['reportId'] ?? null ) && 1 === preg_match( self::UUID, $data['reportId'] ) ? $data['reportId'] : '';
		$when   = is_string( $data['completedAt'] ?? null ) ? $data['completedAt'] : ( $data['createdAt'] ?? '' );
		$time   = is_string( $when ) ? strtotime( $when ) : false;
		$ar     = is_array( $scores['agentReadiness'] ?? null ) ? $scores['agentReadiness'] : null;
		return array(
			'id'           => $id,
			'url'          => is_string( $data['url'] ?? null ) ? esc_url_raw( $data['url'] ) : '',
			'scanned_at'   => false === $time ? 0 : $time,
			'overall'      => self::score( $scores['overall'] ?? 0 ),
			'content'      => self::score( $scores['content'] ?? 0 ),
			'crawlability' => self::score( $scores['crawlability'] ?? 0 ),
			'agents'       => null === $ar ? null : array(
				'detected' => max( 0, (int) ( is_numeric( $ar['detectedCount'] ?? null ) ? $ar['detectedCount'] : 0 ) ),
				'bonus'    => max( 0, (int) ( is_numeric( $ar['bonusPoints'] ?? null ) ? $ar['bonusPoints'] : 0 ) ),
			),
			'sections'     => self::sections( $scores, $data['fixChecklist'] ?? null ),
			'ai_view'      => self::ai_view( $data['aiView'] ?? null ),
			'report_link'  => '' === $id ? '' : GENREADY_API_BASE . '/report/' . $id,
		);
	}

	/**
	 * Map a failed report call to a state.
	 *
	 * @param ApiError $error The failure.
	 * @return array|ApiError
	 */
	private function state_for_error( ApiError $error ) {
		if ( 404 === $error->status ) {
			// The report is gone (deleted, or not this site's). user_message() would say the site was removed.
			$this->forget();
			return array( 'state' => 'none' );
		}
		if ( 422 === $error->status && in_array( $error->code, array( 'ANALYSIS_FAILED', 'ANALYSIS_CANCELLED' ), true ) ) {
			$message = Text::cap( sanitize_text_field( $error->message ), self::MAX_REASON );
			return array(
				'state'   => 'failed',
				'message' => '' !== $message ? $message : __( 'The scan did not finish. Scan again.', 'genready' ),
			);
		}
		return $error;
	}

	/**
	 * The three sections of checks.
	 *
	 * @param array $scores    The report's scores.
	 * @param mixed $checklist The fix checklist (null on plans without it).
	 * @return array array( 'content' => array[], 'crawlability' => array[], 'agents' => array[] ).
	 */
	private static function sections( array $scores, $checklist ): array {
		$problems = array();
		foreach ( is_array( $checklist ) ? $checklist : array() as $item ) {
			if ( is_array( $item ) && is_string( $item['metric'] ?? null ) && is_string( $item['problem'] ?? null ) && ! isset( $problems[ $item['metric'] ] ) ) {
				$problems[ $item['metric'] ] = $item['problem'];
			}
		}
		$sections = array(
			'content'      => array(),
			'crawlability' => array(),
			'agents'       => array(),
		);
		$details  = is_array( $scores['details'] ?? null ) ? $scores['details'] : array();
		foreach ( $details as $key => $detail ) {
			if ( ! is_array( $detail ) ) {
				continue;
			}
			$key      = (string) $key;
			$name     = is_string( $detail['name'] ?? null ) ? $detail['name'] : $key;
			$verdict  = self::verdict( $detail['verdict'] ?? '' );
			$reason   = $problems[ $name ] ?? ( is_string( $detail['description'] ?? null ) ? $detail['description'] : '' );
			$category = $detail['category'] ?? null;
			if ( 'content' !== $category && 'crawlability' !== $category ) {
				$category = in_array( $key, self::CRAWLABILITY_KEYS, true ) ? 'crawlability' : 'content';
			}
			$sections[ $category ][] = self::check( $name, $verdict, $reason, $key );
		}
		$standards = is_array( $scores['agentReadiness']['standards'] ?? null ) ? $scores['agentReadiness']['standards'] : array();
		foreach ( $standards as $key => $standard ) {
			if ( ! is_array( $standard ) ) {
				continue;
			}
			$status = $standard['status'] ?? '';
			// A standard adds bonus points, so a missing one is a warning, not a failure.
			$verdict              = 'detected' === $status ? 'pass' : 'warn';
			$sections['agents'][] = self::check(
				is_string( $standard['name'] ?? null ) ? $standard['name'] : (string) $key,
				$verdict,
				is_string( $standard['message'] ?? null ) ? $standard['message'] : '',
				(string) $key
			);
		}
		return $sections;
	}

	/**
	 * One check line.
	 *
	 * @param string $name    Check name.
	 * @param string $verdict pass, warn or fail.
	 * @param string $reason  One-line reason from the server.
	 * @param string $key     Detail key.
	 */
	private static function check( string $name, string $verdict, string $reason, string $key ): array {
		return array(
			'name'    => Text::cap( wp_strip_all_tags( $name ), 120 ),
			'verdict' => $verdict,
			'reason'  => Text::cap( (string) preg_replace( '/\s+/u', ' ', $reason ), self::MAX_REASON ),
			'fix'     => 'pass' !== $verdict && isset( self::FIX_FOR[ $key ] ) ? self::FIX_FOR[ $key ] : null,
		);
	}

	/**
	 * The AI view block.
	 *
	 * @param mixed $view The report's aiView.
	 * @return array array( 'markdown' => string|null, 'truncated' => bool, 'screenshot' => string|null, 'gap' => int|null ).
	 */
	private static function ai_view( $view ): array {
		$view = is_array( $view ) ? $view : array();
		$gap  = is_array( $view['jsGap'] ?? null ) ? ( $view['jsGap']['gapPercent'] ?? null ) : null;
		return array(
			'markdown'   => is_string( $view['markdown'] ?? null ) ? $view['markdown'] : null,
			'truncated'  => true === ( $view['markdownTruncated'] ?? false ),
			'screenshot' => is_string( $view['screenshotUrl'] ?? null ) ? self::screenshot_url( $view['screenshotUrl'], GENREADY_API_BASE ) : null,
			'gap'        => is_numeric( $gap ) ? max( 0, min( 100, (int) round( (float) $gap ) ) ) : null,
		);
	}

	/**
	 * A screenshot URL the page may load: on the GenReady host, over HTTPS, or
	 * over HTTP only when the GenReady address itself is HTTP (a local server).
	 *
	 * @param string $url  URL from the report.
	 * @param string $base The GenReady address (GENREADY_API_BASE).
	 * @return string|null The URL, or null when the page must not load it.
	 */
	public static function screenshot_url( string $url, string $base ): ?string {
		$parts = wp_parse_url( $url );
		$api   = wp_parse_url( $base );
		if ( ! is_array( $parts ) || ! is_array( $api ) || ! isset( $parts['scheme'], $parts['host'], $api['scheme'], $api['host'] ) ) {
			return null;
		}
		$scheme = strtolower( $parts['scheme'] );
		$secure = 'https' === $scheme || ( 'http' === $scheme && 'http' === strtolower( $api['scheme'] ) );
		if ( ! $secure || strtolower( $parts['host'] ) !== strtolower( $api['host'] ) ) {
			return null;
		}
		$clean = esc_url_raw( $url, array( 'https', 'http' ) );
		return '' === $clean ? null : $clean;
	}

	/**
	 * A verdict the page knows.
	 *
	 * @param mixed $verdict Server verdict.
	 */
	private static function verdict( $verdict ): string {
		return in_array( $verdict, array( 'pass', 'warn', 'fail' ), true ) ? $verdict : 'warn';
	}

	/**
	 * A score from 0 to 100.
	 *
	 * @param mixed $value Server value.
	 */
	private static function score( $value ): int {
		return is_numeric( $value ) ? max( 0, min( 100, (int) round( (float) $value ) ) ) : 0;
	}
}
