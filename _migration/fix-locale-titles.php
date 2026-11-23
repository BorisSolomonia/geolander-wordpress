<?php
/**
 * Fix: Georgian <title> on the English locale for /blog/ and /music/.
 *
 * Run: docker compose run --rm cli eval-file /migration/fix-locale-titles.php
 * Idempotent.
 *
 * ROOT CAUSE (reproduced 2026-09-07): setup-pages.php created these two pages
 * with a Georgian string as the post_title. post_title is the English original
 * in this codebase — every other locale hangs off it via glc_title_{locale}
 * meta — so the x-default page rendered `<title>ბლოგი | Geolander</title>` to
 * Google and to every English visitor. Not a routing bug; wrong data in the
 * one field the routing trusts.
 *
 * This script moves the Georgian title to glc_title_ka, sets an English
 * post_title, and GLC_SEO::title() now resolves page titles through
 * GLC_Content::title() so each locale reads its own. The validator
 * (_migration/validate-schema.mjs, "x-default titles are English") fails the
 * build if the class ever recurs on any page.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit( "Run via wp eval-file\n" );
}

$fixes = [
	'blog'        => [ 'en' => 'Blog',           'ka' => 'ბლოგი' ],
	'music'       => [ 'en' => 'Georgian Music', 'ka' => 'ქართული მუსიკა' ],
	'travel-info' => [ 'en' => 'Travel Info',    'ka' => 'სამოგზაურო ინფორმაცია' ],
	'contact'     => [ 'en' => 'Contact',        'ka' => 'დაგვიკავშირდით' ], // <title> is overridden by glc_seo_title_en; the H1/breadcrumb read post_title
];

foreach ( $fixes as $slug => $titles ) {
	$page = get_page_by_path( $slug, OBJECT, 'page' );
	if ( ! $page instanceof WP_Post ) {
		WP_CLI::log( "  · /{$slug}/ not found — nothing to fix" );
		continue;
	}
	// Only touch a Georgian-script post_title; an owner-edited English one stays.
	if ( preg_match( '/[\x{10A0}-\x{10FF}]/u', $page->post_title ) ) {
		wp_update_post( [ 'ID' => $page->ID, 'post_title' => $titles['en'] ] );
		WP_CLI::log( "  ✓ /{$slug}/ post_title: “{$page->post_title}” → “{$titles['en']}”" );
	} else {
		WP_CLI::log( "  · /{$slug}/ post_title already Latin (“{$page->post_title}”)" );
	}
	if ( '' === trim( (string) get_post_meta( $page->ID, 'glc_title_ka', true ) ) ) {
		update_post_meta( $page->ID, 'glc_title_ka', $titles['ka'] );
		WP_CLI::log( "  ✓ /{$slug}/ glc_title_ka set" );
	}
}

WP_CLI::success( 'Locale titles fixed. Verify: curl -s https://geo-lander.com/blog/ | grep -o "<title>[^<]*"' );
