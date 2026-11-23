<?php
/**
 * Every client of the quote engine against a real WordPress and a real database:
 * the REST routes through the REST server (so its own argument handling runs),
 * the MCP tool, the A2A skill, checkout and what it stores, and one car's own
 * fees written through GLC_Price_Store.
 *
 * LOCAL COPY ONLY. This creates booking requests, gives one car fees of its own
 * and empties one site fee for a moment. Put the database back afterwards
 * (reset script).
 *
 * Run: docker exec -i <wordpress container> wp eval-file - --allow-root < tests/quote-clients-wp.php
 *
 * tests/quote-engine.php proves the rules with WordPress stubbed; this proves
 * the wiring. What checkout stored is read back with SQL, not with the code
 * that wrote it.
 */

if ( ! defined( 'ABSPATH' ) || ! defined( 'WP_CLI' ) ) {
	exit( "Run via wp eval-file\n" );
}
if ( 'production' === wp_get_environment_type() ) {
	WP_CLI::error( 'This test writes booking requests and fees. It does not run on a production site.' );
}

global $wpdb;
$glc_failures = 0;
$glc_check    = static function ( $condition, string $label ) use ( &$glc_failures ): void {
	if ( ! $condition ) {
		++$glc_failures;
	}
	WP_CLI::log( ( $condition ? 'PASS ' : 'FAIL ' ) . $label );
};
/** One request through the REST server, as a browser or an app would send it. */
$glc_rest = static function ( string $method, string $route, array $params ): array {
	$request = new WP_REST_Request( $method, $route );
	if ( 'GET' === $method ) {
		$request->set_query_params( $params );
	} else {
		$request->set_header( 'content-type', 'application/json' );
		$request->set_body( wp_json_encode( $params ) );
	}
	$response = rest_do_request( $request );
	return [ 'status' => $response->get_status(), 'data' => (array) $response->get_data() ];
};
$glc_mcp = static function ( string $method, array $params = [], ?string $name = null ): array {
	$request = new WP_REST_Request( 'POST', '/geolander-agent/v1/mcp' );
	$request->set_header( 'content-type', 'application/json' );
	$request->set_header( 'mcp-protocol-version', '2026-07-28' );
	$request->set_header( 'mcp-method', $method );
	if ( null !== $name ) {
		$request->set_header( 'mcp-name', $name );
	}
	$params['_meta'] = [ 'io.modelcontextprotocol/protocolVersion' => '2026-07-28' ];
	$request->set_body( wp_json_encode( [ 'jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => $params ] ) );
	return (array) GLC_MCP::handle( $request )->get_data();
};
$glc_a2a = static function ( array $arguments ): array {
	$request = new WP_REST_Request( 'POST', '/geolander-agent/v1/a2a' );
	$request->set_header( 'content-type', 'application/json' );
	$request->set_header( 'a2a-version', '1.0' );
	$request->set_body( wp_json_encode( [
		'jsonrpc' => '2.0',
		'id'      => 1,
		'method'  => 'SendMessage',
		'params'  => [ 'message' => [ 'messageId' => wp_generate_uuid4(), 'role' => 'ROLE_USER', 'parts' => [ [ 'data' => [ 'skill' => 'rental-quote' ] + $arguments, 'mediaType' => 'application/json' ] ] ] ],
	] ) );
	$body = (array) GLC_A2A::handle( $request )->get_data();
	foreach ( $body['result']['message']['parts'] ?? [] as $part ) {
		if ( isset( $part['data'] ) ) {
			return (array) $part['data'];
		}
	}
	return [ 'error' => $body['error'] ?? 'no data part' ];
};
$glc_meta = static function ( int $post_id ) use ( $wpdb ): array {
	$rows = $wpdb->get_results( $wpdb->prepare( "SELECT meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id = %d", $post_id ), ARRAY_A );
	return array_column( $rows, 'meta_value', 'meta_key' );
};

$glc_cars = array_values( array_filter(
	array_map( 'intval', get_posts( [ 'post_type' => 'car', 'post_status' => 'publish', 'posts_per_page' => -1, 'fields' => 'ids', 'orderby' => 'ID', 'order' => 'ASC' ] ) ),
	static fn( int $id ): bool => GLC_Pricing::is_priced( $id )
) );
if ( count( $glc_cars ) < 2 ) {
	WP_CLI::error( 'This test needs two published, priced cars.' );
}
[ $glc_a, $glc_b ] = $glc_cars;
$glc_dates   = [ 'from' => '2027-05-01', 'to' => '2027-05-04' ];
$glc_fee     = (float) GLC_Settings::get( 'out_of_hours_fee' );
$glc_default = (string) GLC_Settings::get( 'handover_default_time' );
$glc_kutaisi = (float) GLC_Settings::get( 'kutaisi_each_way' );
WP_CLI::log( sprintf( 'cars #%d and #%d; early/late fee %s; default time %s; Kutaisi %s', $glc_a, $glc_b, $glc_fee, $glc_default, $glc_kutaisi ) );

/* ----------------------------------------------------------- REST routes */

$glc_routes = rest_get_server()->get_routes();
$glc_ok     = true;
foreach ( [ '/geolander/v1/quote', '/geolander/v1/checkout', '/geolander-agent/v1/quote', '/geolander-agent/v1/checkout' ] as $glc_route ) {
	foreach ( [ 'pickup_time', 'return_time' ] as $glc_arg ) {
		$glc_spec = $glc_routes[ $glc_route ][0]['args'][ $glc_arg ] ?? null;
		$glc_ok   = $glc_ok && $glc_spec && false === $glc_spec['required'] && 'string' === $glc_spec['type'] && GLC_Rental::TIME_PATTERN === $glc_spec['pattern'] && ! array_key_exists( 'default', $glc_spec );
	}
}
$glc_check( $glc_ok, 'All four routes (public and agent, quote and checkout) take pickup_time and return_time: optional, text, the HH:MM pattern, no default' );

$glc_plain = $glc_rest( 'GET', '/geolander/v1/quote', [ 'car' => $glc_a ] + $glc_dates );
$glc_q     = $glc_plain['data'];
$glc_check( 200 === $glc_plain['status'] && $glc_default === $glc_q['pickup_time'] && $glc_default === $glc_q['return_time'] && 0.0 === (float) $glc_q['pickup_time_fee'] && 0.0 === (float) $glc_q['return_time_fee'] && true === $glc_q['times_assumed'], 'REST quote without times: the default time, no time fee, times_assumed true' );
$glc_timed = $glc_rest( 'GET', '/geolander/v1/quote', [ 'car' => $glc_a, 'pickup' => 'kutaisi_airport', 'pickup_time' => '09:00', 'return_time' => '21:00' ] + $glc_dates );
$glc_t     = $glc_timed['data'];
$glc_check( 200 === $glc_timed['status'] && $glc_fee === (float) $glc_t['pickup_time_fee'] && $glc_fee === (float) $glc_t['return_time_fee'] && false === $glc_t['times_assumed'] && abs( $glc_t['total'] - ( $glc_q['total'] + $glc_kutaisi + 2 * $glc_fee ) ) < 0.005, 'REST quote at 09:00 and 21:00 from Kutaisi: the plain total plus the Kutaisi fee plus two early/late fees' );
foreach ( [ '9:00' => 'no leading zero', '24:00' => 'hour 24', 'noon' => 'a word', '' => 'empty text', '12:00:00' => 'seconds' ] as $glc_bad => $glc_why ) {
	$glc_r = $glc_rest( 'GET', '/geolander/v1/quote', [ 'car' => $glc_a, 'pickup_time' => (string) $glc_bad ] + $glc_dates );
	$glc_check( 400 === $glc_r['status'] && 'glc_bad_time' === ( $glc_r['data']['code'] ?? '' ), 'REST refuses a time with ' . $glc_why . ' as 400 glc_bad_time (got ' . $glc_r['status'] . ' ' . ( $glc_r['data']['code'] ?? '' ) . ')' );
}
$glc_r = $glc_rest( 'GET', '/geolander/v1/quote', [ 'car' => $glc_a, 'return_time' => [ '12:00' ] ] + $glc_dates );
$glc_check( 400 === $glc_r['status'] && 'glc_bad_time' === ( $glc_r['data']['code'] ?? '' ), 'REST refuses a time sent as a list the same way' );

/* ------------------------------------------------------------ MCP and A2A */

$glc_tools = array_column( $glc_mcp( 'tools/list' )['result']['tools'] ?? [], null, 'name' );
$glc_props = $glc_tools['get_rental_quote']['inputSchema']['properties'] ?? [];
$glc_check( GLC_Rental::TIME_PATTERN === ( $glc_props['pickup_time']['pattern'] ?? '' ) && GLC_Rental::TIME_PATTERN === ( $glc_props['return_time']['pattern'] ?? '' ) && [ 'car', 'from', 'to' ] === $glc_tools['get_rental_quote']['inputSchema']['required'], 'MCP get_rental_quote lists both times with the pattern, and still requires only car, from and to' );
$glc_call = static fn( array $arguments ): array => (array) $glc_mcp( 'tools/call', [ 'name' => 'get_rental_quote', 'arguments' => $arguments ], 'get_rental_quote' )['result'];
$glc_m    = $glc_call( [ 'car' => $glc_a ] + $glc_dates );
$glc_check( false === $glc_m['isError'] && true === $glc_m['structuredContent']['times_assumed'] && abs( $glc_m['structuredContent']['total'] - $glc_q['total'] ) < 0.005 && 'not_confirmed' === $glc_m['structuredContent']['availability_status'], 'MCP quote without times equals the REST one and says the times were assumed' );
$glc_m = $glc_call( [ 'car' => $glc_a, 'pickup' => 'kutaisi_airport', 'pickup_time' => '09:00', 'return_time' => '21:00' ] + $glc_dates );
$glc_check( false === $glc_m['isError'] && abs( $glc_m['structuredContent']['total'] - $glc_t['total'] ) < 0.005 && $glc_fee === (float) $glc_m['structuredContent']['pickup_time_fee'] && false === $glc_m['structuredContent']['times_assumed'], 'MCP quote with times equals the REST one, fees included' );
$glc_m = $glc_call( [ 'car' => $glc_a, 'pickup_time' => '9am' ] + $glc_dates );
$glc_check( true === $glc_m['isError'] && str_contains( $glc_m['content'][0]['text'] ?? '', 'HH:MM' ) && ! isset( $glc_m['structuredContent'] ), 'MCP answers a malformed time with an error that says how to write one, and no figures' );
$glc_check( str_contains( $glc_mcp( 'tools/call', [ 'name' => 'get_booking_policy', 'arguments' => (object) [] ], 'get_booking_policy' )['result']['structuredContent']['handover'] ?? '', GLC_Rental::out_of_hours_sentence() ) && '' !== GLC_Rental::out_of_hours_sentence(), 'MCP policy answer carries the early/late sentence' );
$glc_d = $glc_a2a( [ 'car' => $glc_a, 'pickup' => 'kutaisi_airport', 'pickup_time' => '09:00', 'return_time' => '21:00' ] + $glc_dates );
$glc_check( abs( ( $glc_d['total'] ?? 0 ) - $glc_t['total'] ) < 0.005 && $glc_fee === (float) ( $glc_d['return_time_fee'] ?? -1 ) && false === ( $glc_d['times_assumed'] ?? null ), 'A2A rental-quote with times equals the REST one' );
$glc_d = $glc_a2a( [ 'car' => $glc_a ] + $glc_dates );
$glc_check( true === ( $glc_d['times_assumed'] ?? null ) && abs( ( $glc_d['total'] ?? 0 ) - $glc_q['total'] ) < 0.005, 'A2A rental-quote without times is the plain quote, marked assumed' );

/* ------------------------------------------- checkout and what it stores */

$glc_before = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'booking_request'" );
$glc_c      = $glc_rest( 'POST', '/geolander/v1/checkout', [ 'car' => $glc_a, 'pickup' => 'tbilisi_office', 'return' => 'kutaisi_airport', 'pickup_time' => '09:30', 'return_time' => '12:00', 'name' => 'L2 Test', 'email' => 'l2-test@example.test' ] + $glc_dates );
$glc_msg    = (string) ( $glc_c['data']['message'] ?? '' );
$glc_money  = static fn( float $amount ): string => GLC_Format::money_exact( $amount, 'en' );
$glc_total  = (float) $glc_q['total'] + $glc_kutaisi + $glc_fee;
$glc_check( 200 === $glc_c['status'] && str_contains( $glc_msg, "\nPickup time: 09:30 (early/late fee " . $glc_money( $glc_fee ) . ")\n" ) && str_contains( $glc_msg, "\nReturn time: 12:00\n" ) && str_contains( $glc_msg, "\nFinal total shown: " . $glc_money( $glc_total ) . "\n" ) && ! str_contains( $glc_msg, 'assumed' ), 'Checkout at 09:30 / 12:00: the staff message has both times, the early fee on the pickup line only, and the total with that fee in it' );
$glc_post  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = 'glc_reference' AND meta_value = %s", (string) ( $glc_c['data']['reference'] ?? '' ) ) );
$glc_saved = $glc_meta( $glc_post );
$glc_check( $glc_post > 0 && '09:30' === $glc_saved['glc_pickup_time'] && '12:00' === $glc_saved['glc_return_time'] && $glc_fee === (float) $glc_saved['glc_pickup_time_fee'] && is_numeric( $glc_saved['glc_pickup_time_fee'] ) && '0' === $glc_saved['glc_return_time_fee'] && '0' === $glc_saved['glc_times_assumed'] && abs( (float) $glc_saved['glc_total'] - $glc_total ) < 0.005, 'The stored request, read with SQL: times as HH:MM text, the two time fees as plain numbers, assumed = 0, total with the fee (' . wp_json_encode( array_intersect_key( $glc_saved, array_flip( [ 'glc_pickup_time', 'glc_return_time', 'glc_pickup_time_fee', 'glc_return_time_fee', 'glc_times_assumed', 'glc_total' ] ) ) ) . ')' );
$glc_check( abs( (float) $glc_saved['glc_total'] - ( (float) $glc_saved['glc_rental_total'] + (float) $glc_saved['glc_pickup_fee'] + (float) $glc_saved['glc_return_fee'] + (float) $glc_saved['glc_pickup_time_fee'] + (float) $glc_saved['glc_return_time_fee'] ) ) < 0.005, 'The stored total is the sum of the stored lines' );
$glc_mail = GLC_Booking_Email::text( $glc_post, false );
$glc_check( str_contains( $glc_mail, "\nPickup time: 09:30\n" ) && str_contains( $glc_mail, "\nReturn time: 12:00\n" ) && str_contains( $glc_mail, "\nEarly/late pickup fee: " . $glc_money( $glc_fee ) . "\n" ) && ! str_contains( $glc_mail, 'Early/late return fee' ), 'The receipt text has both times and the one fee that was charged' );
$glc_c     = $glc_rest( 'POST', '/geolander/v1/checkout', [ 'car' => $glc_a, 'pickup' => 'tbilisi_office', 'return' => 'tbilisi_office', 'name' => 'L2 Test', 'email' => 'l2-test@example.test' ] + $glc_dates );
$glc_msg   = (string) ( $glc_c['data']['message'] ?? '' );
$glc_saved = $glc_meta( (int) $wpdb->get_var( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = 'glc_reference' AND meta_value = %s", (string) ( $glc_c['data']['reference'] ?? '' ) ) ) );
$glc_check( 200 === $glc_c['status'] && str_contains( $glc_msg, "\nPickup time: {$glc_default} (assumed: no time was given)\n" ) && '1' === ( $glc_saved['glc_times_assumed'] ?? '' ) && $glc_default === ( $glc_saved['glc_return_time'] ?? '' ), 'Checkout from a client that sends no times: the message and the stored request both say the time was assumed' );
$glc_c = $glc_rest( 'POST', '/geolander/v1/checkout', [ 'car' => $glc_a, 'pickup' => 'tbilisi_office', 'return' => 'tbilisi_office', 'pickup_time' => '7', 'name' => 'L2 Test', 'email' => 'l2-test@example.test' ] + $glc_dates );
$glc_check( 400 === $glc_c['status'] && 'glc_bad_time' === ( $glc_c['data']['code'] ?? '' ) && $glc_before + 2 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'booking_request'" ), 'Checkout with a malformed time is refused and stores nothing (two requests exist, from the two accepted ones)' );

/* ------------------------------------------------- one car's own fees */

$glc_w = GLC_Price_Store::write( $glc_a, [ 'fees' => [ 'delivery' => [ 'kutaisi_airport' => '95' ], 'out_of_hours' => '25' ] ], [ 'source' => 'cli', 'note' => 'quote-clients test' ] );
$glc_check( ! is_wp_error( $glc_w ), 'Fixture: car #' . $glc_a . ' is given its own Kutaisi fee (95) and early/late fee (25) through the store' );
$glc_own   = $glc_rest( 'GET', '/geolander/v1/quote', [ 'car' => $glc_a, 'pickup' => 'kutaisi_airport', 'pickup_time' => '09:00', 'return_time' => '21:00' ] + $glc_dates )['data'];
$glc_other = $glc_rest( 'GET', '/geolander/v1/quote', [ 'car' => $glc_b, 'pickup' => 'kutaisi_airport', 'pickup_time' => '09:00', 'return_time' => '21:00' ] + $glc_dates )['data'];
$glc_check( 95.0 === (float) $glc_own['pickup_fee'] && 25.0 === (float) $glc_own['pickup_time_fee'] && 25.0 === (float) $glc_own['return_time_fee'] && abs( $glc_own['total'] - ( $glc_q['total'] + 95 + 50 ) ) < 0.005, 'That car is quoted with its own 95 and two fees of 25' );
$glc_check( $glc_kutaisi === (float) $glc_other['pickup_fee'] && $glc_fee === (float) $glc_other['pickup_time_fee'], 'Another car keeps the site fee and the site early/late fee' );
$glc_check( [ 'from', min( 95.0, $glc_kutaisi ) ] === array_values( array_intersect_key( GLC_Rental::fee_summary( 'kutaisi_airport' ), [ 'state' => 1, 'fee' => 1 ] ) ) && [ 'from', min( 25.0, $glc_fee ) ] === array_values( array_intersect_key( GLC_Rental::out_of_hours_summary(), [ 'state' => 1, 'fee' => 1 ] ) ), 'For the fleet both now read "from" the lowest amount among the cars' );
$glc_check( str_contains( GLC_Rental::fee_sentence(), 'Kutaisi (KUT airport or city) from ' . $glc_money( min( 95.0, $glc_kutaisi ) ) ) && str_contains( GLC_Rental::fee_sentence( $glc_a ), 'Kutaisi (KUT airport or city) $95' ) && str_contains( GLC_Rental::fee_sentence( $glc_a ), 'is charged $25 per handover' ), 'The machine sentence says "from" for the fleet and the exact amounts for that car' );
GLC_Rental::fee_summary( 'kutaisi_airport' );
$glc_queries = $wpdb->num_queries;
for ( $glc_i = 0; $glc_i < 25; $glc_i++ ) {
	GLC_Rental::fee_summary( 'kutaisi_airport' );
	GLC_Rental::pair_summary( 'kutaisi_airport', 'batumi_airport' );
	GLC_Rental::out_of_hours_summary();
}
$glc_check( $glc_queries === $wpdb->num_queries, '75 more fleet summaries in the same request cost no further database query (' . ( $wpdb->num_queries - $glc_queries ) . ')' );

/* ------------------------------------------ a fee nobody has decided */

$glc_settings = (array) get_option( 'glc_settings', [] );
update_option( 'glc_settings', [ 'kutaisi_each_way' => '' ] + $glc_settings );
$glc_r = $glc_rest( 'GET', '/geolander/v1/quote', [ 'car' => $glc_b, 'pickup' => 'kutaisi_airport' ] + $glc_dates );
$glc_check( 400 === $glc_r['status'] && 'glc_fee_unknown' === ( $glc_r['data']['code'] ?? '' ), 'Site fee for Kutaisi emptied: a car without its own fee is refused there as 400 glc_fee_unknown, not quoted at zero' );
$glc_r = $glc_rest( 'GET', '/geolander/v1/quote', [ 'car' => $glc_b, 'pickup' => 'batumi_airport' ] + $glc_dates );
$glc_check( 200 === $glc_r['status'], 'The same car with another place is still quoted' );
$glc_r = $glc_rest( 'GET', '/geolander/v1/quote', [ 'car' => $glc_a, 'pickup' => 'kutaisi_airport' ] + $glc_dates );
$glc_check( 200 === $glc_r['status'] && 95.0 === (float) $glc_r['data']['pickup_fee'], 'The car with its own Kutaisi fee is still quoted there' );
$glc_check( 'unknown' === GLC_Rental::fee_summary( 'kutaisi_airport' )['state'] && ! str_contains( GLC_Rental::fee_sentence(), 'Kutaisi' ), 'For the fleet the place is unknown and the machine sentence leaves it out' );
update_option( 'glc_settings', $glc_settings );

WP_CLI::log( '' );
if ( $glc_failures ) {
	WP_CLI::error( $glc_failures . ' check(s) failed. The database was changed: run the reset script.' );
}
WP_CLI::success( 'All checks passed. The database was changed: run the reset script.' );
