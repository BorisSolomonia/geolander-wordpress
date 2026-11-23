<?php
/** Standalone checks for monetary claims, publication guards and translation parity. */
define( 'ABSPATH', __DIR__ );
define( 'OBJECT', 'OBJECT' );
// Every paid place has a fee setting since 2026-10-11, Tbilisi Airport included.
$fees = [ 'tbilisi_airport_each_way' => 20, 'kutaisi_each_way' => 68, 'batumi_each_way' => 98 ];
$fleet = [];
$meta  = [];
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
// The published cars and each car's own fees (GLC_Price_Store reads them from car meta).
function get_posts( $args = [] ) { return $GLOBALS['fleet']; }
function get_post_meta( $id, $key = '', $single = false ) { return $GLOBALS['meta'][ $id ][ $key ] ?? ''; }
class GLC_Settings { public static function get( $key ) { return $GLOBALS['fees'][$key] ?? null; } }
class GLC_Format {
	public static function money( $value ) { return '$' . $value; }
	public static function money_exact( $value ) { return '$' . $value; }
}
class GLC_I18n { public static function locale() { return $GLOBALS['locale']; } }
require __DIR__ . '/../wp-content/plugins/geolander-core/includes/class-glc-rental.php';
require __DIR__ . '/../wp-content/plugins/geolander-core/includes/class-glc-price-store.php';
require __DIR__ . '/../wp-content/plugins/geolander-core/includes/class-glc-trip-tools.php';
require __DIR__ . '/../wp-content/plugins/geolander-core/includes/class-glc-trip-planner.php';
$strings = require __DIR__ . '/../wp-content/themes/geolander/inc/strings-en.php';

/** One exact delivery total, or null when the planner would not print one exact amount. */
function exact( string $pickup, string $return ): ?float {
	$delivery = GLC_Trip_Planner::delivery_total( $pickup, $return );
	return in_array( $delivery['state'], [ 'free', 'priced' ], true ) ? $delivery['fee'] : null;
}
/** The words of one cell of the nine-cell table, as a visitor reads them. */
function cell( string $pickup, string $return ): string {
	return preg_match( '/data-trip-fee="' . $pickup . ':' . $return . '">([^<]*)</', GLC_Trip_Planner::render(), $m ) ? html_entity_decode( $m[1], ENT_QUOTES ) : '(no cell)';
}
check( exact( 'kutaisi_airport', 'batumi_airport' ) === 166.0, 'Different airports add the correct one-way fees' );
check( exact( 'kutaisi_airport', 'kutaisi_airport' ) === 136.0, 'Same airport charges both pickup and return' );
check( exact( 'tbilisi_airport', 'tbilisi_airport' ) === 40.0 && '$40' === cell( 'tbilisi_airport', 'tbilisi_airport' ), 'Tbilisi Airport is charged in both directions like the other airports' );
$fees['tbilisi_airport_each_way'] = 0;
check( exact( 'tbilisi_airport', 'tbilisi_airport' ) === 0.0 && 'Included' === cell( 'tbilisi_airport', 'tbilisi_airport' ) && exact( 'tbilisi_airport', 'batumi_airport' ) === 98.0, 'A fee the owner sets to 0 makes that airport free again, said in words' );
$fees['tbilisi_airport_each_way'] = 20;
$fees['kutaisi_each_way'] = 75.25;
check( exact( 'kutaisi_airport', 'batumi_airport' ) === 173.25, 'Changed decimal booking fee immediately updates the planner' );
unset( $fees['kutaisi_each_way'] );
check( exact( 'kutaisi_airport', 'tbilisi_airport' ) === null && 'unknown' === GLC_Trip_Planner::delivery_total( 'kutaisi_airport', 'tbilisi_airport' )['state'], 'Missing paid-airport fee is unknown, not free' );
check( exact( 'unknown', 'batumi_airport' ) === null && exact( 'tbilisi_office', 'batumi_airport' ) === null, 'Unknown airport cannot become free delivery' );
$fees['kutaisi_each_way'] = INF;
check( exact( 'kutaisi_airport', 'batumi_airport' ) === null, 'Nonfinite fees cannot be advertised' );
$fees['kutaisi_each_way'] = -1;
check( exact( 'kutaisi_airport', 'batumi_airport' ) === null, 'Invalid negative paid fee is not advertised as free' );

/* A car with a fee of its own (GLC_Price_Store): the planner speaks for the whole fleet. */
$fees['kutaisi_each_way'] = 68;
$fleet = [ 4, 5 ];
check( exact( 'kutaisi_airport', 'batumi_airport' ) === 166.0 && '$166' === cell( 'kutaisi_airport', 'batumi_airport' ), 'Cars without a fee of their own change nothing' );
$meta[4]['glc_delivery_fees'] = [ 'kutaisi_airport' => '95' ];
$delivery = GLC_Trip_Planner::delivery_total( 'kutaisi_airport', 'batumi_airport' );
check( 'from' === $delivery['state'] && 166.0 === $delivery['fee'] && 'from $166' === cell( 'kutaisi_airport', 'batumi_airport' ), 'One car charges 95 for Kutaisi: the pair reads "from $166", the cheapest car' );
check( 'from $136' === cell( 'kutaisi_airport', 'kutaisi_airport' ) && '$196' === cell( 'batumi_airport', 'batumi_airport' ) && '$118' === cell( 'tbilisi_airport', 'batumi_airport' ), 'Only the cells with Kutaisi in them change' );
$meta[4]['glc_delivery_fees'] = [ 'kutaisi_airport' => '50', 'batumi_airport' => '100' ];
$meta[5]['glc_delivery_fees'] = [ 'kutaisi_airport' => '80', 'batumi_airport' => '60' ];
check( 'from $140' === cell( 'kutaisi_airport', 'batumi_airport' ), 'Two cars at 50+100 and 80+60: "from $140", a total one car really charges, not $110 from two different cars' );
$meta[5]['glc_delivery_fees'] = [ 'kutaisi_airport' => '0', 'batumi_airport' => '0' ];
check( 'Free or from $150' === cell( 'kutaisi_airport', 'batumi_airport' ) && ! str_contains( GLC_Trip_Planner::render(), '$0' ), 'One car free at both and one at 150: "Free or from $150", never "from $0"' );
$meta[4]['glc_delivery_fees'] = [ 'kutaisi_airport' => '0', 'batumi_airport' => '0' ];
check( 'Included' === cell( 'kutaisi_airport', 'batumi_airport' ), 'Every car free at both: the cell says so in words' );
unset( $fees['batumi_each_way'] );
$meta[5] = [];
check( 'Confirm with us' === cell( 'kutaisi_airport', 'batumi_airport' ) && 'Free or from $136' === cell( 'kutaisi_airport', 'kutaisi_airport' ), 'One car with no answer for a place: "Confirm with us", whatever the other car charges; a pair both cars can answer still gets its answer' );
$fees['batumi_each_way'] = 98;
$fleet = [];
$meta  = [];
$fees['kutaisi_each_way'] = -1;
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
