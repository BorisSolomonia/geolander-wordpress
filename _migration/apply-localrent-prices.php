<?php
/**
 * Replace each car's seasonal price table from a Localrent supplier export.
 *
 * Run: docker compose --env-file .env.host -f compose.host.yml exec -T wordpress \
 *        wp eval-file /migration/apply-localrent-prices.php --allow-root
 * Dry run (prints the diff, writes nothing) — note `dry-run` is a POSITIONAL
 * argument, because wp-cli reads anything dash-prefixed as one of its own flags:
 *      … wp eval-file /migration/apply-localrent-prices.php dry-run --allow-root
 *
 * Input: /migration/localrent-prices.json, produced on the workstation by
 * `bun SEO/tools/apply-localrent-pricing.ts export`. Shape:
 *   { "factor": 0.98, "captured": "2026-09-14", "cars": [
 *       { "post_id": 4, "plate": "NN-545-KN", "seasons": [ {label, from, to, rates{…7 tiers}}, … ] }, … ] }
 *
 * WHY A FILE AND NOT A SHELL LOOP: the price tables are JSON containing double
 * quotes; passing them through `wp post meta update` on a command line means
 * three levels of shell quoting, and a single mis-escape writes a corrupt price
 * table to a live car. The file is read by PHP, validated, and only then saved.
 *
 * SAFETY, in order of application:
 *  - Every car is validated BEFORE anything is written. One bad entry aborts the
 *    whole run, so the fleet is never left half-updated.
 *  - The previous value is printed and stored in `glc_pricing_previous` on each
 *    post, so one line of wp-cli restores it (see the footer this prints).
 *  - A rate of zero or below, a missing tier, and a season table that does not
 *    cover all 365 days are all hard failures. The site must never publish a
 *    zero (SEO-AGENT-MANUAL §0.3) and must never quote a price for a day it has
 *    no rate for.
 *  - An implausible rate (over MAX_PLAUSIBLE_RATE) is a hard failure too: the
 *    supplier uses a very high number as a "do not book" block-out, and copying
 *    that to the public site would publish a false price.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit( "Run via wp eval-file\n" );
}

const GLC_TIERS              = [ 'd1_2', 'd3_4', 'd5_7', 'd8_12', 'd13_18', 'd19_30', 'd31p' ];
const MAX_PLAUSIBLE_RATE     = 300;   // USD/day. The fleet's real ceiling is ~$130.
const MIN_PLAUSIBLE_RATE     = 5;
const GLC_PRICES_INPUT       = '/migration/localrent-prices.json';

$glc_dry_run = in_array( 'dry-run', (array) ( $args ?? [] ), true );

if ( ! file_exists( GLC_PRICES_INPUT ) ) {
	WP_CLI::error( 'Missing ' . GLC_PRICES_INPUT . ' — generate it with `bun SEO/tools/apply-localrent-pricing.ts export` and copy it into _migration/.' );
}

$glc_payload = json_decode( (string) file_get_contents( GLC_PRICES_INPUT ), true );
if ( ! is_array( $glc_payload ) || empty( $glc_payload['cars'] ) ) {
	WP_CLI::error( 'Input file is not a readable price payload.' );
}

WP_CLI::log( sprintf(
	'Localrent export captured %s, factor %s, %d cars.%s',
	$glc_payload['captured'] ?? 'unknown date',
	$glc_payload['factor'] ?? '?',
	count( $glc_payload['cars'] ),
	$glc_dry_run ? '  DRY RUN — nothing will be written.' : ''
) );

/** Every day of a non-leap year must be covered by exactly one season. */
function glc_season_days( array $seasons ): array {
	$hits = [];
	foreach ( $seasons as $season ) {
		$from = $season['from'];
		$to   = $season['to'];
		$wrap = $from > $to; // e.g. Dec 25 – Jan 05
		foreach ( range( 1, 12 ) as $m ) {
			$days = (int) gmdate( 't', gmmktime( 0, 0, 0, $m, 1, 2026 ) );
			foreach ( range( 1, $days ) as $d ) {
				$md = sprintf( '%02d-%02d', $m, $d );
				$in = $wrap ? ( $md >= $from || $md <= $to ) : ( $md >= $from && $md <= $to );
				if ( $in ) {
					$hits[ $md ] = ( $hits[ $md ] ?? 0 ) + 1;
				}
			}
		}
	}
	return $hits;
}

/* ------------------------------------------------------------ validate all */

$glc_planned = [];
$glc_errors  = [];

foreach ( $glc_payload['cars'] as $car ) {
	$id    = (int) ( $car['post_id'] ?? 0 );
	$plate = (string) ( $car['plate'] ?? '?' );
	$post  = $id ? get_post( $id ) : null;

	if ( ! $post || 'car' !== $post->post_type ) {
		$glc_errors[] = "{$plate}: post {$id} is not a car";
		continue;
	}
	$stored_plate = (string) get_post_meta( $id, 'glc_registration', true );
	$norm         = fn( string $p ): string => strtoupper( preg_replace( '/[^A-Za-z0-9]/', '', $p ) );
	if ( $norm( $stored_plate ) !== $norm( $plate ) ) {
		$glc_errors[] = "{$plate}: post {$id} ({$post->post_title}) carries registration '{$stored_plate}' — refusing to price the wrong car";
		continue;
	}

	$seasons = $car['seasons'] ?? [];
	if ( count( $seasons ) < 1 ) {
		$glc_errors[] = "{$plate}: no seasons";
		continue;
	}
	foreach ( $seasons as $i => $season ) {
		foreach ( [ 'label', 'from', 'to', 'rates' ] as $key ) {
			if ( ! isset( $season[ $key ] ) ) {
				$glc_errors[] = "{$plate}: season " . ( $i + 1 ) . " missing '{$key}'";
				continue 2;
			}
		}
		if ( ! preg_match( '/^\d{2}-\d{2}$/', $season['from'] ) || ! preg_match( '/^\d{2}-\d{2}$/', $season['to'] ) ) {
			$glc_errors[] = "{$plate}: season " . ( $i + 1 ) . ' has a malformed MM-DD range';
			continue;
		}
		foreach ( GLC_TIERS as $tier ) {
			$rate = $season['rates'][ $tier ] ?? null;
			if ( ! is_numeric( $rate ) ) {
				$glc_errors[] = "{$plate}: season " . ( $i + 1 ) . " missing tier {$tier}";
			} elseif ( $rate < MIN_PLAUSIBLE_RATE ) {
				$glc_errors[] = "{$plate}: season " . ( $i + 1 ) . " tier {$tier} is \${$rate} — a zero or near-zero price is never published";
			} elseif ( $rate > MAX_PLAUSIBLE_RATE ) {
				$glc_errors[] = "{$plate}: season " . ( $i + 1 ) . " tier {$tier} is \${$rate}, above the \$" . MAX_PLAUSIBLE_RATE . ' plausibility ceiling — this is a supplier block-out, not a rate';
			}
		}
	}

	$coverage = glc_season_days( $seasons );
	$gaps     = 365 - count( $coverage );
	$overlaps = count( array_filter( $coverage, fn( $n ) => $n > 1 ) );
	if ( $gaps > 0 ) {
		$glc_errors[] = "{$plate}: seasons leave {$gaps} day(s) of the year unpriced";
	}
	if ( $overlaps > 0 ) {
		// Not fatal: GLC_Pricing::season_for_date takes the first match, which is
		// deterministic. Worth saying out loud so a real overlap gets noticed.
		WP_CLI::warning( "{$plate}: {$overlaps} day(s) fall in more than one season; the first listed season wins" );
	}

	$glc_planned[] = [ 'id' => $id, 'plate' => $plate, 'title' => $post->post_title, 'seasons' => $seasons ];
}

if ( $glc_errors ) {
	foreach ( $glc_errors as $e ) {
		WP_CLI::log( '  ✗ ' . $e );
	}
	WP_CLI::error( count( $glc_errors ) . ' validation failure(s). Nothing was written.' );
}

/* --------------------------------------------------------------- apply all */

$glc_changed = 0;
foreach ( $glc_planned as $car ) {
	$old = get_post_meta( $car['id'], 'glc_pricing', true );
	$new = $car['seasons'];

	$peak_old = '—';
	if ( is_array( $old ) ) {
		foreach ( $old as $s ) {
			$peak_old = (string) ( ( $s['rates']['d1_2'] ?? $s['prices']['days1To2'] ?? null ) ?? '—' );
			break;
		}
	}
	$peak_new = (string) ( $new[0]['rates']['d1_2'] ?? '—' );

	WP_CLI::log( sprintf(
		'  %s %-11s %-34s seasons %d → %d · first-season 1–2 day $%s → $%s',
		$glc_dry_run ? '·' : '✓',
		$car['plate'],
		$car['title'],
		is_array( $old ) ? count( $old ) : 0,
		count( $new ),
		$peak_old,
		$peak_new
	) );

	if ( $glc_dry_run ) {
		continue;
	}
	if ( is_array( $old ) && $old ) {
		update_post_meta( $car['id'], 'glc_pricing_previous', $old );
	}
	update_post_meta( $car['id'], 'glc_pricing', $new );
	update_post_meta( $car['id'], 'glc_pricing_source', sprintf( 'Localrent %s × %s', $glc_payload['captured'] ?? '', $glc_payload['factor'] ?? '' ) );
	$glc_changed++;
}

if ( $glc_dry_run ) {
	WP_CLI::success( 'Dry run complete. ' . count( $glc_planned ) . ' car(s) would be updated.' );
	return;
}

WP_CLI::success( $glc_changed . ' car(s) repriced. Previous tables are on each post as `glc_pricing_previous`.' );
WP_CLI::log( 'Roll one car back with:  wp post meta update <id> glc_pricing "$(wp post meta get <id> glc_pricing_previous --format=json --allow-root)" --format=json --allow-root' );
