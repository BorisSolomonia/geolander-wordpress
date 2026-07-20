<?php
/**
 * Bulk car importer — one folder per car.
 * Run: wp eval-file /migration/import-fleet.php
 *
 * ── How it works ──────────────────────────────────────────────────────────
 * Put a folder for each car under  _migration/fleet-import/  (override with the
 * GLC_FLEET_DIR env var). The FOLDER NAME becomes the car title, and the images
 * inside become its photos:
 *
 *   _migration/fleet-import/
 *     ├─ Toyota Land Cruiser 2021/
 *     │    ├─ 01-front.jpg      ← first image (alphabetical) = MAIN / featured photo
 *     │    ├─ 02-side.jpg       ← the rest fill the gallery
 *     │    └─ 03-interior.jpg
 *     └─ Jeep Wrangler 2019/
 *          └─ ...
 *
 * Optional: drop a  car.json  inside a car's folder to set specs + seasonal
 * pricing too (see the template printed by --help below). Without it the car is
 * created with photos only — it still works with the WhatsApp button; add specs
 * and Seasonal Pricing later in the admin (or via car.json) to enable dated quotes.
 *
 * Safe to re-run: cars are matched by name and updated in place, and images are
 * de-duplicated by content hash, so nothing doubles up.
 * ──────────────────────────────────────────────────────────────────────────
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit( "Run via: wp eval-file /migration/import-fleet.php\n" );
}

require_once ABSPATH . 'wp-admin/includes/media.php';
require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/image.php';

$base = getenv( 'GLC_FLEET_DIR' ) ?: ( dirname( __FILE__ ) . '/fleet-import' );

if ( ! is_dir( $base ) ) {
	WP_CLI::error( "Folder not found: {$base}\nCreate _migration/fleet-import/ with one sub-folder per car, then re-run." );
}

/** Sideload one image file, de-duplicated by content hash. Returns attachment ID or 0. */
function glc_fleet_image( string $path, int $parent, string $alt ): int {
	if ( ! is_file( $path ) ) {
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
	$att_id = media_handle_sideload( [ 'name' => basename( $path ), 'tmp_name' => $tmp ], $parent );
	if ( is_wp_error( $att_id ) ) {
		@unlink( $tmp );
		WP_CLI::warning( '  image failed ' . basename( $path ) . ': ' . $att_id->get_error_message() );
		return 0;
	}
	update_post_meta( $att_id, 'glc_source_hash', $hash );
	update_post_meta( $att_id, '_wp_attachment_image_alt', $alt );
	return (int) $att_id;
}

/** Apply an optional car.json (specs, taxonomies, seasonal pricing). */
function glc_fleet_apply_json( int $post_id, array $data ): void {
	$meta = [
		'glc_year'         => 'year',
		'glc_seats'        => 'seats',
		'glc_color'        => 'color',
		'glc_transmission' => 'transmission',
		'glc_fuel_type'    => 'fuel',
		'glc_registration' => 'registration',
		'glc_price_from'   => 'price_from',
	];
	foreach ( $meta as $key => $src ) {
		if ( isset( $data[ $src ] ) && '' !== $data[ $src ] ) {
			update_post_meta( $post_id, $key, sanitize_text_field( (string) $data[ $src ] ) );
		}
	}
	if ( isset( $data['available'] ) ) {
		update_post_meta( $post_id, 'glc_available', (bool) $data['available'] );
	}
	if ( ! empty( $data['brand'] ) ) {
		wp_set_object_terms( $post_id, sanitize_text_field( $data['brand'] ), 'car_brand' );
	}
	if ( ! empty( $data['body_type'] ) ) {
		wp_set_object_terms( $post_id, sanitize_text_field( $data['body_type'] ), 'car_body_type' );
	}
	// Seasonal pricing: pass through as-is if it already matches the plugin shape
	// [ { label, from(MM-DD), to(MM-DD), rates:{d1_2,…} }, … ].
	if ( ! empty( $data['pricing'] ) && is_array( $data['pricing'] ) ) {
		update_post_meta( $post_id, 'glc_pricing', $data['pricing'] );
	}
}

$dirs = array_values( array_filter( glob( $base . '/*', GLOB_ONLYDIR ) ?: [] ) );
if ( ! $dirs ) {
	WP_CLI::error( "No car sub-folders inside {$base}. Add one folder per car (folder name = car name)." );
}

WP_CLI::log( 'Importing ' . count( $dirs ) . ' cars from ' . $base );
$created = 0;
$updated = 0;

foreach ( $dirs as $dir ) {
	$title = trim( basename( $dir ) );
	$slug  = sanitize_title( $title );

	// Idempotent: reuse an existing car with the same slug.
	$existing = get_posts( [ 'post_type' => 'car', 'name' => $slug, 'posts_per_page' => 1, 'post_status' => 'any' ] );
	$post_id  = wp_insert_post( [
		'ID'          => $existing[0]->ID ?? 0,
		'post_type'   => 'car',
		'post_status' => 'publish',
		'post_name'   => $slug,
		'post_title'  => $title,
	] );
	if ( is_wp_error( $post_id ) ) {
		WP_CLI::warning( "  ✗ {$title}: " . $post_id->get_error_message() );
		continue;
	}
	$existing ? $updated++ : $created++;

	// Optional specs / pricing.
	$json = $dir . '/car.json';
	$has_pricing = false;
	if ( is_file( $json ) ) {
		$data = json_decode( (string) file_get_contents( $json ), true );
		if ( is_array( $data ) ) {
			glc_fleet_apply_json( $post_id, $data );
			$has_pricing = ! empty( $data['pricing'] );
		} else {
			WP_CLI::warning( "  car.json in {$title} is not valid JSON — skipped its specs." );
		}
	}

	// Images: sorted so a NN- prefix controls order; first = featured.
	$files = glob( $dir . '/*.{jpg,jpeg,png,webp,JPG,JPEG,PNG,WEBP}', GLOB_BRACE ) ?: [];
	sort( $files, SORT_NATURAL | SORT_FLAG_CASE );
	$gallery = [];
	foreach ( $files as $file ) {
		$att = glc_fleet_image( $file, $post_id, $title );
		if ( $att ) {
			$gallery[] = $att;
		}
	}
	if ( $gallery ) {
		set_post_thumbnail( $post_id, $gallery[0] );
		update_post_meta( $post_id, 'glc_gallery', $gallery );
	}

	$note = $gallery ? count( $gallery ) . ' photo(s)' : 'NO photos';
	$warn = $has_pricing ? '' : '  ⚠ no pricing (WhatsApp works; add pricing for dated quotes)';
	WP_CLI::log( "  ✓ {$title} — {$note}{$warn}" );
}

WP_CLI::success( "Done. Created {$created}, updated {$updated}." );
