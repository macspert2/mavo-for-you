<?php
require_once __DIR__ . '/harness.php';

// --- REST-layer stubs -------------------------------------------------------
$GLOBALS['IS_ADMIN'] = false;
function current_user_can( $cap ) { return (bool) $GLOBALS['IS_ADMIN']; }
function rest_url( $path = '' ) { return 'https://example.test/wp-json/' . ltrim( $path, '/' ); }
function register_rest_route( ...$args ) {}
function add_action( ...$args ) {}
function sanitize_text_field( $s ) { return trim( strip_tags( (string) $s ) ); }
class WP_REST_Server { const CREATABLE = 'POST'; }
class WP_REST_Request {
	private $params;
	public function __construct( array $params ) { $this->params = $params; }
	public function get_param( $key ) { return $this->params[ $key ] ?? null; }
}
class WP_REST_Response {
	public $data; public $status; public $headers = [];
	public function __construct( $data, $status ) { $this->data = $data; $this->status = $status; }
	public function header( $k, $v ) { $this->headers[ $k ] = $v; }
}

require_once __DIR__ . '/../includes/class-mavo-for-you-rest.php';

$t = time();
mock_post( 1, 'A', 'fr', [ 'citytrip' => 2, 'angleterre' => 2 ] );
mock_post( 2, 'B', 'fr', [ 'citytrip' => 2, 'angleterre' => 2 ] );
mock_post( 3, 'C', 'fr', [ 'citytrip' => 2, 'angleterre' => 2 ] );
mock_post( 4, 'D', 'fr', [ 'citytrip' => 2 ] );
mock_post( 6, 'EN', 'en', [ 'citytrip' => 2, 'angleterre' => 2 ] );
mock_post( 7, 'Draft', 'fr', [ 'citytrip' => 2 ], 'draft' );

function call( array $params ): WP_REST_Response {
	return MFY_Rest::handle( new WP_REST_Request( $params ) );
}

$base = [
	'current_post_id' => 1,
	'lang'            => 'de', // deliberately wrong: the server must ignore it.
	'views'           => [
		[ 'post_id' => 1, 'duration_seconds' => 120, 'max_scroll_pct' => 80, 'last_seen' => $t ],
		[ 'post_id' => 2, 'duration_seconds' => 90,  'max_scroll_pct' => 70, 'last_seen' => $t - 300 ],
	],
];

$r = call( $base );
check( 'happy path returns recommendations', $r->data['show'] === true && count( $r->data['recommendations'] ) > 0 );
check( 'client-supplied language is ignored', $r->data['lang'] === 'fr' );
check( 'recently viewed excludes the current post', ! in_array( 1, array_column( $r->data['recently_viewed'], 'post_id' ), true ) );
check( 'response is marked private / no-store', str_contains( $r->headers['Cache-Control'], 'no-store' ) && str_contains( $r->headers['Cache-Control'], 'private' ) );
check( 'recommendation payload carries post_id for data-mavo-post-id', isset( $r->data['recommendations'][0]['post_id'] ) );
check( 'debug withheld from non-administrators', ! isset( $r->data['debug'] ) );

$GLOBALS['IS_ADMIN'] = true;
$r_debug = call( $base + [ 'debug' => true ] );
check( 'debug returned for administrators who ask', isset( $r_debug->data['debug']['candidates'] ) );
$GLOBALS['IS_ADMIN'] = false;

// --- Gates ------------------------------------------------------------------
check( 'one meaningful view shows nothing',
	call( [ 'current_post_id' => 1, 'views' => [ [ 'post_id' => 1, 'duration_seconds' => 120, 'max_scroll_pct' => 80, 'last_seen' => $t ] ] ] )->data['show'] === false );
check( 'two bounces do not count as meaningful views',
	call( [ 'current_post_id' => 1, 'views' => [
		[ 'post_id' => 1, 'duration_seconds' => 2, 'max_scroll_pct' => 5, 'last_seen' => $t ],
		[ 'post_id' => 2, 'duration_seconds' => 3, 'max_scroll_pct' => 9, 'last_seen' => $t - 60 ],
	] ] )->data['show'] === false );
check( 'invalid current_post_id refused', call( [ 'current_post_id' => 999999 ] )->data['show'] === false );
check( 'draft as current post refused', call( [ 'current_post_id' => 7 ] )->data['show'] === false );
check( 'garbage payload does not fatal', call( [ 'current_post_id' => 1, 'views' => 'nope', 'searches' => 5, 'referral' => 'x' ] )->data['show'] === false );

// --- Shortcode handover -------------------------------------------------------
$plain = call( $base );
check( 'with no limit sent, the default of 3 applies', count( $plain->data['recommendations'] ) <= MFY_Config::num_recommendations(), count( $plain->data['recommendations'] ) . ' returned' );

$GLOBALS['IS_ADMIN'] = false;
$sized = call( $base + [ 'limit' => 6 ] );
check( 'the personalised block honours the shortcode limit', count( $sized->data['recommendations'] ) <= 6 );
$clamped = call( $base + [ 'limit' => 9999 ] );
check( 'an absurd limit from the client is clamped', count( $clamped->data['recommendations'] ) <= MFY_Config::shortcode_max_limit() );
$levelled = call( $base + [ 'geo_level' => 'country' ] );
check( 'a geo level from the client is accepted', $levelled->data['show'] === true );
$bogus = call( $base + [ 'geo_level' => 'planet' ] );
check( 'an unknown geo level is ignored rather than fataling', $bogus->data['show'] === true );

// --- Clamping and bounding --------------------------------------------------
$GLOBALS['IS_ADMIN'] = true;
$hostile = call( [
	'current_post_id' => 1,
	'debug'           => true,
	'views'           => array_merge(
		[
			[ 'post_id' => 1, 'duration_seconds' => 99999, 'max_scroll_pct' => 5000, 'last_seen' => $t + 999999 ],
			[ 'post_id' => 2, 'duration_seconds' => -50,   'max_scroll_pct' => -10,  'last_seen' => $t - 300 ],
			[ 'post_id' => 6, 'duration_seconds' => 100,   'max_scroll_pct' => 90,   'last_seen' => $t ], // wrong language
			[ 'post_id' => 7, 'duration_seconds' => 100,   'max_scroll_pct' => 90,   'last_seen' => $t ], // draft
			[ 'post_id' => 2, 'duration_seconds' => 100,   'max_scroll_pct' => 90,   'last_seen' => $t ], // duplicate
		],
		array_fill( 0, 50, [ 'post_id' => 3, 'duration_seconds' => 10, 'max_scroll_pct' => 30, 'last_seen' => $t ] )
	),
	'searches'        => array_fill( 0, 40, [ 'query' => str_repeat( 'a', 5000 ), 'source' => 'site' ] ),
	'referral'        => [ 'source' => str_repeat( 'x', 500 ), 'query' => str_repeat( 'b', 5000 ), 'landing_post_id' => '12abc' ],
] );
$profiled = $hostile->data['debug']['profile_views'];
$by_id    = array_column( $profiled, null, 'post_id' );
check( 'duration clamped to the cap', $by_id[1]['duration_seconds'] === 600, (string) $by_id[1]['duration_seconds'] );
check( 'scroll clamped to 100', $by_id[1]['max_scroll_pct'] === 100, (string) $by_id[1]['max_scroll_pct'] );
check( 'negative engagement floored at 0', $by_id[2]['duration_seconds'] === 0 && $by_id[2]['max_scroll_pct'] === 0 );
check( 'other-language view dropped', ! isset( $by_id[6] ) );
check( 'draft view dropped', ! isset( $by_id[7] ) );
check( 'duplicate view ids collapsed', count( $profiled ) === count( array_unique( array_column( $profiled, 'post_id' ) ) ) );
check( 'view list capped at maxViews', count( $profiled ) <= MFY_Config::max_views(), count( $profiled ) . ' views' );
check( 'over-long search strings dropped', empty( $hostile->data['debug']['search_filters'] ) );

echo "\n";
printf( "%d failure(s)\n", $GLOBALS['FAILED'] );
exit( $GLOBALS['FAILED'] ? 1 : 0 );
