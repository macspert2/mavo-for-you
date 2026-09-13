<?php
/**
 * §3: contact, privacy and legal pages must not enter the profile — enforced
 * identically in the browser and at the endpoint.
 */
require_once __DIR__ . '/harness.php';

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

mock_post( 1, 'Londres 1',   'fr', [ 'citytrip' => 2, 'angleterre' => 2 ] );
mock_post( 2, 'Londres 2',   'fr', [ 'citytrip' => 2, 'angleterre' => 2 ] );
mock_post( 3, 'Kew Gardens', 'fr', [ 'citytrip' => 2, 'angleterre' => 2 ] );

// Utility pages, in the site's three languages and both suffix styles.
mock_post( 10, 'Contact',            'fr', [], 'publish', 'page', 'contact' );
mock_post( 11, 'Mentions légales',   'fr', [], 'publish', 'page', 'mentions-legales' );
mock_post( 12, 'Impressum',          'de', [], 'publish', 'page', 'impressum-2' );
mock_post( 13, 'Contact',            'en', [], 'publish', 'page', 'contact-en' );
mock_post( 14, 'Datenschutz',        'de', [], 'publish', 'page', 'datenschutz' );

// Editorial pages that must keep being tracked.
mock_post( 20, 'À propos',           'fr', [], 'publish', 'page', 'a-propos' );
mock_post( 21, 'Londres, le guide',  'fr', [ 'citytrip' => 2 ], 'publish', 'page', 'londres-en-famille' );
mock_post( 22, 'Un article',         'fr', [ 'citytrip' => 2 ], 'publish', 'post', 'contact' ); // post, not page

// The privacy page WordPress itself knows about, whatever its slug.
mock_post( 30, 'Confidentialité',    'fr', [], 'publish', 'page', 'vie-privee' );
$GLOBALS['MOCK_OPTIONS']['wp_page_for_privacy_policy'] = 30;

// --- 1. Recognition ---------------------------------------------------------
check( 'a contact page is a utility page', MFY_Data::is_utility_page( 10 ) );
check( 'a legal page is a utility page', MFY_Data::is_utility_page( 11 ) );
check( 'a duplicate-suffixed slug still matches', MFY_Data::is_utility_page( 12 ), 'impressum-2' );
check( 'a language-suffixed slug still matches', MFY_Data::is_utility_page( 13 ), 'contact-en' );
check( 'a German privacy page matches', MFY_Data::is_utility_page( 14 ) );
check( "WordPress's own privacy page matches whatever its slug", MFY_Data::is_utility_page( 30 ), 'vie-privee' );

check( 'an about page is editorial and still tracked', ! MFY_Data::is_utility_page( 20 ) );
check( 'a hub page is still tracked', ! MFY_Data::is_utility_page( 21 ) );
check( 'a post is never judged by the page slug list', ! MFY_Data::is_utility_page( 22 ) );
check( 'the decision runs through should_track_post()', ! MFY_Data::should_track_post( 10 ) && MFY_Data::should_track_post( 20 ) );

// --- 2. The endpoint drops them, whatever the client sends ------------------
$GLOBALS['IS_ADMIN'] = true;
$response = MFY_Rest::handle( new WP_REST_Request( [
	'current_post_id' => 1,
	'debug'           => true,
	'views'           => [
		[ 'post_id' => 1,  'duration_seconds' => 120, 'max_scroll_pct' => 80, 'last_seen' => $t ],
		[ 'post_id' => 10, 'duration_seconds' => 120, 'max_scroll_pct' => 90, 'last_seen' => $t - 100 ],
		[ 'post_id' => 30, 'duration_seconds' => 120, 'max_scroll_pct' => 90, 'last_seen' => $t - 200 ],
		[ 'post_id' => 2,  'duration_seconds' => 100, 'max_scroll_pct' => 75, 'last_seen' => $t - 300 ],
	],
] ) );
$profiled = array_column( $response->data['debug']['profile_views'], 'post_id' );
$recent   = array_column( $response->data['recently_viewed'], 'post_id' );

check( 'a stale contact-page view is dropped by the endpoint', ! in_array( 10, $profiled, true ), implode( ',', $profiled ) );
check( 'a stale privacy-page view is dropped too', ! in_array( 30, $profiled, true ), implode( ',', $profiled ) );
check( 'genuine views survive', $profiled === [ 1, 2 ], implode( ',', $profiled ) );
check( 'no utility page reaches "recently viewed"', ! array_intersect( $recent, [ 10, 30 ] ), implode( ',', $recent ) );

// --- 3. They cannot make up the meaningful-view quorum ----------------------
$thin = MFY_Rest::handle( new WP_REST_Request( [
	'current_post_id' => 1,
	'views'           => [
		[ 'post_id' => 1,  'duration_seconds' => 120, 'max_scroll_pct' => 80, 'last_seen' => $t ],
		[ 'post_id' => 10, 'duration_seconds' => 120, 'max_scroll_pct' => 90, 'last_seen' => $t - 100 ],
	],
] ) );
check( 'one article plus a contact page is not two meaningful views', $thin->data['show'] === false, json_encode( $thin->data['reason'] ?? '' ) );

// --- 4. A utility page as the current page is refused outright --------------
$on_contact = MFY_Rest::handle( new WP_REST_Request( [
	'current_post_id' => 10,
	'views'           => [
		[ 'post_id' => 1, 'duration_seconds' => 120, 'max_scroll_pct' => 80, 'last_seen' => $t ],
		[ 'post_id' => 2, 'duration_seconds' => 100, 'max_scroll_pct' => 75, 'last_seen' => $t - 100 ],
	],
] ) );
check( 'no block is offered on a contact page', $on_contact->data['show'] === false );
check( 'and the reason says so', ( $on_contact->data['reason'] ?? '' ) === 'not a trackable page', $on_contact->data['reason'] ?? '' );

echo "\n";
printf( "%d failure(s)\n", $GLOBALS['FAILED'] );
exit( $GLOBALS['FAILED'] ? 1 : 0 );
