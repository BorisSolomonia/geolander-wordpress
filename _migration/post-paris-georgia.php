<?php
/**
 * Publish the "Paris cancelled → drive Georgia" article.
 *
 *   wp eval-file /migration/post-paris-georgia.php dry-run --allow-root
 *   wp eval-file /migration/post-paris-georgia.php --allow-root
 *
 * Idempotent: re-running refreshes the fields and leaves the post status alone.
 *
 * SHAPED FOR ANSWER ENGINES, on the 2026 GEO research rather than folklore:
 * retrieval scores passages on topical match, recency, authority and clarity, so
 * the piece leads with one short declarative answer, names concrete entities and
 * numbers, puts each likely question in its own heading, carries dated sources
 * the model can check, and emits BlogPosting with a `citation` array. No FAQPage
 * markup: Google retired FAQ rich results for sites like this one, and the SEO
 * manual rules it out. Sources: arXiv 2605.25517 and 2607.14035.
 *
 * EVERY FACT HERE IS SOURCED. Georgia's one-year visa-free entry comes from the
 * legal act on matsne.gov.ge; the qvevri inscription from UNESCO, which notably
 * does NOT date the tradition — so the usual "8,000 years of wine" line is absent
 * rather than borrowed from a travel blog. No claim is made about Geolander's own
 * licence or IDP policy, because that is a business fact only Boris can state.
 */

defined( 'WP_CLI' ) || exit;

$dry = in_array( 'dry-run', (array) ( $args ?? [] ), true );
if ( ! class_exists( 'GLC_News' ) ) {
	WP_CLI::error( 'GLC_News is not loaded.' );
}

$slug = 'paris-cancelled-drive-georgia-instead';

$meta = [
	'glc_news_city'          => 'Tbilisi',
	'glc_news_verified_on'   => '2026-10-07',
	'glc_news_sources'       => implode( "\n", [
		'Government of Georgia — list of countries whose citizens may enter without a visa | https://matsne.gov.ge/en/document/view/2867361',
		'UNESCO — Ancient Georgian traditional Qvevri wine-making method, inscribed 2013 | https://ich.unesco.org/en/RL/ancient-georgian-traditional-qvevri-wine-making-method-00870',
		'Ministry of Foreign Affairs of Georgia — visa information | https://www.geoconsul.gov.ge',
	] ),
	// The block an answer engine lifts: one paragraph, declarative, entity-rich.
	'glc_news_answer'        => 'If a Paris trip falls through, Georgia is the easier replacement for anyone who wants to drive. Citizens of the EU, the United Kingdom, the United States, Canada, Australia and New Zealand may enter Georgia visa-free and stay for one full year, against the 90-days-in-180 limit of the Schengen area. Tbilisi sits roughly three hours by road from the Greater Caucasus, and Georgia has no congestion charge or low-emission zone to keep a rental car out of its capital.',
	'glc_news_faq'           => implode( "\n", [
		'Do I need a visa for Georgia? | Citizens of around 95 countries, including every EU state, the UK, the USA, Canada, Australia and New Zealand, enter visa-free and may stay one full year. The legal list is published by the Government of Georgia on matsne.gov.ge.',
		'How long can I stay compared with France? | One year in Georgia on a single entry, against 90 days in any 180 in the Schengen area. For a long slow trip, that difference is the whole argument.',
		'Is Georgia a wine country like France? | Yes, and with its own method. UNESCO inscribed the Ancient Georgian traditional Qvevri wine-making method on its Representative List of the Intangible Cultural Heritage of Humanity in 2013. Kakheti is about 1.5 hours by car from Tbilisi.',
		'Are the mountains comparable to the Alps? | Georgia sits on the Greater Caucasus, whose Georgian high point, Shkhara, is higher than Mont Blanc. Unlike the Alps, the range is reachable from the capital in a morning.',
		'Can I drive on my own licence? | A foreign licence is generally valid in Georgia for up to a year from entry, and licences in Latin or Cyrillic script are the straightforward case. Rules differ by document, so message us on WhatsApp with what you hold and we will tell you plainly what is needed.',
		'Is a car actually worth it? | Only if you leave Tbilisi. Inside the city, walk. The case for a car is Kazbegi, Kakheti and Svaneti, which public transport reaches slowly or not at all.',
	] ),
	'glc_news_offer_status'  => GLC_News::OFFER_NONE,
	'glc_news_related_pages' => '/fleet/, /car-rental-tbilisi/, /places/, /driving-in-georgia/',
];

$content = <<<'HTML'
<!-- wp:paragraph -->
<p>Trips fall apart. A strike, a price jump, a date that stopped working, and suddenly the week you had set aside for Paris is an empty week. The useful question is not what went wrong but what else that week could be, and Georgia answers it unusually well for anyone who likes driving.</p>
<!-- /wp:paragraph -->

<!-- wp:heading -->
<h2>The difference that actually matters: time</h2>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>The Schengen area gives you 90 days in any 180. Georgia gives most Western passports <strong>one full year, visa-free, on a single entry</strong>, with no paperwork at the border and no residence permit. If your plan was a week, that is irrelevant. If your plan was ever to stay longer, work remotely for a season, or come back twice, it changes everything.</p>
<!-- /wp:paragraph -->

<!-- wp:heading -->
<h2>Where Georgia and France are genuinely alike</h2>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>This is not a consolation prize. The two countries share the three things people actually go to France for.</p>
<!-- /wp:paragraph -->

<!-- wp:list -->
<ul>
<li><strong>Wine with a real tradition.</strong> France has appellations; Georgia has the qvevri, the buried clay vessel UNESCO added to its Intangible Cultural Heritage list in 2013. Kakheti, the main wine region, is about an hour and a half from Tbilisi by car.</li>
<li><strong>High mountains.</strong> France has the Alps; Georgia has the Greater Caucasus, and its Georgian high point, Shkhara, stands higher than Mont Blanc.</li>
<li><strong>A capital built for walking.</strong> Old Tbilisi is a sulphur-bath quarter of balconies and courtyards, and like Paris it rewards aimlessness. Leave the car parked for it.</li>
</ul>
<!-- /wp:list -->

<!-- wp:heading -->
<h2>Where Georgia is simply easier for a driver</h2>
<!-- /wp:heading -->

<!-- wp:list -->
<ul>
<li><strong>No low-emission zone.</strong> Paris restricts which cars may enter and when. Tbilisi does not. Your rental goes where you go.</li>
<li><strong>The mountains are a morning away.</strong> The Georgian Military Highway puts you under Kazbegi in roughly three hours from the capital. The Alps are not three hours from Paris.</li>
<li><strong>The distances are humane.</strong> Georgia is small. Sea, wine country and glacier are all inside a few hours of each other, so a week is a real trip rather than a transfer schedule.</li>
<li><strong>It costs less.</strong> Food, fuel and a night's stay are all cheaper than their French equivalents, which is usually what decides how long people actually stay.</li>
</ul>
<!-- /wp:list -->

<!-- wp:heading -->
<h2>The honest caveats</h2>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>Georgian mountain roads are not French autoroutes. Surfaces vary, passes close in winter without much notice, and some of the best places need ground clearance rather than ambition. That is the reason our fleet is 4×4 rather than whatever was cheapest to buy. Ask us which roads are open for your dates before you plan around them; we drive these roads and we will tell you when the answer is no.</p>
<!-- /wp:paragraph -->

<!-- wp:paragraph -->
<p>And if you are staying inside Tbilisi for the whole visit, do not rent anything. The city is better on foot, and we would rather say so than sell you a car you will leave parked.</p>
<!-- /wp:paragraph -->
HTML;

$existing = get_page_by_path( $slug, OBJECT, GLC_News::POST_TYPE );

if ( $existing ) {
	WP_CLI::log( sprintf( '%sArticle exists as post %d (%s) — refreshing fields, status untouched.', $dry ? '[dry-run] ' : '', $existing->ID, $existing->post_status ) );
	if ( ! $dry ) {
		foreach ( $meta as $k => $v ) { update_post_meta( $existing->ID, $k, $v ); }
		wp_update_post( [ 'ID' => $existing->ID, 'post_content' => $content ] );
	}
} else {
	WP_CLI::log( sprintf( '%sCreating article at /%s/%s/.', $dry ? '[dry-run] ' : '', GLC_News::SLUG, $slug ) );
	if ( ! $dry ) {
		$id = wp_insert_post( [
			'post_type'    => GLC_News::POST_TYPE,
			'post_status'  => 'publish',
			'post_name'    => $slug,
			'post_title'   => 'Paris cancelled? Georgia is the easier drive, and you can stay a year',
			'post_content' => $content,
			'post_excerpt' => 'If a Paris trip falls through, Georgia gives most Western passports a full visa-free year, wine country ninety minutes from the capital and the Caucasus three hours away.',
		], true );
		if ( is_wp_error( $id ) ) { WP_CLI::error( $id->get_error_message() ); }
		foreach ( $meta as $k => $v ) { update_post_meta( $id, $k, $v ); }
		update_post_meta( $id, 'glc_seo_title_en', 'Paris Trip Cancelled? Drive Georgia Instead — Visa-Free for a Year' );
		update_post_meta( $id, 'glc_seo_description_en', 'Swapping Paris for Georgia: one year visa-free instead of 90 days, UNESCO-listed wine country 90 minutes from Tbilisi, and the Caucasus three hours away by car.' );
		WP_CLI::log( sprintf( 'Created and PUBLISHED post %d.', $id ) );
	}
}

if ( ! $dry ) { flush_rewrite_rules( false ); }
WP_CLI::success( $dry ? 'Dry run complete. Nothing was written.' : 'Article live.' );
