<?php
/**
 * Assign a per-car WhatsApp number from a plate list.
 *
 *   wp eval-file /migration/set-car-whatsapp.php dry-run --allow-root
 *   wp eval-file /migration/set-car-whatsapp.php --allow-root
 *
 * Positional `dry-run` because wp-cli claims any `--flag` as its own.
 *
 * WHY PLATE AND NOT POST ID. Post IDs differ between the local stack and
 * production, and several plates carry DUPLICATE car posts on this site (a known
 * open item: the SEO manual keeps deduplication manual because merging posts is
 * destructive). Matching on the normalised plate sets every post for that
 * vehicle, so a visitor cannot reach a stale duplicate that still shows the old
 * number. Plates are compared with punctuation and case stripped: the same car
 * appears as "JZ602ZJ", "JZ-602-ZJ" and "DE-300-Jo" across records.
 *
 * Idempotent: running twice writes the same value and reports no change.
 */

defined( 'WP_CLI' ) || exit;

$dry = in_array( 'dry-run', (array) ( $args ?? [] ), true );

/** Edit here, not in code elsewhere. Empty string clears a car's override. */
$assignments = [
	'+995511286224' => [ 'GG581WG', 'JZ602ZJ', 'BC402CC', 'DE300JO', 'RZ117ZR' ],
];

$norm = static fn( string $p ): string => (string) preg_replace( '/[^A-Z0-9]/', '', strtoupper( $p ) );

$cars = get_posts( [ 'post_type' => 'car', 'posts_per_page' => -1, 'post_status' => 'any' ] );
if ( ! $cars ) {
	WP_CLI::error( 'No car posts found.' );
}

$index = [];
foreach ( $cars as $car ) {
	$plate = $norm( (string) get_post_meta( $car->ID, 'glc_registration', true ) );
	if ( '' !== $plate ) {
		$index[ $plate ][] = $car;
	}
}

$touched = 0;
$missing = [];
foreach ( $assignments as $number => $plates ) {
	foreach ( $plates as $plate ) {
		$key = $norm( $plate );
		if ( empty( $index[ $key ] ) ) {
			$missing[] = $plate;
			continue;
		}
		foreach ( $index[ $key ] as $car ) {
			$current = (string) get_post_meta( $car->ID, 'glc_whatsapp_number', true );
			$state   = $current === $number ? 'already set' : ( '' === $current ? 'was site default' : "was {$current}" );
			WP_CLI::log( sprintf(
				'%s%-6d %-12s %-8s %-40s %s -> %s',
				$dry ? '[dry-run] ' : '',
				$car->ID,
				(string) get_post_meta( $car->ID, 'glc_registration', true ),
				$car->post_status,
				mb_substr( $car->post_title, 0, 40 ),
				$state,
				$number
			) );
			if ( ! $dry && $current !== $number ) {
				update_post_meta( $car->ID, 'glc_whatsapp_number', $number );
				$touched++;
			}
		}
	}
}

if ( $missing ) {
	// Loud, not silent: a plate that matches nothing is almost always a typo or a
	// car that was never imported, and quietly skipping it is how a car keeps
	// answering on the wrong phone.
	WP_CLI::warning( 'No car matched these plates: ' . implode( ', ', $missing ) );
}

WP_CLI::success( $dry
	? 'Dry run complete. Nothing was written.'
	: sprintf( '%d car post(s) updated.', $touched ) );
