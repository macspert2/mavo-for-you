<?php
require_once __DIR__ . '/harness.php';

$t = time();

// Viewed: A and B both strong on citytrip + angleterre.
mock_post( 1, 'A — Londres en famille', 'fr', [ 'citytrip' => 2, 'angleterre' => 2, 'ados' => 1 ] );
mock_post( 2, 'B — Bath week-end',      'fr', [ 'citytrip' => 2, 'angleterre' => 2 ] );
// Candidates.
mock_post( 3, 'C — Kew Gardens',        'fr', [ 'citytrip' => 2, 'angleterre' => 2 ] ); // shares both, strong
mock_post( 4, 'D — Cornouailles',       'fr', [ 'citytrip' => 1, 'angleterre' => 1 ] ); // partial only
mock_post( 5, 'E — Manchester ados',    'fr', [ 'citytrip' => 2, 'ados' => 2 ] );       // different profile
mock_post( 6, 'F — EN twin',            'en', [ 'citytrip' => 2, 'angleterre' => 2 ] ); // wrong language
mock_post( 7, 'G — draft',              'fr', [ 'citytrip' => 2, 'angleterre' => 2 ], 'draft' );
mock_post( 8, 'H — unrelated',          'fr', [ 'gastronomie' => 2 ] );

$views = [ view( 1, 120, 80, $t ), view( 2, 60, 60, $t - 600 ) ];

// --- 1. C outranks D ------------------------------------------------------
$r = MFY_Scorer::rank( 1, 'fr', $views, [], null );
$ids = array_column( $r['recommendations'], 'post_id' );
check( 'C (2↔2) outranks D (1↔2)', array_search( 3, $ids, true ) < array_search( 4, $ids, true ) || ! in_array( 4, $ids, true ), 'order: ' . implode( ',', $ids ) );

// --- 2. Exclusions --------------------------------------------------------
check( 'current post never recommended', ! in_array( 1, $ids, true ) );
check( 'already-viewed post never recommended', ! in_array( 2, $ids, true ) );
check( 'other-language post never recommended', ! in_array( 6, $ids, true ) );
check( 'draft never recommended', ! in_array( 7, $ids, true ) );
check( 'candidate with no shared strong filter excluded', ! in_array( 8, $ids, true ) );
check( 'at most 3 recommendations', count( $ids ) <= 3, count( $ids ) . ' returned' );

// --- 3. Recency changes the ranking ---------------------------------------
$scores_recent = array_column( $r['debug']['candidates'], 'score', 'post_id' );
$older = MFY_Scorer::rank( 1, 'fr', [ view( 1, 120, 80, $t ), view( 2, 60, 60, $t - 600 ), view( 8, 120, 90, $t - 900 ) ], [], null );
$s_old = array_column( $older['debug']['candidates'], 'score', 'post_id' );
check( 'a third, older view dilutes nothing already scored', $s_old[3] >= $scores_recent[3] - 0.01, sprintf( '%.2f vs %.2f', $s_old[3], $scores_recent[3] ) );

// --- 4. Engagement changes the score --------------------------------------
$low  = MFY_Scorer::rank( 1, 'fr', [ view( 1, 3, 10, $t ), view( 2, 3, 10, $t - 600 ) ], [], null );
$high = MFY_Scorer::rank( 1, 'fr', [ view( 1, 300, 95, $t ), view( 2, 300, 95, $t - 600 ) ], [], null );
$c_low  = array_column( $low['debug']['candidates'], 'score', 'post_id' )[3];
$c_high = array_column( $high['debug']['candidates'], 'score', 'post_id' )[3];
check( 'engaged reading scores higher than a bounce', $c_high > $c_low, sprintf( 'bounce %.2f < engaged %.2f', $c_low, $c_high ) );
// Post 5 shares exactly one strong filter (citytrip). Read attentively that is
// worth showing; skimmed for three seconds it must fall under the threshold.
$e_low  = array_column( $low['debug']['candidates'], 'score', 'post_id' )[5];
$e_high = array_column( $high['debug']['candidates'], 'score', 'post_id' )[5];
check( 'one shared filter from a bounce falls below the threshold', $e_low < MFY_Config::min_score(), sprintf( '%.2f', $e_low ) );
check( 'the same filter read attentively clears it', $e_high >= MFY_Config::min_score(), sprintf( '%.2f', $e_high ) );
check( 'bounce-only session recommends nothing from a single shared filter', ! in_array( 5, array_column( $low['recommendations'], 'post_id' ), true ) );

// --- 5. Search-term mapping -------------------------------------------------
$plain  = MFY_Scorer::rank( 1, 'fr', $views, [], null );
$search = MFY_Scorer::rank( 1, 'fr', $views, [ [ 'query' => 'manchester ado', 'source' => 'site', 'timestamp' => $t ] ], null );
$e_plain  = array_column( $plain['debug']['candidates'], 'score', 'post_id' )[5];
$e_search = array_column( $search['debug']['candidates'], 'score', 'post_id' )[5];
check( '"ado" boosts a candidate scored 2 on ados', $e_search > $e_plain, sprintf( '%.2f → %.2f', $e_plain, $e_search ) );
check( 'search mapping recorded in debug', isset( $search['debug']['search_filters']['ados'] ) );

// --- 6. Referral ------------------------------------------------------------
$ref_noq = MFY_Scorer::rank( 1, 'fr', $views, [], [ 'source' => 'google', 'query' => null, 'landing_post_id' => 1, 'timestamp' => $t ] );
check( 'bare google referrer invents no keywords', empty( $ref_noq['debug']['search_filters'] ) );
$ref_q = MFY_Scorer::rank( 1, 'fr', $views, [], [ 'source' => 'google', 'query' => 'randonnée famille', 'landing_post_id' => 1, 'timestamp' => $t ] );
check( 'referrer query maps, at a reduced weight', ( $ref_q['debug']['search_filters']['nature_rando'] ?? 0 ) === 0.5 );

// --- 7. Determinism ---------------------------------------------------------
$a = MFY_Scorer::rank( 1, 'fr', $views, [], null );
$b = MFY_Scorer::rank( 1, 'fr', $views, [], null );
check( 'identical input gives identical output', $a['recommendations'] === $b['recommendations'] );

// --- 8. Diversity -----------------------------------------------------------
mock_post( 9,  'I — clone 1', 'fr', [ 'citytrip' => 2, 'angleterre' => 2 ] );
mock_post( 10, 'J — clone 2', 'fr', [ 'citytrip' => 2, 'angleterre' => 2 ] );
mock_post( 11, 'K — varied',  'fr', [ 'citytrip' => 2, 'ados' => 2, 'angleterre' => 1 ] );
$div = MFY_Scorer::rank( 1, 'fr', $views, [], null );
$div_ids = array_column( $div['recommendations'], 'post_id' );
check( 'diversity pass admits a differently-profiled candidate', in_array( 11, $div_ids, true ) || in_array( 5, $div_ids, true ), 'picked: ' . implode( ',', $div_ids ) );

// --- 9. Engagement multiplier bounds ---------------------------------------
check( 'engagement clamps at the floor', MFY_Scorer::engagement_multiplier( 0, 0 ) >= 0.25 );
check( 'engagement clamps at the ceiling', MFY_Scorer::engagement_multiplier( 9999, 100 ) <= 1.25 );
check( 'duration multiplier steps correctly',
	MFY_Scorer::duration_multiplier( 7 ) === 0.25 && MFY_Scorer::duration_multiplier( 8 ) === 0.6
	&& MFY_Scorer::duration_multiplier( 30 ) === 1.0 && MFY_Scorer::duration_multiplier( 180 ) === 1.25 );
check( 'scroll multiplier steps correctly',
	MFY_Scorer::scroll_multiplier( 24 ) === 0.5 && MFY_Scorer::scroll_multiplier( 25 ) === 0.75
	&& MFY_Scorer::scroll_multiplier( 90 ) === 1.25 );

// --- 10. The shared candidate pool -------------------------------------------
// The pool is cached without exclusions so two visitors can share one query.
// What must not be shared is the answer.
$GLOBALS['MOCK_TRANSIENTS'] = [];
$GLOBALS['wpdb']->queries = 0;

$alice = MFY_Scorer::rank( 1, 'fr', [ view( 1, 120, 80, $t ), view( 2, 100, 75, $t - 300 ) ], [], null );
$after_first = count( $GLOBALS['MOCK_TRANSIENTS'] );

// Bob read one of the posts Alice is about to be recommended.
$bob = MFY_Scorer::rank( 1, 'fr', [ view( 1, 120, 80, $t ), view( 3, 100, 75, $t - 300 ) ], [], null );

check( 'the first request fills the pool cache', $after_first > 0, (string) $after_first );
check( 'the second adds no new pool keys', count( $GLOBALS['MOCK_TRANSIENTS'] ) === $after_first,
	json_encode( array_keys( $GLOBALS['MOCK_TRANSIENTS'] ) ) );

$alice_ids = array_column( $alice['recommendations'], 'post_id' );
$bob_ids   = array_column( $bob['recommendations'], 'post_id' );

check( 'a shared pool still excludes each visitor\'s own history',
	! in_array( 2, $alice_ids, true ) && ! in_array( 3, $bob_ids, true ),
	'alice: ' . implode( ',', $alice_ids ) . ' | bob: ' . implode( ',', $bob_ids ) );
check( 'and the two get different answers from it', $alice_ids !== $bob_ids,
	'alice: ' . implode( ',', $alice_ids ) . ' | bob: ' . implode( ',', $bob_ids ) );

// A save must retire it, or an edited post lingers in everyone's suggestions.
MFY_Cache::bust();
MFY_Scorer::rank( 1, 'fr', [ view( 1, 120, 80, $t ), view( 2, 100, 75, $t - 300 ) ], [], null );
check( 'a save retires the pool rather than serving it stale',
	count( $GLOBALS['MOCK_TRANSIENTS'] ) > $after_first,
	(string) count( $GLOBALS['MOCK_TRANSIENTS'] ) );

// --- 11. Over-fetching ---------------------------------------------------------
// The pool is trimmed in PHP, so it must be fetched deep enough to survive a
// full history being removed from it.
check( 'the pool over-fetches by at least the history cap',
	MFY_Config::pool_overfetch() >= MFY_Config::max_views(),
	MFY_Config::pool_overfetch() . ' vs ' . MFY_Config::max_views() );

echo "\n";
printf( "%d failure(s)\n", $GLOBALS['FAILED'] );
echo "\n--- example debug explanation for candidate 3 ---\n";
foreach ( $r['debug']['candidates'] as $c ) {
	if ( 3 === $c['post_id'] ) {
		echo $c['title'] . "\n";
		foreach ( $c['reasons'] as $reason ) { echo '  ' . $reason . "\n"; }
		printf( "  Total: %.2f\n", $c['score'] );
	}
}
exit( $GLOBALS['FAILED'] ? 1 : 0 );
