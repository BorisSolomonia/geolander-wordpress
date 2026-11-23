<?php
/**
 * The bulk screen's arithmetic and the Excel round trip against a real
 * WordPress and a real database: every rule that turns an uploaded workbook
 * into a change or into a refusal, asked from inside, where a file can be made
 * cell by cell. tests/partner-authority.mjs asks the same screens over HTTP.
 *
 * LOCAL COPY ONLY. It writes prices and fees to cars and to the change log.
 * Put the database back afterwards (reset script).
 *
 * Needs the fixture of tests/partner-authority.mjs (`bun tests/partner-authority.mjs setup`):
 * the partner glc_test_partner_c with three cars.
 *
 * Run: docker exec -i <wordpress container> wp eval-file - --allow-root < tests/bulk-excel-wp.php
 *
 * Every "is it stored" question is answered with SQL, not with the functions
 * that wrote it. Every workbook goes through the real writer and the real
 * reader, as a file on disk.
 */

if ( ! defined( 'ABSPATH' ) || ! defined( 'WP_CLI' ) ) {
	exit( "Run via wp eval-file\n" );
}
if ( 'production' === wp_get_environment_type() ) {
	WP_CLI::error( 'This test writes to cars. It does not run on a production site.' );
}

global $wpdb;
$glc_failures = 0;
$glc_check    = static function ( $condition, string $label ) use ( &$glc_failures ): void {
	if ( ! $condition ) {
		++$glc_failures;
	}
	WP_CLI::log( ( $condition ? 'PASS ' : 'FAIL ' ) . $label );
};
$glc_raw   = static fn( int $id, string $key ) => $wpdb->get_var( $wpdb->prepare( "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s", $id, $key ) );
/** Every car of the fleet: all its meta rows as one fingerprint, read with SQL. */
$glc_fleet = static function () use ( $wpdb ): array {
	$out = [];
	foreach ( $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'car' ORDER BY ID" ) as $id ) {
		$out[ (int) $id ] = md5( wp_json_encode( $wpdb->get_results( $wpdb->prepare( "SELECT meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id = %d ORDER BY meta_key, meta_id", $id ), ARRAY_N ) ) );
	}
	return $out;
};
$glc_entries = static fn( string $batch ): array => $wpdb->get_results( $wpdb->prepare(
	"SELECT p.ID, car.meta_value AS car, kind.meta_value AS kind, src.meta_value AS source FROM {$wpdb->posts} p
	 JOIN {$wpdb->postmeta} b ON b.post_id = p.ID AND b.meta_key = 'glc_batch' AND b.meta_value = %s
	 JOIN {$wpdb->postmeta} car ON car.post_id = p.ID AND car.meta_key = 'glc_car_id'
	 JOIN {$wpdb->postmeta} kind ON kind.post_id = p.ID AND kind.meta_key = 'glc_kind'
	 JOIN {$wpdb->postmeta} src ON src.post_id = p.ID AND src.meta_key = 'glc_source'
	 WHERE p.post_type = %s ORDER BY p.ID",
	$batch,
	GLC_Change_Log::POST_TYPE
), ARRAY_A );

$glc_partner = get_user_by( 'login', 'glc_test_partner_c' );
$glc_cars    = $glc_partner ? GLC_Partners::cars_for( (int) $glc_partner->ID ) : [];
if ( count( $glc_cars ) < 3 ) {
	WP_CLI::error( 'No fixture. Run: bun tests/partner-authority.mjs setup' );
}
$glc_user = (int) $glc_partner->ID;
sort( $glc_cars );
[ $glc_a, $glc_b, $glc_c ] = $glc_cars;
$glc_foreign = (int) $wpdb->get_var( $wpdb->prepare( "SELECT p.ID FROM {$wpdb->posts} p WHERE p.post_type = 'car' AND p.post_status = 'publish' AND p.ID NOT IN ( SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value <> '0' AND meta_value <> '' ) ORDER BY p.ID LIMIT 1", GLC_Partners::META_PARTNER ) );
$glc_page    = (int) $wpdb->get_var( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'page' AND post_status = 'publish' ORDER BY ID LIMIT 1" );
$glc_limits  = [ 'part_bytes' => 2048 * KB_IN_BYTES, 'rows' => 5000, 'cells' => 140000 ];
$glc_tmp     = tempnam( sys_get_temp_dir(), 'glct' );

/** Sheets as arrays → a real .xlsx on disk → what the real reader makes of it. */
$glc_book = static function ( array $sheets ) use ( $glc_tmp, $glc_limits ): array {
	GLC_Xlsx::write( $sheets, $glc_tmp );
	return GLC_Xlsx::read( $glc_tmp, $glc_limits );
};
/** Put a value in a cell of a sheet array by its Excel address ("H2"). */
$glc_set = static function ( array &$sheets, string $sheet, string $ref, $value ): void {
	preg_match( '/^([A-Z]+)(\d+)$/', $ref, $m );
	$row = (int) $m[2] - 1;
	$col = GLC_Xlsx::column_index( $m[1] );
	for ( $i = count( $sheets[ $sheet ][ $row ] ); $i <= $col; $i++ ) {
		$sheets[ $sheet ][ $row ][ $i ] = null;
	}
	$sheets[ $sheet ][ $row ][ $col ] = $value;
};
/** The plan for an edited copy of the partner's own download. */
$glc_fresh = static fn(): array => GLC_Partner_Excel::sheets( $glc_cars, $glc_user );
$glc_plan  = static function ( array $edits, ?callable $reshape = null ) use ( $glc_fresh, $glc_set, $glc_book, $glc_user ): array {
	$sheets = $glc_fresh();
	foreach ( $edits as [ $sheet, $ref, $value ] ) {
		$glc_set( $sheets, $sheet, $ref, $value );
	}
	if ( $reshape ) {
		$sheets = $reshape( $sheets );
	}
	return GLC_Partner_Excel::plan_from( $glc_book( $sheets ), $glc_user );
};
$glc_stops = static fn( array $plan ): string => implode( ' | ', $plan['stops'] );
/** Refused whole: reasons given, nothing to apply, and the wanted words in the reasons. */
$glc_refused = static fn( array $plan, string ...$needles ): bool => $plan['stops'] && ! $plan['items'] && ! array_filter( $needles, static fn( string $needle ): bool => ! str_contains( implode( ' | ', $plan['stops'] ), $needle ) );

$glc_before_all = $glc_fleet();
$glc_stored_a   = GLC_Price_Store::read_pricing( $glc_a );
// Row 2 of Prices is the first season of the first car listed; cars are listed by title, so find car A's first row.
$glc_first = static function ( int $car_id ) use ( $glc_fresh ): int {
	foreach ( $glc_fresh()['Prices'] as $i => $row ) {
		if ( $i && $row[0] === $car_id ) {
			return $i + 1;
		}
	}
	return 0;
};
$glc_row_a = $glc_first( $glc_a );
$glc_row_b = $glc_first( $glc_b );
$glc_fee_row = static function ( int $car_id ) use ( $glc_fresh ): int {
	foreach ( $glc_fresh()['Fees'] as $i => $row ) {
		if ( $i && $row[0] === $car_id ) {
			return $i + 1;
		}
	}
	return 0;
};

/* ------------------------------------------------------- the file that goes out */

$glc_sheets = $glc_fresh();
$glc_seasons = array_sum( array_map( static fn( int $id ): int => count( GLC_Price_Store::read_pricing( $id ) ), $glc_cars ) );
$glc_check( [ 'Prices', 'Fees', 'Meta' ] === array_keys( $glc_sheets ) && count( $glc_sheets['Prices'] ) === 1 + $glc_seasons && 4 === count( $glc_sheets['Fees'] ) && 4 === count( $glc_sheets['Meta'] ), "The partner's file has three sheets: {$glc_seasons} season rows for 3 cars, one fee row and one version row per car" );
$glc_ids = array_values( array_unique( array_column( array_slice( $glc_sheets['Prices'], 1 ), 0 ) ) );
sort( $glc_ids );
$glc_check( $glc_ids === $glc_cars && ! in_array( $glc_foreign, array_column( $glc_sheets['Fees'], 0 ), true ), 'It holds the partner\'s own cars and no other: #' . implode( ', #', $glc_ids ) );
$glc_owner_cars = GLC_Partners::cars_for( (int) get_user_by( 'login', 'glc_test_owner' )->ID );
$glc_check( count( $glc_owner_cars ) === count( GLC_Partner_Excel::sheets( $glc_owner_cars, 1 )['Fees'] ) - 1, 'The owner\'s file holds every current car' );
$glc_check( is_string( $glc_sheets['Prices'][ $glc_row_a - 1 ][4] ) && $glc_stored_a[0]['from'] === $glc_sheets['Prices'][ $glc_row_a - 1 ][4] && is_int( $glc_sheets['Prices'][ $glc_row_a - 1 ][7] ), 'Season dates go out as text ("' . $glc_sheets['Prices'][ $glc_row_a - 1 ][4] . '"), prices as numbers' );

$glc_same = GLC_Partner_Excel::plan_from( $glc_book( $glc_sheets ), $glc_user );
$glc_check( ! $glc_same['stops'] && ! $glc_same['items'] && ! $glc_same['cells'], 'The file uploaded as it was downloaded changes nothing and is not refused' );

/* ------------------------------------------------ what a filled-in file changes */

$glc_plan_two = $glc_plan( [ [ 'Prices', 'H' . $glc_row_a, 61 ], [ 'Prices', 'N' . ( $glc_row_b + 1 ), 29.5 ] ] );
$glc_check( ! $glc_plan_two['stops'] && [ 'Prices!H' . $glc_row_a, 'Prices!N' . ( $glc_row_b + 1 ) ] === array_column( $glc_plan_two['cells'], 'ref' ) && [ $glc_a, $glc_b ] === array_keys( $glc_plan_two['items'] ), 'Two prices changed in the file: exactly those two cells are listed, for exactly those two cars: ' . implode( ', ', array_column( $glc_plan_two['cells'], 'ref' ) ) );
$glc_check( '61' === (string) $glc_plan_two['items'][ $glc_a ]['changes']['pricing'][0]['rates']['d1_2'] && $glc_stored_a[0]['rates']['d3_4'] === $glc_plan_two['items'][ $glc_a ]['changes']['pricing'][0]['rates']['d3_4'] && GLC_Price_Store::rev( $glc_a ) === $glc_plan_two['items'][ $glc_a ]['rev'], 'The change is the whole stored table with the one price replaced, and carries the version the file was made from' );

$glc_blank = $glc_plan( [ [ 'Prices', 'H' . $glc_row_a, null ], [ 'Prices', 'I' . $glc_row_a, null ], [ 'Prices', 'J' . $glc_row_a, 50 ] ] );
$glc_check( ! $glc_blank['stops'] && [ 'Prices!J' . $glc_row_a ] === array_column( $glc_blank['cells'], 'ref' ) && $glc_stored_a[0]['rates']['d1_2'] === $glc_blank['items'][ $glc_a ]['changes']['pricing'][0]['rates']['d1_2'], 'An emptied price cell keeps the stored price: two cells emptied and one filled change one price' );

$glc_text = $glc_plan( [ [ 'Prices', 'H' . $glc_row_a, '61' ], [ 'Prices', 'I' . $glc_row_a, ' 62.50 ' ] ] );
$glc_check( ! $glc_text['stops'] && 2 === count( $glc_text['cells'] ) && '62.50' === $glc_text['items'][ $glc_a ]['changes']['pricing'][0]['rates']['d3_4'], 'A number Excel stored as text ("61", " 62.50 ") is still a price' );

// Excel writes some decimals with a long tail: 54.99 is stored as 54.990000000000002.
$glc_tail = static function ( array $sheets ) use ( $glc_tmp, $glc_limits, $glc_row_a ): array {
	GLC_Xlsx::write( $sheets, $glc_tmp );
	$zip = new ZipArchive();
	$zip->open( $glc_tmp );
	$xml = $zip->getFromName( 'xl/worksheets/sheet1.xml' );
	$zip->addFromString( 'xl/worksheets/sheet1.xml', preg_replace( '~(<c r="H' . $glc_row_a . '"[^>]*><v>)[^<]*~', '${1}54.990000000000002', $xml ) );
	$zip->close();
	return GLC_Xlsx::read( $glc_tmp, $glc_limits );
};
$glc_long = GLC_Partner_Excel::plan_from( $glc_tail( $glc_fresh() ), $glc_user );
$glc_check( ! $glc_long['stops'] && '54.99' === ( $glc_long['items'][ $glc_a ]['changes']['pricing'][0]['rates']['d1_2'] ?? '' ), 'A price Excel stored as 54.990000000000002 is read as 54.99, not refused for its decimals' );

// Columns moved and widened, an extra column of notes added, the heading in capitals.
$glc_moved = $glc_plan( [ [ 'Prices', 'H' . $glc_row_a, 61 ] ], static function ( array $sheets ): array {
	foreach ( $sheets['Prices'] as $i => $row ) {
		$sheets['Prices'][ $i ] = array_merge( array_reverse( array_slice( $row, 7 ) ), [ 0 === $i ? 'My notes' : 'a note for myself, rather long, in a widened column' ], array_slice( $row, 0, 7 ) );
	}
	$sheets['Prices'][0] = array_map( static fn( $heading ) => 'My notes' === $heading ? $heading : strtoupper( ' ' . $heading . ' ' ), $sheets['Prices'][0] );
	return $sheets;
} );
$glc_check( ! $glc_moved['stops'] && 1 === count( $glc_moved['cells'] ) && 'Prices!G' . $glc_row_a === $glc_moved['cells'][0]['ref'] && '61' === (string) $glc_moved['items'][ $glc_a ]['changes']['pricing'][0]['rates']['d1_2'], 'Columns in another order, an added column and headings in capitals are accepted, and the changed cell is named where it now is: ' . ( $glc_moved['cells'][0]['ref'] ?? $glc_stops( $glc_moved ) ) );

// Rows sorted the other way round: a row is found by its car and season, not by where it stands.
$glc_sorted = $glc_plan( [ [ 'Prices', 'H' . $glc_row_a, 61 ] ], static function ( array $sheets ): array {
	$sheets['Prices'] = array_merge( [ $sheets['Prices'][0] ], array_reverse( array_slice( $sheets['Prices'], 1 ) ) );
	return $sheets;
} );
$glc_check( ! $glc_sorted['stops'] && 1 === count( $glc_sorted['cells'] ) && [ $glc_a ] === array_keys( $glc_sorted['items'] ), 'Rows sorted in another order are accepted' );

// A season date Excel re-typed as a date (a number with a date format): still the day that went out, in either calendar.
[ $glc_from_m, $glc_from_d ] = array_map( 'intval', explode( '-', $glc_stored_a[0]['from'] ) );
$glc_serial = (int) ( ( gmmktime( 0, 0, 0, $glc_from_m, $glc_from_d, 2026 ) - gmmktime( 0, 0, 0, 12, 30, 1899 ) ) / DAY_IN_SECONDS );
$glc_dated  = $glc_plan( [ [ 'Prices', 'E' . $glc_row_a, $glc_serial ], [ 'Prices', 'H' . $glc_row_a, 61 ] ] );
$glc_check( ! $glc_dated['stops'] && 1 === count( $glc_dated['cells'] ), "A season date Excel turned into a date ({$glc_serial} for {$glc_stored_a[0]['from']}) is read back as the same day" );
$glc_wrong_day = $glc_plan( [ [ 'Prices', 'E' . $glc_row_a, $glc_serial + 1 ] ] );
$glc_check( $glc_refused( $glc_wrong_day, 'Prices!E' . $glc_row_a, 'panel' ), 'and one day later as a date is refused like any other changed season date' );

/* ---------------------------------------------- seasons cannot change in a file */

$glc_from = $glc_plan( [ [ 'Prices', 'E' . $glc_row_a, '07-04' ] ] );
$glc_check( $glc_refused( $glc_from, 'Prices!E' . $glc_row_a, '07-04', $glc_stored_a[0]['from'], 'panel' ), 'A season\'s first day changed in the file: refused whole, naming the cell, and saying dates are changed in the panel — ' . ( $glc_from['stops'][0] ?? '' ) );
$glc_to = $glc_plan( [ [ 'Prices', 'F' . ( $glc_row_a + 2 ), '01-07' ], [ 'Prices', 'H' . $glc_row_b, 61 ] ] );
$glc_check( $glc_refused( $glc_to, 'Prices!F' . ( $glc_row_a + 2 ) ) && 1 === count( $glc_to['stops'] ), 'A last day changed, with a good price change for another car in the same file: nothing of the file is taken' );
$glc_removed = $glc_plan( [], static function ( array $sheets ) use ( $glc_row_a ): array {
	array_splice( $sheets['Prices'], $glc_row_a + 2, 1 );
	return $sheets;
} );
$glc_check( $glc_refused( $glc_removed, 'season 4', 'panel' ), 'A season row removed: refused, naming the car and the season that is missing — ' . ( $glc_removed['stops'][0] ?? '' ) );
$glc_added = $glc_plan( [], static function ( array $sheets ) use ( $glc_a, $glc_row_a ): array {
	$extra    = $sheets['Prices'][ $glc_row_a - 1 ];
	$extra[3] = 10;
	$extra[4] = '02-01';
	$extra[5] = '02-10';
	$sheets['Prices'][] = $extra;
	return $sheets;
} );
$glc_check( $glc_refused( $glc_added, 'Prices!D' . ( 2 + $glc_seasons ), 'panel' ), 'A season row added: refused, naming the cell, and saying seasons are added in the panel — ' . ( $glc_added['stops'][0] ?? '' ) );
$glc_twice = $glc_plan( [], static function ( array $sheets ) use ( $glc_row_a ): array {
	$sheets['Prices'][] = $sheets['Prices'][ $glc_row_a - 1 ];
	return $sheets;
} );
$glc_check( $glc_refused( $glc_twice, 'twice' ), 'The same season twice: refused' );

/* ------------------------------------------------------ whose cars, which cars */

$glc_not_theirs = [];
foreach ( [ 'another person\'s car' => $glc_foreign, 'a page' => $glc_page, 'nothing' => 99999999 ] as $what => $id ) {
	$plan = $glc_plan( [ [ 'Prices', 'H' . $glc_row_b, 61 ] ], static function ( array $sheets ) use ( $id, $glc_row_a ): array {
		$sheets['Prices'][ $glc_row_a - 1 ][0] = $id;
		$sheets['Meta'][]                      = [ $id, 0, 'x', 1 ];
		return $sheets;
	} );
	$glc_not_theirs[ $what ] = preg_replace( '/\d+/', 'N', $glc_stops( $plan ) );
	$glc_check( $glc_refused( $plan, 'Prices!A' . $glc_row_a ) && ! str_contains( $glc_stops( $plan ), get_the_title( $glc_foreign ) ), "A row for {$what} (#{$id}) among the partner's own, with a good change for an own car: refused whole, naming the cell" );
}
$glc_check( 1 === count( array_unique( $glc_not_theirs ) ), 'The sentence is the same for a car that is someone else\'s, a number that is not a car, and a number that is nothing: "' . reset( $glc_not_theirs ) . '"' );
$glc_owner_file = GLC_Partner_Excel::sheets( [ $glc_a, $glc_foreign ], 1 );
$glc_owner_plan = GLC_Partner_Excel::plan_from( $glc_book( $glc_owner_file ), $glc_user );
$glc_check( $glc_refused( $glc_owner_plan, 'Prices!A' ), 'A file the owner downloaded (with a car that is not the partner\'s), uploaded by the partner unchanged: refused' );
$glc_plate = $glc_plan( [ [ 'Prices', 'B' . $glc_row_a, 'ZZ-999-ZZ' ] ] );
$glc_check( $glc_refused( $glc_plate, 'Prices!B' . $glc_row_a, 'ZZ-999-ZZ' ), 'A plate that is not the car\'s: refused, naming the cell' );
$glc_plate_ok = $glc_plan( [ [ 'Prices', 'B' . $glc_row_a, strtolower( str_replace( '-', ' ', (string) get_post_meta( $glc_a, 'glc_registration', true ) ) ) ], [ 'Prices', 'H' . $glc_row_a, 61 ] ] );
$glc_check( ! $glc_plate_ok['stops'] && 1 === count( $glc_plate_ok['cells'] ), 'The same plate written in small letters with spaces is still that plate' );

/* -------------------------------------------------- cells that are not a price */

$glc_word = $glc_plan( [ [ 'Prices', 'H' . $glc_row_a, 'fifty' ], [ 'Prices', 'I' . $glc_row_a, '54,50' ], [ 'Prices', 'J' . $glc_row_a, -5 ] ] );
$glc_check( $glc_refused( $glc_word, 'Prices!H' . $glc_row_a, 'fifty', 'Prices!I' . $glc_row_a, '54,50', 'Prices!J' . $glc_row_a ) && 3 === count( $glc_word['stops'] ), 'A word, a number with a comma and a number below zero: each refused with its own cell — ' . $glc_stops( $glc_word ) );

// A price the store's limits refuse: the reason is the store's, and it still names the cell.
wp_set_current_user( $glc_user );
$glc_high   = $glc_plan( [ [ 'Prices', 'H' . $glc_row_a, 999 ], [ 'Prices', 'K' . $glc_row_a, 1 ], [ 'Prices', 'L' . $glc_row_a, 50.123 ] ] );
$glc_viewed = GLC_Partner_Batch::preview( [ 'source' => 'excel' ] + $glc_high );
$glc_lines  = implode( ' | ', $glc_viewed['cars'][ $glc_a ]['errors'] ?? [] );
$glc_check( ! $glc_high['stops'] && $glc_viewed['blocked'] && str_contains( $glc_lines, 'Prices!H' . $glc_row_a . ':' ) && str_contains( $glc_lines, '$999' ) && str_contains( $glc_lines, 'Prices!K' . $glc_row_a . ':' ) && str_contains( $glc_lines, 'Prices!L' . $glc_row_a . ':' ) && str_contains( $glc_lines, 'decimals' ), 'A price above the limit, one below it and one with three decimals stop the preview, each with its cell — ' . $glc_lines );

/* ------------------------------------------------------------------ the fees */

$glc_fr   = $glc_fee_row( $glc_a );
$glc_fees = $glc_plan( [ [ 'Fees', 'D' . $glc_fr, 0 ], [ 'Fees', 'F' . $glc_fr, 70 ], [ 'Fees', 'H' . $glc_fr, 12.5 ] ] );
$glc_check( ! $glc_fees['stops'] && [ 'Fees!D' . $glc_fr, 'Fees!F' . $glc_fr, 'Fees!H' . $glc_fr ] === array_column( $glc_fees['cells'], 'ref' ) && [ 'delivery' => [ 'tbilisi_city' => '0', 'tbilisi_airport' => '', 'kutaisi_airport' => '70', 'batumi_airport' => '' ], 'out_of_hours' => '12.5' ] === $glc_fees['items'][ $glc_a ]['changes']['fees'], 'Fees: 0 is free, a number is the car\'s own fee, an empty cell keeps what is stored — ' . wp_json_encode( $glc_fees['items'][ $glc_a ]['changes']['fees'] ?? null ) );
$glc_fee_bad = $glc_plan( [ [ 'Fees', 'E' . $glc_fr, 'free' ], [ 'Fees', 'G' . $glc_fr, 'standard' ] ] );
$glc_check( $glc_refused( $glc_fee_bad, 'Fees!E' . $glc_fr, 'Fees!G' . $glc_fr, '"site"' ), 'A fee cell with any other word is refused, naming the cell and the word that is understood' );
$glc_fee_high = $glc_plan( [ [ 'Fees', 'F' . $glc_fr, 99999 ] ] );
$glc_viewed   = GLC_Partner_Batch::preview( [ 'source' => 'excel' ] + $glc_fee_high );
$glc_check( $glc_viewed['blocked'] && str_contains( implode( ' ', $glc_viewed['cars'][ $glc_a ]['errors'] ?? [] ), 'Fees!F' . $glc_fr . ':' ), 'A fee above the limit stops the preview and names its cell — ' . implode( ' ', $glc_viewed['cars'][ $glc_a ]['errors'] ?? [] ) );

/* ----------------------------------------------------- not this template at all */

$glc_no_sheet = $glc_plan( [], static function ( array $sheets ): array {
	unset( $sheets['Meta'] );
	return $sheets;
} );
$glc_check( $glc_refused( $glc_no_sheet, '"Meta"' ), 'A file without one of the three sheets: refused as not the file downloaded here — ' . $glc_stops( $glc_no_sheet ) );
$glc_no_heading = $glc_plan( [ [ 'Prices', 'H1', 'price' ] ] );
$glc_check( $glc_refused( $glc_no_heading, '"Prices"', '"d1_2"' ), 'A changed heading: refused, naming the sheet and the heading that is missing' );
$glc_other_book = GLC_Partner_Excel::plan_from( $glc_book( [ 'Sheet1' => [ [ 'Invoice', 'Amount' ], [ 'A-1', 100 ] ] ] ), $glc_user );
$glc_check( $glc_refused( $glc_other_book, '"Prices"', '"Fees"', '"Meta"' ), 'Some other workbook: refused, all three sheets named as missing' );
$glc_no_meta = $glc_plan( [], static function ( array $sheets ): array {
	$sheets['Meta'] = [ $sheets['Meta'][0] ];
	return $sheets;
} );
$glc_check( $glc_refused( $glc_no_meta, 'Prices!A2' ), 'A file whose version rows were deleted: refused' );

/* --------------------------------------------- a file older than the car it is for */

$glc_old_sheets = $glc_fresh();
$glc_set( $glc_old_sheets, 'Prices', 'H' . $glc_row_b, 61 );
$glc_write = GLC_Price_Store::write( $glc_a, [ 'fees' => [ 'out_of_hours' => '19' ] ], [ 'source' => 'cli', 'actor' => $glc_user, 'note' => 'bulk-excel test: a later change' ] );
$glc_old   = GLC_Partner_Excel::plan_from( $glc_book( $glc_old_sheets ), $glc_user );
$glc_check( ! is_wp_error( $glc_write ) && $glc_refused( $glc_old, 'Prices!A' . $glc_row_a, 'Test Partner C', 'after this file was downloaded', gmdate( 'Y-m-d' ) ), 'A file made before a later change to one of its cars: refused whole, saying who changed the car and when — ' . ( $glc_old['stops'][0] ?? '' ) );
$glc_check( 1 === count( array_unique( $glc_old['stops'] ) ) || ! array_filter( $glc_old['stops'], static fn( string $s ): bool => str_contains( $s, 'season' ) ), 'and it is refused for being old, not with a list of rows that no longer match' );

/* ------------------------------------------------------------- applying a file */

$glc_before  = $glc_fleet();
$glc_apply   = $glc_plan( [ [ 'Prices', 'H' . $glc_row_a, 61 ], [ 'Prices', 'N' . ( $glc_row_b + 1 ), 29.5 ], [ 'Fees', 'F' . $glc_fee_row( $glc_b ), 70 ] ] );
$glc_revs    = [ $glc_a => GLC_Price_Store::rev( $glc_a ), $glc_b => GLC_Price_Store::rev( $glc_b ) ];
$glc_done    = GLC_Price_Store::write_batch( $glc_apply['items'], [ 'source' => 'excel' ] );
$glc_after   = $glc_fleet();
$glc_changed = array_keys( array_diff_assoc( $glc_after, $glc_before ) );
$glc_log     = is_wp_error( $glc_done ) ? [] : $glc_entries( $glc_done['batch'] );
$glc_check( ! is_wp_error( $glc_done ) && [ $glc_a, $glc_b ] === $glc_changed, 'An Excel apply changes the two cars the file changes; the other ' . ( count( $glc_before ) - 2 ) . ' of the 19 are byte for byte as before (every meta row, by SQL)' );
$glc_check( 3 === count( $glc_log ) && [ 'excel' ] === array_values( array_unique( array_column( $glc_log, 'source' ) ) ) && [ "{$glc_a}:pricing", "{$glc_b}:pricing", "{$glc_b}:fees" ] === array_map( static fn( array $row ): string => $row['car'] . ':' . $row['kind'], $glc_log ), 'One batch: three change-log entries with one batch id and the source "excel" — ' . implode( ', ', array_map( static fn( array $row ): string => "#{$row['ID']} car {$row['car']} {$row['kind']}", $glc_log ) ) );
$glc_stored = (string) $glc_raw( $glc_a, 'glc_pricing' );
$glc_table  = unserialize( $glc_stored, [ 'allowed_classes' => false ] );
$glc_check( str_starts_with( $glc_stored, 'a:' . count( $glc_stored_a ) . ':{i:0;a:4:{s:5:"label";s:15:"' ) && 61 === $glc_table[0]['rates']['d1_2'] && $glc_stored_a[0]['rates']['d3_4'] === $glc_table[0]['rates']['d3_4'] && $glc_stored_a[0]['label'] === $glc_table[0]['label'], 'Stored shape after an Excel apply, read with SQL: ' . substr( $glc_stored, 0, 96 ) . '… (label, from, to, rates; the new price an integer like its neighbours)' );
$glc_table_b = unserialize( (string) $glc_raw( $glc_b, 'glc_pricing' ), [ 'allowed_classes' => false ] );
$glc_check( 29.5 === $glc_table_b[1]['rates']['d31p'] && 'a:1:{s:15:"kutaisi_airport";s:2:"70";}' === $glc_raw( $glc_b, 'glc_delivery_fees' ) && null === $glc_raw( $glc_b, 'glc_out_of_hours_fee' ), 'A price with cents is stored as 29.5; the fee as the override a:1:{s:15:"kutaisi_airport";s:2:"70";}; no early/late row (site standard)' );
$glc_check( (string) ( $glc_revs[ $glc_a ] + 1 ) === $glc_raw( $glc_a, 'glc_rev' ) && (string) ( $glc_revs[ $glc_b ] + 1 ) === $glc_raw( $glc_b, 'glc_rev' ), 'Each changed car is one version up: ' . $glc_raw( $glc_a, 'glc_rev' ) . ' and ' . $glc_raw( $glc_b, 'glc_rev' ) );
$glc_again = GLC_Partner_Excel::plan_from( $glc_book( $glc_old_sheets ), $glc_user );
$glc_check( $glc_refused( $glc_again, 'after this file was downloaded' ), 'The file that was just applied cannot be applied twice: its cars have moved on' );

/* ------------------------------------------------------ the bulk screen's sums */

$glc_draft = static fn( array $over ): array => $over + [ 'use_pricing' => false, 'mode' => 'replace', 'table' => [], 'factor' => '', 'delta' => '', 'round' => true, 'use_delivery' => false, 'use_out_of_hours' => false, 'fees' => array_fill_keys( array_merge( GLC_Rental::fee_place_keys(), [ 'out_of_hours' ] ), [ 'mode' => 'leave', 'amount' => '' ] ) ];
$glc_now_c = GLC_Price_Store::read_pricing( $glc_c );
[ $glc_items, $glc_stop ] = GLC_Partner_Bulk::build( [ $glc_c ], $glc_draft( [ 'use_pricing' => true, 'mode' => 'adjust', 'factor' => '0.98', 'delta' => '5' ] ) );
$glc_want = (float) round( $glc_now_c[0]['rates']['d1_2'] * 0.98 + 5 );
$glc_check( ! $glc_stop && $glc_want === (float) $glc_items[ $glc_c ]['changes']['pricing'][0]['rates']['d1_2'] && $glc_now_c[0]['from'] === $glc_items[ $glc_c ]['changes']['pricing'][0]['from'] && count( $glc_now_c ) === count( $glc_items[ $glc_c ]['changes']['pricing'] ), "Adjust ×0.98 then +5, whole dollars: {$glc_now_c[0]['rates']['d1_2']} → {$glc_want}; the car keeps its own seasons" );
[ $glc_items ] = GLC_Partner_Bulk::build( [ $glc_c ], $glc_draft( [ 'use_pricing' => true, 'mode' => 'adjust', 'factor' => '1.033', 'round' => false ] ) );
$glc_check( round( $glc_now_c[0]['rates']['d1_2'] * 1.033, 2 ) === $glc_items[ $glc_c ]['changes']['pricing'][0]['rates']['d1_2'], 'Without rounding to whole dollars the result is kept to the cent: ' . $glc_items[ $glc_c ]['changes']['pricing'][0]['rates']['d1_2'] );
foreach ( [
	'no car ticked'             => [ [], [ 'use_pricing' => true, 'mode' => 'adjust', 'factor' => '0.98' ] ],
	'nothing switched on'       => [ [ $glc_c ], [] ],
	'adjust with nothing typed' => [ [ $glc_c ], [ 'use_pricing' => true, 'mode' => 'adjust' ] ],
	'a factor that is a word'   => [ [ $glc_c ], [ 'use_pricing' => true, 'mode' => 'adjust', 'factor' => 'two' ] ],
	'a factor of zero'          => [ [ $glc_c ], [ 'use_pricing' => true, 'mode' => 'adjust', 'factor' => '0' ] ],
	'an amount with a comma'    => [ [ $glc_c ], [ 'use_pricing' => true, 'mode' => 'adjust', 'delta' => '2,5' ] ],
	'"set" without an amount'   => [ [ $glc_c ], [ 'use_delivery' => true, 'fees' => [ 'kutaisi_airport' => [ 'mode' => 'set', 'amount' => '' ] ] + $glc_draft( [] )['fees'] ] ],
] as $glc_what => [ $glc_ids, $glc_over ] ) {
	[ $glc_items, $glc_stop ] = GLC_Partner_Bulk::build( $glc_ids, $glc_draft( $glc_over ) );
	$glc_check( ! $glc_items && 1 === count( $glc_stop ), "The form is stopped before any car is looked at — {$glc_what}: {$glc_stop[0]}" );
}

// One car in a batch that breaks a limit: the store writes none of them.
$glc_before = $glc_fleet();
$glc_count  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s", GLC_Change_Log::POST_TYPE ) );
[ $glc_items ] = GLC_Partner_Bulk::build( $glc_cars, $glc_draft( [ 'use_pricing' => true, 'mode' => 'adjust', 'factor' => '1', 'delta' => '-21' ] ) );
$glc_viewed   = GLC_Partner_Batch::preview( [ 'source' => 'bulk', 'items' => $glc_items, 'stops' => [] ] );
$glc_blocked  = array_keys( array_filter( $glc_viewed['cars'], static fn( array $car ): bool => (bool) $car['errors'] ) );
$glc_passing  = array_keys( array_filter( $glc_viewed['cars'], static fn( array $car ): bool => ! $car['errors'] && $car['lines'] ) );
$glc_result   = GLC_Price_Store::write_batch( $glc_items, [ 'source' => 'bulk' ] );
$glc_check( $glc_blocked && $glc_passing && $glc_viewed['blocked'] && is_wp_error( $glc_result ) && $glc_fleet() === $glc_before && $glc_count === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s", GLC_Change_Log::POST_TYPE ) ), 'Every price $21 lower: ' . count( $glc_blocked ) . ' car(s) would fall under the lowest allowed price and ' . count( $glc_passing ) . ' would pass. The preview is blocked, and an apply sent anyway writes nothing to any of the 19 cars and logs nothing' );
$glc_check( str_contains( implode( ' ', $glc_viewed['cars'][ $glc_blocked[0] ]['errors'] ), 'below the lowest allowed price' ), 'The blocked car says why: ' . ( $glc_viewed['cars'][ $glc_blocked[0] ]['errors'][0] ?? '' ) );

// A bulk apply: one batch, one entry per changed car, the rest of the fleet untouched.
[ $glc_items ] = GLC_Partner_Bulk::build( [ $glc_a, $glc_c ], $glc_draft( [ 'use_pricing' => true, 'mode' => 'adjust', 'factor' => '1.1', 'use_delivery' => true, 'fees' => [ 'tbilisi_city' => [ 'mode' => 'free', 'amount' => '' ], 'batumi_airport' => [ 'mode' => 'set', 'amount' => '120' ], 'kutaisi_airport' => [ 'mode' => 'standard', 'amount' => '' ] ] + $glc_draft( [] )['fees'] ] ) );
$glc_result  = GLC_Price_Store::write_batch( $glc_items, [ 'source' => 'bulk' ] );
$glc_changed = array_keys( array_diff_assoc( $glc_fleet(), $glc_before ) );
$glc_log     = is_wp_error( $glc_result ) ? [] : $glc_entries( $glc_result['batch'] );
$glc_check( ! is_wp_error( $glc_result ) && [ $glc_a, $glc_c ] === $glc_changed && 4 === count( $glc_log ) && [ 'bulk' ] === array_values( array_unique( array_column( $glc_log, 'source' ) ) ), 'A bulk apply to two cars: those two change, the other 17 are byte for byte as before, and the four entries (prices and fees, twice) share one batch id' );
$glc_check( 'a:2:{s:12:"tbilisi_city";s:1:"0";s:14:"batumi_airport";s:3:"120";}' === $glc_raw( $glc_a, 'glc_delivery_fees' ), 'Stored fees after a bulk apply, read with SQL: ' . $glc_raw( $glc_a, 'glc_delivery_fees' ) . ' (free, set, and the Kutaisi override dropped back to the standard)' );
$glc_check( count( $glc_result['pinged'] ?? [] ) === 7 * count( array_filter( [ $glc_a, $glc_c ], static fn( int $id ): bool => 'publish' === get_post_status( $id ) ) ), 'Search engines: one list for the whole batch, ' . count( $glc_result['pinged'] ?? [] ) . ' addresses (seven languages for each car whose price range moved)' );

unlink( $glc_tmp );
WP_CLI::log( '' );
WP_CLI::log( $glc_failures ? "{$glc_failures} FAILED" : 'ALL PASS' );
if ( $glc_failures ) {
	WP_CLI::halt( 1 );
}
