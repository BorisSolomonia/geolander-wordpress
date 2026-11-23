<?php
/**
 * Make the modern picture formats for uploads that came before GLC_Images.
 *
 *   wp eval-file /migration/backfill-webp.php dry-run --allow-root
 *   wp eval-file /migration/backfill-webp.php --allow-root
 *
 * Positional `dry-run`, because wp-cli claims any --flag as its own.
 *
 * NOTHING IS OVERWRITTEN AND NOTHING IS DELETED. For each upload and each size
 * of it, a WebP (and an AVIF when that is switched on in Settings → Geolander)
 * is written BESIDE the original, with the same name and a different extension.
 * The originals, their sizes and every URL already published keep working
 * exactly as they are, and a sibling that is already there and newer than its
 * source is left alone — so running this twice does the same thing as running
 * it once, and running it after a fresh upload is a no-op.
 *
 * Only a sibling that is genuinely smaller is kept; see GLC_Images::write().
 *
 * Memory: the container is capped and image editing is the hungriest thing
 * WordPress does, so this works one upload at a time and lets each editor go
 * before the next, the way _migration/regenerate-sizes.php does.
 */

defined( 'WP_CLI' ) || exit;

if ( ! class_exists( 'GLC_Images' ) ) {
	WP_CLI::error( 'GLC_Images is not loaded: is the geolander-core plugin active?' );
}

$dry     = in_array( 'dry-run', (array) ( $args ?? [] ), true );
$formats = GLC_Images::wanted();
if ( ! $formats ) {
	WP_CLI::error( 'No modern format is switched on, or this server cannot write one. See Settings → Geolander → Pictures.' );
}
WP_CLI::log( 'formats:              ' . implode( ', ', array_keys( $formats ) ) );

$ids = get_posts( [
	'post_type'      => 'attachment',
	'post_status'    => 'inherit',
	'post_mime_type' => 'image',
	'posts_per_page' => -1,
	'fields'         => 'ids',
	'orderby'        => 'ID',
	'order'          => 'ASC',
] );
if ( ! $ids ) {
	WP_CLI::error( 'No image attachments found.' );
}

$before = GLC_Images::tally( $ids );

$examined = 0;
$touched  = 0;
$skipped  = 0;
$peak     = memory_get_peak_usage( true );

foreach ( $ids as $i => $id ) {
	$id       = (int) $id;
	$metadata = wp_get_attachment_metadata( $id );
	$file     = (string) get_attached_file( $id );
	++$examined;

	if ( ! is_array( $metadata ) || empty( $metadata['file'] ) || '' === $file || ! is_file( $file ) ) {
		++$skipped;
		continue;
	}
	// A WebP or an AVIF upload is already modern and needs nothing beside it.
	if ( in_array( (string) get_post_mime_type( $id ), [ 'image/webp', 'image/avif' ], true ) ) {
		++$skipped;
		continue;
	}

	// What is missing, which is also what the dry run reports.
	$directory = trailingslashit( dirname( $file ) );
	$names     = [ basename( $file ) ];
	foreach ( (array) ( $metadata['sizes'] ?? [] ) as $size ) {
		if ( ! empty( $size['file'] ) ) {
			$names[] = (string) $size['file'];
		}
	}
	$missing = 0;
	foreach ( array_unique( $names ) as $name ) {
		foreach ( $formats as $about ) {
			$sibling = $directory . pathinfo( $name, PATHINFO_FILENAME ) . '.' . $about['extension'];
			if ( is_file( $directory . $name ) && ! is_file( $sibling ) ) {
				++$missing;
			}
		}
	}
	if ( ! $missing ) {
		continue;
	}

	if ( $dry ) {
		++$touched;
		if ( $touched <= 10 ) {
			WP_CLI::log( sprintf( '[dry-run] #%d %s — would write %d file(s)', $id, basename( (string) $metadata['file'] ), $missing ) );
		}
		continue;
	}

	GLC_Images::make_siblings( $metadata, $id );
	++$touched;

	if ( 0 === $touched % 20 ) {
		wp_cache_flush();
	}
	if ( 0 === ( (int) $i + 1 ) % 25 ) {
		WP_CLI::log( sprintf( '  … %d/%d examined, %d written, peak memory %.0f MB', (int) $i + 1, count( $ids ), $touched, memory_get_peak_usage( true ) / 1048576 ) );
	}
}

WP_CLI::log( sprintf( 'uploads examined:     %d', $examined ) );
WP_CLI::log( sprintf( 'uploads skipped:      %d (no file, or already a modern format)', $skipped ) );
WP_CLI::log( sprintf( $dry ? 'uploads that need it: %d' : 'uploads written:      %d', $touched ) );

if ( ! $dry ) {
	$after = GLC_Images::tally( $ids );
	foreach ( $after as $mime => $totals ) {
		WP_CLI::log( sprintf(
			'%-12s %d files, %.1f MB (was %d files, %.1f MB)',
			$mime . ':',
			$totals['files'],
			$totals['bytes'] / MB_IN_BYTES,
			$before[ $mime ]['files'] ?? 0,
			( $before[ $mime ]['bytes'] ?? 0 ) / MB_IN_BYTES
		) );
	}
	WP_CLI::log( sprintf( 'peak memory:          %.0f MB (started at %.0f MB)', memory_get_peak_usage( true ) / 1048576, $peak / 1048576 ) );
}

WP_CLI::success( $dry ? 'Dry run complete. Nothing was written.' : 'Done. Every original file is untouched.' );
