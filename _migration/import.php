<?php
/**
 * One-shot content importer. Run inside the cli container:
 *   wp eval-file /migration/import.php
 *
 * Idempotent: identifies previously imported posts by `glc_legacy_id`
 * meta and updates instead of duplicating.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit( "Run via: wp eval-file /migration/import.php\n" );
}

require_once ABSPATH . 'wp-admin/includes/media.php';
require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/image.php';

const GLC_MIGRATION = '/migration';

function glc_read_json( string $file ): array {
	$path = GLC_MIGRATION . '/' . $file;
	if ( ! file_exists( $path ) ) {
		WP_CLI::warning( "Missing {$file}" );
		return [];
	}
	return json_decode( file_get_contents( $path ), true ) ?: [];
}

function glc_find_by_legacy_id( string $post_type, string $legacy_id ): int {
	$found = get_posts( [
		'post_type'      => $post_type,
		'posts_per_page' => 1,
		'post_status'    => 'any',
		'fields'         => 'ids',
		'meta_key'       => 'glc_legacy_id',
		'meta_value'     => $legacy_id,
	] );
	return $found ? (int) $found[0] : 0;
}

/** Sideload a file from the migration assets dir; reuse if already attached. */
function glc_import_image( string $rel, int $parent, string $alt ): int {
	$rel  = ltrim( $rel, '/' );
	$path = GLC_MIGRATION . '/assets/' . preg_replace( '#^(cars|hero|places|og|icons|uploads)/#', '$1/', $rel );
	if ( ! file_exists( $path ) ) {
		return 0;
	}
	$hash     = md5_file( $path );
	$existing = get_posts( [
		'post_type'      => 'attachment',
		'posts_per_page' => 1,
		'post_status'    => 'any',
		'fields'         => 'ids',
		'meta_key'       => 'glc_source_hash',
		'meta_value'     => $hash,
	] );
	if ( $existing ) {
		return (int) $existing[0];
	}

	$tmp = wp_tempnam( basename( $path ) );
	copy( $path, $tmp );
	$att_id = media_handle_sideload( [
		'name'     => str_replace( '/', '-', $rel ),
		'tmp_name' => $tmp,
	], $parent );
	if ( is_wp_error( $att_id ) ) {
		@unlink( $tmp );
		WP_CLI::warning( "Image failed {$rel}: " . $att_id->get_error_message() );
		return 0;
	}
	update_post_meta( $att_id, 'glc_source_hash', $hash );
	update_post_meta( $att_id, '_wp_attachment_image_alt', $alt );
	return (int) $att_id;
}

/* ------------------------------------------------------------------ Cars */

$tier_map = [
	'days1To2'   => 'd1_2',
	'days3To4'   => 'd3_4',
	'days5To7'   => 'd5_7',
	'days8To12'  => 'd8_12',
	'days13To18' => 'd13_18',
	'days19To30' => 'd19_30',
	'days31Plus' => 'd31p',
];

$season_dates = [
	'Apr 01 - Oct 31' => [ '04-01', '10-31' ],
	'Nov 01 - Dec 24' => [ '11-01', '12-24' ],
	'Dec 25 - Jan 05' => [ '12-25', '01-05' ],
	'Jan 06 - Mar 31' => [ '01-06', '03-31' ],
];

$cars = glc_read_json( 'cars.json' );
WP_CLI::log( 'Importing ' . count( $cars ) . ' cars…' );

foreach ( $cars as $car ) {
	$title = trim( $car['brand'] . ' ' . $car['model'] );
	$year  = (int) $car['year'];

	$description = $car['descriptionEn'] ?: sprintf(
		'Rent a %1$d %2$s in Tbilisi, Georgia. %3$s with %4$d seats, %5$s transmission and full insurance included — ready for Kazbegi, Gudauri, Kakheti and every Caucasus mountain road. Free Tbilisi Airport delivery and 24/7 support.',
		$year,
		$title,
		$car['bodyType'],
		(int) $car['seats'],
		$car['transmission']
	);

	$pricing = [];
	foreach ( (array) $car['pricing'] as $season ) {
		$rates = [];
		foreach ( $tier_map as $legacy => $tier ) {
			$rates[ $tier ] = (float) ( $season['prices'][ $legacy ] ?? 0 );
		}
		[ $from, $to ] = $season_dates[ $season['period'] ] ?? [ '', '' ];
		$pricing[]     = [ 'label' => $season['period'], 'from' => $from, 'to' => $to, 'rates' => $rates ];
	}

	$post_id = glc_find_by_legacy_id( 'car', $car['id'] );
	$post_id = wp_insert_post( [
		'ID'           => $post_id,
		'post_type'    => 'car',
		'post_status'  => 'publish',
		'post_title'   => $title . ' ' . $year,
		'post_name'    => sanitize_title( $title . '-' . $year . '-' . $car['registrationNumber'] ),
		'post_content' => $description,
		'post_excerpt' => $description,
		'menu_order'   => (int) $car['sortOrder'],
		'meta_input'   => [
			'glc_legacy_id'        => $car['id'],
			'glc_registration'     => $car['registrationNumber'],
			'glc_year'             => $year,
			'glc_color'            => $car['color'],
			'glc_seats'            => (int) $car['seats'],
			'glc_transmission'     => $car['transmission'],
			'glc_fuel_type'        => $car['fuelType'],
			'glc_license_category' => $car['licenseCategory'],
			'glc_price_from'       => (float) $car['pricePerDay'],
			'glc_available'        => (bool) $car['available'],
			'glc_pricing'          => $pricing,
		],
	] );
	if ( is_wp_error( $post_id ) ) {
		WP_CLI::warning( "Car failed: {$title}" );
		continue;
	}

	wp_set_object_terms( $post_id, $car['brand'], 'car_brand' );
	wp_set_object_terms( $post_id, $car['bodyType'], 'car_body_type' );

	$gallery = [];
	foreach ( (array) $car['images'] as $i => $img ) {
		$att = glc_import_image( $img, $post_id, sprintf( '%s %d — photo %d', $title, $year, $i + 1 ) );
		if ( $att ) {
			$gallery[] = $att;
		}
	}
	if ( $gallery ) {
		set_post_thumbnail( $post_id, $gallery[0] );
		update_post_meta( $post_id, 'glc_gallery', $gallery );
	}
	WP_CLI::log( "  ✓ {$title} {$year} (" . count( $gallery ) . ' photos)' );
}

/* ---------------------------------------------------------------- Places */

$regions      = glc_read_json( 'regions.json' );
$region_by_id = [];
foreach ( $regions as $region ) {
	$term = term_exists( $region['nameEn'], 'place_region' ) ?: wp_insert_term( $region['nameEn'], 'place_region', [
		'slug'        => $region['slug'],
		'description' => $region['descriptionEn'],
	] );
	if ( ! is_wp_error( $term ) ) {
		$term_id                      = (int) ( is_array( $term ) ? $term['term_id'] : $term );
		$region_by_id[ $region['id'] ] = $term_id;
		update_term_meta( $term_id, 'glc_name_ka', $region['nameKa'] );
		update_term_meta( $term_id, 'glc_description_ka', $region['descriptionKa'] );
	}
}
WP_CLI::log( 'Regions: ' . count( $region_by_id ) );

$places = glc_read_json( 'tourist-locations.json' );
WP_CLI::log( 'Importing ' . count( $places ) . ' places…' );

foreach ( $places as $place ) {
	$content = $place['descriptionEn'];
	if ( ! empty( $place['whatMakesItSpecialEn'] ) ) {
		$content .= "\n\n<h3>What makes it special</h3>\n" . $place['whatMakesItSpecialEn'];
	}

	$post_id = glc_find_by_legacy_id( 'place', $place['id'] );
	$post_id = wp_insert_post( [
		'ID'           => $post_id,
		'post_type'    => 'place',
		'post_status'  => 'publish',
		'post_title'   => $place['nameEn'],
		'post_content' => $content,
		'post_excerpt' => $place['descriptionEn'],
		'menu_order'   => (int) $place['sortOrder'],
		'meta_input'   => [
			'glc_legacy_id' => $place['id'],
			'glc_name_ka'   => $place['nameKa'] ?? '',
			'glc_lat'       => (float) $place['latitude'],
			'glc_lng'       => (float) $place['longitude'],
		],
	] );
	if ( is_wp_error( $post_id ) ) {
		continue;
	}

	if ( isset( $region_by_id[ $place['regionId'] ] ) ) {
		wp_set_object_terms( $post_id, [ $region_by_id[ $place['regionId'] ] ], 'place_region' );
	}
	foreach ( (array) ( $place['images'] ?? [] ) as $img ) {
		$att = glc_import_image( $img, $post_id, $place['nameEn'] );
		if ( $att && ! has_post_thumbnail( $post_id ) ) {
			set_post_thumbnail( $post_id, $att );
		}
	}
	WP_CLI::log( "  ✓ {$place['nameEn']}" );
}

/* ---------------------------------------------------------- Testimonials */

foreach ( glc_read_json( 'testimonials.json' ) as $t ) {
	if ( empty( $t['visible'] ) ) {
		continue;
	}
	$post_id = glc_find_by_legacy_id( 'testimonial', $t['id'] );
	wp_insert_post( [
		'ID'           => $post_id,
		'post_type'    => 'testimonial',
		'post_status'  => 'publish',
		'post_title'   => $t['name'],
		'post_content' => $t['textEn'],
		'menu_order'   => (int) $t['sortOrder'],
		'meta_input'   => [
			'glc_legacy_id' => $t['id'],
			'glc_route'     => $t['route'],
			'glc_rating'    => (int) $t['rating'],
		],
	] );
	WP_CLI::log( "  ✓ Testimonial: {$t['name']}" );
}

/* ------------------------------------------------------------------ FAQs */

$faq_data = glc_read_json( 'faq.json' );
$faq_num  = 0;
foreach ( (array) ( $faq_data['mainEntity'] ?? [] ) as $i => $qa ) {
	$legacy_id = 'faq-' . sanitize_title( $qa['name'] );
	$post_id   = glc_find_by_legacy_id( 'faq', $legacy_id );
	wp_insert_post( [
		'ID'           => $post_id,
		'post_type'    => 'faq',
		'post_status'  => 'publish',
		'post_title'   => $qa['name'],
		'post_content' => $qa['acceptedAnswer']['text'],
		'menu_order'   => $i,
		'meta_input'   => [ 'glc_legacy_id' => $legacy_id ],
	] );
	$faq_num++;
}
WP_CLI::log( "FAQs: {$faq_num}" );

WP_CLI::success( 'Import finished.' );
