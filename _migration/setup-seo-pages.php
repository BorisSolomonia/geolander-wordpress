<?php
/**
 * Create the SEO architecture pages.
 *
 * Run: docker compose run --rm cli eval-file /migration/setup-seo-pages.php
 * Idempotent by slug — safe to re-run.
 *
 * TWO CLASSES OF PAGE, AND THE DISTINCTION IS THE POINT
 * -----------------------------------------------------
 * PUBLISHED — pages built only from facts already on the site. The coverage hub
 * and the guides index are indexes of existing content; nothing on them is
 * invented.
 *
 * DRAFT — the three highest-value pages in the entire SEO strategy:
 *   /where-you-can-drive/            route permissions
 *   /trust/deposit-policy/           deposit amount and mechanism
 *   /trust/what-our-insurance-covers/ excess, liability limit, exclusions
 *
 * Each is created as a DRAFT with the full structure, the research rationale,
 * and explicit NEEDS: markers where a fact is required. They are NOT published,
 * because every one of them turns on a fact only the business owner and its
 * insurer know, and publishing a permission or a deposit figure that cannot be
 * honoured produces a seized deposit and a one-star review — destroying exactly
 * the trust these pages exist to build.
 *
 * Fill in the markers, delete the editorial notes, publish. The navigation picks
 * each page up automatically once it exists.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit( "Run via wp eval-file\n" );
}

function glc_seo_upsert( string $slug, string $title, string $content, string $status = 'publish', array $meta = [] ): int {
	$existing = get_page_by_path( $slug );
	if ( $existing instanceof WP_Post && 'publish' === $existing->post_status && 'draft' === $status ) {
		WP_CLI::log( "  · /{$slug}/ already published — left alone" );
		return (int) $existing->ID;
	}
	$id = wp_insert_post( [
		'ID'           => $existing->ID ?? 0,
		'post_type'    => 'page',
		'post_status'  => $status,
		'post_name'    => $slug,
		'post_title'   => $title,
		'post_content' => $content,
	] );
	if ( is_wp_error( $id ) ) {
		WP_CLI::warning( "  ✗ /{$slug}/ — " . $id->get_error_message() );
		return 0;
	}
	foreach ( $meta as $key => $value ) {
		update_post_meta( $id, $key, $value );
	}
	WP_CLI::log( sprintf( '  ✓ /%s/ (%s)', $slug, $status ) );
	return (int) $id;
}

function glc_p( string $t ): string { return "<!-- wp:paragraph --><p>{$t}</p><!-- /wp:paragraph -->"; }
function glc_h( string $t, int $l = 2 ): string { return "<!-- wp:heading {\"level\":{$l}} --><h{$l} class=\"wp-block-heading\">{$t}</h{$l}><!-- /wp:heading -->"; }
function glc_note( string $t ): string {
	// Editor-only note. Delete before publishing.
	return "<!-- wp:paragraph {\"className\":\"glc-editor-note\"} --><p class=\"glc-editor-note\"><strong>EDITOR NOTE — delete before publishing.</strong> {$t}</p><!-- /wp:paragraph -->";
}
function glc_needs( string $t ): string {
	return "<!-- wp:paragraph --><p><mark>NEEDS: {$t}</mark></p><!-- /wp:paragraph -->";
}
function glc_ul( array $items ): string {
	$li = implode( '', array_map( fn( $i ) => "<!-- wp:list-item --><li>{$i}</li><!-- /wp:list-item -->", $items ) );
	return "<!-- wp:list --><ul class=\"wp-block-list\">{$li}</ul><!-- /wp:list -->";
}

WP_CLI::log( '' );
WP_CLI::log( '=== Geolander SEO pages ===' );

/* ------------------------------------------------- PUBLISHED: hub pages */

WP_CLI::log( '' );
WP_CLI::log( 'Published (built only from existing facts):' );

$cities      = get_posts( [ 'post_type' => 'city', 'posts_per_page' => -1, 'orderby' => 'menu_order', 'order' => 'ASC' ] );
$city_items  = [];
foreach ( $cities as $city ) {
	$air  = class_exists( 'GLC_City' ) ? GLC_City::airport( $city->ID ) : [ 'name' => '', 'code' => '' ];
	$code = $air['code'] ? ' — ' . trim( $air['name'] . ' (' . $air['code'] . ')' ) : '';
	$city_items[] = sprintf(
		'<a href="%s">%s</a>%s',
		esc_url( get_permalink( $city ) ),
		esc_html( get_the_title( $city ) ),
		esc_html( $code )
	);
}

glc_seo_upsert(
	'car-rental',
	'Car Rental Locations in Georgia',
	glc_p( 'We deliver free to every city below, and to the airport serving it. Pick the city you are arriving in — each page covers the local roads, the airport handover and the vehicles that suit the region.' )
	. ( $city_items ? glc_ul( $city_items ) : '' )
	. glc_p( 'Not on the list? Ask on WhatsApp — delivery outside these cities is often possible and we will tell you honestly if it is not.' ),
	'publish'
);

$guides = get_posts( [
	'post_type'      => 'page',
	'posts_per_page' => -1,
	'meta_key'       => 'glc_guide_route',
	'meta_compare'   => 'EXISTS',
] );
$guide_items = array_map(
	fn( $g ) => sprintf( '<a href="%s">%s</a>', esc_url( get_permalink( $g ) ), esc_html( get_the_title( $g ) ) ),
	$guides
);

glc_seo_upsert(
	'guides',
	'Driving Guides for Georgia',
	glc_p( 'Route notes written by the people who maintain the cars that drive them — road surface, seasonal access, what your vehicle actually needs.' )
	. ( $guide_items ? glc_ul( $guide_items ) : glc_p( 'Guides are being published — check back shortly.' ) ),
	'publish'
);

/* ------------------------------------------- DRAFTS: fact-blocked pages */

WP_CLI::log( '' );
WP_CLI::log( 'Drafts (blocked on facts only the business can supply):' );

/* 1 — the flagship */
$roads = [
	'Tusheti / Abano Pass', 'Omalo', 'Shatili &amp; Upper Khevsureti', 'Truso Valley',
	'Juta', 'Vashlovani Protected Areas', 'Mestia–Ushguli–Lentekhi', 'Goderdzi Pass',
	'Zekari Pass', 'Sairme–Abastumani', 'Gergeti Trinity access track',
];

glc_seo_upsert(
	'where-you-can-drive',
	'Where You Can Drive a Geolander Car',
	glc_note(
		'This is the highest-value page identified in the SEO research and it is deliberately unpublished. '
		. 'Every competitor either forbids these roads, lists the prohibitions without resolving them, or stays silent. '
		. 'Localrent restricts renters to paved roads and names seven forbidden regions; the leading travel blog publishes '
		. 'the restricted list and its own readers ask in the comments who will actually let them drive it. '
		. 'One traveller wrote: "The owner refused to let us drive that route, so we canceled and booked a Mitsubishi '
		. 'Outlander from Martyna z Gruzji instead." That is a booking lost to a policy question. '
		. '<br><br><strong>Do not publish a permission you cannot honour.</strong> The failure mode is a seized deposit '
		. 'and a one-star review, which destroys the trust asset the whole strategy is built on. Confirm each road with '
		. 'your insurer first.'
	)
	. glc_p( 'Most rental companies in Georgia quietly forbid the roads people actually come here to drive. We would rather tell you exactly where our cars may go, in writing, before you book.' )
	. glc_h( 'Road by road' )
	. glc_needs( 'For each road below: permitted yes / no / conditional · which of our vehicles · whether insurance applies · the months it is open. Replace this list with a table carrying those four columns.' )
	. glc_ul( $roads )
	. glc_h( 'What "permitted" means for your insurance' )
	. glc_needs( 'State plainly whether cover continues on a permitted unpaved road, and what changes if it does not.' )
	. glc_h( 'Do we use GPS trackers?' )
	. glc_needs( 'Answer yes or no. Travellers already assume every Georgian rental car is tracked, so silence reads as a yes.' )
	. glc_h( 'If you are not sure, ask us before you book' )
	. glc_p( 'Message us on WhatsApp with your route and dates. We will tell you which car is right for it, and we will tell you if the answer is that you should hire a driver instead.' ),
	'draft',
	[ 'glc_seo_title_en' => 'Where You Can Drive a Geolander Car — Georgia Road Permissions &amp; Insurance' ]
);

/* 2 — deposit */
glc_seo_upsert(
	'trust/deposit-policy',
	'Our Deposit Policy, in Writing',
	glc_note(
		'Every negative review found in the customer research was a deposit story, not an accident story. '
		. 'Eleven Tbilisi competitors put "No Deposit, No Credit Card" in their title tags and none of the ones '
		. 'inspected backs the claim with a process — and Localrent has robots-blocked its own terms page, so the '
		. 'market leader has disqualified itself from this entire query set. '
		. '<br><br>The differentiator here is not the amount. It is the enumerated list of the only reasons money is '
		. 'ever kept, plus the handover protocol.'
	)
	. glc_needs( 'The deposit amount, or a plain statement that there is none.' )
	. glc_needs( 'Cash or card · held or charged · the exact release timeline.' )
	. glc_h( 'The only reasons we would keep any of it' )
	. glc_needs( 'An enumerated, exhaustive list. A vague "approved roads" clause is precisely how travellers report being caught out.' )
	. glc_h( 'How we hand the car over' )
	. glc_p( 'We photograph and video the car with you at pickup and again at return, and send you the file on WhatsApp. Travellers already advise each other to record a video at pickup — we would rather do it for you, so the evidence is in your phone, not only ours.' )
	. glc_h( 'Traffic fines' )
	. glc_needs( 'Do you hold the deposit until fines clear? Is there an admin fee? How and when are customers notified, and what evidence do you send?' )
	. glc_h( 'If you disagree with a charge' )
	. glc_needs( 'A named person and a response time.' ),
	'draft',
	[ 'glc_seo_title_en' => 'Our Deposit Policy, in Writing — Geolander Car Rental Tbilisi' ]
);

/* 3 — insurance */
glc_seo_upsert(
	'trust/what-our-insurance-covers',
	'What Our Insurance Actually Covers',
	glc_note(
		'"Full insurance" is the exact phrase travellers say betrayed them — and it is currently the phrase on the '
		. 'homepage, while /terms/ section 2 says CDW <em>with a deductible</em> and full cover for an extra fee. '
		. 'This page has to resolve that contradiction, and /terms/ must be rewritten to match it. '
		. 'Competitors publish concrete figures ("$100 deductible… civil liability $30,000"); we publish an adjective.'
	)
	. glc_h( 'What is included as standard' )
	. glc_needs( 'The exact cover included in the headline price.' )
	. glc_h( 'The excess, as a number' )
	. glc_needs( 'The deductible in USD or GEL.' )
	. glc_h( 'Third-party liability limit, as a number' )
	. glc_needs( 'The cap. One traveller compared Georgia\'s limit against EU norms and could not get a straight answer from anyone in the market.' )
	. glc_h( 'What is not covered' )
	. glc_needs( 'Name the exclusions travellers actually get caught by: tyres, alloys, windscreen and glass, underbody, roof, interior, single-vehicle accidents, off-contract roads.' )
	. glc_h( 'What voids cover entirely' )
	. glc_needs( 'Be specific, and cross-link the road permissions page.' )
	. glc_h( 'If you have an accident' )
	. glc_needs( 'Step by step, including whether police attendance is mandatory and who to call first.' ),
	'draft',
	[ 'glc_seo_title_en' => 'What Our Insurance Actually Covers — Excess, Limits &amp; Exclusions | Geolander' ]
);

WP_CLI::log( '' );
WP_CLI::log( 'Done. The three drafts are the highest-value pages in the strategy —' );
WP_CLI::log( 'they need facts, not copywriting. Fill the NEEDS: markers, delete the' );
WP_CLI::log( 'editor notes, publish. Navigation picks them up automatically.' );
WP_CLI::log( '' );
