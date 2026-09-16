<?php
/**
 * The line of text under a tile's title.
 *
 * Posts have excerpts. Pages do not — and a hub page is the one page type that
 * reaches a tile. The rule is that a page shows its SEO meta description
 * instead, and the traps are all in the edges: a template Yoast never expanded,
 * a meta key from a plugin that is not installed, an excerpt WordPress
 * invented by trimming a wall of shortcodes.
 */
require_once __DIR__ . '/harness.php';

// --- Fixture ----------------------------------------------------------------

mock_post( 10, 'Un article', 'fr', [ 'citytrip' => 2 ] );
mock_excerpt( 10, 'L’extrait de l’article.' );

mock_post( 20, 'Le guide de Londres', 'fr', [], 'publish', 'page' );
mock_meta( 20, '_yoast_wpseo_metadesc', 'Tout pour visiter Londres en famille : quartiers, musées et bonnes adresses.' );

mock_post( 21, 'Guide Rank Math', 'fr', [], 'publish', 'page' );
mock_meta( 21, 'rank_math_description', 'La description de Rank Math.' );

mock_post( 22, 'Guide SEOPress', 'fr', [], 'publish', 'page' );
mock_meta( 22, '_seopress_titles_desc', 'La description de SEOPress.' );

mock_post( 23, 'Guide sans description', 'fr', [], 'publish', 'page' );

mock_post( 24, 'Guide avec gabarit', 'fr', [], 'publish', 'page' );
mock_meta( 24, '_yoast_wpseo_metadesc', '%%excerpt%% — %%sitename%%' );

mock_post( 25, 'Guide avec balisage', 'fr', [], 'publish', 'page' );
mock_meta( 25, '_yoast_wpseo_metadesc', "  <strong>Londres</strong> &amp; l&rsquo;Angleterre,\n  en famille.  " );

mock_post( 26, 'Guide avec extrait', 'fr', [], 'publish', 'page' );
mock_excerpt( 26, 'Un extrait écrit à la main.' );

mock_post( 30, 'Article sans extrait', 'fr', [ 'citytrip' => 2 ] );
mock_excerpt( 30, '' );
mock_meta( 30, '_yoast_wpseo_metadesc', 'La description de secours.' );

function text( int $post_id ): string {
	return MFY_Data::format_item( $post_id )['excerpt'] ?? '';
}

// --- 1. Each kind of post gets the right sentence ---------------------------

check( 'a post shows its excerpt', 'L’extrait de l’article.' === text( 10 ), text( 10 ) );
check( 'a page shows its meta description',
	'Tout pour visiter Londres en famille : quartiers, musées et bonnes adresses.' === text( 20 ), text( 20 ) );

// --- 2. Whichever SEO plugin is installed -----------------------------------

check( 'Rank Math’s key is read too', 'La description de Rank Math.' === text( 21 ), text( 21 ) );
check( 'and SEOPress’s', 'La description de SEOPress.' === text( 22 ), text( 22 ) );

// --- 3. Nothing is invented -------------------------------------------------

check( 'a page with no description shows no line at all', '' === text( 23 ), text( 23 ) );
check( 'and never a WordPress-trimmed wall of shortcodes',
	! str_contains( text( 23 ), 'Excerpt' ), text( 23 ) );

// --- 4. A template is not a sentence ----------------------------------------

check( 'an unexpanded Yoast template is discarded, not shown', '' === text( 24 ), text( 24 ) );
check( 'so no %%variable%% ever reaches a reader', ! str_contains( text( 24 ), '%%' ), text( 24 ) );

// --- 5. Tile text is plain text ---------------------------------------------

check( 'markup, entities and newlines are cleaned out',
	'Londres & l’Angleterre, en famille.' === text( 25 ), text( 25 ) );

// --- 6. Fallbacks in both directions ----------------------------------------

check( 'a page with a hand-written excerpt and no description uses the excerpt',
	'Un extrait écrit à la main.' === text( 26 ), text( 26 ) );
check( 'a post with no excerpt falls back to its meta description',
	'La description de secours.' === text( 30 ), text( 30 ) );

// --- 7. Compact items still carry no text -----------------------------------

check( 'the recently-viewed shape has no description at all',
	! isset( MFY_Data::format_item( 20, false )['excerpt'] ) );

// --- 8. The site can point this anywhere ------------------------------------

check( 'the accessor is public, so other Mavo code can reuse it',
	'La description de Rank Math.' === MFY_Data::meta_description( get_post( 21 ) ) );

echo "\n";
printf( "%d failure(s)\n", $GLOBALS['FAILED'] );

echo "\n--- what each tile says under its title ---\n";
foreach ( [ 10, 20, 21, 22, 23, 24, 25, 26, 30 ] as $id ) {
	printf( "  %-24s %-5s %s\n", get_the_title( $id ), get_post( $id )->post_type, text( $id ) ?: '—' );
}

exit( $GLOBALS['FAILED'] ? 1 : 0 );
