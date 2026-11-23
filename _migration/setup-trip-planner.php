<?php
/** Add only the researched trip planner; preserve existing editorial content/status.
 * Run: wp eval-file /migration/setup-trip-planner.php --allow-root
 */
defined( 'ABSPATH' ) || exit( "Run via wp eval-file\n" );
$slug = GLC_Trip_Planner::SLUG;
$existing = get_page_by_path( $slug, OBJECT, 'page' );
if ( $existing ) {
	WP_CLI::success( 'Existing /' . $slug . '/ preserved (status: ' . $existing->post_status . ').' );
	return;
}
$english = require get_theme_file_path( 'inc/strings-en.php' );
$block = '<!-- wp:geolander/trip-planner /-->';
$id = wp_insert_post( [
	'post_type' => 'page', 'post_status' => 'publish', 'post_name' => $slug,
	'post_title' => $english['planner_title'], 'post_excerpt' => $english['planner_intro'], 'post_content' => $block,
], true );
if ( is_wp_error( $id ) ) { WP_CLI::error( $id->get_error_message() ); }
update_post_meta( $id, 'glc_seo_title_en', 'Georgia Road Trip Planner: When to Rent & Which Route' );
update_post_meta( $id, 'glc_seo_description_en', $english['planner_description'] );
foreach ( array_keys( GLC_I18n::LOCALES ) as $locale ) {
	if ( 'en' === $locale ) { continue; }
	$strings = require get_theme_file_path( 'inc/strings-' . $locale . '.php' );
	update_post_meta( $id, 'glc_title_' . $locale, $strings['planner_title'] );
	update_post_meta( $id, 'glc_body_' . $locale, $block );
}
WP_CLI::success( 'Created /' . $slug . '/ in all seven languages; fees come from current booking settings.' );
