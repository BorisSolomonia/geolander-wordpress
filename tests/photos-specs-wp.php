<?php
/**
 * Photos and details by partners, the panel language and what belongs to it,
 * against a real WordPress and a real database: what an upload becomes, which
 * photo may go into which gallery, what a save stores, and what a partner's
 * account leaves behind when it is deleted. tests/partner-authority.mjs asks
 * the same screens over HTTP, where "who is asking" is a signed-in browser.
 *
 * LOCAL COPY ONLY. It writes galleries and details to cars and to the change
 * log, and changes settings for a moment. Put the database back afterwards
 * (reset script).
 *
 * Needs the fixture of tests/partner-authority.mjs (`bun tests/partner-authority.mjs setup`).
 *
 * Run: docker exec -i <wordpress container> wp eval-file - --allow-root < tests/photos-specs-wp.php
 *
 * Every picture is made here with GD (bands of colour): no real photo is used.
 * Every attachment and account this test creates is removed at its end, and
 * its last check counts the files in the uploads folder against the count at
 * its start. Every "is it stored" question is answered with SQL or from the
 * file on disk, not with the functions that wrote it.
 *
 * Under WP-CLI the store trusts the caller (a shell is the owner's), so the
 * rules about WHOSE photo it is are asked of the store's validator directly,
 * with the person named; the HTTP test asks them through the real handlers.
 */

if ( ! defined( 'ABSPATH' ) || ! defined( 'WP_CLI' ) ) {
	exit( "Run via wp eval-file\n" );
}
if ( 'production' === wp_get_environment_type() ) {
	WP_CLI::error( 'This test writes to cars and uploads files. It does not run on a production site.' );
}
require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/media.php';
require_once ABSPATH . 'wp-admin/includes/image.php';
require_once ABSPATH . 'wp-admin/includes/user.php';

global $wpdb;
$glc_failures = 0;
$glc_check    = static function ( $condition, string $label ) use ( &$glc_failures ): void {
	if ( ! $condition ) {
		++$glc_failures;
	}
	WP_CLI::log( ( $condition ? 'PASS ' : 'FAIL ' ) . $label );
};
/** One meta value exactly as the database holds it: null = no row, '' = an empty row. */
$glc_raw = static function ( int $id, string $key ) use ( $wpdb ) {
	$rows = $wpdb->get_results( $wpdb->prepare( "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s ORDER BY meta_id LIMIT 1", $id, $key ), ARRAY_N );
	return $rows ? $rows[0][0] : null;
};
$glc_post = static fn( int $id ) => $wpdb->get_row( $wpdb->prepare( "SELECT post_type, post_author, post_parent, post_mime_type, post_status FROM {$wpdb->posts} WHERE ID = %d", $id ), ARRAY_A );
$glc_log_count = static fn(): int => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s", GLC_Change_Log::POST_TYPE ) );
$glc_last_log  = static function () use ( $wpdb, $glc_raw ): array {
	$id  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s ORDER BY ID DESC LIMIT 1", GLC_Change_Log::POST_TYPE ) );
	$out = [ 'id' => $id ];
	foreach ( [ 'glc_car_id', 'glc_actor', 'glc_kind', 'glc_source', 'glc_before', 'glc_after', 'glc_note' ] as $key ) {
		$out[ $key ] = $glc_raw( $id, $key );
	}
	return $out;
};
/** Everything of a car that is not a detail a partner may change, its gallery, its main photo or its revision: one fingerprint. */
$glc_rest_of_car = static function ( int $car ) use ( $wpdb ): string {
	$skip = array_merge( array_keys( GLC_Price_Store::SPEC_FIELDS ), [ 'glc_gallery', '_thumbnail_id', 'glc_rev', '_edit_lock' ] );
	$rows = array_values( array_filter( $wpdb->get_results( $wpdb->prepare( "SELECT meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id = %d ORDER BY meta_key, meta_id", $car ), ARRAY_N ), static fn( array $row ): bool => ! in_array( $row[0], $skip, true ) ) );
	return md5( wp_json_encode( [ $rows, $wpdb->get_row( $wpdb->prepare( "SELECT post_title, post_name, post_content, post_excerpt, post_status FROM {$wpdb->posts} WHERE ID = %d", $car ), ARRAY_N ) ] ) );
};
$glc_disk = static function (): int {
	$count = 0;
	foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( wp_get_upload_dir()['basedir'], FilesystemIterator::SKIP_DOTS ) ) as $file ) {
		$count += $file->isFile() ? 1 : 0;
	}
	return $count;
};
$glc_made  = [ 'files' => [], 'attachments' => [], 'users' => [] ];
/** A picture made here, as a temporary file: bands of colour, optionally with a block of camera data that says "lying on its side". */
$glc_image = static function ( int $w, int $h, string $kind = 'jpeg', bool $camera = false ) use ( &$glc_made ): string {
	$im = imagecreatetruecolor( $w, $h );
	for ( $i = 0; $i < 8; $i++ ) {
		imagefilledrectangle( $im, (int) ( $i * $w / 8 ), 0, (int) ( ( $i + 1 ) * $w / 8 ), $h, imagecolorallocate( $im, 30 * $i, 255 - 30 * $i, 90 + 20 * $i ) );
	}
	ob_start();
	match ( $kind ) {
		'png'  => imagepng( $im ),
		'webp' => imagewebp( $im ),
		'gif'  => imagegif( $im ),
		default => imagejpeg( $im, null, 90 ),
	};
	$bytes = (string) ob_get_clean();
	if ( $camera ) {
		$exif  = "Exif\x00\x00" . "II*\x00\x08\x00\x00\x00" . "\x01\x00" . "\x12\x01\x03\x00\x01\x00\x00\x00\x06\x00\x00\x00" . "\x00\x00\x00\x00";
		$bytes = substr( $bytes, 0, 2 ) . "\xFF\xE1" . pack( 'n', strlen( $exif ) + 2 ) . $exif . substr( $bytes, 2 );
	}
	$path = wp_tempnam( 'glc-test' );
	file_put_contents( $path, $bytes );
	$glc_made['files'][] = $path;
	return $path;
};
/** What a browser's upload looks like to WordPress, for a file made above. */
$glc_upload = static fn( string $path, string $name, string $type ): array => [ 'name' => $name, 'type' => $type, 'tmp_name' => $path, 'error' => 0, 'size' => filesize( $path ) ];
/** Store a picture as that person would through an upload; returns the attachment id (or the refusal). */
$glc_sideload = static function ( int $user, string $path, string $name ) use ( &$glc_made ) {
	wp_set_current_user( $user );
	$id = media_handle_sideload( [ 'name' => $name, 'tmp_name' => $path ], 0 );
	if ( ! is_wp_error( $id ) ) {
		$glc_made['attachments'][] = (int) $id;
	}
	return $id;
};
$glc_set = static function ( array $changes ): void {
	update_option( 'glc_settings', $changes + (array) get_option( 'glc_settings', [] ) );
};
$glc_codes = static fn( array $errors ): string => implode( ' | ', array_column( $errors, 'message' ) );

$glc_partner = get_user_by( 'login', 'glc_test_partner' );
$glc_other   = get_user_by( 'login', 'glc_test_partner_b' );
$glc_owner   = get_user_by( 'login', 'glc_test_owner' );
$glc_cars    = $glc_partner ? GLC_Partners::cars_for( (int) $glc_partner->ID ) : [];
$glc_theirs  = $glc_other ? GLC_Partners::cars_for( (int) $glc_other->ID ) : [];
if ( ! $glc_cars || ! $glc_theirs || ! $glc_owner ) {
	WP_CLI::error( 'No fixture. Run: bun tests/partner-authority.mjs setup' );
}
$glc_car        = $glc_cars[0];   // the first partner's car
$glc_car_b      = $glc_theirs[0]; // another partner's car, with the site's photos on it
$glc_p          = (int) $glc_partner->ID;
$glc_files_was  = $glc_disk();
$glc_settings   = get_option( 'glc_settings', [] );
$glc_site_photo = (int) GLC_Price_Store::read( $glc_car_b, 'photos' )['gallery'][0];
$glc_as         = static fn( int $user ): array => [ 'actor' => $user, 'trusted' => false ];

/* ------------------------------------------------------------ what may be uploaded */

wp_set_current_user( $glc_p );
$glc_png   = $glc_image( 2400, 1600, 'png' );
$glc_after = GLC_Partner_Media::check_upload( $glc_upload( $glc_png, 'Front View.PNG', 'image/png' ) );
$glc_size  = getimagesize( $glc_png );
$glc_check( 0 === $glc_after['error'] && 'Front View.jpg' === $glc_after['name'] && 'image/jpeg' === $glc_after['type'] && $glc_after['tmp_name'] === $glc_png && 'FFD8FF' === strtoupper( bin2hex( file_get_contents( $glc_png, false, null, 0, 3 ) ) ) && [ 1920, 1280 ] === [ $glc_size[0], $glc_size[1] ] && filesize( $glc_png ) === $glc_after['size'], "A partner's 2400×1600 PNG is rewritten where it lies, before WordPress moves it: a JPEG {$glc_size[0]}×{$glc_size[1]}, named {$glc_after['name']}, " . size_format( $glc_after['size'] ) );
$glc_check( 1920 === GLC_Partner_Media::max_width() && 1920 === max( array_column( GLC_IMAGE_SIZES, 0 ) ), 'The width it is shrunk to is the widest crop the theme registers (GLC_IMAGE_SIZES): ' . GLC_Partner_Media::max_width() );
$glc_small = $glc_image( 1700, 1100, 'webp' );
GLC_Partner_Media::check_upload( $glc_upload( $glc_small, 'small.webp', 'image/webp' ) );
$glc_check( 1700 === getimagesize( $glc_small )[0] && 'image/jpeg' === getimagesize( $glc_small )['mime'], 'A WebP narrower than that keeps its width (nothing is enlarged) and still becomes a JPEG' );
$glc_side  = $glc_image( 2000, 1200, 'jpeg', true );
$glc_had   = str_contains( file_get_contents( $glc_side ), "Exif\0\0" );
GLC_Partner_Media::check_upload( $glc_upload( $glc_side, 'side.jpg', 'image/jpeg' ) );
$glc_size  = getimagesize( $glc_side );
$glc_check( $glc_had && ! str_contains( file_get_contents( $glc_side ), "Exif\0\0" ) && [ 1152, 1920 ] === [ $glc_size[0], $glc_size[1] ], "A picture lying on its side, with a block of camera data saying so: stored upright ({$glc_size[0]}×{$glc_size[1]}) and the block is gone from the file" );

$glc_text = wp_tempnam( 'glc-test' );
file_put_contents( $glc_text, '<?php echo 1; ?>' );
$glc_made['files'][] = $glc_text;
$glc_gif   = $glc_image( 2000, 1500, 'gif' );
$glc_md5   = md5_file( $glc_gif );
$glc_no    = [ GLC_Partner_Media::check_upload( $glc_upload( $glc_text, 'photo.jpg', 'image/jpeg' ) ), GLC_Partner_Media::check_upload( $glc_upload( $glc_gif, 'photo.gif', 'image/gif' ) ) ];
$glc_check( 'This file is not a photo of a kind the site takes. Upload one of: jpeg, png, webp.' === $glc_no[0]['error'] && $glc_no[0]['error'] === $glc_no[1]['error'] && md5_file( $glc_gif ) === $glc_md5, 'Text named photo.jpg and a GIF are refused by what the file is, not by its name: "' . $glc_no[0]['error'] . '"' );
$glc_set( [ 'partner_upload_max_mb' => '0.01' ] );
$glc_big = GLC_Partner_Media::check_upload( $glc_upload( $glc_image( 2400, 1600 ), 'big.jpg', 'image/jpeg' ) );
$glc_check( is_string( $glc_big['error'] ) && preg_match( '/^This file is [\d.]+ (KB|MB)\. The largest photo you can upload is 10\.2 KB\.$/', $glc_big['error'] ) && 10486 === GLC_Partner_Media::max_bytes() && 10486 === apply_filters( 'upload_size_limit', 33554432 ), 'With the limit set to 0.01 MB a larger file is refused and both sizes are named: "' . $glc_big['error'] . '"; the browser is told the same limit' );
$glc_set( [ 'partner_upload_max_mb' => '0' ] );
$glc_closed = GLC_Partner_Media::check_upload( $glc_upload( $glc_image( 1700, 1100 ), 'any.jpg', 'image/jpeg' ) );
$glc_set( [ 'partner_upload_max_mb' => '' ] );
$glc_open = GLC_Partner_Media::check_upload( $glc_upload( $glc_image( 1700, 1100 ), 'any.jpg', 'image/jpeg' ) );
$glc_check( 'Uploading photos is switched off for partners at the moment. Write to Geolander.' === $glc_closed['error'] && 0 === $glc_open['error'] && null === GLC_Partner_Media::max_bytes() && 33554432 === apply_filters( 'upload_size_limit', 33554432 ), 'The setting keeps its three meanings: 0 closes uploads with a sentence, empty leaves only the server\'s own limit' );
$glc_set( [ 'partner_upload_max_mb' => '10', 'partner_image_types' => 'png' ] );
$glc_jpeg = GLC_Partner_Media::check_upload( $glc_upload( $glc_image( 1700, 1100 ), 'any.jpg', 'image/jpeg' ) );
$glc_check( is_string( $glc_jpeg['error'] ) && str_ends_with( $glc_jpeg['error'], 'Upload one of: png.' ) && [ 'png' => 'image/png' ] === get_allowed_mime_types( $glc_partner ), 'The kinds of photo are the setting\'s: with "png" alone a JPEG is refused, and WordPress\'s own list of file types for the partner is png only' );
update_option( 'glc_settings', $glc_settings );
$glc_types = get_allowed_mime_types( $glc_partner );
$glc_check( [ 'image/jpeg', 'image/png', 'image/webp' ] === array_values( $glc_types ) && count( get_allowed_mime_types( $glc_owner ) ) > 20, 'Back at the defaults a partner may upload jpeg, png and webp and nothing else; the owner keeps ' . count( get_allowed_mime_types( $glc_owner ) ) . ' file types' );

wp_set_current_user( (int) $glc_owner->ID );
$glc_logo = $glc_image( 2400, 1600, 'png' );
$glc_md5  = md5_file( $glc_logo );
$glc_same = GLC_Partner_Media::check_upload( $glc_upload( $glc_logo, 'logo.png', 'image/png' ) );
$_REQUEST[ GLC_Partner_Media::FLAG ] = '1';
$glc_car_photo = GLC_Partner_Media::check_upload( $glc_upload( $glc_logo, 'logo.png', 'image/png' ) );
unset( $_REQUEST[ GLC_Partner_Media::FLAG ] );
$glc_check( 'logo.png' === $glc_same['name'] && 'logo.jpg' === $glc_car_photo['name'] && md5_file( $glc_logo ) !== $glc_md5 && 1920 === getimagesize( $glc_logo )[0] && 33554432 === apply_filters( 'upload_size_limit', 33554432 ), 'The owner\'s upload is left exactly as it is (a logo stays a PNG), unless it comes from a car\'s screen: then it is stored as a car photo too. The owner has no size limit of this kind' );

/* ---------------------------------------------- an upload as an attachment */

$glc_a = $glc_sideload( $glc_p, $glc_image( 2400, 1600, 'png' ), 'glc-wp-a.png' );
$glc_b = $glc_sideload( $glc_p, $glc_image( 1800, 1200 ), 'glc-wp-b.jpg' );
$glc_n = $glc_sideload( $glc_p, $glc_image( 1200, 900 ), 'glc-wp-narrow.jpg' );
$glc_o = $glc_sideload( (int) $glc_other->ID, $glc_image( 2000, 1300 ), 'glc-wp-other.jpg' );
$glc_row  = $glc_post( (int) $glc_a );
$glc_file = (string) $glc_raw( (int) $glc_a, '_wp_attached_file' );
$glc_meta = maybe_unserialize( (string) $glc_raw( (int) $glc_a, '_wp_attachment_metadata' ) );
$glc_check( ! is_wp_error( $glc_a ) && 'attachment' === $glc_row['post_type'] && (int) $glc_row['post_author'] === $glc_p && '0' === $glc_row['post_parent'] && 'image/jpeg' === $glc_row['post_mime_type'] && str_ends_with( $glc_file, 'glc-wp-a.jpg' ) && 1920 === $glc_meta['width'] && ! array_diff( array_keys( GLC_IMAGE_SIZES ), array_keys( $glc_meta['sizes'] ) ), "Stored through WordPress's own upload path, read with SQL: author #{$glc_row['post_author']} (the partner), parent {$glc_row['post_parent']}, {$glc_row['post_mime_type']}, file {$glc_file}, {$glc_meta['width']} wide, all " . count( GLC_IMAGE_SIZES ) . ' of the site\'s crops made' );
$glc_text_att = wp_insert_attachment( [ 'post_title' => 'glc-wp-notes', 'post_mime_type' => 'text/plain', 'post_status' => 'inherit', 'post_author' => $glc_p ], false, 0 );
$glc_made['attachments'][] = (int) $glc_text_att;

/* ----------------------------------- which photo may go into which gallery */

wp_set_current_user( $glc_p ); // the sentences below are read in this person's panel language, English
$glc_v = static fn( int $car, array $photos, int $user ) => GLC_Price_Store::validate_photos( $photos, GLC_Price_Store::LEVEL_FULL, $car, $glc_as( $user ) );
$glc_check( [] === $glc_v( $glc_car, [ 'gallery' => [ $glc_a, $glc_b ], 'main' => $glc_a ], $glc_p ), 'A partner\'s two own wide uploads may go into the gallery of their car' );
$glc_foreign = [];
foreach ( [ 'another partner\'s upload' => $glc_o, 'the site\'s photo on another car' => $glc_site_photo, 'a car' => $glc_car_b, 'an id that is nothing' => 99999999 ] as $glc_what => $glc_id ) {
	$glc_found = $glc_v( $glc_car, [ 'gallery' => [ $glc_a, $glc_id ], 'main' => $glc_a ], $glc_p );
	if ( 1 !== count( $glc_found ) || "Photo #{$glc_id} cannot be used here: it is not on this car and it is not a photo you uploaded." !== $glc_found[0]['message'] ) {
		$glc_foreign[] = $glc_what;
	}
}
$glc_check( ! $glc_foreign, 'Another partner\'s upload, the site\'s photo on another car, a car and an id that is nothing each refuse the gallery with one and the same sentence' . ( $glc_foreign ? ' — NOT: ' . implode( ', ', $glc_foreign ) : '' ) );
$glc_check( [] === $glc_v( $glc_car_b, [ 'gallery' => array_reverse( GLC_Price_Store::read( $glc_car_b, 'photos' )['gallery'] ) ], (int) $glc_other->ID ) && $glc_v( $glc_car, [ 'gallery' => GLC_Price_Store::read( $glc_car_b, 'photos' )['gallery'] ], $glc_p ), 'The site\'s photos that are already on a partner\'s car may be kept and put in another order by that partner, though none is "theirs"; the same photos are refused on another partner\'s car' );
$glc_found = $glc_v( $glc_car, [ 'gallery' => [ $glc_a, $glc_text_att ], 'main' => $glc_a ], $glc_p );
$glc_check( 1 === count( $glc_found ) && str_contains( $glc_found[0]['message'], 'is of the kind text/plain. A gallery takes: image/jpeg, image/png, image/webp.' ), 'The partner\'s own upload that is not a picture: "' . $glc_codes( $glc_found ) . '"' );
$glc_found = $glc_v( $glc_car, [ 'gallery' => [ $glc_a, $glc_n ], 'main' => $glc_a ], $glc_p );
$glc_check( 1 === count( $glc_found ) && 'glc-wp-narrow.jpg is 1200 pixels wide. A photo added to a gallery must be at least 1600 pixels wide, or it looks soft at the top of the car\'s page.' === $glc_found[0]['message'], 'A 1200-pixel upload: "' . $glc_codes( $glc_found ) . '"' );
// A car of the live fleet whose every photo is narrower than the setting asks for today.
$glc_seven = 0;
$glc_old   = [];
$glc_old_w = [ 0 ];
foreach ( array_map( 'intval', $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'car' ORDER BY ID" ) ) as $glc_id ) {
	$glc_ids    = GLC_Price_Store::read( $glc_id, 'photos' )['gallery'];
	$glc_widths = array_map( static fn( int $id ): int => (int) ( wp_get_attachment_metadata( $id )['width'] ?? 0 ), $glc_ids );
	if ( $glc_ids && max( $glc_widths ) < 1600 && ! in_array( $glc_id, [ $glc_car, $glc_car_b ], true ) ) {
		[ $glc_seven, $glc_old, $glc_old_w ] = [ $glc_id, $glc_ids, $glc_widths ];
		break;
	}
}
$glc_check( $glc_seven && max( $glc_old_w ) < 1600 && [] === $glc_v( $glc_seven, [ 'gallery' => array_reverse( $glc_old ), 'main' => $glc_old[0] ], $glc_p ), "Photos already on a car are not measured again: the gallery of car #{$glc_seven}, whose photos are " . implode( ', ', array_unique( $glc_old_w ) ) . ' pixels wide, can still be put in another order' );
$glc_set( [ 'partner_image_min_width' => '', 'gallery_max_photos' => '1' ] );
$glc_found = $glc_v( $glc_car, [ 'gallery' => [ $glc_a, $glc_n ], 'main' => $glc_a ], $glc_p );
$glc_check( 1 === count( $glc_found ) && 'A gallery can hold at most 1 photos. This one has 2.' === $glc_found[0]['message'], 'The two limits are the settings\': with the width limit emptied the narrow photo passes, and with one photo allowed the gallery of two is refused: "' . $glc_codes( $glc_found ) . '"' );
update_option( 'glc_settings', $glc_settings );
$glc_check( [] === GLC_Price_Store::validate_photos( [ 'gallery' => [ $glc_a, $glc_site_photo, $glc_o ], 'main' => $glc_a ], GLC_Price_Store::LEVEL_FULL, $glc_car, $glc_as( (int) $glc_owner->ID ) ), 'The owner may put any picture of the library into any car\'s gallery' );
$glc_found = array_merge(
	$glc_v( $glc_car, [ 'gallery' => [ $glc_a ], 'main' => $glc_b ], $glc_p ),
	$glc_v( $glc_car, [ 'gallery' => [ $glc_a ], 'main' => 0 ], $glc_p ),
	$glc_v( $glc_car, [ 'gallery' => [ $glc_a ], 'main' => $glc_a, 'alts' => [ $glc_a => str_repeat( 'x', 126 ) ] ], $glc_p ),
	$glc_v( $glc_car, [ 'gallery' => [ $glc_a ], 'main' => $glc_a, 'alts' => [ $glc_o => 'theirs' ] ], $glc_p ),
	$glc_v( $glc_car, [ 'gallery' => [ $glc_a, 'two' ] ], $glc_p ),
	$glc_v( $glc_car, [ 'gallery' => [ $glc_a ], 'cover' => $glc_a ], $glc_p )
);
$glc_check( 6 === count( $glc_found ) && str_contains( $glc_codes( $glc_found ), 'is not in the gallery' ) && str_contains( $glc_codes( $glc_found ), 'Choose which photo is the main one.' ) && str_contains( $glc_codes( $glc_found ), '126 characters long and the most is 125' ) && str_contains( $glc_codes( $glc_found ), "Photo #{$glc_o} cannot be used here" ) && str_contains( $glc_codes( $glc_found ), '"two" is not a photo.' ) && str_contains( $glc_codes( $glc_found ), 'The photos could not be read.' ), 'A main photo outside the gallery, no main photo, a description of 126 characters, a description aimed at someone else\'s photo, a word for an id and an unknown part: six refusals, each with its own sentence' );
$glc_check( [] === $glc_v( $glc_car, [ 'gallery' => [ $glc_a ], 'main' => $glc_a, 'alts' => [ $glc_b => 'left over from the form' ] ], $glc_p ), 'A description sent for the partner\'s own photo that is not in the gallery (just taken out on the form) does not stop the save' );
$glc_check( GLC_Price_Store::photo_open( $glc_car, (int) $glc_a, $glc_p ) && ! GLC_Price_Store::photo_open( $glc_car, (int) $glc_o, $glc_p ) && ! GLC_Price_Store::photo_open( $glc_car, $glc_site_photo, $glc_p ) && ! GLC_Price_Store::photo_open( $glc_car, $glc_site_photo, 0 ) && GLC_Price_Store::photo_open( $glc_car_b, $glc_site_photo, (int) $glc_other->ID ), 'An imported photo has no uploader on record (author 0): it is nobody\'s "own", not even for a request with no user' );

/* ------------------------------------------------------ what a save stores */

wp_set_current_user( $glc_p );
$glc_rest_was = $glc_rest_of_car( $glc_car );
$glc_logs     = $glc_log_count();
$glc_rev      = (int) $glc_raw( $glc_car, 'glc_rev' );
$glc_result   = GLC_Price_Store::write( $glc_car, [ 'photos' => [ 'gallery' => [ $glc_a, $glc_b ], 'main' => $glc_b, 'alts' => [ $glc_a => '  Front of the test car  ', $glc_b => '' ] ] ], [ 'source' => 'panel', 'actor' => $glc_p ] );
$glc_title    = get_post( $glc_car )->post_title;
$glc_log      = $glc_last_log();
$glc_check( ! is_wp_error( $glc_result ) && "a:2:{i:0;i:{$glc_a};i:1;i:{$glc_b};}" === $glc_raw( $glc_car, 'glc_gallery' ) && (string) $glc_b === $glc_raw( $glc_car, '_thumbnail_id' ), 'Stored, read with SQL: glc_gallery ' . $glc_raw( $glc_car, 'glc_gallery' ) . ' (whole numbers, in page order), _thumbnail_id ' . $glc_raw( $glc_car, '_thumbnail_id' ) );
$glc_check( 'Front of the test car' === $glc_raw( (int) $glc_a, '_wp_attachment_image_alt' ) && "{$glc_title} — photo 2" === $glc_raw( (int) $glc_b, '_wp_attachment_image_alt' ), 'The typed description is stored trimmed; the one left empty becomes "' . $glc_raw( (int) $glc_b, '_wp_attachment_image_alt' ) . '" (its place in the gallery)' );
$glc_check( (string) $glc_car === $glc_post( (int) $glc_a )['post_parent'] && (string) $glc_car === $glc_post( (int) $glc_b )['post_parent'] && (string) $glc_p === $glc_post( (int) $glc_a )['post_author'], 'Both photos now belong to the car (post_parent ' . $glc_post( (int) $glc_a )['post_parent'] . ') and are still the partner\'s uploads' );
$glc_before = json_decode( (string) $glc_log['glc_before'], true );
$glc_after  = json_decode( (string) $glc_log['glc_after'], true );
$glc_check( $glc_logs + 1 === $glc_log_count() && 'photos' === $glc_log['glc_kind'] && 'panel' === $glc_log['glc_source'] && (string) $glc_p === $glc_log['glc_actor'] && [ 'gallery' => [], 'main' => 0, 'alts' => [] ] === $glc_before && [ $glc_a, $glc_b ] === $glc_after['gallery'] && $glc_b === $glc_after['main'] && 2 === count( $glc_after['alts'] ) && $glc_rev + 1 === (int) $glc_raw( $glc_car, 'glc_rev' ), 'One log entry (photos / panel) with the full before and after: ' . $glc_log['glc_before'] . ' → ' . substr( (string) $glc_log['glc_after'], 0, 90 ) . '…; the car is one version up' );
$glc_check( $glc_rest_was === $glc_rest_of_car( $glc_car ), 'Nothing else of the car moved: title, text, plate, year, prices, fees and partner are byte for byte as before' );
$glc_again = GLC_Price_Store::write( $glc_car, [ 'photos' => [ 'gallery' => [ $glc_a, $glc_b ], 'main' => $glc_b ] ], [ 'source' => 'panel', 'actor' => $glc_p ] );
$glc_check( ! is_wp_error( $glc_again ) && [ $glc_car ] === $glc_again['unchanged'] && $glc_logs + 1 === $glc_log_count() && $glc_rev + 1 === (int) $glc_raw( $glc_car, 'glc_rev' ), 'Saving what is stored writes nothing: no version step, no log entry' );
$glc_stale = GLC_Price_Store::write( $glc_car, [ 'photos' => [ 'gallery' => [ $glc_b, $glc_a ] ] ], [ 'source' => 'panel', 'actor' => $glc_p, 'rev' => $glc_rev ] );
$glc_check( is_wp_error( $glc_stale ) && 409 === $glc_stale->get_error_data()['status'] && "a:2:{i:0;i:{$glc_a};i:1;i:{$glc_b};}" === $glc_raw( $glc_car, 'glc_gallery' ), 'A photos save that names an older version of the car is refused like a price save: ' . ( is_wp_error( $glc_stale ) ? $glc_stale->get_error_message() : 'NOT REFUSED' ) );

$glc_file_a = get_attached_file( (int) $glc_a );
GLC_Price_Store::write( $glc_car, [ 'photos' => [ 'gallery' => [ $glc_b ], 'main' => $glc_b ] ], [ 'source' => 'panel', 'actor' => $glc_p ] );
$glc_removed = $glc_last_log()['id'];
$glc_check( "a:1:{i:0;i:{$glc_b};}" === $glc_raw( $glc_car, 'glc_gallery' ) && 'inherit' === $glc_post( (int) $glc_a )['post_status'] && is_file( $glc_file_a ) && 'Front of the test car' === $glc_raw( (int) $glc_a, '_wp_attachment_image_alt' ), 'A photo taken out of the gallery is still an attachment, its file is on disk and its description is kept' );
$glc_back = GLC_Change_Log::restore( $glc_removed, [ 'actor' => $glc_p ] );
$glc_check( ! is_wp_error( $glc_back ) && "a:2:{i:0;i:{$glc_a};i:1;i:{$glc_b};}" === $glc_raw( $glc_car, 'glc_gallery' ) && 'restore' === $glc_last_log()['glc_source'] && 'photos' === $glc_last_log()['glc_kind'], 'Putting the earlier gallery back from the log: the stored value is the earlier one again, logged as a restore' );

$glc_taken = (int) GLC_Price_Store::read( $glc_car_b, 'photos' )['gallery'][1];
GLC_Price_Store::write( $glc_car, [ 'photos' => [ 'gallery' => [ $glc_a, $glc_b, $glc_taken ] ] ], [ 'source' => 'cli' ] );
$glc_check( (string) $glc_car_b === $glc_post( $glc_taken )['post_parent'] && in_array( $glc_taken, GLC_Price_Store::read( $glc_car, 'photos' )['gallery'], true ), 'A photo that already belongs to another car can be shown on this one (by the owner\'s shell here) but is not taken from the other: post_parent stays ' . $glc_post( $glc_taken )['post_parent'] );
GLC_Price_Store::write( $glc_car, [ 'photos' => [ 'gallery' => [ $glc_a, $glc_b ] ] ], [ 'source' => 'cli' ] );

// "No gallery image ever has an empty alt": the one photo of the live fleet that has none gets one with the first save of its car's photos.
$glc_empty = $wpdb->get_results( "SELECT g.post_id AS car, a.ID AS photo FROM {$wpdb->postmeta} g JOIN {$wpdb->posts} a ON a.post_type = 'attachment' AND g.meta_value LIKE CONCAT('%i:', a.ID, ';%') LEFT JOIN {$wpdb->postmeta} alt ON alt.post_id = a.ID AND alt.meta_key = '_wp_attachment_image_alt' WHERE g.meta_key = 'glc_gallery' AND COALESCE(alt.meta_value, '') = ''", ARRAY_A );
$glc_empty = array_values( array_filter( $glc_empty, static fn( array $row ): bool => in_array( (int) $row['photo'], GLC_Price_Store::read( (int) $row['car'], 'photos' )['gallery'], true ) ) );
if ( $glc_empty ) {
	$glc_e_car = (int) $glc_empty[0]['car'];
	$glc_e_ids = GLC_Price_Store::read( $glc_e_car, 'photos' )['gallery'];
	$glc_e_at  = array_search( (int) $glc_empty[0]['photo'], $glc_e_ids, true ) + 1;
	GLC_Price_Store::write( $glc_e_car, [ 'photos' => [ 'gallery' => array_reverse( $glc_e_ids ) ] ], [ 'source' => 'cli' ] );
	$glc_e_alt = (string) $glc_raw( (int) $glc_empty[0]['photo'], '_wp_attachment_image_alt' );
	$glc_check( '' !== $glc_e_alt && preg_match( '/ — photo \d+$/', $glc_e_alt ), 'The fleet has ' . count( $glc_empty ) . " gallery photo(s) with no description today (car #{$glc_e_car}, photo {$glc_e_at} of " . count( $glc_e_ids ) . "). After a save of that car's photos it reads \"{$glc_e_alt}\"" );
} else {
	$glc_check( true, 'No gallery photo of the fleet is without a description (nothing to show here)' );
}

/* -------------------------------------------------------------- details */

$glc_s        = static fn( array $specs ) => GLC_Price_Store::validate_specs( $specs, GLC_Price_Store::LEVEL_FULL, $glc_car );
$glc_today    = gmdate( 'Y-m-d' );
$glc_bad      = [
	'seats 0'                   => [ [ 'glc_seats' => '0' ], 'is not a whole number from 1 to 9' ],
	'seats 10'                  => [ [ 'glc_seats' => '10' ], 'is not a whole number from 1 to 9' ],
	'seats 4.5'                 => [ [ 'glc_seats' => '4.5' ], 'is not a whole number from 1 to 9' ],
	'no seats'                  => [ [ 'glc_seats' => '' ], 'is not a whole number from 1 to 9' ],
	'a gearbox not listed'      => [ [ 'glc_transmission' => 'cvt' ], 'is not one of: automatic, manual.' ],
	'no gearbox'                => [ [ 'glc_transmission' => '' ], 'is not one of: automatic, manual.' ],
	'a fuel not listed'         => [ [ 'glc_fuel_type' => 'lpg' ], 'is not one of: gasoline, diesel, hybrid, electric.' ],
	'drive system in small letters' => [ [ 'glc_drivetrain' => 'awd' ], 'capital letters, digits and a small x' ],
	'drive system with a space' => [ [ 'glc_drivetrain' => '4 WD' ], 'capital letters, digits and a small x' ],
	'drive system of 13'        => [ [ 'glc_drivetrain' => 'ABCDEFGHIJKLM' ], '13 characters, and the most is 12' ],
	'colour of 25'              => [ [ 'glc_color' => str_repeat( 'a', 25 ) ], '25 characters, and the most is 24' ],
	'colour with a tag'         => [ [ 'glc_color' => '<b>Red</b>' ], 'plain text only' ],
	'licence category of 5'     => [ [ 'glc_license_category' => 'ABCDE' ], '5 characters, and the most is 4' ],
	'licence category with a sign' => [ [ 'glc_license_category' => 'B+E' ], 'other than letters and digits' ],
	'available: maybe'          => [ [ 'glc_available' => 'maybe' ], 'the answer is yes or no' ],
	'a check date still to come' => [ [ 'glc_evidence_checked_on' => gmdate( 'Y-m-d', time() + 3 * DAY_IN_SECONDS ) ], 'is not a day that has already come' ],
	'a date that does not exist' => [ [ 'glc_evidence_checked_on' => '2026-02-30' ], 'is not a day that has already come' ],
	'a date written another way' => [ [ 'glc_evidence_checked_on' => '30.09.2026' ], 'is not a day that has already come' ],
	'a service after the check' => [ [ 'glc_evidence_checked_on' => '2026-09-01', 'glc_last_service_on' => '2026-09-15' ], 'is after the day the car was checked (2026-09-01)' ],
	'a service with no check date' => [ [ 'glc_evidence_checked_on' => '', 'glc_last_service_on' => '2026-09-15' ], 'is after the day the car was checked (—)' ],
	'odometer 0'                => [ [ 'glc_odometer_km' => '0' ], 'is not a whole number of kilometres above zero' ],
	'odometer in words'         => [ [ 'glc_odometer_km' => '120 000' ], 'is not a whole number of kilometres above zero' ],
	'a plate'                   => [ [ 'glc_registration' => 'XX-000-XX' ], '"glc_registration" is not a detail that can be changed here.' ],
	'a year'                    => [ [ 'glc_year' => '1999' ], '"glc_year" is not a detail that can be changed here.' ],
	'a title'                   => [ [ 'post_title' => 'x' ], '"post_title" is not a detail that can be changed here.' ],
	'the partner'               => [ [ 'glc_partner_id' => '1' ], '"glc_partner_id" is not a detail that can be changed here.' ],
	'a note'                    => [ [ 'glc_tyres_note' => 'x' ], '"glc_tyres_note" is not a detail that can be changed here.' ],
];
$glc_wrong = [];
foreach ( $glc_bad as $glc_what => [ $glc_specs, $glc_must ] ) {
	$glc_found = $glc_s( $glc_specs );
	if ( 1 !== count( $glc_found ) || ! str_contains( $glc_found[0]['message'], $glc_must ) ) {
		$glc_wrong[] = $glc_what . ' (' . $glc_codes( $glc_found ) . ')';
	}
}
$glc_check( ! $glc_wrong, count( $glc_bad ) . ' values the store refuses for a car\'s details, each with one sentence that names the field: seats, gearbox, fuel, drive system, colour, licence category, availability, the three dated facts, and five fields that are not a partner\'s' . ( $glc_wrong ? ' — WRONG: ' . implode( '; ', $glc_wrong ) : '' ) );
$glc_good = [ [ 'glc_seats' => '1' ], [ 'glc_seats' => '9' ], [ 'glc_seats' => 7 ], [ 'glc_transmission' => 'manual' ], [ 'glc_fuel_type' => 'electric' ], [ 'glc_drivetrain' => '' ], [ 'glc_drivetrain' => '4x4' ], [ 'glc_drivetrain' => 'AWD' ], [ 'glc_drivetrain' => 'ABCDEFGHIJKL' ], [ 'glc_color' => 'Dark grey' ], [ 'glc_color' => '' ], [ 'glc_license_category' => 'C1' ], [ 'glc_license_category' => '' ], [ 'glc_available' => true ], [ 'glc_available' => false ], [ 'glc_available' => '1' ], [ 'glc_available' => '' ], [ 'glc_evidence_checked_on' => $glc_today, 'glc_last_service_on' => $glc_today, 'glc_odometer_km' => '9999999' ], [ 'glc_evidence_checked_on' => '', 'glc_last_service_on' => '', 'glc_odometer_km' => '' ], [] ];
$glc_check( ! array_filter( $glc_good, static fn( array $specs ): bool => (bool) $glc_s( $specs ) ), count( $glc_good ) . ' values at and inside every limit are accepted, the two ends of each range included, and so is an empty change' );
$glc_set( [ 'spec_seats_max' => '12', 'spec_fuel_types' => 'gasoline, lpg', 'spec_drivetrain_max_chars' => '3', 'spec_color_max_chars' => '5', 'spec_license_max_chars' => '1', 'spec_transmissions' => 'cvt' ] );
$glc_check( ! $glc_s( [ 'glc_seats' => '12', 'glc_fuel_type' => 'lpg', 'glc_transmission' => 'cvt' ] ) && 4 === count( $glc_s( [ 'glc_fuel_type' => 'diesel', 'glc_drivetrain' => '4x4x', 'glc_color' => 'Silver', 'glc_license_category' => 'BE' ] ) ), 'Every limit and every list is the setting, not a number in the code: with the settings changed, 12 seats, "lpg" and "cvt" pass, and diesel, a drive system of 4, a colour of 6 and a category of 2 are refused' );
update_option( 'glc_settings', $glc_settings );

$glc_rest_was = $glc_rest_of_car( $glc_car );
$glc_was      = array_map( static fn( string $key ) => $glc_raw( $glc_car, $key ), array_combine( array_keys( GLC_Price_Store::SPEC_FIELDS ), array_keys( GLC_Price_Store::SPEC_FIELDS ) ) );
$glc_logs     = $glc_log_count();
$glc_result   = GLC_Price_Store::write( $glc_car, [ 'specs' => [ 'glc_seats' => '4', 'glc_transmission' => 'manual', 'glc_drivetrain' => '4x4', 'glc_color' => ' Dark grey ', 'glc_evidence_checked_on' => '2026-09-30', 'glc_last_service_on' => '2026-01-15', 'glc_odometer_km' => '123456' ] ], [ 'source' => 'panel', 'actor' => $glc_p ] );
$glc_now      = array_map( static fn( string $key ) => $glc_raw( $glc_car, $key ), array_combine( array_keys( GLC_Price_Store::SPEC_FIELDS ), array_keys( GLC_Price_Store::SPEC_FIELDS ) ) );
$glc_log      = $glc_last_log();
$glc_check( ! is_wp_error( $glc_result ) && [ 'glc_seats' => '4', 'glc_transmission' => 'manual', 'glc_fuel_type' => $glc_was['glc_fuel_type'], 'glc_drivetrain' => '4x4', 'glc_color' => 'Dark grey', 'glc_license_category' => $glc_was['glc_license_category'], 'glc_available' => $glc_was['glc_available'], 'glc_evidence_checked_on' => '2026-09-30', 'glc_last_service_on' => '2026-01-15', 'glc_odometer_km' => '123456' ] === $glc_now, 'Details stored, read with SQL, one row each: ' . wp_json_encode( $glc_now ) . ' (the three that were not sent are the rows they were)' );
$glc_before = json_decode( (string) $glc_log['glc_before'], true );
$glc_after  = json_decode( (string) $glc_log['glc_after'], true );
$glc_check( $glc_logs + 1 === $glc_log_count() && 'specs' === $glc_log['glc_kind'] && array_keys( GLC_Price_Store::SPEC_FIELDS ) === array_keys( $glc_after ) && 4 === $glc_after['glc_seats'] && true === $glc_after['glc_available'] && (int) $glc_was['glc_seats'] === $glc_before['glc_seats'] && $glc_rest_was === $glc_rest_of_car( $glc_car ), 'One log entry (specs) with all ten details before and after, seats as a number and availability as true/false; nothing else of the car moved' );

$glc_price = GLC_Price_Store::read_pricing( $glc_car );
$glc_color = GLC_Price_Store::write( $glc_car, [ 'specs' => [ 'glc_color' => 'Red' ] ], [ 'source' => 'panel', 'actor' => $glc_p ] );
$glc_off   = GLC_Price_Store::write( $glc_car, [ 'specs' => [ 'glc_available' => false ] ], [ 'source' => 'panel', 'actor' => $glc_p ] );
$glc_stored_off = $glc_raw( $glc_car, 'glc_available' );
$glc_price[0]['rates']['d31p'] = (float) $glc_price[0]['rates']['d31p'] - 3;
$glc_both  = GLC_Price_Store::write( $glc_car, [ 'specs' => [ 'glc_available' => true ], 'pricing' => $glc_price ], [ 'source' => 'panel', 'actor' => $glc_p ] );
$glc_check( 0 === count( $glc_color['pinged'] ) && 7 === count( $glc_off['pinged'] ) && '' === $glc_stored_off && 7 === count( $glc_both['pinged'] ) && '1' === $glc_raw( $glc_car, 'glc_available' ), 'Search engines: a colour tells them nothing (0 addresses); availability going off tells them once (' . count( $glc_off['pinged'] ) . ' addresses, the car in seven languages); availability and a price changed in one save still tell them once (' . count( $glc_both['pinged'] ) . '), not twice. "No" is stored as an empty row, as the car editor always stored it' );
$glc_photo_only = GLC_Price_Store::write( $glc_car, [ 'photos' => [ 'gallery' => [ $glc_b, $glc_a ], 'main' => $glc_a ] ], [ 'source' => 'panel', 'actor' => $glc_p ] );
$glc_check( ! is_wp_error( $glc_photo_only ) && 0 === count( $glc_photo_only['pinged'] ), 'A change of photos alone tells search engines nothing (the design: only a price range or availability that moved)' );

/* -------------------------------- a partner's account is deleted */

$glc_temp = static function ( string $login ) use ( &$glc_made ): int {
	$id = wp_insert_user( [ 'user_login' => $login, 'user_pass' => wp_generate_password( 32, false ), 'user_email' => $login . '@example.test', 'role' => GLC_Partners::ROLE ] );
	$glc_made['users'][] = (int) $id;
	return (int) $id;
};
wp_set_current_user( (int) $glc_owner->ID );
$glc_t1 = $glc_temp( 'glc_test_temp_partner_1' );
$glc_u1 = (int) $glc_sideload( $glc_t1, $glc_image( 1800, 1200 ), 'glc-wp-temp-1.jpg' );
$glc_f1 = get_attached_file( $glc_u1 );
GLC_Price_Store::write( $glc_car, [ 'photos' => [ 'gallery' => [ $glc_b, $glc_a, $glc_u1 ] ] ], [ 'source' => 'cli' ] );
wp_set_current_user( (int) $glc_owner->ID );
$glc_would = array_values( array_intersect( [ 'attachment' ], array_keys( array_filter( get_post_types( [], 'objects' ), static fn( $type ): bool => (bool) $type->delete_with_user ) ) ) );
wp_delete_user( $glc_t1 );
$glc_row = $glc_post( $glc_u1 );
$glc_check( [ 'attachment' ] === $glc_would && $glc_row && '0' === $glc_row['post_author'] && 'inherit' === $glc_row['post_status'] && is_file( $glc_f1 ) && in_array( $glc_u1, GLC_Price_Store::read( $glc_car, 'photos' )['gallery'], true ) && false === get_userdata( $glc_t1 ), 'A partner\'s account deleted with "delete all content" (WordPress marks attachments to go with their uploader): the account is gone, the photo is still an attachment, its file is on disk, it is still in the car\'s gallery, and it now has no uploader (author 0)' );
$glc_t2 = $glc_temp( 'glc_test_temp_partner_2' );
$glc_u2 = (int) $glc_sideload( $glc_t2, $glc_image( 1800, 1200 ), 'glc-wp-temp-2.jpg' );
$glc_f2 = get_attached_file( $glc_u2 );
wp_set_current_user( (int) $glc_owner->ID );
remove_action( 'delete_user', [ 'GLC_Partner_Media', 'keep_uploads' ], 10 );
remove_filter( 'post_types_to_delete_with_user', [ 'GLC_Partner_Media', 'keep_upload_type' ], 10 );
wp_delete_user( $glc_t2 );
add_action( 'delete_user', [ 'GLC_Partner_Media', 'keep_uploads' ], 10, 3 );
add_filter( 'post_types_to_delete_with_user', [ 'GLC_Partner_Media', 'keep_upload_type' ], 10, 2 );
$glc_check( null === $glc_post( $glc_u2 ) && ! is_file( $glc_f2 ), 'Control, with this plugin\'s two hooks taken off for one call: WordPress on its own deletes the uploader\'s photo, row and file. That is the default the hooks stand against' );
$glc_t3 = $glc_temp( 'glc_test_temp_partner_3' );
$glc_u3 = (int) $glc_sideload( $glc_t3, $glc_image( 1800, 1200 ), 'glc-wp-temp-3.jpg' );
wp_set_current_user( (int) $glc_owner->ID );
wp_delete_user( $glc_t3, (int) $glc_owner->ID );
$glc_check( (string) $glc_owner->ID === ( $glc_post( $glc_u3 )['post_author'] ?? '' ), 'When the owner picks "attribute all content to" an account instead, WordPress hands the photos to that account and the hooks leave that alone' );
$glc_made['attachments'][] = $glc_u1;

/* --------------------------------- whose uploads a partner's query may see */

wp_set_current_user( $glc_p );
$glc_q = GLC_Partner_Media::own_uploads_only( [ 'author' => 0, 'author__in' => [ (int) $glc_other->ID ], 'author__not_in' => [ $glc_p ], 'post__in' => [ (int) $glc_o ] ] );
wp_set_current_user( (int) $glc_owner->ID );
$glc_q_owner = GLC_Partner_Media::own_uploads_only( [ 'author' => 0 ] );
$glc_check( $glc_p === $glc_q['author'] && ! isset( $glc_q['author__in'], $glc_q['author__not_in'] ) && [ 'author' => 0 ] === $glc_q_owner, 'A partner\'s library query is narrowed to their own uploads whatever author it names; the owner\'s is left as asked' );

/* ---------------------------------- the panel language, landing, application passwords */

$glc_lang_user = $glc_temp( 'glc_test_temp_lang' );
$glc_first     = GLC_Admin_Strings::lang( $glc_lang_user );
update_user_meta( $glc_lang_user, 'locale', 'ka_GE' );
$glc_by_locale = GLC_Admin_Strings::lang( $glc_lang_user );
update_user_meta( $glc_lang_user, GLC_Admin_Strings::USER_META, 'en' );
$glc_chosen = GLC_Admin_Strings::lang( $glc_lang_user );
update_user_meta( $glc_lang_user, GLC_Admin_Strings::USER_META, 'xx' );
$glc_check( 'en' === $glc_first && 'ka' === $glc_by_locale && 'en' === $glc_chosen && 'ka' === GLC_Admin_Strings::lang( $glc_lang_user ), 'The panel language: English for a new account, Georgian when the account\'s WordPress language is Georgian, the person\'s own choice over both, and a stored value the panel does not have is ignored' );
$glc_landing = apply_filters( 'login_redirect', admin_url(), '', $glc_partner );
$glc_check( GLC_Partner_Panel::url() === $glc_landing && admin_url() === apply_filters( 'login_redirect', admin_url(), '', $glc_owner ), 'After signing in a partner is sent to ' . str_replace( home_url(), '', $glc_landing ) . '; the owner is sent where WordPress sends them' );

add_filter( 'wp_is_application_passwords_available', '__return_true', 99 );
$glc_ask = static function ( WP_User $user ) {
	wp_set_current_user( (int) $user->ID );
	$request = new WP_REST_Request( 'POST', '/wp/v2/users/' . $user->ID . '/application-passwords' );
	$request->set_param( 'name', 'glc test' );
	return rest_do_request( $request );
};
$glc_refused = $glc_ask( $glc_partner );
$glc_allowed = $glc_ask( $glc_owner );
$glc_check( $glc_refused->get_status() >= 400 && ! WP_Application_Passwords::get_user_application_passwords( $glc_p ) && 201 === $glc_allowed->get_status() && false === wp_is_application_passwords_available_for_user( $glc_partner ), 'With application passwords forced on for the site (plain http turns them off for everyone): a partner asking REST for one gets ' . $glc_refused->get_status() . ' ' . ( $glc_refused->get_data()['code'] ?? '' ) . ' and none is stored; control: the owner gets ' . $glc_allowed->get_status() );
WP_Application_Passwords::delete_all_application_passwords( (int) $glc_owner->ID );
remove_filter( 'wp_is_application_passwords_available', '__return_true', 99 );

/* --------------------------------------------------------- putting it back */

wp_set_current_user( 0 );
update_option( 'glc_settings', $glc_settings );
foreach ( array_unique( $glc_made['attachments'] ) as $glc_id ) {
	if ( get_post( $glc_id ) ) {
		wp_delete_attachment( $glc_id, true );
	}
}
wp_delete_attachment( $glc_u3, true );
foreach ( $glc_made['users'] as $glc_id ) {
	if ( get_userdata( $glc_id ) ) {
		wp_delete_user( $glc_id );
	}
}
foreach ( $glc_made['files'] as $glc_path ) {
	if ( is_file( $glc_path ) ) {
		unlink( $glc_path );
	}
}
$glc_files_now = $glc_disk();
$glc_check( $glc_files_was === $glc_files_now && ! array_filter( array_unique( $glc_made['attachments'] ), 'get_post' ), "Every attachment and account this test made is deleted; the uploads folder holds {$glc_files_now} files, as before the test ({$glc_files_was})" );

WP_CLI::log( '' );
WP_CLI::log( $glc_failures ? "{$glc_failures} FAILED" : 'ALL PASS' );
if ( $glc_failures ) {
	WP_CLI::halt( 1 );
}
