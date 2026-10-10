<?php
namespace GenReady\Tests\Unit;

use Brain\Monkey;
use GenReady\Compat\SeoPlugins;
use PHPUnit\Framework\TestCase;

class SeoPluginsTest extends TestCase {
	/** @var array */
	private $options = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->options = array();
		$opts          = &$this->options;
		Monkey\Functions\when( 'get_option' )->alias( function ( $k, $d = false ) use ( &$opts ) { return $opts[ $k ] ?? $d; } );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_no_seo_plugin_means_no_owner(): void {
		$seo = new SeoPlugins();
		$this->assertSame( array(), $seo->active() );
		$this->assertNull( $seo->llms_txt_owner() );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_yoast_owns_llms_txt_when_the_feature_is_on(): void {
		define( 'WPSEO_VERSION', '25.3' );
		$this->options['wpseo'] = array( 'enable_llms_txt' => true );
		$seo                    = new SeoPlugins();
		$this->assertSame( array( 'yoast' ), $seo->active() );
		$owner = $seo->llms_txt_owner();
		$this->assertSame( 'Yoast SEO', $owner['plugin'] );
		$this->assertStringContainsString( 'Site features', $owner['setting'] );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_yoast_does_not_own_llms_txt_when_the_feature_is_off_or_old(): void {
		define( 'WPSEO_VERSION', '25.3' );
		$seo                    = new SeoPlugins();
		$this->options['wpseo'] = array( 'enable_llms_txt' => false );
		$this->assertNull( $seo->llms_txt_owner() );
		$this->options['wpseo'] = array();
		$this->assertNull( $seo->llms_txt_owner() );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_yoast_before_25_3_has_no_llms_txt(): void {
		define( 'WPSEO_VERSION', '25.2' );
		$this->options['wpseo'] = array( 'enable_llms_txt' => true );
		$this->assertNull( ( new SeoPlugins() )->llms_txt_owner() );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_rank_math_owns_llms_txt_when_the_module_is_on(): void {
		define( 'RANK_MATH_VERSION', '1.0.250' );
		$this->options['rank_math_modules'] = array( 'sitemap', 'llms-txt' );
		$seo                                = new SeoPlugins();
		$this->assertSame( array( 'rankmath' ), $seo->active() );
		$owner = $seo->llms_txt_owner();
		$this->assertSame( 'Rank Math SEO', $owner['plugin'] );
		$this->assertStringContainsString( 'LLMS Txt', $owner['setting'] );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_rank_math_does_not_own_llms_txt_when_the_module_is_off(): void {
		define( 'RANK_MATH_VERSION', '1.0.280' );
		$this->options['rank_math_modules'] = array( 'sitemap' );
		$this->assertNull( ( new SeoPlugins() )->llms_txt_owner() );
		$this->options['rank_math_modules'] = 'broken';
		$this->assertNull( ( new SeoPlugins() )->llms_txt_owner() );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_aioseo_owns_llms_txt_when_enabled_in_its_options(): void {
		define( 'AIOSEO_VERSION', '4.9.0' );
		$this->options['aioseo_options'] = wp_json_encode_stub( array( 'sitemap' => array( 'llms' => array( 'enable' => true ) ) ) );
		$seo                             = new SeoPlugins();
		$this->assertSame( array( 'aioseo' ), $seo->active() );
		$owner = $seo->llms_txt_owner();
		$this->assertSame( 'All in One SEO', $owner['plugin'] );
		$this->assertStringContainsString( 'Sitemaps', $owner['setting'] );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_aioseo_does_not_own_llms_txt_when_turned_off(): void {
		define( 'AIOSEO_VERSION', '4.9.0' );
		$this->options['aioseo_options'] = wp_json_encode_stub( array( 'sitemap' => array( 'llms' => array( 'enable' => false ) ) ) );
		$this->assertNull( ( new SeoPlugins() )->llms_txt_owner() );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_aioseo_with_unreadable_options_counts_as_owner(): void {
		define( 'AIOSEO_VERSION', '5.0.3' );
		// Its docs say the feature is on by default; the code default is off. Unknown fails toward not overwriting.
		foreach ( array( false, 'not json', wp_json_encode_stub( array( 'sitemap' => array() ) ) ) as $value ) {
			$this->options['aioseo_options'] = $value;
			$this->assertSame( 'All in One SEO', ( new SeoPlugins() )->llms_txt_owner()['plugin'] );
		}
	}
}

/** JSON like AIOSEO stores it. */
function wp_json_encode_stub( array $value ): string {
	return (string) json_encode( $value );
}
