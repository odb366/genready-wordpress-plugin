<?php
/**
 * Plugin Name:       GenReady – AI Search & Agent Readiness
 * Plugin URI:        https://genready.ai/wordpress
 * Description:       See how AI search engines and agents read your site, and apply GenReady's fixes for robots.txt, llms.txt and structured data.
 * Version:           1.1.0
 * Requires at least: 6.5
 * Requires PHP:      7.4
 * Author:            GenReady
 * Author URI:        https://genready.ai
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       genready
 *
 * @package GenReady
 */

defined( 'ABSPATH' ) || exit;

define( 'GENREADY_VERSION', '1.1.0' );
define( 'GENREADY_FILE', __FILE__ );
define( 'GENREADY_DIR', __DIR__ );
if ( ! defined( 'GENREADY_API_BASE' ) ) {
	// Development only: set it in wp-config.php to use a local GenReady server.
	define( 'GENREADY_API_BASE', 'https://genready.ai' );
}

spl_autoload_register(
	static function ( $class_name ) {
		if ( 0 !== strpos( $class_name, 'GenReady\\' ) || 0 === strpos( $class_name, 'GenReady\\Tests\\' ) ) {
			return;
		}
		$path = GENREADY_DIR . '/src/' . str_replace( '\\', '/', substr( $class_name, 9 ) ) . '.php';
		if ( is_readable( $path ) ) {
			require $path;
		}
	}
);

if ( ! defined( 'GENREADY_TESTING' ) && ! defined( 'GENREADY_UNINSTALLING' ) ) {
	add_action( 'plugins_loaded', array( \GenReady\Plugin::instance(), 'boot' ) );
}
