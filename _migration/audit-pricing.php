<?php
/**
 * Read-only audit of every car's season price table.
 *
 * Run: docker compose --env-file .env.host -f compose.host.yml exec -T wordpress \
 *        wp eval-file /migration/audit-pricing.php --allow-root
 *
 * This script CHANGES NOTHING. It answers one question before partners are
 * given a screen to edit prices on: would the price store accept each car's
 * table as it is stored today? A car that fails here still gets the same quotes
 * as before, but its table could not be saved unchanged, and after the quote
 * engine stops filling holes a day with no season can no longer be booked online.
 *
 * Per car it reports, as PROBLEMS:
 *   1. days of the year that no season covers                      (a gap)
 *   2. days that more than one season covers                       (an overlap;
 *      the quote engine takes the first season listed)
 *   3. a rental length with no price, or a price that is not a number
 *   4. a price outside the limits in Settings → Geolander
 * and as NOTES, which block nothing:
 *   - a stored season label that differs from the one the store would generate
 *     (a save would change the label printed in /pricing.md)
 *   - 29 February covered by no season (tables are checked as a 365-day year)
 *   - a car with no table at all (shown with its headline price, or as
 *     "price on request" when it has none; no dated quote either way)
 *
 * Gaps and overlaps are counted twice, by different code: the store's own
 * validator, and a walk of 365 dates through the quote engine's season lookup.
 * The two totals are printed side by side; if they differ, that is a problem too.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit( "Run via wp eval-file\n" );
}

$glc_cars = get_posts( [
	'post_type'      => 'car',
	'posts_per_page' => -1,
	'post_status'    => 'any',
	'orderby'        => 'ID',
	'order'          => 'ASC',
] );

WP_CLI::log( '' );
WP_CLI::log( '=== Geolander price table audit (read-only) ===' );
WP_CLI::log( sprintf(
	'Cars: %d. Limits in force: day price %s to %s, at most %s seasons.',
	count( $glc_cars ),
	'' === (string) GLC_Settings::get( 'rate_min_plausible' ) ? 'no lower limit' : '$' . GLC_Settings::get( 'rate_min_plausible' ),
	'' === (string) GLC_Settings::get( 'rate_max_plausible' ) ? 'no upper limit' : '$' . GLC_Settings::get( 'rate_max_plausible' ),
	'' === (string) GLC_Settings::get( 'season_max_count' ) ? 'any number of' : GLC_Settings::get( 'season_max_count' )
) );
WP_CLI::log( '' );

/** Second check: how many of the 365 days the quote engine finds no season for, and how many it finds more than one for. */
$glc_engine_walk = static function ( array $seasons ): array {
	$uncovered = 0;
	$shared    = 0;
	$day       = new DateTimeImmutable( '2001-01-01' ); // any non-leap year: only month and day are read
	for ( $i = 0; $i < 365; $i++ ) {
		$hits = 0;
		foreach ( $seasons as $season ) {
			if ( GLC_Pricing::season_for_date( [ $season ], $day ) ) {
				$hits++;
			}
		}
		$uncovered += 0 === $hits ? 1 : 0;
		$shared    += $hits > 1 ? 1 : 0;
		$day        = $day->modify( '+1 day' );
	}
	return [ $uncovered, $shared ];
};

$glc_problems  = 0;
$glc_problem_cars = 0;
$glc_notes     = 0;
$glc_by_kind   = [ 'gap' => 0, 'overlap' => 0, 'missing or non-numeric price' => 0, 'price outside the limits' => 0, 'date' => 0, 'season count' => 0, 'checks disagree' => 0 ];

foreach ( $glc_cars as $glc_car ) {
	$glc_id     = (int) $glc_car->ID;
	$glc_stored = get_post_meta( $glc_id, 'glc_pricing', true );
	$glc_head   = sprintf( '#%-4d %-11s %-34s %s', $glc_id, get_post_meta( $glc_id, 'glc_registration', true ) ?: '(no plate)', mb_strimwidth( $glc_car->post_title, 0, 34, '…' ), $glc_car->post_status );

	if ( ! is_array( $glc_stored ) || ! $glc_stored ) {
		WP_CLI::log( '  · ' . $glc_head . '  no season table' );
		[ $glc_headline ] = GLC_Pricing::rate_range( $glc_id );
		WP_CLI::log( '        note: no prices stored; ' . ( $glc_headline > 0
			? sprintf( 'pages show the headline price $%s only, and no dated quote can be given', $glc_headline )
			: 'the site shows this car as "price on request"' ) );
		$glc_notes++;
		continue;
	}

	$glc_seasons = GLC_Pricing::normalize( $glc_stored );
	$glc_errors  = GLC_Price_Store::validate_pricing( $glc_stored );
	$glc_found   = [];
	$glc_store_gap_days     = 0;
	$glc_store_overlap_days = 0;
	$glc_dates_ok           = true;
	foreach ( $glc_errors as $glc_error ) {
		switch ( $glc_error['code'] ) {
			case 'err_gap':
				$glc_store_gap_days += $glc_error['days'];
				$glc_by_kind['gap']++;
				break;
			case 'err_overlap':
				$glc_store_overlap_days += $glc_error['days'];
				$glc_by_kind['overlap']++;
				break;
			case 'err_rate_bounds':
				// One code, two findings the brief keeps apart: nothing usable in the cell, or a number outside the limits.
				$glc_value = $glc_stored[ $glc_error['season'] ]['rates'][ $glc_error['band'] ] ?? null;
				$glc_by_kind[ is_numeric( $glc_value ) && (float) $glc_value > 0 && round( (float) $glc_value, 2 ) === (float) $glc_value ? 'price outside the limits' : 'missing or non-numeric price' ]++;
				break;
			case 'err_season_date':
				$glc_dates_ok = false;
				$glc_by_kind['date']++;
				break;
			case 'err_season_count':
				$glc_by_kind['season count']++;
				break;
		}
		$glc_found[] = $glc_error['message'];
	}

	[ $glc_walk_gap_days, $glc_walk_overlap_days ] = $glc_engine_walk( $glc_seasons );
	// The store only counts coverage once every season has two real dates; the walk counts regardless.
	if ( $glc_dates_ok && ( $glc_walk_gap_days !== $glc_store_gap_days || $glc_walk_overlap_days !== $glc_store_overlap_days ) ) {
		$glc_found[] = sprintf( 'THE TWO CHECKS DISAGREE: the store counts %d uncovered and %d shared day(s), the quote engine %d and %d', $glc_store_gap_days, $glc_store_overlap_days, $glc_walk_gap_days, $glc_walk_overlap_days );
		$glc_by_kind['checks disagree']++;
	}

	$glc_prices = 0;
	foreach ( $glc_seasons as $glc_season ) {
		$glc_prices += count( array_filter( (array) $glc_season['rates'], static fn( $rate ): bool => is_numeric( $rate ) && (float) $rate > 0 ) );
	}
	[ $glc_low, $glc_high ] = GLC_Pricing::rate_range( $glc_id );
	WP_CLI::log( sprintf(
		'  %s %s  %d seasons, %d prices, $%s to $%s · uncovered days %d/%d · shared days %d/%d (store/engine)',
		$glc_found ? '✗' : '✓',
		$glc_head,
		count( $glc_seasons ),
		$glc_prices,
		$glc_low,
		$glc_high,
		$glc_store_gap_days,
		$glc_walk_gap_days,
		$glc_store_overlap_days,
		$glc_walk_overlap_days
	) );
	foreach ( $glc_found as $glc_message ) {
		WP_CLI::log( '        problem: ' . $glc_message );
	}
	if ( $glc_found ) {
		$glc_problems += count( $glc_found );
		$glc_problem_cars++;
	}

	foreach ( $glc_seasons as $glc_n => $glc_season ) {
		if ( preg_match( '/^\d{2}-\d{2}$/', $glc_season['from'] ) && preg_match( '/^\d{2}-\d{2}$/', $glc_season['to'] )
			&& GLC_Price_Store::season_label( $glc_season['from'], $glc_season['to'] ) !== $glc_season['label'] ) {
			WP_CLI::log( sprintf( '        note: season %d is labelled "%s"; a save would relabel it "%s"', $glc_n + 1, $glc_season['label'], GLC_Price_Store::season_label( $glc_season['from'], $glc_season['to'] ) ) );
			$glc_notes++;
		}
	}
	if ( ! GLC_Pricing::season_for_date( $glc_seasons, new DateTimeImmutable( '2004-02-29' ) ) ) { // any leap year
		WP_CLI::log( '        note: no season covers 29 February; a rental over that day in a leap year has no rate for it' );
		$glc_notes++;
	}
}

WP_CLI::log( '' );
foreach ( $glc_by_kind as $glc_kind => $glc_count ) {
	WP_CLI::log( sprintf( '  %-30s %d', $glc_kind . ':', $glc_count ) );
}
WP_CLI::log( '' );
WP_CLI::log( sprintf(
	'=== Nothing was changed. %d car(s) checked: %d problem(s) in %d car(s), %d note(s). ===',
	count( $glc_cars ),
	$glc_problems,
	$glc_problem_cars,
	$glc_notes
) );
WP_CLI::log( '' );
