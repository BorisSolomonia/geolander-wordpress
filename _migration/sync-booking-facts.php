<?php
/**
 * Apply owner-confirmed booking facts and consolidate the duplicate RAV4.
 *
 * Run with: wp eval-file /migration/sync-booking-facts.php
 */

defined( 'ABSPATH' ) || exit;

function glc_booking_fact_car( string $slug ): ?WP_Post {
	$posts = get_posts( [
		'post_type'        => 'car',
		'name'             => $slug,
		'post_status'      => 'any',
		'posts_per_page'   => 1,
		'suppress_filters' => true,
	] );
	return $posts[0] ?? null;
}

$survivor = glc_booking_fact_car( 'toyota-rav4-2016-limited' );
$legacy   = glc_booking_fact_car( 'toyota-rav4-2016-gg581wg' );

if ( ! $survivor && ! $legacy ) {
	WP_CLI::error( 'No Toyota RAV4 record was found.' );
}

// Prefer the page with real photos. If it is absent, improve the legacy page.
$survivor = $survivor ?: $legacy;

if ( $legacy && $legacy->ID !== $survivor->ID ) {
	foreach ( [ 'glc_pricing', 'glc_price_from', 'glc_registration', 'glc_year', 'glc_seats', 'glc_transmission', 'glc_license_category', 'glc_available' ] as $key ) {
		$value = get_post_meta( $legacy->ID, $key, true );
		if ( '' !== $value && [] !== $value ) {
			update_post_meta( $survivor->ID, $key, $value );
		}
	}
}

wp_update_post( [
	'ID'          => $survivor->ID,
	'post_status' => 'publish',
	'post_title'  => 'Toyota RAV4 Hybrid AWD 2016',
	'post_name'   => 'toyota-rav4-2016-limited',
	'post_content'=> 'This exact Toyota RAV4 Hybrid AWD is an automatic five-seat crossover with about 25–30% better fuel economy. Full insurance is included with no deductible or security deposit. Wheels and windshield are covered; tyres are not. Unlimited mileage within Georgia and free winter tyres are included.',
	'post_excerpt'=> 'Toyota RAV4 Hybrid AWD automatic with about 25–30% better fuel economy, included full insurance, no excess, no security deposit, and free winter tyres.',
] );

update_post_meta( $survivor->ID, 'glc_registration', 'GG581WG' );
update_post_meta( $survivor->ID, 'glc_year', 2016 );
update_post_meta( $survivor->ID, 'glc_seats', 5 );
update_post_meta( $survivor->ID, 'glc_transmission', 'automatic' );
update_post_meta( $survivor->ID, 'glc_drivetrain', 'AWD' );
update_post_meta( $survivor->ID, 'glc_fuel_type', 'hybrid' );
update_post_meta( $survivor->ID, 'glc_fuel_economy_note', 'About 25–30% better fuel economy' );
update_post_meta( $survivor->ID, 'glc_available', true );
update_post_meta( $survivor->ID, 'glc_seo_title_en', 'Toyota RAV4 Hybrid AWD Rental in Tbilisi' );
update_post_meta(
	$survivor->ID,
	'glc_seo_description_en',
	'Book the exact Toyota RAV4 Hybrid AWD in Tbilisi with about 25–30% better fuel economy, full insurance, no excess, no deposit, and free winter tyres.'
);
wp_set_object_terms( $survivor->ID, 'Toyota', 'car_brand' );
wp_set_object_terms( $survivor->ID, 'Crossover', 'car_body_type' );

if ( ! GLC_Pricing::is_priced( $survivor->ID ) ) {
	WP_CLI::warning( 'RAV4 facts were updated, but no usable seasonal price table exists. The page will not publish a zero.' );
} elseif ( $legacy && $legacy->ID !== $survivor->ID ) {
	wp_update_post( [ 'ID' => $legacy->ID, 'post_status' => 'draft' ] );
	WP_CLI::log( 'Moved duplicate ' . $legacy->post_name . ' to draft; its public URL is retained as a 301.' );
}

WP_CLI::success( sprintf( 'RAV4 survivor #%d updated: Hybrid, AWD, owner-confirmed economy note, plate and pricing.', $survivor->ID ) );
