<?php
/**
 * Rows may repeat articles. They may not repeat a heading.
 *
 * The page deliberately lets the same article appear under several headings —
 * that is what makes it a set of viewpoints rather than one ranking. But
 * "Dans « Angleterre »" directly above "Destination Angleterre" is one heading
 * said twice, and the geography row wins because it holds every article tagged
 * with the place rather than only the hand-placed ones.
 *
 * The limits are what most of this file is about, and they are deliberately
 * narrow: the match is on the whole title, so a hub with a name of its own
 * keeps its row even when it is about exactly the place the geography row
 * names. Most of these assertions exist to stop a broader rule creeping back.
 */
require_once __DIR__ . '/harness.php';
require_once __DIR__ . '/../includes/class-mavo-for-you-rows.php';

$t = time();

mock_place( 11, 'city', 'Londres', 22 );
mock_place( 22, 'country', 'Angleterre' );

// The England hub: a page about exactly the country, tagged as such.
mock_post( 100, 'Angleterre', 'fr', [], 'publish', 'page' );
mock_hub( 100, 'geo' );
mock_geo( 100, [ 'country' => 22 ] );

// The London hub, inside it: tagged London *and* England, like every post.
mock_post( 110, 'Londres en famille', 'fr', [], 'publish', 'page' );
mock_hub( 110, 'geo' );
mock_geo( 110, [ 'city' => 11, 'country' => 22 ] );
mock_primary_hub( 110, 100, 'geo' );

// A thematic hub that happens to sit in England: never a destination.
mock_post( 120, 'City trips en famille', 'fr', [], 'publish', 'page' );
mock_hub( 120, 'theme' );
mock_geo( 120, [ 'country' => 22 ] );

// Children of each, all in London and therefore all in England.
foreach ( [ 201, 202, 203, 204, 205 ] as $i => $id ) {
	mock_post( $id, 'Londres ' . ( $i + 1 ), 'fr', [ 'citytrip' => 2 ] );
	mock_geo( $id, [ 'city' => 11, 'country' => 22 ] );
	mock_primary_hub( $id, 110, 'geo' );
	mock_primary_hub( $id, 120, 'theme' );
}

// London articles the guide does not own, so the city row is not merely the hub
// row again — drop_redundant() would otherwise remove it before the naming rule
// is ever consulted.
foreach ( [ 211, 212, 213 ] as $i => $id ) {
	mock_post( $id, 'Londres libre ' . ( $i + 1 ), 'fr', [ 'citytrip' => 2 ] );
	mock_geo( $id, [ 'city' => 11, 'country' => 22 ] );
}

// Children of the thematic hub that are not London articles, so its row has a
// set of its own to be judged on.
foreach ( [ 501, 502, 503, 504, 505 ] as $i => $id ) {
	mock_post( $id, 'City trip ' . ( $i + 1 ), 'fr', [ 'citytrip' => 2 ] );
	mock_geo( $id, [ 'country' => 22 ] );
	mock_primary_hub( $id, 120, 'theme' );
}

// England articles outside London, so the country row is not merely the city
// row again — otherwise drop_redundant() would remove it before we got here.
foreach ( [ 301, 302, 303, 304, 305 ] as $i => $id ) {
	mock_post( $id, 'Angleterre ' . ( $i + 1 ), 'fr', [ 'gastronomie' => 2 ] );
	mock_geo( $id, [ 'country' => 22 ] );
	mock_primary_hub( $id, 100, 'geo' );
}

// An Edinburgh guide whose articles carry only the country, not the city.
// That is the shape that isolates "sits inside" from "is": the session knows
// about England and nothing finer, so the only destination row is England's —
// and this hub is about Edinburgh, so it is not that row said twice.
mock_place( 33, 'city', 'Édimbourg', 22 );
mock_post( 130, 'Guide d’Édimbourg', 'fr', [], 'publish', 'page' );
mock_hub( 130, 'geo' );
mock_geo( 130, [ 'city' => 33, 'country' => 22 ] );
mock_primary_hub( 130, 100, 'geo' );

foreach ( [ 401, 402, 403, 404, 405 ] as $i => $id ) {
	mock_post( $id, 'Écosse ' . ( $i + 1 ), 'fr', [ 'gastronomie' => 2 ] );
	mock_geo( $id, [ 'country' => 22 ] );
	mock_primary_hub( $id, 130, 'geo' );
}

function headings( array $built ): array {
	return array_map( fn( $row ) => $row['kind'] . ': ' . $row['title'], $built['rows'] );
}

function kinds_of( array $built ): array {
	return array_column( $built['rows'], 'kind' );
}

function has_heading( array $built, string $needle ): bool {
	foreach ( $built['rows'] as $row ) {
		if ( str_contains( $row['title'], $needle ) ) { return true; }
	}
	return false;
}

// --- 1. The case from the live site -----------------------------------------
//
// Reading England articles produces a country geography row and an England hub
// row, both named "Angleterre". Only the destination survives.

$in_england = [ view( 301, 120, 80, $t ), view( 302, 90, 70, $t - 300 ) ];
$built      = MFY_Rows::build( 'fr', $in_england, [], null );

check( 'the destination row is shown', has_heading( $built, 'Destination Angleterre' ), implode( ' | ', headings( $built ) ) );
check( 'and the hub row naming the same place is not',
	! has_heading( $built, 'Dans « Angleterre »' ), implode( ' | ', headings( $built ) ) );

// --- 2. A hub with a name of its own keeps its row ---------------------------
//
// The Edinburgh guide sits inside England and is tagged with it, because Geo
// Tagger attaches every level of the chain. Its heading says something the
// England row does not, so both stand.

$in_scotland = [ view( 401, 120, 80, $t ), view( 402, 90, 70, $t - 300 ) ];
$built       = MFY_Rows::build( 'fr', $in_scotland, [], null );

check( 'a hub keeps its row against a destination it merely sits inside',
	has_heading( $built, 'Guide d’Édimbourg' ), implode( ' | ', headings( $built ) ) );
check( 'alongside that destination row',
	has_heading( $built, 'Destination Angleterre' ), implode( ' | ', headings( $built ) ) );

// --- 2b. Tagging is not naming -----------------------------------------------
//
// The guard against a broader rule creeping back. Reading London articles
// gives the session a city, so a "Destination Londres" row exists, and the
// London hub is tagged with exactly that city — but it is called "Londres en
// famille", which is a different promise from everything tagged Londres. Only
// the words decide.

$in_london = [ view( 201, 120, 80, $t ), view( 202, 90, 70, $t - 300 ) ];
$london    = MFY_Rows::build( 'fr', $in_london, [], null );

check( 'a destination row exists for the city',
	has_heading( $london, 'Destination Londres' ), implode( ' | ', headings( $london ) ) );
check( 'and the hub tagged with that very city keeps its row beside it',
	has_heading( $london, 'Londres en famille' ), implode( ' | ', headings( $london ) ) );
check( 'the hub really is tagged with the row’s place',
	11 === ( MFY_Geo::places_for_posts( [ 110 ], 'fr' )[110]['city'] ?? 0 ) );

// --- 3. A thematic hub is never a destination -------------------------------

$themed = MFY_Rows::build( 'fr', [ view( 501, 120, 80, $t ), view( 502, 90, 70, $t - 300 ) ], [], null );

check( 'a thematic hub survives a geography row it sits inside',
	has_heading( $themed, 'City trips en famille' ), implode( ' | ', headings( $themed ) ) );

// --- 4. Suppression needs a surviving geography row -------------------------
//
// With no place data at all there is no destination row, so the England hub is
// the only thing naming England and must keep its row.

$saved_places      = $GLOBALS['MOCK_PLACES'];
$saved_post_places = $GLOBALS['MOCK_POST_PLACES'];
$GLOBALS['MOCK_PLACES'] = [];
$GLOBALS['MOCK_POST_PLACES'] = [];
mock_bust();

$ungeotagged = MFY_Rows::build( 'fr', $in_england, [], null );

check( 'with no geography at all, the hub row is kept',
	has_heading( $ungeotagged, 'Dans « Angleterre »' ), implode( ' | ', headings( $ungeotagged ) ) );
check( 'and there is no destination row to duplicate',
	! in_array( 'geo', kinds_of( $ungeotagged ), true ), implode( ', ', kinds_of( $ungeotagged ) ) );

$GLOBALS['MOCK_PLACES']      = $saved_places;
$GLOBALS['MOCK_POST_PLACES'] = $saved_post_places;
mock_bust();

// --- 5. Names are matched whole, and the hub's own tags are irrelevant -------

// Geo-tagging the hub or not changes nothing: only the title is read.
$GLOBALS['MOCK_POST_PLACES'][100] = [];
mock_bust();

$untagged = MFY_Rows::build( 'fr', $in_england, [], null );

check( 'an untagged hub of the same name is still suppressed',
	! has_heading( $untagged, 'Dans « Angleterre »' ), implode( ' | ', headings( $untagged ) ) );

// A narrower title is a different promise and keeps its row.
$GLOBALS['MOCK_POSTS'][100]['title'] = 'Angleterre en famille';
mock_bust();

$narrower = MFY_Rows::build( 'fr', $in_england, [], null );

check( 'a hub whose title merely contains the place keeps its row',
	has_heading( $narrower, 'Angleterre en famille' ), implode( ' | ', headings( $narrower ) ) );

// Case and accents are folded, so the collision is caught however it is typed.
$GLOBALS['MOCK_POSTS'][100]['title'] = 'ANGLETERRE';
mock_bust();

check( 'the match folds case',
	! has_heading( MFY_Rows::build( 'fr', $in_england, [], null ), 'Dans « ANGLETERRE »' ) );

$GLOBALS['MOCK_POSTS'][100]['title'] = 'Angleterre';
mock_bust();

echo "\n";
printf( "%d failure(s)\n", $GLOBALS['FAILED'] );

echo "\n--- reading two England articles ---\n";
foreach ( headings( MFY_Rows::build( 'fr', $in_england, [], null ) ) as $line ) {
	printf( "  %s\n", $line );
}

exit( $GLOBALS['FAILED'] ? 1 : 0 );
