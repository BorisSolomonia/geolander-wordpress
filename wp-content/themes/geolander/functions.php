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
	// Version-keyed cache busting (no per-request filemtime stat — opcache runs
	// with validate_timestamps=0 in production, so assets only change on deploy).
	// Bump the theme's Version header in style.css whenever CSS/JS changes.
	$ver = wp_get_theme()->get( 'Version' ) ?: '1.0.0';
	wp_enqueue_style(
		'geolander-main',
		get_theme_file_uri( 'assets/css/main.css' ),
		[],
		$ver
	);
	wp_enqueue_script(
		'geolander-reveal',
		get_theme_file_uri( 'assets/js/reveal.js' ),
		[],
		$ver,
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

/**
 * Image sizes tuned for the fleet grid and galleries.
 *
 * WHY THERE ARE SMALL SIBLINGS FOR EACH CROP. WordPress builds a srcset only
 * from candidates that share the requested size's ASPECT RATIO. The originals
 * are 4:3, so WordPress's own medium/large/1536 sizes are 4:3 too; glc-card is
 * 3:2 and glc-hero is 16:9, and neither had a single same-ratio sibling. The
 * result, measured on 2026-10-01: wp_get_attachment_image_srcset() returned
 * false for both, the page shipped NO srcset at all, and a 360px phone was sent
 * the 1920x1080 hero — 353KB to paint about 12KB worth of pixels.
 *
 * Each crop below therefore comes as a ladder at one fixed ratio. Add a width
 * here and run _migration/regenerate-sizes.php; nothing else needs touching.
 */
const GLC_IMAGE_SIZES = [
	// name            w     h   crop   ratio
	'glc-hero'    => [ 1920, 1080 ], // 16:9
	'glc-hero-md' => [ 1280,  720 ],
	'glc-hero-sm' => [  960,  540 ],
	'glc-hero-xs' => [  640,  360 ],
	'glc-card'    => [  720,  480 ], // 3:2
	'glc-card-sm' => [  480,  320 ],
	'glc-card-xs' => [  360,  240 ],
];

add_action( 'after_setup_theme', function () {
	foreach ( GLC_IMAGE_SIZES as $glc_name => [ $glc_w, $glc_h ] ) {
		add_image_size( $glc_name, $glc_w, $glc_h, true );
	}
} );

/*
 * Tell the browser how wide the image will actually be, or it assumes 100vw and
 * picks the largest candidate anyway — a correct srcset with a wrong sizes
 * attribute saves nothing. The gallery's first tile spans two thirds of a
 * 1240px shell on desktop and the full width on a phone; the small tiles are a
 * third of it.
 */
add_filter( 'wp_calculate_image_sizes', function ( $sizes, $size ) {
	/*
	 * $size arrives as [width, height], NOT the size name — verified by logging it
	 * on 2026-10-01, after a first version keyed on the name silently did nothing
	 * and left WordPress's default "100vw up to 1920px" in place. That default is
	 * what makes a phone fetch a 960px image to fill 360 CSS pixels.
	 *
	 * So match on the aspect ratio, which is what actually distinguishes the two
	 * crops: the hero ladder is 16:9, the card ladder is 3:2.
	 */
	if ( ! is_array( $size ) || empty( $size[0] ) || empty( $size[1] ) ) {
		return $sizes;
	}
	$ratio = $size[0] / $size[1];
	if ( abs( $ratio - 16 / 9 ) < 0.02 ) {
		// Hero: full width on a phone, else two thirds of the 1240px shell.
		return '(max-width: 781px) 100vw, 820px';
	}
	if ( abs( $ratio - 3 / 2 ) < 0.02 ) {
		// Card: a snapped carousel item on a phone, a third of the grid on desktop.
		return '(max-width: 781px) 86vw, 400px';
	}
	return $sizes;
}, 10, 2 );

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
