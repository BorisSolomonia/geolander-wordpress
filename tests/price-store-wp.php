<?php
/**
 * GLC_Price_Store and GLC_Change_Log against a real WordPress and a real database.
 *
 * LOCAL COPY ONLY. This writes to two cars and to the change log, and leaves a
 * test partner account behind. Put the database back afterwards (reset script).
 *
 * Run: docker exec -i <wordpress container> wp eval-file - --allow-root < tests/price-store-wp.php
 *
 * tests/price-store.php proves the rules with WordPress stubbed; this proves
 * what the database holds afterwards. Every "is it stored" question below is
 * answered with SQL, not with the functions that wrote it.
 *
 * Two extra modes prove that two saves of one car cannot both win. Start them
 * about a second apart, on a clean database:
 *   … wp eval-file - hold --allow-root < tests/price-store-wp.php   (saves, pausing inside its transaction)
 *   … wp eval-file - late --allow-root < tests/price-store-wp.php   (saves from the same revision: must wait, then be refused)
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
/** A car's meta exactly as the database holds it. */
$glc_raw = static function ( int $post_id, string $key ) use ( $wpdb ) {
	return $wpdb->get_var( $wpdb->prepare( "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s", $post_id, $key ) );
};
$glc_entries = static function () use ( $wpdb ): int {
	return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s", GLC_Change_Log::POST_TYPE ) );
};
$glc_errors = static function ( $result, int $car_id ): array {
	return is_wp_error( $result ) ? array_column( $result->get_error_data()['errors'][ $car_id ] ?? [], 'message', 'code' ) : [];
};

$glc_cars = get_posts( [ 'post_type' => 'car', 'post_status' => 'publish', 'posts_per_page' => 2, 'orderby' => 'ID', 'order' => 'ASC', 'fields' => 'ids' ] );
if ( count( $glc_cars ) < 2 ) {
	WP_CLI::error( 'Needs two published cars.' );
}
[ $glc_a, $glc_b ] = array_map( 'intval', $glc_cars );
$glc_table         = GLC_Price_Store::read_pricing( $glc_a );
$glc_mode          = (string) ( ( $args ?? [] )[0] ?? '' );

/* ------------------------------------------- the two-process modes (see top) */

if ( 'hold' === $glc_mode || 'late' === $glc_mode ) {
	$glc_new                     = $glc_table;
	$glc_new[0]['rates']['d1_2'] = (int) $glc_table[0]['rates']['d1_2'] + ( 'hold' === $glc_mode ? 1 : 2 );
	if ( 'hold' === $glc_mode ) {
		// Pause after the first meta write: the transaction is open and the car is locked.
		add_action( 'updated_post_meta', static function ( $meta_id, $post_id, $key ) {
			if ( GLC_Price_Store::META_PRICING === $key ) {
				sleep( 4 );
			}
		}, 10, 3 );
	}
	$glc_started = microtime( true );
	$glc_result  = GLC_Price_Store::write( $glc_a, [ 'pricing' => $glc_new ], [ 'source' => 'cli', 'rev' => 0 ] );
	WP_CLI::log( sprintf(
		'%s: %s after %.1f s; car %d is at revision %d, 1-2 day price %s',
		$glc_mode,
		is_wp_error( $glc_result ) ? 'REFUSED (' . implode( ', ', array_keys( $glc_errors( $glc_result, $glc_a ) ) ) . ')' : 'SAVED',
		microtime( true ) - $glc_started,
		$glc_a,
		(int) $glc_raw( $glc_a, GLC_Price_Store::META_REV ),
		maybe_unserialize( $glc_raw( $glc_a, GLC_Price_Store::META_PRICING ) )[0]['rates']['d1_2']
	) );
	return;
}

/* ----------------------------------------------------------------- authority */

$glc_partner = get_user_by( 'login', 'glc_test_store_partner' );
$glc_partner = $glc_partner ? (int) $glc_partner->ID : (int) wp_insert_user( [
	'user_login'   => 'glc_test_store_partner',
	'user_pass'    => wp_generate_password( 32 ),
	'user_email'   => 'glc_test_store_partner@example.test',
	'display_name' => 'Store Test Partner',
	'role'         => GLC_Partners::ROLE,
] );
$glc_owner = (int) ( get_users( [ 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ] )[0] ?? 0 );
update_post_meta( $glc_a, GLC_Partners::META_PARTNER, $glc_partner );
delete_post_meta( $glc_b, GLC_Partners::META_PARTNER );
$glc_page = (int) ( get_posts( [ 'post_type' => 'page', 'posts_per_page' => 1, 'fields' => 'ids' ] )[0] ?? 0 );

$glc_check( GLC_Partners::can_manage( $glc_partner, $glc_a ), 'A partner may change a car assigned to them' );
$glc_check( ! GLC_Partners::can_manage( $glc_partner, $glc_b ), 'A partner may not change a car that is not assigned to them' );
$glc_check( GLC_Partners::can_manage( $glc_owner, $glc_a ) && GLC_Partners::can_manage( $glc_owner, $glc_b ), 'The site owner may change every car' );
$glc_check( ! GLC_Partners::can_manage( 0, $glc_a ) && ! GLC_Partners::can_manage( $glc_partner, $glc_page ) && ! GLC_Partners::can_manage( $glc_owner, $glc_page ), 'Nobody signed in, and a post that is not a car, are refused' );
$glc_check( GLC_Partners::cars_for( $glc_partner ) === [ $glc_a ] && count( GLC_Partners::cars_for( $glc_owner ) ) === (int) array_sum( (array) wp_count_posts( 'car' ) ) - (int) wp_count_posts( 'car' )->trash - (int) wp_count_posts( 'car' )->{'auto-draft'}, 'The partner\'s list is the assigned car; the owner\'s list is every car' );

/* --------------------------------------------------------------- a first save */

$glc_raw_before = $glc_raw( $glc_a, GLC_Price_Store::META_PRICING );
$glc_count      = $glc_entries();
$glc_new        = $glc_table;
$glc_new[0]['rates']['d1_2'] = (int) $glc_table[0]['rates']['d1_2'] + 7; // the top price moves: the public range changes
$glc_new[2]['rates']['d5_7'] = '41.50';                                  // typed as text, with cents
$glc_result = GLC_Price_Store::write( $glc_a, [ 'pricing' => $glc_new ], [ 'source' => 'cli', 'actor' => $glc_partner, 'rev' => 0, 'note' => 'first save' ] );

$glc_stored = maybe_unserialize( $glc_raw( $glc_a, GLC_Price_Store::META_PRICING ) );
$glc_check( ! is_wp_error( $glc_result ) && '1' === $glc_raw( $glc_a, GLC_Price_Store::META_REV ), 'A valid save is written and the stored revision is 1' );
$glc_check( is_array( $glc_stored ) && $glc_stored[0]['rates']['d1_2'] === $glc_new[0]['rates']['d1_2'] && 41.5 === $glc_stored[2]['rates']['d5_7'] && $glc_stored[0]['label'] === $glc_table[0]['label'], 'The database holds the new prices as numbers and the season labels unchanged' );
$glc_check( $glc_raw( $glc_a, GLC_Price_Store::META_PREVIOUS ) === $glc_raw_before, 'glc_pricing_previous is byte-for-byte the table that was live' );

$glc_entry_id = (int) ( $glc_result['cars'][ $glc_a ]['entries'][0] ?? 0 );
$glc_row      = $wpdb->get_row( $wpdb->prepare( "SELECT post_type, post_status, post_author, post_title FROM {$wpdb->posts} WHERE ID = %d", $glc_entry_id ) );
$glc_check( $glc_entries() === $glc_count + 1 && $glc_row && GLC_Change_Log::POST_TYPE === $glc_row->post_type && 'private' === $glc_row->post_status && (int) $glc_row->post_author === $glc_partner, 'One change-log post, private, authored by the actor' );
$glc_check( $glc_row && (bool) preg_match( '/^#' . $glc_a . ' pricing by Store Test Partner \d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $glc_row->post_title ), 'Its title is "#<car> <kind> by <name> <date time>"' . ( $glc_row ? ': ' . $glc_row->post_title : '' ) );
$glc_json = $wpdb->get_row( $wpdb->prepare(
	"SELECT JSON_VALID(b.meta_value) AS before_valid, JSON_TYPE(b.meta_value) AS before_type, JSON_VALID(a.meta_value) AS after_valid, JSON_TYPE(a.meta_value) AS after_type, b.meta_value AS before_json, a.meta_value AS after_json
	 FROM {$wpdb->postmeta} b JOIN {$wpdb->postmeta} a ON a.post_id = b.post_id AND a.meta_key = %s WHERE b.post_id = %d AND b.meta_key = %s",
	'glc_after',
	$glc_entry_id,
	'glc_before'
) );
$glc_check( $glc_json && '1' === (string) $glc_json->before_valid && 'ARRAY' === $glc_json->before_type && '1' === (string) $glc_json->after_valid && 'ARRAY' === $glc_json->after_type, 'Before and after are stored as JSON arrays, not as JSON text inside a string' );
$glc_check( $glc_json && json_decode( $glc_json->before_json, true ) === $glc_table && json_decode( $glc_json->after_json, true ) === $glc_stored, 'The stored before is the old table and the stored after is the new one, value for value' );
$glc_meta = [];
foreach ( [ 'glc_car_id', 'glc_actor', 'glc_kind', 'glc_source', 'glc_batch', 'glc_note' ] as $glc_key ) {
	$glc_meta[ $glc_key ] = $glc_raw( $glc_entry_id, $glc_key );
}
$glc_check( [ (string) $glc_a, (string) $glc_partner, 'pricing', 'cli', $glc_result['batch'], 'first save' ] === array_values( $glc_meta ) && wp_is_uuid( $glc_result['batch'], 4 ), 'The entry carries the car, the actor, the kind, the source, a uuid batch and the note' );
$glc_check( 7 === count( $glc_result['pinged'] ) && str_contains( $glc_result['pinged'][1], '/ka/' ), 'A moved price range hands the car\'s seven locale URLs to IndexNow (which sends nothing outside production)' );

/* ------------------------------------------------------ stale, no-op, invalid */

$glc_snapshot = static function () use ( $glc_raw, $glc_a, $glc_b, $glc_entries ): string {
	$parts = [ $glc_entries() ];
	foreach ( [ $glc_a, $glc_b ] as $car ) {
		foreach ( [ GLC_Price_Store::META_PRICING, GLC_Price_Store::META_PREVIOUS, GLC_Price_Store::META_REV, GLC_Price_Store::META_DELIVERY, GLC_Price_Store::META_OUT_OF_HOURS ] as $key ) {
			$parts[] = $glc_raw( $car, $key );
		}
	}
	return md5( wp_json_encode( $parts ) );
};

$glc_state = $glc_snapshot();
$glc_other = $glc_table;
$glc_other[1]['rates']['d3_4'] = (int) $glc_table[1]['rates']['d3_4'] + 1;
$glc_result = GLC_Price_Store::write( $glc_a, [ 'pricing' => $glc_other ], [ 'source' => 'cli', 'rev' => 0 ] );
$glc_said   = $glc_errors( $glc_result, $glc_a )['err_stale'] ?? '';
$glc_check( '' !== $glc_said && $glc_snapshot() === $glc_state, 'A save from revision 0 after someone saved is refused; the database is unchanged' );
$glc_check( str_contains( $glc_said, 'Store Test Partner' ) && (bool) preg_match( '/\d{4}-\d{2}-\d{2} \d{2}:\d{2}/', $glc_said ), 'The refusal names who changed the car and when: ' . $glc_said );

$glc_result = GLC_Price_Store::write( $glc_a, [ 'pricing' => $glc_new ], [ 'source' => 'cli', 'rev' => 1 ] );
$glc_check( ! is_wp_error( $glc_result ) && [ $glc_a ] === $glc_result['unchanged'] && $glc_snapshot() === $glc_state, 'Saving the table that is already stored writes nothing' );

$glc_bad = $glc_table;
$glc_bad[3]['to'] = '02-28'; // leaves March uncovered if this season ended on 03-31
$glc_result = GLC_Price_Store::write_batch( [ $glc_a => [ 'changes' => [ 'pricing' => $glc_other ], 'rev' => 1 ], $glc_b => [ 'changes' => [ 'pricing' => $glc_bad ], 'rev' => 0 ] ], [ 'source' => 'cli' ] );
$glc_check( is_wp_error( $glc_result ) && $glc_errors( $glc_result, $glc_b ) && $glc_snapshot() === $glc_state, 'A batch with one invalid car writes no car: ' . implode( ' | ', $glc_errors( $glc_result, $glc_b ) ) );

/* ----------------------------------------- a database failure inside a batch */

$glc_seen  = 0;
$glc_break = static function ( $empty, $postarr ) use ( &$glc_seen ) {
	// Refuse the second change-log insert of the batch, after the first car is already written.
	return GLC_Change_Log::POST_TYPE === ( $postarr['post_type'] ?? '' ) && 2 === ++$glc_seen ? true : $empty;
};
add_filter( 'wp_insert_post_empty_content', $glc_break, 10, 2 );
$glc_result = GLC_Price_Store::write_batch( [ $glc_a => [ 'changes' => [ 'pricing' => $glc_other ], 'rev' => 1 ], $glc_b => [ 'changes' => [ 'pricing' => $glc_other ], 'rev' => 0 ] ], [ 'source' => 'cli' ] );
remove_filter( 'wp_insert_post_empty_content', $glc_break, 10 );
$glc_check( is_wp_error( $glc_result ) && 'glc_store_failed' === $glc_result->get_error_code() && 2 === $glc_seen, 'A failure on the second car is reported as a failed batch' );
$glc_check( $glc_snapshot() === $glc_state, 'The first car, already written when the second failed, is rolled back: the database is as it was' );
$glc_check( GLC_Price_Store::read_pricing( $glc_a ) === $glc_stored && 1 === GLC_Price_Store::rev( $glc_a ), 'This request\'s cache was dropped too: it reads the table that is really stored' );

/* ------------------------------------------------------------- a valid batch */

$glc_count  = $glc_entries();
$glc_result = GLC_Price_Store::write_batch(
	[
		$glc_a => [ 'changes' => [ 'pricing' => $glc_other, 'fees' => [ 'delivery' => [ 'kutaisi_airport' => '95.00', 'tbilisi_city' => '0', 'batumi_airport' => '' ], 'out_of_hours' => '25' ] ], 'rev' => 1 ],
		$glc_b => [ 'changes' => [ 'fees' => [ 'out_of_hours' => '0' ] ], 'rev' => 0 ],
	],
	[ 'source' => 'cli', 'note' => 'batch' ]
);
$glc_same_batch = is_wp_error( $glc_result ) ? 0 : (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %s", 'glc_batch', $glc_result['batch'] ) );
$glc_check( ! is_wp_error( $glc_result ) && $glc_entries() === $glc_count + 3 && 3 === $glc_same_batch, 'A valid batch writes both cars; its three entries share one batch id' );
$glc_check( '2' === $glc_raw( $glc_a, GLC_Price_Store::META_REV ) && '1' === $glc_raw( $glc_b, GLC_Price_Store::META_REV ), 'Each car moved one revision, whatever number of kinds changed on it' );
$glc_check( [ 'tbilisi_city' => '0', 'kutaisi_airport' => '95' ] === maybe_unserialize( $glc_raw( $glc_a, GLC_Price_Store::META_DELIVERY ) ) && '25' === $glc_raw( $glc_a, GLC_Price_Store::META_OUT_OF_HOURS ) && '0' === $glc_raw( $glc_b, GLC_Price_Store::META_OUT_OF_HOURS ) && null === $glc_raw( $glc_b, GLC_Price_Store::META_DELIVERY ), 'Fee overrides are stored as plain number strings; zero is kept as "free"; a blank is not stored' );
$glc_fee_entry = GLC_Change_Log::latest( $glc_a, [ 'fees' ] );
$glc_fee_type  = $glc_fee_entry ? $wpdb->get_var( $wpdb->prepare( "SELECT JSON_TYPE(meta_value) FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s", $glc_fee_entry['id'], 'glc_after' ) ) : '';
$glc_check( $glc_fee_entry && 'OBJECT' === $glc_fee_type && [ 'delivery' => [], 'out_of_hours' => '' ] === $glc_fee_entry['before'] && [ 'delivery' => [ 'tbilisi_city' => '0', 'kutaisi_airport' => '95' ], 'out_of_hours' => '25' ] === $glc_fee_entry['after'], 'The fee entry reads back with its before and after intact' );
$glc_check( 7 === count( $glc_result['pinged'] ), 'One ping for the batch, and only for the car whose price range moved: ' . count( $glc_result['pinged'] ) . ' URLs' );

/* ------------------------------------------------------------------ restore */

$glc_count  = $glc_entries();
$glc_result = GLC_Change_Log::restore( $glc_entry_id, [ 'rev' => 2 ] );
$glc_check( ! is_wp_error( $glc_result ) && $glc_raw( $glc_a, GLC_Price_Store::META_PRICING ) === $glc_raw_before, 'Restoring the first entry puts the table back byte for byte' );
$glc_last = GLC_Change_Log::latest( $glc_a, [ 'pricing' ] );
$glc_check( $glc_entries() === $glc_count + 1 && $glc_last && 'restore' === $glc_last['source'] && 'restore of #' . $glc_entry_id === $glc_last['note'] && $glc_last['after'] === $glc_table, 'The restore is a new entry (source restore, note names the entry); nothing in the log was overwritten' );
$glc_check( null !== GLC_Change_Log::get( $glc_entry_id ) && GLC_Change_Log::get( $glc_entry_id )['before'] === $glc_table, 'The entry that was restored from is still there, unchanged' );
$glc_fake = GLC_Change_Log::record( [ 'car_id' => $glc_a, 'actor' => $glc_owner, 'kind' => 'assignment', 'source' => 'panel', 'before' => [ 'partner' => 0 ], 'after' => [ 'partner' => $glc_partner ] ] );
$glc_check( is_int( $glc_fake ) && is_wp_error( GLC_Change_Log::restore( $glc_fake ) ) && is_wp_error( GLC_Change_Log::restore( 999999 ) ), 'A kind the store does not handle yet, and an entry that does not exist, are refused by restore' );
$glc_check( is_wp_error( GLC_Change_Log::record( [ 'car_id' => $glc_a, 'kind' => 'gossip', 'source' => 'panel' ] ) ) && is_wp_error( GLC_Change_Log::record( [ 'car_id' => $glc_a, 'kind' => 'pricing', 'source' => 'rumour' ] ) ), 'The log refuses a kind or a source it does not know' );

/* ----------------------------------------------------- nothing public by type */

$glc_type = get_post_type_object( GLC_Change_Log::POST_TYPE );
$glc_check( ! $glc_type->public && ! $glc_type->publicly_queryable && ! $glc_type->show_in_rest && $glc_type->exclude_from_search && ! in_array( GLC_Change_Log::POST_TYPE, get_post_types( [ 'public' => true ] ), true ), 'The change-log type is not public, not queryable, not in REST and not searchable' );
$glc_check( ! user_can( $glc_partner, $glc_type->cap->edit_posts ) && user_can( $glc_owner, $glc_type->cap->edit_posts ) && ! user_can( $glc_owner, 'edit_post', $glc_entry_id ) && ! user_can( $glc_owner, 'delete_post', $glc_entry_id ), 'The owner can list the log and nobody can edit or delete an entry; a partner cannot list it' );

WP_CLI::log( '' );
WP_CLI::log( $glc_failures ? "{$glc_failures} FAILED" : 'ALL PASS' );
WP_CLI::log( 'This run changed the local database. Put it back with the reset script.' );
if ( $glc_failures ) {
	WP_CLI::halt( 1 );
}
