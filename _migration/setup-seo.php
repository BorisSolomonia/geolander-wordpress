<?php
/**
 * SEO pass setup: privacy policy page (Google Ads requirement),
 * image compression for the theme's hero/CTA/route art, rewrite flush.
 * Run: wp eval-file /migration/setup-seo.php
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit( "Run via wp eval-file\n" );
}

/* Privacy policy — required by Google Ads for sites collecting user data. */
$privacy = get_page_by_path( 'privacy-policy' );
$content = ''
	. '<!-- wp:paragraph --><p>Last updated: ' . current_datetime()->format( 'F j, Y' ) . '</p><!-- /wp:paragraph -->'
	. '<!-- wp:paragraph --><p>Geolander ("we") operates geo-lander.com. This policy explains what we collect and why.</p><!-- /wp:paragraph -->'
	. '<!-- wp:heading --><h2 class="wp-block-heading">What we collect</h2><!-- /wp:heading -->'
	. '<!-- wp:list --><ul class="wp-block-list">'
	. '<!-- wp:list-item --><li><strong>Booking requests:</strong> the name you optionally enter, your chosen car and dates, and a booking reference. Used solely to prepare and confirm your rental.</li><!-- /wp:list-item -->'
	. '<!-- wp:list-item --><li><strong>Language preference:</strong> a cookie (glc_lang) remembering your chosen language for 180 days.</li><!-- /wp:list-item -->'
	. '<!-- wp:list-item --><li><strong>Analytics and advertising:</strong> when enabled, Google Analytics and Google Ads tags measure visits and booking requests. See Google\'s privacy policy for how Google processes this data. You can opt out via browser settings or Google\'s Ads Settings.</li><!-- /wp:list-item -->'
	. '</ul><!-- /wp:list -->'
	. '<!-- wp:heading --><h2 class="wp-block-heading">What we do not do</h2><!-- /wp:heading -->'
	. '<!-- wp:list --><ul class="wp-block-list">'
	. '<!-- wp:list-item --><li>We do not sell personal data.</li><!-- /wp:list-item -->'
	. '<!-- wp:list-item --><li>We do not require an account or store payment card data on this site.</li><!-- /wp:list-item -->'
	. '</ul><!-- /wp:list -->'
	. '<!-- wp:heading --><h2 class="wp-block-heading">Messaging</h2><!-- /wp:heading -->'
	. '<!-- wp:paragraph --><p>Bookings are confirmed via WhatsApp. Once you contact us there, WhatsApp\'s own terms and privacy policy apply to that conversation.</p><!-- /wp:paragraph -->'
	. '<!-- wp:heading --><h2 class="wp-block-heading">Contact</h2><!-- /wp:heading -->'
	. '<!-- wp:paragraph --><p>Questions or deletion requests: info@geo-lander.com or +995 551 33 04 14. Address: 8/5 Vedzini Street, Tbilisi 0108, Georgia.</p><!-- /wp:paragraph -->';

$privacy_id = wp_insert_post( [
	'ID'           => $privacy->ID ?? 0,
	'post_type'    => 'page',
	'post_status'  => 'publish',
	'post_name'    => 'privacy-policy',
	'post_title'   => 'Privacy Policy',
	'post_content' => $content,
] );
update_option( 'wp_page_for_privacy_policy', $privacy_id );
WP_CLI::log( 'Privacy policy page: /privacy-policy/' );

/* Compress oversized theme images in place (JPEG q80, max 1920w). */
$theme_img = get_theme_file_path( 'assets/img' );
$targets   = array_merge(
	glob( $theme_img . '/*.jpg' ) ?: [],
	glob( $theme_img . '/routes/*.jpg' ) ?: []
);
foreach ( $targets as $file ) {
	$before = filesize( $file );
	if ( $before < 150 * 1024 ) {
		continue;
	}
	$editor = wp_get_image_editor( $file );
	if ( is_wp_error( $editor ) ) {
		WP_CLI::warning( basename( $file ) . ': ' . $editor->get_error_message() );
		continue;
	}
	$size = $editor->get_size();
	if ( $size['width'] > 1920 ) {
		$editor->resize( 1920, null );
	}
	$editor->set_quality( 80 );
	$saved = $editor->save( $file );
	if ( ! is_wp_error( $saved ) ) {
		clearstatcache();
		WP_CLI::log( sprintf( '  %s: %dKB -> %dKB', basename( $file ), $before / 1024, filesize( $file ) / 1024 ) );
	}
}

flush_rewrite_rules();
WP_CLI::success( 'SEO setup done (rewrites flushed).' );
