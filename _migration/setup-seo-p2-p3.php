<?php
/**
 * Create the remaining code-backed P2/P3 SEO pages.
 *
 * Run: wp eval-file /migration/setup-seo-p2-p3.php
 * Then: wp rewrite flush
 *
 * Pages are idempotent by slug. Fact-complete pages publish; pages that still
 * require owner or insurer detail remain drafts with visible NEEDS markers.
 */

defined( 'ABSPATH' ) || exit;

function glc_backlog_upsert( array $page ): int {
	$existing = get_page_by_path( $page['slug'], OBJECT, 'page' );
	if ( $existing instanceof WP_Post && 'publish' === $existing->post_status && 'draft' === $page['status'] ) {
		WP_CLI::warning( "/{$page['slug']}/ is already published; left unchanged rather than removing a live URL." );
		return (int) $existing->ID;
	}
	$id = wp_insert_post( [
		'ID'           => $existing->ID ?? 0,
		'post_type'    => 'page',
		'post_status'  => $page['status'],
		'post_name'    => $page['slug'],
		'post_title'   => $page['title'],
		'post_excerpt' => $page['description'],
		'post_content' => $page['content'],
	], true );
	if ( is_wp_error( $id ) ) {
		throw new RuntimeException( $id->get_error_message() );
	}
	update_post_meta( $id, 'glc_seo_title_en', $page['seo_title'] );
	update_post_meta( $id, 'glc_seo_description_en', $page['description'] );
	if ( ! empty( $page['guide_route'] ) ) {
		update_post_meta( $id, 'glc_guide_route', $page['guide_route'] );
	} else {
		delete_post_meta( $id, 'glc_guide_route' );
	}
	if ( ! empty( $page['custom_path'] ) ) {
		update_post_meta( $id, 'glc_custom_path', $page['custom_path'] );
	} else {
		delete_post_meta( $id, 'glc_custom_path' );
	}
	WP_CLI::log( sprintf( '  ✓ /%s/ (%s)', trim( $page['custom_path'] ?? $page['slug'], '/' ), $page['status'] ) );
	return (int) $id;
}

function glc_backlog_needs( string $text ): string {
	return '<!-- wp:paragraph --><p><mark><strong>NEEDS:</strong> ' . esc_html( $text ) . '</mark></p><!-- /wp:paragraph -->';
}

$pages = [
	[
		'slug'        => 'car-rental-kazbegi',
		'status'      => 'publish',
		'title'       => 'Car Rental for Kazbegi from Tbilisi',
		'seo_title'   => 'Car Rental for Kazbegi from Tbilisi',
		'description' => 'Rent an exact car for Kazbegi from central Tbilisi. No deposit, included insurance, free winter tyres, real photos, and clear route conditions.',
		'content'     => <<<'HTML'
<!-- wp:paragraph {"fontSize":"large"} -->
<p class="has-large-font-size"><strong>Rent an exact car for Kazbegi from Geolander's office in the heart of Tbilisi, in Mtatsminda at 8/5 Vedzini Street.</strong> The Tbilisi–Stepantsminda route is permitted for Geolander vehicles with insurance unless bad weather, an official closure, or road damage makes the road unsafe or unavailable.</p>
<!-- /wp:paragraph -->

<!-- wp:heading --><h2 class="wp-block-heading">Is the road to Kazbegi allowed?</h2><!-- /wp:heading -->
<!-- wp:paragraph --><p>Yes. Geolander permits all its vehicles on the planned route and insurance continues. Rental permission never overrides police or Roads Department restrictions: do not proceed during bad weather, a closure, or damaged-road conditions. Check the live road status before leaving Tbilisi and again around Gudauri.</p><!-- /wp:paragraph -->

<!-- wp:heading --><h2 class="wp-block-heading">Do you need a 4x4 for Kazbegi?</h2><!-- /wp:heading -->
<!-- wp:paragraph --><p>Not automatically. The main Georgian Military Highway to Stepantsminda is a surfaced road in normal conditions. AWD or 4x4 becomes more useful when winter traction, luggage, side-road surfaces, or a changing forecast are part of the trip. Four driven wheels do not shorten braking distance on ice, so tyres and careful driving matter more than a badge.</p><!-- /wp:paragraph -->
<!-- wp:paragraph --><p>Read the detailed <a href="/driving-to-kazbegi-in-winter/">Tbilisi-to-Kazbegi winter driving guide</a> before choosing a vehicle.</p><!-- /wp:paragraph -->

<!-- wp:heading --><h2 class="wp-block-heading">What your Kazbegi rental includes</h2><!-- /wp:heading -->
<!-- wp:list --><ul class="wp-block-list"><li>No security deposit, cash deposit, card charge, or card preauthorization hold.</li><li>Full insurance with no deductible.</li><li>Third-party liability cover up to 30,000 GEL.</li><li>Single-vehicle accidents covered.</li><li>Winter tyres supplied free of charge.</li><li>Free delivery at Tbilisi International Airport (TBS).</li><li>The exact car and real photographs, not an anonymous “or similar” category.</li></ul><!-- /wp:list -->

<!-- wp:heading --><h2 class="wp-block-heading">Choose an exact car for your dates</h2><!-- /wp:heading -->
<!-- wp:paragraph --><p>Compare the current vehicles and their real seasonal prices below. Tell Geolander your dates, group size, luggage, and any side valleys you plan to visit so the team can recommend clearance and space without pretending every Kazbegi trip has the same needs.</p><!-- /wp:paragraph -->
<!-- wp:geolander/fleet-grid {"count":6} /-->

<!-- wp:buttons --><div class="wp-block-buttons"><!-- wp:button --><div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="/fleet/">View the full rental fleet</a></div><!-- /wp:button --><!-- wp:button {"className":"is-style-outline"} --><div class="wp-block-button is-style-outline"><a class="wp-block-button__link wp-element-button" href="/contact/">Ask about current Kazbegi conditions</a></div><!-- /wp:button --></div><!-- /wp:buttons -->
HTML,
	],
	[
		'slug'        => '4x4-suv',
		'status'      => 'publish',
		'custom_path' => '/fleet/4x4-suv/',
		'title'       => '4x4 SUV Rental in Tbilisi',
		'seo_title'   => '4x4 SUV Rental in Tbilisi, Georgia',
		'description' => 'Compare exact 4x4 and AWD rentals in Tbilisi with real photos, seasonal pricing, no deposit, included insurance, and free winter tyres.',
		'content'     => <<<'HTML'
<!-- wp:paragraph {"fontSize":"large"} --><p class="has-large-font-size"><strong>Geolander rents exact 4x4 and AWD vehicles from its office in Mtatsminda, in the heart of Tbilisi.</strong> Every listing shows the actual car and its photographs, so your choice is based on the vehicle you will receive rather than an “or similar” promise.</p><!-- /wp:paragraph -->

<!-- wp:heading --><h2 class="wp-block-heading">Choose for the road, not the label</h2><!-- /wp:heading -->
<!-- wp:paragraph --><p>You do not need a 4x4 for every road in Georgia. Main highways and city routes are normally suitable for ordinary cars. AWD, 4x4, and additional clearance become useful for rough surfaces, winter conditions, mountain extensions, and itineraries where the road can deteriorate quickly.</p><!-- /wp:paragraph -->
<!-- wp:paragraph --><p>Geolander permits all its vehicles on planned routes with insurance. Bad weather, official closures, and damaged-road conditions override that permission. For route-specific context, compare the guides for <a href="/driving-to-kazbegi-in-winter/">Kazbegi</a>, <a href="/svaneti-4x4-road-trip-guide/">Svaneti</a>, and <a href="/tusheti-4x4-rental-guide/">Tusheti</a>.</p><!-- /wp:paragraph -->

<!-- wp:heading --><h2 class="wp-block-heading">Current 4x4 and AWD rentals</h2><!-- /wp:heading -->
<!-- wp:geolander/fleet-grid /-->

<!-- wp:heading --><h2 class="wp-block-heading">Clear rental terms</h2><!-- /wp:heading -->
<!-- wp:list --><ul class="wp-block-list"><li>No security deposit or card preauthorization hold.</li><li>Full insurance with no deductible.</li><li>Third-party liability cover limited to 30,000 GEL.</li><li>Free winter tyres.</li><li>Free Tbilisi Airport delivery.</li><li>Direct confirmation through WhatsApp.</li></ul><!-- /wp:list -->

<!-- wp:buttons --><div class="wp-block-buttons"><!-- wp:button --><div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="/contact/">Check availability for your route</a></div><!-- /wp:button --></div><!-- /wp:buttons -->
HTML,
	],
	[
		'slug'        => 'mountain-road-opening-calendar',
		'status'      => 'draft',
		'title'       => 'Georgia Mountain Road Opening Calendar',
		'seo_title'   => 'Georgia Mountain Road Opening Calendar',
		'description' => 'A condition-led reference for Georgia mountain-road openings, closures, and official status sources.',
		'guide_route' => 'Georgia mountain road opening calendar',
		'content'     => '<!-- wp:paragraph --><p><strong>Geolander imposes no seasonal route ban of its own.</strong> Bad weather, official road closures, and damaged-road conditions still control whether a road may be used.</p><!-- /wp:paragraph -->'
			. glc_backlog_needs( 'For every road: an owner-approved typical opening range, the exact official status source, and a dated last-reviewed value. Do not publish a generic month range as a live opening guarantee.' )
			. '<!-- wp:heading --><h2 class="wp-block-heading">Roads to document</h2><!-- /wp:heading -->'
			. '<!-- wp:list --><ul class="wp-block-list"><li>Abano Pass and Omalo</li><li>Shatili and Upper Khevsureti</li><li>Mestia–Ushguli–Lentekhi</li><li>Goderdzi Pass</li><li>Zekari Pass</li><li>Sairme–Abastumani</li><li>Gudauri–Kobi and the Kazbegi approach</li></ul><!-- /wp:list -->'
			. '<!-- wp:paragraph --><p>Until the table is verified, use the <a href="https://www.georoad.ge/?act=news&amp;lang=eng&amp;pid=1388404621" target="_blank" rel="noopener">Roads Department restrictions feed</a> and ask locally immediately before departure.</p><!-- /wp:paragraph -->',
	],
	[
		'slug'        => 'rent-a-car-or-hire-a-driver',
		'status'      => 'publish',
		'title'       => 'Rent a Car or Hire a Driver in Georgia?',
		'seo_title'   => 'Rent a Car or Hire a Driver in Georgia?',
		'description' => 'An honest comparison of self-drive car rental and private drivers in Georgia, including mountain roads, wine routes, cost structure, and flexibility.',
		'guide_route' => 'Self-drive or private driver in Georgia',
		'content'     => <<<'HTML'
<!-- wp:paragraph {"fontSize":"large"} --><p class="has-large-font-size"><strong>Rent a car when independence is the point of the trip; hire a driver when the road, fatigue, or planned drinking would make self-drive a burden.</strong> Georgia rewards flexible road trips, but there are days when handing the wheel to a local is the better travel decision.</p><!-- /wp:paragraph -->

<!-- wp:heading --><h2 class="wp-block-heading">When renting a car wins</h2><!-- /wp:heading -->
<!-- wp:list --><ul class="wp-block-list"><li>You want to change the route or stop without negotiating a new itinerary.</li><li>You are travelling for several days with luggage.</li><li>You want early starts, quiet detours, or accommodation outside town centres.</li><li>The driver is comfortable with the road type and will not be drinking.</li></ul><!-- /wp:list -->

<!-- wp:heading --><h2 class="wp-block-heading">When a private driver wins</h2><!-- /wp:heading -->
<!-- wp:list --><ul class="wp-block-list"><li>You are arriving after a tiring overnight flight and face a long transfer.</li><li>The route involves exposed mountain roads outside your experience.</li><li>The day is built around wine tasting.</li><li>You want a one-way transfer without keeping a car parked.</li><li>Bad weather makes concentrating on the road more important than sightseeing.</li></ul><!-- /wp:list -->

<!-- wp:paragraph --><p><a href="https://gotrip.ge/en/" target="_blank" rel="noopener">GoTrip</a> is one established option for comparing private-driver transfers and tours around Georgia. It is independent of Geolander; the link is included because a driver is genuinely the better answer for some itineraries.</p><!-- /wp:paragraph -->

<!-- wp:heading --><h2 class="wp-block-heading">The useful hybrid plan</h2><!-- /wp:heading -->
<!-- wp:paragraph --><p>Rent for the flexible part of the itinerary, then use a local driver for the one mountain section you do not want to drive. Tusheti is the clearest example: an experienced driver can make sense for the Abano Pass while a rental remains useful for Kakheti and the rest of Georgia.</p><!-- /wp:paragraph -->

<!-- wp:buttons --><div class="wp-block-buttons"><!-- wp:button --><div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="/fleet/4x4-suv/">Compare self-drive vehicles</a></div><!-- /wp:button --><!-- wp:button {"className":"is-style-outline"} --><div class="wp-block-button is-style-outline"><a class="wp-block-button__link wp-element-button" href="/contact/">Discuss your itinerary</a></div><!-- /wp:button --></div><!-- /wp:buttons -->
HTML,
	],
	[
		'slug'        => 'driving-in-georgia',
		'status'      => 'publish',
		'title'       => 'Driving in Georgia: An Honest Self-Drive Guide',
		'seo_title'   => 'Driving in Georgia: Honest Self-Drive Guide',
		'description' => 'What tourists should expect when driving in Georgia: road surfaces, mountain weather, local traffic, closures, emergency help, and vehicle choice.',
		'guide_route' => 'Driving throughout Georgia',
		'content'     => <<<'HTML'
<!-- wp:paragraph {"fontSize":"large"} --><p class="has-large-font-size"><strong>Driving in Georgia is practical for an alert, confident visitor, but the road can change character quickly.</strong> A surfaced highway can lead to construction, livestock, fog, snow, rockfall, or a rough village approach, so plan around the exact route rather than the country as a single road type.</p><!-- /wp:paragraph -->

<!-- wp:heading --><h2 class="wp-block-heading">What feels different</h2><!-- /wp:heading -->
<!-- wp:list --><ul class="wp-block-list"><li>Georgia drives on the right.</li><li>Expect assertive overtaking and vehicles crossing the centre line; do not copy them.</li><li>Livestock, pedestrians, slow trucks, and roadside stops can appear with little warning.</li><li>Mountain visibility and grip may be completely different from conditions in Tbilisi.</li><li>Navigation apps show a route, not whether it is currently open or undamaged.</li></ul><!-- /wp:list -->

<!-- wp:heading --><h2 class="wp-block-heading">Do you need a 4x4?</h2><!-- /wp:heading -->
<!-- wp:paragraph --><p>No blanket answer is honest. Cities and many main highways do not require 4x4 in normal conditions. Rough side roads, high mountain passes, snow, mud, clearance, and luggage can change the decision. Start with the route, current conditions, and driver experience; then choose the vehicle.</p><!-- /wp:paragraph -->

<!-- wp:heading --><h2 class="wp-block-heading">Closures and emergencies</h2><!-- /wp:heading -->
<!-- wp:paragraph --><p>Check the <a href="https://www.georoad.ge/?act=news&amp;lang=eng&amp;pid=1388404621" target="_blank" rel="noopener">official Roads Department restrictions</a> before mountain travel. If police or road authorities close a road, wait rather than searching for an unofficial detour. Georgia's unified emergency number is <a href="https://police.ge/en/lepl/lepl112" target="_blank" rel="noopener">112</a>.</p><!-- /wp:paragraph -->

<!-- wp:heading --><h2 class="wp-block-heading">Geolander route and insurance policy</h2><!-- /wp:heading -->
<!-- wp:paragraph --><p>All Geolander vehicles are permitted on planned routes and insurance continues unless bad weather, an official closure, or road damage makes the route unsafe or unavailable. Insurance is also excluded for wrong-lane driving, running a red light, speeding, or failing to tell Geolander where an incident occurred.</p><!-- /wp:paragraph -->

<!-- wp:buttons --><div class="wp-block-buttons"><!-- wp:button --><div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="/guides/">Read the route guides</a></div><!-- /wp:button --><!-- wp:button {"className":"is-style-outline"} --><div class="wp-block-button is-style-outline"><a class="wp-block-button__link wp-element-button" href="/fleet/">Choose an exact car</a></div><!-- /wp:button --></div><!-- /wp:buttons -->
HTML,
	],
	[
		'slug'        => 'driving-to-armenia',
		'status'      => 'draft',
		'title'       => 'Driving a Geolander Rental Car to Armenia',
		'seo_title'   => 'Driving a Rental Car from Georgia to Armenia',
		'description' => 'What Geolander renters need to arrange before crossing from Georgia into Armenia by rental car.',
		'guide_route' => 'Georgia to Armenia by rental car',
		'content'     => '<!-- wp:paragraph --><p><strong>Geolander allows its rental cars to travel to Armenia.</strong> This owner-confirmed permission is not yet enough to publish a border guide because the operational process is still incomplete.</p><!-- /wp:paragraph -->'
			. glc_backlog_needs( 'Eligible vehicles, advance-notice period, exact documents, power of attorney, cross-border insurance, fees, border points, rental-duration rules, and what support applies inside Armenia.' )
			. '<!-- wp:heading --><h2 class="wp-block-heading">Before this guide goes live</h2><!-- /wp:heading -->'
			. '<!-- wp:list --><ul class="wp-block-list"><li>Confirm the renter and vehicle documents checked at the border.</li><li>Confirm whether insurance must be purchased separately for Armenia.</li><li>Confirm every business fee in GEL or state that there is none.</li><li>Confirm which vehicles and border crossings are eligible.</li><li>Write the breakdown and accident procedure outside Georgia.</li></ul><!-- /wp:list -->',
	],
	[
		'slug'        => 'driving-in-georgia-in-winter',
		'status'      => 'publish',
		'title'       => 'Driving in Georgia in Winter',
		'seo_title'   => 'Driving in Georgia in Winter: Car & Road Guide',
		'description' => 'Plan winter driving in Georgia with free winter tyres, honest 4x4 advice, road-status checks, mountain safety, and exact-car rental from Tbilisi.',
		'guide_route' => 'Winter driving throughout Georgia',
		'content'     => <<<'HTML'
<!-- wp:paragraph {"fontSize":"large"} --><p class="has-large-font-size"><strong>Winter driving in Georgia is a route-and-weather decision, not simply a 4x4 decision.</strong> Geolander supplies winter tyres free of charge, but tyres, visibility, speed, and official road restrictions still matter more than the drivetrain badge.</p><!-- /wp:paragraph -->

<!-- wp:heading --><h2 class="wp-block-heading">What winter changes</h2><!-- /wp:heading -->
<!-- wp:list --><ul class="wp-block-list"><li>Tbilisi can be clear while Gudauri, Kobi, Goderdzi, or another high section is restricted.</li><li>Black ice and packed snow increase stopping distance even in AWD or 4x4.</li><li>Short daylight makes unfamiliar mountain driving less forgiving.</li><li>Snow, avalanche control, wind, rockfall, and roadworks can create temporary closures.</li></ul><!-- /wp:list -->

<!-- wp:heading --><h2 class="wp-block-heading">Choose the car after choosing the route</h2><!-- /wp:heading -->
<!-- wp:paragraph --><p>A normal surfaced itinerary may not require a traditional 4x4. AWD can add useful traction on snowy inclines, while higher clearance matters on rough or snow-built-up approaches. Neither replaces winter tyres or a conservative decision to wait when conditions deteriorate.</p><!-- /wp:paragraph -->

<!-- wp:heading --><h2 class="wp-block-heading">Before each mountain day</h2><!-- /wp:heading -->
<!-- wp:list --><ul class="wp-block-list"><li>Read the <a href="https://www.georoad.ge/?act=news&amp;lang=eng&amp;pid=1388404621" target="_blank" rel="noopener">official road restriction updates</a>.</li><li>Check the forecast at road altitude, not only in the departure city.</li><li>Travel in daylight with a charged phone, warm layers, water, and offline navigation.</li><li>Do not enter a route during bad weather, a closure, or damaged-road conditions.</li><li>Call 112 in an emergency and tell Geolander the incident location immediately.</li></ul><!-- /wp:list -->

<!-- wp:paragraph --><p>For the most searched winter mountain route, read the detailed <a href="/driving-to-kazbegi-in-winter/">Kazbegi winter guide</a>. For vehicle options, compare the <a href="/fleet/4x4-suv/">exact 4x4 and AWD fleet</a>.</p><!-- /wp:paragraph -->
HTML,
	],
];

WP_CLI::log( 'Creating P2/P3 SEO pages…' );
foreach ( $pages as $page ) {
	glc_backlog_upsert( $page );
}

// Keep the published guide hub complete and self-updating.
$guides = get_posts( [
	'post_type'      => 'page',
	'post_status'    => 'publish',
	'posts_per_page' => -1,
	'meta_key'       => 'glc_guide_route',
	'meta_compare'   => 'EXISTS',
	'orderby'        => 'title',
	'order'          => 'ASC',
] );
$hub = get_page_by_path( 'guides', OBJECT, 'page' );
if ( $hub instanceof WP_Post ) {
	$items = array_map(
		fn( $guide ) => sprintf( '<li><a href="%s">%s</a></li>', esc_url( get_permalink( $guide ) ), esc_html( get_the_title( $guide ) ) ),
		$guides
	);
	wp_update_post( [
		'ID'           => $hub->ID,
		'post_content' => '<!-- wp:paragraph --><p>Honest route and driving guides from a Tbilisi-based rental team. Conditions change, so every guide separates rental permission from live road status.</p><!-- /wp:paragraph -->'
			. '<!-- wp:list --><ul class="wp-block-list">' . implode( '', $items ) . '</ul><!-- /wp:list -->',
	] );
}

WP_CLI::success( 'P2/P3 SEO pages ready. Flush rewrites before testing /fleet/4x4-suv/.' );
