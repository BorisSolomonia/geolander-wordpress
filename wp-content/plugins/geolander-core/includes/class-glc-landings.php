<?php
/**
 * Stable custom URLs for a small number of curated SEO landing pages.
 *
 * The car post type owns /fleet/{car}/, so a normal nested WordPress page cannot
 * reliably claim /fleet/4x4-suv/. The page stays editable in wp-admin while this
 * class gives it one canonical public URL and redirects the default page slug.
 */

defined( 'ABSPATH' ) || exit;

class GLC_Landings {

	public static function init(): void {
		// Register before the city CPT's broad `car-rental-*` rule so the one
		// curated Kazbegi landing wins deterministically.
		add_action( 'init', [ __CLASS__, 'rewrites' ], 5 );
		add_filter( 'page_link', [ __CLASS__, 'page_link' ], 10, 2 );
		add_filter( 'redirect_canonical', [ __CLASS__, 'redirect_canonical' ] );
		add_action( 'template_redirect', [ __CLASS__, 'redirect_default_path' ], 1 );
	}

	public static function rewrites(): void {
		// The city CPT's broad ^car-rental-([^/]+) rule otherwise captures this
		// curated page as a nonexistent city named "kazbegi" and returns a 404.
		add_rewrite_rule( '^car-rental-kazbegi/?$', 'index.php?pagename=car-rental-kazbegi', 'top' );
		add_rewrite_rule( '^fleet/4x4-suv/?$', 'index.php?pagename=4x4-suv', 'top' );
	}

	public static function page_link( string $link, int $post_id ): string {
		$path = trim( (string) get_post_meta( $post_id, 'glc_custom_path', true ) );
		return $path ? home_url( '/' . trim( $path, '/' ) . '/' ) : $link;
	}

	public static function redirect_canonical( $redirect ) {
		return is_singular( 'page' ) && get_post_meta( get_queried_object_id(), 'glc_custom_path', true )
			? false
			: $redirect;
	}

	public static function redirect_default_path(): void {
		if ( ! is_singular( 'page' ) ) {
			return;
		}
		$path = trim( (string) get_post_meta( get_queried_object_id(), 'glc_custom_path', true ), '/' );
		if ( ! $path ) {
			return;
		}
		$current = trim( (string) wp_parse_url( $_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH ), '/' );
		if ( $current === $path ) {
			return;
		}
		wp_safe_redirect( get_permalink(), 301, 'Geolander' );
		exit;
	}
}
