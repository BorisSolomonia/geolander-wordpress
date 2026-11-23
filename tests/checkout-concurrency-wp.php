<?php
/** Local integration probe, orchestrated by checkout-concurrency.test.ps1. */
if ( ! defined( 'WP_CLI' ) || 'production' === wp_get_environment_type() ) {
	exit( "Local WP-CLI only.\n" );
}
$mode = $args[0] ?? '';
if ( 'setup' === $mode ) {
	$car = wp_insert_post( [ 'post_type' => 'car', 'post_status' => 'publish', 'post_title' => 'Concurrency audit fixture' ] );
	update_post_meta( $car, 'glc_available', 1 );
	update_post_meta( $car, 'glc_pricing', [ [ 'label' => 'Year', 'from' => '01-01', 'to' => '12-31', 'rates' => array_fill_keys( GLC_Pricing::TIERS, 31 ) ] ] );
	update_option( 'glc_audit_checkout_car', $car, false );
	update_option( 'glc_checkout_rate_limit', [ 'count' => 19, 'expires_at' => time() + HOUR_IN_SECONDS ], false );
	return;
}
if ( 'request' === $mode ) {
	// Force overlapping reads in the old implementation. The assertion still
	// uses the public checkout interface; it does not call the private limiter.
	add_filter( 'option_glc_checkout_rate_limit', static function ( $value ) { usleep( 600000 ); return $value; } );
	add_filter( 'pre_wp_mail', '__return_true' );
	$request = new WP_REST_Request( 'POST', '/geolander/v1/checkout' );
	$request->set_body_params( [ 'car' => (int) get_option( 'glc_audit_checkout_car' ), 'from' => '2027-05-01', 'to' => '2027-05-04', 'pickup' => 'tbilisi_office', 'return' => 'tbilisi_office', 'name' => 'Audit', 'email' => 'audit@example.test' ] );
	$result = rest_do_request( $request );
	echo $result->get_status() . "\n";
	return;
}
if ( 'cleanup' === $mode ) {
	$car = (int) get_option( 'glc_audit_checkout_car' );
	foreach ( get_posts( [ 'post_type' => 'booking_request', 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids', 'meta_key' => 'glc_car_id', 'meta_value' => $car ] ) as $id ) {
		wp_delete_post( $id, true );
	}
	wp_delete_post( $car, true );
	delete_option( 'glc_audit_checkout_car' );
	delete_option( 'glc_checkout_rate_limit' );
}
