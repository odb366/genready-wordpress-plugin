<?php
namespace GenReady\Tests\Unit;

/**
 * What each SEO plugin passes to its schema filter, built from the plugins' source (read 2026-10-10).
 */
final class SchemaGraphs {
	/**
	 * Yoast SEO, filter 'wpseo_schema_graph': a list of graph pieces. Yoast adds '@context' and '@graph' after the filter.
	 * Source: https://raw.githubusercontent.com/Yoast/wordpress-seo/trunk/src/generators/schema-generator.php
	 */
	public static function yoast(): array {
		return array(
			array(
				'@type'    => 'Article',
				'@id'      => 'https://example.com/post/#article',
				'headline' => 'Yoast headline',
				'author'   => array(
					'name' => 'Yoast Author',
					'@id'  => 'https://example.com/#/schema/person/1',
				),
			),
			array(
				'@type' => 'WebPage',
				'@id'   => 'https://example.com/post/',
				'url'   => 'https://example.com/post/',
				'name'  => 'Post',
			),
			array(
				'@type' => 'WebSite',
				'@id'   => 'https://example.com/#website',
			),
		);
	}

	/**
	 * Rank Math, filter 'rank_math/json_ld': entities keyed by name ('publisher', 'WebSite', 'WebPage', 'richSnippet', ...).
	 * Rank Math wraps array_values() of it in '@graph' after the filter.
	 * Sources: https://plugins.svn.wordpress.org/seo-by-rank-math/trunk/includes/modules/schema/class-jsonld.php
	 * and includes/modules/schema/snippets/class-{publisher,website,webpage,singular}.php.
	 */
	public static function rankmath(): array {
		return array(
			'publisher'   => array(
				'@type' => array( 'Person', 'Organization' ),
				'@id'   => 'https://example.com/#person',
				'name'  => 'Example',
			),
			'WebSite'     => array(
				'@type' => 'WebSite',
				'@id'   => 'https://example.com/#website',
				'url'   => 'https://example.com',
			),
			'WebPage'     => array(
				'@type' => 'WebPage',
				'@id'   => 'https://example.com/post/#webpage',
				'url'   => 'https://example.com/post/',
				'name'  => 'Post',
			),
			'richSnippet' => array(
				'@type'         => 'BlogPosting',
				'headline'      => 'Rank Math headline',
				'datePublished' => '2026-09-01T08:00:00+00:00',
				'author'        => array(
					'@id'  => 'https://example.com/author/ana/',
					'name' => 'RM Author',
				),
			),
		);
	}

	/**
	 * All in One SEO, filter 'aioseo_schema_output': the '@graph' list only, not the whole document.
	 * Source: https://plugins.svn.wordpress.org/all-in-one-seo-pack/trunk/app/Common/Schema/Helpers.php
	 * ( $schema['@graph'] = apply_filters( 'aioseo_schema_output', $schema['@graph'] ) ).
	 */
	public static function aioseo(): array {
		return array(
			array(
				'@type' => 'BreadcrumbList',
				'@id'   => 'https://example.com/post/#breadcrumblist',
			),
			array(
				'@type' => 'Organization',
				'@id'   => 'https://example.com/#organization',
				'name'  => 'Example',
			),
			array(
				'@type'    => 'BlogPosting',
				'@id'      => 'https://example.com/post/#blogposting',
				'headline' => 'AIOSEO headline',
				'author'   => array( '@id' => 'https://example.com/author/ana/#author' ),
			),
			array(
				'@type' => 'WebPage',
				'@id'   => 'https://example.com/post/#webpage',
			),
		);
	}
}
