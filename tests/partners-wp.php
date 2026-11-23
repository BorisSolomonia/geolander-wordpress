<?php
/**
 * Partners, message routing and the per-car screen against a real WordPress and
 * a real database: what tests/partner-authority.mjs cannot reach over HTTP, or
 * is better asked from inside.
 *
 * LOCAL COPY ONLY. This creates and deletes a partner account, assigns cars,
 * makes booking requests and writes to the change log. Put the database back
 * afterwards (reset script).
 *
 * Run: docker exec -i <wordpress container> wp eval-file - --allow-root < tests/partners-wp.php
 *
 * Every "is it stored" question is answered with SQL, not with the functions
 * that wrote it.
 */

if ( ! defined( 'ABSPATH' ) || ! defined( 'WP_CLI' ) ) {
	exit( "Run via wp eval-file\n" );
}
if ( 'production' === wp_get_environment_type() ) {
	WP_CLI::error( 'This test writes to cars, users and booking requests. It does not run on a production site.' );
}

global $wpdb;
$glc_failures = 0;
$glc_check    = static function ( $condition, string $label ) use ( &$glc_failures ): void {
	if ( ! $condition ) {
		++$glc_failures;
	}
	WP_CLI::log( ( $condition ? 'PASS ' : 'FAIL ' ) . $label );
};
/** One meta value exactly as the database holds it; null when there is no row. */
$glc_post_raw = static fn( int $id, string $key ) => $wpdb->get_var( $wpdb->prepare( "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s", $id, $key ) );
$glc_user_raw = static fn( int $id, string $key ) => $wpdb->get_var( $wpdb->prepare( "SELECT meta_value FROM {$wpdb->usermeta} WHERE user_id = %d AND meta_key = %s", $id, $key ) );
$glc_entries  = static fn(): int => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s", GLC_Change_Log::POST_TYPE ) );
$glc_last     = static function () use ( $wpdb, $glc_post_raw ): array {
	$row = (array) $wpdb->get_row( $wpdb->prepare( "SELECT ID, post_author, post_title, post_date_gmt FROM {$wpdb->posts} WHERE post_type = %s ORDER BY ID DESC LIMIT 1", GLC_Change_Log::POST_TYPE ), ARRAY_A );
	foreach ( [ 'glc_car_id', 'glc_kind', 'glc_source', 'glc_note', 'glc_before', 'glc_after' ] as $key ) {
		$row[ $key ] = $glc_post_raw( (int) ( $row['ID'] ?? 0 ), $key );
	}
	return $row;
};
/** A booking through the public REST route, as the booking form sends it. */
$glc_checkout = static function ( int $car_id ): array {
	$request = new WP_REST_Request( 'POST', '/geolander/v1/checkout' );
	$request->set_header( 'content-type', 'application/json' );
	$request->set_body( wp_json_encode( [
		'car'    => $car_id,
		'from'   => gmdate( 'Y-m-d', time() + 40 * DAY_IN_SECONDS ),
		'to'     => gmdate( 'Y-m-d', time() + 43 * DAY_IN_SECONDS ),
		'pickup' => 'tbilisi_office',
		'return' => 'tbilisi_office',
		'name'   => 'Qwz Customer',
		'email'  => 'qwz-customer@example.test',
	] ) );
	$data = (array) rest_do_request( $request )->get_data();
	parse_str( (string) wp_parse_url( (string) ( $data['redirect'] ?? '' ), PHP_URL_QUERY ), $query );
	return $data + [ 'phone' => (string) ( $query['phone'] ?? '' ), 'post_id' => (int) substr( (string) ( $data['reference'] ?? 'GL-1000' ), 3 ) - 1000 ];
};
delete_option( 'glc_checkout_rate_limit' );

$glc_owner = (int) ( get_users( [ 'role' => 'administrator', 'number' => 1, 'orderby' => 'ID', 'fields' => 'ID' ] )[0] ?? 0 );
$glc_plain = [];
$glc_legacy = 0;
foreach ( array_map( 'intval', get_posts( [ 'post_type' => 'car', 'post_status' => 'publish', 'posts_per_page' => -1, 'orderby' => 'ID', 'order' => 'ASC', 'fields' => 'ids' ] ) ) as $glc_id ) {
	if ( '' !== trim( (string) get_post_meta( $glc_id, 'glc_whatsapp_number', true ) ) ) {
		$glc_legacy = $glc_legacy ?: $glc_id;
	} elseif ( ! GLC_Partners::partner_of( $glc_id ) ) {
		$glc_plain[] = $glc_id;
	}
}
if ( ! $glc_owner || count( $glc_plain ) < 3 || ! $glc_legacy ) {
	WP_CLI::error( 'Needs an administrator, three unassigned published cars without a number of their own, and one car with one.' );
}
[ $glc_a, $glc_b, $glc_c ] = $glc_plain;
$glc_site   = (string) preg_replace( '/[^0-9]/', '', (string) GLC_Settings::get( 'whatsapp_number' ) );
$glc_number = (string) preg_replace( '/[^0-9]/', '', (string) get_post_meta( $glc_legacy, 'glc_whatsapp_number', true ) );

/* ------------------------------------------------------------- the partner */

$glc_old = get_user_by( 'login', 'glc_test_wp_partner' );
if ( $glc_old ) {
	require_once ABSPATH . 'wp-admin/includes/user.php';
	wp_delete_user( $glc_old->ID );
}
$glc_partner = (int) wp_insert_user( [ 'user_login' => 'glc_test_wp_partner', 'user_pass' => wp_generate_password( 32 ), 'user_email' => 'glc_test_wp_partner@example.test', 'display_name' => 'Wp Test Partner', 'role' => GLC_Partners::ROLE ] );
wp_set_current_user( $glc_owner );

$glc_check( null === GLC_Partners::clean_phone( '995555000505' ) && null === GLC_Partners::clean_phone( '+9955' ) && null === GLC_Partners::clean_phone( '+99555500050512345678' ) && null === GLC_Partners::clean_phone( '+995 555 abc' ), 'A phone without a plus, too short, too long or with letters is not a phone' );
$glc_check( '+995555000505' === GLC_Partners::clean_phone( ' +995 (555) 000-505 ' ) && '' === GLC_Partners::clean_phone( '' ), 'Spaces, brackets and dashes are dropped; an empty phone is allowed and means none' );
$glc_count = $glc_entries();
GLC_Partners::save_phone( $glc_partner, '+995555000505' );
$glc_row = $glc_last();
$glc_check( '+995555000505' === $glc_user_raw( $glc_partner, 'glc_partner_phone' ), 'The phone is stored on the user as plain text with its plus: ' . var_export( $glc_user_raw( $glc_partner, 'glc_partner_phone' ), true ) );
$glc_check( $glc_entries() === $glc_count + 1 && 'profile' === $glc_row['glc_kind'] && '{"user":' . $glc_partner . ',"phone":""}' === $glc_row['glc_before'] && '{"user":' . $glc_partner . ',"phone":"+995555000505"}' === $glc_row['glc_after'], 'A changed phone is logged with the number before and after' );
$glc_check( ! GLC_Partners::save_phone( $glc_partner, '+995555000505' ) && $glc_entries() === $glc_count + 1, 'Saving the same phone again logs nothing' );

/* ------------------------------------------------------------ assignment */

$glc_refused = GLC_Partners::assign( [ $glc_a => $glc_partner, $glc_b => $glc_owner ] );
$glc_check( is_wp_error( $glc_refused ) && null === $glc_post_raw( $glc_a, 'glc_partner_id' ), 'One account in a request that is not a partner: nothing is assigned, the valid row included' );
$glc_refused = GLC_Partners::assign( [ $glc_a => $glc_partner, $glc_owner + 100000 => $glc_partner ] );
$glc_check( is_wp_error( $glc_refused ) && null === $glc_post_raw( $glc_a, 'glc_partner_id' ), 'One id in a request that is not a car: nothing is assigned' );
$glc_count = $glc_entries();
$glc_done  = GLC_Partners::assign( [ $glc_a => $glc_partner, $glc_b => $glc_partner, $glc_c => 0, $glc_legacy => $glc_partner ] );
$glc_check( [ $glc_a, $glc_b, $glc_legacy ] === $glc_done && (string) $glc_partner === $glc_post_raw( $glc_a, 'glc_partner_id' ) && null === $glc_post_raw( $glc_c, 'glc_partner_id' ) && $glc_entries() === $glc_count + 3, 'Three cars assigned, one row that changes nothing left alone: three log entries, the assignment stored as the user id' );
$glc_theirs_now = GLC_Partners::cars_for( $glc_partner );
sort( $glc_theirs_now );
$glc_expected = [ $glc_a, $glc_b, $glc_legacy ];
sort( $glc_expected );
$glc_check( GLC_Partners::can_manage( $glc_partner, $glc_a ) && ! GLC_Partners::can_manage( $glc_partner, $glc_c ) && $glc_expected === $glc_theirs_now, 'The partner may now change exactly those cars' );

/* --------------------------------------------------------------- routing */

$glc_check( [ 'number' => '995555000505', 'source' => 'partner', 'partner_id' => $glc_partner ] === GLC_Gateway_WhatsApp::route_for( $glc_a ), 'A car with a partner who has a phone: the partner' );
$glc_check( [ 'number' => '995555000505', 'source' => 'partner', 'partner_id' => $glc_partner ] === GLC_Gateway_WhatsApp::route_for( $glc_legacy ), 'The partner wins over a number kept on the car' );
$glc_check( [ 'number' => $glc_site, 'source' => 'site', 'partner_id' => 0 ] === GLC_Gateway_WhatsApp::route_for( $glc_c ) && [ 'number' => $glc_site, 'source' => 'site', 'partner_id' => 0 ] === GLC_Gateway_WhatsApp::route_for( null ), 'A car with neither, and a link that is about no car: the site number' );
$glc_check( GLC_Gateway_WhatsApp::number_for( $glc_c ) === $glc_site && str_contains( GLC_Gateway_WhatsApp::url( 'Hi' ), 'phone=' . rawurlencode( '+' . $glc_site ) . '&text=Hi&type=phone_number&app_absent=0' ), 'The site-wide WhatsApp links (footer, contact, city pages) are built exactly as before' );

$glc_to_partner = $glc_checkout( $glc_a );
$glc_check( '+995555000505' === $glc_to_partner['phone'] && '995555000505' === $glc_post_raw( $glc_to_partner['post_id'], 'glc_routed_number' ) && 'partner' === $glc_post_raw( $glc_to_partner['post_id'], 'glc_routing_source' ) && (string) $glc_partner === $glc_post_raw( $glc_to_partner['post_id'], 'glc_partner_id' ), 'Checkout for the partner\'s car: the link opens the partner\'s phone and the request records number, source and partner' );
$glc_check( ! str_contains( (string) $glc_to_partner['message'], 'Wp Test Partner' ) && ! str_contains( strtolower( (string) $glc_to_partner['message'] ), 'partner' ) && str_starts_with( (string) $glc_to_partner['message'], 'Booking request GL-' ), 'The customer\'s message does not name the partner' );
$glc_check( 'disabled' === $glc_post_raw( $glc_to_partner['post_id'], 'glc_partner_mail_status' ), 'The email copy to the partner is recorded as switched off' );

GLC_Partners::save_phone( $glc_partner, '' );
$glc_no_phone = $glc_checkout( $glc_a );
$glc_on_car   = $glc_checkout( $glc_legacy );
$glc_check( '+' . $glc_site === $glc_no_phone['phone'] && 'site' === $glc_post_raw( $glc_no_phone['post_id'], 'glc_routing_source' ) && '0' === $glc_post_raw( $glc_no_phone['post_id'], 'glc_partner_id' ) && null === $glc_post_raw( $glc_no_phone['post_id'], 'glc_partner_mail_status' ), 'The partner has no phone: the site number answers, and the request is not theirs' );
$glc_check( '+' . $glc_number === $glc_on_car['phone'] && 'car' === $glc_post_raw( $glc_on_car['post_id'], 'glc_routing_source' ) && $glc_number === $glc_post_raw( $glc_on_car['post_id'], 'glc_routed_number' ) && $glc_number !== $glc_site, 'The partner has no phone and the car keeps a number: the car\'s number answers (' . $glc_number . ', not the site\'s ' . $glc_site . ')' );
GLC_Partners::save_phone( $glc_partner, '+995555000505' );

$glc_settings = (array) get_option( 'glc_settings', [] );
update_option( 'glc_settings', [ 'partner_email_copy' => '1' ] + $glc_settings );
$glc_copy = $glc_checkout( $glc_b );
update_option( 'glc_settings', $glc_settings );
$glc_check( 'not_configured' === $glc_post_raw( $glc_copy['post_id'], 'glc_partner_mail_status' ), 'With the email copy switched on and no mail server, the request records "not_configured" and nothing is sent' );

/* ------------------------------------------------------------- the count */

// One request made at 21:30 UTC on the last day of last month: in Tbilisi that is already 01:30 on the first of this month.
$glc_this_month = GLC_Settings::local_time( time(), 'Y-m' );
$glc_last_month = gmdate( 'Y-m', strtotime( $glc_this_month . '-01 00:00:00 UTC' ) - DAY_IN_SECONDS );
$glc_edge_time  = gmdate( 'Y-m-d', strtotime( $glc_this_month . '-01 00:00:00 UTC' ) - DAY_IN_SECONDS ) . ' 21:30:00';
$glc_edge = (int) wp_insert_post( [ 'post_type' => 'booking_request', 'post_status' => 'publish', 'post_title' => 'GL-EDGE', 'post_date' => $glc_edge_time, 'post_date_gmt' => $glc_edge_time, 'meta_input' => [ 'glc_car_id' => $glc_a, 'glc_partner_id' => $glc_partner, 'glc_routed_number' => '995555000505', 'glc_routing_source' => 'partner', 'glc_name' => 'Qwz Customer', 'glc_email' => 'qwz-customer@example.test' ] ] );
$glc_old_request = (int) wp_insert_post( [ 'post_type' => 'booking_request', 'post_status' => 'publish', 'post_title' => 'GL-OLD', 'post_date' => '2024-03-01 10:00:00', 'post_date_gmt' => '2024-03-01 10:00:00', 'meta_input' => [ 'glc_car_id' => $glc_c ] ] );
wp_trash_post( $glc_no_phone['post_id'] );
$glc_counts = GLC_Partner_Admin::message_counts();
$glc_sql    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'booking_request' AND post_status <> 'auto-draft'" );
$glc_sum    = array_sum( array_map( static fn( array $row ): int => $row['cells']['total'], $glc_counts['rows'] ) );
$glc_theirs = $glc_counts['rows'][ 'partner:' . $glc_partner ] ?? [ 'cells' => [], 'cars' => [], 'numbers' => [] ];
$glc_check( $glc_sum === $glc_sql && GLC_Partner_Admin::records_count() === $glc_sql && $glc_counts['sum'] === $glc_sql, "The table's total ({$glc_sum}) equals a plain SQL count of the booking requests ({$glc_sql}), a binned request included" );
$glc_check( 3 === $glc_theirs['cells']['total'] && 3 === ( $glc_theirs['cells'][ $glc_this_month ] ?? -1 ) && 0 === ( $glc_theirs['cells'][ $glc_last_month ] ?? -1 ), "To the partner: three requests, all in {$glc_this_month}; the one made at {$glc_edge_time} UTC is counted in {$glc_this_month}, where it falls in Tbilisi, and not in {$glc_last_month}" );
$glc_check( [ '+995555000505' ] === array_keys( $glc_theirs['numbers'] ) && 2 === ( $glc_theirs['cars'][ $glc_a ]['total'] ?? 0 ) && 1 === ( $glc_theirs['cars'][ $glc_b ]['total'] ?? 0 ), 'The row says which number the requests went to, and breaks down by car' );
$glc_check( 1 === ( $glc_counts['rows'][ 'car:' . $glc_number ]['cells']['total'] ?? 0 ) && ( $glc_counts['rows']['site']['cells']['earlier'] ?? 0 ) >= 1 && ( $glc_counts['rows']['site']['cells']['total'] ?? 0 ) >= 2, 'A request to a number kept on a car has its own row; requests with no routing recorded (older ones) and to the site number are Geolander\'s; a 2024 request is under "earlier"' );

update_user_meta( $glc_owner, GLC_Admin_Strings::USER_META, 'ka' );
ob_start();
GLC_Partner_Admin::render();
$glc_screen = (string) ob_get_clean();
delete_user_meta( $glc_owner, GLC_Admin_Strings::USER_META );
$glc_text = html_entity_decode( wp_strip_all_tags( $glc_screen ) );
$glc_keys = array_filter( array_keys( GLC_Admin_Strings::catalogue( 'en' ) ), static fn( string $key ): bool => str_contains( $key, '_' ) && preg_match( '/(?<![A-Za-z0-9_])' . preg_quote( $key, '/' ) . '(?![A-Za-z0-9_])/', $glc_text ) );
$glc_check( ! $glc_keys && str_contains( $glc_screen, 'lang="ka"' ) && str_contains( $glc_text, glc_admin_t( 'count_heading', 'ka' ) ) && str_contains( $glc_text, glc_admin_t( 'assign_heading', 'ka' ) ), 'The Partners screen in Georgian: Georgian headings, no raw key' . ( $glc_keys ? ' (' . implode( ', ', $glc_keys ) . ')' : '' ) );
$glc_leaks = array_filter( [ 'Qwz Customer', 'qwz-customer', (string) $glc_to_partner['reference'], (string) $glc_copy['reference'], 'GL-EDGE', 'GL-OLD', 'Booking request', 'Final total' ], static fn( string $needle ): bool => str_contains( $glc_screen, $needle ) );
$glc_check( ! $glc_leaks && str_contains( $glc_screen, 'data-counted="' . $glc_sql . '"' ) && str_contains( $glc_screen, 'data-records="' . $glc_sql . '"' ), 'Its HTML holds the counts and no customer name, email, reference or message' . ( $glc_leaks ? ': LEAKED ' . implode( ', ', $glc_leaks ) : '' ) );
wp_delete_post( $glc_old_request, true );
ob_start();
add_filter( 'query', $glc_miss = static fn( string $sql ): string => str_contains( $sql, 'COUNT(*)' ) && str_contains( $sql, 'booking_request' ) ? str_replace( "<> 'auto-draft'", "= 'publish'", $sql ) : $sql );
GLC_Partner_Admin::render();
remove_filter( 'query', $glc_miss );
$glc_screen = (string) ob_get_clean();
$glc_check( 1 === preg_match( '/<p class="glc-fault" data-counted="(\d+)" data-records="(\d+)">/', $glc_screen, $glc_m ) && $glc_m[1] !== $glc_m[2] && str_contains( wp_strip_all_tags( $glc_screen ), 'They do not agree' ), 'When the two counts differ (the SQL count made to miss the binned request), the screen says so in red and shows both: ' . ( $glc_m[1] ?? '?' ) . ' against ' . ( $glc_m[2] ?? '?' ) );

/* ----------------------------------------------- the preview's two checks */

$glc_now  = GLC_Price_Store::state( $glc_a );
$glc_then = $glc_now;
$glc_then['pricing'][0]['rates']['d3_4'] = (float) $glc_then['pricing'][0]['rates']['d3_4'] + 10;
$glc_then['fees'] = [ 'delivery' => [ 'kutaisi_airport' => '55' ], 'out_of_hours' => '9' ];
$glc_md5  = md5( (string) $glc_post_raw( $glc_a, 'glc_pricing' ) );
$glc_rows = GLC_Quote_Check::rows( $glc_a, [ 'now' => $glc_now, 'then' => $glc_then ] );
$glc_check( 4 === count( $glc_rows ) && ! array_filter( $glc_rows, static fn( array $row ): bool => ! $row['now']['agree'] || ! $row['then']['agree'] || null === $row['now']['engine'] || null === $row['then']['engine'] ), 'Four sample rentals, each priced by the engine and by the separate recompute, for the saved state and for an unsaved one: every pair agrees' );
$glc_direct = GLC_Rental::quote( $glc_a, $glc_rows[0]['sample']['from'], $glc_rows[0]['sample']['to'], 'tbilisi_office', 'tbilisi_office' );
$glc_check( is_array( $glc_direct ) && (float) $glc_direct['total'] === $glc_rows[0]['now']['engine'], 'The preview\'s "now" figure is the figure the public quote gives: ' . ( $glc_direct['total'] ?? '?' ) );
$glc_fee_row = $glc_rows[3];
$glc_check( abs( ( $glc_fee_row['then']['engine'] - $glc_fee_row['now']['engine'] ) - ( ( 55 + (float) GLC_Settings::get( 'batumi_each_way' ) + 2 * 9 ) - ( (float) GLC_Settings::get( 'kutaisi_each_way' ) + (float) GLC_Settings::get( 'batumi_each_way' ) + 2 * (float) GLC_Settings::get( 'out_of_hours_fee' ) ) ) ) < 0.005, 'The sample with a Kutaisi pickup at 09:00 and a Batumi return at 21:00 moves by exactly the difference of the unsaved fees: ' . $glc_fee_row['now']['engine'] . ' → ' . $glc_fee_row['then']['engine'] );
$glc_check( $glc_md5 === md5( (string) $glc_post_raw( $glc_a, 'glc_pricing' ) ) && null === $glc_post_raw( $glc_a, 'glc_delivery_fees' ) && ! has_filter( 'get_post_metadata' ) && GLC_Price_Store::state( $glc_a ) === $glc_now, 'Pricing an unsaved state wrote nothing, and the car reads as stored again afterwards' );

$glc_fleet = 0;
$glc_bad   = [];
foreach ( get_posts( [ 'post_type' => 'car', 'post_status' => 'publish', 'posts_per_page' => -1, 'fields' => 'ids' ] ) as $glc_id ) {
	foreach ( GLC_Quote_Check::rows( (int) $glc_id, [ 'now' => GLC_Price_Store::state( (int) $glc_id ) ] ) as $glc_row ) {
		++$glc_fleet;
		if ( ! $glc_row['now']['agree'] || null === $glc_row['now']['engine'] ) {
			$glc_bad[] = $glc_id;
		}
	}
}
$glc_expected_samples = (int) wp_count_posts( 'car' )->publish * count( GLC_Quote_Check::samples() );
$glc_check( $glc_fleet > 0 && $glc_fleet === $glc_expected_samples && ! $glc_bad, "Across the fleet: {$glc_fleet} sample quotes, the engine and the recompute agree on every one" . ( $glc_bad ? ' EXCEPT cars ' . implode( ',', array_unique( $glc_bad ) ) : '' ) );

// Make the engine see a table that is $1 a day dearer than the one the recompute is handed: the screen must show it.
add_filter( 'get_post_metadata', $glc_skew = static function ( $value, $object_id, $meta_key ) use ( $glc_a, $glc_now ) {
	if ( (int) $object_id !== $glc_a || 'glc_pricing' !== $meta_key ) {
		return $value;
	}
	return [ array_map( static fn( array $season ): array => [ 'rates' => array_map( static fn( $rate ) => $rate + 1, $season['rates'] ) ] + $season, $glc_now['pricing'] ) ];
}, 99, 3 );
ob_start();
GLC_Partner_Car::render_samples( $glc_a, [ 'now' => $glc_now, 'then' => $glc_now ], 'Now', 'Then' );
$glc_html = (string) ob_get_clean();
remove_filter( 'get_post_metadata', $glc_skew, 99 );
$glc_check( 1 === preg_match( '/data-faults="(\d+)"/', $glc_html, $glc_m ) && (int) $glc_m[1] > 0 && substr_count( $glc_html, 'class="glc-fault"' ) >= 2 * (int) $glc_m[1] && str_contains( wp_strip_all_tags( $glc_html ), 'disagree' ), 'When the two calculations differ the screen marks both figures as a fault and says so, instead of showing one: ' . ( $glc_m[1] ?? 0 ) . ' faults marked' );

/* ------------------------------------------------- restore keeps the rules */

$glc_table = GLC_Price_Store::read_pricing( $glc_a );
$glc_gap   = array_values( array_filter( $glc_table, static fn( array $season ): bool => ! ( $season['from'] <= '03-15' && $season['to'] >= '03-15' ) ) );
$glc_dear  = $glc_table;
$glc_dear[0]['rates']['d1_2'] = 9000;
$glc_ids = [];
foreach ( [ 'gap' => $glc_gap, 'empty' => [], 'dear' => $glc_dear ] as $glc_name => $glc_before ) {
	$glc_ids[ $glc_name ] = (int) GLC_Change_Log::record( [ 'car_id' => $glc_a, 'actor' => 0, 'kind' => 'pricing', 'source' => 'cli', 'before' => $glc_before, 'after' => $glc_table, 'note' => 'test: ' . $glc_name ] );
}
$glc_rev  = GLC_Price_Store::rev( $glc_a );
$glc_r    = GLC_Change_Log::restore( $glc_ids['gap'], [ 'rev' => $glc_rev ] );
$glc_code = is_wp_error( $glc_r ) ? array_column( $glc_r->get_error_data()['errors'][ $glc_a ] ?? [], 'code' ) : [];
$glc_check( [ 'err_gap' ] === $glc_code && $glc_md5 === md5( (string) $glc_post_raw( $glc_a, 'glc_pricing' ) ), 'A restore of a table that leaves days without a season is refused: ' . ( is_wp_error( $glc_r ) ? $glc_r->get_error_message() : 'NOT refused' ) );
$glc_r = GLC_Change_Log::restore( $glc_ids['empty'], [ 'rev' => $glc_rev ] );
$glc_check( is_wp_error( $glc_r ) && $glc_md5 === md5( (string) $glc_post_raw( $glc_a, 'glc_pricing' ) ), 'A restore of an empty table is refused: ' . ( is_wp_error( $glc_r ) ? $glc_r->get_error_message() : 'NOT refused' ) );
$glc_r = GLC_Change_Log::restore( $glc_ids['dear'], [ 'rev' => $glc_rev ] );
$glc_check( ! is_wp_error( $glc_r ) && str_contains( (string) $glc_post_raw( $glc_a, 'glc_pricing' ), 'i:9000;' ) && (string) ( $glc_rev + 1 ) === $glc_post_raw( $glc_a, 'glc_rev' ), 'A restore of a table with a price above today\'s limit goes through (the limits are the only rules it skips), revision ' . $glc_rev . ' → ' . $glc_post_raw( $glc_a, 'glc_rev' ) );

/* -------------------------------- the owner's car editor cannot write prices */

$glc_before_save = (string) $glc_post_raw( $glc_c, 'glc_pricing' );
$glc_before_rev  = $glc_post_raw( $glc_c, 'glc_rev' );
$glc_post_backup = $_POST;
$_POST           = [ 'glc_car_nonce' => wp_create_nonce( 'glc_car_meta' ), 'glc_pricing' => [ [ 'label' => 'x', 'from' => '01-01', 'to' => '12-31', 'rates' => array_fill_keys( GLC_Pricing::TIERS, 1 ) ] ], 'glc_color' => 'Test Colour', 'glc_available' => '1' ];
foreach ( [ 'glc_registration', 'glc_license_category', 'glc_transmission', 'glc_drivetrain', 'glc_fuel_type', 'glc_fuel_economy_note', 'glc_year', 'glc_seats', 'glc_price_from', 'glc_whatsapp_number' ] as $glc_key ) {
	$_POST[ $glc_key ] = (string) get_post_meta( $glc_c, $glc_key, true );
}
$_POST['glc_gallery'] = implode( ',', array_map( 'intval', (array) get_post_meta( $glc_c, 'glc_gallery', true ) ) );
$glc_colour = (string) get_post_meta( $glc_c, 'glc_color', true );
GLC_Meta_Boxes::save( $glc_c );
$_POST = $glc_post_backup;
$glc_check( 'Test Colour' === $glc_post_raw( $glc_c, 'glc_color' ) && $glc_before_save === (string) $glc_post_raw( $glc_c, 'glc_pricing' ) && (int) $glc_before_rev + 1 === (int) $glc_post_raw( $glc_c, 'glc_rev' ), 'The car editor\'s save, sent with a whole price table in the request, saves the colour (through the store since the photos leg: the car is one version up) and leaves the table exactly as it was' );
update_post_meta( $glc_c, 'glc_color', $glc_colour );
$glc_check( ! current_user_can( 'edit_post_meta', $glc_c, 'glc_pricing' ) && ! current_user_can( 'add_post_meta', $glc_c, 'glc_pricing' ) && current_user_can( 'edit_post_meta', $glc_c, 'glc_color' ) && is_protected_meta( 'glc_pricing', 'post' ), 'Nor can the owner write the table as a custom field or over REST: WordPress refuses the key, while an ordinary one stays open' );

/* -------------------------------------------------- times a person reads */

$glc_row = $glc_last();
$glc_utc = strtotime( $glc_row['post_date_gmt'] . ' UTC' );
$glc_tb  = ( new DateTimeImmutable( '@' . $glc_utc ) )->setTimezone( new DateTimeZone( 'Asia/Tbilisi' ) )->format( 'Y-m-d H:i' );
$glc_entry = GLC_Change_Log::get( (int) $glc_row['ID'] );
$glc_check( str_ends_with( $glc_row['post_title'], $glc_tb ) && $glc_tb === $glc_entry['time'] && $glc_tb !== gmdate( 'Y-m-d H:i', $glc_utc ), 'A change saved at ' . gmdate( 'H:i', $glc_utc ) . ' UTC is titled and listed ' . substr( $glc_tb, 11 ) . ' (Asia/Tbilisi)' );
$glc_check( '' === (string) get_option( 'timezone_string' ) && '0' === (string) get_option( 'gmt_offset' ) && '+00:00' === wp_timezone_string(), 'The WordPress site time zone is still UTC: timezone_string "' . get_option( 'timezone_string' ) . '", gmt_offset "' . get_option( 'gmt_offset' ) . '"' );

/* ------------------------------------------------ the partner's account goes */

require_once ABSPATH . 'wp-admin/includes/user.php';
// An entry the partner wrote themselves (the shell may name the actor): it must outlive the account.
$glc_own = GLC_Price_Store::write( $glc_b, [ 'fees' => [ 'out_of_hours' => '11' ] ], [ 'source' => 'cli', 'actor' => $glc_partner ] );
$glc_own = (int) ( $glc_own['cars'][ $glc_b ]['entries'][0] ?? 0 );
$glc_count = $glc_entries();
wp_delete_user( $glc_partner );
$glc_left = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} m JOIN {$wpdb->posts} p ON p.ID = m.post_id WHERE p.post_type = 'car' AND m.meta_key = 'glc_partner_id' AND m.meta_value = %s", (string) $glc_partner ) );
$glc_row  = $glc_last();
$glc_check( 0 === $glc_left && $glc_entries() === $glc_count + 3, 'The partner\'s account is deleted: none of the three cars points at it any more, and each return is logged' );
$glc_check( $glc_own > 0 && (string) $glc_partner === (string) $wpdb->get_var( $wpdb->prepare( "SELECT post_author FROM {$wpdb->posts} WHERE ID = %d AND post_type = %s", $glc_own, GLC_Change_Log::POST_TYPE ) ), 'A change-log entry the partner wrote is still there after their account is gone (WordPress deletes a deleted user\'s posts of most types)' );
$glc_check( 'assignment' === $glc_row['glc_kind'] && '{"partner":' . $glc_partner . '}' === $glc_row['glc_before'] && '{"partner":0}' === $glc_row['glc_after'] && 'partner account #' . $glc_partner . ' was deleted' === $glc_row['glc_note'], 'The entry says who had the car, that Geolander has it now, and why: "' . $glc_row['glc_note'] . '"' );
$glc_check( 'site' === GLC_Gateway_WhatsApp::route_for( $glc_a )['source'] && 'car' === GLC_Gateway_WhatsApp::route_for( $glc_legacy )['source'] && ! GLC_Partners::can_manage( $glc_partner, $glc_a ), 'Their cars are answered by Geolander again (and by the car\'s own number where it keeps one)' );
$glc_gone = GLC_Partner_Admin::message_counts()['rows'][ 'partner:' . $glc_partner ] ?? null;
$glc_check( $glc_gone && 3 === $glc_gone['cells']['total'] && str_contains( $glc_gone['label'], '#' . $glc_partner ), 'The requests that went to them stay counted, under "' . ( $glc_gone['label'] ?? '?' ) . '"' );

WP_CLI::log( '' );
WP_CLI::log( $glc_failures ? "{$glc_failures} FAILED" : 'ALL PASS' );
exit( $glc_failures ? 1 : 0 );
