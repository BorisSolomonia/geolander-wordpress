<?php
/**
 * Create content pages: Terms, Contact, Travel Info, Music, Blog.
 * Run: wp eval-file /migration/setup-pages.php
 * Idempotent by slug.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit( "Run via wp eval-file\n" );
}

function glc_upsert_page( string $slug, string $title, string $content, string $template = '' ): int {
	$existing = get_page_by_path( $slug );
	$id       = wp_insert_post( [
		'ID'           => $existing->ID ?? 0,
		'post_type'    => 'page',
		'post_status'  => 'publish',
		'post_name'    => $slug,
		'post_title'   => $title,
		'post_content' => $content,
	] );
	if ( $template && ! is_wp_error( $id ) ) {
		update_post_meta( $id, '_wp_page_template', $template );
	}
	WP_CLI::log( "  ✓ page /{$slug}/" );
	return (int) $id;
}

function glc_p( string $text ): string {
	return "<!-- wp:paragraph --><p>{$text}</p><!-- /wp:paragraph -->";
}
function glc_h( string $text, int $level = 2 ): string {
	return "<!-- wp:heading {\"level\":{$level}} --><h{$level} class=\"wp-block-heading\">{$text}</h{$level}><!-- /wp:heading -->";
}
function glc_ul( array $items ): string {
	$li = implode( '', array_map( fn( $i ) => "<!-- wp:list-item --><li>{$i}</li><!-- /wp:list-item -->", $items ) );
	return "<!-- wp:list --><ul class=\"wp-block-list\">{$li}</ul><!-- /wp:list -->";
}

/* ------------------------------------------------------------- Terms */

$terms = implode( '', [
	glc_h( '1. Rental Requirements' ),
	glc_ul( [
		'Minimum age: 21 years',
		'Valid driving license (international license recommended for non-Georgian licenses)',
		'Valid passport or national ID',
		'Credit or debit card for security deposit',
	] ),
	glc_h( '2. Insurance' ),
	glc_p( 'Basic insurance (CDW — Collision Damage Waiver) is included in all rental prices. This covers third-party liability and collision damage with a deductible. Additional full coverage insurance is available for an extra fee.' ),
	glc_h( '3. Fuel Policy' ),
	glc_p( 'Vehicles are provided with a full tank of fuel and must be returned with a full tank. If the vehicle is returned with less fuel, a refueling charge will apply.' ),
	glc_h( '4. Mileage' ),
	glc_p( 'All rentals include unlimited mileage within Georgia.' ),
	glc_h( '5. Cancellation Policy' ),
	glc_ul( [
		'Free cancellation up to 24 hours before pickup time',
		'Cancellations within 24 hours: one day rental charge applies',
		'No-show: full rental period charge applies',
	] ),
	glc_h( '6. Cross-Border Travel' ),
	glc_p( 'Travel outside Georgia requires prior written approval and may incur additional insurance fees. Please contact us at least 48 hours before your intended departure to arrange necessary documentation.' ),
	glc_h( '7. Vehicle Return' ),
	glc_ul( [
		'Vehicles must be returned to the agreed location at the agreed time',
		'Late returns may incur additional charges',
		'The vehicle must be returned in the same condition as received',
	] ),
	glc_h( '8. Prohibited Uses' ),
	glc_ul( [
		'Off-road driving (unless vehicle is specifically approved)',
		'Driving under the influence of alcohol or drugs',
		'Sub-renting or lending the vehicle to unauthorized drivers',
		'Participation in races or speed tests',
	] ),
	glc_h( '9. Liability' ),
	glc_p( 'The renter is responsible for any traffic violations, parking fines, or toll charges incurred during the rental period. The renter is liable for damage exceeding the insurance deductible.' ),
	glc_h( '10. Contact' ),
	glc_p( 'For any questions about these terms, please contact us via WhatsApp or email at info@geo-lander.com.' ),
] );
glc_upsert_page( 'terms', 'წესები და პირობები', $terms );

/* ----------------------------------------------------------- Travel Info */

$fuel    = json_decode( file_get_contents( '/migration/fuel-stations.json' ), true ) ?: [];
$markets = json_decode( file_get_contents( '/migration/markets.json' ), true ) ?: [];

$travel = glc_p( 'Everything you need to know about driving in Georgia — from fuel stations to supermarkets along your route.' );
$travel .= glc_h( 'Fuel Stations — ბენზინგასამართი სადგურები' );
foreach ( $fuel as $station ) {
	$travel .= glc_h( $station['name'], 3 );
	$travel .= glc_p( $station['descriptionEn'] . ( $station['website'] ? " <a href=\"{$station['website']}\" rel=\"noopener\">{$station['website']}</a>" : '' ) );
}
$travel .= glc_h( 'Supermarkets — სუპერმარკეტები' );
foreach ( $markets as $market ) {
	$level   = [ 'budget' => 'Budget', 'mid-range' => 'Mid-range', 'premium' => 'Premium' ][ $market['priceLevel'] ?? '' ] ?? '';
	$travel .= glc_h( $market['name'] . ( $level ? " — {$level}" : '' ), 3 );
	$travel .= glc_p( $market['descriptionEn'] );
}
$travel .= glc_h( 'Driving in Georgia — quick facts' );
$travel .= glc_ul( [
	'Georgia drives on the right; major road signs use Georgian and Latin script',
	'Speed limits: 60 km/h in cities, 90 km/h outside, 110 km/h on highways',
	'Zero tolerance for drink-driving (0.03% limit)',
	'Mountain roads (Kazbegi, Svaneti, Tusheti) can require 4x4 — all Geolander vehicles are mountain-ready',
	'Fuel is widely available on main routes; fill up before remote mountain sections',
] );
glc_upsert_page( 'travel-info', 'სამოგზაურო ინფორმაცია', $travel );

/* ---------------------------------------------------------------- Music */

$genres = json_decode( file_get_contents( '/migration/music-genres.json' ), true ) ?: [];
$music  = glc_p( 'Discover Georgia\'s rich musical heritage for your road trip — from UNESCO-listed polyphonic singing to modern Georgian rock.' );
foreach ( $genres as $genre ) {
	$music .= glc_h( $genre['nameEn'] . ' — ' . $genre['nameKa'], 2 );
	$music .= glc_p( $genre['descriptionEn'] );
}
glc_upsert_page( 'music', 'ქართული მუსიკა', $music );

/* -------------------------------------------------------------- Contact */

glc_upsert_page( 'contact', 'დაგვიკავშირდით', '', 'page-contact' );

/* ----------------------------------------------------------------- Blog */

$blog_query = '<!-- wp:query {"query":{"perPage":12,"postType":"post","order":"desc","orderBy":"date","inherit":false}} --><div class="wp-block-query">'
	. '<!-- wp:post-template {"layout":{"type":"grid","columnCount":2}} -->'
	. '<!-- wp:post-featured-image {"isLink":true,"aspectRatio":"16/9"} /-->'
	. '<!-- wp:post-title {"isLink":true,"level":2,"fontSize":"large"} /-->'
	. '<!-- wp:post-excerpt {"moreText":"წაიკითხე მეტი →"} /-->'
	. '<!-- /wp:post-template -->'
	. '<!-- wp:query-no-results --><!-- wp:paragraph --><p>სტატიები მალე დაემატება.</p><!-- /wp:paragraph --><!-- /wp:query-no-results -->'
	. '</div><!-- /wp:query -->';
glc_upsert_page( 'blog', 'ბლოგი', $blog_query );

WP_CLI::success( 'Pages created.' );
