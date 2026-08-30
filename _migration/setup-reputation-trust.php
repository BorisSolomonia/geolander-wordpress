<?php
/**
 * Remove unverifiable seed proof and correct trust-page identity labels.
 *
 * Run: wp eval-file /migration/setup-reputation-trust.php
 * Idempotent. It never deletes a URL or invents a review, legal entity, or ID.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit( "Run via wp eval-file\n" );
}

$terms = get_page_by_path( 'terms' );
if ( $terms instanceof WP_Post && 'Terms and Conditions' !== $terms->post_title ) {
	wp_update_post( [
		'ID'         => $terms->ID,
		'post_title' => 'Terms and Conditions',
	] );
	WP_CLI::log( '  ✓ corrected /terms/ title' );
}

// These three records shipped as theme seed content with no external source.
// Keep them recoverable in wp-admin, but never present them as customer proof.
$seed_names = [ 'Marco & Elena', 'Sarah Johnson', 'Thomas Weber' ];
$seeds      = get_posts( [
	'post_type'      => 'testimonial',
	'post_status'    => 'any',
	'posts_per_page' => -1,
] );
foreach ( $seeds as $seed ) {
	if ( in_array( $seed->post_title, $seed_names, true ) && 'draft' !== $seed->post_status ) {
		wp_update_post( [
			'ID'          => $seed->ID,
			'post_status' => 'draft',
		] );
		WP_CLI::log( "  ✓ unpublished unverifiable seed testimonial: {$seed->post_title}" );
	}
}

WP_CLI::success( 'Reputation trust cleanup complete.' );
