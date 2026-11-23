<?php
/**
 * Standalone checks for the settings added for car partners: every key has its
 * default, and a value that does not fit its field is not saved. No WordPress.
 *
 * Run: docker run --rm -v "<repo>:/src" php:8.3-cli php /src/tests/settings-fields.php
 */
define( 'ABSPATH', __DIR__ );
$failures = 0;
$stored   = [];
$said     = [];
function check( $condition, $label ) {
	if ( ! $condition ) { ++$GLOBALS['failures']; }
	echo ( $condition ? 'PASS ' : 'FAIL ' ) . $label . "\n";
}
function get_option( $name, $default = false ) { return 'glc_settings' === $name ? $GLOBALS['stored'] : $default; }
function sanitize_text_field( $value ) { return trim( strip_tags( (string) $value ) ); }
function sanitize_textarea_field( $value ) { return trim( strip_tags( (string) $value ) ); }
function add_settings_error( $setting, $code, $message ) { $GLOBALS['said'][ $code ] = $message; }
function get_current_user_id() { return 1; }
function get_user_meta( $id, $key, $single = false ) { return ''; }
function get_user_locale( $id = 0 ) { return 'en_US'; }
$includes = __DIR__ . '/../wp-content/plugins/geolander-core/includes';
function glc_ui( $key ) { return $key; }
require $includes . '/class-glc-admin-strings.php';
require $includes . '/class-glc-rental.php';
require $includes . '/class-glc-quote-check.php';
require $includes . '/class-glc-settings.php';
require $includes . '/class-glc-price-store.php';

$expected = [
	'handover_window_start'   => '10:00',
	'handover_window_end'     => '20:00',
	'out_of_hours_fee'        => '15',
	'handover_default_time'   => '12:00',
	'rate_min_plausible'      => '5',
	'rate_max_plausible'      => '300',
	'fee_max_plausible'       => '500',
	'price_grid_car_page'     => '0',
	'price_grid_long_term'    => '0',
	'partner_upload_max_mb'   => '10',
	'partner_image_min_width' => '1600',
	'partner_email_copy'      => '0',
	'display_timezone'        => 'Asia/Tbilisi',
	'preview_samples'         => "07-15 3\n08-01 7\n01-10 31\n08-01 7 kutaisi_airport batumi_airport 09:00 21:00",
	'history_rows'            => '20',
	'counter_months'          => '12',
];
$wrong = [];
foreach ( $expected as $key => $value ) {
	if ( GLC_Settings::get( $key ) !== $value ) { $wrong[] = $key; }
}
check( ! $wrong, 'Every key the design names has its default while nothing is stored' . ( $wrong ? ': ' . implode( ', ', $wrong ) : '' ) );
check( '24' === GLC_Settings::get( 'season_max_count' ), 'The season limit has a default too' );
$stored = [ 'out_of_hours_fee' => '', 'rate_max_plausible' => '400' ];
check( '' === GLC_Settings::get( 'out_of_hours_fee' ) && '400' === GLC_Settings::get( 'rate_max_plausible' ), 'A stored value wins over the default, and a stored empty fee stays empty (unknown), not 15' );
$stored = [];

/** What saving the form with these fields would store, and what the screen would say. */
function save( array $fields ): array {
	$GLOBALS['said'] = [];
	$defaults        = ( new ReflectionClass( 'GLC_Settings' ) )->getConstant( 'DEFAULTS' );
	return GLC_Settings::sanitize( $fields + ( $GLOBALS['stored'] ?: $defaults ) );
}

$clean = save( [ 'handover_window_start' => '08:30', 'handover_window_end' => '22:15', 'out_of_hours_fee' => '17.50', 'handover_default_time' => '09:00', 'rate_max_plausible' => '', 'season_max_count' => '30', 'price_grid_car_page' => '1', 'partner_email_copy' => '1' ] );
check( [ '08:30', '22:15', '17.50', '09:00', '', '30', '1', '1' ] === [ $clean['handover_window_start'], $clean['handover_window_end'], $clean['out_of_hours_fee'], $clean['handover_default_time'], $clean['rate_max_plausible'], $clean['season_max_count'], $clean['price_grid_car_page'], $clean['partner_email_copy'] ] && ! $said, 'Well-formed values are saved as typed, an emptied limit stays empty' );
check( '0' === save( [ 'out_of_hours_fee' => '0' ] )['out_of_hours_fee'] && '' === save( [ 'out_of_hours_fee' => '' ] )['out_of_hours_fee'], 'The handover fee keeps its three meanings: a number, zero, empty' );

$stored = $clean;
foreach ( [
	'handover_window_start'   => [ '9:00', 'time without a leading zero' ],
	'handover_window_end'     => [ '24:00', 'an hour that does not exist' ],
	'handover_default_time'   => [ 'noon', 'a word for a time' ],
	'out_of_hours_fee'        => [ 'fifteen', 'a word for an amount' ],
	'rate_min_plausible'      => [ '-5', 'a negative limit' ],
	'fee_max_plausible'       => [ '10.555', 'three decimals' ],
	'season_max_count'        => [ '2.5', 'a fraction for a count' ],
	'partner_image_min_width' => [ '1600px', 'a unit in a number' ],
	'price_grid_long_term'    => [ 'yes', 'a third choice for an on/off field' ],
	'display_timezone'        => [ 'Tbilisi time', 'a time zone that is not a region and a city' ],
	'preview_samples'         => [ "07-15 3\n02-30 4", 'a sample that starts on a day that does not exist' ],
	'history_rows'            => [ 'all', 'a word for a number of rows' ],
	'counter_months'          => [ '-1', 'a negative number of months' ],
] as $key => [ $bad, $why ] ) {
	$after = save( [ $key => $bad ] );
	check( $after[ $key ] === $stored[ $key ] && 1 === count( $said ) && str_contains( reset( $said ), glc_admin_t( 'setting_' . $key ) ), "Not saved, the earlier value stays and the screen names the field: {$why}" );
}
check( '09:00' === save( [ 'handover_default_time' => '' ] )['handover_default_time'], 'A time cannot be emptied: the earlier one stays' );

$after = save( [ 'handover_window_start' => '21:00', 'handover_window_end' => '09:00' ] );
check( '08:30' === $after['handover_window_start'] && '22:15' === $after['handover_window_end'] && isset( $said['glc_handover_window'] ), 'Hours that end before they start are not saved' );
$after = save( [ 'handover_window_start' => '10:00', 'handover_window_end' => '10:00' ] );
check( '08:30' === $after['handover_window_start'] && isset( $said['glc_handover_window'] ), 'Nor are hours of zero length' );

foreach ( [ '07-15', '07-15 0', '07-15 3 moon_base tbilisi_office', '07-15 3 tbilisi_office', '07-15 3 tbilisi_office tbilisi_office 9:00 21:00', '7-15 3' ] as $bad ) {
	check( save( [ 'preview_samples' => $bad ] )['preview_samples'] === $stored['preview_samples'] && 1 === count( $said ), "A sample line that cannot be read is not saved: \"{$bad}\"" );
}
$after = save( [ 'display_timezone' => 'Europe/Berlin', 'preview_samples' => "12-30 5\r\n\r\n06-01 2 tbilisi_airport tbilisi_office 08:00 12:00", 'history_rows' => '', 'counter_months' => '6' ] );
check( 'Europe/Berlin' === $after['display_timezone'] && '' === $after['history_rows'] && '6' === $after['counter_months'] && ! $said, 'A real time zone, an emptied row limit and another number of months are saved' );
$read = GLC_Quote_Check::parse_samples( $after['preview_samples'] );
check( 2 === count( $read ) && [ '12-30', 5, 'tbilisi_office', 'tbilisi_office', null, null ] === array_values( $read[0] ) && [ '06-01', 2, 'tbilisi_airport', 'tbilisi_office', '08:00', '12:00' ] === array_values( $read[1] ), 'Sample lines are read as start, days, places and times; places and times are optional' );
check( [] === GLC_Quote_Check::parse_samples( '' ) && '' === save( [ 'preview_samples' => '' ] )['preview_samples'], 'No samples at all is allowed: the preview then shows none' );
$stored = [ 'display_timezone' => 'Asia/Tbilisi' ];
check( '2026-10-12 16:59' === GLC_Settings::local_time( '2026-10-12 12:59:07' ) && '2026-10-12 16:59' === GLC_Settings::local_time( gmmktime( 12, 59, 7, 10, 12, 2026 ) ) && '2026-11' === GLC_Settings::local_time( '2026-10-31 21:30:00', 'Y-m' ), 'A time is written in the display time zone, from the database\'s GMT or from a Unix time; 21:30 UTC on 31 Oct is already November in Tbilisi' );
$stored = [ 'display_timezone' => 'Nowhere/Land' ];
check( 'Asia/Tbilisi' === GLC_Settings::timezone()->getName(), 'A stored time zone that PHP does not know falls back to the default instead of failing' );

// The limits of the bulk preview and the Excel upload: whole numbers above zero, never empty (an empty limit would be no limit).
$stored   = [];
$defaults = ( new ReflectionClass( 'GLC_Settings' ) )->getConstant( 'DEFAULTS' );
check( [ '30', '100', '2048', '5000', '50' ] === array_map( fn( $key ) => $defaults[ $key ], [ 'plan_keep_minutes', 'xlsx_max_kb', 'xlsx_max_part_kb', 'xlsx_max_rows', 'xlsx_max_reasons' ] ), 'A preview waits 30 minutes; an upload may be 100 KB, a sheet 2,048 KB once opened and 5,000 rows; 50 reasons are listed' );
check( (int) $defaults['xlsx_max_kb'] * 1024 < 131072, 'The upload limit a person is told is under the 128 KB the web server allows on admin-post.php' );
foreach ( [ 'plan_keep_minutes', 'xlsx_max_kb', 'xlsx_max_part_kb', 'xlsx_max_rows', 'xlsx_max_reasons' ] as $key ) {
	foreach ( [ '', '0', '-5', '1.5', 'many' ] as $bad ) {
		$after = save( [ $key => $bad ] );
		if ( $after[ $key ] !== $defaults[ $key ] || 1 !== count( $said ) ) { check( false, "{$key}: \"{$bad}\" must not be saved" ); }
	}
	check( '77' === save( [ $key => '77' ] )[ $key ] && ! $said, "{$key}: empty, zero, a negative, a fraction and a word are refused with a reason; a whole number above zero is saved" );
}

// Photos and details (leg L5): the kinds of image, the gallery and alt limits, and what a car's details may hold.
$stored = [];
check( [ 'jpeg, png, webp', '40', '125', '1', '9', 'automatic, manual', 'gasoline, diesel, hybrid, electric', '12', '24', '4', '200' ] === array_map( fn( $key ) => $defaults[ $key ], [ 'partner_image_types', 'gallery_max_photos', 'photo_alt_max_chars', 'spec_seats_min', 'spec_seats_max', 'spec_transmissions', 'spec_fuel_types', 'spec_drivetrain_max_chars', 'spec_color_max_chars', 'spec_license_max_chars', 'readonly_excerpt_chars' ] ), 'Photos: jpeg, png, webp, 40 to a gallery, 125 characters of description. Details: 1 to 9 seats, two gearboxes, four fuels, 12, 24 and 4 characters. 200 characters of description shown' );
check( [ 'jpeg', 'png', 'webp' ] === GLC_Settings::words( 'partner_image_types' ) && [ 'image/jpeg', 'image/png', 'image/webp' ] === GLC_Price_Store::image_types(), 'A list is read as its words, and the kinds of image as WordPress names them' );
$stored = [ 'partner_image_types' => ' JPEG,  png ,jpeg ' ];
check( [ 'jpeg', 'png' ] === GLC_Settings::words( 'partner_image_types' ), 'Capitals, spaces and a word written twice do not matter when a list is read' );
$stored = [];
check( 40 === GLC_Settings::whole( 'gallery_max_photos' ) && 1600 === GLC_Settings::whole( 'partner_image_min_width' ), 'A whole-number setting is read as a number' );
$stored = [ 'gallery_max_photos' => '', 'partner_image_min_width' => 'wide' ];
check( null === GLC_Settings::whole( 'gallery_max_photos' ) && null === GLC_Settings::whole( 'partner_image_min_width' ), 'An emptied limit, or one that is not a number, is no limit' );
$stored = [];
foreach ( [ 'partner_image_types', 'spec_transmissions', 'spec_fuel_types' ] as $key ) {
	foreach ( [ '', 'JPEG', 'jpeg png', 'jpeg,,png', 'image/jpeg', ', jpeg' ] as $bad ) {
		$after = save( [ $key => $bad ] );
		if ( $after[ $key ] !== $defaults[ $key ] || 1 !== count( $said ) ) { check( false, "{$key}: \"{$bad}\" must not be saved" ); }
	}
	check( 'one, two-three,four_5' === save( [ $key => 'one, two-three,four_5' ] )[ $key ] && ! $said, "{$key}: an empty list, capitals, words without a comma, an empty word and a slash are refused with a reason; words in small letters separated by commas are saved" );
}
foreach ( [ 'photo_alt_max_chars', 'spec_seats_min', 'spec_seats_max', 'spec_drivetrain_max_chars', 'spec_color_max_chars', 'spec_license_max_chars', 'readonly_excerpt_chars' ] as $key ) {
	$refused = 0;
	foreach ( [ '', '0', '-1', '2.5', 'few' ] as $bad ) {
		$after    = save( [ $key => $bad ] );
		$refused += $after[ $key ] === $defaults[ $key ] && 1 === count( $said ) ? 1 : 0;
	}
	check( 5 === $refused, "{$key}: empty, zero, a negative, a fraction and a word are refused with a reason" );
}
check( '' === save( [ 'gallery_max_photos' => '' ] )['gallery_max_photos'] && ! $said && '12' === save( [ 'gallery_max_photos' => '12' ] )['gallery_max_photos'], 'The gallery limit can be emptied (no limit) or set to another whole number' );
$after = save( [ 'spec_seats_min' => '8', 'spec_seats_max' => '5' ] );
check( '1' === $after['spec_seats_min'] && '9' === $after['spec_seats_max'] && isset( $said['glc_spec_seats'] ), 'Fewest seats above most seats is not saved, and the screen says so' );
$after = save( [ 'spec_seats_min' => '2', 'spec_seats_max' => '2' ] );
check( '2' === $after['spec_seats_min'] && '2' === $after['spec_seats_max'] && ! $said, 'The same number for fewest and most seats is saved' );
check( [] === ( new ReflectionClass( 'GLC_Settings' ) )->getConstant( 'NOT_READ_YET' ), 'No setting is marked "not in use yet" any more: the two photo limits are read' );

$stored = [];
$after  = save( [ 'whatsapp_number' => '+995 551 33 04 14', 'redirects' => "/a/ -> /b/\n/c/ -> /d/" ] );
check( '+995 551 33 04 14' === $after['whatsapp_number'] && str_contains( $after['redirects'], "\n" ) && ! $said, 'The fields that were there before are saved exactly as before' );

echo "\n" . ( $failures ? "{$failures} FAILED" : 'ALL PASS' ) . "\n";
exit( $failures ? 1 : 0 );
