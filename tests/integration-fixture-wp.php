<?php
/** Local suite prerequisites; generated account passwords never reach stdout. */
if ( ! defined( 'WP_CLI' ) || 'development' !== wp_get_environment_type() ) {
	exit( "Development WP-CLI only.\n" );
}
add_filter( 'pre_wp_mail', '__return_true' );
$suite = $args[0] ?? '';
if ( 'cleanup' === $suite ) {
	foreach ( (array) get_option( 'glc_integration_media', [] ) as $attachment ) {
		wp_delete_attachment( (int) $attachment, true );
	}
	delete_option( 'glc_integration_media' );
	return;
}
if ( in_array( $suite, [ 'partners-wp.php', 'photos-specs-wp.php', 'bulk-excel-wp.php' ], true ) ) {
	$cars = get_posts( [ 'post_type' => 'car', 'post_status' => 'publish', 'posts_per_page' => -1, 'fields' => 'ids', 'orderby' => 'ID', 'order' => 'ASC' ] );
	if ( count( $cars ) < 7 ) {
		WP_CLI::error( 'Seed at least seven local cars first.' );
	}
	update_post_meta( (int) end( $cars ), 'glc_whatsapp_number', '+995555000909' );
}
if ( in_array( $suite, [ 'photos-specs-wp.php', 'bulk-excel-wp.php' ], true ) ) {
	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-admin/includes/media.php';
	require_once ABSPATH . 'wp-admin/includes/image.php';
	$made = [];
	foreach ( array_slice( $cars, 0, 6 ) as $index => $car ) {
		$table = [];
		foreach ( [ [ '07-03', '08-02', 54 ], [ '11-01', '12-24', 30 ], [ '12-25', '01-05', 40 ], [ '01-06', '03-31', 28 ], [ '04-01', '05-05', 34 ], [ '05-06', '06-12', 38 ], [ '06-13', '07-02', 46 ], [ '08-03', '09-03', 50 ], [ '09-04', '10-31', 36 ] ] as [ $from, $to, $top ] ) {
			$rates = [];
			foreach ( GLC_Pricing::TIERS as $band => $key ) {
				$rates[ $key ] = $top - [ 0, 4, 6, 8, 10, 12, 14 ][ $band ] + ( 5 === $index ? 20 : 0 );
			}
			$table[] = [ 'label' => GLC_Price_Store::season_label( $from, $to ), 'from' => $from, 'to' => $to, 'rates' => $rates ];
		}
		update_post_meta( $car, 'glc_pricing', $table );
		if ( 0 === $index ) {
			update_post_meta( $car, 'glc_gallery', [] );
			delete_post_meta( $car, '_thumbnail_id' );
			continue;
		}
		$gallery = [];
		foreach ( [ 'front', 'rear' ] as $view ) {
			$image = imagecreatetruecolor( 1920, 1280 );
			imagefill( $image, 0, 0, imagecolorallocate( $image, 70 + 20 * $index, 110, 150 ) );
			$file = wp_tempnam( 'glc-integration.jpg' );
			imagejpeg( $image, $file, 85 );
			imagedestroy( $image );
			$id = media_handle_sideload( [ 'name' => "glc-integration-{$car}-{$view}.jpg", 'tmp_name' => $file ], $car );
			if ( is_wp_error( $id ) ) {
				WP_CLI::error( 'Could not create local photo fixture.' );
			}
			wp_update_post( [ 'ID' => $id, 'post_author' => 0 ] );
			update_post_meta( $id, '_wp_attachment_image_alt', 'Integration fixture ' . $view );
			$made[] = $id;
			$gallery[] = $id;
			update_option( 'glc_integration_media', $made, false );
		}
		update_post_meta( $car, 'glc_gallery', $gallery );
		set_post_thumbnail( $car, $gallery[0] );
	}
	$args = [];
	ob_start();
	try {
		require __DIR__ . '/partner-authority.setup.php';
	} finally {
		ob_end_clean();
	}
}
