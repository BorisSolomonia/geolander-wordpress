<?php
/**
 * Plugin Name: Geolander Core
 * Description: Fleet, places, testimonials, seasonal pricing, booking (WhatsApp / BOG iPay), and structured data for Geolander car rental.
 * Version: 1.0.0
 * Author: Geolander
 * Text Domain: geolander
 * Requires at least: 6.5
 * Requires PHP: 8.1
 */

defined( 'ABSPATH' ) || exit;

define( 'GLC_VERSION', '1.0.0' );
define( 'GLC_DIR', plugin_dir_path( __FILE__ ) );
define( 'GLC_URL', plugin_dir_url( __FILE__ ) );

require_once GLC_DIR . 'includes/class-glc-cpt.php';
require_once GLC_DIR . 'includes/class-glc-meta-boxes.php';
require_once GLC_DIR . 'includes/class-glc-pricing.php';
require_once GLC_DIR . 'includes/class-glc-booking.php';
require_once GLC_DIR . 'includes/class-glc-gateways.php';
require_once GLC_DIR . 'includes/class-glc-schema.php';
require_once GLC_DIR . 'includes/class-glc-settings.php';
require_once GLC_DIR . 'includes/class-glc-blocks.php';
require_once GLC_DIR . 'includes/class-glc-seo.php';
require_once GLC_DIR . 'includes/class-glc-i18n.php';
require_once GLC_DIR . 'includes/class-glc-ai.php';
require_once GLC_DIR . 'includes/class-glc-perf.php';
require_once GLC_DIR . 'includes/class-glc-format.php';
require_once GLC_DIR . 'includes/class-glc-content.php';
require_once GLC_DIR . 'includes/class-glc-city.php';

add_action( 'plugins_loaded', function () {
	GLC_I18n::boot();
}, 1 );

add_action( 'plugins_loaded', function () {
	GLC_CPT::init();
	GLC_Meta_Boxes::init();
	GLC_Booking::init();
	GLC_Schema::init();
	GLC_Settings::init();
	GLC_Blocks::init();
	GLC_SEO::init();
	GLC_AI::init();
	GLC_Perf::init();
	GLC_Content::init();
	GLC_City::init();
} );

register_activation_hook( __FILE__, function () {
	GLC_CPT::register_all();
	GLC_City::register();
	GLC_City::rewrites();
	flush_rewrite_rules();
} );

register_deactivation_hook( __FILE__, 'flush_rewrite_rules' );
