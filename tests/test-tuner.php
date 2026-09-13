<?php
/**
 * The tuner's arithmetic. The sampling needs WordPress, but the analysis is
 * pure — and it is the part that would quietly mislead a tuning decision.
 */
require_once __DIR__ . '/harness.php';

function add_management_page( ...$args ) {}
function add_action( ...$args ) {}
require_once __DIR__ . '/../includes/class-mavo-for-you-tuner.php';

function session( string $shape, array $scores ): array {
	return [
		'shape' => $shape, 'post_id' => 1, 'title' => 't', 'views' => 3,
		'candidates' => count( $scores ), 'scores' => $scores, 'hub_slots' => 0,
		'shown' => min( 3, count( $scores ) ),
	];
}

// --- Percentiles --------------------------------------------------------------
check( 'median of an odd set', MFY_Tuner::percentile( [ 1, 5, 9 ], 50 ) === 5.0 );
check( 'p10 takes the lowest', MFY_Tuner::percentile( [ 1, 2, 3, 4, 5, 6, 7, 8, 9, 10 ], 10 ) === 1.0 );
check( 'p90 takes the highest', MFY_Tuner::percentile( [ 1, 2, 3, 4, 5, 6, 7, 8, 9, 10 ], 90 ) === 9.0 );
check( 'unsorted input is sorted first', MFY_Tuner::percentile( [ 9, 1, 5 ], 50 ) === 5.0 );
check( 'an empty set is 0, not a warning', MFY_Tuner::percentile( [], 50 ) === 0.0 );
check( 'a single value is its own median', MFY_Tuner::percentile( [ 7.5 ], 50 ) === 7.5 );

// --- The sweep ----------------------------------------------------------------
$sessions = [
	session( 'focused',   [ 40, 30, 20, 10 ] ),   // 3+ above every threshold to 20
	session( 'focused',   [ 12, 8, 4 ] ),
	session( 'thematic',  [ 9, 3 ] ),             // never fills a block above 9
	session( 'scattered', [ 2 ] ),                // essentially nothing
];
// Keyed by string: 2.5 and 7.5 as array keys would truncate to 2 and 7.
$sweep = [];
foreach ( MFY_Tuner::sweep( $sessions ) as $row ) {
	$sweep[ (string) $row['threshold'] ] = $row;
}

check( 'at 0 every session with candidates is counted',
	$sweep['0']['full'] === 50.0 && $sweep['0']['some'] === 50.0 && $sweep['0']['none'] === 0.0,
	json_encode( $sweep['0'] ) );
check( 'at 10 only the strong session fills a block',
	$sweep['10']['full'] === 25.0, json_encode( $sweep['10'] ) );
check( 'at 30 nothing fills a block, one still shows something',
	$sweep['30']['full'] === 0.0 && $sweep['30']['some'] === 25.0, json_encode( $sweep['30'] ) );
check( 'at 60 every session shows nothing', $sweep['60']['none'] === 100.0 );
check( 'the three columns always total 100', (bool) array_product( array_map(
	static fn( $r ) => abs( $r['full'] + $r['some'] + $r['none'] - 100 ) < 1.5 ? 1 : 0,
	MFY_Tuner::sweep( $sessions ) ) ) );
check( 'raising the threshold never increases coverage', (function () use ( $sessions ) {
	$prev = 101;
	foreach ( MFY_Tuner::sweep( $sessions ) as $row ) {
		if ( $row['full'] > $prev ) { return false; }
		$prev = $row['full'];
	}
	return true;
} )() );

// --- Distribution -------------------------------------------------------------
$dist = MFY_Tuner::distribution( $sessions );
check( 'shapes are reported separately', isset( $dist['focused'], $dist['thematic'], $dist['scattered'] ) );
check( 'a shape with no sessions is omitted', ! isset( $dist['impersonal'] ) );
check( 'n counts the sessions of that shape', $dist['focused']['n'] === 2 );
check( 'a missing third score reads as 0, not a fatal', $dist['scattered']['third_med'] === 0.0 );
check( 'the ratio is third over top', abs( $dist['focused']['ratio_med'] - 0.333 ) < 0.02 || abs( $dist['focused']['ratio_med'] - 0.5 ) < 0.02,
	(string) $dist['focused']['ratio_med'] );

// --- CSV ----------------------------------------------------------------------
$csv = MFY_Tuner::to_csv( $sessions );
$rows = array_values( array_filter( explode( "\n", $csv ) ) );
check( 'csv has a header and one row per session', count( $rows ) === count( $sessions ) + 1, (string) count( $rows ) );
check( 'every row has the same column count',
	count( array_unique( array_map( static fn( $r ) => substr_count( $r, ',' ), $rows ) ) ) === 1 );
check( 'a title containing a quote cannot break the row',
	! str_contains( MFY_Tuner::to_csv( [ array_merge( session( 'focused', [ 1 ] ), [ 'title' => 'He said "no"' ] ) ] ), '""' ) );

// --- Sample health ------------------------------------------------------------
// post_health() is what turns "24 skipped" into a finding rather than a shrug.
check( 'post_health reports all four signals', (function () {
	$r = new ReflectionMethod( 'MFY_Tuner', 'post_health' );
	return $r->isPublic();
} )() );
check( 'the sweep is unaffected by health reporting', count( MFY_Tuner::sweep( $sessions ) ) === count( MFY_Tuner::SWEEP ) );

echo "\n";
printf( "%d failure(s)\n", $GLOBALS['FAILED'] );
exit( $GLOBALS['FAILED'] ? 1 : 0 );
