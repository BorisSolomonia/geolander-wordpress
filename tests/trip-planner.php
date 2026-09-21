<?php
/** Standalone checks for monetary claims, publication guards and translation parity. */
define( 'ABSPATH', __DIR__ );
define( 'OBJECT', 'OBJECT' );
$fees = [ 'kutaisi_each_way' => 68, 'batumi_each_way' => 98 ];
$pages = [];
$locale = 'en';
$failures = 0;
function check( $condition, $label ) {
	if ( ! $condition ) { ++$GLOBALS['failures']; }
	echo ( $condition ? 'PASS ' : 'FAIL ' ) . $label . "\n";
}
function glc_ui( $key ) { return $GLOBALS['strings'][$key] ?? $key; }
function esc_html( $text ) { return htmlspecialchars( (string) $text, ENT_QUOTES ); }
function esc_attr( $text ) { return esc_html( $text ); }
function esc_url( $text ) { return esc_html( $text ); }
function home_url( $path ) { return 'https://example.test' . $path; }
function get_page_by_path( $slug, $output = OBJECT, $type = 'page' ) { return $GLOBALS['pages'][$slug] ?? null; }
function get_permalink( $page = null ) { return home_url( '/' . ( $page->post_name ?? GLC_Trip_Planner::SLUG ) . '/' ); }
class GLC_Settings { public static function get( $key ) { return $GLOBALS['fees'][$key] ?? null; } }
class GLC_Format { public static function money( $value ) { return '$' . $value; } }
class GLC_I18n { public static function locale() { return $GLOBALS['locale']; } }
require __DIR__ . '/../wp-content/plugins/geolander-core/includes/class-glc-rental.php';
require __DIR__ . '/../wp-content/plugins/geolander-core/includes/class-glc-trip-tools.php';
require __DIR__ . '/../wp-content/plugins/geolander-core/includes/class-glc-trip-planner.php';
$strings = require __DIR__ . '/../wp-content/themes/geolander/inc/strings-en.php';

check( GLC_Trip_Planner::delivery_total( 'kutaisi_airport', 'batumi_airport' ) === 166.0, 'Different airports add the correct one-way fees' );
check( GLC_Trip_Planner::delivery_total( 'kutaisi_airport', 'kutaisi_airport' ) === 136.0, 'Same airport charges both pickup and return' );
check( GLC_Trip_Planner::delivery_total( 'tbilisi_airport', 'tbilisi_airport' ) === 0.0, 'Confirmed free Tbilisi delivery remains free' );
$fees['kutaisi_each_way'] = 75.25;
check( GLC_Trip_Planner::delivery_total( 'kutaisi_airport', 'batumi_airport' ) === 173.25, 'Changed decimal booking fee immediately updates the planner' );
unset( $fees['kutaisi_each_way'] );
check( GLC_Trip_Planner::delivery_total( 'kutaisi_airport', 'tbilisi_airport' ) === null, 'Missing paid-airport fee is unknown, not free' );
check( GLC_Trip_Planner::delivery_total( 'unknown', 'batumi_airport' ) === null, 'Unknown airport cannot become free delivery' );
$fees['kutaisi_each_way'] = INF;
check( GLC_Trip_Planner::delivery_total( 'kutaisi_airport', 'batumi_airport' ) === null, 'Nonfinite fees cannot be advertised' );
$fees['kutaisi_each_way'] = -1;
check( GLC_Trip_Planner::delivery_total( 'kutaisi_airport', 'batumi_airport' ) === null, 'Invalid negative paid fee is not advertised as free' );
check( GLC_Trip_Planner::teaser() === '', 'No homepage link before the page is published' );
$pages[GLC_Trip_Planner::SLUG] = (object) [ 'post_name' => GLC_Trip_Planner::SLUG, 'post_status' => 'draft' ];
check( GLC_Trip_Planner::link() === '', 'Draft planner remains unadvertised' );
$pages[GLC_Trip_Planner::SLUG]->post_status = 'publish';
check( str_contains( GLC_Trip_Planner::teaser(), '/georgia-road-trip-planner/' ), 'Published planner is reachable from homepage' );
$pages['tusheti-4x4-rental-guide'] = (object) [ 'post_name' => 'tusheti-4x4-rental-guide', 'post_status' => 'draft' ];
check( ! str_contains( GLC_Trip_Planner::render(), '/tusheti-4x4-rental-guide/' ), 'Unpublished route guide is not linked' );
check( str_contains( GLC_Trip_Planner::render(), 'Confirm with us' ), 'Missing fee renders an honest uncertainty message' );
$fees['kutaisi_each_way'] = 68;
$english_keys = array_keys( array_filter( $strings, fn( $key ) => str_starts_with( $key, 'planner_' ), ARRAY_FILTER_USE_KEY ) );
foreach ( [ 'en', 'ka', 'ru', 'uk', 'ar', 'zh', 'fr' ] as $locale ) {
	$strings = require __DIR__ . '/../wp-content/themes/geolander/inc/strings-' . $locale . '.php';
	check( ! array_diff( $english_keys, array_keys( $strings ) ) && ! array_filter( array_intersect_key( $strings, array_flip( $english_keys ) ), fn( $v ) => ! is_string( $v ) || ! trim( $v ) ), 'Complete translated planner: ' . $locale );
	$html = GLC_Trip_Planner::render();
	$markdown = GLC_Trip_Planner::markdown();
	$schema = GLC_Trip_Planner::schema();
	check( substr_count( $html, 'data-trip-fee=' ) === 9 && str_contains( $html, '$166' ) && str_contains( $markdown, '$166' ), 'Nine airport combinations with matching HTML/Markdown prices: ' . $locale );
	check( count( $schema['hasPart'] ) === 5 && $schema['inLanguage'] === $locale && str_contains( $html, esc_html( $schema['hasPart'][0]['text'] ) ) && str_contains( $markdown, $schema['hasPart'][0]['text'] ), 'Visible advice, schema and Markdown agree: ' . $locale );
	check( ! str_contains( $html, '$0' ) && ! preg_match( '/planner_[a-z_]+/', $html ), 'No zero price or untranslated key: ' . $locale );
}
exit( $failures ? 1 : 0 );
