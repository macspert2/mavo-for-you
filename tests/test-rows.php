<?php
/**
 * The /pour-vous/ page: one row per reason, instead of one ranking.
 *
 * What is being tested here is mostly what the page refuses to do — show a row
 * of three, show two headings over one set of articles, cross a language, or
 * pretend a cold visit is personalized — because those are the failures that
 * would make the page look broken rather than empty.
 */
require_once __DIR__ . '/harness.php';
require_once __DIR__ . '/../includes/class-mavo-for-you-rows.php';

$t = time();

// --- Fixture ---------------------------------------------------------------

// A hub and its five children, all city trips in London.
mock_post( 200, 'Londres en famille : le guide', 'fr', [], 'publish', 'page' );
mock_hub( 200, 'geo' );

mock_place( 11, 'city', 'Londres', 22 );
mock_place( 22, 'country', 'Angleterre' );

foreach ( [ 201, 202, 203, 204, 205 ] as $i => $id ) {
	mock_post( $id, 'Enfant ' . ( $i + 1 ), 'fr', [ 'citytrip' => 2 ] );
	mock_primary_hub( $id, 200, 'geo' );
	mock_geo( $id, [ 'city' => 11, 'country' => 22 ] );
}

// Three more London articles that the guide does not own: without them the
// city row would be exactly the hub row, and the page is right to drop it.
foreach ( [ 701, 702, 703 ] as $i => $id ) {
	mock_post( $id, 'Londres autre ' . ( $i + 1 ), 'fr', [ 'gastronomie' => 2 ] );
	mock_geo( $id, [ 'city' => 11, 'country' => 22 ] );
}

// Six city trips with teenagers, elsewhere: enough for a filter row each.
foreach ( [ 301, 302, 303, 304, 305, 306 ] as $i => $id ) {
	mock_post( $id, 'Ville ados ' . ( $i + 1 ), 'fr', [ 'citytrip' => 2, 'ados' => 2 ] );
}

// Hiking: only three, which is one short of a row.
foreach ( [ 401, 402, 403 ] as $i => $id ) {
	mock_post( $id, 'Rando ' . ( $i + 1 ), 'fr', [ 'nature_rando' => 2 ] );
}

// Beaches: four, and the search dictionary maps "plage" to them.
foreach ( [ 501, 502, 503, 504 ] as $i => $id ) {
	mock_post( $id, 'Plage ' . ( $i + 1 ), 'fr', [ 'plage_cote' => 2 ] );
}

// An English city trip, which must never reach a French row.
mock_post( 601, 'London with teens', 'en', [ 'citytrip' => 2, 'ados' => 2 ] );

// What the visitor has read: two children of the London guide.
$views    = [ view( 201, 120, 80, $t ), view( 202, 90, 70, $t - 300 ) ];
$searches = [ [ 'query' => 'plage espagne', 'source' => 'site', 'timestamp' => $t - 100 ] ];

$built = MFY_Rows::build( 'fr', $views, $searches, null );
$rows  = $built['rows'];
$kinds = array_column( $rows, 'kind' );

function row_of( array $rows, string $kind ): ?array {
	foreach ( $rows as $row ) {
		if ( $row['kind'] === $kind ) { return $row; }
	}
	return null;
}

function row_ids( ?array $row ): array {
	return $row ? array_column( $row['items'], 'post_id' ) : [];
}

function all_ids( array $rows ): array {
	$out = [];
	foreach ( $rows as $row ) { $out = array_merge( $out, array_column( $row['items'], 'post_id' ) ); }
	return $out;
}

// --- 1. The page is built from the session, not from a ranking --------------

check( 'a session with history produces a personalized page', $built['personalized'] );
check( 'several rows are built', count( $rows ) >= 3, implode( ', ', $kinds ) );
check( 'a hub row is built from the guide being read', null !== row_of( $rows, 'hub' ), implode( ', ', $kinds ) );
check( 'a geography row is built', null !== row_of( $rows, 'geo' ), implode( ', ', $kinds ) );
check( 'a search row is built from the query', null !== row_of( $rows, 'search' ), implode( ', ', $kinds ) );

$geo_row = row_of( $rows, 'geo' );
check( 'the geography row is headed by the place name',
	$geo_row && str_contains( $geo_row['title'], 'Londres' ),
	$geo_row['title'] ?? '—' );
check( 'the country row is dropped, being the same articles as the city row',
	1 === count( array_filter( $rows, fn( $row ) => 'geo' === $row['kind'] ) ),
	implode( ', ', $kinds ) );

$search_row = row_of( $rows, 'search' );
check( 'the search row is headed by the query itself',
	$search_row && str_contains( $search_row['title'], 'plage espagne' ),
	$search_row['title'] ?? '—' );
check( 'and filled by what that query maps to',
	array_diff( row_ids( $search_row ), [ 501, 502, 503, 504 ] ) === [],
	implode( ',', row_ids( $search_row ) ) );

$hub_row = row_of( $rows, 'hub' );
check( 'the hub row is headed by the hub title',
	$hub_row && str_contains( $hub_row['title'], 'Londres en famille' ),
	$hub_row['title'] ?? '—' );

// --- 2. Row order is fixed, and search leads --------------------------------

$suggestion_kinds = array_values( array_filter( $kinds, fn( $k ) => 'recent' !== $k ) );
$expected_order   = array_values( array_filter( MFY_Rows::KIND_ORDER, fn( $k ) => in_array( $k, $suggestion_kinds, true ) ) );
$seen_order       = array_values( array_unique( $suggestion_kinds ) );

check( 'rows appear in the documented kind order', $seen_order === $expected_order, implode( ' → ', $seen_order ) );

// --- 3. A row too thin to rotate is not shown -------------------------------

check( 'a three-article theme never becomes a row',
	! array_intersect( [ 401, 402, 403 ], all_ids( $rows ) ),
	implode( ',', all_ids( $rows ) ) );

foreach ( $rows as $row ) {
	if ( 'recent' === $row['kind'] ) { continue; }
	check( sprintf( 'row "%s" has at least %d tiles', $row['title'], MFY_Config::page_row_min() ),
		count( $row['items'] ) >= MFY_Config::page_row_min(),
		(string) count( $row['items'] ) );
}

// --- 4. Already-read articles stay, unlike in the block ---------------------

check( 'an article already read this visit may still appear in a row',
	(bool) array_intersect( [ 201, 202 ], all_ids( $rows ) ),
	implode( ',', all_ids( $rows ) ) );

check( 'every tile carries its post ID, so the read-marking pass can find it',
	! array_filter( $rows, fn( $row ) => (bool) array_filter( $row['items'], fn( $i ) => empty( $i['post_id'] ) || empty( $i['url'] ) ) ) );

// --- 5. Repetition between rows is fine; a duplicate row is not -------------

$appearances = array_count_values( all_ids( $rows ) );
check( 'an article may appear under more than one heading', max( $appearances ) > 1, json_encode( array_filter( $appearances, fn( $n ) => $n > 1 ) ) );

foreach ( $rows as $row ) {
	$ids = array_column( $row['items'], 'post_id' );
	check( sprintf( 'row "%s" shows each article once', $row['title'] ), $ids === array_unique( $ids ) );
}

$signatures = array_map( fn( $row ) => implode( ',', array_column( $row['items'], 'post_id' ) ), $rows );
check( 'no two rows are the same set of articles', $signatures === array_unique( $signatures ), implode( ' | ', $signatures ) );

// --- 6. Language is never crossed -------------------------------------------

check( 'an English article never reaches a French row', ! in_array( 601, all_ids( $rows ), true ), implode( ',', all_ids( $rows ) ) );

// --- 6b. …and neither are the headings --------------------------------------
//
// Travel Finder resolves its labels per language and defaults to French, so a
// caller that simply forgets the argument gets French headings on a German
// page and no error at all. That is exactly what shipped once.

foreach ( [ 601, 602, 603, 604, 605, 606 ] as $i => $id ) {
	mock_post( $id, 'City break ' . ( $i + 1 ), 'en', [ 'citytrip' => 2 ] );
}
foreach ( [ 611, 612, 613, 614, 615, 616 ] as $i => $id ) {
	mock_post( $id, 'Städtereise ' . ( $i + 1 ), 'de', [ 'citytrip' => 2 ] );
}

function heading_for( string $lang, int $a, int $b ): string {
	$built = MFY_Rows::build( $lang, [ view( $a, 120, 80, time() ), view( $b, 90, 70, time() - 300 ) ], [], null );

	foreach ( $built['rows'] as $row ) {
		if ( 'filter' === $row['kind'] ) { return $row['title']; }
	}

	return '';
}

$en_heading = heading_for( 'en', 601, 602 );
$de_heading = heading_for( 'de', 611, 612 );

check( 'an English filter row uses the English label',
	str_contains( $en_heading, 'City break' ), $en_heading );
check( 'and not the French one', ! str_contains( $en_heading, 'Séjour en ville' ), $en_heading );
check( 'a German filter row uses the German label',
	str_contains( $de_heading, 'Städtereise' ), $de_heading );
check( 'and the row template is the language\'s own',
	str_contains( $de_heading, 'Mehr Artikel' ), $de_heading );

// A filter tvf has not translated yet must fall back to French rather than
// vanish — a row with no heading is worse than a row with a French one.
check( 'an untranslated filter falls back to French rather than disappearing',
	'Enfants' === ( MFY_Data::signal_labels( 'de' )['kids'] ?? '' ),
	json_encode( MFY_Data::signal_labels( 'de' )['kids'] ?? null ) );

// --- 7. Recently viewed: last, and exempt from the minimum ------------------

$recent = row_of( $rows, 'recent' );

check( 'the recently-viewed row exists', null !== $recent );
check( 'it is the last row on the page', 'recent' === end( $kinds ), implode( ', ', $kinds ) );
check( 'it shows the two articles read, newest first', row_ids( $recent ) === [ 201, 202 ], implode( ',', row_ids( $recent ) ) );
check( 'it is allowed to be shorter than a suggestion row', count( $recent['items'] ) < MFY_Config::page_row_min() );

// --- 8. What the block asks before offering a link --------------------------

$available = MFY_Rows::count_available( 'fr', $views, $searches, null );

check( 'count_available counts the suggestion rows', $available === count( $suggestion_kinds ), sprintf( '%d vs %d', $available, count( $suggestion_kinds ) ) );
check( 'and does not count the reader\'s own history', $available < count( $rows ), sprintf( '%d of %d', $available, count( $rows ) ) );
check( 'a session with no history offers no page at all', 0 === MFY_Rows::count_available( 'fr', [], [], null ) );

// --- 9. A cold visit gets a page, and is told what it is --------------------

$cold = MFY_Rows::build( 'fr', [], [], null );

check( 'a visitor with no history still gets rows', ! empty( $cold['rows'] ), (string) count( $cold['rows'] ) );
check( 'and the page does not claim to be personalized', ! $cold['personalized'] );
check( 'the fallback rows are built from the catalogue',
	! array_filter( $cold['rows'], fn( $row ) => 'fallback' !== $row['kind'] ),
	implode( ', ', array_column( $cold['rows'], 'kind' ) ) );
check( 'and they honour the same four-tile minimum',
	! array_filter( $cold['rows'], fn( $row ) => count( $row['items'] ) < MFY_Config::page_row_min() ) );

// --- 10. A session whose rows are all too thin falls back rather than empties

$thin = MFY_Rows::build( 'fr', [ view( 401, 120, 80, $t ) ], [], null );

check( 'a session that can fill no row falls back instead of showing nothing', ! empty( $thin['rows'] ) );
check( 'and says so', ! $thin['personalized'] );

echo "\n";
printf( "%d failure(s)\n", $GLOBALS['FAILED'] );

echo "\n--- two articles into the London guide, after searching \"plage espagne\" ---\n";
foreach ( $rows as $row ) {
	printf( "  [%-6s] %-42s %2d tiles: %s\n",
		$row['kind'],
		$row['title'],
		count( $row['items'] ),
		implode( ', ', array_map( 'get_the_title', array_column( $row['items'], 'post_id' ) ) )
	);
}

exit( $GLOBALS['FAILED'] ? 1 : 0 );
