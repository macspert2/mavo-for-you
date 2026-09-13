<?php
/**
 * Editorial hubs: placement ahead of the ranking, the effect on the geography
 * quota, exclusion of already-read hubs, and their treatment in the
 * recently-viewed list.
 */
require_once __DIR__ . '/harness.php';

// --- REST-layer stubs (recently_viewed lives there) -------------------------
$GLOBALS['IS_ADMIN'] = true;
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

// Geography, so the hub can be shown competing with a geo focus.
mock_place( 10, 'city', 'Londres', 20 );
mock_place( 20, 'region', 'Grand Londres', 30 );
mock_place( 30, 'country', 'Angleterre' );
mock_place( 12, 'city', 'Barcelone', 22 );
mock_place( 22, 'region', 'Catalogne', 32 );
mock_place( 32, 'country', 'Espagne' );
$london    = [ 'city' => 10, 'region' => 20, 'country' => 30 ];
$barcelona = [ 'city' => 12, 'region' => 22, 'country' => 32 ];

$profile = [ 'citytrip' => 2, 'ados' => 2, 'culture_histoire' => 2 ];

// The hubs.
mock_post( 50, 'Londres en famille : le guide', 'fr', [], 'publish', 'page' );
mock_hub( 50, 'geo' );
mock_geo( 50, $london );
mock_post( 51, 'Angleterre', 'fr', [], 'publish', 'page' );
mock_hub( 51, 'geo' );
mock_post( 52, 'City trips en famille', 'fr', [], 'publish', 'page' );
mock_hub( 52, 'theme' );
mock_primary_hub( 50, 51, 'geo' ); // Londres sits under Angleterre.

// Three viewed London articles, all owned by the London hub.
foreach ( [ 1, 2, 3 ] as $id ) {
	mock_post( $id, "Londres $id", 'fr', $profile + [ 'angleterre' => 2 ] );
	mock_geo( $id, $london );
	mock_primary_hub( $id, 50, 'geo' );
	mock_primary_hub( $id, 52, 'theme' );
}

// Unread material.
foreach ( [ 4, 5, 6 ] as $id ) {
	mock_post( $id, "Londres libre $id", 'fr', [ 'citytrip' => 2, 'angleterre' => 2 ] );
	mock_geo( $id, $london );
	mock_primary_hub( $id, 50, 'geo' );
}
mock_post( 7, 'Barcelone', 'fr', $profile ); mock_geo( 7, $barcelona );

$views = [ view( 1, 120, 80, $t ), view( 2, 100, 75, $t - 400 ), view( 3, 90, 70, $t - 800 ) ];

function ids( array $r ): array { return array_column( $r['recommendations'], 'post_id' ); }
function titles( array $r ): string { return implode( ', ', array_map( 'get_the_title', ids( $r ) ) ); }

// --- 1. The hub wins the first slot -----------------------------------------
$r   = MFY_Scorer::rank( 1, 'fr', $views, [], null );
$got = ids( $r );
check( 'the hub takes the first slot', ( $got[0] ?? 0 ) === 50, titles( $r ) );
check( 'the hub is labelled for the reader', ( $r['recommendations'][0]['hub']['label'] ?? '' ) === 'Guide', json_encode( $r['recommendations'][0]['hub'] ?? null ) );
check( 'the hub carries its type', ( $r['recommendations'][0]['hub']['type'] ?? '' ) === 'geo' );
check( 'the other two slots are ordinary suggestions', count( $got ) === 3 && ! array_intersect( array_slice( $got, 1 ), [ 50, 51, 52 ] ), titles( $r ) );
check( 'geography still gets a reserved slot after the hub',
	( $r['debug']['composition']['slots_hub'] ?? 0 ) === 1 && ( $r['debug']['composition']['slots_reserved'] ?? 0 ) === 1,
	json_encode( $r['debug']['composition'] ) );
check( 'a London article still fills that slot', (bool) array_intersect( $got, [ 4, 5, 6 ] ), titles( $r ) );
check( 'and one slot still goes elsewhere', in_array( 7, $got, true ), titles( $r ) );
check( 'the hub is named in debug', (bool) array_filter( $r['debug']['hubs']['found'], fn( $l ) => str_contains( $l, 'Londres en famille' ) ), json_encode( $r['debug']['hubs'] ) );

// --- 2. A hub already read is not offered back ------------------------------
$read_hub = array_merge( $views, [ view( 50, 60, 50, $t - 1000 ) ] );
$r2       = MFY_Scorer::rank( 1, 'fr', $read_hub, [], null );
check( 'an already-read hub is never recommended', ! in_array( 50, ids( $r2 ), true ), titles( $r2 ) );
// The thematic hub carries the full weight of all three views; "Angleterre" is
// only reachable one decayed hop above a hub that has already been read, so the
// more immediate offer wins — as it should.
check( 'another hub is offered in its place', (bool) array_intersect( ids( $r2 ), [ 51, 52 ] ), titles( $r2 ) );
check( 'the more immediate hub wins over a decayed grandparent', in_array( 52, ids( $r2 ), true ), titles( $r2 ) );

// With no thematic hub in play, the ancestor walk is what supplies the offer.
$GLOBALS['MOCK_PRIMARY_HUB'][1]['theme'] = null;
$GLOBALS['MOCK_PRIMARY_HUB'][2]['theme'] = null;
$GLOBALS['MOCK_PRIMARY_HUB'][3]['theme'] = null;
$r2b = MFY_Scorer::rank( 1, 'fr', $read_hub, [], null );
check( 'with the immediate hub read, its parent is offered', in_array( 51, ids( $r2b ), true ), titles( $r2b ) );
foreach ( [ 1, 2, 3 ] as $id ) { mock_primary_hub( $id, 52, 'theme' ); }

// --- 3. Theme hubs count too, and geo breaks the tie ------------------------
$GLOBALS['MOCK_PRIMARY_HUB'][1]['geo'] = null;
$GLOBALS['MOCK_PRIMARY_HUB'][2]['geo'] = null;
$GLOBALS['MOCK_PRIMARY_HUB'][3]['geo'] = null;
$r3 = MFY_Scorer::rank( 1, 'fr', $views, [], null );
check( 'with no geo hub, the thematic hub is offered', in_array( 52, ids( $r3 ), true ), titles( $r3 ) );
foreach ( [ 1, 2, 3 ] as $id ) { mock_primary_hub( $id, 50, 'geo' ); }
$r3b = MFY_Scorer::rank( 1, 'fr', $views, [], null );
check( 'with both, the geographic hub wins the slot', ( ids( $r3b )[0] ?? 0 ) === 50, titles( $r3b ) );

// --- 4. Unpublished / cross-language / retyped hubs are refused -------------
$GLOBALS['MOCK_POSTS'][50]['status'] = 'draft';
( new ReflectionProperty( 'MFY_Hubs', 'type_cache' ) )->setValue( null, [] );
$r4 = MFY_Scorer::rank( 1, 'fr', $views, [], null );
check( 'an unpublished hub is not offered', ! in_array( 50, ids( $r4 ), true ), titles( $r4 ) );
$GLOBALS['MOCK_POSTS'][50]['status'] = 'publish';

$GLOBALS['MOCK_POSTS'][50]['lang'] = 'en';
( new ReflectionProperty( 'MFY_Hubs', 'type_cache' ) )->setValue( null, [] );
$r5 = MFY_Scorer::rank( 1, 'fr', $views, [], null );
check( 'a hub in another language is not offered', ! in_array( 50, ids( $r5 ), true ), titles( $r5 ) );
$GLOBALS['MOCK_POSTS'][50]['lang'] = 'fr';

unset( $GLOBALS['MOCK_HUB_TYPE'][50] );
( new ReflectionProperty( 'MFY_Hubs', 'type_cache' ) )->setValue( null, [] );
$r6 = MFY_Scorer::rank( 1, 'fr', $views, [], null );
check( 'a post that is no longer a hub is not offered as one', ! in_array( 50, ids( $r6 ), true ), titles( $r6 ) );
mock_hub( 50, 'geo' );
( new ReflectionProperty( 'MFY_Hubs', 'type_cache' ) )->setValue( null, [] );

// --- 5. A hub with no travel-finder scores is still offered -----------------
check( 'a hub page with no filter scores at all is still offered',
	mavo_for_you_get_filter_scores( 50, 'fr' ) === array_fill_keys( MFY_Data::signal_slugs(), 0 )
	&& in_array( 50, ids( MFY_Scorer::rank( 1, 'fr', $views, [], null ) ), true ) );

// --- 6. Recently viewed: the hub is kept and marked -------------------------
$request = new WP_REST_Request( [
	'current_post_id' => 1,
	'debug'           => true,
	'views'           => [
		[ 'post_id' => 1,  'duration_seconds' => 120, 'max_scroll_pct' => 80, 'last_seen' => $t ],
		[ 'post_id' => 2,  'duration_seconds' => 100, 'max_scroll_pct' => 75, 'last_seen' => $t - 100 ],
		[ 'post_id' => 3,  'duration_seconds' => 100, 'max_scroll_pct' => 75, 'last_seen' => $t - 200 ],
		[ 'post_id' => 4,  'duration_seconds' => 100, 'max_scroll_pct' => 75, 'last_seen' => $t - 300 ],
		[ 'post_id' => 50, 'duration_seconds' => 100, 'max_scroll_pct' => 75, 'last_seen' => $t - 900 ], // oldest
	],
] );
$response = MFY_Rest::handle( $request );
$recent   = $response->data['recently_viewed'];
$recent_ids = array_column( $recent, 'post_id' );
check( 'the read hub survives a strict-recency cut', in_array( 50, $recent_ids, true ), implode( ',', $recent_ids ) );
check( 'it takes the last slot, not the first', end( $recent_ids ) === 50, implode( ',', $recent_ids ) );
check( 'newer articles keep their order', array_slice( $recent_ids, 0, 2 ) === [ 2, 3 ], implode( ',', $recent_ids ) );
check( 'the hub is marked in the recently-viewed list', ( $recent[ array_search( 50, $recent_ids, true ) ]['hub']['label'] ?? '' ) === 'Guide' );
check( 'ordinary recent items carry no hub marker', ! isset( $recent[0]['hub'] ) );

// --- 7. Without Hub Manager, nothing changes --------------------------------
check( 'hub support is reported as available in these tests', MFY_Hubs::available() );
$labels = MFY_Config::hub_labels( 'de' );
check( 'German label is not the word "hub"', $labels['geo'] === 'Übersicht', $labels['geo'] );

echo "\n";
printf( "%d failure(s)\n", $GLOBALS['FAILED'] );

echo "\n--- the block after three London articles ---\n";
foreach ( $r['recommendations'] as $item ) {
	printf( "  %-32s %s\n", get_the_title( $item['post_id'] ), isset( $item['hub'] ) ? '[' . $item['hub']['label'] . ']' : '' );
}
echo 'composition: ' . json_encode( $r['debug']['composition'] ) . "\n";
exit( $GLOBALS['FAILED'] ? 1 : 0 );
