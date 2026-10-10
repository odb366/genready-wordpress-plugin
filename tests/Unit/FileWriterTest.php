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

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->root = sys_get_temp_dir() . '/genready-fw-' . bin2hex( random_bytes( 6 ) ) . '/';
		$this->dir  = $this->root . 'home/';
		mkdir( $this->dir, 0777, true );
		$this->fs     = new TempFilesystem();
		$this->method = 'direct';
		$dir          = $this->dir;
		$fs           = $this->fs;
		$method       = &$this->method;
		Monkey\Functions\when( 'get_home_path' )->justReturn( $dir );
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
		foreach ( array( $this->dir, $this->root ) as $dir ) {
			foreach ( (array) glob( $dir . '{,.}*', GLOB_BRACE ) as $file ) {
				if ( is_file( $file ) ) {
					unlink( $file );
				}
			}
			rmdir( $dir );
		}
		unset( $GLOBALS['wp_filesystem'] );
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_writes_and_reads_back_in_the_home_path(): void {
		$files = new FileWriter();
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
		$files = new FileWriter();
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
		$files        = new FileWriter();
		$this->assertFalse( $files->can_write( 'robots.txt' ) );
		$this->assertFalse( $files->write( 'robots.txt', 'x' ) );
		$this->assertFileDoesNotExist( $this->dir . 'robots.txt' );
	}

	public function test_can_write_needs_a_writable_target(): void {
		file_put_contents( $this->dir . 'robots.txt', 'R' );
		chmod( $this->dir . 'robots.txt', 0444 );
		$files = new FileWriter();
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
		$this->assertFalse( ( new FileWriter() )->write( 'robots.txt', 'NEW' ) );
	}
}
