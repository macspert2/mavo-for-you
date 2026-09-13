<?php
/**
 * Geography-aware composition — the behaviour reported from the first live
 * test: three London articles read, three suggestions about Barcelona and
 * Edinburgh returned.
 */
require_once __DIR__ . '/harness.php';

$t = time();

// Places: two English cities under one country, one Spanish, one Italian.
mock_place( 10, 'city',    'Londres',        20 );
mock_place( 20, 'region',  'Grand Londres',  30 );
mock_place( 30, 'country', 'Angleterre' );
mock_place( 11, 'city',    'Édimbourg',      21 );
mock_place( 21, 'region',  'Lothian',        30 );
mock_place( 12, 'city',    'Barcelone',      22 );
mock_place( 22, 'region',  'Catalogne',      32 );
mock_place( 32, 'country', 'Espagne' );
mock_place( 13, 'city',    'Rome',           23 );
mock_place( 23, 'region',  'Latium',         33 );
mock_place( 33, 'country', 'Italie' );

$london    = [ 'city' => 10, 'region' => 20, 'country' => 30 ];
$edinburgh = [ 'city' => 11, 'region' => 21, 'country' => 30 ];
$barcelona = [ 'city' => 12, 'region' => 22, 'country' => 32 ];
$rome      = [ 'city' => 13, 'region' => 23, 'country' => 33 ];

// A city-trip-with-teenagers profile shared by everything, which is exactly
// why filter scores alone could not tell London from Barcelona.
$citytrip_ados = [ 'citytrip' => 2, 'ados' => 2, 'culture_histoire' => 2 ];

// Viewed: three London articles.
mock_post( 1, 'Londres avec ados',      'fr', $citytrip_ados + [ 'angleterre' => 2 ] ); mock_geo( 1, $london );
mock_post( 2, 'Londres en 3 jours',     'fr', $citytrip_ados + [ 'angleterre' => 2 ] ); mock_geo( 2, $london );
mock_post( 3, 'Musées de Londres',      'fr', $citytrip_ados + [ 'angleterre' => 2 ] ); mock_geo( 3, $london );

// Unread London.
mock_post( 4, 'Kew Gardens',            'fr', [ 'citytrip' => 2, 'angleterre' => 2 ] ); mock_geo( 4, $london );
mock_post( 5, 'Camden Market',          'fr', [ 'citytrip' => 2, 'angleterre' => 2 ] ); mock_geo( 5, $london );
mock_post( 6, 'Tamise en bateau',       'fr', [ 'citytrip' => 2, 'angleterre' => 2 ] ); mock_geo( 6, $london );

// Elsewhere — stronger filter overlap than the unread London posts.
mock_post( 7, 'Barcelone 1',            'fr', $citytrip_ados ); mock_geo( 7, $barcelona );
mock_post( 8, 'Barcelone 2',            'fr', $citytrip_ados ); mock_geo( 8, $barcelona );
mock_post( 9, 'Édimbourg',              'fr', $citytrip_ados + [ 'angleterre' => 2 ] ); mock_geo( 9, $edinburgh );

$three_london = [ view( 1, 120, 80, $t ), view( 2, 100, 75, $t - 400 ), view( 3, 90, 70, $t - 800 ) ];

function ids( array $result ): array {
	return array_column( $result['recommendations'], 'post_id' );
}
function titles( array $result ): string {
	return implode( ', ', array_map( 'get_the_title', ids( $result ) ) );
}

// --- 1. The reported scenario ----------------------------------------------
$r   = MFY_Scorer::rank( 1, 'fr', $three_london, [], null );
$got = ids( $r );
$in_london = count( array_intersect( $got, [ 4, 5, 6 ] ) );

check( 'three London views yield exactly 2 London suggestions', $in_london === 2, titles( $r ) );
check( 'the third suggestion is somewhere else', count( $got ) === 3 && $in_london === 2, titles( $r ) );
check( 'focus is detected at city level', ( $r['debug']['composition']['focus_level'] ?? null ) === 'city', json_encode( $r['debug']['composition'] ) );
check( 'focus place named in debug', ( $r['debug']['composition']['focus_place'] ?? '' ) === 'Londres' );
check( 'no viewed post is recommended back', ! array_intersect( $got, [ 1, 2, 3 ] ) );

// --- 2. Not enough unread London: cascade outwards --------------------------
$two_read = array_merge( $three_london, [ view( 5, 100, 80, $t - 1200 ), view( 6, 100, 80, $t - 1500 ) ] );
$r2       = MFY_Scorer::rank( 1, 'fr', $two_read, [], null );
$got2     = ids( $r2 );
check( 'with one unread London left, reservation cascades outwards',
	in_array( ( $r2['debug']['composition']['focus_level'] ?? '' ), [ 'region', 'country' ], true ),
	json_encode( $r2['debug']['composition'] ) );
check( 'the remaining London article is still suggested', in_array( 4, $got2, true ), titles( $r2 ) );
check( 'the same-country city fills the quota rather than Spain', in_array( 9, $got2, true ), titles( $r2 ) );

// --- 3. One stray click does not lose the focus -----------------------------
$mostly_london = [ view( 1, 120, 80, $t ), view( 2, 120, 80, $t - 400 ), view( 7, 120, 80, $t - 800 ) ];
$r3 = MFY_Scorer::rank( 1, 'fr', $mostly_london, [], null );
check( '2 London + 1 Barcelona is still a London session',
	( $r3['debug']['composition']['focus_place'] ?? '' ) === 'Londres',
	json_encode( $r3['debug']['composition'] ) );

// --- 4. A genuinely mixed session hands the ranking back to tvf --------------
mock_post( 14, 'Rome en famille', 'fr', $citytrip_ados ); mock_geo( 14, $rome );
$mixed = [ view( 1, 120, 80, $t ), view( 7, 120, 80, $t - 400 ), view( 14, 120, 80, $t - 800 ) ];
$r4    = MFY_Scorer::rank( 1, 'fr', $mixed, [], null );
check( 'three different countries: no focus, no reservation', empty( $r4['debug']['composition'] ), json_encode( $r4['debug']['composition'] ) );
check( 'mixed session still returns suggestions', count( ids( $r4 ) ) > 0, titles( $r4 ) );

// --- 5. Geography can carry a candidate the filters would have missed --------
mock_post( 15, 'Plages près de Londres', 'fr', [ 'plage_cote' => 2 ] ); // no filter overlap at all
mock_geo( 15, $london );
$r5 = MFY_Scorer::rank( 1, 'fr', $three_london, [], null );
$scored_ids = array_column( $r5['debug']['candidates'], 'post_id' );
check( 'a same-city post with no shared filter still enters the pool', in_array( 15, $scored_ids, true ) );
check( 'the geo pool is counted separately in debug', ( $r5['debug']['pool_geo'] ?? 0 ) > 0, json_encode( [ 'filter' => $r5['debug']['pool_filter'], 'geo' => $r5['debug']['pool_geo'] ] ) );

// --- 6. Geo bonus is explainable -------------------------------------------
$reasons = [];
foreach ( $r['debug']['candidates'] as $candidate ) {
	if ( 4 === $candidate['post_id'] ) { $reasons = $candidate['reasons']; }
}
check( 'the same-city bonus appears as its own line', (bool) array_filter( $reasons, fn( $line ) => str_contains( $line, 'same city' ) ), implode( ' | ', $reasons ) );

// --- 7. Without geo data, nothing changes ------------------------------------
$GLOBALS['MOCK_PLACES']      = [];
$GLOBALS['MOCK_POST_PLACES'] = [];
// Reset the memoized availability check, so the fallback path is genuinely exercised.
( new ReflectionProperty( 'MFY_Geo', 'available' ) )->setValue( null, null );
$r6 = MFY_Scorer::rank( 1, 'fr', $three_london, [], null );
check( 'no geo data: no reservation, ranking falls back to filters', empty( $r6['debug']['composition'] ) );
check( 'no geo data: suggestions are still produced', count( ids( $r6 ) ) === 3, titles( $r6 ) );

echo "\n";
printf( "%d failure(s)\n", $GLOBALS['FAILED'] );

echo "\n--- what the reader now gets after three London articles ---\n";
foreach ( $r['recommendations'] as $item ) {
	printf( "  %-24s score %.2f\n", get_the_title( $item['post_id'] ), $item['score'] );
}
echo 'composition: ' . json_encode( $r['debug']['composition'] ) . "\n";
exit( $GLOBALS['FAILED'] ? 1 : 0 );
