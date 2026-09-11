<?php
/**
 * [geo_related]: the impersonal block that ships inside the cached page, and
 * hands over to the personalised one.
 */
require __DIR__ . '/harness.php';
require __DIR__ . '/../includes/class-mavo-for-you-cache.php';
require __DIR__ . '/../includes/class-mavo-for-you-shortcode.php';

// A London guide with children, siblings, and an outsider.
mock_place( 10, 'city', 'Londres', 20 );
mock_place( 20, 'region', 'Grand Londres', 30 );
mock_place( 30, 'country', 'Angleterre' );
mock_place( 12, 'city', 'Barcelone', 22 );
mock_place( 22, 'region', 'Catalogne', 32 );
mock_place( 32, 'country', 'Espagne' );
$london    = [ 'city' => 10, 'region' => 20, 'country' => 30 ];
$barcelona = [ 'city' => 12, 'region' => 22, 'country' => 32 ];

mock_post( 50, 'Londres en famille : le guide', 'fr', [], 'publish', 'page', 'londres-en-famille' );
mock_hub( 50, 'geo' );
mock_geo( 50, $london );

mock_post( 1, 'Tower of London', 'fr', [ 'citytrip' => 2, 'angleterre' => 2 ] );
mock_geo( 1, $london );
mock_primary_hub( 1, 50, 'geo' );
mock_content( 1, "Du texte.\n\n[geo_related]\n\nEncore du texte." );

foreach ( [ 2, 3, 4, 5 ] as $id ) {
	mock_post( $id, "Londres $id", 'fr', [ 'citytrip' => 2, 'angleterre' => 2 ] );
	mock_geo( $id, $london );
	mock_primary_hub( $id, 50, 'geo' );
}
mock_post( 9, 'Barcelone', 'fr', [ 'citytrip' => 2 ] );
mock_geo( 9, $barcelona );

// A post with no shortcode in it.
mock_post( 60, 'Sans shortcode', 'fr', [ 'citytrip' => 2, 'angleterre' => 2 ] );
mock_geo( 60, $london );

$GLOBALS['MOCK_CURRENT_POST'] = 1;

// --- 1. The impersonal ranking ---------------------------------------------
$ranked = MFY_Scorer::rank_for_post( 1, 'fr', [ 'limit' => 6 ] );
$ids    = array_column( $ranked['recommendations'], 'post_id' );

check( 'an impersonal block is produced with no session at all', count( $ids ) > 0, implode( ',', $ids ) );
check( 'the hub leads it', ( $ids[0] ?? 0 ) === 50, implode( ',', $ids ) );
check( 'the hub is labelled', ( $ranked['recommendations'][0]['hub']['label'] ?? '' ) === 'Guide' );
check( 'siblings follow', (bool) array_intersect( $ids, [ 2, 3, 4, 5 ] ), implode( ',', $ids ) );
check( 'the current post is never in it', ! in_array( 1, $ids, true ) );
check( 'the limit is honoured', count( $ids ) <= 6, count( $ids ) . ' items' );
check( 'the synthetic view weighs exactly 1', ( $ranked['debug']['profile_views'][0]['weight'] ?? 0 ) === 1.0, json_encode( $ranked['debug']['profile_views'][0]['weight'] ?? null ) );

// --- 2. level forces the geography ------------------------------------------
$city    = MFY_Scorer::rank_for_post( 1, 'fr', [ 'limit' => 6, 'geo_levels' => [ 'city' ] ] );
$country = MFY_Scorer::rank_for_post( 1, 'fr', [ 'limit' => 6, 'geo_levels' => [ 'country' ] ] );
check( 'level="city" narrows the focus to the city', ( $city['debug']['composition']['focus_level'] ?? '' ) === 'city', json_encode( $city['debug']['composition'] ) );
check( 'level="country" narrows it to the country', ( $country['debug']['composition']['focus_level'] ?? '' ) === 'country', json_encode( $country['debug']['composition'] ) );
check( 'a narrowed profile knows about no other level', ( $city['debug']['geo']['levels'] ?? [] ) && ! isset( $city['debug']['geo']['levels']['country'] ), json_encode( array_keys( $city['debug']['geo']['levels'] ?? [] ) ) );

// --- 3. The rendered markup --------------------------------------------------
$html = MFY_Shortcode::render_shortcode( [] );

check( 'the shortcode renders the placeholder', str_contains( $html, 'id="mavo-for-you"' ) );
check( 'it carries the post id', str_contains( $html, 'data-current-post-id="1"' ) );
check( 'it carries the limit, for the swap to keep the size', str_contains( $html, 'data-limit="6"' ) );
check( 'it carries the level', str_contains( $html, 'data-level=""' ) );
check( 'the section uses the same classes as the personalised block', str_contains( $html, 'class="mfy mfy--impersonal is-visible"' ) );
check( 'it is headed impersonally', str_contains( $html, 'À lire aussi' ) );
check( 'it makes no claim about the visitor', ! str_contains( $html, 'Suggestions basées' ) );
check( 'cards use the theme tile markup', str_contains( $html, 'class="mv-tile mv-tile--media mfy__card' ) );
check( 'the hub card carries the eyebrow', str_contains( $html, 'mv-tile__eyebrow mfy__hub-label' ) && str_contains( $html, '>Guide<' ) );
check( 'links carry data-mavo-post-id', str_contains( $html, 'data-mavo-post-id="50"' ) );
check( 'links are plain and crawlable', ! str_contains( $html, 'nofollow' ) && ! str_contains( $html, 'target=' ) );
check( 'no recently-viewed section', ! str_contains( $html, 'mfy__recent' ) );
check( 'no reset control on an impersonal block', ! str_contains( $html, 'mfy__reset' ) );

// --- 4. Attributes ------------------------------------------------------------
$GLOBALS['MOCK_CURRENT_POST'] = 1;
( new ReflectionProperty( 'MFY_Shortcode', 'rendered' ) )->setValue( null, [] );
$limited = MFY_Shortcode::render_shortcode( [ 'limit' => '2', 'level' => 'country' ] );
check( 'limit is applied', substr_count( $limited, 'mv-tile__link' ) === 2, substr_count( $limited, 'mv-tile__link' ) . ' tiles' );
check( 'level is applied', str_contains( $limited, 'data-level="country"' ) );

( new ReflectionProperty( 'MFY_Shortcode', 'rendered' ) )->setValue( null, [] );
$hostile = MFY_Shortcode::render_shortcode( [ 'limit' => '9999', 'level' => 'planet', 'style' => 'cta', 'post_id' => '77' ] );
check( 'an absurd limit is clamped', str_contains( $hostile, 'data-limit="12"' ) );
check( 'an unknown level is ignored', str_contains( $hostile, 'data-level=""' ) );
check( 'the retired style and post_id attributes are harmless', str_contains( $hostile, 'id="mavo-for-you"' ) && str_contains( $hostile, 'data-current-post-id="1"' ) );

// --- 5. One block per post ----------------------------------------------------
( new ReflectionProperty( 'MFY_Shortcode', 'rendered' ) )->setValue( null, [] );
$first  = MFY_Shortcode::render_shortcode( [] );
$second = MFY_Shortcode::render_shortcode( [] );
check( 'a second shortcode in the same post renders nothing', '' !== $first && '' === $second );
check( 'the render hook is told to stand down', MFY_Shortcode::has_rendered( 1 ) );

// --- 6. Which posts place it themselves ---------------------------------------
check( 'a post containing the shortcode is detected', MFY_Shortcode::post_has_shortcode( 1 ) );
check( 'a post without it is not', ! MFY_Shortcode::post_has_shortcode( 60 ) );
mock_content( 60, '[geo_related_full]' );
check( 'the _full alias counts too', MFY_Shortcode::post_has_shortcode( 60 ) );
mock_content( 60, 'Nothing here about geo_related at all.' );
check( 'a bare mention in prose does not count', ! MFY_Shortcode::post_has_shortcode( 60 ) );

// --- 7. Caching ----------------------------------------------------------------
$GLOBALS['MOCK_TRANSIENTS'] = [];
( new ReflectionProperty( 'MFY_Shortcode', 'rendered' ) )->setValue( null, [] );
MFY_Shortcode::render_shortcode( [] );
check( 'the block is cached', count( $GLOBALS['MOCK_TRANSIENTS'] ) === 1, json_encode( array_keys( $GLOBALS['MOCK_TRANSIENTS'] ) ) );
$generation = MFY_Cache::generation();
MFY_Cache::bust();
check( 'a save bumps the generation', MFY_Cache::generation() === $generation + 1 );
$old_key = array_key_first( $GLOBALS['MOCK_TRANSIENTS'] );
$new_key = MFY_Cache::key( 'sc', [ 1, 'fr', '', 6 ] );
check( 'which orphans every key built before it', $new_key !== $old_key, "$old_key -> $new_key" );
check( 'the orphan is left to lapse on its own TTL, not swept', isset( $GLOBALS['MOCK_TRANSIENTS'][ $old_key ] ) );

// --- 8. A post with nothing to suggest still gets a placeholder ----------------
mock_post( 70, 'Isolé', 'fr', [], 'publish', 'post', 'isole' );
mock_content( 70, '[geo_related]' );
$GLOBALS['MOCK_CURRENT_POST'] = 70;
( new ReflectionProperty( 'MFY_Shortcode', 'rendered' ) )->setValue( null, [] );
$empty = MFY_Shortcode::render_shortcode( [] );
check( 'an empty block still leaves a placeholder for the swap', str_contains( $empty, 'id="mavo-for-you"' ) );
check( 'but renders no section', ! str_contains( $empty, '<section' ), $empty );

// --- 9. Utility pages are refused ----------------------------------------------
mock_post( 80, 'Contact', 'fr', [], 'publish', 'page', 'contact' );
mock_content( 80, '[geo_related]' );
$GLOBALS['MOCK_CURRENT_POST'] = 80;
( new ReflectionProperty( 'MFY_Shortcode', 'rendered' ) )->setValue( null, [] );
check( 'a contact page renders nothing at all', MFY_Shortcode::render_shortcode( [] ) === '' );

echo "\n";
printf( "%d failure(s)\n", $GLOBALS['FAILED'] );
exit( $GLOBALS['FAILED'] ? 1 : 0 );
