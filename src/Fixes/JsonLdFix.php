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
use GenReady\Support\Text;

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
	/** Key of a new node in Rank Math's entities. */
	const RANK_MATH_KEY = 'genready';

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
		$type = $result['type'] ?? null;
		$node = $result['node'] ?? null;
		if ( ! is_array( $node ) ) {
			return $fail;
		}
		// Some SEO plugins print the graph without escaping slashes, so a value must never close the script tag.
		$node = SchemaNode::clean( $node );
		if ( null === $node ) {
			return array( 'error' => $this->uncheckable() );
		}
		if ( ! is_string( $type ) || ! in_array( $type, SchemaGraph::node_types( $node ), true ) ) {
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
			$overrides['description'] = $this->clean( (string) get_bloginfo( 'description' ), SchemaNode::MAX_DESCRIPTION );
		}
		$preview = $this->fill( $preview, $overrides );
		if ( isset( $preview['node'] ) && ! SchemaNode::fits( $preview['node'] ) ) {
			return array( 'error' => $this->uncheckable() );
		}
		return $preview;
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
			if ( 1 !== preg_match( SchemaNode::KEY_PATTERN, $prop ) || ! isset( $values[ $prop ] ) || ! is_string( $values[ $prop ] ) || ! SchemaNode::is_todo( $node[ $prop ] ?? null ) ) {
				continue;
			}
			$value = SchemaNode::form_value( $rule, $values[ $prop ] );
			if ( null === $value ) {
				return array( 'error' => SchemaNode::bad_value( $rule, $prop ) );
			}
			if ( '' !== $value ) {
				$node[ $prop ] = SchemaNode::angle_free( $value );
			}
		}
		$missing = array();
		foreach ( $node as $prop => $value ) {
			if ( SchemaNode::is_todo( $value ) ) {
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
	 * Text for a node the plugin refuses to store.
	 */
	private function uncheckable(): string {
		return __( 'GenReady sent structured data that the plugin cannot check. Try again later.', 'genready' );
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
		if ( null === $target || ! is_array( $node ) || ! is_string( $type ) || ! in_array( $type, SchemaGraph::node_types( $node ), true ) ) {
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
			if ( SchemaGraph::types_match( SchemaGraph::node_types( $node ), $existing, true ) ) {
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
		if ( ! $this->save( $target, $record ) ) {
			return array( 'error' => $this->not_saved() );
		}
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
			$done = $this->delete( $target );
		} else {
			$previous['versions'] = $versions;
			$done                 = $this->write( $target, $previous );
		}
		if ( ! $done ) {
			return array( 'error' => $this->not_saved() );
		}
		$this->purge( $target );
		return array( 'ok' => true );
	}

	/**
	 * The record stored for a target, with its versions, for the fix card.
	 *
	 * @param array $target array( 'post_id' => int ) or array( 'home' => true ).
	 * @return array|null array( 'node', 'type', 'existing_types', 'versions' ), or null when nothing is stored.
	 */
	public function stored_for( array $target ): ?array {
		$target = $this->valid_target( $target );
		return null === $target ? null : $this->stored( $target );
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
			if ( null === $record || SchemaGraph::types_match( SchemaGraph::node_types( $record['node'] ), $record['existing_types'], true ) ) {
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
			$types = SchemaGraph::node_types( $record['node'] );
			if ( null === SchemaGraph::find_match( $graph, $types ) && SchemaGraph::types_match( $types, $record['existing_types'], true ) ) {
				return $graph;
			}
			return SchemaGraph::merge( $graph, $record['node'], $new_key );
		} catch ( \Throwable $e ) {
			return $graph;
		}
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
		$types = SchemaGraph::node_types( $raw['node'] );
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
	 * @return bool True when the record is stored.
	 */
	private function save( array $target, array $record ): bool {
		$previous = $this->stored( $target );
		$versions = null === $previous ? array() : $previous['versions'];
		if ( null !== $previous ) {
			unset( $previous['versions'] );
			$versions[] = $previous;
		}
		$record['versions'] = array_slice( $versions, -self::MAX_VERSIONS );
		return $this->write( $target, $record );
	}

	/**
	 * Write a record exactly as given, then read it back.
	 *
	 * @param array $target Checked target.
	 * @param array $record Record with versions.
	 * @return bool True when the record is stored. update_post_meta() returns false also for an
	 *              unchanged value, so only the read-back decides.
	 */
	private function write( array $target, array $record ): bool {
		if ( isset( $target['home'] ) ) {
			return $this->store->replace( self::HOME_KIND, $record );
		}
		// update_post_meta() unslashes the value, so slash it first to keep backslashes in the node.
		update_post_meta( $target['post_id'], self::META, wp_slash( $record ) );
		return get_post_meta( $target['post_id'], self::META, true ) === $record;
	}

	/**
	 * Delete the record of a target.
	 *
	 * @param array $target Checked target.
	 * @return bool True when no record is stored afterwards.
	 */
	private function delete( array $target ): bool {
		if ( isset( $target['home'] ) ) {
			return $this->store->remove( self::HOME_KIND );
		}
		delete_post_meta( $target['post_id'], self::META );
		return '' === get_post_meta( $target['post_id'], self::META, true );
	}

	/**
	 * Text for a record that WordPress did not store.
	 */
	private function not_saved(): string {
		return __( 'GenReady could not save the structured data. Try again.', 'genready' );
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
			if ( SchemaNode::has_todo( $value ) ) {
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
	 * The facts WordPress knows about the target, cleaned to the server's limits.
	 *
	 * @param array $target Target.
	 * @return array|null Only fields that pass the limits, or null when the post is missing.
	 */
	private function wp_facts( array $target ): ?array {
		if ( isset( $target['home'] ) ) {
			$title = $this->clean( (string) get_bloginfo( 'name' ), SchemaNode::MAX_TEXT );
			return '' === $title ? array() : array( 'title' => $title );
		}
		$post = get_post( $target['post_id'] );
		if ( ! is_object( $post ) ) {
			return null;
		}
		$facts = array(
			'title'         => $this->clean( (string) get_the_title( $post ), SchemaNode::MAX_TEXT ),
			'author'        => $this->clean( (string) get_the_author_meta( 'display_name', (int) ( $post->post_author ?? 0 ) ), SchemaNode::MAX_TEXT ),
			'datePublished' => get_post_time( 'c', true, $post ),
			'dateModified'  => get_post_modified_time( 'c', true, $post ),
		);
		foreach ( $facts as $key => $value ) {
			$keep = is_string( $value ) && '' !== $value && ( 0 !== strpos( $key, 'date' ) || SchemaNode::valid_date( $value ) );
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
	 * tags, trim, and cut it to the server's UTF-16 length limit.
	 *
	 * @param string $text Text.
	 * @param int    $max  Longest length in UTF-16 code units.
	 */
	private function clean( string $text, int $max ): string {
		$text = wp_strip_all_tags( html_entity_decode( $text, ENT_QUOTES, 'UTF-8' ) );
		$text = trim( (string) preg_replace( '/\s+/u', ' ', $text ) );
		return Text::cap( $text, $max );
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
