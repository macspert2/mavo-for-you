<?php
/**
 * The /pour-vous/ page seen from the two sides that face the visitor: the link
 * the block offers to it, and the endpoint the page itself calls.
 *
 * The row building is MFY_Rows' business and is tested in test-rows.php. What
 * matters here is the contract between them — a link that only appears when
 * the page behind it has something on it, and an endpoint that will not build
 * a page for anything but the real one.
 */
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

// --- Query stubs, for the robots tag ----------------------------------------
$GLOBALS['MOCK_QUERY'] = [ 'is_singular' => true, 'queried' => 0 ];
function is_singular( $types = [] ) {
	if ( ! $GLOBALS['MOCK_QUERY']['is_singular'] ) { return false; }
	$post = get_post( $GLOBALS['MOCK_QUERY']['queried'] );
	return $post && ( ! $types || in_array( $post->post_type, (array) $types, true ) );
}
function get_queried_object() { return get_post( $GLOBALS['MOCK_QUERY']['queried'] ); }
function get_queried_object_id() { return (int) $GLOBALS['MOCK_QUERY']['queried']; }

require_once __DIR__ . '/../includes/class-mavo-for-you-rest.php';

$t = time();

// --- Fixture: enough material for four rows ---------------------------------

mock_post( 200, 'Londres en famille : le guide', 'fr', [], 'publish', 'page' );
mock_hub( 200, 'geo' );

mock_place( 11, 'city', 'Londres', 22 );
mock_place( 22, 'country', 'Angleterre' );

foreach ( [ 201, 202, 203, 204, 205 ] as $i => $id ) {
	mock_post( $id, 'Enfant ' . ( $i + 1 ), 'fr', [ 'citytrip' => 2 ] );
	mock_primary_hub( $id, 200, 'geo' );
	mock_geo( $id, [ 'city' => 11, 'country' => 22 ] );
}
foreach ( [ 701, 702, 703 ] as $i => $id ) {
	mock_post( $id, 'Londres autre ' . ( $i + 1 ), 'fr', [ 'gastronomie' => 2 ] );
	mock_geo( $id, [ 'city' => 11, 'country' => 22 ] );
}
foreach ( [ 301, 302, 303, 304, 305, 306 ] as $i => $id ) {
	mock_post( $id, 'Ville ados ' . ( $i + 1 ), 'fr', [ 'citytrip' => 2, 'ados' => 2 ] );
}

$views = [
	[ 'post_id' => 201, 'duration_seconds' => 120, 'max_scroll_pct' => 80, 'last_seen' => $t ],
	[ 'post_id' => 202, 'duration_seconds' => 90,  'max_scroll_pct' => 70, 'last_seen' => $t - 300 ],
];

function call( array $params ): WP_REST_Response {
	return MFY_Rest::handle( new WP_REST_Request( $params ) );
}

function call_page( array $params ): WP_REST_Response {
	return MFY_Rest::handle_page( new WP_REST_Request( $params ) );
}

// --- 1. No page created yet: nothing is ever linked to ----------------------

$r = call( [ 'current_post_id' => 201, 'views' => $views ] );

check( 'the block still works with no suggestions page', true === $r->data['show'] );
check( 'and offers no link to a page that does not exist', ! isset( $r->data['page'] ), json_encode( $r->data['page'] ?? null ) );

// --- 2. The page exists: the link appears, with the row count behind it -----

mock_suggestions_page( 900, 'fr', 'pour-vous' );
MFY_Page::forget_page_ids();

check( 'the page is found by its slug', 900 === MFY_Page::page_id( 'fr' ) );
check( 'and reported as available for French', MFY_Page::available( 'fr' ) );
check( 'but not for a language it has no page in', ! MFY_Page::available( 'de' ) );

$r = call( [ 'current_post_id' => 201, 'views' => $views ] );

check( 'the block now offers a link to the page', isset( $r->data['page']['url'] ), json_encode( $r->data['page'] ?? null ) );
check( 'the link carries a label', ! empty( $r->data['page']['label'] ), $r->data['page']['label'] ?? '—' );
check( 'and only because enough rows were found',
	( $r->data['page']['rows'] ?? 0 ) >= MFY_Config::page_link_min_rows(),
	sprintf( '%d rows, minimum %d', $r->data['page']['rows'] ?? 0, MFY_Config::page_link_min_rows() ) );

// --- 3. A thin session is not sent to a thin page ---------------------------

$one_place_only = [
	[ 'post_id' => 701, 'duration_seconds' => 120, 'max_scroll_pct' => 80, 'last_seen' => $t ],
	[ 'post_id' => 702, 'duration_seconds' => 90,  'max_scroll_pct' => 70, 'last_seen' => $t - 300 ],
];

$thin = call( [ 'current_post_id' => 701, 'views' => $one_place_only ] );

check( 'a session that fills too few rows gets no link',
	! isset( $thin->data['page'] ),
	sprintf( 'rows: %d', MFY_Rows::count_available( 'fr', $one_place_only, [], null ) ) );

// --- 4. The page endpoint -----------------------------------------------------

$page = call_page( [ 'page_id' => 900, 'views' => $views ] );

check( 'the page endpoint returns rows', true === $page->data['show'] && count( $page->data['rows'] ) > 0, (string) count( $page->data['rows'] ) );
check( 'and says the page is personalized', true === $page->data['personalized'] );
check( 'the language comes from the page, not the client', 'fr' === $page->data['lang'] );
check( 'every row carries a heading and its tiles',
	! array_filter( $page->data['rows'], fn( $row ) => empty( $row['title'] ) || empty( $row['items'] ) ) );
check( 'tiles carry the post ID the read-marking pass needs',
	! empty( $page->data['rows'][0]['items'][0]['post_id'] ) );
check( 'the response is private and uncacheable',
	str_contains( $page->headers['Cache-Control'], 'no-store' ) && str_contains( $page->headers['Cache-Control'], 'private' ) );

// --- 5. It builds a page for the suggestions page and nothing else ----------

$wrong = call_page( [ 'page_id' => 201, 'views' => $views ] );
check( 'an ordinary post cannot ask for a page', false === $wrong->data['show'] && 'not the suggestions page' === $wrong->data['reason'] );

$missing = call_page( [ 'views' => $views ] );
check( 'a request with no page ID is refused', false === $missing->data['show'] );

$nobody = call_page( [ 'page_id' => 99999, 'views' => $views ] );
check( 'an unknown page ID is refused', false === $nobody->data['show'] );

// --- 6. A cold visit is served, and labelled honestly ----------------------

$cold = call_page( [ 'page_id' => 900, 'views' => [] ] );

check( 'a visitor with no history still gets a page', ! empty( $cold->data['rows'] ), (string) count( $cold->data['rows'] ) );
check( 'which does not claim to be personalized', false === $cold->data['personalized'] );

// --- 7. The page never enters the profile it is built from ------------------

check( 'the suggestions page is not trackable', ! MFY_Data::should_track_post( 900 ) );

$self_view = array_merge( $views, [ [ 'post_id' => 900, 'duration_seconds' => 200, 'max_scroll_pct' => 90, 'last_seen' => $t + 10 ] ] );
$after     = call_page( [ 'page_id' => 900, 'views' => $self_view ] );
$shown     = [];
foreach ( $after->data['rows'] as $row ) { $shown = array_merge( $shown, array_column( $row['items'], 'post_id' ) ); }

check( 'and never appears in its own rows', ! in_array( 900, $shown, true ), implode( ',', $shown ) );

// --- 8. The placeholder is all the cached page carries ----------------------

$GLOBALS['MOCK_CURRENT_POST'] = 900;
$html = MFY_Page::render_shortcode();

check( 'the shortcode renders one placeholder', 1 === substr_count( $html, 'id="mavo-for-you-page"' ), $html );
check( 'it names the page so the endpoint can verify it', str_contains( $html, 'data-page-id="900"' ) );
check( 'it carries a loading line for the wait', str_contains( $html, 'mfy-page__loading' ) );
check( 'and a noscript fallback that hides it', str_contains( $html, '<noscript>' ) && str_contains( $html, 'mfy-page__empty' ) );
check( 'no suggestion is baked into the cached page',
	! preg_match( '/mv-tile|data-mavo-post-id/', $html ), $html );

// --- 9. The page stays out of the index -------------------------------------

// is_page() decides it, off the queried object.
$GLOBALS['MOCK_QUERY']['queried'] = 900;

$core = MFY_Page::robots( [ 'index' => true, 'max-image-preview' => 'large' ] );

check( 'the suggestions page is noindex', ! empty( $core['noindex'] ), json_encode( $core ) );
check( 'and no longer claims to be indexable', ! isset( $core['index'] ), json_encode( $core ) );
check( 'but its links stay followable', ! empty( $core['follow'] ), json_encode( $core ) );

$yoast = MFY_Page::seo_plugin_robots( [ 'index' => 'index', 'follow' => 'follow' ] );
check( 'an SEO plugin printing its own tag is corrected too', 'noindex' === $yoast['index'], json_encode( $yoast ) );
check( 'and its follow value is left alone', 'follow' === $yoast['follow'], json_encode( $yoast ) );

$GLOBALS['MOCK_QUERY']['queried'] = 201;

$article = MFY_Page::robots( [ 'index' => true ] );
check( 'an ordinary article is left indexable', ! isset( $article['noindex'] ) && ! empty( $article['index'] ), json_encode( $article ) );
check( 'and so is its SEO-plugin tag', 'index' === MFY_Page::seo_plugin_robots( [ 'index' => 'index' ] )['index'] );

$GLOBALS['MOCK_QUERY']['queried'] = 900;

// --- 10. The page's own copy ------------------------------------------------

foreach ( [ 'fr', 'en', 'de' ] as $lang ) {
	$labels = MFY_Config::page_labels( $lang );

	check( sprintf( '[%s] every row template and control has copy', $lang ),
		! array_filter(
			[ 'loading', 'intro', 'coldIntro', 'empty', 'rowHub', 'rowGeo', 'rowFilter', 'rowSearch', 'rowRecent', 'prev', 'next', 'reset', 'resetHint' ],
			fn( $key ) => empty( $labels[ $key ] )
		) );

	foreach ( [ 'rowHub', 'rowGeo', 'rowFilter', 'rowSearch' ] as $template ) {
		check( sprintf( '[%s] %s takes exactly one substitution', $lang, $template ),
			1 === substr_count( $labels[ $template ], '%s' ),
			$labels[ $template ] );
	}

	check( sprintf( '[%s] the reset hint warns that the page will be left', $lang ),
		mb_strlen( $labels['resetHint'] ) > mb_strlen( $labels['reset'] ),
		$labels['resetHint'] );
}

echo "\n";
printf( "%d failure(s)\n", $GLOBALS['FAILED'] );
exit( $GLOBALS['FAILED'] ? 1 : 0 );
