<?php
namespace GenReady\Tests\Unit;

use GenReady\Fixes\SchemaGraph;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/fixtures/schema-graphs.php';

/** The pure graph merge, moved out of JsonLdFixTest with SchemaGraph. */
class SchemaGraphTest extends TestCase {
	public function test_fills_empty_values_but_keeps_real_ones(): void {
		// All in One SEO builds CollectionPage with "description" => "" and drops empty values only after the filter.
		$graph = array(
			array(
				'@type'            => 'CollectionPage',
				'name'             => 'Site name',
				'description'      => '',
				'email'            => null,
				'sameAs'           => array(),
				'position'         => 0,
				'isFamilyFriendly' => false,
			),
		);
		$node  = array(
			'@type'            => 'WebPage',
			'name'             => 'GenReady name',
			'description'      => 'GenReady description',
			'email'            => 'site@example.com',
			'sameAs'           => array( 'https://example.com/profile' ),
			'position'         => 3,
			'isFamilyFriendly' => true,
		);
		$out   = SchemaGraph::merge_into_graph( $graph, $node );
		$this->assertCount( 1, $out );
		$this->assertSame( 'Site name', $out[0]['name'] );
		$this->assertSame( 'GenReady description', $out[0]['description'] );
		$this->assertSame( 'site@example.com', $out[0]['email'] );
		$this->assertSame( array( 'https://example.com/profile' ), $out[0]['sameAs'] );
		$this->assertSame( 0, $out[0]['position'] );
		$this->assertFalse( $out[0]['isFamilyFriendly'] );
		$this->assertSame( 'CollectionPage', $out[0]['@type'] );
	}

	public function test_never_overwrites_existing_property(): void {
		$graph = array(
			array(
				'@type'  => 'Article',
				'author' => array(
					'@type' => 'Person',
					'name'  => 'Yoast Author',
				),
			),
		);
		$node  = array(
			'@context'     => 'https://schema.org',
			'@type'        => 'Article',
			'author'       => 'Ana',
			'dateModified' => '2026-10-01T10:00:00+00:00',
		);
		$out   = SchemaGraph::merge_into_graph( $graph, $node );
		$this->assertSame( 'Yoast Author', $out[0]['author']['name'] );
		$this->assertSame( '2026-10-01T10:00:00+00:00', $out[0]['dateModified'] );
		$this->assertArrayNotHasKey( '@context', $out[0] );
		$this->assertCount( 1, $out );
	}

	public function test_appends_a_node_of_a_new_type_without_context(): void {
		$out = SchemaGraph::merge_into_graph( array( array( '@type' => 'WebPage' ) ), array( '@context' => 'https://schema.org', '@type' => 'FAQPage' ) );
		$this->assertCount( 2, $out );
		$this->assertArrayNotHasKey( '@context', $out[1] );
	}

	public function test_matches_a_type_array(): void {
		$out = SchemaGraph::merge_into_graph( array( array( '@type' => array( 'Article', 'BlogPosting' ) ) ), array( '@type' => 'BlogPosting', 'headline' => 'H' ) );
		$this->assertCount( 1, $out );
		$this->assertSame( 'H', $out[0]['headline'] );
		$out = SchemaGraph::merge_into_graph( array( array( '@type' => 'Organization' ) ), array( '@type' => array( 'Organization', 'Corporation' ), 'name' => 'N' ) );
		$this->assertCount( 1, $out );
		$this->assertSame( 'N', $out[0]['name'] );
	}

	public function test_article_family_counts_as_one_type(): void {
		$out = SchemaGraph::merge_into_graph( SchemaGraphs::aioseo(), array( '@type' => 'Article', 'headline' => 'Ours', 'dateModified' => '2026-10-01T10:00:00+00:00' ) );
		$this->assertCount( 4, $out );
		$this->assertSame( 'AIOSEO headline', $out[2]['headline'] );
		$this->assertSame( '2026-10-01T10:00:00+00:00', $out[2]['dateModified'] );
		$this->assertSame( 'BlogPosting', $out[2]['@type'] );
		// An exact type match wins over a family match.
		$out = SchemaGraph::merge_into_graph( array( array( '@type' => 'CollectionPage' ), array( '@type' => 'WebPage' ) ), array( '@type' => 'WebPage', 'name' => 'N' ) );
		$this->assertArrayNotHasKey( 'name', $out[0] );
		$this->assertSame( 'N', $out[1]['name'] );
		// A blog home page that Yoast marks as CollectionPage gets no second page node.
		$out = SchemaGraph::merge_into_graph( array( array( '@type' => 'CollectionPage' ) ), array( '@type' => 'WebPage', 'description' => 'D' ) );
		$this->assertCount( 1, $out );
		$this->assertSame( 'D', $out[0]['description'] );
	}

	public function test_merge_skips_non_array_entries_and_keeps_keys(): void {
		$out = SchemaGraph::merge_into_graph( array( 'x', 7 => array( 'no' => 'type' ), 9 => array( '@type' => 'Article' ) ), array( '@type' => 'Article', 'headline' => 'H' ) );
		$this->assertSame( 'x', $out[0] );
		$this->assertSame( array( 'no' => 'type' ), $out[7] );
		$this->assertSame( 'H', $out[9]['headline'] );
		$this->assertCount( 3, $out );
	}

	public function test_node_types_accepts_a_string_or_a_list_of_strings_only(): void {
		$this->assertSame( array( 'Article' ), SchemaGraph::node_types( array( '@type' => 'Article' ) ) );
		$this->assertSame( array( 'A', 'B' ), SchemaGraph::node_types( array( '@type' => array( 'A', 'B' ) ) ) );
		$this->assertSame( array(), SchemaGraph::node_types( array( '@type' => array( 'A', 3 ) ) ) );
		$this->assertSame( array(), SchemaGraph::node_types( array( '@type' => '' ) ) );
	}
}
