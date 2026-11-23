<?php
/**
 * Read-only fleet integrity audit.
 *
 * Run: docker compose run --rm cli eval-file /migration/audit-fleet.php
 *
 * This script CHANGES NOTHING. It exists because three sources disagreed about
 * how many cars Geolander has — /fleet/ showed 19, /llms.txt said 15 and
 * /pricing.md listed 8 — and because merging posts is destructive enough that a
 * human should decide which record survives.
 *
 * It reports:
 *   1. Published cars with no usable rate table  → these were publishing "$0/day"
 *   2. Likely duplicate records                  → same make/model/year, or the
 *                                                  same registration plate
 *   3. Cars with no registration plate           → cannot be matched to a
 *                                                  physical vehicle at all
 *   4. Cars with no photographs                  → the "real cars, real photos"
 *                                                  promise fails on these
 *   5. Cars with no unique description           → the thin-content problem
 *
 * Fix duplicates by 301-redirecting the loser to the survivor, never by
 * deleting: a deleted URL loses whatever equity and external links it had.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit( "Run via wp eval-file\n" );
}

$cars = get_posts( [
	'post_type'      => 'car',
	'posts_per_page' => -1,
	'post_status'    => 'any',
	'orderby'        => 'menu_order',
	'order'          => 'ASC',
] );

WP_CLI::log( '' );
WP_CLI::log( '=== Geolander fleet audit (read-only) ===' );
WP_CLI::log( sprintf( 'Car posts found: %d (published: %d)', count( $cars ), (int) ( wp_count_posts( 'car' )->publish ?? 0 ) ) );

$unpriced   = [];
$no_plate   = [];
$no_photo   = [];
$no_desc    = [];
$by_plate   = [];
$by_model   = [];

foreach ( $cars as $car ) {
	$id    = $car->ID;
	$plate = strtoupper( preg_replace( '/[^A-Z0-9]/i', '', (string) get_post_meta( $id, 'glc_registration', true ) ) );
	$year  = (string) get_post_meta( $id, 'glc_year', true );
	$model = strtolower( trim( preg_replace( '/\s+/', ' ', preg_replace( '/\d{4}|black|white|gray|grey|blue|green|silver|limited|sport/i', '', $car->post_title ) ) ) );

	if ( class_exists( 'GLC_Pricing' ) && ! GLC_Pricing::is_priced( $id ) ) {
		$unpriced[] = $car;
	}
	if ( ! $plate ) {
		$no_plate[] = $car;
	} else {
		$by_plate[ $plate ][] = $car;
	}
	if ( ! has_post_thumbnail( $id ) && ! get_post_meta( $id, 'glc_gallery', true ) ) {
		$no_photo[] = $car;
	}
	if ( strlen( wp_strip_all_tags( (string) $car->post_content ) ) < 200 ) {
		$no_desc[] = $car;
	}
	$by_model[ $model . '|' . $year ][] = $car;
}

$line = static function ( WP_Post $car ): string {
	return sprintf(
		'    #%d  %-42s  %-12s  %s',
		$car->ID,
		mb_strimwidth( $car->post_title, 0, 42, '…' ),
		get_post_meta( $car->ID, 'glc_registration', true ) ?: '(no plate)',
		get_permalink( $car )
	);
};

/* 1 — unpriced */
WP_CLI::log( '' );
WP_CLI::log( sprintf( '[1] Cars with no usable rate table: %d', count( $unpriced ) ) );
WP_CLI::log( '    These published "$0/day" before the price guards landed. Either add a' );
WP_CLI::log( '    rate table or set glc_available = false and unpublish them.' );
foreach ( $unpriced as $car ) {
	WP_CLI::log( $line( $car ) );
}

/* 2 — duplicate plates (definitive) and duplicate model+year (probable) */
$dupe_plates = array_filter( $by_plate, fn( $g ) => count( $g ) > 1 );
WP_CLI::log( '' );
WP_CLI::log( sprintf( '[2a] DUPLICATE registration plates: %d group(s) — these are certainly the same car', count( $dupe_plates ) ) );
foreach ( $dupe_plates as $plate => $group ) {
	WP_CLI::log( "    plate {$plate}:" );
	foreach ( $group as $car ) {
		WP_CLI::log( $line( $car ) );
	}
}

$dupe_models = array_filter( $by_model, fn( $g ) => count( $g ) > 1 );
WP_CLI::log( '' );
WP_CLI::log( sprintf( '[2b] Same make/model/year: %d group(s) — check plates before assuming duplication', count( $dupe_models ) ) );
WP_CLI::log( '     (Geolander genuinely owns several Foresters, so this list is a prompt, not a verdict.)' );
foreach ( $dupe_models as $key => $group ) {
	WP_CLI::log( '    ' . $key . ':' );
	foreach ( $group as $car ) {
		WP_CLI::log( $line( $car ) );
	}
}

/* 3–5 — completeness */
foreach ( [
	[ '[3] No registration plate', $no_plate, 'Cannot be matched to a physical car. The plate is the dedupe key.' ],
	[ '[4] No photographs', $no_photo, 'The homepage promises "real cars, real photos". These break that promise.' ],
	[ '[5] Under 200 characters of body content', $no_desc, 'Thin, near-duplicate pages. Publish ground clearance, odometer, service date, tyre age.' ],
] as [ $label, $group, $note ] ) {
	WP_CLI::log( '' );
	WP_CLI::log( sprintf( '%s: %d', $label, count( $group ) ) );
	WP_CLI::log( '    ' . $note );
	foreach ( $group as $car ) {
		WP_CLI::log( $line( $car ) );
	}
}

WP_CLI::log( '' );
WP_CLI::log( '=== Nothing was changed. Decide the survivors, then 301 the losers. ===' );
WP_CLI::log( '' );
