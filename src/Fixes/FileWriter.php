<?php
/**
 * Files in the site root.
 *
 * @package GenReady
 */

namespace GenReady\Fixes;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes the few files GenReady may change, and only in the folder
 * that holds WordPress's home (get_home_path()). It writes only through the
 * direct filesystem method, and checks every write by reading it back.
 */
class FileWriter {
	/** The only file names this class touches. */
	const ALLOWED = array( 'robots.txt', 'llms.txt' );

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
		$this->load_admin_functions();
		$dir = $this->dir();
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
	 * Write the file, then read it back and compare the hash.
	 *
	 * @param string $name    File name from ALLOWED.
	 * @param string $content New content.
	 * @return bool False when the name is not allowed, the write failed, or the file holds other bytes.
	 */
	public function write( string $name, string $content ): bool {
		$path = $this->path( $name );
		if ( null === $path ) {
			return false;
		}
		$filesystem = $this->filesystem();
		if ( null === $filesystem ) {
			return false;
		}
		$mode = defined( 'FS_CHMOD_FILE' ) ? FS_CHMOD_FILE : 0644;
		if ( ! $filesystem->put_contents( $path, $content, $mode ) ) {
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
	 */
	public function delete( string $name ): bool {
		$path = $this->path( $name );
		if ( null === $path ) {
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
		return $this->dir() . $name;
	}

	/**
	 * The folder that holds WordPress's home, with a trailing slash.
	 */
	private function dir(): string {
		$this->load_admin_functions();
		return trailingslashit( (string) get_home_path() );
	}

	/**
	 * The filesystem object, only for the direct method.
	 *
	 * @return object|null WP_Filesystem_Direct, or null.
	 */
	private function filesystem() {
		$this->load_admin_functions();
		$dir = $this->dir();
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
