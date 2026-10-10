<?php
namespace GenReady\Tests\Unit;

use Brain\Monkey;
use GenReady\Fixes\FileWriter;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/fixtures/fix-doubles.php';

class FileWriterTest extends TestCase {
	/** @var string Private temp folder that holds the home folder. */
	private $root;
	/** @var string */
	private $dir;
	/** @var TempFilesystem */
	private $fs;
	/** @var string */
	private $method = 'direct';
	/** @var string ABSPATH of the WordPress under test. */
	private $abspath;
	/** @var string */
	private $site_url = 'https://example.com';
	/** @var mixed */
	private $home_path;
	/** @var array */
	private $server = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->root = sys_get_temp_dir() . '/genready-fw-' . bin2hex( random_bytes( 6 ) ) . '/';
		$this->dir  = $this->root . 'home/';
		mkdir( $this->dir, 0777, true );
		file_put_contents( $this->dir . 'index.php', '<?php' );
		$this->server    = $_SERVER;
		$this->fs        = new TempFilesystem();
		$this->method    = 'direct';
		$this->abspath   = $this->dir;
		$this->site_url  = 'https://example.com';
		$this->home_path = $this->dir;
		$fs              = $this->fs;
		$method          = &$this->method;
		$site_url        = &$this->site_url;
		$home_path       = &$this->home_path;
		Monkey\Functions\when( 'get_home_path' )->alias( function () use ( &$home_path ) { return $home_path; } );
		Monkey\Functions\when( 'home_url' )->justReturn( 'https://example.com' );
		Monkey\Functions\when( 'site_url' )->alias( function () use ( &$site_url ) { return $site_url; } );
		Monkey\Functions\when( 'wp_parse_url' )->alias( 'parse_url' );
		Monkey\Functions\when( 'wp_normalize_path' )->alias( function ( $p ) { return str_replace( '\\', '/', $p ); } );
		Monkey\Functions\when( 'wp_unslash' )->returnArg( 1 );
		Monkey\Functions\when( 'trailingslashit' )->alias( function ( $s ) { return rtrim( $s, '/\\' ) . '/'; } );
		Monkey\Functions\when( 'wp_is_writable' )->alias( 'is_writable' );
		Monkey\Functions\when( 'get_filesystem_method' )->alias( function () use ( &$method ) { return $method; } );
		Monkey\Functions\when( 'WP_Filesystem' )->alias(
			function () use ( $fs ) {
				$GLOBALS['wp_filesystem'] = $fs;
				return true;
			}
		);
	}

	protected function tearDown(): void {
		$this->remove_tree( $this->root );
		$_SERVER = $this->server;
		unset( $GLOBALS['wp_filesystem'] );
		Monkey\tearDown();
		parent::tearDown();
	}

	private function remove_tree( string $dir ): void {
		foreach ( (array) scandir( $dir ) as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}
			$path = rtrim( $dir, '/' ) . '/' . $entry;
			if ( is_dir( $path ) && ! is_link( $path ) ) {
				$this->remove_tree( $path );
			} else {
				unlink( $path );
			}
		}
		rmdir( $dir );
	}

	private function writer(): TempFileWriter {
		$files          = new TempFileWriter();
		$files->abspath = $this->abspath;
		return $files;
	}

	/** WordPress in its own folder: home is the root, siteurl is /wp. */
	private function own_directory(): void {
		mkdir( $this->dir . 'wp' );
		$this->abspath  = $this->dir . 'wp/';
		$this->site_url = 'https://example.com/wp';
	}

	public function test_equal_home_and_site_paths_use_abspath(): void {
		$this->home_path = '/';
		$files           = $this->writer();
		$this->assertTrue( $files->root_known() );
		$this->assertTrue( $files->write( 'robots.txt', 'R' ) );
		$this->assertFileExists( $this->abspath . 'robots.txt' );
	}

	public function test_own_directory_uses_a_valid_home_path(): void {
		$this->own_directory();
		$files = $this->writer();
		$this->assertTrue( $files->write( 'robots.txt', 'R' ) );
		$this->assertFileExists( $this->dir . 'robots.txt' );
		$this->assertFileDoesNotExist( $this->abspath . 'robots.txt' );
	}

	public function test_own_directory_with_a_bad_home_path_falls_back_to_the_front_controller(): void {
		$this->own_directory();
		$this->home_path                 = '/';
		$_SERVER['SCRIPT_FILENAME']      = $this->dir . 'index.php';
		file_put_contents( $this->dir . 'robots.txt', 'PHYSICAL' );
		$files = $this->writer();
		$this->assertTrue( $files->root_known() );
		$this->assertTrue( $files->exists( 'robots.txt' ) );
		$this->assertSame( 'PHYSICAL', $files->read( 'robots.txt' ) );
	}

	public function test_no_valid_root_reads_and_writes_nothing(): void {
		$this->own_directory();
		file_put_contents( $this->dir . 'robots.txt', 'PHYSICAL' );
		$other = $this->root . 'other/';
		mkdir( $other );
		file_put_contents( $other . 'index.php', '<?php' );
		// A folder outside ABSPATH's parents, the filesystem root, and a script that is not index.php.
		foreach ( array( '/', $other, $this->root . 'missing/', null ) as $home_path ) {
			$this->home_path            = $home_path;
			$_SERVER['SCRIPT_FILENAME'] = '/usr/bin/php';
			$files                      = $this->writer();
			$this->assertFalse( $files->root_known() );
			$this->assertFalse( $files->exists( 'robots.txt' ) );
			$this->assertNull( $files->read( 'robots.txt' ) );
			$this->assertFalse( $files->can_write( 'robots.txt' ) );
			$this->assertFalse( $files->write( 'robots.txt', 'x' ) );
			$this->assertFalse( $files->delete( 'robots.txt' ) );
		}
		// A front controller outside ABSPATH's parents is refused too.
		$_SERVER['SCRIPT_FILENAME'] = $other . 'index.php';
		$this->assertFalse( $this->writer()->root_known() );
		// A folder without index.php is refused.
		unlink( $this->dir . 'index.php' );
		$this->home_path = $this->dir;
		$this->assertFalse( $this->writer()->root_known() );
		$this->assertSame( 'PHYSICAL', file_get_contents( $this->dir . 'robots.txt' ) );
		$this->assertSame( array(), $this->fs->modes );
	}

	public function test_writes_and_reads_back_in_the_home_path(): void {
		$files = $this->writer();
		$this->assertFalse( $files->exists( 'robots.txt' ) );
		$this->assertNull( $files->read( 'robots.txt' ) );
		$this->assertTrue( $files->can_write( 'robots.txt' ) );
		$this->assertTrue( $files->write( 'robots.txt', "User-agent: *\n" ) );
		$this->assertTrue( $files->exists( 'robots.txt' ) );
		$this->assertSame( "User-agent: *\n", $files->read( 'robots.txt' ) );
		$this->assertSame( "User-agent: *\n", file_get_contents( $this->dir . 'robots.txt' ) );
		$this->assertSame( array( 0644 ), $this->fs->modes );
		$this->assertTrue( $files->delete( 'robots.txt' ) );
		$this->assertFileDoesNotExist( $this->dir . 'robots.txt' );
	}

	public function test_refuses_names_outside_the_list(): void {
		$files = $this->writer();
		foreach ( array( '../x', 'wp-config.php', 'sub/robots.txt', '/robots.txt', 'ROBOTS.TXT', 'robots.txt/' ) as $name ) {
			$this->assertFalse( $files->write( $name, 'x' ), $name );
			$this->assertNull( $files->read( $name ), $name );
			$this->assertFalse( $files->exists( $name ), $name );
			$this->assertFalse( $files->delete( $name ), $name );
		}
		$this->assertSame( array(), $this->fs->modes );
		$this->assertFileDoesNotExist( $this->root . 'x' );
		$this->assertTrue( $files->write( 'llms.txt', 'L' ) );
	}

	public function test_does_not_write_without_the_direct_method(): void {
		$this->method = 'ftpext';
		$files        = $this->writer();
		$this->assertFalse( $files->can_write( 'robots.txt' ) );
		$this->assertFalse( $files->write( 'robots.txt', 'x' ) );
		$this->assertFileDoesNotExist( $this->dir . 'robots.txt' );
	}

	public function test_can_write_needs_a_writable_target(): void {
		file_put_contents( $this->dir . 'robots.txt', 'R' );
		chmod( $this->dir . 'robots.txt', 0444 );
		$files = $this->writer();
		if ( is_writable( $this->dir . 'robots.txt' ) ) {
			chmod( $this->dir . 'robots.txt', 0644 );
			$this->markTestSkipped( 'Running as a user who can write read-only files.' );
		}
		$this->assertFalse( $files->can_write( 'robots.txt' ) );
		$this->assertTrue( $files->can_write( 'llms.txt' ) );
		chmod( $this->dir . 'robots.txt', 0644 );
	}

	public function test_write_detects_read_back_mismatch(): void {
		$this->fs->corrupt = true;
		$this->assertFalse( ( $this->writer() )->write( 'robots.txt', 'NEW' ) );
	}
}
