<?php
/**
 * SEO plugins that make their own llms.txt.
 *
 * @package GenReady
 */

namespace GenReady\Compat;

use GenReady\Fixes\FileWriter;

defined( 'ABSPATH' ) || exit;

/**
 * Finds out whether Yoast SEO, Rank Math or All in One SEO makes llms.txt on
 * this site, so GenReady never replaces a file another plugin keeps up to
 * date. Each plugin counts as the owner only when its version constant shows
 * a version that has the feature AND its own setting is on. The settings and
 * versions come from each plugin's source (see the notes on the methods).
 *
 * The class is not final so tests can replace it. Its methods do not catch
 * exceptions: the caller treats an exception as "unknown", never as "no owner".
 */
class SeoPlugins {
	/**
	 * Files in the site root.
	 *
	 * @var FileWriter
	 */
	private $files;

	/**
	 * Build the check.
	 *
	 * @param FileWriter|null $files Files in the site root. All in One SEO is judged by its llms.txt file when its setting is unclear.
	 */
	public function __construct( ?FileWriter $files = null ) {
		$this->files = $files ?? new FileWriter();
	}

	/**
	 * Which of the three plugins are active, by their version constant.
	 *
	 * @return string[] A subset of 'yoast', 'rankmath' and 'aioseo'.
	 */
	public function active(): array {
		$active = array();
		foreach ( array(
			'yoast'    => 'WPSEO_VERSION',
			'rankmath' => 'RANK_MATH_VERSION',
			'aioseo'   => 'AIOSEO_VERSION',
		) as $id => $constant ) {
			if ( defined( $constant ) ) {
				$active[] = $id;
			}
		}
		return $active;
	}

	/**
	 * The SEO plugin that makes llms.txt now, if any.
	 *
	 * @return array|null array( 'plugin' => 'Yoast SEO', 'setting' => 'Where to turn it off' ), or null.
	 */
	public function llms_txt_owner() {
		if ( $this->yoast() ) {
			return array(
				'plugin'  => 'Yoast SEO',
				'setting' => 'Yoast SEO → Settings → Site features → AI tools → llms.txt',
			);
		}
		if ( $this->rank_math() ) {
			return array(
				'plugin'  => 'Rank Math SEO',
				'setting' => 'Rank Math SEO → Dashboard → LLMS Txt (turn the module off)',
			);
		}
		if ( $this->aioseo() ) {
			return array(
				'plugin'  => 'All in One SEO',
				'setting' => 'All in One SEO → Sitemaps → LLMs.txt',
			);
		}
		return null;
	}

	/**
	 * Yoast SEO 25.3 (June 2025) added llms.txt. The toggle is the 'enable_llms_txt'
	 * key (default false) in the 'wpseo' option.
	 */
	private function yoast(): bool {
		if ( ! $this->version_at_least( 'WPSEO_VERSION', '25.3' ) ) {
			return false;
		}
		$option = get_option( 'wpseo' );
		return is_array( $option ) && $this->is_on( $option['enable_llms_txt'] ?? false );
	}

	/**
	 * Rank Math has an 'llms-txt' module since 1.0.250. A module is on when its
	 * ID is in the 'rank_math_modules' option.
	 */
	private function rank_math(): bool {
		if ( ! $this->version_at_least( 'RANK_MATH_VERSION', '1.0.250' ) ) {
			return false;
		}
		$modules = get_option( 'rank_math_modules' );
		return is_array( $modules ) && in_array( 'llms-txt', $modules, true );
	}

	/**
	 * All in One SEO keeps its options as JSON in 'aioseo_options'. The switch is
	 * sitemap.llms.enable. Its code default is off, but its docs say the feature
	 * is on by default, so a missing or unreadable value is not proof either way.
	 * AIOSEO serves llms.txt only as a static file: it writes the file when the
	 * feature is on and deletes it when the feature is off. So the rule is:
	 * - the setting is explicitly on: owner;
	 * - the setting is explicitly off: never the owner;
	 * - the setting is missing or unreadable: owner only when a physical llms.txt
	 *   exists in the site root, or when the root folder is unknown (a file cannot
	 *   be ruled out, so GenReady does not overwrite).
	 * Releases before 4.9.0 are not in the plugin repository's tags, so the first
	 * version is not confirmed; 4.8.0 is a safe floor.
	 */
	private function aioseo(): bool {
		if ( ! $this->version_at_least( 'AIOSEO_VERSION', '4.8.0' ) ) {
			return false;
		}
		$options = get_option( 'aioseo_options' );
		if ( is_string( $options ) ) {
			$options = json_decode( $options, true );
		}
		$enable = is_array( $options ) && isset( $options['sitemap']['llms'] ) && is_array( $options['sitemap']['llms'] )
			? ( $options['sitemap']['llms']['enable'] ?? null )
			: null;
		if ( is_bool( $enable ) || 0 === $enable || 1 === $enable || '0' === $enable || '1' === $enable ) {
			return $this->is_on( $enable );
		}
		return ! $this->files->root_known() || $this->files->exists( 'llms.txt' );
	}

	/**
	 * Tell whether a plugin's version constant is at or above a version.
	 *
	 * @param string $constant Constant name.
	 * @param string $minimum  First version with the feature.
	 */
	private function version_at_least( string $constant, string $minimum ): bool {
		if ( ! defined( $constant ) ) {
			return false;
		}
		$version = constant( $constant );
		return is_string( $version ) && version_compare( $version, $minimum, '>=' );
	}

	/**
	 * Read a stored on/off value.
	 *
	 * @param mixed $value Stored value.
	 */
	private function is_on( $value ): bool {
		return true === $value || 1 === $value || '1' === $value || 'true' === $value;
	}
}
