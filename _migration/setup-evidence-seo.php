<?php
/** Add the airport decision tool. Safe to rerun: existing editorial work is untouched.
 * wp eval-file /migration/setup-evidence-seo.php
 * No credentials, invented fleet facts, outreach or external submissions in this script.
 */
defined( 'ABSPATH' ) || exit( "Run via wp eval-file\n" );
$slug = 'georgia-airport-rental-costs';
$existing = get_page_by_path( $slug, OBJECT, 'page' );
if ( $existing ) {
	WP_CLI::log( 'Existing /' . $slug . '/ preserved (status: ' . $existing->post_status . ').' );
} else {
	$english = require get_theme_file_path( 'inc/strings-en.php' );
	$id = wp_insert_post( [
		'post_type' => 'page', 'post_status' => 'publish', 'post_name' => $slug,
		'post_title' => $english['arrival_title'],
		'post_excerpt' => $english['arrival_intro'],
		'post_content' => '<!-- wp:geolander/arrival-costs /-->',
	], true );
	if ( is_wp_error( $id ) ) { WP_CLI::error( $id->get_error_message() ); }
	update_post_meta( $id, 'glc_seo_title_en', 'Georgia Airport Rental Delivery Costs: Tbilisi, Kutaisi, Batumi' );
	update_post_meta( $id, 'glc_seo_description_en', 'Compare Geolander car delivery charges at Tbilisi, Kutaisi and Batumi airports. Check one-way and return fees, then ask the right questions before booking.' );
	foreach ( array_keys( GLC_I18n::LOCALES ) as $locale ) {
		if ( 'en' === $locale ) { continue; }
		$strings = require get_theme_file_path( 'inc/strings-' . $locale . '.php' );
		update_post_meta( $id, 'glc_title_' . $locale, $strings['arrival_title'] );
		// The block translates its complete visible content, not just navigation.
		update_post_meta( $id, 'glc_body_' . $locale, '<!-- wp:geolander/arrival-costs /-->' );
	}
	WP_CLI::success( 'Created /' . $slug . '/ with live fees, seven-language content and links from rental-facts blocks.' );
}
