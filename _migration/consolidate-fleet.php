<?php
/**
 * Consolidate verified duplicate fleet URLs without deleting any URL.
 *
 * Run: wp eval-file /migration/consolidate-fleet.php
 * Idempotent: canonical records remain published; duplicate records become
 * drafts and their former paths are retained as permanent redirects.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit( "Run via wp eval-file\n" );
}

$groups = [
	'subaru-forester-2016-blue' => [ 'subaru-forester-2016-uu108ul' ],
	'subaru-forester-2018-black' => [ 'subaru-forester-2017-bc-402-cc' ],
	'subaru-forester-2018-green' => [ 'subaru-forester-2017-jz602zj' ],
	'subaru-forester-2019-gray' => [ 'subaru-forester-2019-tt902ft' ],
	'subaru-forester-2023-black' => [ 'subaru-forester-2023-wx-356-wx' ],
	'mitsubishi-outlander-2016-gray' => [ 'mitsubishi-outlander-2016-qy075qy' ],
	'mitsubishi-outlander-2018-black' => [
		'mitsubishi-outlander-2018-qy-045-qy',
		'mitsubishi-outlander-2018-gray',
	],
	'toyota-rav4-2016-limited' => [ 'toyota-rav4-hybrid-awd-2016-gg581wg', 'toyota-rav4-2016-gg581wg' ],
];

$redirects = (array) get_option( 'glc_fleet_redirects', [] );
foreach ( $groups as $canonical_slug => $duplicate_slugs ) {
	$canonical = get_page_by_path( $canonical_slug, OBJECT, 'car' );
	if ( ! $canonical ) {
		WP_CLI::warning( "Canonical fleet record missing: {$canonical_slug}" );
		continue;
	}

	if ( 'subaru-forester-2019-gray' === $canonical_slug ) {
		// The plate is visible in its supplied photograph; its old sidecar copied
		// the 2023 Forester plate by mistake.
		update_post_meta( $canonical->ID, 'glc_registration', 'TT-902-FT' );
	}

	foreach ( $duplicate_slugs as $duplicate_slug ) {
		$redirects[ 'fleet/' . $duplicate_slug ] = 'fleet/' . $canonical_slug;
		$duplicate = get_page_by_path( $duplicate_slug, OBJECT, 'car' );
		if ( $duplicate && (int) $duplicate->ID !== (int) $canonical->ID && 'draft' !== $duplicate->post_status ) {
			wp_update_post( [ 'ID' => $duplicate->ID, 'post_status' => 'draft' ] );
			WP_CLI::log( "  drafted duplicate /fleet/{$duplicate_slug}/" );
		}
	}
}

ksort( $redirects );
update_option( 'glc_fleet_redirects', $redirects, false );
WP_CLI::success( count( $redirects ) . ' fleet URL redirects retained.' );
