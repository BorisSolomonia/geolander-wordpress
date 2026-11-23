<?php
/**
 * Report the featured image format and source size for every published place.
 *
 * Run with:
 *   wp eval-file /migration/audit-place-images.php
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit( "Run via wp eval-file\n" );
}

$places = get_posts( [
	'post_type'      => 'place',
	'post_status'    => 'publish',
	'posts_per_page' => -1,
	'orderby'        => 'title',
	'order'          => 'ASC',
] );

$rows       = [];
$total      = 0;
$oversized  = 0;
$non_webp   = 0;
$missing    = 0;

foreach ( $places as $place ) {
	$attachment_id = get_post_thumbnail_id( $place );
	$file          = $attachment_id ? get_attached_file( $attachment_id ) : '';
	$bytes         = $file && file_exists( $file ) ? (int) filesize( $file ) : 0;
	$mime          = $attachment_id ? (string) get_post_mime_type( $attachment_id ) : '';

	if ( ! $attachment_id || ! $bytes ) {
		++$missing;
	}
	if ( 'image/webp' !== $mime ) {
		++$non_webp;
	}
	if ( $bytes > 500 * KB_IN_BYTES ) {
		++$oversized;
	}
	$total += $bytes;

	$rows[] = [
		'ID'     => $place->ID,
		'Place'  => $place->post_title,
		'MIME'   => $mime ?: 'missing',
		'SizeKB' => $bytes ? (string) round( $bytes / KB_IN_BYTES ) : '-',
		'File'   => $file ? basename( $file ) : '-',
	];
}

if ( $rows ) {
	WP_CLI\Utils\format_items( 'table', $rows, [ 'ID', 'Place', 'MIME', 'SizeKB', 'File' ] );
}

WP_CLI::log( sprintf(
	'Published places: %d; featured source total: %.1f MB; non-WebP: %d; over 500 KB: %d; missing: %d.',
	count( $places ),
	$total / MB_IN_BYTES,
	$non_webp,
	$oversized,
	$missing
) );
