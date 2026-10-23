<?php
/**
 * The GenReady admin page under Settings.
 *
 * @package GenReady
 */

namespace GenReady\Admin;

use GenReady\Api\ApiError;
use GenReady\Connection\TokenStore;
use GenReady\Onboarding\AnonymousScans;
use GenReady\Onboarding\PageUrl;
use GenReady\Verification\MetaTag;

defined( 'ABSPATH' ) || exit;

/**
 * Registers Settings → GenReady with the Overview, Fixes and Connection tabs,
 * loads the script and style on that page only, and shows the one notice on
 * the Plugins screen.
 */
final class Page {
	/** Menu slug. */
	const SLUG = 'genready';
	/** Hook suffix of the page. */
	const HOOK = 'settings_page_genready';
	/** The tabs, in order. */
	const TABS = array( 'overview', 'fixes', 'connection' );
	/** Views that view() may render. */
	const VIEWS = array( 'overview', 'fixes', 'connection', 'start', 'scan-form', 'banner' );

	/**
	 * Token storage.
	 *
	 * @var TokenStore
	 */
	private $store;
	/**
	 * Report and account data.
	 *
	 * @var Reports
	 */
	private $reports;
	/**
	 * Fix cards.
	 *
	 * @var FixCards
	 */
	private $cards;
	/**
	 * Scans made without an account.
	 *
	 * @var AnonymousScans
	 */
	private $scans;
	/**
	 * The callback notice, read once per request. False until read.
	 *
	 * @var array|null|false
	 */
	private $notice = false;
	/**
	 * The report state, loaded once per request. Null until loaded.
	 *
	 * @var array|null
	 */
	private $state = null;
	/**
	 * The fix cards of the Fixes tab, built on the load hook. Null until built.
	 *
	 * @var array[]|null
	 */
	private $fix_cards = null;

	/**
	 * Build the page.
	 *
	 * @param TokenStore     $store   Token storage.
	 * @param Reports        $reports Report and account data.
	 * @param FixCards       $cards   Fix cards.
	 * @param AnonymousScans $scans   Scans made without an account.
	 */
	public function __construct( TokenStore $store, Reports $reports, FixCards $cards, AnonymousScans $scans ) {
		$this->store   = $store;
		$this->reports = $reports;
		$this->cards   = $cards;
		$this->scans   = $scans;
	}

	/**
	 * Hook the page into wp-admin.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'load-' . self::HOOK, array( $this, 'prepare' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'admin_notices', array( $this, 'plugins_notice' ) );
	}

	/**
	 * Add Settings → GenReady.
	 */
	public function add_menu(): void {
		add_options_page( 'GenReady', 'GenReady', 'manage_options', self::SLUG, array( $this, 'render' ) );
	}

	/**
	 * Build the fix cards before wp-admin prints anything. The robots.txt card
	 * runs do_robots(), which sends a Content-Type header.
	 */
	public function prepare(): void {
		if ( 'fixes' === $this->tab() && current_user_can( 'manage_options' ) ) {
			$this->fix_cards();
		}
	}

	/**
	 * Load the script and style on the GenReady page, and the dismiss script on
	 * the Plugins screen while its notice shows.
	 *
	 * @param string $hook Hook suffix of the current admin page.
	 */
	public function enqueue( $hook ): void {
		if ( 'plugins.php' === $hook && $this->notice_due() ) {
			wp_enqueue_script( 'wp-api-fetch' );
			wp_add_inline_script(
				'wp-api-fetch',
				"document.addEventListener('click',function(e){var t=e.target;if(t&&t.closest&&t.closest('.genready-notice .notice-dismiss')){window.wp.apiFetch({path:'/genready/v1/notice/dismiss',method:'POST'}).catch(function(){});}});"
			);
			return;
		}
		if ( self::HOOK !== $hook ) {
			return;
		}
		wp_enqueue_style( 'genready-admin', plugins_url( 'assets/admin.css', GENREADY_FILE ), array(), GENREADY_VERSION );
		wp_enqueue_script( 'genready-admin', plugins_url( 'assets/admin.js', GENREADY_FILE ), array( 'wp-api-fetch', 'wp-i18n' ), GENREADY_VERSION, true );
		wp_set_script_translations( 'genready-admin', 'genready' );
		$notice    = $this->notice();
		$connected = null !== $this->store->load();
		$overview  = 'overview' === $this->tab() && 'overview' === $this->view_name( 'overview', $connected );
		$state     = $overview ? $this->state() : array();
		$data      = array(
			'ns'            => Rest::NS,
			'connected'     => $connected,
			// An account notice means the site is verified already, so the verify and scan steps do not run.
			'justConnected' => $connected && is_array( $notice ) && ! empty( $notice['ok'] ) && empty( $notice['account'] ),
			'reportState'   => (string) ( $state['state'] ?? '' ),
			'scanId'        => $connected ? '' : (string) ( $state['scan_id'] ?? '' ),
			'overviewUrl'   => admin_url( 'options-general.php?page=' . self::SLUG ),
		);
		wp_add_inline_script( 'genready-admin', 'window.genreadyAdmin = ' . wp_json_encode( $data, JSON_HEX_TAG | JSON_HEX_AMP ) . ';', 'before' );
		if ( ! $connected ) {
			wp_enqueue_script( 'genready-onboarding', plugins_url( 'assets/onboarding.js', GENREADY_FILE ), array( 'genready-admin', 'wp-api-fetch', 'wp-i18n' ), GENREADY_VERSION, true );
			wp_set_script_translations( 'genready-onboarding', 'genready' );
		}
	}

	/**
	 * The one notice on the Plugins screen, until a scan is stored, the site
	 * connects, or the admin dismisses it.
	 */
	public function plugins_notice(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'plugins' !== $screen->id || ! $this->notice_due() ) {
			return;
		}
		printf(
			'<div class="notice notice-info is-dismissible genready-notice"><p>%1$s <a href="%2$s">%3$s</a></p></div>',
			esc_html__( 'See how AI search engines and agents read your site. No account needed.', 'genready' ),
			esc_url( admin_url( 'options-general.php?page=' . self::SLUG ) ),
			esc_html__( 'Open GenReady', 'genready' )
		);
	}

	/**
	 * Print the page.
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$tab       = $this->tab();
		$connected = null !== $this->store->load();
		echo '<div class="wrap genready-admin">';
		echo '<h1>' . esc_html__( 'GenReady', 'genready' ) . '</h1>';
		$this->print_notice();
		echo '<nav class="nav-tab-wrapper" aria-label="' . esc_attr__( 'GenReady sections', 'genready' ) . '">';
		$labels = array(
			'overview'   => __( 'Overview', 'genready' ),
			'fixes'      => __( 'Fixes', 'genready' ),
			'connection' => __( 'Connection', 'genready' ),
		);
		foreach ( self::TABS as $name ) {
			printf(
				'<a href="%1$s" class="nav-tab%2$s"%3$s>%4$s</a>',
				esc_url( admin_url( 'options-general.php?page=' . self::SLUG . '&tab=' . $name ) ),
				$tab === $name ? ' nav-tab-active' : '',
				$tab === $name ? ' aria-current="page"' : '',
				esc_html( $labels[ $name ] )
			);
		}
		echo '</nav>';
		echo '<div id="genready-setup" class="genready-setup" role="status" aria-live="polite" tabindex="-1" hidden></div>';
		echo self::view( $this->view_name( $tab, $connected ), $this->view_data( $tab, $connected ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the views escape every value.
		echo '</div>';
	}

	/**
	 * Render a view to a string. The view reads its data from $view.
	 *
	 * @param string $name One of VIEWS.
	 * @param array  $view Data for the view.
	 */
	public static function view( string $name, array $view ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- the included view reads $view.
		if ( ! in_array( $name, self::VIEWS, true ) ) {
			return '';
		}
		ob_start();
		include __DIR__ . '/views/' . $name . '.php';
		return (string) ob_get_clean();
	}

	/**
	 * The current tab.
	 */
	private function tab(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only tab switch.
		$tab = isset( $_GET['tab'] ) && is_string( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'overview';
		return in_array( $tab, self::TABS, true ) ? $tab : 'overview';
	}

	/**
	 * The view for a tab. Without a connection the Overview is the connect form
	 * while GenReady paused the onboarding, the first screen without a stored
	 * scan, and the anonymous report with the banner otherwise.
	 *
	 * @param string $tab       Current tab.
	 * @param bool   $connected Whether the site is connected.
	 */
	private function view_name( string $tab, bool $connected ): string {
		if ( 'overview' !== $tab || $connected ) {
			return $tab;
		}
		if ( AnonymousScans::paused() ) {
			return 'connection';
		}
		return array() === $this->scans->all() ? 'start' : 'overview';
	}

	/**
	 * The data of a tab's view.
	 *
	 * @param string $tab       Current tab.
	 * @param bool   $connected Whether the site is connected.
	 */
	private function view_data( string $tab, bool $connected ): array {
		$name = $this->view_name( $tab, $connected );
		if ( 'overview' === $name && $connected ) {
			return array(
				'connected' => true,
				'state'     => $this->state(),
			);
		}
		if ( 'start' === $name || 'overview' === $name ) {
			return array(
				'connected'   => false,
				'home'        => home_url( '/' ),
				'suggestions' => PageUrl::suggestions(),
				'email'       => $this->user_email(),
				'scans'       => $this->scans->all(),
				'state'       => 'overview' === $name ? $this->state() : array( 'state' => 'none' ),
				// The scripts loaded the only scan, GenReady said it is gone, and the list is empty now (spec 11).
				'notice'      => 'start' === $name && 'gone' === ( $this->state['state'] ?? '' ) ? (string) ( $this->state['message'] ?? '' ) : '',
			);
		}
		if ( 'fixes' === $tab ) {
			return array(
				'connected' => $connected,
				'cards'     => $this->fix_cards(),
			);
		}
		return array(
			'connected' => $connected,
			'account'   => $connected ? $this->reports->account() : null,
			'tag_on'    => MetaTag::is_valid( get_option( MetaTag::OPTION ) ),
			'paused'    => ! $connected && AnonymousScans::paused(),
		);
	}

	/**
	 * The fix cards, built once.
	 *
	 * @return array[]
	 */
	private function fix_cards(): array {
		if ( null === $this->fix_cards ) {
			$this->fix_cards = $this->cards->all( $this->cards->jsonld_target( $this->reports->cached_url() ) );
		}
		return $this->fix_cards;
	}

	/**
	 * The report state for the Overview tab, loaded once.
	 */
	private function state(): array {
		if ( null === $this->state ) {
			$state       = $this->reports->state( $this->requested_scan() );
			$this->state = $state instanceof ApiError
				? array(
					'state'   => 'error',
					'message' => $state->user_message(),
				)
				: $state;
		}
		return $this->state;
	}

	/**
	 * The scan the recent list asked for, or ''. Reports accepts only an ID from the plugin's own list.
	 */
	private function requested_scan(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only choice between stored scans.
		$id = isset( $_GET['scan'] ) && is_string( $_GET['scan'] ) ? sanitize_key( wp_unslash( $_GET['scan'] ) ) : '';
		return 1 === preg_match( AnonymousScans::UUID, $id ) ? $id : '';
	}

	/**
	 * The email of the current user for the banner, or ''.
	 */
	private function user_email(): string {
		$user  = wp_get_current_user();
		$email = is_object( $user ) && isset( $user->user_email ) ? (string) $user->user_email : '';
		return is_email( $email ) ? $email : '';
	}

	/**
	 * The line "GenReady's Terms and Privacy Policy apply." with its two links. Escaped.
	 */
	public static function terms(): string {
		return sprintf(
			/* translators: 1: link to the Terms, 2: link to the Privacy Policy. */
			esc_html__( "GenReady's %1\$s and %2\$s apply.", 'genready' ),
			'<a href="' . esc_url( 'https://genready.ai/terms' ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Terms', 'genready' ) . '</a>',
			'<a href="' . esc_url( 'https://genready.ai/privacy' ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Privacy Policy', 'genready' ) . '</a>'
		);
	}

	/**
	 * The notice the OAuth callback left for this user. It is read once, then deleted.
	 *
	 * @return array|null array( 'ok' => true, 'notice'? => string, 'account'? => true, 'email'? => string ) or array( 'error' => string ), or null.
	 */
	private function notice(): ?array {
		if ( false === $this->notice ) {
			$key          = 'genready_notice_' . get_current_user_id();
			$notice       = get_transient( $key );
			$this->notice = is_array( $notice ) ? $notice : null;
			if ( null !== $this->notice ) {
				delete_transient( $key );
			}
		}
		return $this->notice;
	}

	/**
	 * Print the callback notice, if any.
	 */
	private function print_notice(): void {
		$notice = $this->notice();
		if ( null === $notice ) {
			return;
		}
		if ( isset( $notice['error'] ) && is_string( $notice['error'] ) ) {
			printf( '<div class="notice notice-error"><p>%s</p></div>', esc_html( $notice['error'] ) );
			return;
		}
		if ( ! empty( $notice['ok'] ) && ! empty( $notice['account'] ) ) {
			printf( '<div class="notice notice-success"><p>%s</p></div>', esc_html__( 'Your free GenReady account is ready. This site is connected and verified.', 'genready' ) );
			printf(
				'<div class="notice notice-info"><p>%s</p></div>',
				esc_html(
					sprintf(
						/* translators: %s: the email address of the new account. */
						__( 'GenReady sent a link to %s. Use it to set your password.', 'genready' ),
						is_string( $notice['email'] ?? null ) ? $notice['email'] : ''
					)
				)
			);
			return;
		}
		if ( ! empty( $notice['ok'] ) ) {
			printf( '<div class="notice notice-success"><p>%s</p></div>', esc_html__( 'GenReady is connected.', 'genready' ) );
			if ( isset( $notice['notice'] ) && is_string( $notice['notice'] ) && '' !== $notice['notice'] ) {
				printf( '<div class="notice notice-warning"><p>%s</p></div>', esc_html( $notice['notice'] ) );
			}
		}
	}

	/**
	 * Whether the Plugins screen notice should show for this user.
	 */
	private function notice_due(): bool {
		return current_user_can( 'manage_options' )
			&& null === $this->store->load()
			&& ! get_user_meta( get_current_user_id(), Rest::NOTICE_DISMISSED, true )
			&& array() === $this->scans->all();
	}
}
