<?php
/**
 * Files in the site root.
 *
 * @package GenReady
 */

namespace GenReady\Fixes;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes the few files GenReady may change, and only in the site's
 * root folder (where the front index.php lives). It writes only through the
 * direct filesystem method, and checks every write by reading it back. When
 * the root folder cannot be found, it reads and writes nothing. On a multisite
 * network every site shares the root folder, so only a network admin on the
 * main site may write or delete there; every site may still read.
 */
class FileWriter {
	/** The only file names this class touches. */
	const ALLOWED = array( 'robots.txt', 'llms.txt' );

	/**
	 * Tell whether the site's root folder was found.
	 */
	public function root_known(): bool {
		return null !== $this->dir();
	}

	/**
	 * Tell whether this request may change files in the root folder. On a
	 * multisite network the files belong to every site, so only a network
	 * admin on the main site may change them.
	 */
	public function may_write(): bool {
		return ! is_multisite() || ( is_main_site() && current_user_can( 'manage_network_options' ) );
	}

	/**
	 * Tell whether the file exists.
	 *
	 * @param string $name File name from ALLOWED.
	 */
	public function exists( string $name ): bool {
		$path = $this->path( $name );
		return null !== $path && file_exists( $path );
	}

	/**
	 * Read the file.
	 *
	 * @param string $name File name from ALLOWED.
	 * @return string|null Null when the name is not allowed or the file cannot be read.
	 */
	public function read( string $name ): ?string {
		$path = $this->path( $name );
		if ( null === $path || ! is_file( $path ) || ! is_readable( $path ) ) {
			return null;
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a local file in the site root; WP_Filesystem can need FTP credentials only to read it.
		$content = file_get_contents( $path );
		return false === $content ? null : $content;
	}

	/**
	 * Tell whether WordPress can write the file without asking for credentials.
	 *
	 * @param string $name File name from ALLOWED. When it exists, the file must be writable; else the folder.
	 */
	public function can_write( string $name = '' ): bool {
		$dir = $this->dir();
		if ( null === $dir ) {
			return false;
		}
		$this->load_admin_functions();
		if ( 'direct' !== get_filesystem_method( array(), $dir ) ) {
			return false;
		}
		$path = '' === $name ? null : $this->path( $name );
		if ( null !== $path && file_exists( $path ) ) {
			return wp_is_writable( $path );
		}
		return wp_is_writable( $dir );
	}

	/**
	 * Write the file through a temp file in the same folder, move it into
	 * place, then read it back and compare the hash. A failed step leaves the
	 * old file and no temp file.
	 *
	 * @param string $name    File name from ALLOWED.
	 * @param string $content New content.
	 * @return bool False when the name is not allowed, this request may not write, the write failed,
	 *              or the file holds other bytes.
	 */
	public function write( string $name, string $content ): bool {
		$path = $this->path( $name );
		if ( null === $path || ! $this->may_write() ) {
			return false;
		}
		$filesystem = $this->filesystem();
		if ( null === $filesystem ) {
			return false;
		}
		$mode = defined( 'FS_CHMOD_FILE' ) ? FS_CHMOD_FILE : 0644;
		$temp = dirname( $path ) . '/.genready-tmp-' . bin2hex( random_bytes( 8 ) );
		if ( ! $filesystem->put_contents( $temp, $content, $mode ) || ! $filesystem->move( $temp, $path, true ) ) {
			$filesystem->delete( $temp );
			return false;
		}
		clearstatcache( true, $path );
		$back = $this->read( $name );
		return null !== $back && hash_equals( hash( 'sha256', $content ), hash( 'sha256', $back ) );
	}

	/**
	 * Delete the file.
	 *
	 * @param string $name File name from ALLOWED.
	 * @return bool False when the name is not allowed, this request may not write, or the file is still there.
	 */
	public function delete( string $name ): bool {
		$path = $this->path( $name );
		if ( null === $path || ! $this->may_write() ) {
			return false;
		}
		if ( ! file_exists( $path ) ) {
			return true;
		}
		$filesystem = $this->filesystem();
		if ( null === $filesystem || ! $filesystem->delete( $path ) ) {
			return false;
		}
		clearstatcache( true, $path );
		return ! file_exists( $path );
	}

	/**
	 * Full path of an allowed file, or null for any other name.
	 *
	 * @param string $name File name.
	 */
	private function path( string $name ): ?string {
		if ( basename( $name ) !== $name || ! in_array( $name, self::ALLOWED, true ) ) {
			return null;
		}
		$dir = $this->dir();
		return null === $dir ? null : $dir . $name;
	}

	/**
	 * The site's root folder, with a trailing slash, or null when it cannot be found.
	 *
	 * Outside wp-admin, get_home_path() reads SCRIPT_FILENAME. When WordPress has
	 * its own folder, it can then return '/', so its answer is checked first.
	 */
	private function dir(): ?string {
		$abspath = trailingslashit( wp_normalize_path( $this->abspath() ) );
		$home    = rtrim( (string) wp_parse_url( home_url(), PHP_URL_PATH ), '/' );
		$site    = rtrim( (string) wp_parse_url( site_url(), PHP_URL_PATH ), '/' );
		if ( $home === $site ) {
			return $abspath;
		}
		$this->load_admin_functions();
		$root = $this->checked_root( get_home_path(), $abspath );
		if ( null !== $root ) {
			return $root;
		}
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- a server path, only passed to realpath() and compared, never printed or stored.
		$script = isset( $_SERVER['SCRIPT_FILENAME'] ) ? wp_unslash( $_SERVER['SCRIPT_FILENAME'] ) : '';
		if ( is_string( $script ) && 'index.php' === basename( $script ) ) {
			$real = realpath( $script );
			return false === $real ? null : $this->checked_root( dirname( $real ), $abspath );
		}
		return null;
	}

	/**
	 * Accept a folder as the root only when it holds index.php, is not the
	 * filesystem root, and is ABSPATH or a parent of it.
	 *
	 * @param mixed  $dir     Candidate folder.
	 * @param string $abspath ABSPATH, normalized, with a trailing slash.
	 * @return string|null The real folder with a trailing slash, or null.
	 */
	private function checked_root( $dir, string $abspath ): ?string {
		if ( ! is_string( $dir ) || '' === $dir ) {
			return null;
		}
		$real      = realpath( $dir );
		$real_base = realpath( $abspath );
		if ( false === $real || false === $real_base || dirname( $real ) === $real ) {
			return null;
		}
		$root = trailingslashit( wp_normalize_path( $real ) );
		$base = trailingslashit( wp_normalize_path( $real_base ) );
		if ( 0 !== strpos( $base, $root ) || ! is_file( $root . 'index.php' ) ) {
			return null;
		}
		return $root;
	}

	/**
	 * The WordPress folder. A method, so tests can use a temp folder.
	 */
	protected function abspath(): string {
		return ABSPATH;
	}

	/**
	 * The filesystem object, only for the direct method.
	 *
	 * @return object|null WP_Filesystem_Direct, or null.
	 */
	private function filesystem() {
		$dir = $this->dir();
		if ( null === $dir ) {
			return null;
		}
		$this->load_admin_functions();
		if ( 'direct' !== get_filesystem_method( array(), $dir ) || ! WP_Filesystem( false, $dir ) ) {
			return null;
		}
		global $wp_filesystem;
		return is_object( $wp_filesystem ) ? $wp_filesystem : null;
	}

	/**
	 * Load get_home_path() and the filesystem functions outside wp-admin.
	 */
	private function load_admin_functions(): void {
		if ( ! function_exists( 'get_home_path' ) || ! function_exists( 'get_filesystem_method' ) || ! function_exists( 'WP_Filesystem' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
	}
}
