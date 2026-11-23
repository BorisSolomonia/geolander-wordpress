<?php
/**
 * Plugin Name: Geolander Core
 * Description: Fleet, places, testimonials, seasonal pricing, booking (WhatsApp / BOG iPay), and structured data for Geolander car rental.
 * Version: 1.21.0
 * Author: Geolander
 * Text Domain: geolander
 * Requires at least: 6.5
 * Requires PHP: 8.1
 */

defined( 'ABSPATH' ) || exit;

define( 'GLC_VERSION', '1.21.0' );
define( 'GLC_DIR', plugin_dir_path( __FILE__ ) );
define( 'GLC_URL', plugin_dir_url( __FILE__ ) );

require_once GLC_DIR . 'includes/class-glc-cpt.php';
require_once GLC_DIR . 'includes/class-glc-meta-boxes.php';
require_once GLC_DIR . 'includes/class-glc-pricing.php';
require_once GLC_DIR . 'includes/class-glc-rental.php';
require_once GLC_DIR . 'includes/class-glc-access.php';
require_once GLC_DIR . 'includes/class-glc-web-bot-auth.php';
require_once GLC_DIR . 'includes/class-glc-booking.php';
require_once GLC_DIR . 'includes/class-glc-mcp.php';
require_once GLC_DIR . 'includes/class-glc-assist.php';
require_once GLC_DIR . 'includes/class-glc-review.php';
require_once GLC_DIR . 'includes/class-glc-a2a.php';
require_once GLC_DIR . 'includes/class-glc-gateways.php';
require_once GLC_DIR . 'includes/class-glc-booking-email.php';
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
require_once GLC_DIR . 'includes/class-glc-contact.php';
require_once GLC_DIR . 'includes/class-glc-landings.php';
require_once GLC_DIR . 'includes/class-glc-redirects.php';
require_once GLC_DIR . 'includes/class-glc-indexnow.php';
require_once GLC_DIR . 'includes/class-glc-vehicle-evidence.php';
require_once GLC_DIR . 'includes/class-glc-trip-tools.php';
require_once GLC_DIR . 'includes/class-glc-trip-planner.php';
require_once GLC_DIR . 'includes/class-glc-news.php';
require_once GLC_DIR . 'includes/class-glc-admin-strings.php';
require_once GLC_DIR . 'includes/class-glc-partners.php';
require_once GLC_DIR . 'includes/class-glc-change-log.php';
require_once GLC_DIR . 'includes/class-glc-price-store.php';
require_once GLC_DIR . 'includes/class-glc-quote-check.php';
require_once GLC_DIR . 'includes/class-glc-partner-panel.php';
require_once GLC_DIR . 'includes/class-glc-partner-car.php';
require_once GLC_DIR . 'includes/class-glc-partner-admin.php';
require_once GLC_DIR . 'includes/class-glc-xlsx.php';
require_once GLC_DIR . 'includes/class-glc-partner-batch.php';
require_once GLC_DIR . 'includes/class-glc-partner-bulk.php';
require_once GLC_DIR . 'includes/class-glc-partner-excel.php';
require_once GLC_DIR . 'includes/class-glc-partner-media.php';
require_once GLC_DIR . 'includes/class-glc-roles.php';
require_once GLC_DIR . 'includes/class-glc-images.php';
require_once GLC_DIR . 'includes/class-glc-speed.php';
require_once GLC_DIR . 'includes/class-glc-security.php';

add_action( 'plugins_loaded', function () {
	GLC_I18n::boot();
}, 1 );

add_action( 'plugins_loaded', function () {
	GLC_CPT::init();
	GLC_Meta_Boxes::init();
	GLC_Rental::init();
	GLC_Web_Bot_Auth::init();
	GLC_Booking::init();
	GLC_MCP::init();
	GLC_Assist::init();
	GLC_Review::init();
	GLC_A2A::init();
	GLC_Booking_Email::init();
	GLC_Schema::init();
	GLC_Settings::init();
	GLC_Blocks::init();
	GLC_SEO::init();
	GLC_AI::init();
	GLC_Perf::init();
	GLC_Content::init();
	GLC_City::init();
	GLC_Contact::init();
	GLC_Landings::init();
	GLC_Redirects::init();
	GLC_IndexNow::init();
	GLC_Vehicle_Evidence::init();
	GLC_Trip_Tools::init();
	GLC_Trip_Planner::init();
	GLC_News::init();
	GLC_Partners::init();
	GLC_Change_Log::init();
	GLC_Price_Store::init();
	GLC_Partner_Panel::init();
	GLC_Partner_Car::init();
	GLC_Partner_Admin::init();
	GLC_Partner_Bulk::init();
	GLC_Partner_Excel::init();
	GLC_Partner_Media::init();
	GLC_Roles::init();
	GLC_Images::init();
	GLC_Speed::init();
	GLC_Security::init();
} );

register_activation_hook( __FILE__, function () {
	GLC_CPT::register_all();
	GLC_City::register();
	GLC_City::rewrites();
	GLC_AI::rewrites();
	GLC_News::register();
	flush_rewrite_rules();
} );

register_deactivation_hook( __FILE__, 'flush_rewrite_rules' );
