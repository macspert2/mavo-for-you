<?php
/**
 * Where the placeholder goes.
 *
 * The rule this pins down: a post carrying [geo_related] places the block
 * itself, so the after-content hook stands down — and a post without the
 * shortcode behaves exactly as it did before the shortcode existed.
 */
require __DIR__ . '/harness.php';
require __DIR__ . '/../includes/class-mavo-for-you-cache.php';
require __DIR__ . '/../includes/class-mavo-for-you-shortcode.php';

// --- The conditional tags the render class consults -------------------------
$GLOBALS['MOCK_QUERY'] = [
	'is_admin'      => false,
	'is_feed'       => false,
	'is_search'     => false,
	'is_404'        => false,
	'is_front_page' => false,
	'is_singular'   => true,
	'queried'       => 0,
];
function is_admin() { return $GLOBALS['MOCK_QUERY']['is_admin']; }
function is_feed() { return $GLOBALS['MOCK_QUERY']['is_feed']; }
function is_search() { return $GLOBALS['MOCK_QUERY']['is_search']; }
function is_404() { return $GLOBALS['MOCK_QUERY']['is_404']; }
function is_front_page() { return $GLOBALS['MOCK_QUERY']['is_front_page']; }
function is_singular( $types = [] ) {
	if ( ! $GLOBALS['MOCK_QUERY']['is_singular'] ) { return false; }
	$post = get_post( $GLOBALS['MOCK_QUERY']['queried'] );
	return $post && ( ! $types || in_array( $post->post_type, (array) $types, true ) );
}
function get_queried_object() { return get_post( $GLOBALS['MOCK_QUERY']['queried'] ); }
function get_queried_object_id() { return (int) $GLOBALS['MOCK_QUERY']['queried']; }
function in_the_loop() { return true; }
function is_main_query() { return true; }
function add_filter( ...$args ) {}
function add_action( ...$args ) {}
function wp_enqueue_style( ...$args ) {}
function wp_enqueue_script( ...$args ) {}
function wp_add_inline_script( ...$args ) {}
function wp_json_encode( $v ) { return json_encode( $v ); }
function wp_create_nonce( $a ) { return 'nonce'; }
function nocache_headers() {}
function plugin_dir_path( $f ) { return dirname( $f ) . '/'; }
function plugin_dir_url( $f ) { return 'https://example.test/plugin/'; }
function filemtime_safe() { return 1; }
function rest_url( $path = '' ) { return 'https://example.test/wp-json/' . ltrim( $path, '/' ); }
function current_user_can( $cap ) { return false; }
function register_rest_route( ...$args ) {}
function sanitize_text_field( $s ) { return trim( (string) $s ); }
class WP_REST_Server { const CREATABLE = 'POST'; }
class WP_REST_Request { public function get_param( $k ) { return null; } }
class WP_REST_Response {
	public $data; public $headers = [];
	public function __construct( $d, $s ) { $this->data = $d; }
	public function header( $k, $v ) { $this->headers[ $k ] = $v; }
}

define( 'MFY_PLUGIN_DIR', dirname( __DIR__ ) . '/' );
define( 'MFY_PLUGIN_URL', 'https://example.test/plugin/' );
define( 'MFY_VERSION', 'test' );

require __DIR__ . '/../includes/class-mavo-for-you-rest.php';
require __DIR__ . '/../includes/class-mavo-for-you-render.php';

/** Renders the after-content hook for one post, from a clean slate. */
function render_for( int $post_id ): string {
	$GLOBALS['MOCK_QUERY']['queried'] = $post_id;
	( new ReflectionProperty( 'MFY_Render', 'rendered' ) )->setValue( null, false );

	ob_start();
	MFY_Render::render_placeholder();
	return (string) ob_get_clean();
}

// --- Fixtures ----------------------------------------------------------------
mock_post( 1, 'Un article sans shortcode', 'fr', [ 'citytrip' => 2 ] );
mock_post( 2, 'Un article avec shortcode', 'fr', [ 'citytrip' => 2 ] );
mock_content( 2, "Du texte.\n\n[geo_related]\n\nEncore du texte." );
mock_post( 3, 'Avec attributs', 'fr', [ 'citytrip' => 2 ] );
mock_content( 3, '[geo_related level="city" limit="4"]' );
mock_post( 4, 'Une page éditoriale', 'fr', [], 'publish', 'page', 'a-propos' );
mock_post( 5, 'Contact', 'fr', [], 'publish', 'page', 'contact' );
mock_post( 6, 'Brouillon', 'fr', [ 'citytrip' => 2 ], 'draft' );
mock_post( 7, 'In English', 'en', [ 'citytrip' => 2 ] );

// --- 1. The unchanged path: no shortcode -------------------------------------
$html = render_for( 1 );
check( 'a post without the shortcode still gets the placeholder', str_contains( $html, 'id="mavo-for-you"' ), $html );
check( 'it carries the post id', str_contains( $html, 'data-current-post-id="1"' ), $html );
check( 'it keeps the documented class', str_contains( $html, 'class="mavo-for-you-placeholder"' ) );
check( 'it is empty, so the cached HTML stays impersonal', str_contains( $html, '></div>' ), $html );
check( 'it carries no size or level: those are the shortcode\'s business',
	! str_contains( $html, 'data-limit' ) && ! str_contains( $html, 'data-level' ), $html );
check( 'an editorial page gets it too', str_contains( render_for( 4 ), 'id="mavo-for-you"' ) );

// --- 2. The shortcode path: the hook stands down -----------------------------
check( 'a post with [geo_related] gets nothing from the hook', render_for( 2 ) === '', render_for( 2 ) );
check( 'attributes on the shortcode make no difference to that', render_for( 3 ) === '' );

// The shortcode is what places the block on those posts.
$GLOBALS['MOCK_CURRENT_POST'] = 2;
( new ReflectionProperty( 'MFY_Shortcode', 'rendered' ) )->setValue( null, [] );
check( 'and the shortcode itself does place one', str_contains( MFY_Shortcode::render_shortcode( [] ), 'id="mavo-for-you"' ) );

// --- 3. Exactly one placeholder per page, either way -------------------------
$GLOBALS['MOCK_QUERY']['queried'] = 1;
( new ReflectionProperty( 'MFY_Render', 'rendered' ) )->setValue( null, false );
ob_start();
MFY_Render::render_placeholder();
MFY_Render::render_placeholder();
$twice = (string) ob_get_clean();
check( 'the hook cannot emit two placeholders', substr_count( $twice, 'id="mavo-for-you"' ) === 1, substr_count( $twice, 'id="mavo-for-you"' ) . ' found' );

// --- 4. Everything that already suppressed it, still does --------------------
check( 'nothing on a draft', render_for( 6 ) === '' );
check( 'nothing on a contact page', render_for( 5 ) === '' );

$GLOBALS['MOCK_QUERY']['is_front_page'] = true;
check( 'nothing on the front page', render_for( 1 ) === '' );
$GLOBALS['MOCK_QUERY']['is_front_page'] = false;

$GLOBALS['MOCK_QUERY']['is_search'] = true;
check( 'nothing on a search page', render_for( 1 ) === '' );
$GLOBALS['MOCK_QUERY']['is_search'] = false;

$GLOBALS['MOCK_QUERY']['is_feed'] = true;
check( 'nothing in a feed', render_for( 1 ) === '' );
$GLOBALS['MOCK_QUERY']['is_feed'] = false;

$GLOBALS['MOCK_QUERY']['is_404'] = true;
check( 'nothing on a 404', render_for( 1 ) === '' );
$GLOBALS['MOCK_QUERY']['is_404'] = false;

$GLOBALS['MOCK_QUERY']['is_singular'] = false;
check( 'nothing on an archive', render_for( 1 ) === '' );
$GLOBALS['MOCK_QUERY']['is_singular'] = true;

check( 'the unchanged path still works afterwards', str_contains( render_for( 1 ), 'id="mavo-for-you"' ) );

// --- 5. The language toggle --------------------------------------------------
$GLOBALS['MOCK_OPTIONS'][ MFY_Config::OPTION_ENABLED_LANGS ] = [ 'fr' ];
check( 'a disabled language gets no placeholder', render_for( 7 ) === '' );
check( 'an enabled one still does', str_contains( render_for( 1 ), 'id="mavo-for-you"' ) );
unset( $GLOBALS['MOCK_OPTIONS'][ MFY_Config::OPTION_ENABLED_LANGS ] );

echo "\n";
printf( "%d failure(s)\n", $GLOBALS['FAILED'] );
exit( $GLOBALS['FAILED'] ? 1 : 0 );
