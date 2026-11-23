<?php
/**
 * Create the missing image sizes so WordPress can build a srcset.
 *
 *   wp eval-file /migration/regenerate-sizes.php dry-run --allow-root
 *   wp eval-file /migration/regenerate-sizes.php --allow-root
 *
 * Positional `dry-run`: wp-cli claims any --flag as its own.
 *
 * ONLY MISSING FILES ARE WRITTEN. Existing crops are left exactly as they are,
 * so this cannot change how any current page looks; it only adds the smaller
 * siblings that a srcset needs. Re-running it is a no-op.
 *
 * Why this is needed at all: WordPress picks srcset candidates by aspect ratio.
 * glc-card (3:2) and glc-hero (16:9) had no same-ratio siblings, so every page
 * shipped without a srcset and phones downloaded desktop-sized photographs.
 *
 * Memory: the container is capped at 384MB and image editing is the hungriest
 * thing WordPress does, so this works one attachment at a time and frees the
 * editor after each, rather than loading a batch.
 */

defined( 'WP_CLI' ) || exit;

$dry = in_array( 'dry-run', (array) ( $args ?? [] ), true );

$ids = get_posts( [
	'post_type'      => 'attachment',
	'post_status'    => 'inherit',
	'post_mime_type' => 'image',
	'posts_per_page' => -1,
	'fields'         => 'ids',
] );
if ( ! $ids ) {
	WP_CLI::error( 'No image attachments found.' );
}

$wanted = array_keys( GLC_IMAGE_SIZES );
$uploads = wp_get_upload_dir();

$needed = 0; $done = 0; $failed = 0; $bytes = 0;
$peak_start = memory_get_peak_usage( true );

foreach ( $ids as $i => $id ) {
	$meta = wp_get_attachment_metadata( $id );
	if ( ! is_array( $meta ) || empty( $meta['file'] ) ) { continue; }

	$missing = [];
	foreach ( $wanted as $size ) {
		if ( empty( $meta['sizes'][ $size ]['file'] ) ) { $missing[] = $size; }
	}
	// An original smaller than a crop cannot produce it; that is not a failure.
	if ( ! $missing ) { continue; }
	$needed++;

	if ( $dry ) {
		if ( $needed <= 5 ) {
			WP_CLI::log( sprintf( '[dry-run] #%d %s — would add: %s', $id, basename( $meta['file'] ), implode( ', ', $missing ) ) );
		}
		continue;
	}

	$path = $uploads['basedir'] . '/' . $meta['file'];
	if ( ! file_exists( $path ) ) { $failed++; continue; }

	$fresh = wp_generate_attachment_metadata( $id, $path );
	if ( is_array( $fresh ) && ! empty( $fresh['sizes'] ) ) {
		wp_update_attachment_metadata( $id, $fresh );
		foreach ( $missing as $size ) {
			$f = $uploads['basedir'] . '/' . dirname( $meta['file'] ) . '/' . ( $fresh['sizes'][ $size ]['file'] ?? '' );
			if ( ! empty( $fresh['sizes'][ $size ]['file'] ) && file_exists( $f ) ) { $bytes += filesize( $f ); }
		}
		$done++;
	} else {
		$failed++;
	}

	// Release the image editor's buffers before the next file.
	if ( function_exists( 'wp_cache_flush' ) && 0 === $done % 20 ) { wp_cache_flush(); }
	if ( 0 === ( $i + 1 ) % 25 ) {
		WP_CLI::log( sprintf( '  … %d/%d processed, peak memory %.0f MB', $i + 1, count( $ids ), memory_get_peak_usage( true ) / 1048576 ) );
	}
}

WP_CLI::log( sprintf( 'images examined:      %d', count( $ids ) ) );
WP_CLI::log( sprintf( 'needed new sizes:     %d', $needed ) );
if ( ! $dry ) {
	WP_CLI::log( sprintf( 'regenerated:          %d', $done ) );
	WP_CLI::log( sprintf( 'failed:               %d', $failed ) );
	WP_CLI::log( sprintf( 'extra disk used:      %.1f MB', $bytes / 1048576 ) );
	WP_CLI::log( sprintf( 'peak memory:          %.0f MB (started at %.0f MB)', memory_get_peak_usage( true ) / 1048576, $peak_start / 1048576 ) );
}

WP_CLI::success( $dry ? 'Dry run complete. Nothing was written.' : 'Sizes regenerated. Existing crops were not touched.' );
