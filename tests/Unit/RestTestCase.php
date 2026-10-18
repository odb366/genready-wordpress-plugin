<?php
namespace GenReady\Tests\Unit;

use Brain\Monkey;
use GenReady\Admin\FixCards;
use GenReady\Admin\Reports;
use GenReady\Admin\Rest;
use GenReady\Compat\SeoPlugins;
use GenReady\Connection\ApiKey;
use GenReady\Connection\OAuth;
use GenReady\Connection\SiteSetup;
use GenReady\Connection\TokenStore;
use GenReady\Fixes\FixStore;
use GenReady\Fixes\JsonLdFix;
use GenReady\Fixes\LlmsTxtFix;
use GenReady\Fixes\RobotsFix;
use GenReady\Onboarding\AccountCreator;
use GenReady\Onboarding\AnonymousScans;
use GenReady\Onboarding\Proof;
use GenReady\Verification\MetaTag;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/fixtures/doubles.php';
require_once dirname( __DIR__ ) . '/fixtures/fix-doubles.php';
require_once dirname( __DIR__ ) . '/fixtures/rest-doubles.php';

/**
 * Shared setUp and helpers of the Rest tests.
 */
abstract class RestTestCase extends TestCase {
	const REPORT_ID = '11111111-2222-3333-4444-555555555555';
	const BASE      = "User-agent: *\nDisallow: /wp-admin/\n";

	/** @var array */
	protected $options = array();
	/** @var array */
	protected $transients = array();
	/** @var array */
	protected $user_meta = array();
	/** @var TokenStore */
	protected $store;
	/** @var FakeClient */
	protected $client;
	/** @var MemoryFiles */
	protected $files;
	/** @var FixStore */
	protected $fixes;
	/** @var RecordingCaches */
	protected $caches;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->options    = array( 'blog_public' => '1' );
		$this->transients = array();
		$this->user_meta  = array();
		$opts             = &$this->options;
		$trans            = &$this->transients;
		$meta             = &$this->user_meta;
		Monkey\Functions\when( 'wp_salt' )->justReturn( str_repeat( 's', 64 ) );
		Monkey\Functions\when( 'get_option' )->alias( function ( $k, $d = false ) use ( &$opts ) { return $opts[ $k ] ?? $d; } );
		Monkey\Functions\when( 'update_option' )->alias( function ( $k, $v ) use ( &$opts ) { $opts[ $k ] = $v; return true; } );
		Monkey\Functions\when( 'delete_option' )->alias( function ( $k ) use ( &$opts ) { unset( $opts[ $k ] ); return true; } );
		Monkey\Functions\when( 'get_transient' )->alias( function ( $k ) use ( &$trans ) { return $trans[ $k ] ?? false; } );
		Monkey\Functions\when( 'set_transient' )->alias( function ( $k, $v ) use ( &$trans ) { $trans[ $k ] = $v; return true; } );
		Monkey\Functions\when( 'delete_transient' )->alias( function ( $k ) use ( &$trans ) { unset( $trans[ $k ] ); return true; } );
		Monkey\Functions\when( 'update_user_meta' )->alias( function ( $u, $k, $v ) use ( &$meta ) { $meta[ $u ][ $k ] = $v; return true; } );
		Monkey\Functions\when( '__' )->returnArg( 1 );
		Monkey\Functions\when( 'sanitize_text_field' )->alias( function ( $v ) { return trim( strip_tags( (string) $v ) ); } );
		Monkey\Functions\when( 'sanitize_textarea_field' )->alias( function ( $v ) { return trim( strip_tags( (string) $v ) ); } );
		Monkey\Functions\when( 'home_url' )->alias( function ( $path = '' ) { return 'https://example.com' . $path; } );
		Monkey\Functions\when( 'wp_parse_url' )->alias( 'parse_url' );
		Monkey\Functions\when( 'get_current_user_id' )->justReturn( 7 );
		Monkey\Functions\when( 'current_user_can' )->justReturn( true );
		Monkey\Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Monkey\Functions\when( 'rest_url' )->alias( function ( $p ) { return 'https://example.com/wp-json/' . $p; } );
		Monkey\Functions\when( 'admin_url' )->alias( function ( $p ) { return 'https://example.com/wp-admin/' . $p; } );
		Monkey\Functions\when( 'esc_url_raw' )->returnArg( 1 );
		Monkey\Functions\when( 'wp_strip_all_tags' )->alias( 'strip_tags' );
		Monkey\Functions\when( 'get_bloginfo' )->justReturn( 'Example' );
		Monkey\Functions\when( '_n' )->alias( function ( $one, $many, $n ) { return 1 === $n ? $one : $many; } );
		Monkey\Functions\when( 'wp_date' )->alias( function ( $format, $time ) { return gmdate( $format, $time ); } );
		Monkey\Functions\when( 'sanitize_email' )->alias( function ( $e ) { return trim( (string) $e ); } );
		Monkey\Functions\when( 'is_email' )->alias( function ( $e ) { return false !== filter_var( $e, FILTER_VALIDATE_EMAIL ) ? $e : false; } );
		$this->options['time_format'] = 'H:i';
		$this->store  = new TokenStore();
		$this->client = new FakeClient();
		$this->files  = new MemoryFiles();
		$this->fixes  = new FixStore();
		$this->caches = new RecordingCaches();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	protected function scans(): AnonymousScans {
		return new AnonymousScans( $this->store, $this->client );
	}

	protected function rest(): Rest {
		$domain = function (): string {
			$connection = $this->store->load();
			return is_array( $connection ) ? (string) ( $connection['domain_id'] ?? '' ) : '';
		};
		$meta   = new MetaTag();
		$seo    = new SeoPlugins( $this->files );
		$robots = new RobotsFix( $this->fixes, $this->files, $this->client, $this->caches, $domain );
		$llms   = new LlmsTxtFix( $this->fixes, $this->files, $this->client, $this->caches, $seo, $domain );
		$jsonld = new JsonLdFix( $this->fixes, $this->client, $this->caches, $seo, $domain );
		$scans  = $this->scans();
		return new Rest(
			$this->store,
			$this->client,
			new OAuth( $this->store, $this->client ),
			new ApiKey( $this->store, $this->client ),
			new SiteSetup( $this->store, $this->client, $meta, $this->caches ),
			$meta,
			$this->caches,
			new Reports( $this->store, $this->client, $scans ),
			new FixCards( $this->fixes, $robots, $llms, $jsonld, $this->files ),
			$scans,
			new AccountCreator( $this->store, $this->client, new Proof(), $scans, $meta, $this->caches )
		);
	}

	protected function connect(): void {
		$this->store->save(
			array(
				'method'    => 'api_key',
				'api_key'   => 'gr_live_abcdefghijklmnop',
				'domain_id' => 'd1',
			)
		);
	}

	protected function robots_merge( string $content ): array {
		return array(
			'content'         => $content,
			'diff'            => "--- a\n+++ b\n+User-agent: GPTBot",
			'summary'         => array( 'Allows GPTBot.' ),
			'notes'           => array(),
			'changed'         => true,
			'needsHumanInput' => false,
			'baseSha256'      => hash( 'sha256', self::BASE ),
			'live'            => null,
		);
	}

	protected function fix( Rest $rest, string $kind, string $action, array $params = array() ) {
		return $rest->fix( new FakeRequest( array_merge( array( 'kind' => $kind, 'action' => $action ), $params ) ) );
	}

	protected function assert_error( $result, string $message, int $status = 400 ): void {
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( $message, $result->get_error_message() );
		$this->assertSame( $status, $result->get_error_data()['status'] );
	}

	protected function cards(): FixCards {
		$seo = new SeoPlugins( $this->files );
		return new FixCards(
			$this->fixes,
			new RobotsFix( $this->fixes, $this->files, $this->client, $this->caches, 'd1' ),
			new LlmsTxtFix( $this->fixes, $this->files, $this->client, $this->caches, $seo, 'd1' ),
			new JsonLdFix( $this->fixes, $this->client, $this->caches, $seo, 'd1' ),
			$this->files
		);
	}
}
