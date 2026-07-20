<?php
/**
 * Create the four city landing pages (car-rental-{city}).
 * Run: wp eval-file /migration/setup-cities.php
 * Idempotent by slug. Content is unique per city (NOT a doorway template) —
 * English drafts; get ka/ru natively proofed before heavy promotion.
 *
 * After running this the FIRST time, flush rewrites so the pretty URLs resolve:
 *   wp rewrite flush
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit( "Run via wp eval-file\n" );
}

function glc_city_p( string $t ): string { return "<!-- wp:paragraph --><p>{$t}</p><!-- /wp:paragraph -->"; }
function glc_city_h( string $t ): string { return "<!-- wp:heading --><h2 class=\"wp-block-heading\">{$t}</h2><!-- /wp:heading -->"; }
function glc_city_ul( array $items ): string {
	$li = implode( '', array_map( fn( $i ) => "<!-- wp:list-item --><li>{$i}</li><!-- /wp:list-item -->", $items ) );
	return "<!-- wp:list --><ul class=\"wp-block-list\">{$li}</ul><!-- /wp:list -->";
}

/**
 * @param array{slug:string,title:string,excerpt:string,airport_name:string,airport_code:string,delivery:string,order:int,body:string} $c
 */
function glc_upsert_city( array $c ): void {
	$existing = get_posts( [ 'post_type' => 'city', 'name' => $c['slug'], 'posts_per_page' => 1, 'post_status' => 'any' ] );
	$id = wp_insert_post( [
		'ID'           => $existing[0]->ID ?? 0,
		'post_type'    => 'city',
		'post_status'  => 'publish',
		'post_name'    => $c['slug'],
		'post_title'   => $c['title'],
		'post_excerpt' => $c['excerpt'],
		'post_content' => $c['body'],
		'menu_order'   => $c['order'],
	] );
	if ( is_wp_error( $id ) ) {
		WP_CLI::warning( "  ✗ {$c['slug']}: " . $id->get_error_message() );
		return;
	}
	update_post_meta( $id, 'glc_airport_name', $c['airport_name'] );
	update_post_meta( $id, 'glc_airport_code', $c['airport_code'] );
	update_post_meta( $id, 'glc_delivery_note', $c['delivery'] );
	WP_CLI::log( "  ✓ /car-rental-{$c['slug']}/  (#{$id})" );
}

/* ----- Unique, genuinely differentiated content per city ------------------ */

$cities = [
	[
		'slug' => 'tbilisi', 'order' => 1,
		'title' => 'Car Rental in Tbilisi',
		'excerpt' => 'Rent a 4x4 in Tbilisi with free delivery to Tbilisi International Airport (TBS). Full insurance, no deposit, pay at pickup.',
		'airport_name' => 'Tbilisi International', 'airport_code' => 'TBS',
		'delivery' => 'Free delivery to Tbilisi Airport & city hotels',
		'body' =>
			glc_city_p( 'Tbilisi is where most Georgia road trips begin, and it is Geolander\'s home base. We deliver your car free of charge to Tbilisi International Airport (TBS) or to any hotel or address in the city — usually within the hour once your dates are confirmed on WhatsApp. Every rental is a real 4x4 with full insurance, unlimited mileage, and no security deposit; you pay at pickup, not online.' ) .
			glc_city_h( 'Where a Tbilisi rental takes you' ) .
			glc_city_p( 'From Tbilisi the whole country is within a day\'s drive, and a proper 4x4 opens the routes an ordinary car cannot:' ) .
			glc_city_ul( [
				'<strong>Kazbegi &amp; Gudauri</strong> — the Georgian Military Highway north to Gergeti Trinity Church (about 3 hours).',
				'<strong>Kakheti wine country</strong> — Sighnaghi, Telavi and the Alazani Valley to the east.',
				'<strong>Davit Gareja</strong> — the desert cave monastery on the Azerbaijan border, where the last gravel stretch really wants 4x4.',
				'<strong>Tusheti</strong> — for the experienced: the Abano Pass, one of the world\'s most demanding mountain roads (summer only, ask us first).',
			] ) .
			glc_city_h( 'Driving in and out of Tbilisi' ) .
			glc_city_p( 'City traffic is busy and parking near the old town is tight, so many travellers collect the car on the day they leave for the mountains rather than for city sightseeing. Tell us your plan on WhatsApp and we will suggest the pickup timing that saves you a parking headache.' ),
	],
	[
		'slug' => 'batumi', 'order' => 2,
		'title' => 'Car Rental in Batumi',
		'excerpt' => 'Rent a 4x4 in Batumi with free delivery to Batumi Airport (BUS) and seafront hotels. Full insurance, no deposit.',
		'airport_name' => 'Batumi International', 'airport_code' => 'BUS',
		'delivery' => 'Free delivery to Batumi Airport & seafront hotels',
		'body' =>
			glc_city_p( 'Batumi is Georgia\'s Black Sea capital — palm-lined boulevards, summer nightlife, and mountains that rise straight off the coast. We deliver your car free to Batumi International Airport (BUS) or to your seafront hotel, so you can land, settle in, and pick the car up only when you actually want to drive.' ) .
			glc_city_h( 'Why you want 4x4 in Adjara' ) .
			glc_city_p( 'The coast is flat and easy, but the reason to rent in Batumi is what sits just behind it — the green, rainy mountains of Adjara, where the interesting roads turn to gravel fast:' ) .
			glc_city_ul( [
				'<strong>Mtirala National Park</strong> — rainforest, waterfalls and a propeller bridge, 25 km inland.',
				'<strong>Machakhela valley</strong> — old arched stone bridges strung along the Turkish border.',
				'<strong>Gonio &amp; Sarpi</strong> — the Roman fortress and the beach right on the frontier.',
				'<strong>Goderdzi Pass</strong> — the spectacular high road over to Akhaltsikhe and southern Georgia.',
			] ) .
			glc_city_h( 'Batumi in summer' ) .
			glc_city_p( 'July and August are peak season on the coast, cars go quickly, and prices are seasonal — message us early on WhatsApp to lock a vehicle and an exact price for your dates.' ),
	],
	[
		'slug' => 'kutaisi', 'order' => 3,
		'title' => 'Car Rental at Kutaisi Airport',
		'excerpt' => 'Pick up a 4x4 at Kutaisi Airport (KUT) — the budget-flight gateway. Free airport delivery, full insurance, no deposit.',
		'airport_name' => 'Kutaisi International', 'airport_code' => 'KUT',
		'delivery' => 'Free pickup at Kutaisi Airport arrivals',
		'body' =>
			glc_city_p( 'Kutaisi International Airport (KUT) is how most budget travellers reach Georgia — the Wizz Air and low-cost hub for the whole region. The catch is that the airport sits well outside the city with little around it, so a car waiting at arrivals is the single best way to start your trip. We meet you at the terminal with the keys; full insurance, unlimited mileage, no deposit, pay on arrival.' ) .
			glc_city_h( 'Kutaisi is the gateway to western Georgia' ) .
			glc_city_p( 'Skip the taxi queues and drive straight into the west, which is greener, wilder and less visited than the east:' ) .
			glc_city_ul( [
				'<strong>Prometheus Cave &amp; Sataplia</strong> — huge caverns and dinosaur footprints, both a short drive from the airport.',
				'<strong>Okatse Canyon &amp; Kinchkha waterfall</strong> — cliff walkways and a two-tier falls in Imereti.',
				'<strong>Katskhi Pillar</strong> — the tiny church on top of a 40-metre limestone column.',
				'<strong>Svaneti</strong> — the big one: Mestia and Ushguli, medieval tower villages under the high Caucasus. The road rewards a capable 4x4.',
			] ) .
			glc_city_h( 'Landing late at Kutaisi?' ) .
			glc_city_p( 'Low-cost flights often arrive at odd hours. Send us your flight number on WhatsApp and we will have the car at the terminal whenever you land — no waiting for morning.' ),
	],
	[
		'slug' => 'kobuleti', 'order' => 4,
		'title' => 'Car Rental in Kobuleti',
		'excerpt' => 'Rent a 4x4 in Kobuleti with free delivery to your hotel. Quieter Black Sea base near Batumi. Full insurance, no deposit.',
		'airport_name' => 'Batumi International', 'airport_code' => 'BUS',
		'delivery' => 'Free delivery to Kobuleti hotels & guesthouses',
		'body' =>
			glc_city_p( 'Kobuleti is the calmer stretch of Georgia\'s Black Sea coast — a long pebble beach and low-key guesthouses, half an hour up from Batumi and close to Batumi Airport (BUS). It suits families and travellers who want the sea without Batumi\'s crowds, and a car turns a quiet beach base into a launch pad for the whole southwest. We deliver free to your hotel or guesthouse.' ) .
			glc_city_h( 'Day trips from Kobuleti' ) .
			glc_city_p( 'Everything in Adjara is within easy reach, plus a few things right on the doorstep:' ) .
			glc_city_ul( [
				'<strong>Kobuleti Protected Areas</strong> — the Ispani peat bogs and birdlife right beside town.',
				'<strong>Kintrishi Protected Area</strong> — lakes and forest in the hills just inland.',
				'<strong>Batumi</strong> — the boulevard, botanical garden and nightlife, a 30-minute drive south.',
				'<strong>Mtirala &amp; Machakhela</strong> — the Adjaran rainforest and border valleys, best reached with 4x4.',
			] ) .
			glc_city_h( 'Getting a car to Kobuleti' ) .
			glc_city_p( 'Fly into Batumi (BUS) or Kutaisi (KUT) and we will bring the car to you in Kobuleti, or meet you at either airport. Message us on WhatsApp with your arrival details for an exact price.' ),
	],
];

WP_CLI::log( 'Creating city landing pages…' );
foreach ( $cities as $city ) {
	glc_upsert_city( $city );
}
WP_CLI::log( 'Done. Now run:  wp rewrite flush' );
