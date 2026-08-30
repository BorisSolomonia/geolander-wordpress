<?php
/**
 * Crop transparent logo padding and resize the source without changing its URL.
 * Run with: wp eval-file /migration/optimize-brand-image.php
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit( "Run via wp eval-file\n" );
}

$file   = get_theme_file_path( 'assets/img/logo.png' );
$editor = wp_get_image_editor( $file );
if ( is_wp_error( $editor ) ) {
	WP_CLI::error( $editor->get_error_message() );
}

$size = $editor->get_size();
if ( 1024 === (int) $size['width'] && 1024 === (int) $size['height'] ) {
	// Alpha bounds of the checked-in source, with the original composition kept.
	$result = $editor->crop( 80, 168, 884, 549, 442, 275, false );
	if ( is_wp_error( $result ) ) {
		WP_CLI::error( $result->get_error_message() );
	}
	$editor->set_quality( 82 );
	$saved = $editor->save( $file, 'image/png' );
	if ( is_wp_error( $saved ) ) {
		WP_CLI::error( $saved->get_error_message() );
	}
	WP_CLI::success( sprintf( 'Logo optimized to %dx%d (%d KB); URL unchanged.', 442, 275, filesize( $file ) / KB_IN_BYTES ) );
	return;
}

if ( $size['width'] <= 442 && $size['height'] <= 275 ) {
	WP_CLI::success( 'Logo is already optimized.' );
	return;
}

WP_CLI::error( sprintf(
	'Unexpected logo dimensions %dx%d; refusing a coordinate-based crop.',
	$size['width'],
	$size['height']
) );
