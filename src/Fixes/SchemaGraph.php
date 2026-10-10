<?php
/**
 * Schema graph logic for the JSON-LD fix.
 *
 * @package GenReady
 */

namespace GenReady\Fixes;

defined( 'ABSPATH' ) || exit;

/**
 * Pure functions on schema.org graphs: the types of a node, type groups, and
 * a merge that never adds a duplicate and never replaces a real value.
 */
final class SchemaGraph {
	/**
	 * Types that describe the same thing on one page. Two nodes from one group
	 * would be a duplicate, so a match inside a group counts as the same type.
	 * FAQPage and QAPage stay out of the page group: they describe the page's
	 * questions, so they sit next to a WebPage node as their own node.
	 */
	const TYPE_GROUPS = array(
		array( 'Article', 'NewsArticle', 'BlogPosting', 'TechArticle', 'ScholarlyArticle', 'LiveBlogPosting', 'Report' ),
		array( 'WebPage', 'CollectionPage', 'ProfilePage', 'AboutPage', 'ContactPage', 'ItemPage', 'SearchResultsPage', 'CheckoutPage', 'MedicalWebPage', 'RealEstateListing' ),
		array( 'Organization', 'Corporation', 'LocalBusiness', 'OnlineBusiness', 'NGO', 'EducationalOrganization', 'NewsMediaOrganization' ),
	);

	/**
	 * Add a node to a schema graph without a duplicate.
	 *
	 * A node of the same type (see types_match()) gets only the properties it
	 * does not have, or has empty (null, a blank string, an empty list); no
	 * real value is ever replaced. An exact type match wins over a
	 * group match. With no match the node is added. '@context' is never added
	 * to a graph node. Entries that are not arrays with a '@type' are skipped.
	 * Keys are kept, so the same code merges a list or keyed entities.
	 *
	 * @param array $graph Graph nodes (a list, or entities keyed by name).
	 * @param array $node  The node to add.
	 * @return array The new graph.
	 */
	public static function merge_into_graph( array $graph, array $node ): array {
		return self::merge( $graph, $node, null );
	}

	/**
	 * Merge a node into graph entities, keeping their keys.
	 *
	 * @param array       $graph   Graph entities.
	 * @param array       $node    Node to add.
	 * @param string|null $new_key Key for a new node, or null to append it.
	 * @return array
	 */
	public static function merge( array $graph, array $node, ?string $new_key ): array {
		unset( $node['@context'] );
		$key = self::find_match( $graph, self::node_types( $node ) );
		if ( null === $key ) {
			if ( null === $new_key ) {
				$graph[] = $node;
			} else {
				$graph[ $new_key ] = $node;
			}
			return $graph;
		}
		foreach ( $node as $prop => $value ) {
			if ( ! array_key_exists( $prop, $graph[ $key ] ) || self::is_empty_value( $graph[ $key ][ $prop ] ) ) {
				$graph[ $key ][ $prop ] = $value;
			}
		}
		return $graph;
	}

	/**
	 * Tell whether a graph value holds nothing: null, a blank string or an
	 * empty list. All in One SEO sets such values and removes them only after
	 * its schema filter runs, so they count as missing. 0 and false are values.
	 *
	 * @param mixed $value Graph value.
	 */
	private static function is_empty_value( $value ): bool {
		return null === $value || array() === $value || ( is_string( $value ) && '' === trim( $value ) );
	}

	/**
	 * The key of the graph node that has the same type: an exact match first,
	 * then a match in the same type group.
	 *
	 * @param array    $graph Graph entities.
	 * @param string[] $types Types of the node.
	 * @return int|string|null
	 */
	public static function find_match( array $graph, array $types ) {
		if ( array() === $types ) {
			return null;
		}
		foreach ( array( false, true ) as $grouped ) {
			foreach ( $graph as $key => $entity ) {
				if ( is_array( $entity ) && isset( $entity['@type'] ) && self::types_match( $types, self::node_types( $entity ), $grouped ) ) {
					return $key;
				}
			}
		}
		return null;
	}

	/**
	 * Tell whether two type lists share a type, or, when grouped, a type group.
	 *
	 * @param string[] $a       Types.
	 * @param string[] $b       Types.
	 * @param bool     $grouped Count types in one group as the same.
	 */
	public static function types_match( array $a, array $b, bool $grouped ): bool {
		if ( $grouped ) {
			$a = self::with_groups( $a );
			$b = self::with_groups( $b );
		}
		return array() !== array_intersect( $a, $b );
	}

	/**
	 * Add every type of a group that one of the types belongs to.
	 *
	 * @param string[] $types Types.
	 * @return string[]
	 */
	private static function with_groups( array $types ): array {
		foreach ( self::TYPE_GROUPS as $group ) {
			if ( array() !== array_intersect( $types, $group ) ) {
				$types = array_merge( $types, $group );
			}
		}
		return array_values( array_unique( $types ) );
	}

	/**
	 * The types of a node: a string or a list of strings. Anything else gives
	 * an empty list.
	 *
	 * @param array $node Node.
	 * @return string[]
	 */
	public static function node_types( array $node ): array {
		$type = $node['@type'] ?? null;
		if ( is_string( $type ) && '' !== $type ) {
			return array( $type );
		}
		if ( ! is_array( $type ) || array() === $type ) {
			return array();
		}
		foreach ( $type as $one ) {
			if ( ! is_string( $one ) || '' === $one ) {
				return array();
			}
		}
		return array_values( $type );
	}
}
