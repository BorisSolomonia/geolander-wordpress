<?php
/**
 * Synchronize owner-confirmed facts that live in options and FAQ posts.
 *
 * Run with:
 *   wp eval-file /migration/sync-owner-facts.php
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit( "Run via wp eval-file\n" );
}

$settings = (array) get_option( 'glc_settings', [] );
$old_map  = 'https://maps.app.goo.gl/qxw1pBq3P3C9PZrj8';

// Do not overwrite an owner-supplied replacement, but migrate the former
// project default and blank values to the verified listing supplied by Boris.
if ( empty( $settings['google_maps_url'] ) || $old_map === $settings['google_maps_url'] ) {
	$settings['google_maps_url'] = 'https://maps.app.goo.gl/XuY47hmvdEau9HoS9';
}
$settings['office_district'] = 'Mtatsminda';
update_option( 'glc_settings', $settings );

$path = '/migration/faq.json';
$data = file_exists( $path ) ? json_decode( file_get_contents( $path ), true ) : [];
if ( empty( $data['mainEntity'] ) || ! is_array( $data['mainEntity'] ) ) {
	WP_CLI::error( 'faq.json has no mainEntity array.' );
}

$active = [];
foreach ( $data['mainEntity'] as $position => $qa ) {
	$question = trim( (string) ( $qa['name'] ?? '' ) );
	$answer   = trim( (string) ( $qa['acceptedAnswer']['text'] ?? '' ) );
	if ( '' === $question || '' === $answer ) {
		WP_CLI::error( 'Every FAQ needs both a question and an answer.' );
	}

	$legacy_id = 'faq-' . sanitize_title( $question );
	$active[]  = $legacy_id;
	$matches   = get_posts( [
		'post_type'      => 'faq',
		'post_status'    => 'any',
		'posts_per_page' => 1,
		'fields'         => 'ids',
		'meta_key'       => 'glc_legacy_id',
		'meta_value'     => $legacy_id,
	] );

	$id = wp_insert_post( [
		'ID'           => $matches ? (int) $matches[0] : 0,
		'post_type'    => 'faq',
		'post_status'  => 'publish',
		'post_title'   => $question,
		'post_content' => $answer,
		'menu_order'   => (int) $position,
		'meta_input'   => [ 'glc_legacy_id' => $legacy_id ],
	], true );
	if ( is_wp_error( $id ) ) {
		WP_CLI::error( $id->get_error_message() );
	}
}

// FAQ is a non-public support CPT. Retire stale importer-owned rows so an old
// answer cannot survive in visible blocks or FAQ schema after wording changes.
$imported = get_posts( [
	'post_type'      => 'faq',
	'post_status'    => 'any',
	'posts_per_page' => -1,
	'meta_key'       => 'glc_legacy_id',
] );
$retired = 0;
foreach ( $imported as $faq ) {
	$legacy_id = (string) get_post_meta( $faq->ID, 'glc_legacy_id', true );
	if ( str_starts_with( $legacy_id, 'faq-' ) && ! in_array( $legacy_id, $active, true ) ) {
		wp_update_post( [ 'ID' => $faq->ID, 'post_status' => 'draft' ] );
		++$retired;
	}
}

WP_CLI::success( sprintf(
	'Owner facts synchronized: Mtatsminda map setting, %d current FAQs, %d stale FAQ rows retired.',
	count( $active ),
	$retired
) );
