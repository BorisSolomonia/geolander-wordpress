<?php
/**
 * Create WebP counterparts for images attached to published place pages.
 *
 * Original attachments and files remain intact, preserving every existing URL.
 * Converted attachments are linked back to their source and safely reused on
 * subsequent runs. Featured images are switched to the WebP counterpart.
 *
 * Run with:
 *   wp eval-file /migration/convert-place-images-webp.php
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit( "Run via wp eval-file\n" );
}

require_once ABSPATH . 'wp-admin/includes/image.php';

$place_ids = get_posts( [
	'post_type'      => 'place',
	'post_status'    => 'publish',
	'posts_per_page' => -1,
	'fields'         => 'ids',
] );

if ( ! $place_ids ) {
	WP_CLI::success( 'No published place images to convert.' );
	return;
}

$attachments = get_posts( [
	'post_type'      => 'attachment',
	'post_status'    => 'inherit',
	'post_parent__in'=> $place_ids,
	'post_mime_type' => 'image',
	'posts_per_page' => -1,
	'orderby'        => 'ID',
	'order'          => 'ASC',
] );

$source_bytes = 0;
foreach ( $attachments as $attachment ) {
	$file = get_attached_file( $attachment->ID );
	if ( $file && file_exists( $file ) && 'image/webp' !== $attachment->post_mime_type ) {
		$source_bytes += (int) filesize( $file );
	}
}

$upload = wp_upload_dir();
$free   = @disk_free_space( $upload['basedir'] );
if ( false !== $free && $free < max( $source_bytes, 16 * MB_IN_BYTES ) ) {
	WP_CLI::error( sprintf(
		'Not enough free upload space for a URL-preserving conversion. Free %.1f MB; require at least %.1f MB.',
		$free / MB_IN_BYTES,
		max( $source_bytes, 16 * MB_IN_BYTES ) / MB_IN_BYTES
	) );
}

$created = 0;
$reused  = 0;
$skipped = 0;
$saved_bytes = 0;

foreach ( $attachments as $attachment ) {
	if ( 'image/webp' === $attachment->post_mime_type ) {
		++$skipped;
		continue;
	}

	$source_id = (int) $attachment->ID;
	$webp_id   = (int) get_post_meta( $source_id, 'glc_webp_attachment_id', true );
	if ( $webp_id && 'image/webp' === get_post_mime_type( $webp_id ) && file_exists( (string) get_attached_file( $webp_id ) ) ) {
		++$reused;
	} else {
		$source = get_attached_file( $source_id );
		if ( ! $source || ! file_exists( $source ) ) {
			WP_CLI::warning( "Attachment {$source_id} has no readable source file." );
			++$skipped;
			continue;
		}

		$editor = wp_get_image_editor( $source );
		if ( is_wp_error( $editor ) ) {
			WP_CLI::warning( "Attachment {$source_id}: " . $editor->get_error_message() );
			++$skipped;
			continue;
		}
		$size = $editor->get_size();
		if ( ! empty( $size['width'] ) && $size['width'] > 1600 ) {
			$editor->resize( 1600, null, false );
		}
		$editor->set_quality( 76 );

		$directory = dirname( $source );
		$filename  = wp_unique_filename( $directory, pathinfo( $source, PATHINFO_FILENAME ) . '.webp' );
		$saved     = $editor->save( trailingslashit( $directory ) . $filename, 'image/webp' );
		if ( is_wp_error( $saved ) ) {
			WP_CLI::warning( "Attachment {$source_id}: " . $saved->get_error_message() );
			++$skipped;
			continue;
		}

		$source_url = wp_get_attachment_url( $source_id );
		$webp_id = wp_insert_attachment( [
			'post_mime_type' => 'image/webp',
			'post_title'     => $attachment->post_title,
			'post_content'   => '',
			'post_excerpt'   => $attachment->post_excerpt,
			'post_status'    => 'inherit',
			'post_parent'    => $attachment->post_parent,
			'guid'           => trailingslashit( dirname( $source_url ) ) . basename( $saved['path'] ),
		], $saved['path'], $attachment->post_parent, true );
		if ( is_wp_error( $webp_id ) ) {
			@unlink( $saved['path'] );
			WP_CLI::warning( "Attachment {$source_id}: " . $webp_id->get_error_message() );
			++$skipped;
			continue;
		}

		wp_update_attachment_metadata( $webp_id, wp_generate_attachment_metadata( $webp_id, $saved['path'] ) );
		update_post_meta( $webp_id, '_wp_attachment_image_alt', get_post_meta( $source_id, '_wp_attachment_image_alt', true ) );
		update_post_meta( $webp_id, 'glc_webp_source_id', $source_id );
		update_post_meta( $source_id, 'glc_webp_attachment_id', $webp_id );
		$saved_bytes += (int) filesize( $saved['path'] );
		++$created;
	}

	foreach ( $place_ids as $place_id ) {
		if ( $source_id === (int) get_post_thumbnail_id( $place_id ) ) {
			set_post_thumbnail( $place_id, $webp_id );
		}
	}
}

WP_CLI::success( sprintf(
	'Place WebP conversion complete: %d created (%.1f MB), %d reused, %d skipped. Original URLs retained.',
	$created,
	$saved_bytes / MB_IN_BYTES,
	$reused,
	$skipped
) );
