<?php
/** WP_CLI integration: covers must never replace exact-car media or leak to other routes. */
defined( 'ABSPATH' ) || exit;
if ( ! defined( 'WP_CLI' ) || 'development' !== wp_get_environment_type() ) {
	exit( "Development WP-CLI only.\n" );
}
global $wp_query, $post;
$saved_query = $wp_query;
$saved_post  = $post;
$saved_front = get_option( 'show_on_front' );
$fixture     = wp_insert_post( [ 'post_type' => 'car', 'post_status' => 'draft', 'post_title' => 'Cover isolation fixture' ] );
$check = static function ( $ok, $message ) { if ( ! $ok ) { throw new RuntimeException( $message ); } };
try {
	$manifest = json_decode( file_get_contents( GLC_DIR . 'assets/fleet-covers/manifest.json' ), true );
	$slug     = array_key_first( $manifest );
	$year     = $manifest[ $slug ]['year'];
	update_post_meta( $fixture, 'glc_year', $year );
	$photos = get_posts( [ 'post_type' => 'attachment', 'post_mime_type' => 'image/jpeg', 'numberposts' => 1, 'post_status' => 'inherit', 'fields' => 'ids' ] );
	if ( $photos ) {
		set_post_thumbnail( $fixture, $photos[0] );
		update_post_meta( $fixture, 'glc_gallery', $photos );
	}
	$before = [ get_post_thumbnail_id( $fixture ), get_post_meta( $fixture, 'glc_gallery', true ) ];
	$car = get_post( $fixture );
	$car->post_name = $slug;
	update_option( 'show_on_front', 'posts' );
	$wp_query = new WP_Query();
	$wp_query->is_home = true;
	$html = GLC_Fleet_Covers::image( $car, 1 );
	$check( str_contains( $html, 'data-glc-studio-cover' ), 'Homepage must use the cover' );
	$check( str_contains( $html, 'loading="eager"' ) && str_contains( $html, '360w' ) && str_contains( $html, '720w' ), 'Responsive cover and eager loading missing' );
	$check( str_contains( GLC_Fleet_Covers::image( $car, 4 ), 'loading="lazy"' ), 'Later covers must be lazy' );
	$wp_query->is_home = false;
	$wp_query->is_post_type_archive = true;
	$wp_query->query_vars['post_type'] = 'car';
	$check( '' !== GLC_Fleet_Covers::image( $car, 1 ), 'Fleet archive must use the cover' );
	$card_method = new ReflectionMethod( GLC_Blocks::class, 'card' );
	$card_html = $card_method->invoke( null, $car, 1, '2027-01-10', '2027-01-13' );
	$check( preg_match( '/<a href="([^"]+)"><picture data-glc-studio-cover/', $card_html, $link ), 'Cover must link to its own car page' );
	$check( str_contains( $link[1], 'from=2027-01-10' ) && str_contains( $link[1], 'to=2027-01-13' ), 'Cover click must retain both booking dates' );
	$wp_query->is_feed = true;
	$check( '' === GLC_Fleet_Covers::image( $car, 1 ), 'Feeds must retain actual photos' );
	$wp_query->is_feed = false;
	update_post_meta( $fixture, 'glc_year', '1900' );
	$check( '' === GLC_Fleet_Covers::image( $car, 1 ), 'Changed years must fail safely to actual photos' );
	update_post_meta( $fixture, 'glc_year', $year );
	$car->post_name = 'unmapped-new-car';
	$check( '' === GLC_Fleet_Covers::image( $car, 1 ), 'New cars must retain actual photos' );
	$car->post_name = $slug;
	$wp_query = new WP_Query();
	$wp_query->is_page = true;
	$check( '' === GLC_Fleet_Covers::image( $car, 1 ), 'City and other pages must retain actual photos' );
	$wp_query->is_page = false;
	$wp_query->is_singular = true;
	$wp_query->is_single = true;
	$wp_query->queried_object = $car;
	$post = $car;
	$check( '' === GLC_Fleet_Covers::image( $car, 1 ), 'Exact car page must never use a generated cover' );
	$gallery = GLC_Blocks::car_gallery();
	$check( ! str_contains( $gallery, 'assets/fleet-covers' ), 'Generated cover leaked into actual gallery' );
	$check( $before === [ get_post_thumbnail_id( $fixture ), get_post_meta( $fixture, 'glc_gallery', true ) ], 'Actual media references changed' );
	echo "PASS: home/archive only; responsive images; year/slug fallbacks; actual gallery and media preserved\n";
} finally {
	$wp_query = $saved_query;
	$post = $saved_post;
	update_option( 'show_on_front', $saved_front );
	wp_delete_post( $fixture, true );
}
