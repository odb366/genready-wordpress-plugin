<?php
/**
 * Plugin bootstrap class.
 *
 * @package GenReady
 */

namespace GenReady;

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
	 * Return the single instance.
	 */
	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Register hooks.
	 */
	public function boot(): void {
		// Each unit registers its own hooks here. Later tasks add lines.
	}
}
