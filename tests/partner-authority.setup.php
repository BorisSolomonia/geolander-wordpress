<?php
/**
 * Fixture for tests/partner-authority.mjs. LOCAL COPY ONLY.
 *
 * The .mjs runs this through wp-cli and keeps its output in tests/.local/, which
 * git ignores. Do not run it by hand into a terminal: the default mode prints
 * the test accounts' new passwords.
 *
 *   (no argument)      create or refresh five local test accounts with new random
 *                      passwords, assign one car to each of two partners and three to
 *                      a third (for changes to several cars at once), give the cars
 *                      fee overrides through the store, and print it all as JSON
 *   facts              print only the facts a refused request must not change
 *   inspect            print what the database holds for the three cars, the newest
 *                      change-log entry, the partners' phones and the newest booking
 *                      requests: every value read with SQL, none through the plugin
 *   cleanup <id> …     delete attachments this test uploaded (and nothing else)
 *   cleanup-all        delete every attachment a test account uploaded, with its files, and count
 *                      the files in the uploads folder afterwards
 *   disk               count the files in the uploads folder
 *   image <w> <h> <kind> [bytes]
 *                      a picture made here (never a real photo), as base64: jpeg, png, webp or gif,
 *                      optionally padded to a size in bytes. `rotated` as kind: a JPEG lying on its
 *                      side with the note that says which way is up
 *   photos <id> …      what the database and the disk hold for these attachments: every value
 *                      read with SQL or from the file itself, none through the plugin
 *   xlsx <login> <ids> [Sheet!Cell=value …]
 *                      the price file that account would download for those cars
 *                      (comma-separated ids), with the named cells changed, as base64.
 *                      "=" alone empties a cell. For uploads the HTTP test sends.
 *   xlsx-read <base64> what the plugin's reader makes of a file the test downloaded
 *   xlsx-hostile       a workbook whose sheet declares an entity, as base64
 *
 * "Facts" are read straight from the database. The HTTP test compares them
 * before and after: a 403 says a request was refused, the facts say nothing moved.
 */

if ( ! defined( 'ABSPATH' ) || ! defined( 'WP_CLI' ) ) {
	exit( "Run via wp eval-file\n" );
}
if ( 'production' === wp_get_environment_type() ) {
	WP_CLI::error( 'Test accounts are for the local copy. This does not run on a production site.' );
}

$glc_mode  = (string) ( ( $args ?? [] )[0] ?? '' );
$glc_logins = [ 'partner' => 'glc_test_partner', 'partner_ka' => 'glc_test_partner_ka', 'partner_b' => 'glc_test_partner_b', 'partner_c' => 'glc_test_partner_c', 'owner' => 'glc_test_owner' ];

if ( 'cleanup' === $glc_mode ) {
	$glc_gone = [];
	foreach ( array_slice( $args, 1 ) as $glc_id ) {
		$glc_post = get_post( (int) $glc_id );
		$glc_user = $glc_post ? get_userdata( (int) $glc_post->post_author ) : null;
		// Only an attachment one of the test accounts uploaded.
		if ( $glc_post && 'attachment' === $glc_post->post_type && $glc_user && in_array( $glc_user->user_login, $glc_logins, true ) && wp_delete_attachment( $glc_post->ID, true ) ) {
			$glc_gone[] = (int) $glc_post->ID;
		}
	}
	echo wp_json_encode( [ 'deleted' => $glc_gone ] );
	return;
}

$glc_disk = static function (): int {
	$count = 0;
	foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( wp_get_upload_dir()['basedir'], FilesystemIterator::SKIP_DOTS ) ) as $glc_file ) {
		$count += $glc_file->isFile() ? 1 : 0;
	}
	return $count;
};
if ( 'disk' === $glc_mode ) {
	echo wp_json_encode( [ 'files' => $glc_disk() ] );
	return;
}
if ( 'cleanup-all' === $glc_mode ) {
	global $wpdb;
	$glc_gone = [];
	foreach ( $glc_logins as $glc_login ) {
		$glc_user = get_user_by( 'login', $glc_login );
		foreach ( $glc_user ? $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_author = %d", $glc_user->ID ) ) : [] as $glc_id ) {
			if ( wp_delete_attachment( (int) $glc_id, true ) ) {
				$glc_gone[] = (int) $glc_id;
			}
		}
	}
	echo wp_json_encode( [ 'deleted' => $glc_gone, 'files' => $glc_disk() ] );
	return;
}
if ( 'xmlrpc' === $glc_mode ) {
	// WordPress's own XML-RPC server, asked in this process: the web server lets no request reach it. It asks "am I
	// switched on?" before it looks at a password, so no real password is needed here (and none goes on a command line):
	// switched off it answers 405, switched on it would answer 403 for the wrong password.
	require_once ABSPATH . WPINC . '/class-IXR.php';
	require_once ABSPATH . WPINC . '/class-wp-xmlrpc-server.php';
	$glc_server = new wp_xmlrpc_server();
	$glc_user   = $glc_server->login( (string) ( $args[1] ?? '' ), 'not the password' );
	echo wp_json_encode( [ 'enabled' => (bool) apply_filters( 'xmlrpc_enabled', true ), 'signed_in' => $glc_user instanceof WP_User, 'code' => $glc_server->error instanceof IXR_Error ? (int) $glc_server->error->code : 0, 'error' => $glc_server->error instanceof IXR_Error ? (string) $glc_server->error->message : '' ] );
	return;
}
if ( 'evidence-clock' === $glc_mode ) {
	// What day the dated facts of a car take "today" to be at a given moment (UTC), and which of the dates named after it they keep.
	$glc_now  = (int) strtotime( (string) ( $args[1] ?? '' ) . ' UTC' );
	$glc_kept = [];
	foreach ( array_slice( $args, 2 ) as $glc_day ) {
		$glc_kept[ $glc_day ] = '' !== GLC_Vehicle_Evidence::date( (string) $glc_day, $glc_now );
	}
	echo wp_json_encode( [ 'utc_date' => gmdate( 'Y-m-d', $glc_now ), 'today' => GLC_Vehicle_Evidence::today( $glc_now ), 'kept' => $glc_kept, 'today_now' => GLC_Vehicle_Evidence::today(), 'display_timezone' => (string) GLC_Settings::get( 'display_timezone' ), 'site_timezone' => wp_timezone_string() ] );
	return;
}
if ( 'meta' === $glc_mode ) {
	// One meta key of one post, from a shell. `meta <post> <key>` reads it (the row's id and its value as the database
	// holds them, and how many meta rows the post has); `meta <post> <key> <value>` writes it first, `meta <post> <key> :delete`
	// removes it first (a word wp-cli does not take for an option of its own): what `wp post meta update` does, which must keep working.
	global $wpdb;
	[ $glc_post, $glc_key ] = [ (int) ( $args[1] ?? 0 ), (string) ( $args[2] ?? '' ) ];
	$glc_wrote = null;
	if ( isset( $args[3] ) ) {
		$glc_wrote = ':delete' === $args[3] ? delete_post_meta( $glc_post, $glc_key ) : (bool) update_post_meta( $glc_post, $glc_key, wp_slash( (string) $args[3] ) );
	}
	$glc_row = $wpdb->get_row( $wpdb->prepare( "SELECT meta_id, meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s ORDER BY meta_id LIMIT 1", $glc_post, $glc_key ), ARRAY_A );
	echo wp_json_encode( [ 'wrote' => $glc_wrote, 'meta_id' => $glc_row ? (int) $glc_row['meta_id'] : 0, 'value' => $glc_row ? $glc_row['meta_value'] : null, 'rows' => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE post_id = %d", $glc_post ) ), 'exists' => (bool) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE ID = %d", $glc_post ) ) ] );
	return;
}
if ( 'title' === $glc_mode ) {
	// A post's title exactly as given, tags and all (`title <post>` reads it, `title <post> <text>` sets it first). Only
	// the title column is written, straight into the table, so the post's modified time and everything else stay.
	global $wpdb;
	$glc_post = (int) ( $args[1] ?? 0 );
	if ( isset( $args[2] ) ) {
		$wpdb->update( $wpdb->posts, [ 'post_title' => (string) $args[2] ], [ 'ID' => $glc_post ] );
		clean_post_cache( $glc_post );
	}
	echo wp_json_encode( [ 'title' => (string) $wpdb->get_var( $wpdb->prepare( "SELECT post_title FROM {$wpdb->posts} WHERE ID = %d", $glc_post ) ) ] );
	return;
}
if ( 'image' === $glc_mode ) {
	[ $glc_w, $glc_h, $glc_kind ] = [ (int) ( $args[1] ?? 0 ), (int) ( $args[2] ?? 0 ), (string) ( $args[3] ?? 'jpeg' ) ];
	$glc_im = imagecreatetruecolor( $glc_w, $glc_h );
	// Bands of colour and a few lines, so the picture is not one flat tone and a wrong crop or turn would show.
	for ( $glc_i = 0; $glc_i < 8; $glc_i++ ) {
		imagefilledrectangle( $glc_im, (int) ( $glc_i * $glc_w / 8 ), 0, (int) ( ( $glc_i + 1 ) * $glc_w / 8 ), $glc_h, imagecolorallocate( $glc_im, 30 * $glc_i, 255 - 30 * $glc_i, 90 + 20 * $glc_i ) );
	}
	imagefilledrectangle( $glc_im, 0, 0, $glc_w, (int) ( $glc_h / 10 ), imagecolorallocate( $glc_im, 250, 250, 250 ) );
	imagestring( $glc_im, 5, 10, 10, "glc test {$glc_w}x{$glc_h} {$glc_kind}", imagecolorallocate( $glc_im, 0, 0, 0 ) );
	ob_start();
	match ( $glc_kind ) {
		'png'  => imagepng( $glc_im ),
		'webp' => imagewebp( $glc_im ),
		'gif'  => imagegif( $glc_im ),
		default => imagejpeg( $glc_im, null, 90 ),
	};
	$glc_bytes = (string) ob_get_clean();
	if ( 'rotated' === $glc_kind ) {
		// EXIF orientation 6 ("turn 90° clockwise to see it upright"), put in as an APP1 block right after the JPEG's first marker.
		$glc_tiff  = "II*\x00\x08\x00\x00\x00" . "\x01\x00" . "\x12\x01\x03\x00\x01\x00\x00\x00\x06\x00\x00\x00" . "\x00\x00\x00\x00";
		$glc_exif  = "Exif\x00\x00" . $glc_tiff;
		$glc_bytes = substr( $glc_bytes, 0, 2 ) . "\xFF\xE1" . pack( 'n', strlen( $glc_exif ) + 2 ) . $glc_exif . substr( $glc_bytes, 2 );
	}
	if ( isset( $args[4] ) && (int) $args[4] > strlen( $glc_bytes ) ) {
		$glc_bytes .= str_repeat( "\0", (int) $args[4] - strlen( $glc_bytes ) ); // what follows a picture's last marker is not read as picture
	}
	echo wp_json_encode( [ 'file' => base64_encode( $glc_bytes ), 'bytes' => strlen( $glc_bytes ) ] );
	return;
}
if ( 'photos' === $glc_mode ) {
	global $wpdb;
	$glc_out = [];
	foreach ( array_slice( $args, 1 ) as $glc_id ) {
		$glc_id   = (int) $glc_id;
		$glc_row  = $wpdb->get_row( $wpdb->prepare( "SELECT ID, post_type, post_author, post_parent, post_mime_type, post_status, post_title, post_modified_gmt FROM {$wpdb->posts} WHERE ID = %d", $glc_id ), ARRAY_A );
		$glc_meta = static fn( string $key ) => $wpdb->get_var( $wpdb->prepare( "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s", $glc_id, $key ) );
		$glc_file = $glc_meta( '_wp_attached_file' );
		$glc_path = $glc_file ? wp_get_upload_dir()['basedir'] . '/' . $glc_file : '';
		$glc_size = $glc_path && is_file( $glc_path ) ? getimagesize( $glc_path ) : false;
		$glc_exif = $glc_path && is_file( $glc_path ) && function_exists( 'exif_read_data' ) ? @exif_read_data( $glc_path ) : false;
		$glc_out[ $glc_id ] = $glc_row ? $glc_row + [
			'alt'        => $glc_meta( '_wp_attachment_image_alt' ),
			'file'       => $glc_file,
			'on_disk'    => $glc_path && is_file( $glc_path ),
			'first_bytes' => $glc_path && is_file( $glc_path ) ? strtoupper( bin2hex( (string) file_get_contents( $glc_path, false, null, 0, 3 ) ) ) : '',
			'bytes'      => $glc_path && is_file( $glc_path ) ? filesize( $glc_path ) : 0,
			'width'      => $glc_size ? $glc_size[0] : 0,
			'height'     => $glc_size ? $glc_size[1] : 0,
			'disk_mime'  => $glc_size ? $glc_size['mime'] : '',
			'orientation' => is_array( $glc_exif ) ? (int) ( $glc_exif['Orientation'] ?? 0 ) : 0,
			// Does the file still carry a block of camera data (EXIF), whatever is in it?
			'camera_data' => $glc_path && is_file( $glc_path ) && str_contains( (string) file_get_contents( $glc_path, false, null, 0, 65536 ), "Exif\0\0" ),
			// The colour at the middle of the left edge: the first band of the test picture, wherever a turn has put it.
			'left_middle' => $glc_size && IMAGETYPE_JPEG === $glc_size[2] ? sprintf( '%06X', imagecolorat( imagecreatefromjpeg( $glc_path ), 4, (int) ( $glc_size[1] / 2 ) ) ) : '',
			'sizes'      => array_keys( (array) ( maybe_unserialize( (string) $glc_meta( '_wp_attachment_metadata' ) )['sizes'] ?? [] ) ),
		] : null;
	}
	echo wp_json_encode( $glc_out );
	return;
}

if ( 'xlsx' === $glc_mode ) {
	$glc_user   = get_user_by( 'login', (string) ( $args[1] ?? '' ) );
	$glc_sheets = GLC_Partner_Excel::sheets( array_map( 'intval', explode( ',', (string) ( $args[2] ?? '' ) ) ), (int) $glc_user->ID );
	foreach ( array_slice( $args, 3 ) as $glc_edit ) {
		if ( ! preg_match( '/^(\w+)!([A-Z]+)(\d+)=(.*)$/s', (string) $glc_edit, $glc_m ) ) {
			WP_CLI::error( "Cannot read the edit {$glc_edit}" );
		}
		$glc_row = (int) $glc_m[3] - 1;
		$glc_col = GLC_Xlsx::column_index( $glc_m[2] );
		for ( $glc_i = count( $glc_sheets[ $glc_m[1] ][ $glc_row ] ); $glc_i <= $glc_col; $glc_i++ ) {
			$glc_sheets[ $glc_m[1] ][ $glc_row ][ $glc_i ] = null;
		}
		$glc_sheets[ $glc_m[1] ][ $glc_row ][ $glc_col ] = '' === $glc_m[4] ? null : ( is_numeric( $glc_m[4] ) ? $glc_m[4] + 0 : $glc_m[4] );
	}
	$glc_tmp = tempnam( sys_get_temp_dir(), 'glct' );
	GLC_Xlsx::write( $glc_sheets, $glc_tmp );
	echo wp_json_encode( [ 'file' => base64_encode( (string) file_get_contents( $glc_tmp ) ), 'rows' => array_map( static fn( array $row ): array => array_slice( $row, 0, 4 ), $glc_sheets['Prices'] ) ] );
	unlink( $glc_tmp );
	return;
}
if ( 'xlsx-read' === $glc_mode ) {
	$glc_tmp = tempnam( sys_get_temp_dir(), 'glct' );
	file_put_contents( $glc_tmp, base64_decode( (string) ( $args[1] ?? '' ), true ) );
	try {
		echo wp_json_encode( GLC_Xlsx::read( $glc_tmp, [ 'part_bytes' => 2048 * KB_IN_BYTES, 'rows' => 5000, 'cells' => 140000 ] ) );
	} catch ( GLC_Xlsx_Refused $glc_refused ) {
		echo wp_json_encode( [ 'refused' => $glc_refused->reason ] );
	}
	unlink( $glc_tmp );
	return;
}
if ( 'xlsx-hostile' === $glc_mode ) {
	$glc_tmp = tempnam( sys_get_temp_dir(), 'glct' );
	$glc_zip = new ZipArchive();
	$glc_zip->open( $glc_tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE );
	$glc_zip->addFromString( 'xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Prices" sheetId="1" r:id="rId1"/></sheets></workbook>' );
	$glc_zip->addFromString( 'xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/></Relationships>' );
	$glc_zip->addFromString( 'xl/worksheets/sheet1.xml', '<?xml version="1.0" encoding="UTF-8"?><!DOCTYPE worksheet [<!ENTITY x SYSTEM "file:///var/www/html/wp-config.php">]><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData><row r="1"><c r="A1" t="inlineStr"><is><t>&x;</t></is></c></row></sheetData></worksheet>' );
	$glc_zip->close();
	echo wp_json_encode( [ 'file' => base64_encode( (string) file_get_contents( $glc_tmp ) ) ] );
	unlink( $glc_tmp );
	return;
}

// Six published cars with no number of their own (three for the one-car rows, three for the partner who changes
// several at once), and one that carries a number (how partner cars were routed before accounts).
$glc_plain  = [];
$glc_legacy = 0;
foreach ( array_map( 'intval', get_posts( [ 'post_type' => 'car', 'post_status' => 'publish', 'posts_per_page' => -1, 'orderby' => 'ID', 'order' => 'ASC', 'fields' => 'ids' ] ) ) as $glc_id ) {
	if ( '' !== trim( (string) get_post_meta( $glc_id, 'glc_whatsapp_number', true ) ) ) {
		$glc_legacy = $glc_legacy ?: $glc_id;
	} elseif ( count( $glc_plain ) < 6 ) {
		$glc_plain[] = $glc_id;
	}
}
if ( count( $glc_plain ) < 6 || ! $glc_legacy ) {
	WP_CLI::error( 'Needs six published cars without a number of their own and one with.' );
}
[ $glc_mine, $glc_other, $glc_theirs ] = $glc_plain;
$glc_fleet_c = array_slice( $glc_plain, 3 );

/** One value exactly as the database holds it: null when there is no such row, '' when the row is empty (get_var() cannot tell those apart). */
$glc_exact = static function ( int $post_id, string $key ) {
	global $wpdb;
	$rows = $wpdb->get_results( $wpdb->prepare( "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s ORDER BY meta_id LIMIT 1", $post_id, $key ), ARRAY_N );
	return $rows ? $rows[0][0] : null;
};
/** One value as get_var() gives it: null for no row and for an empty one. */
$glc_raw = static function ( string $table, string $id_column, int $id, string $key ) {
	global $wpdb;
	return $wpdb->get_var( $wpdb->prepare( "SELECT meta_value FROM {$table} WHERE {$id_column} = %d AND meta_key = %s ORDER BY 1 LIMIT 1", $id, $key ) );
};

if ( 'inspect' === $glc_mode ) {
	global $wpdb;
	$glc_out = [ 'cars' => [], 'accounts' => [], 'bookings' => [] ];
	foreach ( [ $glc_mine, $glc_other, $glc_theirs, $glc_legacy ] as $glc_id ) {
		$glc_pricing = $glc_raw( $wpdb->postmeta, 'post_id', $glc_id, 'glc_pricing' );
		$glc_out['cars'][ $glc_id ] = [
			'rev'          => $glc_raw( $wpdb->postmeta, 'post_id', $glc_id, 'glc_rev' ),
			'partner'      => $glc_raw( $wpdb->postmeta, 'post_id', $glc_id, 'glc_partner_id' ),
			'pricing_md5'  => md5( (string) $glc_pricing ),
			'pricing_head' => substr( (string) $glc_pricing, 0, 120 ),
			// The stored table decoded by PHP itself, not by the plugin: for the test to build its own requests from.
			'pricing'      => unserialize( (string) $glc_pricing, [ 'allowed_classes' => false ] ),
			'delivery'     => $glc_raw( $wpdb->postmeta, 'post_id', $glc_id, 'glc_delivery_fees' ),
			'out_of_hours' => $glc_raw( $wpdb->postmeta, 'post_id', $glc_id, 'glc_out_of_hours_fee' ),
			'whatsapp'     => $glc_raw( $wpdb->postmeta, 'post_id', $glc_id, 'glc_whatsapp_number' ),
			// Photos and details, each row as the database holds it (null = no such row).
			'gallery'      => $glc_exact( $glc_id, 'glc_gallery' ),
			'gallery_ids'  => array_values( array_filter( array_map( 'intval', (array) maybe_unserialize( (string) $glc_exact( $glc_id, 'glc_gallery' ) ) ) ) ),
			'thumbnail'    => $glc_exact( $glc_id, '_thumbnail_id' ),
			'specs'        => array_combine( array_keys( GLC_Price_Store::SPEC_FIELDS ), array_map( static fn( string $key ) => $glc_exact( $glc_id, $key ), array_keys( GLC_Price_Store::SPEC_FIELDS ) ) ),
			'identity'     => [
				'title'   => (string) $wpdb->get_var( $wpdb->prepare( "SELECT post_title FROM {$wpdb->posts} WHERE ID = %d", $glc_id ) ),
				'content' => md5( (string) $wpdb->get_var( $wpdb->prepare( "SELECT post_content FROM {$wpdb->posts} WHERE ID = %d", $glc_id ) ) ),
				'plate'   => $glc_raw( $wpdb->postmeta, 'post_id', $glc_id, 'glc_registration' ),
				'year'    => $glc_raw( $wpdb->postmeta, 'post_id', $glc_id, 'glc_year' ),
				'price_from' => $glc_raw( $wpdb->postmeta, 'post_id', $glc_id, 'glc_price_from' ),
				'fuel_note'  => $glc_raw( $wpdb->postmeta, 'post_id', $glc_id, 'glc_fuel_economy_note' ),
			],
		];
	}
	$glc_last = $wpdb->get_row( $wpdb->prepare( "SELECT ID, post_author, post_title, post_status, post_date_gmt FROM {$wpdb->posts} WHERE post_type = %s ORDER BY ID DESC LIMIT 1", GLC_Change_Log::POST_TYPE ), ARRAY_A );
	if ( $glc_last ) {
		foreach ( [ 'glc_car_id', 'glc_actor', 'glc_kind', 'glc_source', 'glc_note', 'glc_before', 'glc_after' ] as $glc_key ) {
			$glc_last[ $glc_key ] = $glc_raw( $wpdb->postmeta, 'post_id', (int) $glc_last['ID'], $glc_key );
		}
	}
	// How many attachments each test account has uploaded: a refused upload must add none.
	foreach ( $glc_logins as $glc_name => $glc_login ) {
		$glc_out['uploads'][ $glc_name ] = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} p JOIN {$wpdb->users} u ON u.ID = p.post_author WHERE p.post_type = 'attachment' AND u.user_login = %s", $glc_login ) );
	}
	$glc_out['last_change']  = $glc_last;
	$glc_out['change_count'] = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s", GLC_Change_Log::POST_TYPE ) );
	foreach ( $glc_logins as $glc_name => $glc_login ) {
		$glc_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM {$wpdb->users} WHERE user_login = %s", $glc_login ) );
		$glc_out['accounts'][ $glc_name ] = [
			'id'         => $glc_id,
			'phone'      => $glc_raw( $wpdb->usermeta, 'user_id', $glc_id, 'glc_partner_phone' ),
			'first_name' => $glc_raw( $wpdb->usermeta, 'user_id', $glc_id, 'first_name' ),
			'last_name'  => $glc_raw( $wpdb->usermeta, 'user_id', $glc_id, 'last_name' ),
			'lang'       => $glc_raw( $wpdb->usermeta, 'user_id', $glc_id, 'glc_panel_lang' ),
		];
	}
	foreach ( $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'booking_request' ORDER BY ID DESC LIMIT 8" ) as $glc_id ) {
		$glc_row = [ 'id' => (int) $glc_id ];
		foreach ( [ 'glc_car_id', 'glc_partner_id', 'glc_routed_number', 'glc_routing_source', 'glc_partner_mail_status', 'glc_reference', 'glc_name', 'glc_email' ] as $glc_key ) {
			$glc_row[ $glc_key ] = $glc_raw( $wpdb->postmeta, 'post_id', (int) $glc_id, $glc_key );
		}
		$glc_out['bookings'][] = $glc_row;
	}
	// Every car of the fleet as the database holds it: all its meta rows in one fingerprint, and its price table and revision
	// on their own. A change to some cars must leave the fingerprint of every other car exactly as it was.
	foreach ( $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'car' ORDER BY ID" ) as $glc_id ) {
		$glc_out['fleet'][ (int) $glc_id ] = [
			'meta'         => md5( wp_json_encode( $wpdb->get_results( $wpdb->prepare( "SELECT meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id = %d ORDER BY meta_key, meta_id", $glc_id ), ARRAY_N ) ) ),
			'rev'          => (string) $glc_raw( $wpdb->postmeta, 'post_id', (int) $glc_id, 'glc_rev' ),
			'pricing'      => unserialize( (string) $glc_raw( $wpdb->postmeta, 'post_id', (int) $glc_id, 'glc_pricing' ), [ 'allowed_classes' => false ] ),
			'pricing_raw'  => (string) $glc_raw( $wpdb->postmeta, 'post_id', (int) $glc_id, 'glc_pricing' ),
			'delivery'     => $glc_raw( $wpdb->postmeta, 'post_id', (int) $glc_id, 'glc_delivery_fees' ),
			'out_of_hours' => $glc_raw( $wpdb->postmeta, 'post_id', (int) $glc_id, 'glc_out_of_hours_fee' ),
			'modified'     => (string) $wpdb->get_var( $wpdb->prepare( "SELECT post_modified_gmt FROM {$wpdb->posts} WHERE ID = %d", $glc_id ) ),
		];
	}
	// The newest change-log entries with their batch, so "one batch, one entry per car" can be counted.
	foreach ( $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s ORDER BY ID DESC LIMIT 12", GLC_Change_Log::POST_TYPE ) ) as $glc_id ) {
		$glc_row = [ 'id' => (int) $glc_id ];
		foreach ( [ 'glc_car_id', 'glc_actor', 'glc_kind', 'glc_source', 'glc_batch' ] as $glc_key ) {
			$glc_row[ $glc_key ] = $glc_raw( $wpdb->postmeta, 'post_id', (int) $glc_id, $glc_key );
		}
		$glc_out['changes'][] = $glc_row;
	}
	$glc_out['plans']         = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_glc\\_plan\\_%'" );
	// Everything the panel keeps for a moment (waiting plans, and the answers that wait for the next screen).
	$glc_out['kept']          = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_glc\\_%'" );
	$glc_out['booking_count'] =(int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'booking_request' AND post_status <> 'auto-draft'" );
	$glc_out['site_timezone'] = [ 'timezone_string' => (string) $wpdb->get_var( "SELECT option_value FROM {$wpdb->options} WHERE option_name = 'timezone_string'" ), 'gmt_offset' => (string) $wpdb->get_var( "SELECT option_value FROM {$wpdb->options} WHERE option_name = 'gmt_offset'" ) ];
	$glc_out['utc_now']       = gmdate( 'Y-m-d H:i' );
	$glc_out['display_timezone'] = (string) GLC_Settings::get( 'display_timezone' );
	echo wp_json_encode( $glc_out );
	return;
}

/** Everything a refused request could have damaged, as the database holds it now. */
$glc_facts = static function () use ( $glc_mine, $glc_other, $glc_theirs, $glc_legacy, $glc_logins ): array {
	global $wpdb;
	$facts = [];
	$hash  = static fn( string $sql ): string => md5( wp_json_encode( $wpdb->get_results( $sql, ARRAY_N ) ) );
	foreach ( [ $glc_mine, $glc_other, $glc_theirs, $glc_legacy ] as $car ) {
		$post                 = get_post( $car );
		$facts[ "car_{$car}" ] = [
			'title'    => $post->post_title,
			'slug'     => $post->post_name,
			'status'   => $post->post_status,
			'modified' => $post->post_modified_gmt,
			'content'  => md5( $post->post_content . '|' . $post->post_excerpt ),
			'meta'     => $hash( $wpdb->prepare( "SELECT meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id = %d ORDER BY meta_key, meta_id", $car ) ),
			'rev'      => (string) $wpdb->get_var( $wpdb->prepare( "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s", $car, GLC_Price_Store::META_REV ) ),
			'partner'  => (string) $wpdb->get_var( $wpdb->prepare( "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s", $car, GLC_Partners::META_PARTNER ) ),
		];
	}
	$facts['settings']      = md5( (string) $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", 'glc_settings' ) ) );
	$facts['roles']         = md5( (string) $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $wpdb->prefix . 'user_roles' ) ) );
	$facts['users']         = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->users}" );
	$facts['capabilities']  = $hash( $wpdb->prepare( "SELECT user_id, meta_value FROM {$wpdb->usermeta} WHERE meta_key = %s ORDER BY user_id", $wpdb->prefix . 'capabilities' ) );
	// Every post that is not an attachment or a revision: none may appear, vanish or change.
	$facts['posts']         = $hash( "SELECT ID, post_type, post_status, post_modified_gmt, post_title, post_name, post_parent FROM {$wpdb->posts} WHERE post_type NOT IN ('attachment', 'revision') ORDER BY ID" );
	$facts['post_count']    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type NOT IN ('attachment', 'revision')" );
	$facts['change_log']    = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s", GLC_Change_Log::POST_TYPE ) );
	// Every car of the fleet, all its meta rows: a refused change to several cars must move none of them.
	$facts['fleet']         = $hash( "SELECT m.post_id, m.meta_key, m.meta_value FROM {$wpdb->postmeta} m JOIN {$wpdb->posts} p ON p.ID = m.post_id AND p.post_type = 'car' ORDER BY m.post_id, m.meta_key, m.meta_id" );
	$facts['bookings']      = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'booking_request'" );
	// Who every car belongs to, and every person's name, phone and login record.
	$facts['assignments']   = $hash( $wpdb->prepare( "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s ORDER BY post_id", GLC_Partners::META_PARTNER ) );
	$facts['profiles']      = $hash( "SELECT user_id, meta_key, meta_value FROM {$wpdb->usermeta} WHERE meta_key IN ('glc_partner_phone', 'first_name', 'last_name', 'nickname', 'glc_panel_lang') ORDER BY user_id, meta_key" );
	$facts['accounts']      = $hash( "SELECT ID, user_login, user_email, display_name FROM {$wpdb->users} ORDER BY ID" );
	// Attachments nobody from the test uploaded: untouched, down to their alt text.
	$test_users             = array_values( array_filter( array_map( static fn( string $login ): int => (int) ( get_user_by( 'login', $login )->ID ?? 0 ), $glc_logins ) ) ) ?: [ 0 ];
	$facts['others_media']  = $hash( $wpdb->prepare(
		"SELECT p.ID, p.post_modified_gmt, p.post_parent, p.post_title, m.meta_value FROM {$wpdb->posts} p LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_wp_attachment_image_alt' WHERE p.post_type = 'attachment' AND p.post_author NOT IN (" . implode( ',', array_fill( 0, count( $test_users ), '%d' ) ) . ') ORDER BY p.ID',
		...$test_users
	) );
	// The application-password switch, asked with site-wide support forced on (plain http reports it off for everyone).
	add_filter( 'wp_is_application_passwords_available', '__return_true', 99 );
	foreach ( $glc_logins as $name => $login ) {
		$user = get_user_by( 'login', $login );
		$facts[ "app_passwords_{$name}" ] = $user ? wp_is_application_passwords_available_for_user( $user ) : null;
	}
	remove_filter( 'wp_is_application_passwords_available', '__return_true', 99 );
	return $facts;
};

if ( 'facts' === $glc_mode ) {
	echo wp_json_encode( $glc_facts() );
	return;
}

$glc_accounts = [];
// Phones in a range no real subscriber has; the Georgian partner without cars has none, to show what "no phone" does.
foreach ( [
	'partner'    => [ GLC_Partners::ROLE, 'Test Partner', 'en', '+995555000101' ],
	'partner_ka' => [ GLC_Partners::ROLE, 'Test Partner Ka', 'ka', '' ],
	'partner_b'  => [ GLC_Partners::ROLE, 'Test Partner B', 'ka', '+995555000202' ],
	'partner_c'  => [ GLC_Partners::ROLE, 'Test Partner C', 'en', '+995555000606' ],
	'owner'      => [ 'administrator', 'Test Owner', 'en', '' ],
] as $glc_name => [ $glc_role, $glc_display, $glc_lang, $glc_phone ] ) {
	$glc_login    = $glc_logins[ $glc_name ];
	$glc_password = wp_generate_password( 32, false );
	$glc_user     = get_user_by( 'login', $glc_login );
	$glc_id       = $glc_user ? (int) $glc_user->ID : (int) wp_insert_user( [ 'user_login' => $glc_login, 'user_pass' => $glc_password, 'user_email' => $glc_login . '@example.test', 'display_name' => $glc_display, 'role' => $glc_role ] );
	if ( $glc_user ) {
		wp_set_password( $glc_password, $glc_id );
		$glc_user->set_role( $glc_role );
	}
	wp_update_user( [ 'ID' => $glc_id, 'display_name' => $glc_display, 'first_name' => '', 'last_name' => '' ] );
	update_user_meta( $glc_id, GLC_Admin_Strings::USER_META, $glc_lang );
	if ( '' !== $glc_phone ) {
		update_user_meta( $glc_id, GLC_Partners::META_PHONE, $glc_phone );
	} else {
		delete_user_meta( $glc_id, GLC_Partners::META_PHONE );
	}
	$glc_accounts[ $glc_name ] = [ 'login' => $glc_login, 'password' => $glc_password, 'id' => $glc_id, 'slug' => get_userdata( $glc_id )->user_nicename, 'name' => $glc_display, 'phone' => $glc_phone ];
}

update_post_meta( $glc_mine, GLC_Partners::META_PARTNER, $glc_accounts['partner']['id'] );
update_post_meta( $glc_theirs, GLC_Partners::META_PARTNER, $glc_accounts['partner_b']['id'] );
foreach ( $glc_fleet_c as $glc_id ) {
	update_post_meta( $glc_id, GLC_Partners::META_PARTNER, $glc_accounts['partner_c']['id'] );
}
delete_post_meta( $glc_other, GLC_Partners::META_PARTNER );
delete_post_meta( $glc_legacy, GLC_Partners::META_PARTNER );
// A burst of test bookings must not run into the site's own ceiling on checkouts per hour.
delete_option( 'glc_checkout_rate_limit' );
// So the car really carries the meta that REST must not show. A second run changes nothing and logs nothing.
$glc_write = GLC_Price_Store::write( $glc_mine, [ 'fees' => [ 'delivery' => [ 'kutaisi_airport' => '95' ] ] ], [ 'source' => 'cli', 'note' => 'authority test fixture' ] );
if ( is_wp_error( $glc_write ) ) {
	WP_CLI::error( 'Fixture write refused: ' . $glc_write->get_error_message() );
}
$glc_change  = GLC_Change_Log::latest( $glc_mine, [ 'fees' ] );
// One entry written by the owner and one by a partner, each on a car they may change: nobody may edit
// either through WordPress, and the partner may not read theirs there. A second run adds nothing.
$glc_entry_by = static function ( int $car_id, int $author, array $fees ): int {
	GLC_Price_Store::write( $car_id, [ 'fees' => $fees ], [ 'source' => 'cli', 'actor' => $author, 'note' => 'authority test fixture' ] );
	return (int) ( get_posts( [ 'post_type' => GLC_Change_Log::POST_TYPE, 'post_status' => 'private', 'author' => $author, 'posts_per_page' => 1, 'fields' => 'ids', 'meta_key' => 'glc_car_id', 'meta_value' => $car_id ] )[0] ?? 0 );
};
$glc_by_owner   = $glc_entry_by( $glc_theirs, $glc_accounts['owner']['id'], [ 'out_of_hours' => '30' ] );
$glc_by_partner = $glc_entry_by( $glc_mine, $glc_accounts['partner']['id'], [ 'out_of_hours' => '25' ] );
// An entry whose "before" leaves March without a season: the store never saved such a table, so the log
// is written directly. A restore of it must be refused.
$glc_gap_table = array_values( array_filter( GLC_Price_Store::read_pricing( $glc_mine ), static fn( array $season ): bool => ! ( $season['from'] <= '03-15' && $season['to'] >= '03-15' ) ) );
$glc_gap_entry = (int) ( get_posts( [ 'post_type' => GLC_Change_Log::POST_TYPE, 'post_status' => 'private', 'posts_per_page' => 1, 'fields' => 'ids', 'meta_key' => 'glc_note', 'meta_value' => 'authority test fixture: gap' ] )[0] ?? 0 );
if ( ! $glc_gap_entry ) {
	$glc_gap_entry = (int) GLC_Change_Log::record( [ 'car_id' => $glc_mine, 'actor' => 0, 'kind' => 'pricing', 'source' => 'cli', 'before' => $glc_gap_table, 'after' => GLC_Price_Store::read_pricing( $glc_mine ), 'note' => 'authority test fixture: gap' ] );
}
$glc_owner_user   = (int) $glc_accounts['owner']['id'];
$glc_partner_user = (int) $glc_accounts['partner']['id'];
$glc_booking = (int) ( get_posts( [ 'post_type' => 'booking_request', 'post_status' => 'any', 'posts_per_page' => 1, 'fields' => 'ids' ] )[0] ?? 0 );
if ( ! $glc_booking ) {
	$glc_booking = (int) wp_insert_post( [ 'post_type' => 'booking_request', 'post_status' => 'publish', 'post_title' => 'GL-TEST — authority test fixture' ] );
}
$glc_image = (int) ( get_posts( [ 'post_type' => 'attachment', 'post_status' => 'inherit', 'post_mime_type' => 'image', 'posts_per_page' => 1, 'orderby' => 'ID', 'order' => 'ASC', 'fields' => 'ids', 'author__not_in' => array_column( $glc_accounts, 'id' ) ] )[0] ?? 0 );

$glc_car = static fn( int $id ): array => [
	'id'    => $id,
	'title' => get_post( $id )->post_title,
	'plate' => (string) get_post_meta( $id, 'glc_registration', true ),
	'year'  => (string) get_post_meta( $id, 'glc_year', true ),
	'color' => (string) get_post_meta( $id, 'glc_color', true ),
];

echo wp_json_encode( [
	'generated_at'    => gmdate( 'c' ),
	'accounts'        => $glc_accounts,
	'cars'            => [ 'mine' => $glc_car( $glc_mine ), 'other' => $glc_car( $glc_other ), 'theirs' => $glc_car( $glc_theirs ), 'legacy' => $glc_car( $glc_legacy ) ],
	'fleet_c'         => array_map( $glc_car, $glc_fleet_c ),
	'change'          => [ 'id' => (int) ( $glc_change['id'] ?? 0 ), 'title' => $glc_change ? get_post( $glc_change['id'] )->post_title : '' ],
	'entries'         => [ 'by_owner' => $glc_by_owner, 'by_partner' => $glc_by_partner, 'gap' => $glc_gap_entry ],
	// What WordPress itself answers when asked about an entry: asked directly, the HTTP rows ask again over the wire.
	'entry_caps'      => [
		'owner_edits_own'     => user_can( $glc_owner_user, 'edit_post', $glc_by_owner ),
		'owner_deletes_own'   => user_can( $glc_owner_user, 'delete_post', $glc_by_owner ),
		'owner_reads'         => user_can( $glc_owner_user, 'read_post', $glc_by_partner ),
		'partner_reads_own'   => user_can( $glc_partner_user, 'read_post', $glc_by_partner ),
		'partner_edits_own'   => user_can( $glc_partner_user, 'edit_post', $glc_by_partner ),
		'partner_deletes_own' => user_can( $glc_partner_user, 'delete_post', $glc_by_partner ),
	],
	'site_number'     => (string) preg_replace( '/[^0-9]/', '', (string) GLC_Settings::get( 'whatsapp_number' ) ),
	'legacy_number'   => (string) preg_replace( '/[^0-9]/', '', (string) get_post_meta( $glc_legacy, 'glc_whatsapp_number', true ) ),
	'catalogue'       => [ 'en' => GLC_Admin_Strings::catalogue( 'en' ), 'ka' => GLC_Admin_Strings::catalogue( 'ka' ) ],
	'booking_request' => $glc_booking,
	'others_image'    => $glc_image,
	// How many files the uploads folder holds before the test uploads anything; it must hold as many when the test has cleaned up.
	'uploads_files'   => $glc_disk(),
	'strings'         => [
		'cars_title_en'  => glc_admin_t( 'cars_title', 'en' ),
		'cars_title_all' => glc_admin_t( 'cars_title_all', 'en' ),
		'cars_title_ka'  => glc_admin_t( 'cars_title', 'ka' ),
		'cars_empty_ka'  => glc_admin_t( 'cars_empty', 'ka' ),
		'not_read_yet'   => glc_admin_t( 'settings_not_read_yet', 'en' ),
	],
	'facts'           => $glc_facts(),
] );
