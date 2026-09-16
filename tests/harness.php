<?php
/** Minimal WP/tvf stubs so the scorer can be exercised outside WordPress. */

define( 'ABSPATH', '/' );
define( 'DAY_IN_SECONDS', 86400 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'ARRAY_A', 'ARRAY_A' );
define( 'OBJECT', 'OBJECT' );

$GLOBALS['MOCK_POSTS'] = [];   // id => ['title'=>, 'lang'=>, 'status'=>, 'type'=>]
$GLOBALS['MOCK_WEIGHTS'] = []; // id => ['lang'=>, 'slugs'=>[slug=>w]]
$GLOBALS['MOCK_PLACES'] = [];      // place_id => ['level'=>, 'name'=>, 'parent_id'=>]
$GLOBALS['MOCK_POST_PLACES'] = []; // post_id  => [level => place_id]
$GLOBALS['MOCK_OPTIONS'] = [];     // option_name => value
$GLOBALS['MOCK_TRANSIENTS'] = [];
$GLOBALS['MOCK_SHORTCODES'] = [];
$GLOBALS['MOCK_CURRENT_POST'] = 0;
$GLOBALS['MOCK_HUB_TYPE'] = [];    // post_id  => 'geo'|'theme'
$GLOBALS['MOCK_PRIMARY_HUB'] = []; // post_id  => [type => hub post_id]

function apply_filters( $tag, $value ) { return $value; }
function absint( $v ) { return abs( (int) $v ); }
function sanitize_key( $v ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $v ) ); }
function __( $s, $d = '' ) { return $s; }
function esc_html( $s ) { return $s; }
function get_option( $k, $d = null ) { return $GLOBALS['MOCK_OPTIONS'][ $k ] ?? $d; }
function wp_is_post_revision( $id ) { return false; }
function wp_is_post_autosave( $id ) { return false; }
function remove_accents( $s ) { return strtr( $s, [ 'é'=>'e','è'=>'e','ê'=>'e','à'=>'a','ù'=>'u','ô'=>'o','î'=>'i','ç'=>'c' ] ); }
function get_the_title( $p ) { $id = is_object( $p ) ? $p->ID : (int) $p; return $GLOBALS['MOCK_POSTS'][ $id ]['title'] ?? "#$id"; }
function get_permalink( $p ) { $id = is_object($p)?$p->ID:$p; return "https://example.test/$id/"; }
function get_the_post_thumbnail_url( $p, $s = '' ) { return 'https://example.test/img.jpg'; }
function get_the_excerpt( $p ) { return 'Excerpt.'; }
function wp_strip_all_tags( $s ) { return $s; }
function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_url( $s ) { return (string) $s; }
function add_shortcode( $tag, $cb ) { $GLOBALS['MOCK_SHORTCODES'][ $tag ] = $cb; }
function has_shortcode( $content, $tag ) { return (bool) preg_match( '/\[' . preg_quote( $tag, '/' ) . '[\s\]]/', (string) $content ); }
function shortcode_atts( $pairs, $atts, $shortcode = '' ) {
	$out = [];
	foreach ( $pairs as $name => $default ) {
		$out[ $name ] = array_key_exists( $name, (array) $atts ) ? $atts[ $name ] : $default;
	}
	return $out;
}
function get_the_ID() { return $GLOBALS['MOCK_CURRENT_POST'] ?? 0; }
function get_transient( $k ) { return $GLOBALS['MOCK_TRANSIENTS'][ $k ] ?? false; }
function set_transient( $k, $v, $ttl = 0 ) { $GLOBALS['MOCK_TRANSIENTS'][ $k ] = $v; return true; }
function update_option( $k, $v, $autoload = null ) { $GLOBALS['MOCK_OPTIONS'][ $k ] = $v; return true; }
function get_post( $id ) {
	$id = (int) $id;
	if ( ! isset( $GLOBALS['MOCK_POSTS'][ $id ] ) ) { return null; }
	$p = new WP_Post();
	$p->ID = $id;
	$p->post_status = $GLOBALS['MOCK_POSTS'][ $id ]['status'];
	$p->post_type   = $GLOBALS['MOCK_POSTS'][ $id ]['type'];
	$p->post_name    = $GLOBALS['MOCK_POSTS'][ $id ]['slug'] ?? '';
	$p->post_content = $GLOBALS['MOCK_POSTS'][ $id ]['content'] ?? '';
	return $p;
}
function pll_get_post_language( $id, $field = 'slug' ) { return $GLOBALS['MOCK_POSTS'][ (int) $id ]['lang'] ?? ''; }
function delete_transient( $k ) { unset( $GLOBALS['MOCK_TRANSIENTS'][ $k ] ); return true; }

/** MFY_Page resolves /pour-vous/ by slug; the fixture is the page table. */
function get_page_by_path( $path, $output = null, $post_type = 'page' ) {
	foreach ( $GLOBALS['MOCK_POSTS'] as $id => $post ) {
		if ( ( $post['slug'] ?? '' ) === $path && $post['type'] === $post_type ) { return get_post( $id ); }
	}
	return null;
}

class WP_Post { public $ID; public $post_status; public $post_type; public $post_name = ''; public $post_content = ''; }

/** Puts shortcode text into a post's stored content. */
function mock_content( int $post_id, string $content ): void {
	$GLOBALS['MOCK_POSTS'][ $post_id ]['content'] = $content;
}

/**
 * Hub Manager stand-in. Mirrors the real helpers, including the documented
 * behaviour that get_primary_hub() returns raw, possibly stale meta while
 * get_hub_ancestors() validates each hop.
 *
 * Defining MFY_TEST_WITHOUT_HUBS before loading the harness leaves them
 * undefined, which is how the plugin sees a site with no Hub Manager at all.
 */
if ( ! defined( 'MFY_TEST_WITHOUT_HUBS' ) ) {

function mavo_get_hub_type( int $post_id ): ?string {
	return $GLOBALS['MOCK_HUB_TYPE'][ $post_id ] ?? null;
}

function mavo_get_primary_hub( int $post_id, string $type ): ?int {
	return $GLOBALS['MOCK_PRIMARY_HUB'][ $post_id ][ $type ] ?? null;
}

function mavo_get_hub_ancestors( int $post_id, string $type ): array {
	$ancestors = [];
	$seen      = [ $post_id => true ];
	$current   = $post_id;

	for ( $depth = 0; $depth < 20; $depth++ ) {
		$parent = mavo_get_primary_hub( $current, $type );

		if ( null === $parent || isset( $seen[ $parent ] ) ) { break; }
		if ( ! isset( $GLOBALS['MOCK_POSTS'][ $parent ] ) ) { break; }
		if ( mavo_get_hub_type( $parent ) !== $type ) { break; }

		$ancestors[]   = $parent;
		$seen[ $parent ] = true;
		$current       = $parent;
	}

	return $ancestors;
}

/**
 * Derives children the way the real helper does — from each child's own meta,
 * never from a list stored on the hub.
 */
function mavo_get_hub_children( int $hub_id, string $type, array $args = [] ): array {
	$status = $args['post_status'] ?? 'any';
	$limit  = (int) ( $args['posts_per_page'] ?? -1 );
	$out    = [];

	foreach ( $GLOBALS['MOCK_PRIMARY_HUB'] as $child_id => $types ) {
		if ( (int) ( $types[ $type ] ?? 0 ) !== $hub_id ) { continue; }
		$post = $GLOBALS['MOCK_POSTS'][ $child_id ] ?? null;
		if ( ! $post ) { continue; }
		if ( 'any' !== $status && $post['status'] !== $status ) { continue; }
		$out[] = (int) $child_id;
	}

	sort( $out );

	return $limit > 0 ? array_slice( $out, 0, $limit ) : $out;
}

} // MFY_TEST_WITHOUT_HUBS

/**
 * Content changed, so the cache generation moves — exactly as save_post does
 * in production. Without this the mocks would populate a pool, add a post to
 * the fixture, and then read the stale pool back.
 */
function mock_bust(): void {
	if ( class_exists( 'MFY_Cache' ) ) {
		MFY_Cache::bust();
	}
}

/** Marks a post as a hub of the given type. */
function mock_hub( int $post_id, string $type ): void {
	$GLOBALS['MOCK_HUB_TYPE'][ $post_id ] = $type;
	mock_bust();
}

/** Points a child at its primary hub of one type. */
function mock_primary_hub( int $child_id, int $hub_id, string $type ): void {
	$GLOBALS['MOCK_PRIMARY_HUB'][ $child_id ][ $type ] = $hub_id;
	mock_bust();
}

/** tvf registry + store stubs. */
function tvf_get_registry(): array {
	return [
		'interet' => [ 'filters' => [ 'citytrip' => 'City trip', 'plage_cote' => 'Plage', 'nature_rando' => 'Rando', 'gastronomie' => 'Gastronomie' ] ],
		'saison'  => [ 'filters' => [ 'ete' => 'Été' ] ],
		'age_enfants' => [ 'filters' => [ 'ados' => 'Ados', 'kids' => 'Enfants' ] ],
		'geographie'  => [ 'filters' => [ 'angleterre' => 'Angleterre', 'france' => 'France' ] ],
	];
}
function tvf_get_slug_labels(): array {
	$out = [];
	foreach ( tvf_get_registry() as $cat ) { foreach ( $cat['filters'] as $s => $l ) { $out[ $s ] = $l; } }
	return $out;
}

class TVF_Store {
	public static function table_name(): string { return 'wp_tvf_post_filter'; }
	public static function get_weights( int $post_id, string $lang = 'fr' ): array {
		$row = $GLOBALS['MOCK_WEIGHTS'][ $post_id ] ?? null;
		return ( $row && $row['lang'] === $lang ) ? $row['slugs'] : [];
	}
}

/** Fake $wpdb: only the two queries MFY_Data issues. */
class Fake_WPDB {
	public $posts = 'wp_posts';
	public $postmeta = 'wp_postmeta';
	public $prefix = 'wp_';
	public $term_relationships = 'wp_term_relationships';
	public $term_taxonomy = 'wp_term_taxonomy';
	public $last_sql = '';
	public $queries = 0;
	public function prepare( $sql, ...$args ) {
		if ( isset( $args[0] ) && is_array( $args[0] ) ) { $args = $args[0]; }
		$i = 0;
		return preg_replace_callback( '/%[ds]/', function ( $m ) use ( &$i, $args ) {
			$v = $args[ $i++ ] ?? '';
			return $m[0] === '%d' ? (string) (int) $v : "'" . $v . "'";
		}, $sql );
	}
	public function get_results( $sql, $mode = null ) {
		$this->last_sql = $sql;
		if ( str_contains( $sql, 'gp.level' ) )       { return $this->post_places( $sql ); }
		if ( str_contains( $sql, 'AS name' ) )        { return $this->place_names( $sql ); }
		if ( str_contains( $sql, 'pf.post_id IN' ) )  { return $this->eligible( $sql ); }
		if ( str_contains( $sql, 'COUNT(*) AS hits' ) ) { return $this->candidates( $sql ); }
		return $this->weights( $sql );
	}

	public function get_col( $sql ) {
		$this->last_sql = $sql;
		// MFY_Geo::candidate_ids()
		$places  = array_map( 'intval', $this->extract_list( $sql, 'gp.id IN' ) );
		$exclude = array_map( 'intval', $this->extract_list( $sql, 'p.ID NOT IN' ) );
		$out     = [];
		foreach ( $GLOBALS['MOCK_POST_PLACES'] as $post_id => $levels ) {
			if ( in_array( $post_id, $exclude, true ) ) { continue; }
			$post = $GLOBALS['MOCK_POSTS'][ $post_id ] ?? null;
			if ( ! $post || $post['status'] !== 'publish' || $post['type'] !== 'post' ) { continue; }
			if ( array_intersect( array_map( 'intval', array_values( $levels ) ), $places ) ) { $out[] = $post_id; }
		}
		return $out;
	}

	public function get_var( $sql ) {
		$this->last_sql = $sql;
		if ( str_contains( $sql, 'SHOW TABLES LIKE' ) ) {
			return empty( $GLOBALS['MOCK_PLACES'] ) ? null : 'wp_geo_tagger_places';
		}
		return null;
	}

	public function esc_like( $s ) { return $s; }

	private function post_places( string $sql ): array {
		$ids    = array_map( 'intval', $this->extract_list( $sql, 'tr.object_id IN' ) );
		$levels = $this->extract_list( $sql, 'gp.level IN' );
		$out    = [];
		foreach ( $ids as $id ) {
			foreach ( $GLOBALS['MOCK_POST_PLACES'][ $id ] ?? [] as $level => $place_id ) {
				if ( in_array( $level, $levels, true ) ) {
					$out[] = [ 'post_id' => $id, 'place_id' => $place_id, 'level' => $level ];
				}
			}
		}
		return $out;
	}

	private function place_names( string $sql ): array {
		$ids = array_map( 'intval', $this->extract_list( $sql, 'id IN' ) );
		$out = [];
		foreach ( $ids as $id ) {
			if ( isset( $GLOBALS['MOCK_PLACES'][ $id ] ) ) {
				$out[] = [ 'id' => $id, 'name' => $GLOBALS['MOCK_PLACES'][ $id ]['name'] ];
			}
		}
		return $out;
	}

	/** MFY_Data::filter_eligible() — same rows as candidates(), restricted to an ID list. */
	private function eligible( string $sql ): array {
		$ids   = array_map( 'intval', $this->extract_list( $sql, 'pf.post_id IN' ) );
		$slugs = $this->extract_list( $sql, 'filter_slug IN' );
		$lang  = $this->lang( $sql );
		$out   = [];
		foreach ( $ids as $id ) {
			$post = $GLOBALS['MOCK_POSTS'][ $id ] ?? null;
			if ( ! $post || $post['status'] !== 'publish' || $post['type'] !== 'post' ) { continue; }
			$hits = 0;
			foreach ( TVF_Store::get_weights( $id, $lang ) as $slug => $w ) {
				if ( 2 === (int) $w && in_array( $slug, $slugs, true ) ) { $hits++; }
			}
			if ( $hits ) { $out[] = [ 'post_id' => $id, 'hits' => $hits, 'views' => 0 ]; }
		}
		return $out;
	}
	private function extract_list( string $sql, string $after ): array {
		if ( ! preg_match( '/' . preg_quote( $after, '/' ) . '\s*\(([^)]*)\)/', $sql, $m ) ) { return []; }
		return array_map( fn( $v ) => trim( trim( $v ), "'" ), explode( ',', $m[1] ) );
	}
	private function lang( string $sql ): string {
		preg_match( "/lang = '([^']+)'/", $sql, $m );
		return $m[1] ?? 'fr';
	}
	private function weights( string $sql ): array {
		$ids   = array_map( 'intval', $this->extract_list( $sql, 'post_id IN' ) );
		$slugs = $this->extract_list( $sql, 'filter_slug IN' );
		$lang  = $this->lang( $sql );
		$out   = [];
		foreach ( $ids as $id ) {
			foreach ( TVF_Store::get_weights( $id, $lang ) as $slug => $w ) {
				if ( in_array( $slug, $slugs, true ) ) {
					$out[] = [ 'post_id' => $id, 'filter_slug' => $slug, 'weight' => $w ];
				}
			}
		}
		return $out;
	}
	private function candidates( string $sql ): array {
		$slugs   = $this->extract_list( $sql, 'filter_slug IN' );
		$exclude = array_map( 'intval', $this->extract_list( $sql, 'post_id NOT IN' ) );
		$lang    = $this->lang( $sql );
		$rows    = [];
		foreach ( $GLOBALS['MOCK_POSTS'] as $id => $post ) {
			if ( in_array( $id, $exclude, true ) || $post['status'] !== 'publish' || $post['type'] !== 'post' ) { continue; }
			$hits = 0;
			foreach ( TVF_Store::get_weights( $id, $lang ) as $slug => $w ) {
				if ( 2 === (int) $w && in_array( $slug, $slugs, true ) ) { $hits++; }
			}
			if ( $hits ) { $rows[] = [ 'post_id' => $id, 'hits' => $hits, 'views' => 0 ]; }
		}
		usort( $rows, fn( $a, $b ) => [ $b['hits'], $a['post_id'] ] <=> [ $a['hits'], $b['post_id'] ] );
		return $rows;
	}
}
$GLOBALS['wpdb'] = new Fake_WPDB();

require_once __DIR__ . '/../includes/mavo-for-you-config.php';
require_once __DIR__ . '/../includes/class-mavo-for-you-data.php';
require_once __DIR__ . '/../includes/class-mavo-for-you-cache.php';
require_once __DIR__ . '/../includes/class-mavo-for-you-geo.php';
require_once __DIR__ . '/../includes/class-mavo-for-you-hubs.php';
require_once __DIR__ . '/../includes/class-mavo-for-you-scorer.php';
require_once __DIR__ . '/../includes/class-mavo-for-you-rows.php';
require_once __DIR__ . '/../includes/class-mavo-for-you-page.php';

/**
 * Geo Tagger stand-in: places keyed by id, and a post -> place-chain map.
 * Mirrors the real model closely enough for the composition rules — every
 * level of a post's chain is attached to it, exactly as TagManager does.
 */
function mock_place( int $id, string $level, string $name, ?int $parent_id = null ): void {
	$GLOBALS['MOCK_PLACES'][ $id ] = [ 'level' => $level, 'name' => $name, 'parent_id' => $parent_id ];
	mock_bust();
}

/** @param array $levels [ 'city' => place_id, 'region' => .., 'country' => .. ] */
function mock_geo( int $post_id, array $levels ): void {
	$GLOBALS['MOCK_POST_PLACES'][ $post_id ] = $levels;
	mock_bust();
}

function mock_post( int $id, string $title, string $lang, array $weights, string $status = 'publish', string $type = 'post', string $slug = '' ): void {
	$GLOBALS['MOCK_POSTS'][ $id ]   = [ 'title' => $title, 'lang' => $lang, 'status' => $status, 'type' => $type, 'slug' => $slug, 'content' => '' ];
	$GLOBALS['MOCK_WEIGHTS'][ $id ] = [ 'lang' => $lang, 'slugs' => $weights ];
	mock_bust();
}

/** Creates the suggestions page a language links to, at its configured slug. */
function mock_suggestions_page( int $id, string $lang, string $slug ): void {
	mock_post( $id, 'Pour vous', $lang, [], 'publish', 'page', $slug );
	mock_content( $id, '[mavo_for_you_page]' );
	mock_bust();
}

function view( int $id, int $duration, int $scroll, int $last_seen ): array {
	return [ 'post_id' => $id, 'duration_seconds' => $duration, 'max_scroll_pct' => $scroll, 'last_seen' => $last_seen ];
}

$FAILED = 0;
function check( string $label, bool $ok, string $detail = '' ): void {
	global $FAILED;
	if ( ! $ok ) { $FAILED++; }
	printf( "%s %s%s\n", $ok ? 'PASS' : 'FAIL', $label, $detail ? "  ($detail)" : '' );
}
