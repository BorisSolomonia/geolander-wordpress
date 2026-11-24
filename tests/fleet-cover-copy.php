<?php
/** Archive SEO copy must stay independent of the visible illustrative-cover explanation. */
defined( 'ABSPATH' ) || define( 'ABSPATH', __DIR__ );
foreach ( [ 'en', 'ka', 'ru', 'uk', 'ar', 'zh', 'fr' ] as $locale ) {
	$strings = require __DIR__ . '/../wp-content/themes/geolander/inc/strings-' . $locale . '.php';
	if ( empty( $strings['fleet_subtitle'] ) || empty( $strings['fleet_studio_subtitle'] ) || $strings['fleet_subtitle'] === $strings['fleet_studio_subtitle'] ) {
		throw new RuntimeException( 'Missing independent SEO and cover copy: ' . $locale );
	}
	if ( 1 !== substr_count( $strings['studio_cover'] ?? '', '%s' ) ) {
		throw new RuntimeException( 'Missing translated cover alt format: ' . $locale );
	}
}
$seo = file_get_contents( __DIR__ . '/../wp-content/plugins/geolander-core/includes/class-glc-seo.php' );
if ( str_contains( $seo, 'fleet_studio_subtitle' ) ) {
	throw new RuntimeException( 'Illustrative cover copy must not replace archive SEO metadata' );
}
echo "PASS seven locale cover/SEO copy isolation\n";
