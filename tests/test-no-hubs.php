<?php
/**
 * The same site without Hub Manager: every hub feature must switch off
 * silently and leave the ranking exactly as it was before hubs existed.
 */
define( 'MFY_TEST_WITHOUT_HUBS', true );
require __DIR__ . '/harness.php';

$t = time();

mock_post( 1, 'Londres 1',   'fr', [ 'citytrip' => 2, 'angleterre' => 2 ] );
mock_post( 2, 'Londres 2',   'fr', [ 'citytrip' => 2, 'angleterre' => 2 ] );
mock_post( 3, 'Kew Gardens', 'fr', [ 'citytrip' => 2, 'angleterre' => 2 ] );
mock_post( 4, 'Bath',        'fr', [ 'citytrip' => 2 ] );

// Marked as a hub with a child — data that would matter if the plugin were here.
mock_hub( 5, 'geo' );
mock_primary_hub( 1, 5, 'geo' );

$views = [ view( 1, 120, 80, $t ), view( 2, 100, 75, $t - 300 ) ];

check( 'hub helpers are genuinely absent', ! function_exists( 'mavo_get_hub_ancestors' ) );
check( 'MFY_Hubs reports itself unavailable', ! MFY_Hubs::available() );
check( 'hub_type() answers null rather than fataling', MFY_Hubs::hub_type( 5 ) === null );
check( 'is_hub() answers false rather than fataling', MFY_Hubs::is_hub( 5 ) === false );
check( 'session_context() returns nothing', MFY_Hubs::session_context( [ [ 'post_id' => 1, 'weight' => 1.0 ] ], 'fr' ) === [] );
check( 'child_candidates() returns nothing', MFY_Hubs::child_candidates( [ [ 'post_id' => 5, 'type' => 'geo', 'weight' => 1.0 ] ], 'fr', [] ) === [] );

$r   = MFY_Scorer::rank( 1, 'fr', $views, [], null );
$ids = array_column( $r['recommendations'], 'post_id' );

check( 'suggestions are still produced', count( $ids ) > 0, implode( ',', $ids ) );
check( 'no hub is placed', empty( $r['debug']['composition']['slots_hub'] ) );
check( 'no hub-children pool is built', ! isset( $r['debug']['pool_hub_children'] ) );
check( 'nothing is labelled as a hub', ! array_filter( $r['recommendations'], fn( $i ) => isset( $i['hub'] ) ) );
check( 'debug records hub support as unavailable', ( $r['debug']['hubs']['available'] ?? true ) === false, json_encode( $r['debug']['hubs'] ) );

echo "\n";
printf( "%d failure(s)\n", $GLOBALS['FAILED'] );
exit( $GLOBALS['FAILED'] ? 1 : 0 );
