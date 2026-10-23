<?php
/**
 * Plugin bootstrap class.
 *
 * @package GenReady
 */

namespace GenReady;

use GenReady\Admin\FixCards;
use GenReady\Admin\Page;
use GenReady\Admin\Reports;
use GenReady\Admin\Rest;
use GenReady\Api\Client;
use GenReady\Connection\ApiKey;
use GenReady\Compat\Caches;
use GenReady\Compat\SeoPlugins;
use GenReady\Connection\OAuth;
use GenReady\Connection\SiteSetup;
use GenReady\Connection\TokenStore;
use GenReady\Fixes\FileWriter;
use GenReady\Fixes\FixStore;
use GenReady\Fixes\JsonLdFix;
use GenReady\Fixes\LlmsTxtFix;
use GenReady\Fixes\RobotsFix;
use GenReady\Onboarding\AccountCreator;
use GenReady\Onboarding\AnonymousScans;
use GenReady\Onboarding\Proof;
use GenReady\Verification\MetaTag;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the plugin's hooks.
 */
final class Plugin {
	/**
	 * Single instance.
	 *
	 * @var Plugin|null
	 */
	private static $instance = null;
	/**
	 * Token storage.
	 *
	 * @var TokenStore|null
	 */
	private $store = null;
	/**
	 * OAuth connector.
	 *
	 * @var OAuth|null
	 */
	private $oauth = null;
	/**
	 * Scans made without an account.
	 *
	 * @var AnonymousScans|null
	 */
	private $scans = null;

	/**
	 * Return the single instance.
	 */
	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Create the shared objects and register hooks. No network call is made.
	 */
	public function boot(): void {
		$this->store = new TokenStore();
		// The client needs the refresh callable and OAuth needs the client, so the callable looks OAuth up when it runs.
		$client      = new Client(
			$this->store,
			function (): bool {
				return $this->oauth->refresh();
			}
		);
		$this->oauth = new OAuth( $this->store, $client );
		$api_key     = new ApiKey( $this->store, $client );

		$meta_tag   = new MetaTag();
		$caches     = new Caches();
		$site_setup = new SiteSetup( $this->store, $client, $meta_tag, $caches );
		add_action( 'wp_head', array( $meta_tag, 'render' ), 1 );

		$fixes = new FixStore();
		$files = new FileWriter();
		// The domain ID is read only when a preview runs, so serving robots.txt or llms.txt never decrypts the connection.
		$domain_id = function (): string {
			$connection = $this->store->load();
			$domain_id  = is_array( $connection ) ? ( $connection['domain_id'] ?? '' ) : '';
			return is_string( $domain_id ) ? $domain_id : '';
		};
		$seo       = new SeoPlugins( $files );
		$robots    = new RobotsFix( $fixes, $files, $client, $caches, $domain_id );
		$llms      = new LlmsTxtFix( $fixes, $files, $client, $caches, $seo, $domain_id );
		$jsonld    = new JsonLdFix( $fixes, $client, $caches, $seo, $domain_id );
		add_filter( 'robots_txt', array( $robots, 'filter' ), PHP_INT_MAX, 2 );
		add_action( 'parse_request', array( $llms, 'serve' ), 0 );
		add_action( 'init', array( $jsonld, 'register_meta' ) );
		add_action( 'wp_head', array( $jsonld, 'print_script' ), 20 );
		// Late, so the node meets every node the SEO plugin and other plugins added.
		add_filter( SeoPlugins::SCHEMA_FILTERS['yoast'], array( $jsonld, 'yoast' ), 99 );
		add_filter( SeoPlugins::SCHEMA_FILTERS['rankmath'], array( $jsonld, 'rankmath' ), 99 );
		add_filter( SeoPlugins::SCHEMA_FILTERS['aioseo'], array( $jsonld, 'aioseo' ), 99 );

		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
		// The one-time proof for "Create my free account". It is public; see Proof::register().
		$proof = new Proof();
		add_action( 'rest_api_init', array( $proof, 'register' ) );
		$this->scans = new AnonymousScans( $this->store, $client );
		$account     = new AccountCreator( $this->store, $client, $proof, $this->scans, $meta_tag, $caches );
		add_action( 'admin_init', array( $this, 'maybe_handle_oauth_callback' ) );
		add_action( 'admin_init', array( $this, 'register_privacy_text' ) );

		// The admin page and its REST routes. Building them makes no call and reads no option.
		$reports = new Reports( $this->store, $client, $this->scans );
		$cards   = new FixCards(
			$fixes,
			$robots,
			$llms,
			$jsonld,
			$files,
			function (): bool {
				return null !== $this->store->load();
			}
		);
		$rest    = new Rest( $this->store, $client, $this->oauth, $api_key, $site_setup, $meta_tag, $caches, $reports, $cards, $this->scans, $account );
		add_action( 'rest_api_init', array( $rest, 'register' ) );
		( new Page( $this->store, $reports, $cards, $this->scans ) )->register();
	}

	/**
	 * OAuth connector. The browser tests' helper plugin uses it to refresh the token.
	 */
	public function oauth(): OAuth {
		return $this->oauth;
	}

	/**
	 * Register the REST route that serves the OAuth client document.
	 */
	public function register_routes(): void {
		register_rest_route(
			'genready/v1',
			'/client',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'serve_client_document' ),
				// Public on purpose: GenReady fetches the document without credentials, and it holds no secret.
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Answer the client document request. GenReady fetches it while a connect
	 * flow runs (authorize and token exchange) and later on each refresh, so it
	 * is served while the site is connected or a connect flow started in the
	 * last 15 minutes. Otherwise the route answers like a missing one.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function serve_client_document() {
		if ( ! $this->client_document_needed() ) {
			return new \WP_Error( 'rest_no_route', __( 'No route was found matching the URL and request method.', 'genready' ), array( 'status' => 404 ) );
		}
		return new \WP_REST_Response( $this->oauth->client_document(), 200, array( 'Cache-Control' => 'public, max-age=300' ) );
	}

	/**
	 * Tell whether GenReady may need the client document now.
	 */
	private function client_document_needed(): bool {
		if ( null !== $this->store->load() ) {
			return true;
		}
		$since = get_option( OAuth::PENDING_OPTION );
		return is_numeric( $since ) && abs( time() - (int) $since ) <= OAuth::PENDING_TTL;
	}

	/**
	 * Add the plugin's text to the suggested privacy policy (Settings, Privacy).
	 */
	public function register_privacy_text(): void {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}
		wp_add_privacy_policy_content(
			'GenReady',
			wp_kses_post( wpautop( __( 'The GenReady plugin does not collect data about visitors to this site and sets no cookies. Site administrators who use the plugin send site data to genready.ai, as the plugin readme describes.', 'genready' ) ) )
		);
	}

	/**
	 * Finish the OAuth connection when GenReady sends the admin back.
	 */
	public function maybe_handle_oauth_callback(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- OAuth state is verified in handle_callback(); a nonce cannot survive the round trip through genready.ai.
		if ( ! isset( $_GET['page'], $_GET['genready_cb'] ) || 'genready' !== sanitize_text_field( wp_unslash( $_GET['page'] ) ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$query = array();
		foreach ( array( 'state', 'code', 'error', 'error_description' ) as $key ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- see above.
			if ( isset( $_GET[ $key ] ) && is_string( $_GET[ $key ] ) ) {
				// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- see above.
				$query[ $key ] = sanitize_text_field( wp_unslash( $_GET[ $key ] ) );
			}
		}
		$user_id = get_current_user_id();
		$result  = $this->oauth->handle_callback( $user_id, $query );
		if ( ! empty( $result['ok'] ) ) {
			// A new connection ends the scans made without an account (spec R20).
			$this->scans->clear();
		}
		set_transient( 'genready_notice_' . $user_id, $result, 60 );
		wp_safe_redirect( admin_url( 'admin.php?page=genready' ) );
		$this->end_request();
	}

	/**
	 * Stop the request after a redirect. Unit tests must not exit.
	 */
	private function end_request(): void {
		if ( defined( 'GENREADY_TESTING' ) ) {
			return;
		}
		exit;
	}
}
