<?php
namespace GenReady\Tests\Unit;

use Brain\Monkey;
use GenReady\Api\ApiError;
use GenReady\Fixes\FixStore;
use GenReady\Fixes\RobotsFix;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/fixtures/doubles.php';
require_once dirname( __DIR__ ) . '/fixtures/fix-doubles.php';

class RobotsFixTest extends TestCase {
	const BASE = "User-agent: *\nDisallow: /wp-admin/\n";

	/** @var array */
	private $options = array();
	/** @var array */
	private $autoload = array();
	/** @var string */
	private $home = 'https://example.com';
	/** @var FixStore */
	private $store;
	/** @var MemoryFiles */
	private $files;
	/** @var FakeClient */
	private $client;
	/** @var RecordingCaches */
	private $caches;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->options  = array( 'blog_public' => '1' );
		$this->autoload = array();
		$this->home     = 'https://example.com';
		$opts           = &$this->options;
		$autoload       = &$this->autoload;
		$home           = &$this->home;
		Monkey\Functions\when( 'get_option' )->alias( function ( $k, $d = false ) use ( &$opts ) { return $opts[ $k ] ?? $d; } );
		Monkey\Functions\when( 'update_option' )->alias( function ( $k, $v, $a = null ) use ( &$opts, &$autoload ) { $opts[ $k ] = $v; $autoload[ $k ] = $a; return true; } );
		Monkey\Functions\when( 'delete_option' )->alias( function ( $k ) use ( &$opts ) { unset( $opts[ $k ] ); return true; } );
		Monkey\Functions\when( '__' )->returnArg( 1 );
		Monkey\Functions\when( 'home_url' )->alias( function ( $path = '' ) use ( &$home ) { return $home . $path; } );
		Monkey\Functions\when( 'wp_parse_url' )->alias( 'parse_url' );
		$this->store  = new FixStore();
		$this->files  = new MemoryFiles();
		$this->client = new FakeClient();
		$this->caches = new RecordingCaches();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function fix(): RobotsFix {
		return new RobotsFix( $this->store, $this->files, $this->client, $this->caches, 'd1' );
	}

	private function preview( string $content, string $base, string $mode = 'file' ): array {
		return array(
			'content'         => $content,
			'base_sha256'     => hash( 'sha256', $base ),
			'mode'            => $mode,
			'needsHumanInput' => false,
			'external'        => false,
		);
	}

	/** WordPress's do_robots(): runs the robots_txt filter on $output and prints the result. */
	private function wordpress_makes( RobotsFix $fix, string $output ): void {
		Monkey\Functions\when( 'do_robots' )->alias(
			function () use ( $fix, $output ) {
				echo $fix->filter( $output, '1' );
			}
		);
	}

	private function merge_response( string $base, array $live, array $extra = array() ): array {
		return array_merge(
			array(
				'content'         => "NEW\n",
				'changed'         => true,
				'diff'            => '--- robots.txt',
				'summary'         => array( 'Allows GPTBot.' ),
				'notes'           => array(),
				'needsHumanInput' => false,
				'baseSha256'      => hash( 'sha256', $base ),
				'checks'          => array( 'nonAiAccessUnchanged' => true ),
				'live'            => $live,
			),
			$extra
		);
	}

	// Plan tests.

	public function test_serves_the_fix_when_the_base_is_unchanged(): void {
		$base = "User-agent: *\nDisallow: /wp-admin/\n";
		$this->store->put( 'robots', array( 'mode' => 'virtual', 'content' => 'NEW', 'base_sha256' => hash( 'sha256', $base ) ) );
		$this->assertSame( 'NEW', $this->fix()->filter( $base, '1' ) );
	}

	public function test_serves_incoming_output_when_base_changed(): void {
		$this->store->put( 'robots', array( 'mode' => 'virtual', 'content' => 'NEW', 'base_sha256' => hash( 'sha256', 'old base' ), 'status' => 'applied' ) );
		$this->assertSame( 'changed by Yoast', $this->fix()->filter( 'changed by Yoast', '1' ) );
		$this->assertSame( 'applied', $this->store->get( 'robots' )['status'], 'the filter never writes' );
	}

	public function test_corrupt_store_fails_safe(): void {
		$this->options['genready_fix_robots'] = 'not an array';
		$this->assertSame( 'original', $this->fix()->filter( 'original', '1' ) );
	}

	public function test_does_not_offer_a_fix_on_a_hidden_site(): void {
		$this->options['blog_public'] = '0';
		$this->assertSame( 'hidden', $this->fix()->mode() );
	}

	public function test_file_mode_saves_the_original_before_writing(): void {
		$this->files->put( 'robots.txt', 'ORIGINAL' );
		$r = $this->fix()->apply( $this->preview( 'NEW', 'ORIGINAL' ) );
		$this->assertSame( array( 'ok' => true ), $r );
		$this->assertSame( 'NEW', $this->files->read( 'robots.txt' ) );
		$this->assertSame( 'ORIGINAL', $this->store->get( 'robots' )['original'] );
		$this->assertSame( hash( 'sha256', 'NEW' ), $this->store->get( 'robots' )['written_sha256'] );
		$this->assertSame( array( 'https://example.com/robots.txt' ), $this->caches->calls );
	}

	public function test_undo_restores_the_original_in_file_mode(): void {
		$this->files->put( 'robots.txt', 'ORIGINAL' );
		$this->fix()->apply( $this->preview( 'NEW', 'ORIGINAL' ) );
		$this->assertSame( array( 'ok' => true ), $this->fix()->undo() );
		$this->assertSame( 'ORIGINAL', $this->files->read( 'robots.txt' ) );
		$this->assertSame( array(), $this->store->get( 'robots' ) );
	}

	public function test_undo_refuses_edited_file(): void {
		$this->files->put( 'robots.txt', 'EDITED' );
		$this->store->put( 'robots', array( 'mode' => 'file', 'content' => 'WRITTEN', 'written_sha256' => hash( 'sha256', 'WRITTEN' ), 'original' => 'ORIGINAL' ) );
		$r = $this->fix()->undo();
		$this->assertArrayHasKey( 'error', $r );
		$this->assertSame( 'EDITED', $this->files->read( 'robots.txt' ) );
	}

	public function test_refuses_to_write_without_direct_filesystem(): void {
		$this->files->put( 'robots.txt', 'ORIGINAL' );
		$this->files->writable = false;
		$r = $this->fix()->apply( $this->preview( 'NEW', 'ORIGINAL' ) );
		$this->assertStringContainsString( 'Download', $r['error'] );
		$this->assertSame( 'CANNOT_WRITE', $r['code'] );
		$this->assertSame( 'ORIGINAL', $this->files->read( 'robots.txt' ) );
	}

	public function test_refuses_apply_when_the_base_changed_since_preview(): void {
		$this->files->put( 'robots.txt', 'CHANGED' );
		$r = $this->fix()->apply( $this->preview( 'NEW', 'ORIGINAL' ) );
		$this->assertArrayHasKey( 'error', $r );
		$this->assertSame( 'CHANGED', $this->files->read( 'robots.txt' ) );
	}

	// Controller rulings.

	public function test_own_applied_fix_live_is_not_external(): void {
		$this->store->put( 'robots', array( 'mode' => 'virtual', 'content' => "NEW\n", 'base_sha256' => hash( 'sha256', self::BASE ), 'status' => 'applied' ) );
		$fix = $this->fix();
		$this->wordpress_makes( $fix, self::BASE );
		$this->client->request_result = $this->merge_response(
			self::BASE,
			array(
				'sha256'          => hash( 'sha256', "NEW\n" ),
				'differsFromBase' => true,
			)
		);
		$preview = $fix->preview( true );
		$this->assertIsArray( $preview );
		$this->assertFalse( $preview['external'] );
		$this->assertSame( 'virtual', $preview['mode'] );
		$this->assertSame( hash( 'sha256', self::BASE ), $preview['base_sha256'] );
		$this->assertSame(
			array(
				'POST',
				'/api/v1/artifacts/robots_txt/merge',
				array(
					'domainId' => 'd1',
					'base'     => array(
						'mode'    => 'virtual',
						'content' => self::BASE,
					),
					'options'  => array( 'contentSignal' => true ),
				),
			),
			$this->client->requests[0]
		);
	}

	public function test_different_live_file_is_external(): void {
		$fix = $this->fix();
		$this->wordpress_makes( $fix, self::BASE );
		$this->client->request_result = $this->merge_response(
			self::BASE,
			array(
				'sha256'          => hash( 'sha256', "User-agent: *\nDisallow:\n# Cloudflare\n" ),
				'differsFromBase' => true,
			)
		);
		$preview = $fix->preview( false );
		$this->assertTrue( $preview['external'] );
		$this->assertFalse( $this->client->requests[0][2]['options']['contentSignal'] );
		$r = $fix->apply( $preview );
		$this->assertStringContainsString( 'host or CDN', $r['error'] );
		$this->assertSame( array(), $this->store->get( 'robots' ) );
	}

	public function test_a_different_live_file_is_external_even_with_an_applied_fix(): void {
		$this->store->put( 'robots', array( 'mode' => 'virtual', 'content' => "NEW\n", 'base_sha256' => hash( 'sha256', self::BASE ), 'status' => 'applied' ) );
		$fix = $this->fix();
		$this->wordpress_makes( $fix, self::BASE );
		$this->client->request_result = $this->merge_response(
			self::BASE,
			array(
				'sha256'          => hash( 'sha256', 'CDN FILE' ),
				'differsFromBase' => true,
			)
		);
		$this->assertTrue( $fix->preview( true )['external'] );
	}

	public function test_live_unknown_or_same_as_base_is_not_external(): void {
		$fix = $this->fix();
		$this->wordpress_makes( $fix, self::BASE );
		$this->client->request_result = $this->merge_response( self::BASE, array( 'sha256' => null, 'differsFromBase' => false ) );
		$this->assertFalse( $fix->preview( true )['external'] );
	}

	public function test_file_mode_is_never_external(): void {
		$this->files->put( 'robots.txt', self::BASE );
		$this->client->request_result = $this->merge_response(
			self::BASE,
			array(
				'sha256'          => hash( 'sha256', 'other' ),
				'differsFromBase' => true,
			)
		);
		$preview = $this->fix()->preview( true );
		$this->assertFalse( $preview['external'] );
		$this->assertSame( 'file', $preview['mode'] );
		$this->assertSame( 'file', $this->client->requests[0][2]['base']['mode'] );
	}

	public function test_preview_rejects_server_base_hash_mismatch(): void {
		$fix = $this->fix();
		$this->wordpress_makes( $fix, self::BASE );
		$this->client->request_result = $this->merge_response( 'something else', array( 'sha256' => null, 'differsFromBase' => false ) );
		$r = $fix->preview( true );
		$this->assertIsArray( $r );
		$this->assertStringContainsString( 'changed during the preview', $r['error'] );
	}

	public function test_preview_returns_the_api_error(): void {
		$fix = $this->fix();
		$this->wordpress_makes( $fix, self::BASE );
		$this->client->request_result = new ApiError( 422, 'UNSAFE_MERGE', 'This change would block Googlebot.' );
		$r = $fix->preview( true );
		$this->assertInstanceOf( ApiError::class, $r );
		$this->assertSame( 'This change would block Googlebot.', $r->user_message() );
	}

	public function test_preview_refuses_a_hidden_site_without_a_call(): void {
		$this->options['blog_public'] = '0';
		$r = $this->fix()->preview( true );
		$this->assertStringContainsString( 'Settings', $r['error'] );
		$this->assertSame( array(), $this->client->requests );
		$this->assertArrayHasKey( 'error', $this->fix()->apply( $this->preview( 'NEW', self::BASE, 'hidden' ) ) );
	}

	public function test_subdir_site_refuses(): void {
		$this->home = 'https://example.com/blog';
		$fix        = $this->fix();
		$this->assertSame( 'subdir', $fix->mode() );
		$r = $fix->preview( true );
		$this->assertStringContainsString( 'root of a domain', $r['error'] );
		$this->assertSame( array(), $this->client->requests );
		$r = $fix->apply( $this->preview( 'NEW', self::BASE, 'virtual' ) );
		$this->assertStringContainsString( 'root of a domain', $r['error'] );
		$this->assertSame( array(), $this->store->get( 'robots' ) );
		$this->home = 'https://example.com/';
		$this->assertSame( 'virtual', $fix->mode() );
	}

	public function test_preview_needs_a_domain(): void {
		$fix = new RobotsFix(
			$this->store,
			$this->files,
			$this->client,
			$this->caches,
			function (): string {
				return '';
			}
		);
		$this->wordpress_makes( $fix, self::BASE );
		$this->assertArrayHasKey( 'error', $fix->preview( true ) );
		$this->assertSame( array(), $this->client->requests );
	}

	public function test_domain_id_is_read_only_when_preview_runs(): void {
		$reads = 0;
		$fix   = new RobotsFix(
			$this->store,
			$this->files,
			$this->client,
			$this->caches,
			function () use ( &$reads ): string {
				++$reads;
				return 'd9';
			}
		);
		$fix->filter( 'x', '1' );
		$this->assertSame( 0, $reads );
		$this->wordpress_makes( $fix, self::BASE );
		$this->client->request_result = $this->merge_response( self::BASE, array( 'sha256' => null, 'differsFromBase' => false ) );
		$fix->preview( true );
		$this->assertSame( 1, $reads );
		$this->assertSame( 'd9', $this->client->requests[0][2]['domainId'] );
	}

	public function test_virtual_apply_stores_the_fix_and_purges(): void {
		$fix = $this->fix();
		$this->wordpress_makes( $fix, self::BASE );
		$this->assertSame( array( 'ok' => true ), $fix->apply( $this->preview( "NEW\n", self::BASE, 'virtual' ) ) );
		$stored = $this->store->get( 'robots' );
		$this->assertSame( 'virtual', $stored['mode'] );
		$this->assertSame( "NEW\n", $stored['content'] );
		$this->assertSame( hash( 'sha256', self::BASE ), $stored['base_sha256'] );
		$this->assertSame( 'applied', $stored['status'] );
		$this->assertSame( array( 'https://example.com/robots.txt' ), $this->caches->calls );
		$this->assertSame( "NEW\n", $fix->filter( self::BASE, '1' ) );
	}

	public function test_apply_refuses_a_preview_that_does_not_match(): void {
		$this->files->put( 'robots.txt', 'ORIGINAL' );
		$good = $this->preview( 'NEW', 'ORIGINAL' );
		$bad  = array(
			array_merge( $good, array( 'mode' => 'virtual' ) ),
			array_merge( $good, array( 'base_sha256' => 'abc' ) ),
			array_merge( $good, array( 'content' => 42 ) ),
			array_merge( $good, array( 'content' => '' ) ),
			array_merge( $good, array( 'content' => str_repeat( 'a', 600001 ) ) ),
			array_diff_key( $good, array( 'external' => true ) ),
			array_diff_key( $good, array( 'needsHumanInput' => true ) ),
		);
		foreach ( $bad as $preview ) {
			$r = $this->fix()->apply( $preview );
			$this->assertStringContainsString( 'Preview again', $r['error'] );
		}
		$this->assertSame( 'ORIGINAL', $this->files->read( 'robots.txt' ) );
		$this->assertSame( 0, $this->files->writes );
		$this->assertSame( array(), $this->store->get( 'robots' ) );
	}

	public function test_apply_refuses_when_human_input_is_needed(): void {
		$this->files->put( 'robots.txt', 'ORIGINAL' );
		$r = $this->fix()->apply( array_merge( $this->preview( 'NEW', 'ORIGINAL' ), array( 'needsHumanInput' => true ) ) );
		$this->assertArrayHasKey( 'error', $r );
		$this->assertSame( 'ORIGINAL', $this->files->read( 'robots.txt' ) );
	}

	public function test_virtual_undo_restores_previous_record_with_its_base(): void {
		$this->store->put( 'robots', array( 'mode' => 'virtual', 'content' => 'A', 'base_sha256' => hash( 'sha256', 'B1' ), 'status' => 'applied' ) );
		$this->store->put( 'robots', array( 'mode' => 'virtual', 'content' => 'B', 'base_sha256' => hash( 'sha256', 'B2' ), 'status' => 'applied' ) );
		$this->assertSame( array( 'ok' => true ), $this->fix()->undo() );
		$stored = $this->store->get( 'robots' );
		$this->assertSame( 'A', $stored['content'] );
		$this->assertSame( hash( 'sha256', 'B1' ), $stored['base_sha256'] );
		$this->assertSame( 'virtual', $stored['mode'] );
		$this->assertSame( 'applied', $stored['status'] );
		$this->assertSame( array(), $stored['versions'] );
		// The restored record is served only for the base it was checked against.
		$this->assertSame( 'A', $this->fix()->filter( 'B1', '1' ) );
		$this->assertSame( array( 'ok' => true ), $this->fix()->undo() );
		$this->assertSame( array(), $this->store->get( 'robots' ) );
		$this->assertSame( array( 'https://example.com/robots.txt', 'https://example.com/robots.txt' ), $this->caches->calls );
	}

	public function test_virtual_undo_does_not_restore_a_file_record(): void {
		$this->store->put( 'robots', array( 'mode' => 'file', 'content' => 'F', 'base_sha256' => hash( 'sha256', 'X' ), 'written_sha256' => hash( 'sha256', 'F' ), 'original' => 'X' ) );
		$this->store->put( 'robots', array( 'mode' => 'virtual', 'content' => 'V', 'base_sha256' => hash( 'sha256', 'B' ), 'status' => 'applied' ) );
		$this->assertSame( array( 'ok' => true ), $this->fix()->undo() );
		$this->assertSame( array(), $this->store->get( 'robots' ) );
	}

	public function test_undo_with_nothing_stored(): void {
		$this->assertArrayHasKey( 'error', $this->fix()->undo() );
		$this->assertSame( array(), $this->caches->calls );
	}

	public function test_file_reapply_keeps_first_original(): void {
		$this->files->put( 'robots.txt', 'ORIGINAL' );
		$this->assertSame( array( 'ok' => true ), $this->fix()->apply( $this->preview( 'NEW', 'ORIGINAL' ) ) );
		$this->assertSame( array( 'ok' => true ), $this->fix()->apply( $this->preview( 'NEWER', 'NEW' ) ) );
		$this->assertSame( 'NEWER', $this->files->read( 'robots.txt' ) );
		$this->assertSame( 'ORIGINAL', $this->store->get( 'robots' )['original'] );
		$this->assertSame( array( 'ok' => true ), $this->fix()->undo() );
		$this->assertSame( 'ORIGINAL', $this->files->read( 'robots.txt' ) );
	}

	public function test_file_apply_after_a_hand_edit_takes_the_edited_file_as_original(): void {
		$this->files->put( 'robots.txt', 'EDITED' );
		$this->store->put( 'robots', array( 'mode' => 'file', 'content' => 'WRITTEN', 'written_sha256' => hash( 'sha256', 'WRITTEN' ), 'original' => 'ORIGINAL' ) );
		$this->assertSame( array( 'ok' => true ), $this->fix()->apply( $this->preview( 'NEW', 'EDITED' ) ) );
		$this->assertSame( 'EDITED', $this->store->get( 'robots' )['original'] );
	}

	public function test_failed_write_puts_the_old_file_back(): void {
		$this->files->put( 'robots.txt', 'ORIGINAL' );
		$this->files->broken_writes = true;
		$r = $this->fix()->apply( $this->preview( 'NEW', 'ORIGINAL' ) );
		$this->assertArrayHasKey( 'error', $r );
		$this->assertSame( 'WRITE_FAILED', $r['code'] );
		$this->assertSame( 'ORIGINAL', $this->files->read( 'robots.txt' ) );
		$this->assertSame( array(), $this->store->get( 'robots' ) );
	}

	public function test_file_undo_deletes_a_file_that_did_not_exist(): void {
		$this->files->put( 'robots.txt', 'WRITTEN' );
		$this->store->put( 'robots', array( 'mode' => 'file', 'content' => 'WRITTEN', 'written_sha256' => hash( 'sha256', 'WRITTEN' ), 'original' => null ) );
		$this->assertSame( array( 'ok' => true ), $this->fix()->undo() );
		$this->assertFalse( $this->files->exists( 'robots.txt' ) );
	}

	public function test_filter_never_calls_network(): void {
		Monkey\Functions\expect( 'wp_remote_request' )->never();
		Monkey\Functions\expect( 'wp_remote_get' )->never();
		$this->store->put( 'robots', array( 'mode' => 'virtual', 'content' => 'NEW', 'base_sha256' => hash( 'sha256', self::BASE ) ) );
		$fix = $this->fix();
		$this->assertSame( 'NEW', $fix->filter( self::BASE, '1' ) );
		$this->assertSame( 'other', $fix->filter( 'other', '1' ) );
		$this->assertSame( array(), $this->client->requests );
		$this->assertSame( array(), $this->caches->calls );
		$this->assertSame( 0, $this->files->writes );
	}

	public function test_filter_with_a_changed_base_writes_nothing(): void {
		$this->store->put( 'robots', array( 'mode' => 'virtual', 'content' => 'NEW', 'base_sha256' => hash( 'sha256', 'old base' ), 'status' => 'applied' ) );
		$writes = $this->count_option_writes();
		$fix    = $this->fix();
		$this->assertSame( 'changed', $fix->filter( 'changed', '1' ) );
		$this->assertSame( 'changed', $fix->filter( 'changed', '1' ) );
		$this->assertSame( array(), $writes->calls );
	}

	/** Record every option write. The filter catches Throwable, so a throwing stub would hide a write. */
	private function count_option_writes(): \stdClass {
		$writes        = new \stdClass();
		$writes->calls = array();
		foreach ( array( 'update_option', 'add_option', 'delete_option' ) as $function ) {
			Monkey\Functions\when( $function )->alias(
				function ( $name ) use ( $writes, $function ) {
					$writes->calls[] = $function . ':' . $name;
					return true;
				}
			);
		}
		return $writes;
	}

	public function test_filter_ignores_file_records_and_errors(): void {
		$this->store->put( 'robots', array( 'mode' => 'file', 'content' => 'NEW', 'base_sha256' => hash( 'sha256', self::BASE ) ) );
		$this->assertSame( self::BASE, $this->fix()->filter( self::BASE, '1' ) );
		Monkey\Functions\when( 'get_option' )->alias(
			function () {
				throw new \RuntimeException( 'db down' );
			}
		);
		$this->assertSame( self::BASE, $this->fix()->filter( self::BASE, '1' ) );
	}

	public function test_base_capture_returns_the_incoming_output_without_the_fix(): void {
		$this->store->put( 'robots', array( 'mode' => 'virtual', 'content' => 'NEW', 'base_sha256' => hash( 'sha256', self::BASE ) ) );
		$fix = $this->fix();
		$this->wordpress_makes( $fix, self::BASE );
		$level = ob_get_level();
		$this->assertSame( self::BASE, $fix->base() );
		$this->assertSame( $level, ob_get_level() );
		// After the capture, the filter serves the fix again.
		$this->assertSame( 'NEW', $fix->filter( self::BASE, '1' ) );
	}

	public function test_base_capture_ends_when_do_robots_throws(): void {
		$this->store->put( 'robots', array( 'mode' => 'virtual', 'content' => 'NEW', 'base_sha256' => hash( 'sha256', self::BASE ) ) );
		$fix = $this->fix();
		Monkey\Functions\when( 'do_robots' )->alias(
			function () {
				echo 'partial';
				throw new \RuntimeException( 'boom' );
			}
		);
		$level = ob_get_level();
		try {
			$fix->base();
			$this->fail( 'Expected the exception.' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'boom', $e->getMessage() );
		}
		$this->assertSame( $level, ob_get_level() );
		$this->assertSame( 'NEW', $fix->filter( self::BASE, '1' ) );
	}

	public function test_unreadable_file_is_never_replaced(): void {
		$this->files->put( 'robots.txt', "User-agent: Googlebot\nDisallow: /\n" );
		$this->files->unreadable = true;
		$this->assertSame( 'file', $this->fix()->mode() );
		$r = $this->fix()->preview( true );
		$this->assertStringContainsString( 'cannot read', $r['error'] );
		$this->assertSame( array(), $this->client->requests );
		// A preview of an empty base matches the empty read, but the file is still not replaced.
		$r = $this->fix()->apply( $this->preview( 'NEW', '' ) );
		$this->assertStringContainsString( 'cannot read', $r['error'] );
		$this->assertSame( 0, $this->files->writes );
		$this->assertSame( array(), $this->store->get( 'robots' ) );
	}

	public function test_failed_write_removes_a_file_that_did_not_exist(): void {
		$files = new class() extends MemoryFiles {
			/** @var int */
			public $exists_calls = 0;
			public function exists( string $name ): bool {
				++$this->exists_calls;
				// The file exists for the two mode() checks (apply and base), and is gone when apply_file reads it.
				return $this->exists_calls <= 2 || parent::exists( $name );
			}
		};
		$files->broken_writes = true;
		$fix = new RobotsFix( $this->store, $files, $this->client, $this->caches, 'd1' );
		$r   = $fix->apply( $this->preview( 'NEW', '' ) );
		$this->assertArrayHasKey( 'error', $r );
		$this->assertArrayNotHasKey( 'robots.txt', $files->files );
		$this->assertSame( array(), $this->store->get( 'robots' ) );
	}

	public function test_malformed_record_fails_safe_without_warnings(): void {
		$records = array(
			array( 'mode' => 'virtual', 'content' => array( 'x' ), 'base_sha256' => hash( 'sha256', self::BASE ) ),
			array( 'mode' => 'virtual', 'content' => 'NEW', 'base_sha256' => array( 'x' ) ),
			array( 'mode' => array( 'virtual' ), 'content' => 'NEW', 'base_sha256' => hash( 'sha256', self::BASE ) ),
			array( 'mode' => 'virtual', 'content' => 'NEW' ),
		);
		$errors = array();
		set_error_handler(
			function ( $no, $message ) use ( &$errors ) {
				$errors[] = $message;
				return true;
			}
		);
		try {
			foreach ( $records as $record ) {
				$this->options['genready_fix_robots'] = $record;
				$this->assertSame( self::BASE, $this->fix()->filter( self::BASE, '1' ) );
			}
			$this->options['genready_fix_robots'] = array( 'mode' => 'virtual', 'content' => 'NEW', 'base_sha256' => hash( 'sha256', self::BASE ) );
			$this->assertSame( array( 'not', 'a string' ), $this->fix()->filter( array( 'not', 'a string' ), '1' ) );
			$this->assertSame( 'NEW', $this->fix()->filter( self::BASE, '1' ) );
		} finally {
			restore_error_handler();
		}
		$this->assertSame( array(), $errors );
	}

	// Fix round 1.

	/** RobotsFix whose Content-Type guard writes to a log. */
	private function logging_fix( array &$log ): RobotsFix {
		$fix      = new class( $this->store, $this->files, $this->client, $this->caches, 'd1' ) extends RobotsFix {
			/** @var array */
			public $log = array();
			protected function remember_content_type(): void {
				$this->log[] = 'remember';
			}
			protected function restore_content_type(): void {
				$this->log[] = 'restore';
			}
		};
		$fix->log = &$log;
		return $fix;
	}

	public function test_base_restores_the_content_type_after_do_robots(): void {
		$log = array();
		$fix = $this->logging_fix( $log );
		Monkey\Functions\when( 'do_robots' )->alias(
			function () use ( $fix, &$log ) {
				$log[] = 'do_robots';
				echo $fix->filter( self::BASE, '1' );
			}
		);
		$this->assertSame( self::BASE, $fix->base() );
		$this->assertSame( array( 'remember', 'do_robots', 'restore' ), $log );
	}

	public function test_base_restores_the_content_type_when_do_robots_throws(): void {
		$log = array();
		$fix = $this->logging_fix( $log );
		Monkey\Functions\when( 'do_robots' )->alias(
			function () use ( &$log ) {
				$log[] = 'do_robots';
				throw new \RuntimeException( 'boom' );
			}
		);
		try {
			$fix->base();
			$this->fail( 'Expected the exception.' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'boom', $e->getMessage() );
		}
		$this->assertSame( array( 'remember', 'do_robots', 'restore' ), $log );
	}

	public function test_file_mode_base_does_not_touch_headers(): void {
		$log = array();
		$this->files->put( 'robots.txt', 'FILE' );
		$this->assertSame( 'FILE', $this->logging_fix( $log )->base() );
		$this->assertSame( array(), $log );
	}

	public function test_unknown_root_folder_refuses_without_a_call(): void {
		$this->files->root = false;
		$this->files->put( 'robots.txt', 'ORIGINAL' );
		$fix = $this->fix();
		$this->assertSame( 'unknown', $fix->mode() );
		$r = $fix->preview( true );
		$this->assertStringContainsString( "cannot find this site's root folder", $r['error'] );
		$this->assertSame( array(), $this->client->requests );
		foreach ( array( 'file', 'virtual', 'unknown' ) as $mode ) {
			$r = $fix->apply( $this->preview( 'NEW', 'ORIGINAL', $mode ) );
			$this->assertStringContainsString( "cannot find this site's root folder", $r['error'] );
		}
		$this->assertSame( 0, $this->files->writes );
		$this->assertSame( array(), $this->store->get( 'robots' ) );
	}

	public function test_file_undo_refuses_when_the_root_folder_is_unknown(): void {
		$this->files->put( 'robots.txt', 'WRITTEN' );
		$this->store->put( 'robots', array( 'mode' => 'file', 'content' => 'WRITTEN', 'written_sha256' => hash( 'sha256', 'WRITTEN' ), 'original' => 'ORIGINAL' ) );
		$this->files->root = false;
		$r = $this->fix()->undo();
		$this->assertStringContainsString( "cannot find this site's root folder", $r['error'] );
		$this->assertSame( 'WRITTEN', $this->files->read( 'robots.txt' ) );
		$this->assertSame( 'file', $this->store->get( 'robots' )['mode'] );
	}

	public function test_filter_serves_on_an_unknown_root(): void {
		$this->files->root = false;
		$this->store->put( 'robots', array( 'mode' => 'virtual', 'content' => 'NEW', 'base_sha256' => hash( 'sha256', self::BASE ) ) );
		$this->assertSame( 'NEW', $this->fix()->filter( self::BASE, '1' ) );
	}

	public function test_filter_with_the_checked_base_writes_nothing(): void {
		$this->store->put( 'robots', array( 'mode' => 'virtual', 'content' => 'NEW', 'base_sha256' => hash( 'sha256', self::BASE ), 'status' => 'needs_review' ) );
		$writes = $this->count_option_writes();
		$this->assertSame( 'NEW', $this->fix()->filter( self::BASE, '1' ) );
		$this->assertSame( array(), $writes->calls );
	}

	public function test_file_apply_refuses_when_the_backup_does_not_save(): void {
		$this->files->put( 'robots.txt', 'ORIGINAL' );
		Monkey\Functions\when( 'update_option' )->justReturn( false );
		$r = $this->fix()->apply( $this->preview( 'NEW', 'ORIGINAL' ) );
		$this->assertSame( 'GenReady could not save its backup of robots.txt, so it did not change the file.', $r['error'] );
		$this->assertSame( 0, $this->files->writes );
		$this->assertSame( 'ORIGINAL', $this->files->read( 'robots.txt' ) );
		$this->assertSame( array(), $this->caches->calls );
	}

	public function test_virtual_apply_reports_a_record_that_does_not_save(): void {
		$fix = $this->fix();
		$this->wordpress_makes( $fix, self::BASE );
		Monkey\Functions\when( 'update_option' )->justReturn( false );
		$r = $fix->apply( $this->preview( "NEW\n", self::BASE, 'virtual' ) );
		$this->assertSame( 'GenReady could not save the fix. Try again.', $r['error'] );
		$this->assertSame( array(), $this->caches->calls );
	}

	// Fix round A, G1: multisite file safety.

	public function test_sub_site_with_a_physical_file_is_refused_without_a_write(): void {
		$this->files->may = false;
		$this->files->put( 'robots.txt', 'NETWORK FILE' );
		$fix = $this->fix();
		$this->assertSame( 'network', $fix->mode() );
		$text = 'On a multisite network, only a network admin on the main site can change robots.txt in the site root.';
		$this->assertSame( $text, $fix->mode_refusal( 'network' ) );
		$this->assertSame( array( 'error' => $text ), $fix->preview( true ) );
		$this->assertSame( array(), $this->client->requests );
		foreach ( array( 'file', 'network' ) as $mode ) {
			$this->assertSame( array( 'error' => $text ), $fix->apply( $this->preview( 'NEW', 'NETWORK FILE', $mode ) ) );
		}
		$this->assertSame( 0, $this->files->writes );
		$this->assertSame( 'NETWORK FILE', $this->files->read( 'robots.txt' ) );
		$this->assertSame( array(), $this->store->get( 'robots' ) );
	}

	public function test_sub_site_without_a_physical_file_keeps_the_virtual_fix(): void {
		$this->files->may = false;
		$fix              = $this->fix();
		$this->assertSame( 'virtual', $fix->mode() );
		$this->wordpress_makes( $fix, self::BASE );
		$this->assertSame( array( 'ok' => true ), $fix->apply( $this->preview( "NEW\n", self::BASE, 'virtual' ) ) );
		$this->assertSame( 'virtual', $this->store->get( 'robots' )['mode'] );
		$this->assertSame( "NEW\n", $fix->filter( self::BASE, '1' ) );
		$this->assertSame( 0, $this->files->writes );
	}

	public function test_main_site_network_admin_keeps_file_mode(): void {
		$this->files->put( 'robots.txt', 'ORIGINAL' );
		$this->assertSame( 'file', $this->fix()->mode() );
		$this->assertSame( array( 'ok' => true ), $this->fix()->apply( $this->preview( 'NEW', 'ORIGINAL' ) ) );
		$this->assertSame( 'NEW', $this->files->read( 'robots.txt' ) );
	}

	// Fix round A, G6: text printed on do_robotstxt is not part of the checked base.

	public function test_preview_refuses_when_another_plugin_prints_extra_text(): void {
		$fix = $this->fix();
		Monkey\Functions\when( 'do_robots' )->alias(
			function () use ( $fix ) {
				echo "# Added on do_robotstxt\n";
				echo $fix->filter( self::BASE, '1' );
			}
		);
		$text = 'Another plugin adds text to robots.txt, so GenReady cannot check the result.';
		$this->assertSame( array( 'error' => $text ), $fix->preview( true ) );
		$this->assertSame( array(), $this->client->requests );
		$this->assertSame( array( 'error' => $text ), $fix->apply( $this->preview( "NEW\n", self::BASE, 'virtual' ) ) );
		$this->assertSame( array(), $this->store->get( 'robots' ) );
	}

	public function test_base_without_extra_text_previews_normally(): void {
		$fix = $this->fix();
		$this->wordpress_makes( $fix, self::BASE );
		$this->client->request_result = $this->merge_response( self::BASE, array( 'sha256' => null, 'differsFromBase' => false ) );
		$this->assertArrayNotHasKey( 'error', $fix->preview( true ) );
	}
}
