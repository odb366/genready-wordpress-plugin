<?php
namespace GenReady\Tests\Unit;

use Brain\Monkey;
use GenReady\Api\ApiError;
use GenReady\Compat\SeoPlugins;
use GenReady\Fixes\FixStore;
use GenReady\Fixes\LlmsTxtFix;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/fixtures/doubles.php';
require_once dirname( __DIR__ ) . '/fixtures/fix-doubles.php';

/** SEO plugin check with a scripted answer. */
class SeoDouble extends SeoPlugins {
	/** @var array|null */
	public $owner = null;
	/** @var bool */
	public $throws = false;

	public function llms_txt_owner() {
		if ( $this->throws ) {
			throw new \RuntimeException( 'boom' );
		}
		return $this->owner;
	}
}

/** The fix with emit() recording what it would send. */
class RecordingLlmsFix extends LlmsTxtFix {
	/** @var string|null */
	public $emitted = null;
	/** @var bool|null */
	public $body = null;
	/** @var bool Whether the headers count as sent. PHPUnit has printed output, so the real check says yes. */
	public $sent = false;

	protected function headers_already_sent(): bool {
		return $this->sent;
	}

	protected function emit( string $content, bool $with_body = true ): void {
		$this->emitted = $content;
		$this->body    = $with_body;
	}
}

class LlmsTxtFixTest extends TestCase {
	/** @var array */
	private $options = array();
	/** @var FixStore */
	private $store;
	/** @var MemoryFiles */
	private $files;
	/** @var FakeClient */
	private $client;
	/** @var RecordingCaches */
	private $caches;
	/** @var SeoDouble */
	private $seo;
	/** @var array */
	private $blog = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->options = array();
		$this->blog    = array(
			'name'        => 'Example',
			'description' => 'A tagline',
		);
		$opts          = &$this->options;
		$blog          = &$this->blog;
		Monkey\Functions\when( 'get_option' )->alias( function ( $k, $d = false ) use ( &$opts ) { return $opts[ $k ] ?? $d; } );
		Monkey\Functions\when( 'update_option' )->alias( function ( $k, $v, $a = null ) use ( &$opts ) { $opts[ $k ] = $v; return true; } );
		Monkey\Functions\when( 'delete_option' )->alias( function ( $k ) use ( &$opts ) { unset( $opts[ $k ] ); return true; } );
		Monkey\Functions\when( '__' )->returnArg( 1 );
		Monkey\Functions\when( 'home_url' )->alias( function ( $path = '' ) { return 'https://example.com' . $path; } );
		Monkey\Functions\when( 'wp_parse_url' )->alias( 'parse_url' );
		Monkey\Functions\when( 'wp_unslash' )->returnArg( 1 );
		Monkey\Functions\when( 'esc_url_raw' )->returnArg( 1 );
		Monkey\Functions\when( 'sanitize_text_field' )->returnArg( 1 );
		Monkey\Functions\when( 'get_bloginfo' )->alias( function ( $k ) use ( &$blog ) { return $blog[ $k ] ?? ''; } );
		Monkey\Functions\when( 'wp_strip_all_tags' )->alias( function ( $s ) { return trim( strip_tags( $s ) ); } );
		Monkey\Functions\when( 'sanitize_textarea_field' )->returnArg( 1 );
		Monkey\Functions\when( 'wp_http_validate_url' )->alias( function ( $u ) { return false !== filter_var( $u, FILTER_VALIDATE_URL ) && in_array( parse_url( $u, PHP_URL_SCHEME ), array( 'http', 'https' ), true ) ? $u : false; } );
		Monkey\Functions\when( 'get_permalink' )->alias( function ( $p ) { return 'https://example.com/?p=' . $p->ID; } );
		Monkey\Functions\when( 'get_the_title' )->alias( function ( $p ) { return 'Title ' . $p->ID; } );
		Monkey\Functions\when( 'get_pages' )->justReturn( array() );
		Monkey\Functions\when( 'get_posts' )->justReturn( array() );
		$this->store  = new FixStore();
		$this->files  = new MemoryFiles();
		$this->client = new FakeClient();
		$this->caches = new RecordingCaches();
		$this->seo    = new SeoDouble();
		unset( $_SERVER['REQUEST_URI'], $_SERVER['REQUEST_METHOD'] );
	}

	protected function tearDown(): void {
		unset( $_SERVER['REQUEST_URI'], $_SERVER['REQUEST_METHOD'] );
		Monkey\tearDown();
		parent::tearDown();
	}

	private function fixFor( string $path, string $method = 'GET' ): RecordingLlmsFix {
		$_SERVER['REQUEST_URI']    = $path;
		$_SERVER['REQUEST_METHOD'] = $method;
		return new RecordingLlmsFix( $this->store, $this->files, $this->client, $this->caches, $this->seo, 'd1' );
	}

	private function post( int $id, string $password = '' ): object {
		return (object) array( 'ID' => $id, 'post_password' => $password );
	}

	private function preview( string $content, ?string $base, string $mode = 'virtual', array $extra = array() ): array {
		return array_merge(
			array(
				'content'         => $content,
				'base_sha256'     => hash( 'sha256', (string) $base ),
				'mode'            => $mode,
				'needsHumanInput' => false,
				'missing'         => array(),
			),
			$extra
		);
	}

	private function response( array $extra = array() ): array {
		return array_merge(
			array(
				'content'         => "# Example\n",
				'changed'         => true,
				'diff'            => '+# Example',
				'summary'         => array( 'Builds llms.txt.' ),
				'notes'           => array(),
				'needsHumanInput' => false,
				'missing'         => array(),
			),
			$extra
		);
	}

	// Plan tests.

	public function test_serves_llms_txt_as_plain_text(): void {
		$this->store->put( 'llms', array( 'mode' => 'virtual', 'content' => "# Example\n" ) );
		$fix = $this->fixFor( '/llms.txt' );
		$fix->serve( null );
		$this->assertSame( "# Example\n", $fix->emitted );
		$this->assertTrue( $fix->body );
	}

	public function test_ignores_other_paths(): void {
		$this->store->put( 'llms', array( 'mode' => 'virtual', 'content' => '# Example' ) );
		foreach ( array( '/llms.txt.bak', '/blog/llms.txt', '/llms.txt/x' ) as $path ) {
			$fix = $this->fixFor( $path );
			$fix->serve( null );
			$this->assertNull( $fix->emitted, $path );
		}
	}

	public function test_ignores_the_query_string(): void {
		$this->store->put( 'llms', array( 'mode' => 'virtual', 'content' => '# Example' ) );
		$fix = $this->fixFor( '/llms.txt?x=1' );
		$fix->serve( null );
		$this->assertSame( '# Example', $fix->emitted );
	}

	public function test_corrupt_store_fails_safe(): void {
		$this->options['genready_fix_llms'] = 'x';
		$fix = $this->fixFor( '/llms.txt' );
		$fix->serve( null );
		$this->assertNull( $fix->emitted );
	}

	public function test_skips_password_protected_posts(): void {
		Monkey\Functions\when( 'get_posts' )->justReturn( array( $this->post( 1, 'x' ), $this->post( 2 ) ) );
		$urls = array_column( $this->fixFor( '/' )->site_input()['pages'], 'url' );
		$this->assertSame( array( 'https://example.com/?p=2' ), $urls );
	}

	public function test_refuses_to_replace_an_seo_plugins_llms_txt(): void {
		$this->seo->owner = array( 'plugin' => 'Yoast SEO', 'setting' => 'Yoast SEO → Settings → Site features → llms.txt' );
		$r = $this->fixFor( '/' )->apply( $this->preview( '# X', null ) );
		$this->assertStringContainsString( 'Yoast SEO', $r['error'] );
		$this->assertSame( array(), $this->store->get( 'llms' ) );
	}

	public function test_refuses_apply_with_missing_summary(): void {
		$r = $this->fixFor( '/' )->apply( $this->preview( '# X', null, 'virtual', array( 'missing' => array( 'summary' ), 'needsHumanInput' => true ) ) );
		$this->assertSame( 'Add a site summary first.', $r['error'] );
	}

	public function test_refuses_apply_with_no_pages(): void {
		$r = $this->fixFor( '/' )->apply( $this->preview( '# X', null, 'virtual', array( 'missing' => array( 'pages' ), 'needsHumanInput' => true ) ) );
		$this->assertStringContainsString( 'Publish a page first', $r['error'] );
	}

	public function test_writes_a_file_when_asked_after_a_404(): void {
		$fix = $this->fixFor( '/' );
		$this->assertSame( array( 'ok' => true ), $fix->apply( $this->preview( "# Example\n", null ), true ) );
		$this->assertSame( "# Example\n", $this->files->read( 'llms.txt' ) );
		$record = $this->store->get( 'llms' );
		$this->assertSame( 'file', $record['mode'] );
		$this->assertNull( $record['original'] );
		$this->assertSame( hash( 'sha256', "# Example\n" ), $record['written_sha256'] );
		$this->assertSame( array( 'https://example.com/llms.txt' ), $this->caches->calls );
		// Undo deletes the file it wrote.
		$this->assertSame( array( 'ok' => true ), $fix->undo() );
		$this->assertFalse( $this->files->exists( 'llms.txt' ) );
		$this->assertSame( array(), $this->store->get( 'llms' ) );
	}

	public function test_does_not_write_a_file_unless_asked(): void {
		$this->fixFor( '/' )->apply( $this->preview( "# Example\n", null ) );
		$this->assertFalse( $this->files->exists( 'llms.txt' ) );
		$this->assertSame( 'virtual', $this->store->get( 'llms' )['mode'] );
	}

	public function test_file_write_is_refused_when_wordpress_cannot_write(): void {
		$this->files->writable = false;
		$r = $this->fixFor( '/' )->apply( $this->preview( "# Example\n", null ), true );
		$this->assertArrayHasKey( 'error', $r );
		$this->assertSame( 'CANNOT_WRITE', $r['code'] );
		$this->assertSame( array(), $this->store->get( 'llms' ) );
	}

	public function test_file_mode_keeps_the_original_and_undo_refuses_an_edited_file(): void {
		$this->files->put( 'llms.txt', 'ORIGINAL' );
		$fix = $this->fixFor( '/' );
		$this->assertSame( array( 'ok' => true ), $fix->apply( $this->preview( 'NEW', 'ORIGINAL', 'file' ) ) );
		$this->assertSame( 'ORIGINAL', $this->store->get( 'llms' )['original'] );
		$this->files->put( 'llms.txt', 'EDITED' );
		$r = $fix->undo();
		$this->assertStringContainsString( 'edited', $r['error'] );
		$this->assertSame( 'EDITED', $this->files->read( 'llms.txt' ) );
	}

	public function test_undo_restores_the_previous_virtual_fix(): void {
		$fix = $this->fixFor( '/' );
		$fix->apply( $this->preview( 'ONE', null ) );
		$fix->apply( $this->preview( 'TWO', 'ONE' ) );
		$this->assertSame( 'TWO', $this->store->get( 'llms' )['content'] );
		$this->assertSame( array( 'ok' => true ), $fix->undo() );
		$this->assertSame( 'ONE', $this->store->get( 'llms' )['content'] );
		$this->assertSame( array( 'ok' => true ), $fix->undo() );
		$this->assertSame( array(), $this->store->get( 'llms' ) );
		$this->assertSame( 'https://example.com/llms.txt', end( $this->caches->calls ) );
	}

	// Added by the controller rulings.

	public function test_site_input_cleans_titles_and_caps_lengths(): void {
		$this->blog['name']        = '';
		$this->blog['description'] = '<b>' . str_repeat( 'é', 600 ) . '</b>';
		Monkey\Functions\when( 'get_posts' )->justReturn( array( $this->post( 1 ) ) );
		Monkey\Functions\when( 'get_the_title' )->justReturn( 'It&#8217;s ' . str_repeat( 'a', 400 ) );
		$input = $this->fixFor( '/' )->site_input();
		$this->assertSame( 'example.com', $input['name'] );
		$this->assertSame( 500, mb_strlen( $input['tagline'] ) );
		$this->assertStringStartsWith( "It\u{2019}s a", $input['pages'][0]['title'] );
		$this->assertSame( 300, mb_strlen( $input['pages'][0]['title'] ) );
		$this->blog['name'] = '&amp; ' . str_repeat( 'n', 300 );
		$this->assertSame( 200, mb_strlen( $this->fixFor( '/' )->site_input()['name'] ) );
		$this->assertStringStartsWith( '& n', $this->fixFor( '/' )->site_input()['name'] );
	}

	public function test_site_input_caps_names_with_emoji_by_utf16_length(): void {
		$this->blog['name']        = str_repeat( 'n😀', 100 );
		$this->blog['description'] = str_repeat( '😀', 400 );
		$input                     = $this->fixFor( '/' )->site_input();
		$this->assertLessThanOrEqual( 200, intdiv( strlen( (string) iconv( 'UTF-8', 'UTF-16LE', $input['name'] ) ), 2 ) );
		$this->assertSame( 199, intdiv( strlen( (string) iconv( 'UTF-8', 'UTF-16LE', $input['name'] ) ), 2 ) );
		$this->assertSame( 250, mb_strlen( $input['tagline'] ) );
	}

	public function test_site_input_caps_items_at_200_pages_first(): void {
		$pages = array();
		$posts = array();
		for ( $i = 1; $i <= 150; $i++ ) {
			$pages[] = $this->post( $i );
		}
		for ( $i = 1001; $i <= 1100; $i++ ) {
			$posts[] = $this->post( $i );
		}
		Monkey\Functions\when( 'get_pages' )->justReturn( $pages );
		Monkey\Functions\when( 'get_posts' )->justReturn( $posts );
		$items = $this->fixFor( '/' )->site_input()['pages'];
		$this->assertCount( 200, $items );
		$this->assertSame( 'page', $items[0]['type'] );
		$this->assertSame( 'post', $items[150]['type'] );
	}

	public function test_skips_invalid_and_foreign_scheme_urls(): void {
		Monkey\Functions\when( 'get_posts' )->justReturn( array( $this->post( 1 ), $this->post( 2 ), $this->post( 3 ), $this->post( 4 ), $this->post( 5 ) ) );
		Monkey\Functions\when( 'get_permalink' )->alias(
			function ( $p ) {
				$urls = array(
					1 => 'javascript:alert(1)',
					2 => 'ftp://example.com/file',
					3 => 'https://example.com/' . str_repeat( 'a', 2100 ),
					4 => 'https://example.com/ok',
					5 => false,
				);
				return $urls[ $p->ID ];
			}
		);
		$urls = array_column( $this->fixFor( '/' )->site_input()['pages'], 'url' );
		$this->assertSame( array( 'https://example.com/ok' ), $urls );
	}

	public function test_preview_sends_the_cleaned_input_and_the_base(): void {
		Monkey\Functions\when( 'get_posts' )->justReturn( array( $this->post( 2 ) ) );
		$this->client->request_result = $this->response();
		$r = $this->fixFor( '/' )->preview( array( 'tagline' => 'Mine', 'name' => 'ignored', 'x' => 'y' ) );
		$sent = $this->client->requests[0];
		$this->assertSame( array( 'POST', '/api/v1/artifacts/llms_txt/merge' ), array( $sent[0], $sent[1] ) );
		$this->assertSame( 'd1', $sent[2]['domainId'] );
		$this->assertSame( array( 'mode' => 'virtual', 'content' => null ), $sent[2]['base'] );
		$this->assertSame( 'Example', $sent[2]['site']['name'] );
		$this->assertSame( 'Mine', $sent[2]['site']['tagline'] );
		$this->assertSame( hash( 'sha256', '' ), $r['base_sha256'] );
		$this->assertSame( 'virtual', $r['mode'] );
		$this->assertSame( "# Example\n", $r['content'] );
	}

	public function test_preview_in_file_mode_sends_the_file_as_base(): void {
		$this->files->put( 'llms.txt', 'OLD' );
		$this->client->request_result = $this->response();
		$r = $this->fixFor( '/' )->preview( array() );
		$this->assertSame( array( 'mode' => 'file', 'content' => 'OLD' ), $this->client->requests[0][2]['base'] );
		$this->assertSame( hash( 'sha256', 'OLD' ), $r['base_sha256'] );
	}

	public function test_preview_passes_an_api_error_through(): void {
		$this->client->request_result = new ApiError( 'x', 'Nope', 500 );
		$this->assertInstanceOf( ApiError::class, $this->fixFor( '/' )->preview( array() ) );
	}

	public function test_apply_refuses_when_base_changed_since_preview(): void {
		$this->files->put( 'llms.txt', 'OLD' );
		$preview = $this->preview( 'NEW', 'OLD', 'file' );
		$this->files->put( 'llms.txt', 'CHANGED' );
		$r = $this->fixFor( '/' )->apply( $preview );
		$this->assertSame( 'llms.txt changed after the preview. Preview again.', $r['error'] );
		$this->assertSame( 'CHANGED', $this->files->read( 'llms.txt' ) );
		$this->assertSame( array(), $this->store->get( 'llms' ) );
	}

	public function test_apply_refuses_when_the_mode_changed_since_preview(): void {
		$this->files->put( 'llms.txt', 'OLD' );
		$r = $this->fixFor( '/' )->apply( $this->preview( 'NEW', null, 'virtual' ) );
		$this->assertSame( 'llms.txt changed after the preview. Preview again.', $r['error'] );
	}

	public function test_apply_refuses_bad_content(): void {
		$fix = $this->fixFor( '/' );
		$this->assertArrayHasKey( 'error', $fix->apply( $this->preview( '', null ) ) );
		$this->assertArrayHasKey( 'error', $fix->apply( $this->preview( str_repeat( 'a', 600001 ), null ) ) );
		$bad = $this->preview( 'x', null );
		$bad['content'] = array( 'x' );
		$this->assertArrayHasKey( 'error', $fix->apply( $bad ) );
	}

	public function test_seo_plugin_check_failure_blocks_apply(): void {
		$this->seo->throws = true;
		$r = $this->fixFor( '/' )->apply( $this->preview( '# X', null ) );
		$this->assertSame( 'Could not check whether another plugin makes llms.txt. Try again.', $r['error'] );
		$this->assertSame( array(), $this->store->get( 'llms' ) );
	}

	public function test_head_request_sends_no_body(): void {
		$this->store->put( 'llms', array( 'mode' => 'virtual', 'content' => '# Example' ) );
		$fix = $this->fixFor( '/llms.txt', 'HEAD' );
		$fix->serve( null );
		$this->assertSame( '# Example', $fix->emitted );
		$this->assertFalse( $fix->body );
	}

	public function test_post_request_is_ignored(): void {
		$this->store->put( 'llms', array( 'mode' => 'virtual', 'content' => '# Example' ) );
		foreach ( array( 'POST', 'PUT', 'OPTIONS' ) as $method ) {
			$fix = $this->fixFor( '/llms.txt', $method );
			$fix->serve( null );
			$this->assertNull( $fix->emitted, $method );
		}
	}

	public function test_serve_never_calls_network(): void {
		Monkey\Functions\expect( 'wp_remote_request' )->never();
		Monkey\Functions\expect( 'wp_remote_post' )->never();
		$this->store->put( 'llms', array( 'mode' => 'virtual', 'content' => '# Example' ) );
		$fix = $this->fixFor( '/llms.txt' );
		$fix->serve( null );
		$this->assertSame( array(), $this->client->requests );
		$this->assertSame( array(), $this->client->forms );
		$this->assertSame( '# Example', $fix->emitted );
	}

	public function test_serve_ignores_a_file_record_and_a_missing_fix(): void {
		$fix = $this->fixFor( '/llms.txt' );
		$fix->serve( null );
		$this->assertNull( $fix->emitted );
		$this->store->put( 'llms', array( 'mode' => 'file', 'content' => 'x' ) );
		$fix->serve( null );
		$this->assertNull( $fix->emitted );
	}

	public function test_does_not_work_when_the_root_folder_is_unknown(): void {
		$this->files->root = false;
		$r = $this->fixFor( '/' )->apply( $this->preview( '# X', null ) );
		$this->assertArrayHasKey( 'error', $r );
		$p = $this->fixFor( '/' )->preview( array() );
		$this->assertArrayHasKey( 'error', $p );
		$this->assertSame( array(), $this->client->requests );
	}

	// Fix round A, G1: multisite file safety.

	public function test_sub_site_with_a_physical_file_is_refused_without_a_write(): void {
		$this->files->may = false;
		$this->files->put( 'llms.txt', 'NETWORK FILE' );
		$fix  = $this->fixFor( '/' );
		$text = 'On a multisite network, only a network admin on the main site can change llms.txt in the site root.';
		$this->assertSame( 'network', $fix->mode() );
		$this->assertSame( $text, $fix->refusal( 'network' ) );
		$this->assertSame( array( 'error' => $text ), $fix->preview( array() ) );
		$this->assertSame( array(), $this->client->requests );
		$this->assertSame( array( 'error' => $text ), $fix->apply( $this->preview( 'NEW', 'NETWORK FILE', 'file' ) ) );
		$this->assertSame( 0, $this->files->writes );
		$this->assertSame( 'NETWORK FILE', $this->files->read( 'llms.txt' ) );
		$this->assertSame( array(), $this->store->get( 'llms' ) );
	}

	public function test_sub_site_cannot_write_llms_txt_as_a_file(): void {
		$this->files->may = false;
		$fix              = $this->fixFor( '/' );
		$this->assertSame( 'virtual', $fix->mode() );
		$r = $fix->apply( $this->preview( "# Example\n", null ), true );
		$this->assertSame( 'On a multisite network, only a network admin on the main site can change llms.txt in the site root.', $r['error'] );
		$this->assertSame( 0, $this->files->writes );
		$this->assertFalse( $this->files->exists( 'llms.txt' ) );
		$this->assertSame( array(), $this->store->get( 'llms' ) );
	}

	public function test_sub_site_without_a_physical_file_keeps_the_virtual_fix(): void {
		$this->files->may = false;
		$this->assertSame( array( 'ok' => true ), $this->fixFor( '/' )->apply( $this->preview( "# Example\n", null ) ) );
		$this->assertSame( 'virtual', $this->store->get( 'llms' )['mode'] );
		$fix = $this->fixFor( '/llms.txt' );
		$fix->serve( null );
		$this->assertSame( "# Example\n", $fix->emitted );
	}

	// Fix round A, G3: the backup must save before a file changes.

	public function test_file_apply_refuses_when_the_backup_does_not_save(): void {
		$this->files->put( 'llms.txt', 'ORIGINAL' );
		Monkey\Functions\when( 'update_option' )->justReturn( false );
		$r = $this->fixFor( '/' )->apply( $this->preview( 'NEW', 'ORIGINAL', 'file' ) );
		$this->assertSame( 'GenReady could not save its backup of llms.txt, so it did not change the file.', $r['error'] );
		$this->assertSame( 0, $this->files->writes );
		$this->assertSame( 'ORIGINAL', $this->files->read( 'llms.txt' ) );
	}

	public function test_virtual_apply_reports_a_record_that_does_not_save(): void {
		Monkey\Functions\when( 'update_option' )->justReturn( false );
		$r = $this->fixFor( '/' )->apply( $this->preview( '# X', null ) );
		$this->assertSame( 'GenReady could not save the fix. Try again.', $r['error'] );
		$this->assertSame( array(), $this->caches->calls );
	}

	// Fix round A, G6: serve() yields to an SEO plugin and to sent headers.

	public function test_serve_yields_to_an_seo_plugin_that_makes_llms_txt(): void {
		$this->store->put( 'llms', array( 'mode' => 'virtual', 'content' => '# Example' ) );
		$this->seo->owner = array( 'plugin' => 'Yoast SEO', 'setting' => 'Yoast SEO → Settings' );
		$fix              = $this->fixFor( '/llms.txt' );
		$fix->serve( null );
		$this->assertNull( $fix->emitted );
	}

	public function test_serve_yields_when_the_seo_check_fails(): void {
		$this->store->put( 'llms', array( 'mode' => 'virtual', 'content' => '# Example' ) );
		$this->seo->throws = true;
		$fix               = $this->fixFor( '/llms.txt' );
		$fix->serve( null );
		$this->assertNull( $fix->emitted );
	}

	public function test_serve_does_nothing_after_the_headers_were_sent(): void {
		$this->store->put( 'llms', array( 'mode' => 'virtual', 'content' => '# Example' ) );
		$fix       = $this->fixFor( '/llms.txt' );
		$fix->sent = true;
		$fix->serve( null );
		$this->assertNull( $fix->emitted );
	}

	public function test_serve_checks_the_seo_plugins_only_for_llms_txt(): void {
		$this->store->put( 'llms', array( 'mode' => 'virtual', 'content' => '# Example' ) );
		$this->seo->throws = true;
		$fix               = $this->fixFor( '/other' );
		$fix->serve( null );
		$this->assertNull( $fix->emitted );
		$this->seo->throws = false;
		$fix               = $this->fixFor( '/llms.txt' );
		$fix->serve( null );
		$this->assertSame( '# Example', $fix->emitted );
	}
}
