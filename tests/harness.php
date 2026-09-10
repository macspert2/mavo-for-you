<?php
/** Minimal WP/tvf stubs so the scorer can be exercised outside WordPress. */

define( 'ABSPATH', '/' );
define( 'DAY_IN_SECONDS', 86400 );
define( 'ARRAY_A', 'ARRAY_A' );

$GLOBALS['MOCK_POSTS'] = [];   // id => ['title'=>, 'lang'=>, 'status'=>, 'type'=>]
$GLOBALS['MOCK_WEIGHTS'] = []; // id => ['lang'=>, 'slugs'=>[slug=>w]]

function apply_filters( $tag, $value ) { return $value; }
function absint( $v ) { return abs( (int) $v ); }
function sanitize_key( $v ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $v ) ); }
function __( $s, $d = '' ) { return $s; }
function esc_html( $s ) { return $s; }
function get_option( $k, $d = null ) { return $d; }
function remove_accents( $s ) { return strtr( $s, [ 'é'=>'e','è'=>'e','ê'=>'e','à'=>'a','ù'=>'u','ô'=>'o','î'=>'i','ç'=>'c' ] ); }
function get_the_title( $p ) { $id = is_object( $p ) ? $p->ID : (int) $p; return $GLOBALS['MOCK_POSTS'][ $id ]['title'] ?? "#$id"; }
function get_permalink( $p ) { $id = is_object($p)?$p->ID:$p; return "https://example.test/$id/"; }
function get_the_post_thumbnail_url( $p, $s = '' ) { return 'https://example.test/img.jpg'; }
function get_the_excerpt( $p ) { return 'Excerpt.'; }
function wp_strip_all_tags( $s ) { return $s; }
function get_post( $id ) {
	$id = (int) $id;
	if ( ! isset( $GLOBALS['MOCK_POSTS'][ $id ] ) ) { return null; }
	$p = new WP_Post();
	$p->ID = $id;
	$p->post_status = $GLOBALS['MOCK_POSTS'][ $id ]['status'];
	$p->post_type   = $GLOBALS['MOCK_POSTS'][ $id ]['type'];
	return $p;
}
function pll_get_post_language( $id, $field = 'slug' ) { return $GLOBALS['MOCK_POSTS'][ (int) $id ]['lang'] ?? ''; }

class WP_Post { public $ID; public $post_status; public $post_type; }

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
	public $last_sql = '';
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
		if ( str_contains( $sql, 'COUNT(*) AS hits' ) ) { return $this->candidates( $sql ); }
		return $this->weights( $sql );
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

require __DIR__ . '/../includes/mavo-for-you-config.php';
require __DIR__ . '/../includes/class-mavo-for-you-data.php';
require __DIR__ . '/../includes/class-mavo-for-you-scorer.php';

function mock_post( int $id, string $title, string $lang, array $weights, string $status = 'publish', string $type = 'post' ): void {
	$GLOBALS['MOCK_POSTS'][ $id ]   = [ 'title' => $title, 'lang' => $lang, 'status' => $status, 'type' => $type ];
	$GLOBALS['MOCK_WEIGHTS'][ $id ] = [ 'lang' => $lang, 'slugs' => $weights ];
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
