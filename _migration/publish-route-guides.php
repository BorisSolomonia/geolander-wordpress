<?php
/**
 * Idempotently publish the Geolander route guides and vehicle SEO titles.
 *
 * Run with: wp --allow-root eval-file /migration/publish-route-guides.php
 */

defined( 'ABSPATH' ) || exit;

$vehicle_titles = [
	'jeep-wrangler-2017-ys-105-sy'          => 'Jeep Wrangler 4x4 Rental in Tbilisi, Georgia',
	'jeep-wrangler-2017-white-sport'        => 'White Jeep Wrangler Rental in Tbilisi, Georgia',
	'mitsubishi-outlander'                   => 'Mitsubishi Outlander Rental in Tbilisi, Georgia',
	'mitsubishi-outlander-2016-gray'         => '2016 Outlander AWD Rental in Tbilisi, Georgia',
	'mitsubishi-outlander-2018-black'        => '2018 Black Outlander Rental in Tbilisi, Georgia',
	'mitsubishi-outlander-2018-gray'         => '2018 Gray Outlander 4x4 Rental in Tbilisi',
	'mitsubishi-outlander-2021-white'        => '2021 White Outlander Rental in Tbilisi, Georgia',
	'subaru-crosstrek-2017-rz117zr'          => '2017 Subaru Crosstrek AWD Rental in Tbilisi',
	'subaru-crosstrek-2021-dy-089-dy'        => '2021 Subaru Crosstrek Rental in Tbilisi, Georgia',
	'subaru-forester-2014-ee346el'           => '2014 Subaru Forester 4x4 Rental in Tbilisi',
	'subaru-forester-2016-blue'               => '2016 Blue Subaru Forester Rental in Tbilisi',
	'subaru-forester-2018-black'              => '2018 Black Subaru Forester Rental in Tbilisi',
	'subaru-forester-2018-green'              => '2018 Green Subaru Forester Rental in Tbilisi',
	'subaru-forester-2019-gray'               => '2019 Gray Subaru Forester Rental in Tbilisi',
	'subaru-forester-2020-ll802ml'            => '2020 Subaru Forester AWD Rental in Tbilisi',
	'subaru-forester-2023-black'              => '2023 Subaru Forester Rental in Tbilisi, Georgia',
	'toyota-4runner-2021-white'               => 'Toyota 4Runner 4x4 Rental in Tbilisi, Georgia',
	'toyota-highlander-2017-lc-785-ll'        => '7-Seat Toyota Highlander Rental in Tbilisi',
	'toyota-rav4-2016-limited'                => 'Toyota RAV4 Hybrid AWD Rental in Tbilisi',
];

if ( 19 !== count( $vehicle_titles ) ) {
	throw new RuntimeException( 'Expected exactly 19 vehicle SEO titles.' );
}

$resolved_cars = [];
foreach ( $vehicle_titles as $slug => $seo_title ) {
	$car = get_page_by_path( $slug, OBJECT, 'car' );
	if ( ! $car || 'publish' !== $car->post_status ) {
		throw new RuntimeException( "Published vehicle not found: {$slug}" );
	}
	$resolved_cars[ $car->ID ] = $seo_title;
}

foreach ( $resolved_cars as $post_id => $seo_title ) {
	update_post_meta( $post_id, 'glc_seo_title_en', $seo_title );
}

$guides = [
	[
		'slug'        => 'driving-to-kazbegi-in-winter',
		'title'       => 'Driving to Kazbegi in Winter: Road & 4x4 Guide',
		'seo_title'   => 'Kazbegi Winter Driving & 4x4 Rental Guide',
		'description' => 'Plan a winter drive from Tbilisi to Kazbegi: road conditions, 4x4 choice, stops, safety checks, and exact-car rental advice from a local Georgia team.',
		'route'       => 'Tbilisi to Kazbegi (Stepantsminda) via Gudauri',
		'content'     => <<<'HTML'
<!-- wp:paragraph {"fontSize":"large"} -->
<p class="has-large-font-size"><strong>Yes, you can drive from Tbilisi to Kazbegi in winter</strong>, but snow, ice, wind and temporary traffic controls can change the trip quickly around Gudauri and the Jvari Pass. Use a properly equipped vehicle, check the official road status before departure, and leave enough daylight for a calm mountain drive.</p>
<!-- /wp:paragraph -->

<!-- wp:group {"backgroundColor":"surface","style":{"border":{"radius":"14px"},"spacing":{"padding":{"top":"var:preset|spacing|40","right":"var:preset|spacing|40","bottom":"var:preset|spacing|40","left":"var:preset|spacing|40"}}}} -->
<div class="wp-block-group has-surface-background-color has-background" style="border-radius:14px;padding-top:var(--wp--preset--spacing--40);padding-right:var(--wp--preset--spacing--40);padding-bottom:var(--wp--preset--spacing--40);padding-left:var(--wp--preset--spacing--40)"><!-- wp:heading {"level":2} --><h2 class="wp-block-heading">Kazbegi winter drive at a glance</h2><!-- /wp:heading -->
<!-- wp:list --><ul class="wp-block-list"><li><strong>Route:</strong> Tbilisi → Mtskheta → Zhinvali → Ananuri → Gudauri → Jvari Pass → Stepantsminda.</li><li><strong>Road type:</strong> the main Georgian Military Highway is paved; winter weather is the real challenge.</li><li><strong>Best vehicle:</strong> AWD or 4x4 with winter-ready tires. Ground clearance helps when side roads or parking areas hold snow.</li><li><strong>Decision point:</strong> check the <a href="https://www.georoad.ge/?lang=eng" target="_blank" rel="noopener">Roads Department of Georgia</a> notices on the morning you leave and again before the return.</li></ul><!-- /wp:list --></div>
<!-- /wp:group -->

<!-- wp:heading {"level":2} -->
<h2 class="wp-block-heading">Do you need a 4x4 for Kazbegi?</h2>
<!-- /wp:heading -->
<!-- wp:paragraph -->
<p>In dry conditions, the main highway to Stepantsminda is not an off-road route. In winter, however, AWD or 4x4 gives useful traction on snow-covered inclines and makes the trip more forgiving when conditions change. Tires remain critical: four driven wheels do not shorten braking distance on ice. Drive smoothly, increase following distance, and never treat the center line as a safe place to overtake.</p>
<!-- /wp:paragraph -->
<!-- wp:paragraph -->
<p>For two to five travelers, a <a href="/fleet/subaru-forester-2020-ll802ml/">Subaru Forester AWD</a> balances traction, luggage space and easy road manners. If you want more clearance and a traditional 4x4 platform, compare the <a href="/fleet/jeep-wrangler-2017-ys-105-sy/">Jeep Wrangler</a>. Browse the <a href="/fleet/">full Tbilisi 4x4 rental fleet</a> to see the exact cars and real photos.</p>
<!-- /wp:paragraph -->

<!-- wp:heading {"level":2} -->
<h2 class="wp-block-heading">Where the road becomes difficult</h2>
<!-- /wp:heading -->
<!-- wp:paragraph -->
<p>The most weather-sensitive section is around Gudauri, Kobi and the Jvari Pass. Heavy snow, avalanche control, low visibility or strong wind can cause temporary restrictions. A clear forecast in Tbilisi does not guarantee the same conditions at altitude. If officials close or restrict a section, wait—do not look for an unverified detour.</p>
<!-- /wp:paragraph -->
<!-- wp:paragraph -->
<p>After reaching Stepantsminda, the paved access toward Gergeti can still be snowy or icy. Park only where it is safe and legal. Roads into side valleys can have different conditions from the main highway, so ask locally before continuing beyond the town.</p>
<!-- /wp:paragraph -->

<!-- wp:heading {"level":2} -->
<h2 class="wp-block-heading">A practical Tbilisi-to-Kazbegi plan</h2>
<!-- /wp:heading -->
<!-- wp:list {"ordered":true} -->
<ol class="wp-block-list"><li><strong>Start early.</strong> Collect the car, confirm tires and controls, and aim to complete the mountain section in daylight.</li><li><strong>Fuel before the climb.</strong> Do not rely on every small station being open during bad weather.</li><li><strong>Use Ananuri or Zhinvali as a first stop.</strong> They break up the drive before the steeper section.</li><li><strong>Pause in Gudauri.</strong> Recheck road notices, visibility and the return forecast before crossing toward Kobi.</li><li><strong>Keep the return flexible.</strong> If snow intensifies, stay in Stepantsminda rather than racing a closure.</li></ol>
<!-- /wp:list -->

<!-- wp:heading {"level":2} -->
<h2 class="wp-block-heading">Winter safety checklist</h2>
<!-- /wp:heading -->
<!-- wp:list -->
<ul class="wp-block-list"><li>Confirm the vehicle has season-appropriate tires and ask whether chains are supplied or required for the forecast.</li><li>Carry water, warm layers, a charged phone and an offline map.</li><li>Use engine braking gently on descents; avoid sudden steering, throttle or braking inputs.</li><li>Never stop in an avalanche channel, blind bend or active traffic lane for photos.</li><li>Save the rental support number and Georgia's emergency number, 112.</li><li>Do not enter a road affected by bad weather, an official closure, or road damage.</li></ul>
<!-- /wp:list -->

<!-- wp:heading {"level":2} -->
<h2 class="wp-block-heading">Book the exact car for your Kazbegi dates</h2>
<!-- /wp:heading -->
<!-- wp:paragraph -->
<p>Geolander shows real photos of the actual vehicle you reserve. Insurance is included, Tbilisi airport delivery is free, and our local team can help you match the car to the forecast and group size. Road access is never guaranteed by a rental booking, so we will always favor the current official restriction over an itinerary.</p>
<!-- /wp:paragraph -->
<!-- wp:buttons -->
<div class="wp-block-buttons"><!-- wp:button --><div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="/fleet/">Choose a Kazbegi-ready 4x4</a></div><!-- /wp:button --><!-- wp:button {"className":"is-style-outline"} --><div class="wp-block-button is-style-outline"><a class="wp-block-button__link wp-element-button" href="/contact/">Ask a local route question</a></div><!-- /wp:button --></div>
<!-- /wp:buttons -->

<!-- wp:paragraph {"fontSize":"small"} -->
<p class="has-small-font-size"><em>Road rules and restrictions change with conditions. This guide is planning information, not a live road-status report. Follow police and Roads Department instructions.</em></p>
<!-- /wp:paragraph -->
HTML,
	],
	[
		'slug'        => 'svaneti-4x4-road-trip-guide',
		'title'       => 'Svaneti Road Trip: Tbilisi to Mestia & Ushguli by 4x4',
		'seo_title'   => 'Svaneti 4x4 Road Trip Guide: Mestia & Ushguli',
		'description' => 'Plan a Svaneti road trip from Tbilisi to Mestia and Ushguli with route options, seasonal road advice, 4x4 selection, stops, and local rental tips in Georgia.',
		'route'       => 'Tbilisi to Mestia and Ushguli in Svaneti',
		'content'     => <<<'HTML'
<!-- wp:paragraph {"fontSize":"large"} -->
<p class="has-large-font-size"><strong>The reliable self-drive plan for Svaneti is to reach Mestia through Zugdidi, then treat Ushguli as a separate mountain-road decision.</strong> The road into Mestia is the practical year-round approach, while the final section to Ushguli is rougher, weather-sensitive and much better suited to a capable 4x4.</p>
<!-- /wp:paragraph -->

<!-- wp:group {"backgroundColor":"surface","style":{"border":{"radius":"14px"},"spacing":{"padding":{"top":"var:preset|spacing|40","right":"var:preset|spacing|40","bottom":"var:preset|spacing|40","left":"var:preset|spacing|40"}}}} -->
<div class="wp-block-group has-surface-background-color has-background" style="border-radius:14px;padding-top:var(--wp--preset--spacing--40);padding-right:var(--wp--preset--spacing--40);padding-bottom:var(--wp--preset--spacing--40);padding-left:var(--wp--preset--spacing--40)"><!-- wp:heading {"level":2} --><h2 class="wp-block-heading">Svaneti route at a glance</h2><!-- /wp:heading -->
<!-- wp:list --><ul class="wp-block-list"><li><strong>Primary approach:</strong> Tbilisi → Kutaisi/Senaki → Zugdidi → Jvari → Mestia.</li><li><strong>Mountain extension:</strong> Mestia → Ipari/Kala → Ushguli, only when weather and road conditions allow.</li><li><strong>Best vehicle:</strong> AWD is comfortable for Mestia; a higher-clearance 4x4 is the sensible choice for Ushguli and rough side roads.</li><li><strong>Season warning:</strong> the Ushguli–Lentekhi road is seasonal and should not be treated as a guaranteed through-route.</li></ul><!-- /wp:list --></div>
<!-- /wp:group -->

<!-- wp:heading {"level":2} -->
<h2 class="wp-block-heading">Which road should you take to Mestia?</h2>
<!-- /wp:heading -->
<!-- wp:paragraph -->
<p>For most visitors, the Zugdidi–Jvari–Mestia approach is the default. It follows the Enguri valley and provides the most straightforward access to the regional center. Build the trip as a full travel day, share the driving when possible, and avoid arriving on unfamiliar mountain roads after dark.</p>
<!-- /wp:paragraph -->
<!-- wp:paragraph -->
<p>Do not plan the Lentekhi–Ushguli road as your only exit. Georgia's official tourism portal describes that connection as a warm-season road that is often snow-covered for much of the year. Even in summer, rain, rockfall and roadworks can change the surface. Check locally in Mestia or Ushguli before committing to a one-way loop.</p>
<!-- /wp:paragraph -->

<!-- wp:heading {"level":2} -->
<h2 class="wp-block-heading">Do you need a 4x4 for Mestia and Ushguli?</h2>
<!-- /wp:heading -->
<!-- wp:paragraph -->
<p>An AWD crossover is a comfortable choice for the main approach to <a href="/places/mestia/">Mestia</a> in normal conditions. For <a href="/places/ushguli/">Ushguli</a>, prioritize ground clearance, tire condition and a true 4x4 system. The official Georgia Travel guidance for Chazhashi in the Ushguli community says an off-road vehicle is necessary from Mestia and warns that bad weather can make the road nearly impassable.</p>
<!-- /wp:paragraph -->
<!-- wp:paragraph -->
<p>A <a href="/fleet/jeep-wrangler-2017-ys-105-sy/">Jeep Wrangler 4x4</a> or <a href="/fleet/toyota-4runner-2021-white/">Toyota 4Runner</a> provides extra clearance for the rougher extension. For a paved-road-focused trip ending in Mestia, compare the more efficient <a href="/fleet/subaru-forester-2020-ll802ml/">Subaru Forester AWD</a>. Geolander permits all its vehicles on planned routes with insurance; bad weather, an official closure, or road damage still overrides that permission.</p>
<!-- /wp:paragraph -->

<!-- wp:heading {"level":2} -->
<h2 class="wp-block-heading">A sensible 5–7 day road-trip outline</h2>
<!-- /wp:heading -->
<!-- wp:list {"ordered":true} -->
<ol class="wp-block-list"><li><strong>Tbilisi to western Georgia:</strong> break the long transfer around Kutaisi or Zugdidi instead of forcing a late arrival.</li><li><strong>Zugdidi to Mestia:</strong> drive the Enguri valley in daylight and stop only in safe pullouts.</li><li><strong>Mestia base day:</strong> visit the museum, towers and nearby valleys while asking for the latest Ushguli road report.</li><li><strong>Mestia to Ushguli:</strong> leave early with fuel, water and offline navigation; turn back if weather or surface conditions deteriorate.</li><li><strong>Return through Mestia and Zugdidi:</strong> use the known approach unless reliable local information confirms a seasonal alternative.</li></ol>
<!-- /wp:list -->

<!-- wp:heading {"level":2} -->
<h2 class="wp-block-heading">Mountain-road driving rules that matter</h2>
<!-- /wp:heading -->
<!-- wp:list -->
<ul class="wp-block-list"><li>Give uphill traffic space where the road narrows and use established passing areas.</li><li>Expect livestock, minibuses and construction equipment around blind bends.</li><li>Cross water or deep mud only when the line and depth are known and your rental terms permit it.</li><li>Keep the fuel tank above half after Zugdidi; stations become less frequent.</li><li>Download offline maps, but trust closures and local instructions over app routing.</li><li>Carry cash, water, warm layers and a charged phone; mountain weather changes quickly.</li></ul>
<!-- /wp:list -->

<!-- wp:heading {"level":2} -->
<h2 class="wp-block-heading">Reserve a real 4x4 for Svaneti</h2>
<!-- /wp:heading -->
<!-- wp:paragraph -->
<p>With Geolander, the exact car shown in the listing is the car you receive. There is no anonymous “or similar” substitution. Tell us whether Mestia is your endpoint or Ushguli is part of the plan, and we can recommend the right clearance and luggage capacity. Insurance is included and Tbilisi airport delivery is free; current weather, closures, and road damage still control whether a route is safe to use.</p>
<!-- /wp:paragraph -->
<!-- wp:buttons -->
<div class="wp-block-buttons"><!-- wp:button --><div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="/fleet/">Compare Svaneti 4x4s</a></div><!-- /wp:button --><!-- wp:button {"className":"is-style-outline"} --><div class="wp-block-button is-style-outline"><a class="wp-block-button__link wp-element-button" href="/contact/">Check your Svaneti route</a></div><!-- /wp:button --></div>
<!-- /wp:buttons -->

<!-- wp:paragraph {"fontSize":"small"} -->
<p class="has-small-font-size"><em>Before departure, check current road notices and ask locally about the Mestia–Ushguli and Ushguli–Lentekhi sections. Conditions can change after rain or snow.</em></p>
<!-- /wp:paragraph -->
HTML,
	],
	[
		'slug'        => 'tusheti-4x4-rental-guide',
		'title'       => 'Tusheti Road Guide: Abano Pass to Omalo by 4x4',
		'seo_title'   => 'Tusheti 4x4 Rental Guide: Abano Pass to Omalo',
		'description' => 'Plan the Abano Pass road to Omalo with season dates, high-clearance 4x4 advice, safety checks, fuel planning, and local Tusheti route tips in Georgia.',
		'route'       => 'Abano Pass to Omalo in Tusheti',
		'content'     => <<<'HTML'
<!-- wp:paragraph {"fontSize":"large"} -->
<p class="has-large-font-size"><strong>Tusheti is not a casual rental-car detour.</strong> The road from Kakheti over the Abano Pass to Omalo is seasonal, unpaved, exposed and officially suitable only for an off-road vehicle. Drive it only with strong mountain-road experience, a favorable forecast, and confirmation that the pass is open and undamaged.</p>
<!-- /wp:paragraph -->

<!-- wp:group {"backgroundColor":"surface","style":{"border":{"radius":"14px"},"spacing":{"padding":{"top":"var:preset|spacing|40","right":"var:preset|spacing|40","bottom":"var:preset|spacing|40","left":"var:preset|spacing|40"}}}} -->
<div class="wp-block-group has-surface-background-color has-background" style="border-radius:14px;padding-top:var(--wp--preset--spacing--40);padding-right:var(--wp--preset--spacing--40);padding-bottom:var(--wp--preset--spacing--40);padding-left:var(--wp--preset--spacing--40)"><!-- wp:heading {"level":2} --><h2 class="wp-block-heading">Tusheti road at a glance</h2><!-- /wp:heading -->
<!-- wp:list --><ul class="wp-block-list"><li><strong>Route:</strong> Tbilisi → Telavi/Akhmeta → Kvemo Alvani/Pshaveli → Abano Pass → Omalo.</li><li><strong>Season:</strong> plan within the official visitor season, generally June through October, and verify the actual opening before travel.</li><li><strong>Vehicle:</strong> official visitor guidance calls for an off-road vehicle; choose high clearance even though Geolander's rental policy does not impose a model-specific route ban.</li><li><strong>Experience:</strong> hire a local driver if exposed, narrow mountain roads are outside your experience.</li></ul><!-- /wp:list --></div>
<!-- /wp:group -->

<!-- wp:heading {"level":2} -->
<h2 class="wp-block-heading">When is the Abano Pass open?</h2>
<!-- /wp:heading -->
<!-- wp:paragraph -->
<p>The Agency of Protected Areas lists Tusheti's tourist season as June through October and states that travel from Kvemo Alvani to Omalo is possible only by off-road vehicle. Georgia Travel gives a narrower typical pass window from late May to September because heavy snowfall closes the road outside the warm season. These are planning ranges, not a promise that the road is open on a particular date.</p>
<!-- /wp:paragraph -->
<!-- wp:paragraph -->
<p>Snowmelt, landslides, repairs and early autumn snow can delay opening or force a closure. Confirm with the <a href="https://apa.gov.ge/en/eco-tourism/servisebi-da-tarifebi/tushetis-daculi-teritoriebi1" target="_blank" rel="noopener">Tusheti Protected Areas administration</a> and ask the rental team before leaving Tbilisi. Do not rely on a map app showing the route as open.</p>
<!-- /wp:paragraph -->

<!-- wp:heading {"level":2} -->
<h2 class="wp-block-heading">Can you take a Geolander rental to Tusheti?</h2>
<!-- /wp:heading -->
<!-- wp:paragraph -->
<p><strong>Yes, while the road is officially open and conditions are safe.</strong> Geolander permits all its vehicles on planned routes and insurance continues. Do not proceed during bad weather, an official closure, or when road damage makes the route unsafe or unavailable. Vehicle permission does not override police, protected-area, or road-authority instructions.</p>
<!-- /wp:paragraph -->
<!-- wp:paragraph -->
<p>A high-clearance platform such as the <a href="/fleet/toyota-4runner-2021-white/">Toyota 4Runner</a> or <a href="/fleet/jeep-wrangler-2017-ys-105-sy/">Jeep Wrangler</a> is the relevant category. Final suitability depends on tires, maintenance, load and current conditions—not the model name alone. Read the <a href="/terms/">rental terms</a> and confirm the latest road status before departure.</p>
<!-- /wp:paragraph -->

<!-- wp:heading {"level":2} -->
<h2 class="wp-block-heading">What the drive is actually like</h2>
<!-- /wp:heading -->
<!-- wp:paragraph -->
<p>The paved section ends before the high pass. Beyond it, expect a narrow dirt road, long climbs, steep drops, blind bends, loose surfaces and few places to pass. Georgia Travel estimates roughly four hours for the Pshaveli-to-Omalo mountain section in a high-clearance SUV; allow more time for weather, traffic and safe stops. Fatigue is a major risk, so do not combine the pass with a rushed overnight flight arrival.</p>
<!-- /wp:paragraph -->
<!-- wp:paragraph -->
<p>After Omalo, village roads toward Dartlo or other valleys may be rougher and may enter border zones. The protected-area guidance notes that special movement permits are required for some locations, including Diklo, Girevi and Atsunta Pass. Confirm permits and access separately; reaching Omalo does not authorize every onward track.</p>
<!-- /wp:paragraph -->

<!-- wp:heading {"level":2} -->
<h2 class="wp-block-heading">Tusheti preparation checklist</h2>
<!-- /wp:heading -->
<!-- wp:list -->
<ul class="wp-block-list"><li>Verify opening, weather, and road damage with official/local sources immediately before departure.</li><li>Fuel fully in Kakheti and carry water, food, warm layers and offline navigation.</li><li>Inspect the spare tire, jack and basic emergency equipment before leaving pavement.</li><li>Travel in daylight and avoid the pass during heavy rain, fog, snow or after a closure notice.</li><li>Yield early at passing places; never reverse toward an exposed edge under pressure.</li><li>Tell your accommodation when to expect you and save Georgia's emergency number, 112.</li></ul>
<!-- /wp:list -->

<!-- wp:heading {"level":2} -->
<h2 class="wp-block-heading">When a local driver is the better choice</h2>
<!-- /wp:heading -->
<!-- wp:paragraph -->
<p>Choose a local 4x4 transfer if you have not driven narrow unguarded mountain roads, are uncomfortable reversing on a single-lane track, or want to focus on the scenery. That is a good travel decision, not a missed adventure. You can still rent a car for Kakheti and the rest of Georgia, leave it safely below the pass, and use an experienced Tusheti driver for the mountain section.</p>
<!-- /wp:paragraph -->

<!-- wp:heading {"level":2} -->
<h2 class="wp-block-heading">Discuss Tusheti before you reserve</h2>
<!-- /wp:heading -->
<!-- wp:paragraph -->
<p>Send Geolander your dates, passenger count, luggage and intended villages. We will recommend the most suitable exact car and discuss current conditions. If weather, a closure, road damage, or driver experience makes self-drive unsafe, we will say so plainly rather than hide the risk in fine print.</p>
<!-- /wp:paragraph -->
<!-- wp:buttons -->
<div class="wp-block-buttons"><!-- wp:button --><div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="/contact/">Ask about Tusheti conditions</a></div><!-- /wp:button --><!-- wp:button {"className":"is-style-outline"} --><div class="wp-block-button is-style-outline"><a class="wp-block-button__link wp-element-button" href="/fleet/">View high-clearance 4x4s</a></div><!-- /wp:button --></div>
<!-- /wp:buttons -->

<!-- wp:paragraph {"fontSize":"small"} -->
<p class="has-small-font-size"><em>Geolander's route policy does not override official restrictions. Current weather, closures, road damage, police instructions, and protected-area rules control the trip.</em></p>
<!-- /wp:paragraph -->
HTML,
	],
];

// GL-024: the three mountain guides previously formed isolated silos. Keep the
// links in the idempotent source so rerunning this publisher cannot erase them.
foreach ( $guides as &$guide ) {
	$items = [];
	foreach ( $guides as $related ) {
		if ( $related['slug'] === $guide['slug'] ) {
			continue;
		}
		$items[] = sprintf(
			'<li><a href="/%s/">%s</a></li>',
			esc_attr( $related['slug'] ),
			esc_html( $related['title'] )
		);
	}
	$guide['content'] .= "\n<!-- glc-related-guides -->\n"
		. '<!-- wp:heading {"level":2} --><h2 class="wp-block-heading">Related mountain driving guides</h2><!-- /wp:heading -->'
		. '<!-- wp:list --><ul class="wp-block-list">' . implode( '', $items ) . '</ul><!-- /wp:list -->';
}
unset( $guide );

$published = [];
foreach ( $guides as $guide ) {
	$existing = get_page_by_path( $guide['slug'], OBJECT, 'page' );
	$postarr   = [
		'ID'           => $existing ? $existing->ID : 0,
		'post_type'    => 'page',
		'post_status'  => 'publish',
		'post_name'    => $guide['slug'],
		'post_title'   => $guide['title'],
		'post_excerpt' => $guide['description'],
		'post_content' => $guide['content'],
		'meta_input'   => [
			'glc_seo_title_en'       => $guide['seo_title'],
			'glc_seo_description_en' => $guide['description'],
			'glc_guide_route'        => $guide['route'],
		],
	];

	$post_id = $existing ? wp_update_post( $postarr, true ) : wp_insert_post( $postarr, true );
	if ( is_wp_error( $post_id ) ) {
		throw new RuntimeException( $post_id->get_error_message() );
	}
	$published[] = [
		'id'    => $post_id,
		'title' => $guide['title'],
		'url'   => get_permalink( $post_id ),
	];
}

clean_post_cache( 0 );
echo wp_json_encode(
	[
		'vehicle_titles_updated' => count( $resolved_cars ),
		'guides_published'       => $published,
	],
	JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
), "\n";
