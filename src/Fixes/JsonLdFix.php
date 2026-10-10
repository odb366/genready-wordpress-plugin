<?php
/**
 * The JSON-LD fix.
 *
 * @package GenReady
 */

namespace GenReady\Fixes;

use GenReady\Api\ApiError;
use GenReady\Api\Client;
use GenReady\Compat\Caches;
use GenReady\Compat\SeoPlugins;

defined( 'ABSPATH' ) || exit;

/**
 * Preview, apply and undo one JSON-LD node for one scanned page: a post or
 * page (post meta) or the blog home page (the 'jsonld_home' fix record).
 *
 * The plugin never prints duplicate schema:
 * - With Yoast SEO, Rank Math or All in One SEO active, the node goes into
 *   that plugin's graph. A node of the same type gets only the properties it
 *   does not have. A new node is added only when the page has no node of that
 *   type anywhere, not even outside the SEO plugin's graph.
 * - With no SEO plugin, the plugin prints one script tag, and only when the
 *   scan found no markup of that type on the page.
 *
 * The front-end methods (print_script and the three filters) read local data
 * only. They never load the connection, call the API or write anything, and a
 * Throwable leaves the original output unchanged.
 */
final class JsonLdFix {
	/** Post meta key of a post's record. */
	const META = '_genready_jsonld';
	/** Fix kind of the home page record in the fix store. */
	const HOME_KIND = 'jsonld_home';
	/** Earlier records kept per target. */
	const MAX_VERSIONS = 5;
	/** Longest title or author name the server accepts, in UTF-16 code units. */
	const MAX_TEXT = 300;
	/** Longest description the form accepts. */
	const MAX_DESCRIPTION = 500;
	/** Key of a new node in Rank Math's entities. */
	const RANK_MATH_KEY = 'genready';
	/** Prefix of a value the server could not fill. */
	const TODO = 'TODO: ';

	/**
	 * Properties the preview form can fill, and how each is checked.
	 */
	const FILLABLE = array(
		'headline'      => 'text',
		'name'          => 'text',
		'author'        => 'text',
		'description'   => 'textarea',
		'datePublished' => 'date',
		'dateModified'  => 'date',
		'logo'          => 'url',
	);

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
	 * Fix storage, for the home page record.
	 *
	 * @var FixStore
	 */
	private $store;
	/**
	 * API client.
	 *
	 * @var Client
	 */
	private $client;
	/**
	 * Page cache purger.
	 *
	 * @var Caches
	 */
	private $caches;
	/**
	 * SEO plugin check.
	 *
	 * @var SeoPlugins
	 */
	private $seo;
	/**
	 * GenReady domain ID, or a callable that returns it when preview() runs.
	 *
	 * @var string|callable
	 */
	private $domain_id;

	/**
	 * Build the fix.
	 *
	 * @param FixStore        $store     Fix storage.
	 * @param Client          $client    API client.
	 * @param Caches          $caches    Page caches.
	 * @param SeoPlugins      $seo       SEO plugin check.
	 * @param string|callable $domain_id Domain ID, or a callable that returns it. A callable keeps the
	 *                                   front end from loading (and decrypting) the connection.
	 */
	public function __construct( FixStore $store, Client $client, Caches $caches, SeoPlugins $seo, $domain_id ) {
		$this->store     = $store;
		$this->client    = $client;
		$this->caches    = $caches;
		$this->seo       = $seo;
		$this->domain_id = is_string( $domain_id ) || is_callable( $domain_id ) ? $domain_id : '';
	}

	/**
	 * Register the post meta, so only administrators can change it.
	 */
	public function register_meta(): void {
		register_post_meta(
			'',
			self::META,
			array(
				'single'        => true,
				'show_in_rest'  => false,
				'auth_callback' => function (): bool {
					return current_user_can( 'manage_options' );
				},
			)
		);
	}

	/**
	 * The page of this site that a report URL is about.
	 *
	 * @param string $report_url URL of the scanned page.
	 * @return array array( 'post_id' => int ), array( 'home' => true ) for a blog home page, or
	 *               array() when the URL is not a page of this site.
	 */
	public function target_for( string $report_url ): array {
		$post_id = (int) url_to_postid( $report_url );
		if ( $post_id > 0 ) {
			return array( 'post_id' => $post_id );
		}
		if ( ! $this->is_home_url( $report_url ) ) {
			return array();
		}
		$front = (int) get_option( 'page_on_front' );
		if ( 'page' === get_option( 'show_on_front' ) && $front > 0 ) {
			return array( 'post_id' => $front );
		}
		return array( 'home' => true );
	}

	/**
	 * Ask GenReady to build the JSON-LD node for a scanned page.
	 *
	 * @param string $report_id  ID of the scan report (a UUID).
	 * @param string $report_url URL of the scanned page.
	 * @param array  $overrides  Values from the preview form for missing properties (see fill()).
	 * @return array|ApiError The preview: type, node, missing, fillable, blocked_props, changed, summary,
	 *                        notes, existing_types (null when the server did not say) and target.
	 *                        array( 'error' => string ) when the plugin refuses. ApiError when the call failed.
	 */
	public function preview( string $report_id, string $report_url, array $overrides ) {
		$fail = array( 'error' => __( 'GenReady could not do this. Try again.', 'genready' ) );
		if ( 1 !== preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $report_id ) ) {
			return array( 'error' => __( 'Run a scan of this page first.', 'genready' ) );
		}
		$target = $this->target_for( $report_url );
		if ( array() === $target ) {
			return array( 'error' => __( 'GenReady adds JSON-LD only to a post, a page or the home page of this site.', 'genready' ) );
		}
		$domain_id = is_string( $this->domain_id ) ? $this->domain_id : call_user_func( $this->domain_id );
		if ( ! is_string( $domain_id ) || '' === $domain_id ) {
			return array( 'error' => __( 'Reconnect GenReady.', 'genready' ) );
		}
		$wp = $this->wp_facts( $target );
		if ( null === $wp ) {
			return $fail;
		}
		$result = $this->client->request(
			'POST',
			'/api/v1/artifacts/jsonld/merge',
			array(
				'domainId' => $domain_id,
				'reportId' => $report_id,
				'wp'       => (object) $wp,
			),
			30
		);
		if ( $result instanceof ApiError ) {
			return $result;
		}
		$type  = $result['type'] ?? null;
		$node  = $result['node'] ?? null;
		$types = is_array( $node ) ? self::node_types( $node ) : array();
		if ( ! is_string( $type ) || ! in_array( $type, $types, true ) ) {
			return $fail;
		}
		$existing = $result['existing'] ?? null;
		$preview  = array(
			'type'           => $type,
			'node'           => $node,
			'changed'        => false !== ( $result['changed'] ?? true ),
			'summary'        => $this->strings( $result['summary'] ?? null ),
			'notes'          => $this->strings( $result['notes'] ?? null ),
			'existing_types' => is_array( $existing ) && is_array( $existing['types'] ?? null ) ? $this->strings( $existing['types'] ) : null,
			'target'         => $target,
		);
		if ( isset( $target['home'] ) && 'WebPage' === $type && ! isset( $overrides['description'] ) ) {
			$overrides['description'] = $this->clean( (string) get_bloginfo( 'description' ), self::MAX_DESCRIPTION );
		}
		return $this->fill( $preview, $overrides );
	}

	/**
	 * Fill missing properties of a preview from the preview form. Only the
	 * properties in FILLABLE that still hold a TODO value change. A measured
	 * value is never replaced. An empty value leaves the TODO in place.
	 *
	 * @param array $preview A preview from preview().
	 * @param array $values  Form values keyed by property.
	 * @return array The updated preview, or array( 'error' => string ) for a bad date or logo.
	 */
	public function fill( array $preview, array $values ): array {
		$node = is_array( $preview['node'] ?? null ) ? $preview['node'] : array();
		foreach ( self::FILLABLE as $prop => $rule ) {
			if ( ! isset( $values[ $prop ] ) || ! is_string( $values[ $prop ] ) || ! $this->is_todo( $node[ $prop ] ?? null ) ) {
				continue;
			}
			$value = $this->form_value( $rule, $values[ $prop ] );
			if ( null === $value ) {
				return array( 'error' => $this->bad_value( $rule, $prop ) );
			}
			if ( '' !== $value ) {
				$node[ $prop ] = $value;
			}
		}
		$missing = array();
		foreach ( $node as $prop => $value ) {
			if ( $this->is_todo( $value ) ) {
				$missing[] = (string) $prop;
			}
		}
		$fillable                 = array_values( array_intersect( $missing, array_keys( self::FILLABLE ) ) );
		$preview['node']          = $node;
		$preview['missing']       = $missing;
		$preview['fillable']      = $fillable;
		$preview['blocked_props'] = array_values( array_diff( $missing, $fillable ) );
		return $preview;
	}

	/**
	 * Store the node of a preview for its page. The caller keeps the preview
	 * on the server; a node from the browser is never trusted.
	 *
	 * @param array $preview A preview from preview() or fill().
	 * @return array array( 'ok' => true ) or array( 'error' => string ).
	 */
	public function apply( array $preview ): array {
		$fail   = array( 'error' => __( 'GenReady could not do this. Try again.', 'genready' ) );
		$target = $this->valid_target( $preview['target'] ?? null );
		$node   = $preview['node'] ?? null;
		$type   = $preview['type'] ?? null;
		if ( null === $target || ! is_array( $node ) || ! is_string( $type ) || ! in_array( $type, self::node_types( $node ), true ) ) {
			return $fail;
		}
		if ( false === ( $preview['changed'] ?? true ) ) {
			return array(
				/* translators: %s: schema.org type, for example Article. */
				'error' => sprintf( __( 'This page already has complete %s markup. Nothing to change.', 'genready' ), $type ),
			);
		}
		$refusal = $this->incomplete( $node, $preview['blocked_props'] ?? null );
		if ( null !== $refusal ) {
			return array( 'error' => $refusal );
		}
		$existing = is_array( $preview['existing_types'] ?? null ) ? $this->strings( $preview['existing_types'] ) : null;
		if ( array() === $this->seo->active() ) {
			if ( null === $existing ) {
				return array( 'error' => __( 'GenReady needs a newer server answer to check this page for existing markup. Try again later.', 'genready' ) );
			}
			if ( self::types_match( self::node_types( $node ), $existing, true ) ) {
				return array(
					'error' => sprintf(
						/* translators: %s: schema.org type, for example Article. */
						__( 'Your theme or another plugin already adds %s markup to this page. GenReady does not add a second copy. Add the missing fields there.', 'genready' ),
						$type
					),
				);
			}
		}
		$record = array(
			'node'           => $node,
			'type'           => $type,
			'existing_types' => null === $existing ? array() : $existing,
		);
		$this->save( $target, $record );
		$this->purge( $target );
		return array( 'ok' => true );
	}

	/**
	 * Undo the fix of one page: restore the previous record, or remove the
	 * fix when there is none. It needs no connection.
	 *
	 * @param array $target array( 'post_id' => int ) or array( 'home' => true ).
	 * @return array array( 'ok' => true ) or array( 'error' => string ).
	 */
	public function undo( array $target ): array {
		$target = $this->valid_target( $target );
		$stored = null === $target ? null : $this->stored( $target );
		if ( null === $stored ) {
			return array( 'error' => __( 'There is nothing to undo.', 'genready' ) );
		}
		$versions = is_array( $stored['versions'] ?? null ) ? array_values( $stored['versions'] ) : array();
		$previous = null;
		while ( null === $previous && array() !== $versions ) {
			$previous = $this->record( array_pop( $versions ) );
		}
		if ( null === $previous ) {
			$this->delete( $target );
		} else {
			$previous['versions'] = $versions;
			$this->write( $target, $previous );
		}
		$this->purge( $target );
		return array( 'ok' => true );
	}

	/**
	 * Add a node to a schema graph without a duplicate.
	 *
	 * A node of the same type (see types_match()) gets only the properties it
	 * does not have; no value is ever replaced. An exact type match wins over a
	 * group match. With no match the node is added. '@context' is never added
	 * to a graph node. Entries that are not arrays with a '@type' are skipped.
	 * Keys are kept, so the same code merges a list or keyed entities.
	 *
	 * @param array $graph Graph nodes (a list, or entities keyed by name).
	 * @param array $node  The node to add.
	 * @return array The new graph.
	 */
	public static function merge_into_graph( array $graph, array $node ): array {
		return self::merge_entities( $graph, $node, null );
	}

	/**
	 * The wp_head action, at priority 20, when no SEO plugin is active. It
	 * prints the stored node of the page being viewed, unless the page already
	 * has markup of that type.
	 */
	public function print_script(): void {
		try {
			if ( array() !== $this->seo->active() ) {
				return;
			}
			$record = $this->current_record();
			if ( null === $record || self::types_match( self::node_types( $record['node'] ), $record['existing_types'], true ) ) {
				return;
			}
			$json = wp_json_encode( $record['node'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES );
			if ( false === $json ) {
				return;
			}
			echo '<script type="application/ld+json">' . $json . "</script>\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_json_encode with JSON_HEX_TAG.
		} catch ( \Throwable $e ) {
			return;
		}
	}

	/**
	 * Yoast SEO's 'wpseo_schema_graph' filter. It passes the list of graph pieces.
	 *
	 * @param mixed $graph Graph pieces.
	 * @return mixed The graph with the node merged in, or the graph unchanged.
	 */
	public function yoast( $graph ) {
		return $this->filter_graph( $graph, null );
	}

	/**
	 * Rank Math's 'rank_math/json_ld' filter. It passes entities keyed by name;
	 * a new node goes under the key 'genready'.
	 *
	 * @param mixed $data Entities keyed by name.
	 * @return mixed The entities with the node merged in, or unchanged.
	 */
	public function rankmath( $data ) {
		return $this->filter_graph( $data, self::RANK_MATH_KEY );
	}

	/**
	 * All in One SEO's 'aioseo_schema_output' filter. It passes the '@graph' list.
	 *
	 * @param mixed $graph Graph nodes.
	 * @return mixed The graph with the node merged in, or the graph unchanged.
	 */
	public function aioseo( $graph ) {
		return $this->filter_graph( $graph, null );
	}

	/**
	 * Merge the current page's node into an SEO plugin's graph. When the graph
	 * has no node of the type but the scan found that type on the page (printed
	 * by the theme or another plugin), the graph stays unchanged.
	 *
	 * @param mixed       $graph   Graph from the SEO plugin.
	 * @param string|null $new_key Key for a new node, or null to append it.
	 * @return mixed
	 */
	private function filter_graph( $graph, ?string $new_key ) {
		try {
			if ( ! is_array( $graph ) ) {
				return $graph;
			}
			$record = $this->current_record();
			if ( null === $record ) {
				return $graph;
			}
			$types = self::node_types( $record['node'] );
			if ( null === self::find_match( $graph, $types ) && self::types_match( $types, $record['existing_types'], true ) ) {
				return $graph;
			}
			return self::merge_entities( $graph, $record['node'], $new_key );
		} catch ( \Throwable $e ) {
			return $graph;
		}
	}

	/**
	 * Merge a node into graph entities, keeping their keys.
	 *
	 * @param array       $graph   Graph entities.
	 * @param array       $node    Node to add.
	 * @param string|null $new_key Key for a new node, or null to append it.
	 * @return array
	 */
	private static function merge_entities( array $graph, array $node, ?string $new_key ): array {
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
			if ( ! array_key_exists( $prop, $graph[ $key ] ) ) {
				$graph[ $key ][ $prop ] = $value;
			}
		}
		return $graph;
	}

	/**
	 * The key of the graph node that has the same type: an exact match first,
	 * then a match in the same type group.
	 *
	 * @param array    $graph Graph entities.
	 * @param string[] $types Types of the node.
	 * @return int|string|null
	 */
	private static function find_match( array $graph, array $types ) {
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
	private static function types_match( array $a, array $b, bool $grouped ): bool {
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
	private static function node_types( array $node ): array {
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

	/**
	 * The stored record of the page being viewed: the singular post's meta, or
	 * the home record on a blog home page.
	 *
	 * @return array|null array( 'node' => array, 'type' => string, 'existing_types' => string[] ), or null.
	 */
	private function current_record(): ?array {
		if ( is_singular() ) {
			$post_id = (int) get_queried_object_id();
			return $post_id > 0 ? $this->stored( array( 'post_id' => $post_id ) ) : null;
		}
		if ( is_front_page() ) {
			return $this->stored( array( 'home' => true ) );
		}
		return null;
	}

	/**
	 * The well-formed record stored for a target, with its versions.
	 *
	 * @param array $target Checked target.
	 * @return array|null
	 */
	private function stored( array $target ): ?array {
		$raw    = isset( $target['home'] ) ? $this->store->get( self::HOME_KIND ) : get_post_meta( $target['post_id'], self::META, true );
		$record = $this->record( $raw );
		if ( null === $record ) {
			return null;
		}
		$record['versions'] = is_array( $raw['versions'] ?? null ) ? array_values( array_filter( $raw['versions'], 'is_array' ) ) : array();
		return $record;
	}

	/**
	 * A record without its versions, or null when it is not well formed. The
	 * node needs a string or string-list '@type'.
	 *
	 * @param mixed $raw Stored value.
	 * @return array|null
	 */
	private function record( $raw ): ?array {
		if ( ! is_array( $raw ) || ! is_array( $raw['node'] ?? null ) ) {
			return null;
		}
		$types = self::node_types( $raw['node'] );
		if ( array() === $types ) {
			return null;
		}
		return array(
			'node'           => $raw['node'],
			'type'           => is_string( $raw['type'] ?? null ) ? $raw['type'] : $types[0],
			'existing_types' => is_array( $raw['existing_types'] ?? null ) ? $this->strings( $raw['existing_types'] ) : array(),
		);
	}

	/**
	 * Store a new record. The previous record goes to the end of 'versions',
	 * and only the last 5 are kept.
	 *
	 * @param array $target Checked target.
	 * @param array $record New record without versions.
	 */
	private function save( array $target, array $record ): void {
		$previous = $this->stored( $target );
		$versions = null === $previous ? array() : $previous['versions'];
		if ( null !== $previous ) {
			unset( $previous['versions'] );
			$versions[] = $previous;
		}
		$record['versions'] = array_slice( $versions, -self::MAX_VERSIONS );
		$this->write( $target, $record );
	}

	/**
	 * Write a record exactly as given.
	 *
	 * @param array $target Checked target.
	 * @param array $record Record with versions.
	 */
	private function write( array $target, array $record ): void {
		if ( isset( $target['home'] ) ) {
			$this->store->replace( self::HOME_KIND, $record );
			return;
		}
		// update_post_meta() unslashes the value, so slash it first to keep backslashes in the node.
		update_post_meta( $target['post_id'], self::META, wp_slash( $record ) );
	}

	/**
	 * Delete the record of a target.
	 *
	 * @param array $target Checked target.
	 */
	private function delete( array $target ): void {
		if ( isset( $target['home'] ) ) {
			$this->store->remove( self::HOME_KIND );
			return;
		}
		delete_post_meta( $target['post_id'], self::META );
	}

	/**
	 * Purge the page caches of a target's URL.
	 *
	 * @param array $target Checked target.
	 */
	private function purge( array $target ): void {
		if ( isset( $target['home'] ) ) {
			$this->caches->purge_url( home_url( '/' ) );
			return;
		}
		$url = get_permalink( $target['post_id'] );
		if ( is_string( $url ) && '' !== $url ) {
			$this->caches->purge_url( $url, $target['post_id'] );
			return;
		}
		$this->caches->purge_all();
	}

	/**
	 * A target in its exact form, or null.
	 *
	 * @param mixed $target Target from a preview or the REST layer.
	 * @return array|null
	 */
	private function valid_target( $target ): ?array {
		if ( ! is_array( $target ) ) {
			return null;
		}
		if ( true === ( $target['home'] ?? null ) ) {
			return array( 'home' => true );
		}
		$post_id = $target['post_id'] ?? null;
		return is_int( $post_id ) && $post_id > 0 ? array( 'post_id' => $post_id ) : null;
	}

	/**
	 * The text that refuses a node with TODO values, or null. Properties the
	 * form cannot fill are named first.
	 *
	 * @param array $node    Node.
	 * @param mixed $blocked The preview's blocked_props.
	 */
	private function incomplete( array $node, $blocked ): ?string {
		$todo = array();
		foreach ( $node as $prop => $value ) {
			if ( $this->has_todo( $value ) ) {
				$todo[] = (string) $prop;
			}
		}
		$blocked = array_values( array_unique( array_merge( $this->strings( $blocked ), array_diff( $todo, array_keys( self::FILLABLE ) ) ) ) );
		$blocked = array_values( array_intersect( $blocked, $todo ) );
		if ( array() !== $blocked ) {
			return sprintf(
				/* translators: %s: list of schema.org properties, for example offers. */
				__( 'This page needs %s, which GenReady cannot fill in from a form. Add it with your SEO plugin or theme.', 'genready' ),
				implode( ', ', $blocked )
			);
		}
		if ( array() !== $todo ) {
			return sprintf(
				/* translators: %s: list of schema.org properties, for example author. */
				__( 'Fill in %s first.', 'genready' ),
				implode( ', ', $todo )
			);
		}
		return null;
	}

	/**
	 * Tell whether a value is, or holds at any depth, a TODO string.
	 *
	 * @param mixed $value Value.
	 */
	private function has_todo( $value ): bool {
		if ( is_array( $value ) ) {
			foreach ( $value as $child ) {
				if ( $this->has_todo( $child ) ) {
					return true;
				}
			}
			return false;
		}
		return $this->is_todo( $value );
	}

	/**
	 * Tell whether a value is a TODO string.
	 *
	 * @param mixed $value Value.
	 */
	private function is_todo( $value ): bool {
		return is_string( $value ) && 0 === strpos( $value, self::TODO );
	}

	/**
	 * Clean one form value.
	 *
	 * @param string $rule  'text', 'textarea', 'date' or 'url'.
	 * @param string $value Raw value.
	 * @return string|null The clean value ('' when empty), or null when it is not valid.
	 */
	private function form_value( string $rule, string $value ): ?string {
		if ( 'textarea' === $rule ) {
			return trim( mb_substr( sanitize_textarea_field( $value ), 0, self::MAX_DESCRIPTION ) );
		}
		if ( 'text' === $rule ) {
			return $this->cap( trim( sanitize_text_field( $value ) ), self::MAX_TEXT );
		}
		$value = trim( $value );
		if ( '' === $value ) {
			return '';
		}
		if ( 'date' === $rule ) {
			return $this->valid_date( $value ) ? $value : null;
		}
		$url = esc_url_raw( $value, array( 'http', 'https' ) );
		return '' !== $url && 1 === preg_match( '#^https?://#i', $url ) && false !== wp_http_validate_url( $url ) ? $url : null;
	}

	/**
	 * The error text for a value that is not valid.
	 *
	 * @param string $rule 'date' or 'url'.
	 * @param string $prop Property name.
	 */
	private function bad_value( string $rule, string $prop ): string {
		if ( 'date' === $rule ) {
			return sprintf(
				/* translators: %s: schema.org property, for example datePublished. */
				__( 'Enter %s as a date and time with a time zone, like 2026-10-01T10:00:00+00:00.', 'genready' ),
				$prop
			);
		}
		return __( 'Enter the logo as a full web address that starts with https:// or http://.', 'genready' );
	}

	/**
	 * Tell whether a date is ISO 8601 with an offset, in the exact form the
	 * server accepts (for example 2026-10-01T10:00:00+00:00).
	 *
	 * @param string $value Date.
	 */
	private function valid_date( string $value ): bool {
		if ( 1 !== preg_match( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/', $value ) ) {
			return false;
		}
		$date = \DateTime::createFromFormat( DATE_ATOM, $value );
		return false !== $date && $date->format( DATE_ATOM ) === $value;
	}

	/**
	 * The facts WordPress knows about the target, cleaned to the server's limits.
	 *
	 * @param array $target Target.
	 * @return array|null Only fields that pass the limits, or null when the post is missing.
	 */
	private function wp_facts( array $target ): ?array {
		if ( isset( $target['home'] ) ) {
			$title = $this->clean( (string) get_bloginfo( 'name' ), self::MAX_TEXT );
			return '' === $title ? array() : array( 'title' => $title );
		}
		$post = get_post( $target['post_id'] );
		if ( ! is_object( $post ) ) {
			return null;
		}
		$facts = array(
			'title'         => $this->clean( (string) get_the_title( $post ), self::MAX_TEXT ),
			'author'        => $this->clean( (string) get_the_author_meta( 'display_name', (int) ( $post->post_author ?? 0 ) ), self::MAX_TEXT ),
			'datePublished' => get_post_time( 'c', true, $post ),
			'dateModified'  => get_post_modified_time( 'c', true, $post ),
		);
		foreach ( $facts as $key => $value ) {
			$keep = is_string( $value ) && '' !== $value && ( 0 !== strpos( $key, 'date' ) || $this->valid_date( $value ) );
			if ( ! $keep ) {
				unset( $facts[ $key ] );
			}
		}
		return $facts;
	}

	/**
	 * Tell whether a URL is the home page of this site (same host and path).
	 *
	 * @param string $url URL.
	 */
	private function is_home_url( string $url ): bool {
		$parts = wp_parse_url( $url );
		$home  = wp_parse_url( home_url( '/' ) );
		if ( ! is_array( $parts ) || ! is_array( $home ) || ! isset( $parts['host'], $home['host'] ) ) {
			return false;
		}
		return strtolower( $parts['host'] ) === strtolower( $home['host'] )
			&& rtrim( $parts['path'] ?? '', '/' ) === rtrim( $home['path'] ?? '', '/' );
	}

	/**
	 * Turn a WordPress text into plain text on one line: decode entities, remove
	 * tags, trim, and cut it at a length.
	 *
	 * @param string $text Text.
	 * @param int    $max  Longest length.
	 */
	private function clean( string $text, int $max ): string {
		$text = wp_strip_all_tags( html_entity_decode( $text, ENT_QUOTES, 'UTF-8' ) );
		$text = trim( (string) preg_replace( '/\s+/u', ' ', $text ) );
		return $this->cap( $text, $max );
	}

	/**
	 * Cut a text so the server's length check passes. The server counts UTF-16
	 * code units, so a character outside the BMP counts twice.
	 *
	 * @param string $text Text.
	 * @param int    $max  Longest length.
	 */
	private function cap( string $text, int $max ): string {
		$text  = mb_substr( $text, 0, $max );
		$units = strlen( (string) mb_convert_encoding( $text, 'UTF-16LE', 'UTF-8' ) ) / 2;
		while ( $units > $max ) {
			$last   = mb_substr( $text, -1 );
			$text   = mb_substr( $text, 0, -1 );
			$units -= strlen( (string) mb_convert_encoding( $last, 'UTF-16LE', 'UTF-8' ) ) / 2;
		}
		return trim( $text );
	}

	/**
	 * Keep only the strings of a list.
	 *
	 * @param mixed $values Value.
	 * @return string[]
	 */
	private function strings( $values ): array {
		return is_array( $values ) ? array_values( array_filter( $values, 'is_string' ) ) : array();
	}
}
