<?php
/**
 * Standalone checks for GLC_Price_Store, the one write path for a car's season
 * prices and fee overrides. No WordPress, no database: the few WordPress pieces
 * the store touches are held in memory below.
 *
 * Run: docker run --rm -v "<repo>:/src" php:8.3-cli php /src/tests/price-store.php
 */
define( 'ABSPATH', __DIR__ );
$failures     = 0;
$meta         = [];
$posts        = [];
$log          = [];
$pings        = [];
$uuid         = 0;
$current_user = 7;
$assigned     = [ 7 => [ 4, 5 ] ];
$settings     = [
	'rate_min_plausible' => '5',
	'rate_max_plausible' => '300',
	'fee_max_plausible'  => '500',
	'season_max_count'   => '24',
	'tbilisi_city_each_way' => '10', 'tbilisi_airport_each_way' => '20', 'kutaisi_each_way' => '80', 'batumi_each_way' => '100',
];
function check( $condition, $label ) {
	if ( ! $condition ) { ++$GLOBALS['failures']; }
	echo ( $condition ? 'PASS ' : 'FAIL ' ) . $label . "\n";
}
class WP_Error {
	public function __construct( public string $code = '', public string $message = '', public $data = [] ) {}
	public function get_error_code() { return $this->code; }
	public function get_error_message() { return $this->message; }
	public function get_error_data() { return $this->data; }
}
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function get_post( $id ) { return $GLOBALS['posts'][ $id ] ?? null; }
function get_post_status( $id ) { return $GLOBALS['posts'][ $id ]->post_status ?? false; }
function get_post_meta( $id, $key = '', $single = false ) { return $GLOBALS['meta'][ $id ][ $key ] ?? ''; }
function update_post_meta( $id, $key, $value ) { $GLOBALS['meta'][ $id ][ $key ] = $value; return true; }
function delete_post_meta( $id, $key ) { unset( $GLOBALS['meta'][ $id ][ $key ] ); return true; }
function wp_cache_delete( ...$args ) {}
function get_current_user_id() { return $GLOBALS['current_user']; }
function get_user_meta( $id, $key, $single = false ) { return ''; }
function get_user_locale( $id = 0 ) { return 'en_US'; }
function wp_generate_uuid4() { return sprintf( '00000000-0000-4000-8000-%012d', ++$GLOBALS['uuid'] ); }
function wp_json_encode( $value ) { return json_encode( $value ); }
function wp_slash( $value ) { return $value; }
function get_permalink( $id ) { return 'https://example.test/fleet/car-' . $id . '/'; }
function glc_ui( $key ) { return $key; }
class GLC_Settings { public static function get( $key, $default = null ) { return $GLOBALS['settings'][ $key ] ?? $default; } }
class GLC_Format {
	public static function money( $value, $locale = null ) { return '$' . $value; }
	public static function money_exact( $value, $locale = null ) { return '$' . $value; }
}
class GLC_Partners {
	public static function can_manage( int $user_id, int $car_id ): bool { return in_array( $car_id, $GLOBALS['assigned'][ $user_id ] ?? [], true ); }
}
class GLC_Change_Log {
	public const SOURCES = [ 'panel', 'bulk', 'excel', 'cli', 'metabox', 'restore' ];
	public static function record( array $entry ) { $GLOBALS['log'][] = $entry; return count( $GLOBALS['log'] ); }
	public static function latest( int $car_id, array $kinds = [] ): ?array {
		foreach ( array_reverse( $GLOBALS['log'] ) as $entry ) {
			if ( $entry['car_id'] === $car_id ) { return $entry + [ 'actor_name' => 'Nino Partner', 'time' => '2026-10-12 14:03' ]; }
		}
		return null;
	}
}
class GLC_IndexNow {
	public static function variants( string $url ): array { return array_map( fn( $l ) => $url . '?' . $l, [ 'en', 'ka', 'ru', 'uk', 'ar', 'zh', 'fr' ] ); }
	public static function ping( array $urls, string $reason = 'manual' ): array { $GLOBALS['pings'][] = [ 'urls' => $urls, 'reason' => $reason ]; return [ 'status' => 200, 'count' => count( $urls ) ]; }
}
$wpdb = new class {
	public $posts = 'wp_posts';
	public $last_error = '';
	public function prepare( $query, ...$args ) { return vsprintf( str_replace( '%d', '%s', $query ), $args ); }
	public function query( $query ) { return true; }
	public function get_var( $query ) { return 1; }
};
require __DIR__ . '/../wp-content/plugins/geolander-core/includes/class-glc-pricing.php';
require __DIR__ . '/../wp-content/plugins/geolander-core/includes/class-glc-rental.php';
require __DIR__ . '/../wp-content/plugins/geolander-core/includes/class-glc-admin-strings.php';
require __DIR__ . '/../wp-content/plugins/geolander-core/includes/class-glc-price-store.php';

/** The live shape: nine seasons that cover the year once, whole-dollar rates. */
function table(): array {
	$seasons = [];
	foreach ( [ [ '07-03', '08-02', 54 ], [ '11-01', '12-24', 30 ], [ '12-25', '01-05', 40 ], [ '01-06', '03-31', 28 ], [ '04-01', '05-05', 34 ], [ '05-06', '06-12', 38 ], [ '06-13', '07-02', 46 ], [ '08-03', '09-03', 50 ], [ '09-04', '10-31', 36 ] ] as [ $from, $to, $top ] ) {
		$seasons[] = [ 'label' => GLC_Price_Store::season_label( $from, $to ), 'from' => $from, 'to' => $to, 'rates' => [ 'd1_2' => $top, 'd3_4' => $top - 4, 'd5_7' => $top - 6, 'd8_12' => $top - 8, 'd13_18' => $top - 10, 'd19_30' => $top - 12, 'd31p' => $top - 14 ] ];
	}
	return $seasons;
}
function codes( array $errors ): array { return array_values( array_map( fn( $e ) => $e['code'], $errors ) ); }
function first( array $errors, string $code ): array { foreach ( $errors as $e ) { if ( $e['code'] === $code ) { return $e; } } return []; }
function car( int $id, array $pricing ): void {
	$GLOBALS['posts'][ $id ] = (object) [ 'ID' => $id, 'post_type' => 'car', 'post_status' => 'publish', 'post_title' => 'Car ' . $id ];
	$GLOBALS['meta'][ $id ]  = [ 'glc_pricing' => $pricing, 'glc_available' => '1' ];
}
function refused( $result, string $code, int $car_id ): bool {
	return is_wp_error( $result ) && in_array( $code, codes( $result->get_error_data()['errors'][ $car_id ] ?? [] ), true );
}

/* ------------------------------------------------------------ season table */

check( GLC_Price_Store::season_label( '07-03', '08-02' ) === 'Jul 03 - Aug 02', 'Season label is generated in the format the 171 live rows already carry' );
check( GLC_Price_Store::validate_pricing( table() ) === [], 'A table that covers the year once, inside the bounds, is accepted' );

$gap       = table();
$gap[3]    = [ 'from' => '01-06', 'to' => '02-28', 'rates' => $gap[3]['rates'] ];
$gap[]     = [ 'from' => '03-16', 'to' => '03-31', 'rates' => $gap[3]['rates'] ];
$errors    = GLC_Price_Store::validate_pricing( $gap );
$e         = first( $errors, 'err_gap' );
check( codes( $errors ) === [ 'err_gap' ] && '03-01' === $e['from'] && '03-15' === $e['to'] && 15 === $e['days'], 'A hole from 03-01 to 03-15 is one gap with its first day, last day and length' );
check( str_contains( $e['message'], 'Mar 1' ) && str_contains( $e['message'], 'Mar 15' ), 'The gap message names both dates' );

$wrap      = table();
$wrap[1]['to']   = '12-24';
$wrap[2]['from'] = '12-30';
$wrap[2]['to']   = '01-02';
$wrap[3]['from'] = '01-06';
$e         = first( GLC_Price_Store::validate_pricing( $wrap ), 'err_gap' );
$all       = array_values( array_filter( GLC_Price_Store::validate_pricing( $wrap ), fn( $x ) => 'err_gap' === $x['code'] ) );
check( 2 === count( $all ) && [ '12-25', '12-29', 5 ] === [ $all[1]['from'], $all[1]['to'], $all[1]['days'] ] && [ '01-03', '01-05', 3 ] === [ $all[0]['from'], $all[0]['to'], $all[0]['days'] ], 'Two separate holes are reported as two gaps' );

$newyear   = table();
unset( $newyear[2] );
$e         = first( GLC_Price_Store::validate_pricing( array_values( $newyear ) ), 'err_gap' );
check( '12-25' === $e['from'] && '01-05' === $e['to'] && 12 === $e['days'], 'A hole that runs over New Year is one gap, not two' );

$overlap   = table();
$overlap[6]['to'] = '07-10';
$errors    = GLC_Price_Store::validate_pricing( $overlap );
$e         = first( $errors, 'err_overlap' );
check( codes( $errors ) === [ 'err_overlap' ] && '07-03' === $e['from'] && '07-10' === $e['to'] && [ 0, 6 ] === $e['seasons'], 'Two seasons that share days are refused, naming the days and both seasons' );
check( str_contains( $e['message'], 'Jul 3' ) && str_contains( $e['message'], 'Jul 10' ) && str_contains( $e['message'], 'season 1' ) && str_contains( $e['message'], 'season 7' ), 'The overlap message names the dates and both seasons' );

foreach ( [ '02-30' => 'February 30', '13-01' => 'month 13', '7-1' => 'no leading zeros', '00-10' => 'month zero', '02-29' => 'leap day (the year is checked as 365 days)', '' => 'empty' ] as $bad => $why ) {
	$t            = table();
	$t[0]['from'] = $bad;
	$e            = first( GLC_Price_Store::validate_pricing( $t ), 'err_season_date' );
	check( $e && 0 === $e['season'] && 'from' === $e['field'], 'Season date refused: ' . $why );
}
$t = table();
unset( $t[4]['to'] );
check( in_array( 'err_season_date', codes( GLC_Price_Store::validate_pricing( $t ) ), true ), 'A season with no end date is refused' );
$t    = table();
$t[2] = 'Dec 25 - Jan 05';
check( in_array( 'err_season_date', codes( GLC_Price_Store::validate_pricing( $t ) ), true ), 'A row that is not a season is refused' );
check( in_array( 'err_season_date', codes( GLC_Price_Store::validate_pricing( 'not a table' ) ), true ) || in_array( 'err_season_count', codes( GLC_Price_Store::validate_pricing( 'not a table' ) ), true ), 'Something that is not a table is refused' );

/* ------------------------------------------------------------------- rates */

$cases = [
	'missing'      => [ null, true ],
	'empty string' => [ '', true ],
	'text'         => [ 'abc', true ],
	'zero'         => [ 0, true ],
	'negative'     => [ -20, true ],
	'under the lowest allowed price' => [ 4.99, true ],
	'over the highest allowed price' => [ 300.01, true ],
	'three decimals' => [ 55.555, true ],
	'exponent'     => [ '1e2', true ],
	'an array'     => [ [ 55 ], true ],
	'exactly the lowest allowed price'  => [ 5, false ],
	'exactly the highest allowed price' => [ 300, false ],
	'a decimal as text' => [ '55.50', false ],
];
foreach ( $cases as $name => [ $value, $bad ] ) {
	$t = table();
	if ( null === $value ) { unset( $t[3]['rates']['d8_12'] ); } else { $t[3]['rates']['d8_12'] = $value; }
	$errors = GLC_Price_Store::validate_pricing( $t );
	$e      = first( $errors, 'err_rate_bounds' );
	check( $bad ? ( codes( $errors ) === [ 'err_rate_bounds' ] && 3 === $e['season'] && 'd8_12' === $e['band'] ) : ( [] === $errors ), 'Rate ' . ( $bad ? 'refused' : 'accepted' ) . ': ' . $name );
}
$t = table();
$t[3]['rates']['d8_12'] = 4;
$e = first( GLC_Price_Store::validate_pricing( $t ), 'err_rate_bounds' );
check( str_contains( $e['message'], '8-12' ) && str_contains( $e['message'], 'Jan 6' ) && str_contains( $e['message'], 'Mar 31' ) && str_contains( $e['message'], '$4' ) && str_contains( $e['message'], '$5' ), 'The rate message names the band, the season dates, the price and the limit' );

$t = table();
$t[0]['rates']['d1_2'] = 350;
$settings['rate_max_plausible'] = '400';
check( [] === GLC_Price_Store::validate_pricing( $t ), 'Raising the limit in the settings accepts the price without a code change' );
$settings['rate_max_plausible'] = '';
$t[0]['rates']['d1_2'] = 9000;
check( [] === GLC_Price_Store::validate_pricing( $t ), 'An empty limit means no upper limit' );
$settings['rate_min_plausible'] = '';
$t[0]['rates']['d1_2'] = 0;
check( codes( GLC_Price_Store::validate_pricing( $t ) ) === [ 'err_rate_bounds' ], 'With no lower limit a price must still be above zero' );
$settings['rate_min_plausible'] = 'cheap';
check( in_array( 'err_bounds_setting', codes( GLC_Price_Store::validate_pricing( table() ) ), true ), 'A limit that is not a number refuses the save instead of switching the check off' );
$settings['rate_min_plausible'] = '5';
$settings['rate_max_plausible'] = '300';

$t = table();
$t[0]['rates']['d1_2'] = 9000;
check( [] === GLC_Price_Store::validate_pricing( $t, GLC_Price_Store::LEVEL_SHAPE ), 'Restore level skips the price limits (the table was live once)' );
$t[0]['from'] = '02-30';
check( codes( GLC_Price_Store::validate_pricing( $t, GLC_Price_Store::LEVEL_SHAPE ) ) === [ 'err_season_date' ], 'Restore level still refuses a date that does not exist' );
$t = table();
unset( $t[3] ); // Jan 06 - Mar 31
$holes = GLC_Price_Store::validate_pricing( $t, GLC_Price_Store::LEVEL_SHAPE );
check( codes( $holes ) === [ 'err_gap' ] && '01-06' === $holes[0]['from'] && '03-31' === $holes[0]['to'], 'Restore level still refuses a table that leaves days without a season, and names them' );
$t   = table();
$t[] = [ 'from' => '07-01', 'to' => '07-10', 'rates' => $t[0]['rates'] ];
check( in_array( 'err_overlap', codes( GLC_Price_Store::validate_pricing( $t, GLC_Price_Store::LEVEL_SHAPE ) ), true ), 'Restore level still refuses a table that puts a day in two seasons' );
check( codes( GLC_Price_Store::validate_pricing( [], GLC_Price_Store::LEVEL_SHAPE ) ) === [ 'err_season_count' ], 'Restore level still refuses a table with no season at all' );
$many = [];
foreach ( GLC_Price_Store::year_days() as $i => $day ) {
	if ( $i < 30 ) { $many[] = [ 'from' => $day, 'to' => $day, 'rates' => [] ]; }
}
$many[] = [ 'from' => '01-31', 'to' => '12-31', 'rates' => [] ];
check( [] === GLC_Price_Store::validate_pricing( $many, GLC_Price_Store::LEVEL_SHAPE ) && in_array( 'err_season_count', codes( GLC_Price_Store::validate_pricing( $many ) ), true ), 'Restore level skips the season-count limit: 31 seasons that cover the year once pass it and fail a normal save' );

/* ------------------------------------------------------------ season count */

check( codes( GLC_Price_Store::validate_pricing( [] ) ) === [ 'err_season_count' ], 'A table with no season is refused' );
$many = [];
for ( $i = 0; $i < 25; $i++ ) {
	$many[] = [ 'from' => sprintf( '%02d-%02d', 1 + intdiv( $i, 3 ), 1 + ( $i % 3 ) * 9 ), 'to' => sprintf( '%02d-%02d', 1 + intdiv( $i, 3 ), 9 + ( $i % 3 ) * 9 ), 'rates' => table()[0]['rates'] ];
}
$e = first( GLC_Price_Store::validate_pricing( $many ), 'err_season_count' );
check( $e && str_contains( $e['message'], '24' ) && str_contains( $e['message'], '25' ), 'Twenty-five seasons are refused, naming the limit and the count' );
$settings['season_max_count'] = '30';
check( ! in_array( 'err_season_count', codes( GLC_Price_Store::validate_pricing( $many ) ), true ), 'The season limit is a setting, not a number in the code' );
$settings['season_max_count'] = '24';

/* -------------------------------------------------------------------- fees */

check( [] === GLC_Price_Store::validate_fees( [ 'delivery' => [ 'kutaisi_airport' => '95', 'tbilisi_city' => '0', 'batumi_airport' => '' ], 'out_of_hours' => '25' ] ), 'Fee overrides: a number, a zero for free and an empty field for the site standard are accepted' );
check( [] === GLC_Price_Store::validate_fees( [ 'delivery' => [ 'tbilisi_airport' => 500 ], 'out_of_hours' => '0' ] ), 'A fee exactly at the highest allowed fee is accepted' );
foreach ( [ 'tbilisi_office' => 'the office has no fee to override', 'mars_airport' => 'unknown place' ] as $place => $why ) {
	$e = first( GLC_Price_Store::validate_fees( [ 'delivery' => [ $place => '10' ] ] ), 'err_fee' );
	check( $e && $place === $e['place'], 'Fee refused: ' . $why );
}
foreach ( [ '-5' => 'negative', 'abc' => 'text', '10.555' => 'three decimals', '500.01' => 'above the highest allowed fee', '1e2' => 'exponent' ] as $value => $why ) {
	$e = first( GLC_Price_Store::validate_fees( [ 'delivery' => [ 'kutaisi_airport' => (string) $value ] ] ), 'err_fee' );
	check( $e && 'kutaisi_airport' === $e['place'], 'Delivery fee refused: ' . $why );
	$e = first( GLC_Price_Store::validate_fees( [ 'out_of_hours' => (string) $value ] ), 'err_fee' );
	check( $e && 'out_of_hours' === $e['place'], 'Early/late fee refused: ' . $why );
}
check( codes( GLC_Price_Store::validate_fees( 'free' ) ) === [ 'err_fee' ], 'Fees that are not a list are refused' );
check( [] === GLC_Price_Store::validate_fees( [ 'delivery' => [ 'kutaisi_airport' => '900' ] ], GLC_Price_Store::LEVEL_SHAPE ), 'Restore level skips the fee limit' );

/* ------------------------------------------------- writing, and the revision */

car( 4, table() );
car( 5, table() );
car( 6, table() );
$posts[9] = (object) [ 'ID' => 9, 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'A page' ];

$new = table();
$new[3]['rates']['d1_2'] = 22; // the car's lowest price moves from $14 to… still $14; the top stays $54
$new[1]['rates']['d8_12'] = 23;
$before = $meta;
$result = GLC_Price_Store::write( 4, [ 'pricing' => $new ], [ 'source' => 'panel' ] );
check( refused( $result, 'err_no_rev', 4 ) && $meta === $before && [] === $log, 'A write that does not say which revision it started from is refused, and nothing is written' );

$result = GLC_Price_Store::write( 4, [ 'pricing' => $new ], [ 'source' => 'panel', 'rev' => 0 ] );
check( ! is_wp_error( $result ) && 1 === GLC_Price_Store::rev( 4 ) && 1 === $result['cars'][4]['rev'], 'A valid write is saved and the revision goes from 0 to 1' );
check( $meta[4]['glc_pricing'][3]['rates']['d1_2'] === 22 && $meta[4]['glc_pricing_previous'] === table(), 'The new table is stored and the previous one is kept' );
check( 1 === count( $log ) && 'pricing' === $log[0]['kind'] && 'panel' === $log[0]['source'] && 7 === $log[0]['actor'] && 4 === $log[0]['car_id'] && table() === $log[0]['before'] && $meta[4]['glc_pricing'] === $log[0]['after'] && $result['batch'] === $log[0]['batch'], 'One change-log entry carries the full before and after, the actor, the source and the batch' );
check( [] === $pings, 'No search-engine ping when the car\'s lowest and highest price did not move' );


$stale  = table();
$stale[0]['rates']['d1_2'] = 60;
$before = $meta;
$result = GLC_Price_Store::write( 4, [ 'pricing' => $stale ], [ 'source' => 'panel', 'rev' => 0 ] );
$e      = is_wp_error( $result ) ? first( $result->get_error_data()['errors'][4], 'err_stale' ) : [];
check( $e && $meta === $before && 1 === count( $log ) && 1 === GLC_Price_Store::rev( 4 ), 'A write made from an older revision is refused and nothing changes' );
check( $e && str_contains( $e['message'], 'Nino Partner' ) && str_contains( $e['message'], '2026-10-12 14:03' ), 'The refusal says who changed the car and when' );

$result = GLC_Price_Store::write( 4, [ 'pricing' => $stale ], [ 'source' => 'panel', 'rev' => 1 ] );
check( ! is_wp_error( $result ) && 2 === GLC_Price_Store::rev( 4 ) && 1 === count( $pings ) && 7 === count( $pings[0]['urls'] ) && 'pricing:' . $result['batch'] === $pings[0]['reason'], 'From the current revision the write is saved, and a changed highest price pings once with the car\'s seven URLs' );

$before = $meta;
$count  = count( $log );
$result = GLC_Price_Store::write( 4, [ 'pricing' => $stale ], [ 'source' => 'panel', 'rev' => 2 ] );
check( ! is_wp_error( $result ) && [ 4 ] === $result['unchanged'] && [] === $result['cars'] && $meta === $before && count( $log ) === $count, 'Saving the same table again writes nothing: no revision, no log entry' );

$before = $meta;
$bad    = table();
$bad[0]['rates']['d1_2'] = 0;
$result = GLC_Price_Store::write( 4, [ 'pricing' => $bad ], [ 'source' => 'panel', 'rev' => 2 ] );
check( refused( $result, 'err_rate_bounds', 4 ) && $meta === $before, 'An invalid table is refused and the stored one is untouched' );

$before = $meta;
$result = GLC_Price_Store::write( 6, [ 'pricing' => $new ], [ 'source' => 'panel', 'rev' => 0 ] );
check( refused( $result, 'err_forbidden', 6 ) && $meta === $before, 'A car that is not assigned to the actor is refused' );
check( refused( GLC_Price_Store::write( 9, [ 'pricing' => $new ], [ 'source' => 'panel', 'rev' => 0 ] ), 'err_not_car', 9 ), 'A post that is not a car is refused' );
// "photos" was the example here until the store learned photos (leg L5); the rule is about a kind it does not know.
check( refused( GLC_Price_Store::write( 4, [ 'videos' => [ 1, 2 ] ], [ 'source' => 'panel', 'rev' => 2 ] ), 'err_unknown_kind', 4 ), 'A kind the store does not handle is refused, not ignored' );
check( [ 'pricing', 'fees', 'photos', 'specs' ] === GLC_Price_Store::KINDS && [ 'pricing', 'fees' ] === GLC_Price_Store::QUOTED, 'The store handles prices, fees, photos and details; only the first two have a quote to preview' );
check( is_wp_error( GLC_Price_Store::write( 4, [ 'pricing' => $new ], [ 'source' => 'telepathy', 'rev' => 2 ] ) ), 'An unknown source is refused' );
check( is_wp_error( GLC_Price_Store::write( 4, [ 'pricing' => $new ], [ 'source' => 'cli' ] ) ) && $meta === $before, 'Source "cli" is refused outside WP-CLI, so a web request cannot skip the checks by naming it' );

/* -------------------------------------------------------------- fee writes */

$count  = count( $log );
$pinged = count( $pings );
$result = GLC_Price_Store::write( 4, [ 'fees' => [ 'delivery' => [ 'kutaisi_airport' => '95.00', 'tbilisi_city' => 0, 'batumi_airport' => '' ], 'out_of_hours' => '25' ] ], [ 'source' => 'panel', 'rev' => 2 ] );
check( ! is_wp_error( $result ) && [ 'tbilisi_city' => '0', 'kutaisi_airport' => '95' ] === $meta[4]['glc_delivery_fees'] && '25' === $meta[4]['glc_out_of_hours_fee'] && 3 === GLC_Price_Store::rev( 4 ), 'Fee overrides are stored as plain number strings: zero kept as free, an empty field left out' );
check( count( $log ) === $count + 1 && 'fees' === $log[ $count ]['kind'] && [ 'delivery' => [], 'out_of_hours' => '' ] === $log[ $count ]['before'] && count( $pings ) === $pinged, 'A fee change is logged with before and after and does not ping' );
$result = GLC_Price_Store::write( 4, [ 'fees' => [ 'out_of_hours' => '' ] ], [ 'source' => 'panel', 'rev' => 3 ] );
check( ! is_wp_error( $result ) && ! isset( $meta[4]['glc_out_of_hours_fee'] ) && isset( $meta[4]['glc_delivery_fees'] ), 'Clearing the early/late override removes it and leaves the delivery overrides alone' );
$result = GLC_Price_Store::write( 4, [ 'fees' => [ 'delivery' => [] ] ], [ 'source' => 'panel', 'rev' => 4 ] );
check( ! is_wp_error( $result ) && ! isset( $meta[4]['glc_delivery_fees'] ), 'Removing every delivery override removes the meta: absent means the site standard' );
$before = $meta;
check( refused( GLC_Price_Store::write( 4, [ 'fees' => [ 'delivery' => [ 'kutaisi_airport' => '501' ] ] ], [ 'source' => 'panel', 'rev' => 5 ] ), 'err_fee', 4 ) && $meta === $before, 'A fee above the limit is refused and nothing is written' );

/* ------------------------------------------------------------------ batches */

$a = table();
$a[0]['rates']['d1_2'] = 70;
$b = table();
$b[0]['rates']['d1_2'] = 0;
$before = $meta;
$count  = count( $log );
$result = GLC_Price_Store::write_batch( [ 4 => [ 'changes' => [ 'pricing' => $a ], 'rev' => 5 ], 5 => [ 'changes' => [ 'pricing' => $b ], 'rev' => 0 ] ], [ 'source' => 'bulk' ] );
check( refused( $result, 'err_rate_bounds', 5 ) && $meta === $before && count( $log ) === $count, 'One invalid car blocks the whole batch: no car is written' );
$result = GLC_Price_Store::write_batch( [ 4 => [ 'changes' => [ 'pricing' => $a ], 'rev' => 5 ], 6 => [ 'changes' => [ 'pricing' => $a ], 'rev' => 0 ] ], [ 'source' => 'bulk' ] );
check( refused( $result, 'err_forbidden', 6 ) && $meta === $before, 'One unassigned car blocks the whole batch' );

$pinged = count( $pings );
$b      = table();
$b[4]['rates']['d5_7'] = 29; // inside the range: this car's lowest and highest price stay
$result = GLC_Price_Store::write_batch( [ 4 => [ 'changes' => [ 'pricing' => $a ], 'rev' => 5 ], 5 => [ 'changes' => [ 'pricing' => $b, 'fees' => [ 'out_of_hours' => '0' ] ], 'rev' => 0 ] ], [ 'source' => 'bulk', 'note' => 'autumn' ] );
$mine   = array_slice( $log, $count );
check( ! is_wp_error( $result ) && 6 === GLC_Price_Store::rev( 4 ) && 1 === GLC_Price_Store::rev( 5 ), 'A valid batch writes every car, one revision step each' );
check( 3 === count( $mine ) && 1 === count( array_unique( array_column( $mine, 'batch' ) ) ) && [ 'pricing', 'pricing', 'fees' ] === array_column( $mine, 'kind' ) && 'autumn' === $mine[0]['note'], 'Every entry of the batch carries the same batch id; pricing and fees are logged separately' );
check( count( $pings ) === $pinged + 1 && 7 === count( $pings[ $pinged ]['urls'] ) && str_contains( $pings[ $pinged ]['urls'][0], 'car-4' ), 'The batch pings once, and only for the car whose price range moved' );

/* ------------------------------------------------------------- who is saving */

// Who saved is the person signed in. Only a shell (WP-CLI, which is not defined here) may name someone else.
$assigned[99] = [ 4, 5 ];
$result       = GLC_Price_Store::write( 4, [ 'fees' => [ 'out_of_hours' => '7' ] ], [ 'source' => 'panel', 'rev' => GLC_Price_Store::rev( 4 ), 'actor' => 99 ] );
check( ! is_wp_error( $result ) && 7 === end( $log )['actor'], 'A caller that names another user as the actor is ignored: the entry is the signed-in user\'s' );
$assigned[7] = [ 5 ];
$result      = GLC_Price_Store::write( 4, [ 'fees' => [ 'out_of_hours' => '8' ] ], [ 'source' => 'panel', 'rev' => GLC_Price_Store::rev( 4 ), 'actor' => 99 ] );
check( refused( $result, 'err_forbidden', 4 ) && '7' === $meta[4]['glc_out_of_hours_fee'], 'Nor does naming a user who has the car get a person past the assignment check' );

echo "\n" . ( $failures ? "{$failures} FAILED" : 'ALL PASS' ) . "\n";
exit( $failures ? 1 : 0 );
