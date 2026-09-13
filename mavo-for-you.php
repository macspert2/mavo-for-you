<?php
/**
 * Plugin Name: Mavo For You
 * Plugin URI:  https://mamanvoyage.com
 * Description: Session-only, privacy-conscious content personalization. Adds a "Pour vous" block after the content of eligible posts, loaded after page load so full-page caching stays intact.
 * Version:     0.7.3
 * Author:      Mavo
 * Text Domain: mavo-for-you
 * Requires at least: 6.3
 * Requires PHP: 8.0
 */

defined( 'ABSPATH' ) || exit;

define( 'MFY_VERSION',    '0.7.3' );
define( 'MFY_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'MFY_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'MFY_PLUGIN_FILE', __FILE__ );

require_once MFY_PLUGIN_DIR . 'includes/mavo-for-you-config.php';
require_once MFY_PLUGIN_DIR . 'includes/class-mavo-for-you-cache.php';
require_once MFY_PLUGIN_DIR . 'includes/class-mavo-for-you-data.php';
require_once MFY_PLUGIN_DIR . 'includes/class-mavo-for-you-geo.php';
require_once MFY_PLUGIN_DIR . 'includes/class-mavo-for-you-hubs.php';
require_once MFY_PLUGIN_DIR . 'includes/class-mavo-for-you-scorer.php';
require_once MFY_PLUGIN_DIR . 'includes/class-mavo-for-you-rest.php';
require_once MFY_PLUGIN_DIR . 'includes/class-mavo-for-you-shortcode.php';
require_once MFY_PLUGIN_DIR . 'includes/class-mavo-for-you-render.php';
require_once MFY_PLUGIN_DIR . 'includes/class-mavo-for-you-admin.php';
require_once MFY_PLUGIN_DIR . 'includes/class-mavo-for-you-tuner.php';

add_action( 'plugins_loaded', static function () {
	load_plugin_textdomain( 'mavo-for-you', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );

	MFY_Cache::init();
	MFY_Rest::init();
	MFY_Render::init();
	MFY_Admin::init();

	// Administrator-only, and only ever loaded in wp-admin.
	if ( is_admin() ) {
		MFY_Tuner::init();
	}

	// On init, not plugins_loaded: if an older mavo-geotag-plus is still
	// active during a deploy it registers [geo_related] on plugins_loaded, and
	// the later registration is the one that wins.
	add_action( 'init', [ 'MFY_Shortcode', 'init' ] );
} );
