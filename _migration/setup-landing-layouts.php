<?php
/**
 * Three starting layouts for landing pages, as WordPress patterns.
 *
 *   wp eval-file /migration/setup-landing-layouts.php --allow-root
 *
 * A layout here is an ordinary WordPress pattern (Appearance → Patterns), which
 * is why this is a one-off seeding script and not code: once these exist, the
 * person who writes the pages can change them, copy them and add more of them
 * in wp-admin, with no deploy and nobody's help. The landing-page box offers
 * every pattern the site has, so a new one appears in the list by existing.
 *
 * RE-RUNNABLE. A pattern is found by its slug. One that is already there is left
 * exactly as it is — including any change made to it in wp-admin, which is the
 * whole point — and is never rewritten by running this again. Nothing is
 * deleted, here or ever, by this script.
 *
 * THE PROSE IS DELIBERATELY NOT WRITTEN. Each layout opens with a NEEDS: line,
 * the convention this project already uses for a draft that is waiting on a
 * fact only the owner has. A layout that shipped with invented sentences about
 * deposits or insurance would put them on a live page the first time somebody
 * chose it, and that is exactly the failure docs/seo/SEO-AGENT-MANUAL.md warns
 * about. The blocks below carry no numbers at all: every figure they show is
 * read live from the settings and the fleet.
 */

defined( 'WP_CLI' ) || exit;

$needs = static fn( string $what ): string =>
	'<!-- wp:paragraph --><p><strong>NEEDS:</strong> ' . $what . '</p><!-- /wp:paragraph -->';

$heading = static fn( string $text ): string =>
	'<!-- wp:heading {"level":2} --><h2 class="wp-block-heading">' . $text . '</h2><!-- /wp:heading -->';

$layouts = [
	'glc-landing-place' => [
		'title'   => 'Landing — a place or an airport',
		'content' => implode( '', [
			$needs( 'one paragraph that answers the phrase this page targets, in the words someone would search for. Say where the car is handed over and what the drive is like.' ),
			$heading( 'Cars for this trip' ),
			'<!-- wp:geolander/fleet-grid {"count":6} /-->',
			$heading( 'What a rental includes' ),
			'<!-- wp:geolander/rental-facts /-->',
			$heading( 'What people ask' ),
			'<!-- wp:geolander/faq-list /-->',
			'<!-- wp:geolander/testimonials /-->',
		] ),
	],
	'glc-landing-vehicle' => [
		'title'   => 'Landing — a kind of vehicle',
		'content' => implode( '', [
			$needs( 'one paragraph on who this kind of car is for and what it can do that the others cannot. No prices in prose: the blocks below read the real rates.' ),
			$heading( 'The cars' ),
			'<!-- wp:geolander/fleet-grid /-->',
			$heading( 'Longer rentals' ),
			'<!-- wp:geolander/long-term-rates /-->',
			$heading( 'What a rental includes' ),
			'<!-- wp:geolander/rental-facts /-->',
			$heading( 'What people ask' ),
			'<!-- wp:geolander/faq-list /-->',
		] ),
	],
	'glc-landing-route' => [
		'title'   => 'Landing — a route or a region',
		'content' => implode( '', [
			$needs( 'one paragraph on the route: how long it takes, what the road is like, and when it is open. Road and season facts come from the owner or they are not published.' ),
			$heading( 'Where this road goes' ),
			'<!-- wp:geolander/places-grid /-->',
			$heading( 'Cars that can do it' ),
			'<!-- wp:geolander/fleet-grid {"count":3} /-->',
			$heading( 'What people ask' ),
			'<!-- wp:geolander/faq-list /-->',
		] ),
	],
];

$made = 0;
$kept = 0;
foreach ( $layouts as $slug => $layout ) {
	$existing = get_posts( [
		'post_type'        => 'wp_block',
		'post_status'      => [ 'publish', 'draft', 'trash' ],
		'name'             => $slug,
		'posts_per_page'   => 1,
		'no_found_rows'    => true,
		'suppress_filters' => false,
	] );
	if ( $existing ) {
		++$kept;
		WP_CLI::log( sprintf( '  = %s (#%d) is already there; left exactly as it is', $slug, (int) $existing[0]->ID ) );
		continue;
	}
	$id = wp_insert_post( [
		'post_type'    => 'wp_block',
		'post_status'  => 'publish',
		'post_title'   => $layout['title'],
		'post_name'    => $slug,
		'post_content' => $layout['content'],
	], true );
	if ( is_wp_error( $id ) ) {
		WP_CLI::warning( sprintf( '%s: %s', $slug, $id->get_error_message() ) );
		continue;
	}
	++$made;
	WP_CLI::log( sprintf( '  + %s (#%d) %s', $slug, (int) $id, $layout['title'] ) );
}

WP_CLI::success( sprintf( '%d layout(s) created, %d already there. They are patterns now: edit them under Appearance → Patterns.', $made, $kept ) );
