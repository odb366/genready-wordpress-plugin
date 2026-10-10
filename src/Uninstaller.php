<?php
/**
 * Removes everything the plugin stores when an admin deletes it.
 *
 * @package GenReady
 */

namespace GenReady;

use GenReady\Api\Client;
use GenReady\Connection\OAuth;
use GenReady\Connection\TokenStore;
use GenReady\Fixes\FileWriter;
use GenReady\Fixes\FixStore;

defined( 'ABSPATH' ) || exit;

/**
 * Runs from uninstall.php. Deactivating the plugin changes nothing; only
 * deleting it ends the connection, restores the files GenReady wrote and
 * removes the stored data. It makes one network call at most per site: the
 * OAuth revoke, which fails safe.
 */
class Uninstaller {
	/** Fix kinds that can own a file in the site root, mapped to the file name. */
	const FILE_KINDS = array(
		'robots' => 'robots.txt',
		'llms'   => 'llms.txt',
	);
	/** Options the plugin stores in each site. 'genready_fixes' is the name used before release. */
	const OPTIONS = array( 'genready_connection', 'genready_fix_robots', 'genready_fix_llms', 'genready_fix_jsonld_home', 'genready_fixes', 'genready_meta', 'genready_settings' );
	/** Transients with a fixed name. Per-user ones are removed by prefix. */
	const TRANSIENTS = array( 'genready_report_cache', 'genready_account_cache' );
	/** Post meta key of the per-post JSON-LD record. */
	const POST_META = '_genready_jsonld';
	/** User meta key of the dismissed notice. It is network-wide. */
	const USER_META = 'genready_notice_dismissed';

	/**
	 * Fix storage.
	 *
	 * @var FixStore
	 */
	private $fixes;
	/**
	 * Files in the site root.
	 *
	 * @var FileWriter
	 */
	private $files;
	/**
	 * Callable that ends the connection at GenReady and locally.
	 *
	 * @var callable
	 */
	private $revoke;

	/**
	 * Build the uninstaller.
	 *
	 * @param FixStore   $fixes  Fix storage.
	 * @param FileWriter $files  Files in the site root.
	 * @param callable   $revoke Ends the connection. It may throw.
	 */
	public function __construct( FixStore $fixes, FileWriter $files, callable $revoke ) {
		$this->fixes  = $fixes;
		$this->files  = $files;
		$this->revoke = $revoke;
	}

	/**
	 * Build the uninstaller with the real objects. Hooks are not registered.
	 */
	public static function create(): Uninstaller {
		$store  = new TokenStore();
		$oauth  = null;
		$client = new Client(
			$store,
			static function () use ( &$oauth ): bool {
				return $oauth->refresh();
			}
		);
		$oauth  = new OAuth( $store, $client );
		return new self(
			new FixStore(),
			new FileWriter(),
			static function () use ( $oauth ): void {
				$oauth->revoke();
			}
		);
	}

	/**
	 * Clean up every site, or the one site on a single install.
	 */
	public function run(): void {
		if ( is_multisite() ) {
			foreach ( get_sites(
				array(
					'fields' => 'ids',
					'number' => 0,
				)
			) as $site_id ) {
				switch_to_blog( (int) $site_id );
				try {
					$this->run_site();
				} catch ( \Throwable $e ) {
					unset( $e ); // One broken site must not stop the others.
				} finally {
					restore_current_blog();
				}
			}
		} else {
			$this->run_site();
		}
		// User meta is shared by the whole network, so it goes once.
		delete_metadata( 'user', 0, self::USER_META, '', true );
	}

	/**
	 * Clean up the current site.
	 */
	private function run_site(): void {
		try {
			call_user_func( $this->revoke );
		} catch ( \Throwable $e ) {
			unset( $e ); // A failed revoke never blocks the cleanup.
		}
		try {
			$this->restore_files();
		} catch ( \Throwable $e ) {
			unset( $e );
		}
		foreach ( self::OPTIONS as $option ) {
			delete_option( $option );
		}
		$this->delete_lock();
		delete_post_meta_by_key( self::POST_META );
		foreach ( self::TRANSIENTS as $transient ) {
			delete_transient( $transient );
		}
		$this->delete_prefixed_transients();
	}

	/**
	 * Put back the files GenReady wrote, but only those nobody edited since.
	 * On a multisite network the root folder is shared, so only the main site's
	 * records count, and only for a network admin. A sub-site's records never
	 * change the shared files.
	 */
	private function restore_files(): void {
		if ( ! $this->files->root_known() || ! $this->files->may_write() ) {
			return;
		}
		foreach ( self::FILE_KINDS as $kind => $name ) {
			$fix     = $this->fixes->get( $kind );
			$written = $fix['written_sha256'] ?? null;
			if ( 'file' !== ( $fix['mode'] ?? '' ) || ! is_string( $written ) || '' === $written ) {
				continue;
			}
			$current = $this->files->read( $name );
			if ( null === $current || ! hash_equals( $written, hash( 'sha256', $current ) ) ) {
				continue; // Missing or edited: leave it alone.
			}
			$original = $fix['original'] ?? null;
			if ( is_string( $original ) ) {
				$this->files->write( $name, $original );
			} else {
				$this->files->delete( $name );
			}
		}
	}

	/**
	 * Delete the refresh lock row. It is not an options-API row.
	 */
	private function delete_lock(): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- the lock row bypasses the options cache.
		$wpdb->delete( $wpdb->options, array( 'option_name' => TokenStore::LOCK ) );
	}

	/**
	 * Delete the per-user transients (OAuth state, notices, previews) by prefix.
	 */
	private function delete_prefixed_transients(): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- one-time cleanup; the table name is $wpdb->options.
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
				$wpdb->esc_like( '_transient_genready_' ) . '%',
				$wpdb->esc_like( '_transient_timeout_genready_' ) . '%'
			)
		);
	}
}
