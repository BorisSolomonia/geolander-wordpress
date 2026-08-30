<?php
/**
 * Fix pass: FAQs (after BOM repair) + gallery fallbacks.
 * Cars with no photos borrow the gallery of another unit of the same
 * brand+model (marked with glc_gallery_borrowed until a real shoot).
 * The orphan mitsubishi-outlander-lc-235-ll photo set is imported and
 * used for the photo-less Outlanders.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit( "Run via: wp eval-file /migration/import-fixpass.php\n" );
}

require_once ABSPATH . 'wp-admin/includes/media.php';
require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/image.php';

/* FAQs */
$faq_data = json_decode( file_get_contents( '/migration/faq.json' ), true ) ?: [];
$faq_num  = 0;
foreach ( (array) ( $faq_data['mainEntity'] ?? [] ) as $i => $qa ) {
	$legacy_id = 'faq-' . sanitize_title( $qa['name'] );
	$existing  = get_posts( [ 'post_type' => 'faq', 'posts_per_page' => 1, 'post_status' => 'any', 'fields' => 'ids', 'meta_key' => 'glc_legacy_id', 'meta_value' => $legacy_id ] );
	wp_insert_post( [
		'ID'           => $existing ? (int) $existing[0] : 0,
		'post_type'    => 'faq',
		'post_status'  => 'publish',
		'post_title'   => $qa['name'],
		'post_content' => $qa['acceptedAnswer']['text'],
		'menu_order'   => $i,
		'meta_input'   => [ 'glc_legacy_id' => $legacy_id ],
	] );
	$faq_num++;
}
WP_CLI::log( "FAQs imported: {$faq_num}" );

/* Orphan Outlander photo set → attach to the first Outlander missing photos */
$outlanders = get_posts( [ 'post_type' => 'car', 'posts_per_page' => -1, 's' => 'Outlander', 'orderby' => 'menu_order', 'order' => 'ASC' ] );
$orphan_dir = '/migration/assets/cars/mitsubishi-outlander-lc-235-ll';
$orphan_ids = [];
if ( is_dir( $orphan_dir ) && $outlanders ) {
	$owner = $outlanders[0]->ID;
	foreach ( glob( $orphan_dir . '/*.jpg' ) as $i => $file ) {
		$tmp = wp_tempnam( basename( $file ) );
		copy( $file, $tmp );
		$att = media_handle_sideload( [ 'name' => 'mitsubishi-outlander-' . basename( $file ), 'tmp_name' => $tmp ], $owner );
		if ( ! is_wp_error( $att ) ) {
			update_post_meta( $att, '_wp_attachment_image_alt', get_the_title( $owner ) . ' — photo ' . ( $i + 1 ) );
			$orphan_ids[] = (int) $att;
		}
	}
	WP_CLI::log( 'Orphan Outlander set imported: ' . count( $orphan_ids ) . ' photos' );
}

/* Gallery fallbacks by brand+model */
$cars = get_posts( [ 'post_type' => 'car', 'posts_per_page' => -1 ] );
$with_photos = [];
foreach ( $cars as $car ) {
	$gallery = get_post_meta( $car->ID, 'glc_gallery', true );
	if ( is_array( $gallery ) && $gallery ) {
		// Key by brand+model (title minus trailing year).
		$key = preg_replace( '/\s\d{4}$/', '', $car->post_title );
		$with_photos[ $key ][] = [ 'id' => $car->ID, 'gallery' => $gallery ];
	}
}
if ( $orphan_ids ) {
	$with_photos['Mitsubishi Outlander'][] = [ 'id' => 0, 'gallery' => $orphan_ids ];
}

$fixed = 0;
foreach ( $cars as $car ) {
	$gallery = get_post_meta( $car->ID, 'glc_gallery', true );
	if ( is_array( $gallery ) && $gallery ) {
		continue;
	}
	$key = preg_replace( '/\s\d{4}$/', '', $car->post_title );
	if ( empty( $with_photos[ $key ] ) ) {
		WP_CLI::warning( "No donor gallery for {$car->post_title}" );
		continue;
	}
	$donor = $with_photos[ $key ][ $fixed % count( $with_photos[ $key ] ) ];
	update_post_meta( $car->ID, 'glc_gallery', $donor['gallery'] );
	update_post_meta( $car->ID, 'glc_gallery_borrowed', 1 );
	set_post_thumbnail( $car->ID, $donor['gallery'][0] );
	WP_CLI::log( "  ✓ {$car->post_title} borrows " . count( $donor['gallery'] ) . ' photos' );
	$fixed++;
}
WP_CLI::success( "Fix pass done. Galleries borrowed: {$fixed}" );
