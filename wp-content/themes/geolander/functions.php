<?php
/**
 * Geolander block theme bootstrap.
 */

defined( 'ABSPATH' ) || exit;

add_action( 'after_setup_theme', function () {
	add_theme_support( 'editor-styles' );
	add_editor_style( 'assets/css/main.css' );
} );

add_action( 'wp_enqueue_scripts', function () {
	// filemtime as version: every edit busts browser caches immediately.
	wp_enqueue_style(
		'geolander-main',
		get_theme_file_uri( 'assets/css/main.css' ),
		[],
		(string) filemtime( get_theme_file_path( 'assets/css/main.css' ) )
	);
	wp_enqueue_script(
		'geolander-reveal',
		get_theme_file_uri( 'assets/js/reveal.js' ),
		[],
		(string) filemtime( get_theme_file_path( 'assets/js/reveal.js' ) ),
		[ 'strategy' => 'defer' ]
	);
} );

/**
 * Preload the Latin display font (likely LCP text); Georgian loads via
 * unicode-range on demand.
 */
add_action( 'wp_head', function () {
	printf(
		'<link rel="preload" href="%s" as="font" type="font/woff2" crossorigin>' . "\n",
		esc_url( get_theme_file_uri( 'assets/fonts/archivo-var.woff2' ) )
	);
}, 2 );

/** Cross-document view transitions (progressive enhancement). */
add_action( 'wp_head', function () {
	echo '<style>@view-transition { navigation: auto; }</style>' . "\n";
}, 3 );

/** Image sizes tuned for the fleet grid and galleries. */
add_action( 'after_setup_theme', function () {
	add_image_size( 'glc-card', 720, 480, true );
	add_image_size( 'glc-hero', 1920, 1080, true );
} );

/**
 * Front-end UI strings for templates and blocks, resolved per visitor
 * locale (GLC_I18n). English is the fallback catalog; vehicle content
 * itself stays English by design.
 */
function glc_t( string $key ): string {
	static $strings = null, $fallback = null;
	if ( null === $strings ) {
		$locale   = class_exists( 'GLC_I18n' ) ? GLC_I18n::locale() : 'en';
		$file     = get_theme_file_path( "inc/strings-{$locale}.php" );
		$strings  = file_exists( $file ) ? require $file : [];
		$fallback = require get_theme_file_path( 'inc/strings-en.php' );
	}
	return $strings[ $key ] ?? $fallback[ $key ] ?? $key;
}

/** Georgian string regardless of visitor locale (brand/signage layer). */
function glc_t_ka( string $key ): string {
	static $ka = null;
	if ( null === $ka ) {
		$ka = require get_theme_file_path( 'inc/strings-ka.php' );
	}
	return $ka[ $key ] ?? $key;
}

/**
 * Kilometer-post data chip above section titles: one mono line with a
 * true fact (fleet size, altitude range, response promise). The section
 * name itself lives in the localized heading right below.
 */
function glc_sign( string $key, string $alt = '' ): string {
	if ( ! $alt ) {
		$alt = glc_t( $key );
	}
	return sprintf(
		'<span class="glc-sign"><span class="glc-sign-alt">%s</span></span>',
		esc_html( $alt )
	);
}

/** Georgian registration plate — proof of the exact car. */
function glc_plate( string $registration, string $size = '1rem' ): string {
	if ( ! $registration ) {
		return '';
	}
	return sprintf(
		'<span class="glc-plate" style="font-size:%s" aria-label="Registration %s"><span class="glc-plate-band">GE</span><span class="glc-plate-num">%s</span></span>',
		esc_attr( $size ),
		esc_attr( $registration ),
		esc_html( strtoupper( $registration ) )
	);
}
