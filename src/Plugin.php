<?php
/**
 * Plugin bootstrap class.
 *
 * @package GenReady
 */

namespace GenReady;

use GenReady\Api\Client;
use GenReady\Connection\ApiKey;
use GenReady\Connection\OAuth;
use GenReady\Connection\TokenStore;

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

		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
		add_action( 'admin_init', array( $this, 'maybe_handle_oauth_callback' ) );
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
