<?php
namespace GenReady\Tests\Unit;

use Brain\Monkey;
use GenReady\Api\ApiError;
use GenReady\Compat\SeoPlugins;
use GenReady\Fixes\FixStore;
use GenReady\Fixes\JsonLdFix;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/fixtures/doubles.php';
require_once dirname( __DIR__ ) . '/fixtures/fix-doubles.php';
require_once dirname( __DIR__ ) . '/fixtures/schema-graphs.php';

/** SEO plugin check with a scripted list of active plugins. */
class ActiveSeoDouble extends SeoPlugins {
	/** @var string[] */
	public $active = array();

	public function __construct() {
	}

	public function active(): array {
		return $this->active;
	}
}

class JsonLdFixTest extends TestCase {
	const REPORT = '0b6f6b4e-1d2c-4a5b-8c9d-0e1f2a3b4c5d';

	/** @var array */
	private $options = array();
	/** @var array */
	private $meta = array();
	/** @var array */
	private $view = array();
	/** @var FakeClient */
	private $client;
	/** @var RecordingCaches */
	private $caches;
	/** @var ActiveSeoDouble */
	private $seo;
	/** @var JsonLdFix */
	private $fix;
	/** @var array */
	private $posts = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->options = array();
		$this->meta    = array();
		$this->view    = array(
			'singular' => false,
			'front'    => false,
			'id'       => 0,
		);
		$this->posts   = array(
			5 => (object) array(
				'ID'          => 5,
				'post_author' => 3,
				'post_title'  => 'Hello &amp; <em>world</em>',
				'date'        => '2026-09-01T08:00:00+00:00',
				'modified'    => '2026-10-01T10:00:00+00:00',
			),
		);
		$opts  = &$this->options;
		$meta  = &$this->meta;
		$view  = &$this->view;
		$posts = &$this->posts;
		Monkey\Functions\when( 'get_option' )->alias( function ( $k, $d = false ) use ( &$opts ) { return $opts[ $k ] ?? $d; } );
		Monkey\Functions\when( 'update_option' )->alias( function ( $k, $v, $a = null ) use ( &$opts ) { $opts[ $k ] = $v; return true; } );
		Monkey\Functions\when( 'delete_option' )->alias( function ( $k ) use ( &$opts ) { unset( $opts[ $k ] ); return true; } );
		Monkey\Functions\when( 'get_post_meta' )->alias( function ( $id, $k = '', $single = false ) use ( &$meta ) { return $meta[ $id ][ $k ] ?? ''; } );
		Monkey\Functions\when( 'update_post_meta' )->alias( function ( $id, $k, $v ) use ( &$meta ) { $meta[ $id ][ $k ] = stripslashes_deep_stub( $v ); return true; } );
		Monkey\Functions\when( 'wp_slash' )->alias( function ( $v ) { return is_array( $v ) ? array_map( __NAMESPACE__ . '\\addslashes_deep_stub', $v ) : ( is_string( $v ) ? addslashes( $v ) : $v ); } );
		Monkey\Functions\when( 'delete_post_meta' )->alias( function ( $id, $k ) use ( &$meta ) { unset( $meta[ $id ][ $k ] ); return true; } );
		Monkey\Functions\when( 'is_singular' )->alias( function () use ( &$view ) { return $view['singular']; } );
		Monkey\Functions\when( 'is_front_page' )->alias( function () use ( &$view ) { return $view['front']; } );
		Monkey\Functions\when( 'get_queried_object_id' )->alias( function () use ( &$view ) { return $view['id']; } );
		Monkey\Functions\when( 'get_post' )->alias( function ( $id ) use ( &$posts ) { return $posts[ $id ] ?? null; } );
		Monkey\Functions\when( 'get_the_title' )->alias( function ( $p ) { return $p->post_title; } );
		Monkey\Functions\when( 'get_the_author_meta' )->alias( function ( $field, $id ) { return 3 === $id ? ' <b>Ana</b>  Lee ' : ''; } );
		Monkey\Functions\when( 'get_post_time' )->alias( function ( $f, $gmt, $p ) { return $p->date; } );
		Monkey\Functions\when( 'get_post_modified_time' )->alias( function ( $f, $gmt, $p ) { return $p->modified; } );
		Monkey\Functions\when( 'get_bloginfo' )->alias( function ( $k ) { return 'name' === $k ? 'Example &amp; Co' : '<i>A tagline</i>'; } );
		Monkey\Functions\when( 'home_url' )->alias( function ( $path = '' ) { return 'https://example.com' . $path; } );
		Monkey\Functions\when( 'get_permalink' )->alias( function ( $id ) { return 'https://example.com/?p=' . $id; } );
		Monkey\Functions\when( 'url_to_postid' )->alias( function ( $url ) { return 'https://example.com/hello/' === $url ? 5 : 0; } );
		Monkey\Functions\when( 'wp_parse_url' )->alias( 'parse_url' );
		Monkey\Functions\when( 'wp_json_encode' )->alias( function ( $data, $flags = 0 ) { return json_encode( $data, $flags ); } );
		Monkey\Functions\when( 'wp_strip_all_tags' )->alias( function ( $s ) { return trim( strip_tags( $s ) ); } );
		Monkey\Functions\when( 'sanitize_text_field' )->alias( function ( $s ) { return trim( preg_replace( '/\s+/', ' ', strip_tags( $s ) ) ); } );
		Monkey\Functions\when( 'sanitize_textarea_field' )->alias( function ( $s ) { return trim( strip_tags( $s ) ); } );
		Monkey\Functions\when( 'esc_url_raw' )->returnArg( 1 );
		Monkey\Functions\when( 'wp_http_validate_url' )->alias( function ( $u ) { return false !== filter_var( $u, FILTER_VALIDATE_URL ) && in_array( parse_url( $u, PHP_URL_SCHEME ), array( 'http', 'https' ), true ) ? $u : false; } );
		Monkey\Functions\when( '__' )->returnArg( 1 );
		$this->client = new FakeClient();
		$this->caches = new RecordingCaches();
		$this->seo    = new ActiveSeoDouble();
		$this->fix    = new JsonLdFix( new FixStore(), $this->client, $this->caches, $this->seo, 'd1' );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/** View post 5 with a stored record. */
	private function givenAppliedNode( array $node, array $extra = array() ): void {
		$this->view                                 = array(
			'singular' => true,
			'front'    => false,
			'id'       => 5,
		);
		$this->meta[5][ JsonLdFix::META ] = array_merge( array( 'node' => $node ), $extra );
	}

	private function printed(): string {
		ob_start();
		$this->fix->print_script();
		return (string) ob_get_clean();
	}

	private function answer( array $node, array $missing = array(), array $extra = array() ): array {
		return array_merge(
			array(
				'type'     => is_string( $node['@type'] ) ? $node['@type'] : $node['@type'][0],
				'node'     => array_merge( array( '@context' => 'https://schema.org' ), $node ),
				'missing'  => $missing,
				'changed'  => true,
				'summary'  => array( 'Adds markup' ),
				'notes'    => array(),
				'existing' => array(
					'types'        => array(),
					'missingProps' => array(),
				),
			),
			$extra
		);
	}

	private function preview_for( array $answer, array $overrides = array(), string $url = 'https://example.com/hello/' ) {
		$this->client->request_result = $answer;
		return $this->fix->preview( self::REPORT, $url, $overrides );
	}

	// Plan tests.





	public function test_escapes_script_breaking_text(): void {
		$this->givenAppliedNode( array( '@context' => 'https://schema.org', '@type' => 'Article', 'headline' => '</script><script>alert(1)</script>' ) );
		$html = $this->printed();
		$this->assertStringNotContainsString( '</script><script>', $html );
		$this->assertStringContainsString( '</script>', $html );
		$this->assertStringStartsWith( '<script type="application/ld+json">', $html );
	}

	public function test_prints_nothing_when_an_seo_plugin_is_active(): void {
		$this->seo->active = array( 'yoast' );
		$this->givenAppliedNode( array( '@type' => 'Article', 'headline' => 'H' ) );
		$this->assertSame( '', $this->printed() );
	}

	public function test_corrupt_meta_fails_safe(): void {
		$this->view = array(
			'singular' => true,
			'front'    => false,
			'id'       => 5,
		);
		Monkey\Functions\when( 'get_post_meta' )->justReturn( 'x' );
		$graph = array( array( '@type' => 'WebPage' ) );
		$this->assertSame( $graph, $this->fix->yoast( $graph ) );
		$this->assertSame( $graph, $this->fix->aioseo( $graph ) );
		$this->assertSame( array( 'WebPage' => $graph[0] ), $this->fix->rankmath( array( 'WebPage' => $graph[0] ) ) );
		$this->assertSame( '', $this->printed() );
		foreach ( array( array( 'node' => 'x' ), array( 'node' => array( 'headline' => 'H' ) ), array( 'node' => array( '@type' => array( 'Article', 3 ) ) ), array( 'node' => array( '@type' => array() ) ) ) as $bad ) {
			Monkey\Functions\when( 'get_post_meta' )->justReturn( $bad );
			$this->assertSame( $graph, $this->fix->yoast( $graph ) );
			$this->assertSame( '', $this->printed() );
		}
	}

	public function test_refuses_apply_with_todo_fields(): void {
		$this->seo->active = array( 'yoast' );
		$r = $this->fix->apply(
			array(
				'type'           => 'Article',
				'node'           => array( '@type' => 'Article', 'image' => 'TODO: image' ),
				'changed'        => true,
				'existing_types' => array(),
				'target'         => array( 'post_id' => 5 ),
			)
		);
		$this->assertArrayHasKey( 'error', $r );
		$this->assertArrayNotHasKey( 5, $this->meta );
	}

	// Brief tests.

	public function test_fills_text_props_locally_and_refuses_bad_dates(): void {
		$answer = $this->answer(
			array(
				'@type'         => 'Article',
				'url'           => 'https://example.com/hello/',
				'headline'      => 'Measured headline',
				'author'        => 'TODO: author',
				'datePublished' => 'TODO: datePublished',
				'dateModified'  => 'TODO: dateModified',
			),
			array( 'author', 'datePublished', 'dateModified' )
		);
		$p      = $this->preview_for(
			$answer,
			array(
				'author'        => '<b>Ana</b>',
				'headline'      => 'Not used',
				'datePublished' => '2026-10-01T10:00:00+02:00',
				'image'         => 'https://example.com/x.png',
			)
		);
		$this->assertSame( 'Ana', $p['node']['author'] );
		$this->assertSame( 'Measured headline', $p['node']['headline'] );
		$this->assertSame( '2026-10-01T10:00:00+02:00', $p['node']['datePublished'] );
		$this->assertSame( 'TODO: dateModified', $p['node']['dateModified'] );
		$this->assertArrayNotHasKey( 'image', $p['node'] );
		$this->assertSame( array( 'dateModified' ), $p['missing'] );
		$this->assertSame( array( 'dateModified' ), $p['fillable'] );
		$this->assertSame( array(), $p['blocked_props'] );

		$long = $this->fix->fill( $p, array( 'dateModified' => '2026-10-02T09:00:00+00:00' ) );
		$this->assertSame( array(), $long['missing'] );
		$this->assertSame( 'https://example.com/hello/', $long['node']['url'] );

		foreach ( array( 'yesterday', '2026-10-01', '2026-10-01T10:00:00Z', '2026-13-01T10:00:00+00:00', '-0001-11-30T00:00:00+00:00' ) as $bad ) {
			$r = $this->fix->fill( $p, array( 'dateModified' => $bad ) );
			$this->assertArrayHasKey( 'error', $r, $bad );
		}
		$this->assertArrayHasKey( 'error', $this->preview_for( $answer, array( 'datePublished' => 'soon' ) ) );

		$capped = $this->fix->fill( $this->preview_for( $answer ), array( 'author' => str_repeat( 'é', 400 ) ) );
		$this->assertSame( 300, mb_strlen( $capped['node']['author'] ) );
	}

	public function test_fills_description_and_logo_with_their_own_rules(): void {
		$answer = $this->answer(
			array(
				'@type' => 'Organization',
				'url'   => 'https://example.com/',
				'name'  => 'TODO: name',
				'logo'  => 'TODO: logo',
			),
			array( 'name', 'logo' )
		);
		$p      = $this->preview_for( $answer );
		$this->assertArrayHasKey( 'error', $this->fix->fill( $p, array( 'logo' => 'javascript:alert(1)' ) ) );
		$this->assertArrayHasKey( 'error', $this->fix->fill( $p, array( 'logo' => 'ftp://example.com/logo.png' ) ) );
		$ok = $this->fix->fill( $p, array( 'logo' => 'https://example.com/logo.png', 'name' => '  ' ) );
		$this->assertSame( 'https://example.com/logo.png', $ok['node']['logo'] );
		$this->assertSame( 'TODO: name', $ok['node']['name'] );

		$page = $this->answer(
			array(
				'@type'       => 'WebPage',
				'name'        => 'P',
				'description' => 'TODO: description',
			),
			array( 'description' )
		);
		$d    = $this->fix->fill( $this->preview_for( $page ), array( 'description' => str_repeat( 'a', 600 ) ) );
		$this->assertSame( 500, strlen( $d['node']['description'] ) );
	}

	public function test_blocks_offers_and_main_entity(): void {
		$this->seo->active = array( 'yoast' );
		$product           = $this->answer(
			array(
				'@type'       => 'Product',
				'name'        => 'Shoe',
				'description' => 'TODO: description',
				'offers'      => 'TODO: offers',
			),
			array( 'description', 'offers' )
		);
		$p                 = $this->preview_for( $product, array( 'offers' => '10 EUR', 'description' => 'A shoe' ) );
		$this->assertSame( array( 'offers' ), $p['blocked_props'] );
		$this->assertSame( 'TODO: offers', $p['node']['offers'] );
		$r = $this->fix->apply( $p );
		$this->assertSame( 'This page needs offers, which GenReady cannot fill in from a form. Add it with your SEO plugin or theme.', $r['error'] );

		$faq = $this->answer( array( '@type' => 'FAQPage', 'mainEntity' => 'TODO: mainEntity' ), array( 'mainEntity' ) );
		$p   = $this->preview_for( $faq );
		$this->assertSame( array( 'mainEntity' ), $p['blocked_props'] );
		$this->assertSame( 'This page needs mainEntity, which GenReady cannot fill in from a form. Add it with your SEO plugin or theme.', $this->fix->apply( $p )['error'] );
		$this->assertArrayNotHasKey( 5, $this->meta );
	}

	public function test_refuses_apply_without_existing_when_no_seo_plugin(): void {
		$answer = $this->answer( array( '@type' => 'Article', 'headline' => 'H' ) );
		unset( $answer['existing'] );
		$p = $this->preview_for( $answer );
		$this->assertNull( $p['existing_types'] );
		$r = $this->fix->apply( $p );
		$this->assertSame( 'GenReady needs a newer server answer to check this page for existing markup. Try again later.', $r['error'] );
		$this->assertArrayNotHasKey( 5, $this->meta );

		$this->seo->active = array( 'rankmath' );
		$this->assertSame( array( 'ok' => true ), $this->fix->apply( $p ) );
		$this->assertSame( array(), $this->meta[5][ JsonLdFix::META ]['existing_types'] );
	}

	public function test_refuses_apply_when_the_page_already_has_the_type_and_no_seo_plugin(): void {
		$answer = $this->answer(
			array( '@type' => 'Article', 'headline' => 'H' ),
			array(),
			array(
				'existing' => array(
					'types'        => array( 'BlogPosting', 'WebSite' ),
					'missingProps' => array( 'dateModified' ),
				),
			)
		);
		$p      = $this->preview_for( $answer );
		$this->assertSame( array( 'BlogPosting', 'WebSite' ), $p['existing_types'] );
		$this->assertArrayHasKey( 'error', $this->fix->apply( $p ) );
		$this->assertArrayNotHasKey( 5, $this->meta );
	}

	public function test_does_not_print_when_theme_already_has_the_type(): void {
		$this->givenAppliedNode( array( '@type' => 'Article', 'headline' => 'H' ), array( 'existing_types' => array( 'Article' ) ) );
		$this->assertSame( '', $this->printed() );
		$this->givenAppliedNode( array( '@type' => array( 'Article' ), 'headline' => 'H' ), array( 'existing_types' => array( 'NewsArticle' ) ) );
		$this->assertSame( '', $this->printed() );
		$this->givenAppliedNode( array( '@type' => 'Article', 'headline' => 'H' ), array( 'existing_types' => array( 'WebSite' ) ) );
		$this->assertStringContainsString( '"headline":"H"', $this->printed() );
	}

	public function test_filter_skips_type_that_exists_outside_the_seo_graph(): void {
		$this->seo->active = array( 'yoast' );
		$this->givenAppliedNode( array( '@type' => 'Article', 'headline' => 'H' ), array( 'existing_types' => array( 'Article' ) ) );
		$graph = array( array( '@type' => 'WebPage' ) );
		$this->assertSame( $graph, $this->fix->yoast( $graph ) );
		$this->assertSame( $graph, $this->fix->aioseo( $graph ) );
		$this->assertSame( array( 'WebPage' => $graph[0] ), $this->fix->rankmath( array( 'WebPage' => $graph[0] ) ) );
		// When the SEO graph has the type, the fix still fills its gaps.
		$out = $this->fix->yoast( array( array( '@type' => 'Article' ) ) );
		$this->assertSame( array( array( '@type' => 'Article', 'headline' => 'H' ) ), $out );
	}



	public function test_yoast_shape(): void {
		$this->seo->active = array( 'yoast' );
		$this->givenAppliedNode( array( '@context' => 'https://schema.org', '@type' => 'Article', 'headline' => 'Ours', 'author' => 'Ana', 'dateModified' => '2026-10-01T10:00:00+00:00' ), array( 'existing_types' => array( 'Article' ) ) );
		$out = $this->fix->yoast( SchemaGraphs::yoast() );
		$this->assertCount( 3, $out );
		$this->assertSame( 'Yoast headline', $out[0]['headline'] );
		$this->assertSame( 'Yoast Author', $out[0]['author']['name'] );
		$this->assertSame( '2026-10-01T10:00:00+00:00', $out[0]['dateModified'] );
		$this->assertSame( SchemaGraphs::yoast()[1], $out[1] );
	}

	public function test_rankmath_shape_merges_by_key_and_adds_genready_key(): void {
		$this->seo->active = array( 'rankmath' );
		$this->givenAppliedNode( array( '@context' => 'https://schema.org', '@type' => 'BlogPosting', 'headline' => 'Ours', 'dateModified' => '2026-10-01T10:00:00+00:00' ) );
		$out = $this->fix->rankmath( SchemaGraphs::rankmath() );
		$this->assertSame( array( 'publisher', 'WebSite', 'WebPage', 'richSnippet' ), array_keys( $out ) );
		$this->assertSame( 'Rank Math headline', $out['richSnippet']['headline'] );
		$this->assertSame( 'RM Author', $out['richSnippet']['author']['name'] );
		$this->assertSame( '2026-10-01T10:00:00+00:00', $out['richSnippet']['dateModified'] );
		$this->assertArrayNotHasKey( '@context', $out['richSnippet'] );

		$this->givenAppliedNode( array( '@context' => 'https://schema.org', '@type' => 'Product', 'name' => 'Shoe' ) );
		$out = $this->fix->rankmath( SchemaGraphs::rankmath() );
		$this->assertSame( array( 'publisher', 'WebSite', 'WebPage', 'richSnippet', 'genready' ), array_keys( $out ) );
		$this->assertSame( array( '@type' => 'Product', 'name' => 'Shoe' ), $out['genready'] );

		// An Organization node fills the publisher, whose @type is an array.
		$this->givenAppliedNode( array( '@type' => 'Organization', 'name' => 'Ours', 'logo' => 'https://example.com/logo.png' ) );
		$out = $this->fix->rankmath( SchemaGraphs::rankmath() );
		$this->assertSame( 'Example', $out['publisher']['name'] );
		$this->assertSame( 'https://example.com/logo.png', $out['publisher']['logo'] );
		$this->assertArrayNotHasKey( 'genready', $out );
	}

	public function test_aioseo_shape(): void {
		$this->seo->active = array( 'aioseo' );
		$this->givenAppliedNode( array( '@context' => 'https://schema.org', '@type' => 'BlogPosting', 'headline' => 'Ours', 'author' => 'Ana', 'datePublished' => '2026-09-01T08:00:00+00:00' ) );
		$out = $this->fix->aioseo( SchemaGraphs::aioseo() );
		$this->assertCount( 4, $out );
		$this->assertSame( array( 0, 1, 2, 3 ), array_keys( $out ) );
		$this->assertSame( 'AIOSEO headline', $out[2]['headline'] );
		$this->assertSame( array( '@id' => 'https://example.com/author/ana/#author' ), $out[2]['author'] );
		$this->assertSame( '2026-09-01T08:00:00+00:00', $out[2]['datePublished'] );

		$this->givenAppliedNode( array( '@context' => 'https://schema.org', '@type' => 'Product', 'name' => 'Shoe' ) );
		$out = $this->fix->aioseo( SchemaGraphs::aioseo() );
		$this->assertCount( 5, $out );
		$this->assertSame( array( '@type' => 'Product', 'name' => 'Shoe' ), $out[4] );
	}

	public function test_todo_check_is_recursive(): void {
		$this->seo->active = array( 'yoast' );
		$r = $this->fix->apply(
			array(
				'type'           => 'Article',
				'node'           => array(
					'@type'    => 'Article',
					'headline' => 'H',
					'author'   => array(
						'@type' => 'Person',
						'name'  => array( 'TODO: name' ),
					),
				),
				'changed'        => true,
				'existing_types' => array(),
				'blocked_props'  => array(),
				'target'         => array( 'post_id' => 5 ),
			)
		);
		$this->assertSame( 'Fill in author first.', $r['error'] );
		$this->assertArrayNotHasKey( 5, $this->meta );
	}

	public function test_front_end_never_calls_network(): void {
		Monkey\Functions\expect( 'wp_remote_request' )->never();
		Monkey\Functions\expect( 'wp_remote_post' )->never();
		$fix = new JsonLdFix(
			new FixStore(),
			$this->client,
			$this->caches,
			$this->seo,
			function () {
				throw new \LogicException( 'The front end must not load the connection.' );
			}
		);
		$this->givenAppliedNode( array( '@type' => 'Article', 'headline' => 'H' ), array( 'existing_types' => array() ) );
		ob_start();
		$fix->print_script();
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( '"headline":"H"', $html );
		$this->seo->active = array( 'yoast', 'rankmath', 'aioseo' );
		$fix->yoast( SchemaGraphs::yoast() );
		$fix->rankmath( SchemaGraphs::rankmath() );
		$fix->aioseo( SchemaGraphs::aioseo() );
		$this->assertSame( array(), $this->client->requests );
		$this->assertSame( array(), $this->caches->calls );
		$this->assertSame( array(), $this->options );
	}

	public function test_undo_restores_previous_record(): void {
		$this->seo->active = array( 'yoast' );
		$first             = $this->preview_for( $this->answer( array( '@type' => 'Article', 'headline' => 'First' ) ) );
		$second            = $this->preview_for( $this->answer( array( '@type' => 'Article', 'headline' => 'Second' ) ) );
		$this->assertSame( array( 'ok' => true ), $this->fix->apply( $first ) );
		$this->assertSame( array( 'ok' => true ), $this->fix->apply( $second ) );
		$record = $this->meta[5][ JsonLdFix::META ];
		$this->assertSame( 'Second', $record['node']['headline'] );
		$this->assertCount( 1, $record['versions'] );
		$this->assertSame( array( 'https://example.com/?p=5', 'https://example.com/?p=5' ), $this->caches->calls );

		$this->assertSame( array( 'ok' => true ), $this->fix->undo( array( 'post_id' => 5 ) ) );
		$record = $this->meta[5][ JsonLdFix::META ];
		$this->assertSame( 'First', $record['node']['headline'] );
		$this->assertSame( 'Article', $record['type'] );
		$this->assertSame( array(), $record['versions'] );
		$this->assertSame( array( 'ok' => true ), $this->fix->undo( array( 'post_id' => 5 ) ) );
		$this->assertArrayNotHasKey( JsonLdFix::META, $this->meta[5] );
		$this->assertArrayHasKey( 'error', $this->fix->undo( array( 'post_id' => 5 ) ) );
		$this->assertCount( 4, $this->caches->calls );
	}

	public function test_backslashes_survive_post_meta(): void {
		$this->seo->active = array( 'yoast' );
		$this->assertSame( array( 'ok' => true ), $this->fix->apply( $this->preview_for( $this->answer( array( '@type' => 'Article', 'headline' => 'C:\\path "q"' ) ) ) ) );
		$this->assertSame( 'C:\\path "q"', $this->meta[5][ JsonLdFix::META ]['node']['headline'] );
	}

	public function test_keeps_five_earlier_records(): void {
		$this->seo->active = array( 'yoast' );
		for ( $i = 1; $i <= 7; $i++ ) {
			$this->fix->apply( $this->preview_for( $this->answer( array( '@type' => 'Article', 'headline' => 'H' . $i ) ) ) );
		}
		$versions = $this->meta[5][ JsonLdFix::META ]['versions'];
		$this->assertCount( 5, $versions );
		$this->assertSame( 'H2', $versions[0]['node']['headline'] );
		$this->assertSame( 'H6', $versions[4]['node']['headline'] );
		$this->assertArrayNotHasKey( 'versions', $versions[4] );
	}

	public function test_home_target_lives_in_the_fix_store(): void {
		$this->options['show_on_front'] = 'posts';
		$this->assertSame( array( 'home' => true ), $this->fix->target_for( 'https://example.com/' ) );
		$answer = $this->answer(
			array(
				'@type'       => 'WebPage',
				'url'         => 'https://example.com/',
				'name'        => 'Example & Co',
				'description' => 'TODO: description',
			),
			array( 'description' )
		);
		$p      = $this->preview_for( $answer, array(), 'https://example.com/' );
		$body   = $this->client->requests[0][2];
		$this->assertSame( array( 'title' => 'Example & Co' ), (array) $body['wp'] );
		$this->assertSame( 'A tagline', $p['node']['description'] );
		$this->assertSame( array( 'home' => true ), $p['target'] );
		$this->assertSame( array( 'ok' => true ), $this->fix->apply( $p ) );
		$this->assertSame( 'A tagline', $this->options['genready_fix_jsonld_home']['node']['description'] );
		$this->assertSame( array( 'https://example.com/' ), $this->caches->calls );

		// The blog home page prints the home node; a post does not.
		$this->view = array(
			'singular' => false,
			'front'    => true,
			'id'       => 0,
		);
		$this->assertStringContainsString( '"description":"A tagline"', $this->printed() );
		$this->view = array(
			'singular' => true,
			'front'    => false,
			'id'       => 5,
		);
		$this->assertSame( '', $this->printed() );

		$this->assertSame( array( 'ok' => true ), $this->fix->undo( array( 'home' => true ) ) );
		$this->assertArrayNotHasKey( 'genready_fix_jsonld_home', $this->options );
	}

	public function test_target_for_maps_posts_static_front_page_and_unknown_urls(): void {
		$this->assertSame( array( 'post_id' => 5 ), $this->fix->target_for( 'https://example.com/hello/' ) );
		$this->assertSame( array(), $this->fix->target_for( 'https://example.com/missing/' ) );
		$this->assertSame( array(), $this->fix->target_for( 'https://other.example/' ) );
		$this->options['show_on_front'] = 'page';
		$this->options['page_on_front'] = '5';
		$this->assertSame( array( 'post_id' => 5 ), $this->fix->target_for( 'https://example.com' ) );
	}

	public function test_preview_sends_clean_wp_facts_and_validates_input(): void {
		$p    = $this->preview_for( $this->answer( array( '@type' => 'Article', 'headline' => 'H' ) ) );
		$call = $this->client->requests[0];
		$this->assertSame( array( 'POST', '/api/v1/artifacts/jsonld/merge' ), array( $call[0], $call[1] ) );
		$this->assertSame( 'd1', $call[2]['domainId'] );
		$this->assertSame( self::REPORT, $call[2]['reportId'] );
		$this->assertSame(
			array(
				'title'         => 'Hello & world',
				'author'        => 'Ana Lee',
				'datePublished' => '2026-09-01T08:00:00+00:00',
				'dateModified'  => '2026-10-01T10:00:00+00:00',
			),
			(array) $call[2]['wp']
		);
		$this->assertSame( array( 'post_id' => 5 ), $p['target'] );
		$this->assertTrue( $p['changed'] );
		$this->assertSame( 'Article', $p['type'] );

		// Dates that are empty or not ISO 8601 with an offset are left out.
		$this->posts[5]->date     = false;
		$this->posts[5]->modified = '-0001-11-30T00:00:00+00:00';
		$this->preview_for( $this->answer( array( '@type' => 'Article', 'headline' => 'H' ) ) );
		$this->assertSame( array( 'title', 'author' ), array_keys( (array) $this->client->requests[1][2]['wp'] ) );

		// The server counts UTF-16 code units, so an emoji counts twice.
		$this->posts[5]->post_title = str_repeat( '😀', 200 );
		$this->preview_for( $this->answer( array( '@type' => 'Article', 'headline' => 'H' ) ) );
		$this->assertSame( 150, mb_strlen( $this->client->requests[2][2]['wp']->title ) );

		$calls = count( $this->client->requests );
		$this->assertArrayHasKey( 'error', $this->fix->preview( 'not-a-uuid', 'https://example.com/hello/', array() ) );
		$this->assertArrayHasKey( 'error', $this->fix->preview( self::REPORT, 'https://example.com/missing/', array() ) );
		$this->assertCount( $calls, $this->client->requests );
	}

	public function test_preview_passes_errors_and_rejects_a_bad_answer(): void {
		$this->client->request_result = new ApiError( 402, 'PLAN_REQUIRED', 'Plan required' );
		$this->assertInstanceOf( ApiError::class, $this->fix->preview( self::REPORT, 'https://example.com/hello/', array() ) );
		foreach ( array( array(), array( 'type' => 'Article', 'node' => 'x' ), array( 'type' => 'Article', 'node' => array( 'headline' => 'H' ) ), array( 'type' => 'Article', 'node' => array( '@type' => 'Product' ) ) ) as $bad ) {
			$this->client->request_result = $bad;
			$this->assertArrayHasKey( 'error', $this->fix->preview( self::REPORT, 'https://example.com/hello/', array() ) );
		}
		$fix = new JsonLdFix( new FixStore(), $this->client, $this->caches, $this->seo, '' );
		$this->assertSame( array( 'error' => 'Reconnect GenReady.' ), $fix->preview( self::REPORT, 'https://example.com/hello/', array() ) );
	}

	public function test_unchanged_answer_is_shown_but_not_applied(): void {
		$this->seo->active = array( 'yoast' );
		$p                 = $this->preview_for( $this->answer( array( '@type' => 'Article', 'headline' => 'H' ), array(), array( 'changed' => false ) ) );
		$this->assertFalse( $p['changed'] );
		$this->assertArrayHasKey( 'error', $this->fix->apply( $p ) );
		$this->assertArrayNotHasKey( 5, $this->meta );
	}

	public function test_registers_protected_meta_for_administrators_only(): void {
		$args = null;
		Monkey\Functions\when( 'register_post_meta' )->alias( function ( $type, $key, $a ) use ( &$args ) { $args = array( $type, $key, $a ); return true; } );
		$this->fix->register_meta();
		$this->assertSame( array( '', '_genready_jsonld' ), array( $args[0], $args[1] ) );
		$this->assertTrue( $args[2]['single'] );
		$this->assertFalse( $args[2]['show_in_rest'] );
		Monkey\Functions\when( 'current_user_can' )->alias( function ( $cap ) { return 'manage_options' === $cap; } );
		$this->assertTrue( ( $args[2]['auth_callback'] )() );
		Monkey\Functions\when( 'current_user_can' )->justReturn( false );
		$this->assertFalse( ( $args[2]['auth_callback'] )() );
	}

	public function test_apply_refuses_a_bad_preview(): void {
		$this->seo->active = array( 'yoast' );
		$good              = $this->preview_for( $this->answer( array( '@type' => 'Article', 'headline' => 'H' ) ) );
		$bad               = array(
			array_merge( $good, array( 'target' => array() ) ),
			array_merge( $good, array( 'target' => array( 'post_id' => '5' ) ) ),
			array_merge( $good, array( 'node' => array( 'headline' => 'H' ) ) ),
			array_merge( $good, array( 'type' => 'Product' ) ),
		);
		foreach ( $bad as $preview ) {
			$this->assertArrayHasKey( 'error', $this->fix->apply( $preview ) );
		}
		$this->assertArrayNotHasKey( 5, $this->meta );
	}

	// Fix round A, G3: a record that does not save is an error.

	public function test_apply_reports_post_meta_that_does_not_save(): void {
		$this->seo->active = array( 'yoast' );
		Monkey\Functions\when( 'update_post_meta' )->justReturn( false );
		$r = $this->fix->apply( $this->preview_for( $this->answer( array( '@type' => 'Article', 'headline' => 'H' ) ) ) );
		$this->assertSame( 'GenReady could not save the structured data. Try again.', $r['error'] );
		$this->assertSame( array(), $this->caches->calls );
	}

	public function test_undo_reports_post_meta_that_does_not_save(): void {
		$this->seo->active = array( 'yoast' );
		$this->fix->apply( $this->preview_for( $this->answer( array( '@type' => 'Article', 'headline' => 'First' ) ) ) );
		$this->fix->apply( $this->preview_for( $this->answer( array( '@type' => 'Article', 'headline' => 'Second' ) ) ) );
		Monkey\Functions\when( 'update_post_meta' )->justReturn( false );
		$r = $this->fix->undo( array( 'post_id' => 5 ) );
		$this->assertSame( 'GenReady could not save the structured data. Try again.', $r['error'] );
		$this->assertSame( 'Second', $this->meta[5][ JsonLdFix::META ]['node']['headline'] );
	}

	public function test_apply_reports_a_home_record_that_does_not_save(): void {
		$this->seo->active = array( 'yoast' );
		Monkey\Functions\when( 'update_option' )->justReturn( false );
		$p = $this->preview_for( $this->answer( array( '@type' => 'WebPage', 'name' => 'Example & Co' ) ), array(), 'https://example.com/' );
		$this->assertSame( 'GenReady could not save the structured data. Try again.', $this->fix->apply( $p )['error'] );
	}

	// Fix round A, G4: JSON-LD hardening.

	const UNCHECKABLE = "The structured data from GenReady did not pass the plugin's safety check. Try again later.";

	/**
	 * @dataProvider bad_keys
	 */
	public function test_preview_refuses_keys_the_plugin_cannot_check( array $node ): void {
		$this->seo->active = array( 'yoast' );
		$answer            = $this->answer( array( '@type' => 'Article', 'headline' => 'H' ) );
		$answer['node']    = array_merge( $answer['node'], $node );
		$this->assertSame( array( 'error' => self::UNCHECKABLE ), $this->preview_for( $answer ) );
	}

	public function bad_keys(): array {
		return array(
			'script in a key'     => array( array( '</script><script>x' => 'v' ) ),
			'space'               => array( array( 'head line' => 'v' ) ),
			'starts with a digit' => array( array( '1abc' => 'v' ) ),
			'number key'          => array( array( 'list' => array( 'a', 'b' ), 5 => 'v' ) ),
			'too long'            => array( array( 'a' . str_repeat( 'b', 64 ) => 'v' ) ),
			'nested'              => array( array( 'author' => array( 'name' => 'A', 'na"me' => 'B' ) ) ),
			'in a list'           => array( array( 'author' => array( array( '@type' => 'Person', 'x>y' => 'B' ) ) ) ),
			'empty'               => array( array( '' => 'v' ) ),
		);
	}

	public function test_preview_accepts_schema_keys_and_lists(): void {
		$this->seo->active = array( 'yoast' );
		$node              = array(
			'@type'    => array( 'Article', 'BlogPosting' ),
			'@id'      => 'https://example.com/hello/#article',
			'headline' => 'H',
			'keywords' => array( 'a', 'b' ),
			'author'   => array( array( '@type' => 'Person', 'name' => 'Ana', 'sameAs' => array( 'https://x.example/' ) ) ),
			'og:title' => 'T',
			'snake_case-key' => 'v',
			'a' . str_repeat( 'b', 63 ) => 'v',
		);
		$preview = $this->preview_for( $this->answer( $node ) );
		$this->assertArrayNotHasKey( 'error', $preview );
		$this->assertSame( array( 'a', 'b' ), $preview['node']['keywords'] );
	}

	public function test_preview_replaces_angle_brackets_in_every_string(): void {
		$this->seo->active = array( 'yoast' );
		$node              = array(
			'@type'    => 'Article',
			'headline' => 'Tom & </script><script>alert(1)</script>',
			'author'   => array(
				'@type' => 'Person',
				'name'  => '<b>Ana</b>',
				'list'  => array( 'a<b', 'c>d', 3, true ),
			),
		);
		$preview = $this->preview_for( $this->answer( $node ) );
		$this->assertSame( "Tom & \u{2039}/script\u{203A}\u{2039}script\u{203A}alert(1)\u{2039}/script\u{203A}", $preview['node']['headline'] );
		$this->assertSame( "\u{2039}b\u{203A}Ana\u{2039}/b\u{203A}", $preview['node']['author']['name'] );
		$this->assertSame( array( "a\u{2039}b", "c\u{203A}d", 3, true ), $preview['node']['author']['list'] );
		$this->assertSame( array( 'ok' => true ), $this->fix->apply( $preview ) );
		$stored = wp_json_encode( $this->meta[5][ JsonLdFix::META ]['node'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		$this->assertStringNotContainsString( '<', $stored );
		$this->assertStringNotContainsString( '>', $stored );
	}

	public function test_preview_refuses_a_node_over_32_kb(): void {
		$this->seo->active = array( 'yoast' );
		$big               = $this->answer( array( '@type' => 'Article', 'headline' => 'H', 'articleBody' => str_repeat( 'a', 32768 ) ) );
		$this->assertSame( array( 'error' => self::UNCHECKABLE ), $this->preview_for( $big ) );
		$fits = $this->answer( array( '@type' => 'Article', 'headline' => 'H', 'articleBody' => str_repeat( 'a', 32000 ) ) );
		$this->assertArrayNotHasKey( 'error', $this->preview_for( $fits ) );
	}
}

/** Like WordPress's wp_slash() for nested arrays. */
function addslashes_deep_stub( $v ) {
	return is_array( $v ) ? array_map( __NAMESPACE__ . '\\addslashes_deep_stub', $v ) : ( is_string( $v ) ? addslashes( $v ) : $v );
}

/** Like WordPress's wp_unslash() for nested arrays, as update_metadata() does. */
function stripslashes_deep_stub( $v ) {
	return is_array( $v ) ? array_map( __NAMESPACE__ . '\\stripslashes_deep_stub', $v ) : ( is_string( $v ) ? stripslashes( $v ) : $v );
}
