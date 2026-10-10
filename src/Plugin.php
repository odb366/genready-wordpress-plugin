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
	 * API client.
	 *
	 * @var Client|null
	 */
	private $client = null;
	/**
	 * OAuth connector.
	 *
	 * @var OAuth|null
	 */
	private $oauth = null;
	/**
	 * API key connector.
	 *
	 * @var ApiKey|null
	 */
	private $api_key = null;
	/**
	 * Verification meta tag.
	 *
	 * @var MetaTag|null
	 */
	private $meta_tag = null;
	/**
	 * Page cache purger.
	 *
	 * @var Caches|null
	 */
	private $caches = null;
	/**
	 * Verification and first scan.
	 *
	 * @var SiteSetup|null
	 */
	private $site_setup = null;
	/**
	 * Fix storage.
	 *
	 * @var FixStore|null
	 */
	private $fixes = null;
	/**
	 * Files in the site root.
	 *
	 * @var FileWriter|null
	 */
	private $files = null;
	/**
	 * The robots.txt fix.
	 *
	 * @var RobotsFix|null
	 */
	private $robots = null;
	/**
	 * The llms.txt fix.
	 *
	 * @var LlmsTxtFix|null
	 */
	private $llms = null;
	/**
	 * The JSON-LD fix.
	 *
	 * @var JsonLdFix|null
	 */
	private $jsonld = null;

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
		$this->client  = new Client(
			$this->store,
			function (): bool {
				return $this->oauth->refresh();
			}
		);
		$this->oauth   = new OAuth( $this->store, $this->client );
		$this->api_key = new ApiKey( $this->store, $this->client );

		$this->meta_tag   = new MetaTag();
		$this->caches     = new Caches();
		$this->site_setup = new SiteSetup( $this->store, $this->client, $this->meta_tag, $this->caches );
		add_action( 'wp_head', array( $this->meta_tag, 'render' ), 1 );

		$this->fixes = new FixStore();
		$this->files = new FileWriter();
		// The domain ID is read only when a preview runs, so serving robots.txt or llms.txt never decrypts the connection.
		$domain_id    = function (): string {
			$connection = $this->store->load();
			$domain_id  = is_array( $connection ) ? ( $connection['domain_id'] ?? '' ) : '';
			return is_string( $domain_id ) ? $domain_id : '';
		};
		$seo          = new SeoPlugins( $this->files );
		$this->robots = new RobotsFix( $this->fixes, $this->files, $this->client, $this->caches, $domain_id );
		$this->llms   = new LlmsTxtFix( $this->fixes, $this->files, $this->client, $this->caches, $seo, $domain_id );
		$this->jsonld = new JsonLdFix( $this->fixes, $this->client, $this->caches, $seo, $domain_id );
		add_filter( 'robots_txt', array( $this->robots, 'filter' ), PHP_INT_MAX, 2 );
		add_action( 'parse_request', array( $this->llms, 'serve' ), 0 );
		add_action( 'init', array( $this->jsonld, 'register_meta' ) );
		add_action( 'wp_head', array( $this->jsonld, 'print_script' ), 20 );
		// Late, so the node meets every node the SEO plugin and other plugins added.
		add_filter( SeoPlugins::SCHEMA_FILTERS['yoast'], array( $this->jsonld, 'yoast' ), 99 );
		add_filter( SeoPlugins::SCHEMA_FILTERS['rankmath'], array( $this->jsonld, 'rankmath' ), 99 );
		add_filter( SeoPlugins::SCHEMA_FILTERS['aioseo'], array( $this->jsonld, 'aioseo' ), 99 );

		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
		add_action( 'admin_init', array( $this, 'maybe_handle_oauth_callback' ) );

		// The admin page and its REST routes. Building them makes no call and reads no option.
		$reports = new Reports( $this->store, $this->client );
		$cards   = new FixCards( $this->fixes, $this->robots, $this->llms, $this->jsonld );
		$rest    = new Rest( $this->store, $this->client, $this->oauth, $this->api_key, $this->site_setup, $this->meta_tag, $this->caches, $reports, $cards );
		add_action( 'rest_api_init', array( $rest, 'register' ) );
		( new Page( $this->store, $reports, $cards ) )->register();
	}

	/**
	 * Token storage.
	 */
	public function store(): TokenStore {
		return $this->store;
	}

	/**
	 * API client.
	 */
	public function client(): Client {
		return $this->client;
	}

	/**
	 * OAuth connector.
	 */
	public function oauth(): OAuth {
		return $this->oauth;
	}

	/**
	 * API key connector.
	 */
	public function api_key(): ApiKey {
		return $this->api_key;
	}

	/**
	 * Verification meta tag.
	 */
	public function meta_tag(): MetaTag {
		return $this->meta_tag;
	}

	/**
	 * Page cache purger.
	 */
	public function caches(): Caches {
		return $this->caches;
	}

	/**
	 * Verification and first scan.
	 */
	public function site_setup(): SiteSetup {
		return $this->site_setup;
	}

	/**
	 * Fix storage.
	 */
	public function fixes(): FixStore {
		return $this->fixes;
	}

	/**
	 * Files in the site root.
	 */
	public function files(): FileWriter {
		return $this->files;
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
	 * Answer the client document request.
	 */
	public function serve_client_document(): \WP_REST_Response {
		return new \WP_REST_Response( $this->oauth->client_document(), 200, array( 'Cache-Control' => 'public, max-age=300' ) );
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
