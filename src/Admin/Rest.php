<?php
/**
 * REST routes for the admin page.
 *
 * @package GenReady
 */

namespace GenReady\Admin;

use GenReady\Api\ApiError;
use GenReady\Api\Client;
use GenReady\Compat\Caches;
use GenReady\Connection\ApiKey;
use GenReady\Connection\OAuth;
use GenReady\Connection\SiteSetup;
use GenReady\Connection\TokenStore;
use GenReady\Fixes\JsonLdFix;
use GenReady\Support\Text;
use GenReady\Verification\MetaTag;

defined( 'ABSPATH' ) || exit;

/**
 * The genready/v1 routes the admin script calls. Every route needs
 * manage_options; wp.apiFetch sends the wp_rest nonce. Errors are WP_Error
 * objects with status 402, 429 or 400 only: apiFetch treats 401 and 403 as an
 * expired login. Fix previews stay on the server in a transient for each
 * user, and apply reads them from there, never from the request.
 */
final class Rest {
	/** REST namespace. */
	const NS = 'genready/v1';
	/** Seconds a preview is kept for apply. */
	const PREVIEW_TTL = 900;
	/** User meta that hides the Plugins screen notice. */
	const NOTICE_DISMISSED = 'genready_notice_dismissed';
	/** Error codes that mean the plan does not include the action. */
	const PLAN_CODES = array( 'PLAN_REQUIRED', 'DOMAIN_LIMIT_REACHED', 'CREDITS_EXHAUSTED' );
	/** Longest form value accepted for a JSON-LD property. */
	const MAX_VALUE = 2048;

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
	 * OAuth connector.
	 *
	 * @var OAuth
	 */
	private $oauth;
	/**
	 * API key connector.
	 *
	 * @var ApiKey
	 */
	private $api_key;
	/**
	 * Verification and scans.
	 *
	 * @var SiteSetup
	 */
	private $setup;
	/**
	 * Verification meta tag.
	 *
	 * @var MetaTag
	 */
	private $meta;
	/**
	 * Page cache purger.
	 *
	 * @var Caches
	 */
	private $caches;
	/**
	 * Report and account data.
	 *
	 * @var Reports
	 */
	private $reports;
	/**
	 * Fix cards and the fixes behind them.
	 *
	 * @var FixCards
	 */
	private $cards;

	/**
	 * Build the routes.
	 *
	 * @param TokenStore $store   Token storage.
	 * @param Client     $client  API client.
	 * @param OAuth      $oauth   OAuth connector.
	 * @param ApiKey     $api_key API key connector.
	 * @param SiteSetup  $setup   Verification and scans.
	 * @param MetaTag    $meta    Verification meta tag.
	 * @param Caches     $caches  Page caches.
	 * @param Reports    $reports Report and account data.
	 * @param FixCards   $cards   Fix cards.
	 */
	public function __construct( TokenStore $store, Client $client, OAuth $oauth, ApiKey $api_key, SiteSetup $setup, MetaTag $meta, Caches $caches, Reports $reports, FixCards $cards ) {
		$this->store   = $store;
		$this->client  = $client;
		$this->oauth   = $oauth;
		$this->api_key = $api_key;
		$this->setup   = $setup;
		$this->meta    = $meta;
		$this->caches  = $caches;
		$this->reports = $reports;
		$this->cards   = $cards;
	}

	/**
	 * Only administrators may call these routes.
	 */
	public static function permission(): bool {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Register the routes. Hooked on rest_api_init.
	 */
	public function register(): void {
		$boolean = array(
			'type'              => 'boolean',
			'sanitize_callback' => 'rest_sanitize_boolean',
			'validate_callback' => 'rest_validate_request_arg',
		);
		$this->route( '/connect/oauth', 'POST', 'connect_oauth', array( 'send_email' => array_merge( $boolean, array( 'default' => false ) ) ) );
		$this->route(
			'/connect/key',
			'POST',
			'connect_key',
			array(
				'key' => array(
					'type'              => 'string',
					'required'          => true,
					'sanitize_callback' => 'sanitize_text_field',
					'validate_callback' => array( $this, 'validate_short_text' ),
				),
			)
		);
		$this->route( '/disconnect', 'POST', 'disconnect' );
		$this->route( '/verify', 'POST', 'verify' );
		$this->route( '/scan', 'POST', 'scan' );
		$this->route( '/report', 'GET', 'report' );
		$this->route( '/verification-tag/remove', 'POST', 'remove_tag' );
		$this->route( '/notice/dismiss', 'POST', 'dismiss_notice' );
		$this->route( '/fixes/(?P<kind>robots|llms)/download', 'POST', 'download' );
		$this->route(
			'/fixes/(?P<kind>robots|llms|jsonld)/(?P<action>preview|apply|undo|live-check)',
			'POST',
			'fix',
			array(
				'content_signal' => $boolean,
				'as_file'        => $boolean,
				'tagline'        => array(
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_textarea_field',
					'validate_callback' => array( $this, 'validate_long_text' ),
				),
				'values'         => array(
					'type'              => 'object',
					'sanitize_callback' => array( $this, 'sanitize_values' ),
					'validate_callback' => array( $this, 'validate_values' ),
				),
			)
		);
	}

	/**
	 * Start the OAuth connection: return the URL that sends the admin to GenReady.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array|\WP_Error array( 'url' => string ), or an error while a connection exists.
	 */
	public function connect_oauth( $request ) {
		if ( null !== $this->store->load() ) {
			return $this->error( __( 'Disconnect GenReady first.', 'genready' ), 'CONNECTED' );
		}
		return array( 'url' => $this->oauth->authorize_url( get_current_user_id(), true === $request->get_param( 'send_email' ) ) );
	}

	/**
	 * Connect with a one-site API key.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array|\WP_Error
	 */
	public function connect_key( $request ) {
		$key    = $request->get_param( 'key' );
		$result = $this->api_key->connect( is_string( $key ) ? $key : '' );
		if ( isset( $result['error'] ) ) {
			return $this->failure( $result );
		}
		$this->reports->forget_account();
		return array( 'ok' => true );
	}

	/**
	 * End the connection. Applied fixes and the verification tag stay.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array array( 'ok' => true ).
	 */
	public function disconnect( $request ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- REST callback signature.
		$this->oauth->revoke();
		$this->reports->forget();
		$this->reports->forget_account();
		foreach ( FixCards::KINDS as $kind ) {
			delete_transient( $this->preview_key( $kind ) );
		}
		return array( 'ok' => true );
	}

	/**
	 * Show the verification tag and ask GenReady to check it.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array|\WP_Error array( 'verified' => bool, 'detail' => string ).
	 */
	public function verify( $request ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- REST callback signature.
		if ( null === $this->store->load() ) {
			return $this->reconnect();
		}
		$result = $this->setup->verify();
		if ( isset( $result['error'] ) ) {
			return $this->failure( $result );
		}
		$this->reports->forget_account();
		return array(
			'verified' => true === ( $result['verified'] ?? false ),
			'detail'   => (string) ( $result['detail'] ?? '' ),
		);
	}

	/**
	 * Start a scan of the home page.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array|\WP_Error array( 'report_id' => string ).
	 */
	public function scan( $request ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- REST callback signature.
		if ( null === $this->store->load() ) {
			return $this->reconnect();
		}
		$result = $this->setup->scan();
		if ( isset( $result['error'] ) ) {
			return $this->failure( $result );
		}
		return array( 'report_id' => (string) $result['report_id'] );
	}

	/**
	 * The state of the last scan, for the page's poll.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array|\WP_Error See Reports::state().
	 */
	public function report( $request ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- REST callback signature.
		if ( '' === $this->reports->last_report_id() ) {
			return array( 'state' => 'none' );
		}
		if ( null === $this->store->load() ) {
			return $this->reconnect();
		}
		$state = $this->reports->state();
		return $state instanceof ApiError ? $this->api_error( $state ) : $state;
	}

	/**
	 * Stop printing the verification tag and purge the home page.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array array( 'ok' => true ).
	 */
	public function remove_tag( $request ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- REST callback signature.
		$this->meta->disable();
		$this->caches->purge_url( home_url( '/' ) );
		return array( 'ok' => true );
	}

	/**
	 * Hide the Plugins screen notice for this user.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array array( 'ok' => true ).
	 */
	public function dismiss_notice( $request ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- REST callback signature.
		update_user_meta( get_current_user_id(), self::NOTICE_DISMISSED, 1 );
		return array( 'ok' => true );
	}

	/**
	 * The file of this user's stored preview, for a site where WordPress
	 * cannot write it. It reads the transient only and makes no call.
	 *
	 * @param \WP_REST_Request $request Request with the kind from the route.
	 * @return array|\WP_Error array( 'filename' => string, 'content' => string ).
	 */
	public function download( $request ) {
		$kind = (string) $request->get_param( 'kind' );
		if ( ! in_array( $kind, array( 'robots', 'llms' ), true ) ) {
			return $this->error( __( 'GenReady could not do this. Try again.', 'genready' ) );
		}
		$preview = get_transient( $this->preview_key( $kind ) );
		$content = is_array( $preview ) ? ( $preview['content'] ?? null ) : null;
		if ( ! is_string( $content ) || '' === $content ) {
			return $this->error( __( 'The preview expired. Preview again.', 'genready' ), 'PREVIEW_EXPIRED' );
		}
		return array(
			'filename' => 'robots' === $kind ? 'robots.txt' : 'llms.txt',
			'content'  => $content,
		);
	}

	/**
	 * Preview, apply, undo or live-check one fix.
	 *
	 * @param \WP_REST_Request $request Request with the kind and action from the route.
	 * @return array|\WP_Error
	 */
	public function fix( $request ) {
		$kind   = (string) $request->get_param( 'kind' );
		$action = (string) $request->get_param( 'action' );
		if ( ! in_array( $kind, FixCards::KINDS, true ) ) {
			return $this->error( __( 'GenReady could not do this. Try again.', 'genready' ) );
		}
		switch ( $action ) {
			case 'preview':
				return $this->preview( $kind, $request );
			case 'apply':
				return $this->apply( $kind, $request );
			case 'undo':
				return $this->undo( $kind );
			case 'live-check':
				return $this->live_check( $kind );
		}
		return $this->error( __( 'GenReady could not do this. Try again.', 'genready' ) );
	}

	/**
	 * Keep only the JSON-LD form values the fix can fill. The fix checks each value.
	 *
	 * @param mixed $values Raw values.
	 * @return array Property => string.
	 */
	public function sanitize_values( $values ): array {
		$clean = array();
		if ( ! is_array( $values ) ) {
			return $clean;
		}
		foreach ( array_keys( JsonLdFix::FILLABLE ) as $prop ) {
			if ( isset( $values[ $prop ] ) && is_string( $values[ $prop ] ) && strlen( $values[ $prop ] ) <= self::MAX_VALUE ) {
				$clean[ $prop ] = sanitize_textarea_field( $values[ $prop ] );
			}
		}
		return $clean;
	}

	/**
	 * Check the JSON-LD form values.
	 *
	 * @param mixed $values Raw values.
	 */
	public function validate_values( $values ): bool {
		return is_array( $values ) && count( $values ) <= count( JsonLdFix::FILLABLE );
	}

	/**
	 * Check a short text such as an API key.
	 *
	 * @param mixed $value Raw value.
	 */
	public function validate_short_text( $value ): bool {
		return is_string( $value ) && strlen( $value ) <= 200;
	}

	/**
	 * Check a longer text such as the site summary.
	 *
	 * @param mixed $value Raw value.
	 */
	public function validate_long_text( $value ): bool {
		return is_string( $value ) && strlen( $value ) <= 2000;
	}

	/**
	 * Ask GenReady for a fix, keep it on the server and return what the card shows.
	 *
	 * @param string           $kind    Fix kind.
	 * @param \WP_REST_Request $request Request.
	 * @return array|\WP_Error
	 */
	private function preview( string $kind, $request ) {
		if ( null === $this->store->load() ) {
			return $this->reconnect();
		}
		if ( 'robots' === $kind ) {
			$result = $this->cards->robots()->preview( false !== $request->get_param( 'content_signal' ) );
		} elseif ( 'llms' === $kind ) {
			$tagline = $request->get_param( 'tagline' );
			$result  = $this->cards->llms()->preview( is_string( $tagline ) ? array( 'tagline' => $tagline ) : array() );
		} else {
			$url    = $this->reports->cached_url();
			$result = $this->cards->jsonld()->preview(
				$this->reports->last_report_id(),
				null === $url ? home_url( '/' ) : $url,
				$this->sanitize_values( $request->get_param( 'values' ) )
			);
		}
		if ( $result instanceof ApiError ) {
			if ( 'PLAN_REQUIRED' === $result->code && $this->cards->is_applied( $kind, $this->target( $kind ) ) ) {
				return $this->error( __( 'This file does not update on the Free plan.', 'genready' ), 'PLAN_REQUIRED', 402 );
			}
			return $this->api_error( $result );
		}
		if ( isset( $result['error'] ) ) {
			return $this->failure( $result );
		}
		set_transient( $this->preview_key( $kind ), $result, self::PREVIEW_TTL );
		return $this->display( $kind, $result );
	}

	/**
	 * Apply the preview kept on the server.
	 *
	 * @param string           $kind    Fix kind.
	 * @param \WP_REST_Request $request Request. Only as_file (llms.txt) and values (JSON-LD) are read.
	 * @return array|\WP_Error
	 */
	private function apply( string $kind, $request ) {
		$key     = $this->preview_key( $kind );
		$preview = get_transient( $key );
		if ( ! is_array( $preview ) ) {
			return $this->error( __( 'The preview expired. Preview again.', 'genready' ), 'PREVIEW_EXPIRED' );
		}
		if ( 'robots' === $kind ) {
			$result = $this->cards->robots()->apply( $preview );
		} elseif ( 'llms' === $kind ) {
			$result = $this->cards->llms()->apply( $preview, true === $request->get_param( 'as_file' ) );
		} else {
			$values = $this->sanitize_values( $request->get_param( 'values' ) );
			if ( array() !== $values ) {
				$preview = $this->cards->jsonld()->fill( $preview, $values );
			}
			$result = isset( $preview['error'] ) ? $preview : $this->cards->jsonld()->apply( $preview );
		}
		if ( isset( $result['error'] ) ) {
			return $this->failure( $result );
		}
		delete_transient( $key );
		return $this->done( $kind );
	}

	/**
	 * Undo a fix. It needs no connection.
	 *
	 * @param string $kind Fix kind.
	 * @return array|\WP_Error
	 */
	private function undo( string $kind ) {
		if ( 'robots' === $kind ) {
			$result = $this->cards->robots()->undo();
		} elseif ( 'llms' === $kind ) {
			$result = $this->cards->llms()->undo();
		} else {
			$result = $this->cards->jsonld()->undo( $this->target( $kind ) );
		}
		if ( isset( $result['error'] ) ) {
			return $this->failure( $result );
		}
		return $this->done( $kind );
	}

	/**
	 * Ask GenReady whether the live file is the applied file, and keep the answer in the fix record.
	 * The answer is stored only when the record still holds the same file after the call: an undo
	 * or a new apply during the call wins.
	 *
	 * @param string $kind Fix kind.
	 * @return array|\WP_Error
	 */
	private function live_check( string $kind ) {
		$connection = $this->store->load();
		$domain_id  = is_array( $connection ) ? ( $connection['domain_id'] ?? '' ) : '';
		if ( ! is_string( $domain_id ) || '' === $domain_id ) {
			return $this->reconnect();
		}
		if ( 'jsonld' === $kind ) {
			return $this->error( __( 'GenReady checks only robots.txt and llms.txt on your live site.', 'genready' ) );
		}
		$record  = $this->cards->store()->get( $kind );
		$content = $record['content'] ?? null;
		if ( ! is_string( $content ) || '' === $content ) {
			return $this->error( __( 'Apply the fix first.', 'genready' ), 'NOT_APPLIED' );
		}
		$path   = 'robots' === $kind ? '/robots.txt' : rtrim( (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ), '/' ) . '/llms.txt';
		$result = $this->client->request(
			'POST',
			'/api/v1/domains/' . rawurlencode( $domain_id ) . '/live-check',
			array(
				'path'   => $path,
				'sha256' => hash( 'sha256', $content ),
			)
		);
		if ( $result instanceof ApiError ) {
			return $this->api_error( $result );
		}
		$fresh = $this->cards->store()->get( $kind );
		$same  = ( $fresh['mode'] ?? null ) === ( $record['mode'] ?? null )
			&& is_string( $fresh['content'] ?? null )
			&& hash_equals( hash( 'sha256', $content ), hash( 'sha256', $fresh['content'] ) );
		if ( ! $same ) {
			return $this->done( $kind );
		}
		$record            = $fresh;
		$cache             = is_array( $result['cache'] ?? null ) ? $result['cache'] : array();
		$record['live']    = array(
			'status' => is_int( $result['status'] ?? null ) ? $result['status'] : null,
			'match'  => true === ( $result['match'] ?? false ),
			'cache'  => array(
				'age'           => is_int( $cache['age'] ?? null ) ? $cache['age'] : null,
				'cfCacheStatus' => is_string( $cache['cfCacheStatus'] ?? null ) ? substr( sanitize_text_field( $cache['cfCacheStatus'] ), 0, 32 ) : null,
			),
		);
		$record['live_at'] = time();
		$this->cards->store()->update( $kind, $record );
		return $this->done( $kind );
	}

	/**
	 * The answer after a change: ok and the new card.
	 *
	 * @param string $kind Fix kind.
	 */
	private function done( string $kind ): array {
		return array(
			'ok'   => true,
			'card' => $this->cards->card( $kind, $this->target( $kind ) ),
		);
	}

	/**
	 * The page the JSON-LD fix is for. Other kinds need no target.
	 *
	 * @param string $kind Fix kind.
	 */
	private function target( string $kind ): array {
		return 'jsonld' === $kind ? $this->cards->jsonld_target( $this->reports->cached_url() ) : array();
	}

	/**
	 * The fields of a preview the browser may see. The file content and the
	 * JSON-LD node stay in the transient.
	 *
	 * @param string $kind    Fix kind.
	 * @param array  $preview The preview.
	 */
	private function display( string $kind, array $preview ): array {
		$display = array(
			'diff'            => is_string( $preview['diff'] ?? null ) ? $preview['diff'] : '',
			'summary'         => Text::strings( $preview['summary'] ?? null ),
			'notes'           => Text::strings( $preview['notes'] ?? null ),
			'changed'         => false !== ( $preview['changed'] ?? true ),
			'needsHumanInput' => true === ( $preview['needsHumanInput'] ?? false ),
			'missing'         => Text::strings( $preview['missing'] ?? null ),
			'blocked_props'   => Text::strings( $preview['blocked_props'] ?? null ),
			'external'        => true === ( $preview['external'] ?? false ),
			'mode'            => is_string( $preview['mode'] ?? null ) ? $preview['mode'] : '',
		);
		if ( 'jsonld' === $kind ) {
			// The node is shown as text so the admin sees what will be added; apply never reads it from the browser.
			$display['type']     = is_string( $preview['type'] ?? null ) ? $preview['type'] : '';
			$display['fillable'] = Text::strings( $preview['fillable'] ?? null );
			$display['code']     = (string) wp_json_encode( $preview['node'] ?? array(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		}
		return $display;
	}

	/**
	 * Register one admin route.
	 *
	 * @param string $path     Route path.
	 * @param string $method   HTTP method.
	 * @param string $callback Method of this class.
	 * @param array  $args     Arguments.
	 */
	private function route( string $path, string $method, string $callback, array $args = array() ): void {
		register_rest_route(
			self::NS,
			$path,
			array(
				'methods'             => $method,
				'callback'            => array( $this, $callback ),
				'permission_callback' => array( self::class, 'permission' ),
				'args'                => $args,
			)
		);
	}

	/**
	 * The transient that holds this user's preview of a fix.
	 *
	 * @param string $kind Fix kind.
	 */
	private function preview_key( string $kind ): string {
		return 'genready_preview_' . $kind . '_' . get_current_user_id();
	}

	/**
	 * The error for a missing connection. No network call is made.
	 */
	private function reconnect(): \WP_Error {
		return $this->error( __( 'Reconnect GenReady.', 'genready' ), 'RECONNECT' );
	}

	/**
	 * A failed API call as a REST error with a plain message.
	 *
	 * @param ApiError $error The failure.
	 */
	private function api_error( ApiError $error ): \WP_Error {
		return $this->error( $error->user_message(), $error->code, self::status_for( $error->code, $error->status ) );
	}

	/**
	 * A refusal array from a unit as a REST error. SiteSetup and the file
	 * writes add 'code' (and SiteSetup 'status') so the script can show the
	 * next step.
	 *
	 * @param array $result array( 'error' => string, 'code'? => string, 'status'? => int|null ).
	 */
	private function failure( array $result ): \WP_Error {
		$code = is_string( $result['code'] ?? null ) && '' !== $result['code'] ? $result['code'] : 'GENREADY_ERROR';
		return $this->error( (string) $result['error'], $code, self::status_for( $code, $result['status'] ?? null ) );
	}

	/**
	 * The REST status for an error: 402 when the plan does not include the
	 * action, 429 when GenReady is busy, else 400.
	 *
	 * @param string   $code   Error code.
	 * @param int|null $status HTTP status from GenReady, if any.
	 */
	private static function status_for( string $code, $status ): int {
		if ( in_array( $code, self::PLAN_CODES, true ) ) {
			return 402;
		}
		return 429 === $status ? 429 : 400;
	}

	/**
	 * A REST error. The status is never 401 or 403.
	 *
	 * @param string $message Plain text for the admin.
	 * @param string $code    Machine code for the script.
	 * @param int    $status  400, 402 or 429.
	 */
	private function error( string $message, string $code = 'GENREADY_ERROR', int $status = 400 ): \WP_Error {
		return new \WP_Error(
			'genready_error',
			$message,
			array(
				'status'        => in_array( $status, array( 400, 402, 429 ), true ) ? $status : 400,
				'genready_code' => $code,
			)
		);
	}
}
