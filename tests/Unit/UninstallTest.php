<?php
namespace GenReady\Tests\Unit;

use Brain\Monkey;
use GenReady\Fixes\FixStore;
use GenReady\Tests\Fixtures\FakeWpdb;
use GenReady\Uninstaller;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/fixtures/FakeWpdb.php';
require_once dirname( __DIR__ ) . '/fixtures/fix-doubles.php';

class UninstallTest extends TestCase {
	/** @var array */
	private $options = array();
	/** @var FixStore */
	private $store;
	/** @var MemoryFiles */
	private $files;
	/** @var FakeWpdb */
	private $db;
	/** @var array<string, array> Recorded calls of the delete functions, by function name. */
	private $calls = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->options = array();
		$this->db      = new FakeWpdb();
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- test double for $wpdb.
		$GLOBALS['wpdb'] = $this->db;
		$opts            = &$this->options;
		Monkey\Functions\when( 'get_option' )->alias( function ( $k, $d = false ) use ( &$opts ) { return $opts[ $k ] ?? $d; } );
		Monkey\Functions\when( 'update_option' )->alias( function ( $k, $v ) use ( &$opts ) { $opts[ $k ] = $v; return true; } );
		Monkey\Functions\when( 'delete_option' )->alias( function ( $k ) use ( &$opts ) { unset( $opts[ $k ] ); return true; } );
		Monkey\Functions\when( 'is_multisite' )->justReturn( false );
		$this->calls = array();
		$calls       = &$this->calls;
		foreach ( array( 'delete_post_meta_by_key', 'delete_transient', 'delete_metadata' ) as $function ) {
			Monkey\Functions\when( $function )->alias( function () use ( &$calls, $function ) { $calls[ $function ][] = func_get_args(); return true; } );
		}
		$this->store = new FixStore();
		$this->files = new MemoryFiles();
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
		Monkey\tearDown();
		parent::tearDown();
	}

	private function file_record( string $content, $original ): array {
		return array(
			'mode'           => 'file',
			'content'        => $content,
			'written_sha256' => hash( 'sha256', $content ),
			'original'       => $original,
		);
	}

	private function uninstaller( ?callable $revoke = null ): Uninstaller {
		return new Uninstaller( $this->store, $this->files, $revoke ?? function () {} );
	}

	public function test_uninstall_restores_only_unedited_files_and_deletes_data(): void {
		$this->files->put( 'robots.txt', 'WRITTEN' );
		$this->files->put( 'llms.txt', 'EDITED' );
		$this->store->put( 'robots', $this->file_record( 'WRITTEN', 'ORIG' ) );
		$this->store->put( 'llms', $this->file_record( 'W2', null ) );
		$this->store->put( 'jsonld_home', array( 'content' => '{}' ) );
		foreach ( array( 'genready_connection', 'genready_settings', 'genready_meta', 'genready_fixes', 'genready_oauth_pending' ) as $option ) {
			$this->options[ $option ] = 'x';
		}
		$this->db->rows['genready_refresh_lock'] = '123';
		$this->db->rows['other_option']          = 'keep';

		$revoked = 0;
		$this->uninstaller( function () use ( &$revoked ) { ++$revoked; } )->run();
		$this->assertSame( array( array( '_genready_jsonld' ) ), $this->calls['delete_post_meta_by_key'] );
		$this->assertContains( array( 'genready_report_cache' ), $this->calls['delete_transient'] );

		$this->assertSame( 'ORIG', $this->files->read( 'robots.txt' ) );
		$this->assertSame( 'EDITED', $this->files->read( 'llms.txt' ) );
		$this->assertSame( 1, $revoked );
		foreach ( array( 'genready_connection', 'genready_fixes', 'genready_fix_robots', 'genready_fix_llms', 'genready_fix_jsonld_home', 'genready_meta', 'genready_settings', 'genready_oauth_pending' ) as $option ) {
			$this->assertArrayNotHasKey( $option, $this->options );
		}
		$this->assertArrayNotHasKey( 'genready_refresh_lock', $this->db->rows );
		$this->assertArrayHasKey( 'other_option', $this->db->rows );
	}

	public function test_uninstall_deletes_a_file_that_did_not_exist_before(): void {
		$this->files->put( 'llms.txt', 'W2' );
		$this->store->put( 'llms', $this->file_record( 'W2', null ) );
		$this->uninstaller()->run();
		$this->assertFalse( $this->files->exists( 'llms.txt' ) );
	}

	public function test_uninstall_leaves_edited_files(): void {
		$this->files->put( 'llms.txt', 'EDITED' );
		$this->files->put( 'robots.txt', 'HAND MADE' );
		$this->store->put( 'llms', $this->file_record( 'W2', null ) );
		$this->store->put( 'robots', $this->file_record( 'R', 'ORIG' ) );
		$this->uninstaller()->run();
		$this->assertSame( 'EDITED', $this->files->read( 'llms.txt' ) );
		$this->assertSame( 'HAND MADE', $this->files->read( 'robots.txt' ) );
		$this->assertArrayNotHasKey( 'genready_fix_llms', $this->options );
		$this->assertArrayNotHasKey( 'genready_fix_robots', $this->options );
	}

	public function test_uninstall_ignores_virtual_records_and_missing_files(): void {
		$this->files->put( 'robots.txt', 'SITE FILE' );
		$this->store->put( 'robots', array( 'mode' => 'virtual', 'content' => 'V', 'written_sha256' => hash( 'sha256', 'SITE FILE' ), 'original' => 'X' ) );
		$this->store->put( 'llms', $this->file_record( 'W2', 'OLD' ) );
		$this->uninstaller()->run();
		$this->assertSame( 'SITE FILE', $this->files->read( 'robots.txt' ) );
		$this->assertFalse( $this->files->exists( 'llms.txt' ) );
		$this->assertSame( 0, $this->files->writes );
	}

	public function test_uninstall_skips_files_when_root_unknown(): void {
		$this->files->root = false;
		$this->files->put( 'robots.txt', 'WRITTEN' );
		$this->store->put( 'robots', $this->file_record( 'WRITTEN', 'ORIG' ) );
		$this->uninstaller()->run();
		$this->assertSame( 'WRITTEN', $this->files->read( 'robots.txt' ) );
		$this->assertSame( 0, $this->files->writes );
		$this->assertArrayNotHasKey( 'genready_fix_robots', $this->options );
	}

	public function test_uninstall_deletes_prefixed_transients_and_user_meta(): void {
		$this->db->rows['_transient_genready_oauth_5']             = 'a';
		$this->db->rows['_transient_timeout_genready_oauth_5']     = '1';
		$this->db->rows['_transient_genready_notice_5']            = 'a';
		$this->db->rows['_transient_genready_preview_robots_5']    = 'a';
		$this->db->rows['_transient_timeout_genready_preview_robots_5'] = '1';
		$this->db->rows['_transient_genreadyX_lookalike']          = 'keep';
		$this->db->rows['_transient_other_plugin']                 = 'keep';
		$this->uninstaller()->run();
		$this->assertSame( array( array( 'user', 0, 'genready_notice_dismissed', '', true ) ), $this->calls['delete_metadata'] );
		$this->assertSame( array( '_transient_genreadyX_lookalike', '_transient_other_plugin' ), array_keys( $this->db->rows ) );
	}

	public function test_uninstall_continues_when_revoke_throws(): void {
		$this->options['genready_connection'] = 'x';
		$this->store->put( 'jsonld_home', array( 'content' => '{}' ) );
		$this->uninstaller( function () { throw new \RuntimeException( 'network down' ); } )->run();
		$this->assertCount( 1, $this->calls['delete_post_meta_by_key'] );
		$this->assertArrayNotHasKey( 'genready_connection', $this->options );
		$this->assertArrayNotHasKey( 'genready_fix_jsonld_home', $this->options );
	}

	public function test_multisite_runs_per_site(): void {
		Monkey\Functions\when( 'is_multisite' )->justReturn( true );
		Monkey\Functions\expect( 'get_sites' )->once()->with( array( 'fields' => 'ids', 'number' => 0 ) )->andReturn( array( 1, 2, 3 ) );
		$switched = array();
		Monkey\Functions\when( 'switch_to_blog' )->alias( function ( $id ) use ( &$switched ) { $switched[] = $id; return true; } );
		Monkey\Functions\expect( 'restore_current_blog' )->times( 3 );
		$revoked = 0;
		$this->uninstaller( function () use ( &$revoked ) { ++$revoked; } )->run();
		$this->assertCount( 3, $this->calls['delete_post_meta_by_key'] );
		$this->assertCount( 1, $this->calls['delete_metadata'] );
		$this->assertSame( array( 1, 2, 3 ), $switched );
		$this->assertSame( 3, $revoked );
	}

	public function test_multisite_restores_the_blog_when_a_site_fails(): void {
		Monkey\Functions\when( 'is_multisite' )->justReturn( true );
		Monkey\Functions\when( 'get_sites' )->justReturn( array( 1, 2 ) );
		Monkey\Functions\when( 'switch_to_blog' )->justReturn( true );
		Monkey\Functions\expect( 'restore_current_blog' )->times( 2 );
		Monkey\Functions\when( 'delete_post_meta_by_key' )->alias( function () { throw new \RuntimeException( 'db gone' ); } );
		$this->uninstaller()->run();
		$this->addToAssertionCount( 1 );
	}

	// Fix round A, G1: multisite file safety.

	/** Files with the real may_write() rule (is_multisite, is_main_site, manage_network_options). */
	private function network_files(): MemoryFiles {
		return new class() extends MemoryFiles {
			public function may_write(): bool {
				return \GenReady\Fixes\FileWriter::may_write();
			}
		};
	}

	public function test_multisite_never_restores_files_from_a_sub_sites_records(): void {
		Monkey\Functions\when( 'is_multisite' )->justReturn( true );
		Monkey\Functions\when( 'get_sites' )->justReturn( array( 2 ) );
		Monkey\Functions\when( 'switch_to_blog' )->justReturn( true );
		Monkey\Functions\when( 'restore_current_blog' )->justReturn( true );
		Monkey\Functions\when( 'is_main_site' )->justReturn( false );
		Monkey\Functions\when( 'current_user_can' )->justReturn( true );
		$this->files = $this->network_files();
		$this->files->put( 'robots.txt', 'WRITTEN' );
		$this->files->put( 'llms.txt', 'W2' );
		$this->store->put( 'robots', $this->file_record( 'WRITTEN', 'ORIG' ) );
		$this->store->put( 'llms', $this->file_record( 'W2', null ) );
		$this->uninstaller()->run();
		$this->assertSame( 'WRITTEN', $this->files->read( 'robots.txt' ) );
		$this->assertSame( 'W2', $this->files->read( 'llms.txt' ) );
		$this->assertSame( 0, $this->files->writes );
	}

	public function test_multisite_main_site_restores_files_for_a_network_admin(): void {
		Monkey\Functions\when( 'is_multisite' )->justReturn( true );
		Monkey\Functions\when( 'get_sites' )->justReturn( array( 1 ) );
		Monkey\Functions\when( 'switch_to_blog' )->justReturn( true );
		Monkey\Functions\when( 'restore_current_blog' )->justReturn( true );
		Monkey\Functions\when( 'is_main_site' )->justReturn( true );
		Monkey\Functions\when( 'current_user_can' )->alias( function ( $cap ) { return 'manage_network_options' === $cap; } );
		$this->files = $this->network_files();
		$this->files->put( 'robots.txt', 'WRITTEN' );
		$this->files->put( 'llms.txt', 'W2' );
		$this->store->put( 'robots', $this->file_record( 'WRITTEN', 'ORIG' ) );
		$this->store->put( 'llms', $this->file_record( 'W2', null ) );
		$this->uninstaller()->run();
		$this->assertSame( 'ORIG', $this->files->read( 'robots.txt' ) );
		$this->assertFalse( $this->files->exists( 'llms.txt' ) );
	}
}
