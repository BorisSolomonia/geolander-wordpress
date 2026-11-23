<?php
/**
 * Standalone checks for the quote engine: GLC_Pricing (the season table) and
 * GLC_Rental (delivery fees, the early/late handover fee, the total). No
 * WordPress, no database: car meta, the fleet and the settings are held in
 * memory below. The numbered headings are the invariants of the design, §3.5.
 *
 * Run: docker run --rm -v "<repo>:/src" php:8.3-cli php /src/tests/quote-engine.php
 *
 * GLC_SRC points the test at another copy of the code (a folder holding
 * `includes/` and `inc/`). That is how it is shown that these checks can fail:
 * break one line in a copy, run against the copy, read the FAIL.
 */
define( 'ABSPATH', __DIR__ );
$failures = 0;
$meta     = [];
$fleet    = [];
$read     = [];
$car_page = 0;
$base     = [
	'tbilisi_city_each_way' => '10', 'tbilisi_airport_each_way' => '20', 'kutaisi_each_way' => '80', 'batumi_each_way' => '100',
	'prepayment_percent' => '10', 'payment_currency' => 'USD',
	'handover_window_start' => '10:00', 'handover_window_end' => '20:00', 'out_of_hours_fee' => '15', 'handover_default_time' => '12:00',
	'rate_min_plausible' => '5', 'rate_max_plausible' => '300', 'fee_max_plausible' => '500', 'season_max_count' => '24',
];
$settings = $base;
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
function __( $text, $domain = '' ) { return $text; }
/** Every meta key the engine asks for is noted, so "what does a quote read" has an answer. */
function get_post_meta( $id, $key = '', $single = false ) { $GLOBALS['read'][ $key ] = true; return $GLOBALS['meta'][ $id ][ $key ] ?? ''; }
/** The published fleet, as ids. Nothing else about a car is reachable from here: no title, no text. */
function get_posts( $args = [] ) { return $GLOBALS['fleet']; }
function is_singular( $type = '' ) { return 'car' === $type && $GLOBALS['car_page'] > 0; }
function get_queried_object_id() { return $GLOBALS['car_page']; }
function sanitize_key( $key ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) ); }
function shortcode_atts( $defaults, $atts, $tag = '' ) { return array_merge( $defaults, array_intersect_key( (array) $atts, $defaults ) ); }
function get_current_user_id() { return 0; }
function get_user_meta( $id, $key, $single = false ) { return ''; }
function get_user_locale( $id = 0 ) { return 'en_US'; }
function glc_ui( $key ) { return $GLOBALS['strings'][ $key ] ?? $key; }
class GLC_Settings { public static function get( $key, $default = null ) { return $GLOBALS['settings'][ $key ] ?? $default; } }

$src = getenv( 'GLC_SRC' ) ?: null;
$inc = $src ? $src . '/includes' : __DIR__ . '/../wp-content/plugins/geolander-core/includes';
$strings = require ( $src ? $src . '/inc' : __DIR__ . '/../wp-content/themes/geolander/inc' ) . '/strings-en.php';
require $inc . '/class-glc-format.php';
require $inc . '/class-glc-pricing.php';
require $inc . '/class-glc-rental.php';
require $inc . '/class-glc-admin-strings.php';
require $inc . '/class-glc-price-store.php';

/** The live shape: nine seasons that cover the year once, whole-dollar rates. */
function table(): array {
	$seasons = [];
	foreach ( [ [ '07-03', '08-02', 54 ], [ '11-01', '12-24', 30 ], [ '12-25', '01-05', 40 ], [ '01-06', '03-31', 28 ], [ '04-01', '05-05', 34 ], [ '05-06', '06-12', 38 ], [ '06-13', '07-02', 46 ], [ '08-03', '09-03', 50 ], [ '09-04', '10-31', 36 ] ] as [ $from, $to, $top ] ) {
		$seasons[] = [ 'label' => GLC_Price_Store::season_label( $from, $to ), 'from' => $from, 'to' => $to, 'rates' => [ 'd1_2' => $top, 'd3_4' => $top - 4, 'd5_7' => $top - 6, 'd8_12' => $top - 8, 'd13_18' => $top - 10, 'd19_30' => $top - 12, 'd31p' => $top - 14 ] ];
	}
	return $seasons;
}
/** Put the fixture back: two published cars with the same table, no overrides, the site's own settings. */
function reset_world(): void {
	$GLOBALS['settings'] = $GLOBALS['base'];
	$GLOBALS['fleet']    = [ 4, 5 ];
	$GLOBALS['meta']     = [ 4 => [ 'glc_pricing' => table() ], 5 => [ 'glc_pricing' => table() ] ];
	$GLOBALS['car_page'] = 0;
}
function q( array $args = [] ) {
	$a = $args + [ 'car' => 4, 'from' => '2027-05-01', 'to' => '2027-05-04', 'pickup' => 'tbilisi_office', 'return' => 'tbilisi_office', 'pickup_time' => null, 'return_time' => null ];
	return GLC_Rental::quote( $a['car'], $a['from'], $a['to'], $a['pickup'], $a['return'], $a['pickup_time'], $a['return_time'] );
}
function refused( $quote, string $code ): bool { return is_wp_error( $quote ) && $code === $quote->get_error_code(); }
function codes( array $errors ): array { return array_values( array_map( fn( $e ) => $e['code'], $errors ) ); }
function first( array $errors, string $code ): array { foreach ( $errors as $e ) { if ( $e['code'] === $code ) { return $e; } } return []; }
/** The rental by a second path: this file's own day loop over the table, not the engine's. */
function recompute( array $seasons, string $from, int $days ): ?float {
	$bands = [ 2 => 'd1_2', 4 => 'd3_4', 7 => 'd5_7', 12 => 'd8_12', 18 => 'd13_18', 30 => 'd19_30' ];
	$band  = 'd31p';
	foreach ( $bands as $max => $key ) { if ( $days <= $max ) { $band = $key; break; } }
	$sum = 0.0;
	for ( $i = 0; $i < $days; $i++ ) {
		$md = gmdate( 'm-d', strtotime( $from . ' +' . $i . ' days UTC' ) );
		if ( '02-29' === $md ) { $md = '02-28'; }
		$rate = null;
		foreach ( $seasons as $s ) {
			$in = $s['from'] <= $s['to'] ? ( $md >= $s['from'] && $md <= $s['to'] ) : ( $md >= $s['from'] || $md <= $s['to'] );
			if ( $in ) { $rate = (float) ( $s['rates'][ $band ] ?? 0 ); break; }
		}
		if ( null === $rate || $rate <= 0 ) { return null; }
		$sum += $rate;
	}
	return round( $sum, 2 );
}
$fee_keys = [ 'pickup_fee', 'return_fee', 'pickup_time_fee', 'return_time_fee' ];

/* --------------------------------- 1. a request without times, as before */

reset_world();
$old_keys = [ 'days', 'tier', 'total', 'per_day_avg', 'breakdown', 'currency', 'rental_total', 'pickup', 'pickup_label', 'pickup_fee', 'return', 'return_label', 'return_fee', 'prepayment', 'balance' ];
$new_keys = [ 'pickup_time', 'return_time', 'pickup_time_fee', 'return_time_fee', 'times_assumed' ];
$quote    = q( [ 'pickup' => 'kutaisi_airport', 'return' => 'batumi_airport' ] );
check( is_array( $quote ) && ! array_diff( $old_keys, array_keys( $quote ) ) && [] === array_values( array_diff( array_keys( $quote ), $old_keys, $new_keys ) ) && ! array_diff( $new_keys, array_keys( $quote ) ), 'A quote keeps every field it had and gains exactly five' );
check( is_array( $quote ) && '12:00' === $quote['pickup_time'] && '12:00' === $quote['return_time'] && 0.0 === $quote['pickup_time_fee'] && 0.0 === $quote['return_time_fee'] && true === $quote['times_assumed'], 'No time sent: both are the default time, no time fee, and the quote says the times were assumed' );
check( is_array( $quote ) && 90.0 === $quote['rental_total'] && 80.0 === $quote['pickup_fee'] && 100.0 === $quote['return_fee'] && 270.0 === $quote['total'] && 27.0 === $quote['prepayment'] && 243.0 === $quote['balance'], 'Three May days at $30 plus Kutaisi $80 and Batumi $100: $270, prepayment $27, balance $243' );
$settings['handover_default_time'] = '09:15';
$quote = q();
check( is_array( $quote ) && '09:15' === $quote['pickup_time'] && true === $quote['times_assumed'] && 15.0 === $quote['pickup_time_fee'] && 15.0 === $quote['return_time_fee'], 'The assumed time is the setting, and an assumed time outside the hours is charged like a chosen one' );
reset_world();
$quote = q( [ 'pickup_time' => '11:00', 'return_time' => '18:30' ] );
check( is_array( $quote ) && false === $quote['times_assumed'] && '11:00' === $quote['pickup_time'] && '18:30' === $quote['return_time'], 'Times that were sent come back as sent, not assumed' );
$quote = q( [ 'pickup_time' => '11:00' ] );
check( is_array( $quote ) && true === $quote['times_assumed'] && '11:00' === $quote['pickup_time'] && '12:00' === $quote['return_time'], 'One time sent, one not: the missing one is assumed and the quote says so' );

/* ------------------------------------------------------- 2. additivity */

reset_world();
$meta[5]['glc_delivery_fees']    = [ 'kutaisi_airport' => '95.50', 'tbilisi_city' => '0' ];
$meta[5]['glc_out_of_hours_fee'] = '12.25';
$ok    = true;
$count = 0;
foreach ( [ 4, 5 ] as $car ) {
	foreach ( [ [ '2027-05-01', '2027-05-02' ], [ '2027-06-28', '2027-07-08' ], [ '2026-12-20', '2027-01-10' ], [ '2027-10-20', '2027-11-29' ] ] as [ $from, $to ] ) {
		foreach ( GLC_Rental::location_keys() as $pickup ) {
			foreach ( GLC_Rental::location_keys() as $return ) {
				foreach ( [ [ null, null ], [ '09:00', '12:00' ], [ '12:00', '21:30' ], [ '06:00', '23:59' ] ] as [ $pt, $rt ] ) {
					$quote = q( [ 'car' => $car, 'from' => $from, 'to' => $to, 'pickup' => $pickup, 'return' => $return, 'pickup_time' => $pt, 'return_time' => $rt ] );
					++$count;
					$ok = $ok && is_array( $quote )
						&& $quote['total'] === round( $quote['rental_total'] + $quote['pickup_fee'] + $quote['return_fee'] + $quote['pickup_time_fee'] + $quote['return_time_fee'], 2 )
						&& $quote['prepayment'] === round( $quote['total'] * ( 10 / 100 ), 2 )
						&& $quote['balance'] === round( $quote['total'] - $quote['prepayment'], 2 );
				}
			}
		}
	}
}
check( $ok && 800 === $count, 'In 800 quotes the total is the rental plus the four fees, the prepayment is 10% of it and the balance is the rest' );
$settings['prepayment_percent'] = '15';
$quote = q( [ 'pickup' => 'batumi_airport', 'pickup_time' => '07:00', 'return_time' => '12:00' ] );
check( is_array( $quote ) && 205.0 === $quote['total'] && 30.75 === $quote['prepayment'] && 174.25 === $quote['balance'], 'The prepayment follows its setting and is taken from the total with the early fee in it' );

/* ------------------------------------------------------- 3. boundaries */

reset_world();
$edge = static fn( array $times ): array => array_map( [ 'GLC_Rental', 'is_out_of_hours' ], $times );
check( [ true, false, false, true, true, true ] === $edge( [ '09:59', '10:00', '20:00', '20:01', '00:00', '23:59' ] ), 'Outside the hours: 09:59, 20:01, 00:00, 23:59. Inside: exactly 10:00 and exactly 20:00' );
$settings['handover_window_start'] = '08:30';
$settings['handover_window_end']   = '22:15';
check( [ true, false, false, true ] === $edge( [ '08:29', '08:30', '22:15', '22:16' ] ), 'The hours are the settings: with 08:30 to 22:15 the same four edges hold there' );
$quote = q( [ 'pickup_time' => '08:30', 'return_time' => '22:16' ] );
check( is_array( $quote ) && 0.0 === $quote['pickup_time_fee'] && 15.0 === $quote['return_time_fee'], 'A quote uses the same edges as is_out_of_hours' );

/* ----------------------------------------------------- 4. independence */

reset_world();
$fees = static function ( array $args ): array { $quote = q( $args ); return is_array( $quote ) ? [ $quote['pickup_time_fee'], $quote['return_time_fee'] ] : [ 'refused' ]; };
check( [ 15.0, 0.0 ] === $fees( [ 'pickup_time' => '09:00', 'return_time' => '12:00' ] ), 'Early pickup, midday return: one fee' );
check( [ 15.0, 15.0 ] === $fees( [ 'pickup_time' => '09:00', 'return_time' => '21:00' ] ), 'Early pickup and late return: two fees' );
check( [ 0.0, 0.0 ] === $fees( [ 'pickup_time' => '12:00', 'return_time' => '12:00' ] ), 'Both inside the hours: none' );
check( [ 0.0, 15.0 ] === $fees( [ 'pickup_time' => '12:00', 'return_time' => '20:01' ] ), 'A late return alone: one fee, on the return' );
$office  = q( [ 'pickup' => 'tbilisi_office', 'pickup_time' => '08:00', 'return_time' => '12:00' ] );
$airport = q( [ 'pickup' => 'kutaisi_airport', 'pickup_time' => '08:00', 'return_time' => '12:00' ] );
check( is_array( $office ) && is_array( $airport ) && 15.0 === $office['pickup_time_fee'] && 15.0 === $airport['pickup_time_fee'] && 0.0 === $office['pickup_fee'] && 80.0 === $airport['pickup_fee'], 'The office at 08:00 is charged the early fee like an airport at 08:00; the place changes only the delivery fee' );
$with    = q( [ 'pickup' => 'batumi_airport', 'return' => 'kutaisi_airport', 'pickup_time' => '09:00', 'return_time' => '21:00' ] );
$without = q( [ 'pickup' => 'batumi_airport', 'return' => 'kutaisi_airport' ] );
check( is_array( $with ) && is_array( $without ) && $with['total'] === $without['total'] + 30.0 && $with['rental_total'] === $without['rental_total'] && $with['pickup_fee'] === $without['pickup_fee'] && $with['return_fee'] === $without['return_fee'], 'Times change nothing but the two time fees: the same quote plus two fees' );

/* ---------------------------------------------- 5. a car's own amount */

reset_world();
$meta[4]['glc_out_of_hours_fee'] = '0';
check( [ 0.0, 0.0 ] === $fees( [ 'pickup_time' => '06:00', 'return_time' => '23:00' ] ) && 0.0 === GLC_Rental::out_of_hours_fee( 4 ), 'A car whose own amount is 0 is not charged, even at 06:00' );
$meta[4]['glc_out_of_hours_fee'] = '25';
check( [ 25.0, 25.0 ] === $fees( [ 'pickup_time' => '06:00', 'return_time' => '23:00' ] ) && [ 15.0, 15.0 ] === $fees( [ 'car' => 5, 'pickup_time' => '06:00', 'return_time' => '23:00' ] ), 'A car whose own amount is 25 is charged 25; the other car keeps the site amount' );
unset( $meta[4]['glc_out_of_hours_fee'] );
check( [ 15.0, 0.0 ] === $fees( [ 'pickup_time' => '06:00', 'return_time' => '12:00' ] ) && 15.0 === GLC_Rental::out_of_hours_fee( 4 ) && 15.0 === GLC_Rental::out_of_hours_fee(), 'No amount of its own: the setting' );
$settings['out_of_hours_fee'] = '';
check( refused( q( [ 'pickup_time' => '06:00', 'return_time' => '12:00' ] ), 'glc_fee_unknown' ) && null === GLC_Rental::out_of_hours_fee( 4 ), 'Setting empty and no amount of its own: a time outside the hours is refused (glc_fee_unknown), not charged zero' );
check( [ 0.0, 0.0 ] === $fees( [ 'pickup_time' => '12:00', 'return_time' => '12:00' ] ) && [ 0.0, 0.0 ] === $fees( [] ), 'The same car inside the hours quotes normally: no fee was needed' );
$meta[5]['glc_out_of_hours_fee'] = '9';
check( [ 9.0, 9.0 ] === $fees( [ 'car' => 5, 'pickup_time' => '06:00', 'return_time' => '23:00' ] ), 'A car with its own amount is quoted even while the setting is empty' );
foreach ( [ 'abc' => 'a word', '-5' => 'a negative amount' ] as $bad => $why ) {
	$settings['out_of_hours_fee'] = (string) $bad;
	check( refused( q( [ 'pickup_time' => '06:00' ] ), 'glc_fee_unknown' ), 'A setting that is ' . $why . ' is not decided, so it is refused too' );
}
reset_world();
$settings['handover_window_end'] = 'late';
check( refused( q( [ 'pickup_time' => '12:00', 'return_time' => '12:00' ] ), 'glc_fee_unknown' ), 'Hours that are not times cannot say whether a fee applies: refused, not waved through' );
reset_world();
$settings['handover_default_time'] = '';
check( refused( q(), 'glc_fee_unknown' ) && is_array( q( [ 'pickup_time' => '12:00', 'return_time' => '12:00' ] ) ), 'No usable default time: a request without times is refused, one with times is quoted' );

/* --------------------------------------- 6. a car's own delivery fee */

reset_world();
$meta[4]['glc_delivery_fees'] = [ 'kutaisi_airport' => '95' ];
$quote = q( [ 'pickup' => 'kutaisi_airport', 'return' => 'batumi_airport' ] );
check( is_array( $quote ) && 95.0 === $quote['pickup_fee'] && 100.0 === $quote['return_fee'] && 'priced' === GLC_Rental::fee_state( 'kutaisi_airport', 4 ) && 95.0 === GLC_Rental::location_fee( 'kutaisi_airport', 4 ), 'Its own Kutaisi fee of 95 is what its quote charges; Batumi stays the site fee' );
$other = q( [ 'car' => 5, 'pickup' => 'kutaisi_airport', 'return' => 'batumi_airport' ] );
check( is_array( $other ) && 80.0 === $other['pickup_fee'] && 80.0 === GLC_Rental::location_fee( 'kutaisi_airport', 5 ) && 80.0 === GLC_Rental::location_fee( 'kutaisi_airport' ), 'The other car and the site keep 80' );
$meta[4]['glc_delivery_fees'] = [ 'kutaisi_airport' => '0' ];
$quote = q( [ 'pickup' => 'kutaisi_airport' ] );
check( is_array( $quote ) && 0.0 === $quote['pickup_fee'] && 'free' === GLC_Rental::fee_state( 'kutaisi_airport', 4 ) && GLC_Rental::is_free( 'kutaisi_airport', 4 ) && ! GLC_Rental::is_free( 'kutaisi_airport', 5 ), 'Its own fee of 0 is free for that car only' );
unset( $meta[4]['glc_delivery_fees'] );
$settings['kutaisi_each_way'] = '';
check( refused( q( [ 'pickup' => 'kutaisi_airport' ] ), 'glc_fee_unknown' ) && refused( q( [ 'return' => 'kutaisi_airport' ] ), 'glc_fee_unknown' ) && 'unknown' === GLC_Rental::fee_state( 'kutaisi_airport', 4 ), 'Site fee empty and no fee of its own: choosing that place is refused (glc_fee_unknown), as pickup or as return' );
check( is_array( q( [ 'pickup' => 'batumi_airport', 'return' => 'tbilisi_office' ] ) ), 'The same car with other places quotes normally' );
$meta[5]['glc_delivery_fees'] = [ 'kutaisi_airport' => '70' ];
$quote = q( [ 'car' => 5, 'pickup' => 'kutaisi_airport' ] );
check( is_array( $quote ) && 70.0 === $quote['pickup_fee'], 'A car with its own fee for that place is quoted even while the site fee is empty' );
$places = GLC_Rental::locations( 4 );
check( 'unknown' === $places['kutaisi_airport']['state'] && false === $places['kutaisi_airport']['free'] && 'priced' === GLC_Rental::locations( 5 )['kutaisi_airport']['state'] && 70.0 === GLC_Rental::locations( 5 )['kutaisi_airport']['fee'] && 'free' === $places['tbilisi_office']['state'], 'The booking form is told each place\'s state for that car: unknown, priced at its own fee, free' );
reset_world();
check( refused( GLC_Rental::quote( 4, '2027-05-01', '2027-05-04', 'tbilisi_office', 'tbilisi_office', '9:00', '12:00' ), 'glc_bad_time' ), 'A time without a leading zero is refused (glc_bad_time)' );
foreach ( [ '24:00' => 'an hour that does not exist', '12:60' => 'a minute that does not exist', '' => 'an empty text', 'noon' => 'a word', '12:00:00' => 'seconds', ' 12:00' => 'a leading space', "12:00\n" => 'a trailing line break' ] as $bad => $why ) {
	check( refused( q( [ 'pickup_time' => '12:00', 'return_time' => (string) $bad ] ), 'glc_bad_time' ), 'Time refused: ' . $why );
}
check( null === GLC_Rental::quote( 4, '2027-05-01', '2027-05-04', 'nowhere', 'tbilisi_office' ) && null === q( [ 'to' => '2027-05-01' ] ) && null === q( [ 'from' => 'soon' ] ), 'An unknown place, an end not after the start, or a date that is not a date gives no quote, as before' );

/* ------------------------------------------------------------- 7. gaps */

reset_world();
$gap    = table();
$gap[3] = [ 'label' => 'Jan 06 - Feb 28', 'from' => '01-06', 'to' => '02-28', 'rates' => $gap[3]['rates'] ];
$gap[]  = [ 'label' => 'Mar 16 - Mar 31', 'from' => '03-16', 'to' => '03-31', 'rates' => $gap[3]['rates'] ];
$meta[4]['glc_pricing']    = $gap;
$meta[4]['glc_price_from'] = '50';
check( null === GLC_Pricing::quote( 4, '2027-02-26', '2027-03-03' ) && null === q( [ 'from' => '2027-02-26', 'to' => '2027-03-03' ] ) && null === GLC_Pricing::quote( 4, '2027-03-15', '2027-03-17' ), 'A rental touching a day no season covers gets no quote: not the headline price, not zero' );
check( is_array( GLC_Pricing::quote( 4, '2027-02-20', '2027-03-01' ) ) && is_array( GLC_Pricing::quote( 4, '2027-03-16', '2027-03-20' ) ), 'A rental that stays on covered days is quoted (the return day itself is not a rental day)' );
$errors = GLC_Price_Store::validate_pricing( $gap );
$e      = first( $errors, 'err_gap' );
check( [ 'err_gap' ] === codes( $errors ) && '03-01' === $e['from'] && '03-15' === $e['to'], 'The store refuses to save that table, naming 03-01 and 03-15' );
$zero = table();
$zero[4]['rates']['d3_4'] = 0;
$meta[4]['glc_pricing']   = $zero;
check( null === GLC_Pricing::quote( 4, '2027-05-01', '2027-05-04' ) && is_array( GLC_Pricing::quote( 4, '2027-05-01', '2027-05-03' ) ), 'A season with no price for the rental length gives no quote for that length, and still quotes the lengths it has' );
$missing = table();
unset( $missing[4]['rates']['d3_4'] );
$meta[4]['glc_pricing'] = $missing;
check( null === GLC_Pricing::quote( 4, '2027-05-01', '2027-05-04' ), 'The same when the band is absent' );
$meta[4]['glc_pricing'] = [];
check( null === GLC_Pricing::quote( 4, '2027-05-01', '2027-05-04' ) && [ 50.0, 50.0 ] === GLC_Pricing::rate_range( 4 ), 'A car with no seasons at all: no quote, and the headline price is still its published "from" price' );
reset_world();
$leap = GLC_Pricing::quote( 4, '2028-02-27', '2028-03-02' );
check( is_array( $leap ) && 4 === $leap['days'] && 96.0 === $leap['total'] && [ 'Jan 06 - Mar 31' => [ 'days' => 4, 'rate' => 24.0 ] ] === $leap['breakdown'], '29 February is a rental day like the others' );
$split = table();
$split[3] = [ 'label' => 'Jan 06 - Feb 28', 'from' => '01-06', 'to' => '02-28', 'rates' => array_fill_keys( GLC_Pricing::TIERS, 20 ) ];
$split[]  = [ 'label' => 'Mar 01 - Mar 31', 'from' => '03-01', 'to' => '03-31', 'rates' => array_fill_keys( GLC_Pricing::TIERS, 50 ) ];
$meta[4]['glc_pricing'] = $split;
$leap = GLC_Pricing::quote( 4, '2028-02-28', '2028-03-02' );
check( [] === GLC_Price_Store::validate_pricing( $split ) && is_array( $leap ) && 90.0 === $leap['total'] && 2 === $leap['breakdown']['Jan 06 - Feb 28']['days'] && 1 === $leap['breakdown']['Mar 01 - Mar 31']['days'], 'Seasons that meet at 28 Feb / 1 Mar are a full table, and 29 Feb is priced as 28 Feb: 20 + 20 + 50' );
check( null !== GLC_Pricing::season_for_date( $split, new DateTimeImmutable( '2028-02-29' ) ) && 'Jan 06 - Feb 28' === GLC_Pricing::season_for_date( $split, new DateTimeImmutable( '2028-02-29' ) )['label'], 'season_for_date finds the 28 February season for 29 February' );

/* --------------------------------------------------------- 8. overlap */

reset_world();
$overlap = table();
$overlap[6]['to'] = '07-10';
$errors  = GLC_Price_Store::validate_pricing( $overlap );
check( [ 'err_overlap' ] === codes( $errors ), 'Two seasons that share days: the store refuses to save the table' );
$meta[4]['glc_pricing'] = $overlap;
$quote = GLC_Pricing::quote( 4, '2027-07-05', '2027-07-06' );
check( is_array( $quote ) && 54.0 === $quote['total'] && [ 'Jul 03 - Aug 02' ] === array_keys( $quote['breakdown'] ), 'Given such a table anyway, the engine prices a shared day at the first season\'s rate' );

/* ------------------------------------------ 9. bands and rounding */

reset_world();
$bands = [];
for ( $days = 1; $days <= 45; $days++ ) { $bands[ GLC_Pricing::tier_for_days( $days ) ][] = $days; }
check( [ 'd1_2' => [ 1, 2 ], 'd3_4' => [ 3, 4 ], 'd5_7' => [ 5, 7 ], 'd8_12' => [ 8, 12 ], 'd13_18' => [ 13, 18 ], 'd19_30' => [ 19, 30 ], 'd31p' => [ 31, 45 ] ] === array_map( fn( $d ) => [ min( $d ), max( $d ) ], $bands ), 'The seven rental-length bands start and end where they did' );
$cents = table();
$cents[4]['rates']['d3_4'] = 10.004;
$meta[4]['glc_pricing']    = $cents;
$quote = GLC_Pricing::quote( 4, '2027-05-01', '2027-05-04' );
check( is_array( $quote ) && 30.01 === $quote['total'] && 10.0 === $quote['per_day_avg'], 'Days are summed first and rounded once: 3 × 10.004 is 30.01, not 30.00' );
reset_world();
$quote = GLC_Pricing::quote( 4, '2027-06-28', '2027-07-08' );
check( is_array( $quote ) && 'd8_12' === $quote['tier'] && 10 === $quote['days'] && 5 * 38.0 + 5 * 46.0 === $quote['total'] && [ 'Jun 13 - Jul 02' => [ 'days' => 5, 'rate' => 38.0 ], 'Jul 03 - Aug 02' => [ 'days' => 5, 'rate' => 46.0 ] ] === $quote['breakdown'], 'A rental over two seasons prices each day at its own season, in the band of the whole rental' );

/* -------------------------------------------------- 10. nothing but numbers */

reset_world();
$meta[4]['glc_delivery_fees']    = [ 'kutaisi_airport' => '95' ];
$meta[4]['glc_out_of_hours_fee'] = '25';
$meta[4]['glc_price_from']       = '50';
$read  = [];
$quote = q( [ 'pickup' => 'kutaisi_airport', 'return' => 'batumi_airport', 'pickup_time' => '08:00', 'return_time' => '21:00' ] );
$keys  = array_keys( $read );
sort( $keys );
check( is_array( $quote ) && [ 'glc_delivery_fees', 'glc_out_of_hours_fee', 'glc_pricing' ] === $keys, 'A quote reads three things about a car: its season table and its two fee overrides. Not its headline price, title or text (' . implode( ', ', $keys ) . ')' );
$source = (string) file_get_contents( $inc . '/class-glc-pricing.php' ) . preg_replace( '/public static function redirect_duplicate_fleet.*/s', '', (string) file_get_contents( $inc . '/class-glc-rental.php' ) );
check( ! preg_match( '/post_content|post_excerpt|post_title|get_the_title|get_post\s*\(/', $source ), 'Neither engine file touches a post\'s title or text' );

/* ------------------------------------ 11. two checks on every total */

reset_world();
$meta[5]['glc_delivery_fees']    = [ 'batumi_airport' => '120', 'tbilisi_airport' => '0' ];
$meta[5]['glc_out_of_hours_fee'] = '18.5';
$ok    = true;
$count = 0;
foreach ( [ 4, 5 ] as $car ) {
	foreach ( [ [ '2027-05-01', 1 ], [ '2027-01-10', 2 ], [ '2027-03-30', 4 ], [ '2027-06-10', 7 ], [ '2027-07-01', 10 ], [ '2027-07-28', 15 ], [ '2027-08-25', 25 ], [ '2027-10-20', 40 ], [ '2026-12-20', 21 ], [ '2028-02-20', 14 ] ] as [ $from, $days ] ) {
		foreach ( [ [ 'tbilisi_office', 'tbilisi_office' ], [ 'tbilisi_airport', 'batumi_airport' ], [ 'tbilisi_city', 'kutaisi_airport' ] ] as [ $pickup, $return ] ) {
			foreach ( [ [ null, null ], [ '07:45', '22:10' ] ] as [ $pt, $rt ] ) {
				$to    = gmdate( 'Y-m-d', strtotime( $from . ' +' . $days . ' days UTC' ) );
				$quote = q( [ 'car' => $car, 'from' => $from, 'to' => $to, 'pickup' => $pickup, 'return' => $return, 'pickup_time' => $pt, 'return_time' => $rt ] );
				++$count;
				if ( ! is_array( $quote ) ) { $ok = false; continue; }
				// Path one: the lines the customer is shown add up to the total.
				$lines = [ $quote['rental_total'], $quote['pickup_fee'], $quote['return_fee'], $quote['pickup_time_fee'], $quote['return_time_fee'] ];
				// Path two: this file's own sum from the table, the settings and the car's overrides.
				$own_fee  = static fn( string $place ) => 'tbilisi_office' === $place ? 0.0 : (float) ( $meta[ $car ]['glc_delivery_fees'][ $place ] ?? $settings[ [ 'tbilisi_city' => 'tbilisi_city_each_way', 'tbilisi_airport' => 'tbilisi_airport_each_way', 'kutaisi_airport' => 'kutaisi_each_way', 'batumi_airport' => 'batumi_each_way' ][ $place ] ] );
				$own_time = null === $pt ? 0.0 : 2 * (float) ( $meta[ $car ]['glc_out_of_hours_fee'] ?? $settings['out_of_hours_fee'] );
				$own      = round( recompute( table(), $from, $days ) + $own_fee( $pickup ) + $own_fee( $return ) + $own_time, 2 );
				$ok       = $ok && abs( array_sum( $lines ) - $quote['total'] ) < 0.005 && abs( $own - $quote['total'] ) < 0.005
					&& abs( $quote['rental_total'] - array_sum( array_map( fn( $b ) => $b['days'] * $b['rate'], $quote['breakdown'] ) ) ) < 0.005;
			}
		}
	}
}
check( $ok && 120 === $count, 'In 120 quotes the total equals the sum of its lines, the sum of its day-by-day breakdown, and this file\'s own recomputation' );

/* ------------------------------- what the pages say about a fee */

reset_world();
$sum = static fn( string $place ): array => array_values( array_intersect_key( GLC_Rental::fee_summary( $place ), [ 'state' => 1, 'fee' => 1 ] ) );
check( [ 'priced', 80.0 ] === $sum( 'kutaisi_airport' ) && [ 'free', 0.0 ] === $sum( 'tbilisi_office' ) && '$80' === GLC_Rental::fee_shortcode( [ 'place' => 'kutaisi_airport' ] ), 'No car has a fee of its own: the site says the site fee, as before' );
$meta[4]['glc_delivery_fees'] = [ 'kutaisi_airport' => '95' ];
check( [ 'from', 80.0 ] === $sum( 'kutaisi_airport' ) && 'from $80' === GLC_Rental::fee_shortcode( [ 'place' => 'kutaisi_airport' ] ) && [ 'priced', 100.0 ] === $sum( 'batumi_airport' ), 'One car charges 95 and one 80: "from $80", the lowest among the cars; other places unchanged' );
$car_page = 4;
check( '$95' === GLC_Rental::fee_shortcode( [ 'place' => 'kutaisi_airport' ] ) && '$100' === GLC_Rental::fee_shortcode( [ 'place' => 'batumi_airport' ] ), 'On that car\'s own page the fee is its own, exact' );
$car_page = 5;
check( '$80' === GLC_Rental::fee_shortcode( [ 'place' => 'kutaisi_airport' ] ), 'On the other car\'s page it is the site fee, exact' );
$car_page = 0;
$meta[5]['glc_delivery_fees'] = [ 'kutaisi_airport' => '95' ];
check( [ 'priced', 95.0 ] === $sum( 'kutaisi_airport' ), 'Every car at the same amount: one price again' );
$meta[5]['glc_delivery_fees'] = [ 'kutaisi_airport' => '0' ];
check( [ 'free_or_from', 95.0 ] === $sum( 'kutaisi_airport' ) && 'Free or from $95' === GLC_Rental::fee_shortcode( [ 'place' => 'kutaisi_airport' ] ), 'One car free and one at 95: "Free or from $95", never "from $0"' );
$meta[4]['glc_delivery_fees'] = [ 'kutaisi_airport' => '0' ];
check( [ 'free', 0.0 ] === $sum( 'kutaisi_airport' ) && 'Free' === GLC_Rental::fee_shortcode( [ 'place' => 'kutaisi_airport' ] ), 'Every car free: "Free"' );
reset_world();
$settings['kutaisi_each_way'] = '';
$meta[4]['glc_delivery_fees'] = [ 'kutaisi_airport' => '95' ];
check( 'unknown' === GLC_Rental::fee_summary( 'kutaisi_airport' )['state'] && 'Confirm with us' === GLC_Rental::fee_shortcode( [ 'place' => 'kutaisi_airport' ] ), 'One car unknown: "Confirm with us", whatever the others charge' );
check( '' === GLC_Rental::fee_shortcode( [ 'place' => 'nowhere' ] ), 'A place that does not exist prints nothing' );
$fleet = [];
check( 'unknown' === GLC_Rental::fee_summary( 'kutaisi_airport' )['state'] && [ 'priced', 100.0 ] === $sum( 'batumi_airport' ), 'With no published car the site\'s own fee is the answer' );

reset_world();
$pair = static fn( string $a, string $b ): array => array_values( array_intersect_key( GLC_Rental::pair_summary( $a, $b ), [ 'state' => 1, 'fee' => 1 ] ) );
check( [ 'priced', 180.0 ] === $pair( 'kutaisi_airport', 'batumi_airport' ) && [ 'priced', 160.0 ] === $pair( 'kutaisi_airport', 'kutaisi_airport' ) && [ 'free', 0.0 ] === $pair( 'tbilisi_office', 'tbilisi_office' ), 'A pickup and a return together, no car with its own fee: the two site fees' );
$meta[4]['glc_delivery_fees'] = [ 'kutaisi_airport' => '50', 'batumi_airport' => '100' ];
$meta[5]['glc_delivery_fees'] = [ 'kutaisi_airport' => '80', 'batumi_airport' => '60' ];
check( [ 'from', 140.0 ] === $pair( 'kutaisi_airport', 'batumi_airport' ), 'Two cars at 50+100 and 80+60: "from" the cheapest pair one car really charges (140), not the two lowest fees of different cars added (110)' );

reset_world();
$hours = static fn(): array => array_values( array_intersect_key( GLC_Rental::out_of_hours_summary(), [ 'state' => 1, 'fee' => 1 ] ) );
check( [ 'priced', 15.0 ] === $hours(), 'Early/late fee across the cars, none with its own amount: the setting' );
$meta[4]['glc_out_of_hours_fee'] = '25';
check( [ 'from', 15.0 ] === $hours(), 'One car at 25: "from" 15' );
$meta[5]['glc_out_of_hours_fee'] = '0';
check( [ 'free_or_from', 25.0 ] === $hours(), 'One free and one at 25: free or from 25' );
$settings['out_of_hours_fee'] = '';
unset( $meta[5]['glc_out_of_hours_fee'] );
check( 'unknown' === GLC_Rental::out_of_hours_summary()['state'], 'One car with no amount and the setting empty: unknown' );

/* The sentences machine clients read, in English. */
reset_world();
$delivery = 'Handover at the Tbilisi office (Mtatsminda) is free. Delivery or collection is charged per direction: Tbilisi city address $10, Tbilisi Airport (TBS) $20, Kutaisi (KUT airport or city) $80, Batumi (BUS airport or city) $100. A pickup and a return at the same place are charged twice; the quote shows them separately.';
$early    = 'Pickup or return before 10:00 or after 20:00 is charged $15 per handover, at the office too.';
check( $delivery === GLC_Rental::delivery_sentence(), 'The delivery sentence is word for word what it was' );
check( $early === GLC_Rental::out_of_hours_sentence() && $delivery . ' ' . $early === GLC_Rental::fee_sentence(), 'The fee sentence is the delivery sentence plus one sentence on early or late handover, in the terms the quote charges' );
$settings['handover_window_start'] = '08:30';
$settings['out_of_hours_fee']      = '17.50';
check( 'Pickup or return before 08:30 or after 20:00 is charged $17.50 per handover, at the office too.' === GLC_Rental::out_of_hours_sentence(), 'The sentence follows the settings, cents included' );
reset_world();
$meta[4]['glc_out_of_hours_fee'] = '25';
$meta[4]['glc_delivery_fees']    = [ 'kutaisi_airport' => '95', 'batumi_airport' => '0' ];
check( str_contains( GLC_Rental::out_of_hours_sentence(), 'is charged from $15 per handover depending on the car' ) && str_contains( GLC_Rental::delivery_sentence(), 'Kutaisi (KUT airport or city) from $80' ) && str_contains( GLC_Rental::delivery_sentence(), 'Batumi (BUS airport or city) free or from $100 depending on the car' ), 'Cars differ: "from $15", "from $80", "free or from $100 depending on the car"' );
check( str_contains( GLC_Rental::fee_sentence( 4 ), 'Kutaisi (KUT airport or city) $95' ) && str_contains( GLC_Rental::fee_sentence( 4 ), 'also free at: Batumi (BUS airport or city)' ) && str_contains( GLC_Rental::fee_sentence( 4 ), 'is charged $25 per handover' ), 'For one car the sentence is exact: its own 95, its own free Batumi, its own 25' );
reset_world();
$settings['out_of_hours_fee'] = '';
$settings['batumi_each_way']  = '';
check( '' === GLC_Rental::out_of_hours_sentence() && ! str_contains( GLC_Rental::fee_sentence(), 'Batumi' ) && ! str_contains( GLC_Rental::fee_sentence(), 'before 10:00' ), 'An undecided fee is left out of the sentence: no zero, no "free"' );
$settings['out_of_hours_fee'] = '0';
check( 'Pickup or return before 10:00 or after 20:00 carries no extra charge.' === GLC_Rental::out_of_hours_sentence(), 'A fee the owner set to 0 is said in words' );
$all = '';
foreach ( [ 0, 4 ] as $page ) {
	$car_page = $page;
	foreach ( GLC_Rental::location_keys() as $place ) { $all .= GLC_Rental::fee_shortcode( [ 'place' => $place ] ) . ' | '; }
	$all .= GLC_Rental::fee_sentence( $page ?: null ) . ' | ' . GLC_Rental::out_of_hours_text( $page ?: null ) . ' | ';
}
$car_page = 0;
check( ! preg_match( '/\$0(?![.\d])/', $all ) && ! str_contains( strtolower( $all ), 'from $0' ), 'With fees at 0 or undecided, no surface prints "$0" or "from $0"' );

/* The amount shown beside the hours in the booking form and the rental facts. */
reset_world();
check( '$15' === GLC_Rental::out_of_hours_text() && '$15' === GLC_Rental::out_of_hours_text( 4 ), 'The early/late amount in words for a page: the setting' );
$meta[4]['glc_out_of_hours_fee'] = '25';
check( '$25' === GLC_Rental::out_of_hours_text( 4 ) && '$15' === GLC_Rental::out_of_hours_text( 5 ) && 'from $15' === GLC_Rental::out_of_hours_text(), 'Per car on a car page, summarised elsewhere' );
$meta[4]['glc_out_of_hours_fee'] = '0';
check( 'Free' === GLC_Rental::out_of_hours_text( 4 ) && 'Free or from $15' === GLC_Rental::out_of_hours_text(), 'A car at 0 reads "Free" on its page and "Free or from $15" elsewhere' );
reset_world();
$settings['out_of_hours_fee'] = '';
check( '' === GLC_Rental::out_of_hours_text() && '' === GLC_Rental::out_of_hours_text( 4 ), 'Undecided everywhere: nothing is printed, so the row is left out' );
$meta[5]['glc_out_of_hours_fee'] = '9';
check( 'Confirm with us' === GLC_Rental::out_of_hours_text() && '' === GLC_Rental::out_of_hours_text( 4 ) && '$9' === GLC_Rental::out_of_hours_text( 5 ), 'Decided for one car only: that car\'s page says it, the undecided car\'s page says nothing, the site says "Confirm with us"' );

/* Calendar input is shared by every quote client, including internal callers. */
reset_world();
foreach ( [ '2027-02-30', '2027-02-29', '2027-04-31', '0000-01-01', '2027-05-01T12:00:00', 'tomorrow' ] as $invalid ) {
	check( null === GLC_Pricing::quote( 4, $invalid, '2028-06-01' ), 'Refuse a non-calendar pickup date: ' . $invalid );
	check( null === GLC_Pricing::quote( 4, '2026-01-01', $invalid ), 'Refuse a non-calendar return date: ' . $invalid );
}

/* Large public date ranges must not consume one calculation per rental day. */
$meta[4]['glc_pricing'] = [ [ 'label' => 'Year', 'from' => '01-01', 'to' => '12-31', 'rates' => array_fill_keys( GLC_Pricing::TIERS, 31 ) ] ];
$began = microtime( true );
$wide = GLC_Pricing::quote( 4, '0001-01-01', '9999-12-31' );
check( is_array( $wide ) && 3652058 === $wide['days'] && 113213798.0 === $wide['total'], 'A full supported calendar range has its exact Gregorian day count and total' );
check( microtime( true ) - $began < 1.0, 'Even an extreme public date range takes less than a second' );
reset_world();
foreach ( [ [ '1999-12-29', '2001-03-02' ], [ '2099-02-27', '2101-03-02' ], [ '2399-02-27', '2401-03-02' ], [ '2028-02-29', '2032-03-01' ] ] as [ $from, $to ] ) {
	$wide = GLC_Pricing::quote( 4, $from, $to );
	check( is_array( $wide ) && recompute( table(), $from, $wide['days'] ) === $wide['total'] && array_sum( array_column( $wide['breakdown'], 'days' ) ) === $wide['days'], 'Multi-year seasonal totals and leap-day counts agree with independent daily calculation: ' . $from );
}

echo ( $failures ? "\n{$failures} FAILED\n" : "\nALL PASS\n" );
exit( $failures ? 1 : 0 );
