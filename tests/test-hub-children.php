<?php
/**
 * Hub children as candidates: the other articles an editor placed in the same
 * hub, which no score would necessarily have found.
 */
require_once __DIR__ . '/harness.php';

$t = time();

// A hub page with no travel-finder data of its own, and its children.
mock_post( 200, 'Londres en famille : le guide', 'fr', [], 'publish', 'page' );
mock_hub( 200, 'geo' );

mock_post( 201, 'Enfant lu',            'fr', [ 'citytrip' => 2 ] );
mock_post( 202, 'Enfant non lu',        'fr', [ 'citytrip' => 2 ] );
mock_post( 203, 'Enfant sans score',    'fr', [ 'citytrip' => 1 ] ); // no filter scored 2
mock_post( 204, 'Enfant brouillon',     'fr', [ 'citytrip' => 2 ], 'draft' );
mock_post( 205, 'Child in English',     'en', [ 'citytrip' => 2 ] );
mock_post( 206, 'Enfant sans rapport',  'fr', [ 'plage_cote' => 2 ] ); // shares no session filter
foreach ( [ 201, 202, 203, 204, 205, 206 ] as $id ) {
	mock_primary_hub( $id, 200, 'geo' );
}

// An outsider with a better filter overlap than any of the children.
mock_post( 210, 'Barcelone ados', 'fr', [ 'citytrip' => 2, 'ados' => 2, 'culture_histoire' => 2 ] );

function ids( array $r ): array { return array_column( $r['recommendations'], 'post_id' ); }
function titles( array $r ): string { return implode( ', ', array_map( 'get_the_title', ids( $r ) ) ); }
function reasons_for( array $r, int $post_id ): array {
	foreach ( $r['debug']['candidates'] as $candidate ) {
		if ( $candidate['post_id'] === $post_id ) { return $candidate['reasons']; }
	}
	return [];
}

// --- 1. Standing on the hub page: its children are the candidates -----------
// The visitor read the hub itself, then one article inside it.
$on_hub = [ view( 201, 120, 80, $t ), view( 200, 90, 70, $t - 300 ) ];
$r      = MFY_Scorer::rank( 201, 'fr', $on_hub, [], null );
$got    = ids( $r );

check( 'an unread sibling is suggested', in_array( 202, $got, true ), titles( $r ) );
check( 'the read hub is not suggested back', ! in_array( 200, $got, true ), titles( $r ) );
check( 'the read child is not suggested back', ! in_array( 201, $got, true ), titles( $r ) );
check( 'the hub-children pool is reported', ( $r['debug']['pool_hub_children'] ?? 0 ) > 0, json_encode( [ 'filter' => $r['debug']['pool_filter'], 'geo' => $r['debug']['pool_geo'] ?? 0, 'hub' => $r['debug']['pool_hub_children'] ?? 0 ] ) );
check( 'the hub relationship is spelled out in debug',
	(bool) array_filter( reasons_for( $r, 202 ), fn( $line ) => str_contains( $line, 'Londres en famille' ) && str_contains( $line, 'guide' ) ),
	implode( ' | ', reasons_for( $r, 202 ) ) );

// --- 2. Eligibility still applies to children -------------------------------
check( 'a child with no filter scored 2 is not offered', ! in_array( 203, $got, true ), titles( $r ) );
check( 'a draft child is never offered', ! in_array( 204, $got, true ), titles( $r ) );
check( 'a child in another language is never offered', ! in_array( 205, $got, true ), titles( $r ) );

// --- 3. The hub relationship can carry a child no score would have found ----
$scored_ids = array_column( $r['debug']['candidates'], 'post_id' );
check( 'a child sharing no session filter still enters the pool', in_array( 206, $scored_ids, true ), implode( ',', $scored_ids ) );
$unrelated_score = array_column( $r['debug']['candidates'], 'score', 'post_id' )[206] ?? 0;
check( 'and the hub relationship alone clears the minimum score', $unrelated_score >= MFY_Config::min_score(), sprintf( '%.2f', $unrelated_score ) );

// --- 4. A sibling outranks a better filter match from elsewhere -------------
$scores = array_column( $r['debug']['candidates'], 'score', 'post_id' );
check( 'a hand-placed sibling outranks a stronger filter match elsewhere',
	$scores[202] > $scores[210],
	sprintf( 'sibling %.2f vs outsider %.2f', $scores[202], $scores[210] ) );

// --- 5. Siblings without having read the hub itself -------------------------
mock_post( 207, 'Autre enfant lu', 'fr', [ 'citytrip' => 2 ] );
mock_primary_hub( 207, 200, 'geo' );
$siblings_only = [ view( 201, 120, 80, $t ), view( 207, 100, 75, $t - 300 ) ];
$r2  = MFY_Scorer::rank( 201, 'fr', $siblings_only, [], null );
$got2 = ids( $r2 );
check( 'reading two children surfaces the hub first', ( $got2[0] ?? 0 ) === 200, titles( $r2 ) );
check( 'and an unread sibling as well', in_array( 202, $got2, true ), titles( $r2 ) );

// --- 6. Children of a thematic hub count the same way ------------------------
mock_post( 220, 'City trips en famille', 'fr', [], 'publish', 'page' );
mock_hub( 220, 'theme' );
mock_post( 221, 'Enfant thématique', 'fr', [ 'citytrip' => 2 ] );
mock_primary_hub( 221, 220, 'theme' );
mock_primary_hub( 201, 220, 'theme' );
$r3 = MFY_Scorer::rank( 201, 'fr', $on_hub, [], null );
check( 'a thematic hub contributes its children too', in_array( 221, array_column( $r3['debug']['candidates'], 'post_id' ), true ), titles( $r3 ) );

// --- 7. Without Hub Manager, none of this happens ----------------------------
check( 'hub helpers are present in this suite', MFY_Hubs::available() );

echo "\n";
printf( "%d failure(s)\n", $GLOBALS['FAILED'] );

echo "\n--- reading the London guide and one article inside it ---\n";
foreach ( $r['recommendations'] as $item ) {
	printf( "  %-28s score %6.2f %s\n", get_the_title( $item['post_id'] ), $item['score'], isset( $item['hub'] ) ? '[' . $item['hub']['label'] . ']' : '' );
}
exit( $GLOBALS['FAILED'] ? 1 : 0 );
