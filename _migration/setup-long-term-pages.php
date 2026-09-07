<?php
/**
 * Long-term rental pages — the most profitable segment, previously absent.
 *
 * Run: docker compose run --rm cli eval-file /migration/setup-long-term-pages.php
 * Then: docker compose run --rm cli rewrite flush
 * Idempotent by slug.
 *
 * WHY THESE PAGES
 * The owner's stated profit driver is rentals of two weeks and longer, and the
 * price engine already discounts the 19–30 and 31+ day tiers by roughly a third
 * — yet on 2026-09-01 the words "monthly", "long-term", "relocation" appeared
 * zero times on the site (Search Console + live grep). Nobody typing
 * "monthly car rental Tbilisi" or "long term car hire Georgia" could learn any
 * of that from Geolander.
 *
 * FACT DISCIPLINE (SEO-AGENT-MANUAL.md §0.1)
 * Every business fact below is from the manual's verified table §1.1 or is
 * rendered live by a block (rates, delivery charges, the rental-facts list).
 * Long-rental specifics the owner has not confirmed — servicing during the
 * rental, swapping cars, cross-border on a long booking — are NOT stated; the
 * page tells the reader to agree them on WhatsApp. When the owner confirms
 * them (SEO/facts-2026-09.md, items B-13..B-15), extend the page.
 *
 * PUBLISH / DRAFT
 *   /long-term-car-rental-georgia/   PUBLISHED — the hub, fact-complete
 *   /monthly-car-rental-tbilisi/     DRAFT — the owner decides (plan Day 17)
 *                                    whether a city-level page adds anything a
 *                                    section of the hub does not.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit( "Run via wp eval-file\n" );
}

function glc_lt_upsert( array $page ): int {
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
	update_post_meta( $id, 'glc_service_type', $page['service_type'] );
	foreach ( ( $page['titles'] ?? [] ) as $locale => $title ) {
		update_post_meta( $id, "glc_title_{$locale}", $title );
	}
	WP_CLI::log( sprintf( '  ✓ /%s/ (%s)', $page['slug'], $page['status'] ) );
	return (int) $id;
}

$pages = [
	[
		'slug'         => 'long-term-car-rental-georgia',
		'status'       => 'publish',
		'title'        => 'Long-Term Car Rental in Georgia',
		'seo_title'    => 'Long-Term Car Rental in Georgia (Country) — Monthly Rates',
		'description'  => 'Rent a 4x4 or AWD car in Georgia for a month or longer. Live per-day rates for 19–30 and 31+ day rentals, no security deposit, full insurance included, unlimited mileage within Georgia, delivery in Tbilisi, Kutaisi and Batumi.',
		'service_type' => 'Long-term car rental',
		'titles'       => [
			'ka' => 'მანქანის გრძელვადიანი ქირაობა საქართველოში',
			'ru' => 'Долгосрочная аренда авто в Грузии',
			'uk' => 'Довгострокова оренда авто в Грузії',
			'ar' => 'تأجير سيارات طويل الأمد في جورجيا',
			'zh' => '格鲁吉亚长期租车',
			'fr' => 'Location de voiture longue durée en Géorgie',
		],
		'content'      => <<<'HTML'
<!-- wp:paragraph {"fontSize":"large"} --><p class="has-large-font-size"><strong>Yes — Geolander rents cars in Georgia for a month or longer.</strong> Rentals of 19–30 days and 31+ days are priced on lower per-day tiers, read live from each car's seasonal price table below, with no security deposit and full insurance included.</p><!-- /wp:paragraph -->

<!-- wp:heading --><h2 class="wp-block-heading">Long-term rates, per car and season</h2><!-- /wp:heading -->
<!-- wp:paragraph --><p>These are the same tables our quote widget uses. Pick any car, enter your dates on its page, and the total is calculated day by day at that season's rate for your rental length.</p><!-- /wp:paragraph -->
<!-- wp:geolander/long-term-rates /-->

<!-- wp:heading --><h2 class="wp-block-heading">What every rental includes</h2><!-- /wp:heading -->
<!-- wp:geolander/rental-facts /-->

<!-- wp:heading --><h2 class="wp-block-heading">How a long booking works</h2><!-- /wp:heading -->
<!-- wp:list --><ul class="wp-block-list"><li><strong>Quote first.</strong> Choose a car, enter your dates, and send the pre-filled WhatsApp request. We confirm availability, usually within the hour during the day.</li><li><strong>A 10% prepayment confirms the booking.</strong> The balance is paid at pickup.</li><li><strong>Cancellation.</strong> 30 days or more before the rental starts, 50% of the prepayment is refunded. Fewer than 30 days before, the prepayment is non-refundable.</li><li><strong>The exact car.</strong> The vehicle on the page — plate, photos, year — is the vehicle you receive. Never “or similar”.</li></ul><!-- /wp:list -->

<!-- wp:heading --><h2 class="wp-block-heading">Handover and delivery</h2><!-- /wp:heading -->
<!-- wp:paragraph --><p>Pickup at our office in Mtatsminda, central Tbilisi, or free delivery to Tbilisi International Airport (TBS). Handover at Kutaisi Airport (KUT) or Batumi Airport (BUS) carries a fixed each-way charge that is shown in your quote before you confirm. See the city pages for <a href="/car-rental-tbilisi/">Tbilisi</a>, <a href="/car-rental-kutaisi/">Kutaisi</a> and <a href="/car-rental-batumi/">Batumi</a>.</p><!-- /wp:paragraph -->

<!-- wp:heading --><h2 class="wp-block-heading">Agree these on WhatsApp before you book</h2><!-- /wp:heading -->
<!-- wp:paragraph --><p>A month on Georgian roads is different from a weekend. Three things are agreed per booking rather than promised on a web page, so ask us and get the answer in writing in the chat: <strong>servicing during your rental</strong> (oil change intervals on a high-mileage month), <strong>swapping the car</strong> if it needs workshop time, and <strong>crossing into Armenia</strong>, which is possible with advance notice under our <a href="/terms/">rental terms</a>.</p><!-- /wp:paragraph -->

<!-- wp:heading --><h2 class="wp-block-heading">Who rents for a month or more</h2><!-- /wp:heading -->
<!-- wp:list --><ul class="wp-block-list"><li>Remote workers and families settling into Tbilisi or Batumi before buying a car.</li><li>Travellers doing the full loop — Kazbegi, Kakheti, Svaneti, the coast — at their own pace. Start with the <a href="/driving-in-georgia/">driving in Georgia guide</a>.</li><li>Anyone whose imported car is stuck in customs or in a workshop.</li><li>Project teams who need an AWD car for mountain sites through the winter, when <a href="/driving-in-georgia-in-winter/">winter tyres are fitted free</a>.</li></ul><!-- /wp:list -->

<!-- wp:heading --><h2 class="wp-block-heading">Questions people ask before a long rental</h2><!-- /wp:heading -->
<!-- wp:heading {"level":3} --><h3 class="wp-block-heading">Is there a security deposit on a monthly rental?</h3><!-- /wp:heading -->
<!-- wp:paragraph --><p>No. Geolander takes no cash deposit, card charge or pre-authorisation hold on any rental, whatever its length.</p><!-- /wp:paragraph -->
<!-- wp:heading {"level":3} --><h3 class="wp-block-heading">Is mileage limited?</h3><!-- /wp:heading -->
<!-- wp:paragraph --><p>Mileage is unlimited within Georgia.</p><!-- /wp:paragraph -->
<!-- wp:heading {"level":3} --><h3 class="wp-block-heading">What does the insurance cover for a month?</h3><!-- /wp:heading -->
<!-- wp:paragraph --><p>The same full cover as any rental: no excess, wheels and windscreen included, third-party liability up to 30,000 GEL. Tyres are not covered. Cover is void for wrong-lane driving, running a red light, speeding, or failing to tell us where an incident happened.</p><!-- /wp:paragraph -->
<!-- wp:heading {"level":3} --><h3 class="wp-block-heading">Which cars suit a long rental?</h3><!-- /wp:heading -->
<!-- wp:paragraph --><p>For city and highway use with occasional mountain trips, the Subaru Foresters and Mitsubishi Outlanders are the most economical in the table above. For a winter in Gudauri or regular rough tracks, compare the <a href="/fleet/4x4-suv/">4x4 and AWD fleet</a>.</p><!-- /wp:paragraph -->
<!-- wp:heading {"level":3} --><h3 class="wp-block-heading">Can I extend?</h3><!-- /wp:heading -->
<!-- wp:paragraph --><p>Message us on WhatsApp before your return date. If the car is free, we re-quote the whole rental at the tier your new total length earns.</p><!-- /wp:paragraph -->

<!-- wp:buttons --><div class="wp-block-buttons"><!-- wp:button --><div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="/contact/">Ask for a monthly quote on WhatsApp</a></div><!-- /wp:button --></div><!-- /wp:buttons -->
<!-- wp:paragraph --><p>Browse the <a href="/fleet/">full fleet</a> to pick your car and see its seasonal price table.</p><!-- /wp:paragraph -->
HTML,
	],
	[
		'slug'         => 'monthly-car-rental-tbilisi',
		'status'       => 'draft',
		'title'        => 'Monthly Car Rental in Tbilisi',
		'seo_title'    => 'Monthly Car Rental in Tbilisi, Georgia — 31+ Day Rates',
		'description'  => 'Rent a car in Tbilisi by the month: lower 31+ day per-day rates read live from each car, no deposit, full insurance, free handover at our Mtatsminda office or Tbilisi Airport.',
		'service_type' => 'Monthly car rental',
		'titles'       => [
			'ka' => 'მანქანის ყოველთვიური ქირაობა თბილისში',
			'ru' => 'Помесячная аренда авто в Тбилиси',
			'uk' => 'Помісячна оренда авто у Тбілісі',
			'ar' => 'تأجير سيارات شهري في تبليسي',
			'zh' => '第比利斯按月租车',
			'fr' => 'Location de voiture au mois à Tbilissi',
		],
		'content'      => <<<'HTML'
<!-- wp:paragraph {"fontSize":"large"} --><p class="has-large-font-size"><strong>Geolander rents cars in Tbilisi by the month</strong>, from our office in Mtatsminda or with free handover at Tbilisi International Airport. The 31+ day tier below is read live from each car's price table.</p><!-- /wp:paragraph -->

<!-- wp:paragraph --><p><mark><strong>OWNER DECISION (plan Day 17):</strong> keep this as a separate page only if Tbilisi monthly demand justifies its own URL. Otherwise fold the Tbilisi-specific paragraph into <a href="/long-term-car-rental-georgia/">the long-term hub</a> and leave this draft unpublished. Two strong pages beat four thin ones.</mark></p><!-- /wp:paragraph -->

<!-- wp:heading --><h2 class="wp-block-heading">Monthly rates in Tbilisi</h2><!-- /wp:heading -->
<!-- wp:geolander/long-term-rates /-->

<!-- wp:heading --><h2 class="wp-block-heading">What is different about Tbilisi</h2><!-- /wp:heading -->
<!-- wp:list --><ul class="wp-block-list"><li>Handover is free at the Mtatsminda office and at Tbilisi Airport (TBS) — no delivery charge on a month-long rental here.</li><li>Parking: most long-stay visitors park on the street in the central districts; ask us about the current municipal parking rules when you book.</li><li>Extending is a WhatsApp message; if the car is free we re-quote the whole rental at the longer tier.</li></ul><!-- /wp:list -->

<!-- wp:heading --><h2 class="wp-block-heading">What every rental includes</h2><!-- /wp:heading -->
<!-- wp:geolander/rental-facts /-->

<!-- wp:buttons --><div class="wp-block-buttons"><!-- wp:button --><div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="/contact/">Ask for a monthly quote on WhatsApp</a></div><!-- /wp:button --></div><!-- /wp:buttons -->
<!-- wp:paragraph --><p>Long-term rental anywhere in Georgia: see the <a href="/long-term-car-rental-georgia/">long-term rental hub</a>. Short rentals from Tbilisi: <a href="/car-rental-tbilisi/">car rental in Tbilisi</a>.</p><!-- /wp:paragraph -->
HTML,
	],
];

WP_CLI::log( 'Creating long-term rental pages…' );
foreach ( $pages as $page ) {
	glc_lt_upsert( $page );
}
WP_CLI::success( 'Long-term pages ready. Flush rewrites, then check /long-term-car-rental-georgia/ renders the live rates table.' );
