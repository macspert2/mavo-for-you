<?php
/**
 * Plugin Name: Mavo For You
 * Plugin URI:  https://mamanvoyage.com
 * Description: Session-only, privacy-conscious content personalization. Adds a "Pour vous" block after the content of eligible posts, loaded after page load so full-page caching stays intact.
 * Version:     0.2.0
 * Author:      Mavo
 * Text Domain: mavo-for-you
 * Requires at least: 6.3
 * Requires PHP: 8.0
 */

defined( 'ABSPATH' ) || exit;

define( 'MFY_VERSION',    '0.2.0' );
define( 'MFY_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'MFY_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'MFY_PLUGIN_FILE', __FILE__ );

require_once MFY_PLUGIN_DIR . 'includes/mavo-for-you-config.php';
require_once MFY_PLUGIN_DIR . 'includes/class-mavo-for-you-data.php';
require_once MFY_PLUGIN_DIR . 'includes/class-mavo-for-you-geo.php';
require_once MFY_PLUGIN_DIR . 'includes/class-mavo-for-you-scorer.php';
require_once MFY_PLUGIN_DIR . 'includes/class-mavo-for-you-rest.php';
require_once MFY_PLUGIN_DIR . 'includes/class-mavo-for-you-render.php';
require_once MFY_PLUGIN_DIR . 'includes/class-mavo-for-you-admin.php';

add_action( 'plugins_loaded', static function () {
	load_plugin_textdomain( 'mavo-for-you', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );

	MFY_Rest::init();
	MFY_Render::init();
	MFY_Admin::init();
} );

/**
 * Data attribute other Mavo plugins/templates can drop on internal links so a
 * future "already read" pass can match them against the local history.
 *
 * Returns a leading-space attribute string ready to concatenate inside a tag,
 * or '' for an invalid ID.
 */
function mavo_for_you_link_data_attr( int $post_id ): string {
	$post_id = absint( $post_id );

	return $post_id ? ' data-mavo-post-id="' . esc_attr( (string) $post_id ) . '"' : '';
}
