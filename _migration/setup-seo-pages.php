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
function glc_route_table( array $roads ): string {
	$rows = implode( '', array_map(
		fn( $road ) => "<tr><td>{$road}</td><td>Permitted unless bad weather, an official closure, or road damage makes the route unsafe or unavailable</td><td>All Geolander vehicles</td><td>Included</td><td>No rental-season limit; live road conditions control access</td></tr>",
		$roads
	) );
	return '<!-- wp:table --><figure class="wp-block-table"><table><thead><tr><th>Road</th><th>Permission</th><th>Vehicles</th><th>Insurance</th><th>When</th></tr></thead><tbody>' . $rows . '</tbody></table></figure><!-- /wp:table -->';
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
	glc_p( 'Choose your arrival point below. Tbilisi office and Tbilisi Airport handovers are free. Kutaisi Airport costs $68 each way and Batumi costs $98 each way; the booking quote shows pickup and return separately.' )
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
	. glc_route_table( $roads )
	. glc_h( 'What "permitted" means for your insurance' )
	. glc_p( 'Insurance continues on permitted routes. Do not proceed when bad weather, an official road closure, or road damage makes the route unsafe or unavailable.' )
	. glc_p( '<em>Policy confirmed by the business owner on 15 August 2026. Road status can change after publication.</em>' )
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
	. glc_p( '<strong>There is no security deposit.</strong> Geolander does not take a cash deposit, charge a card deposit, or place a preauthorization hold.' )
	. glc_h( 'The booking prepayment is separate' )
	. glc_p( 'A prepayment equal to <strong>10% of the total rental price</strong> confirms the booking. If the booking is cancelled at least 30 days before the rental starts, 50% of that prepayment is refunded. With fewer than 30 days remaining, the prepayment is non-refundable.' )
	. glc_h( 'The only reasons we would keep any of it' )
	. glc_p( 'There is no deposit to retain. Traffic fines or losses caused by an excluded use are separate obligations under the rental terms; they are not deductions from a held deposit.' )
	. glc_h( 'How we hand the car over' )
	. glc_needs( 'Confirm whether Geolander photographs or videos the car at pickup and return, whether the customer receives the files, and how existing damage is acknowledged.' )
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
		. 'The owner has now confirmed the deductible and liability limit, and /terms/ is rewritten by setup-pages.php to match. '
		. 'Competitors publish concrete figures ("$100 deductible… civil liability $30,000"); we publish an adjective.'
	)
	. glc_h( 'What is included as standard' )
	. glc_p( 'Full insurance is included in the rental price. Single-vehicle accidents are covered.' )
	. glc_h( 'The excess, as a number' )
	. glc_p( 'There is no insurance deductible.' )
	. glc_h( 'Third-party liability limit, as a number' )
	. glc_p( 'Third-party liability cover is limited to <strong>30,000 GEL</strong>.' )
	. glc_h( 'What is not covered' )
	. glc_needs( 'Clarify the owner\'s phrase "no interior is covered": does it mean interior damage is covered or excluded? Also confirm tyres, alloys, windscreen/glass, underbody, and roof.' )
	. glc_h( 'What voids cover entirely' )
	. glc_ul( [
		'Driving in the wrong lane',
		'Running a red light',
		'Speeding',
		'Failing to inform Geolander of the location of an incident',
	] )
	. glc_p( 'Route cover also cannot be relied on during bad weather, an official road closure, or damaged-road conditions.' )
	. glc_h( 'If you have an accident' )
	. glc_needs( 'Step by step, including whether police attendance is mandatory and who to call first.' ),
	'draft',
	[ 'glc_seo_title_en' => 'What Our Insurance Actually Covers — Excess, Limits &amp; Exclusions' ]
);

WP_CLI::log( '' );
WP_CLI::log( 'Done. The three drafts are the highest-value pages in the strategy —' );
WP_CLI::log( 'they need facts, not copywriting. Fill the NEEDS: markers, delete the' );
WP_CLI::log( 'editor notes, publish. Navigation picks them up automatically.' );
WP_CLI::log( '' );
