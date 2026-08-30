<?php
/**
 * Publish the trust and developer-discovery pages needed by agents.
 *
 * Run: wp eval-file /migration/setup-agent-readiness.php
 * Idempotent by slug. Uses only owner-confirmed facts already present in the
 * project; it does not invent an owner biography, registration number, review
 * count, tax inclusion, webhook, MCP server, or authentication system.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit( "Run via wp eval-file\n" );
}

function glc_agent_upsert( string $slug, string $title, string $content, array $meta = [] ): int {
	$existing = get_page_by_path( $slug );
	$id = wp_insert_post( [
		'ID'           => $existing->ID ?? 0,
		'post_type'    => 'page',
		'post_status'  => 'publish',
		'post_name'    => $slug,
		'post_title'   => $title,
		'post_content' => $content,
	] );
	if ( is_wp_error( $id ) ) {
		WP_CLI::error( "Could not publish /{$slug}/: " . $id->get_error_message() );
	}
	foreach ( $meta as $key => $value ) {
		update_post_meta( $id, $key, $value );
	}
	WP_CLI::log( "  ✓ /{$slug}/" );
	return (int) $id;
}

function glc_agent_p( string $text ): string {
	return "<!-- wp:paragraph --><p>{$text}</p><!-- /wp:paragraph -->";
}

function glc_agent_h( string $text ): string {
	return "<!-- wp:heading --><h2 class=\"wp-block-heading\">{$text}</h2><!-- /wp:heading -->";
}

function glc_agent_ul( array $items ): string {
	$list = implode( '', array_map( fn( $item ) => "<!-- wp:list-item --><li>{$item}</li><!-- /wp:list-item -->", $items ) );
	return "<!-- wp:list --><ul class=\"wp-block-list\">{$list}</ul><!-- /wp:list -->";
}

WP_CLI::log( 'Publishing Geolander agent-readiness pages:' );

$about = glc_agent_p( '<strong>Geolander car rental</strong> is based at 8/5 Vedzini Street in Mtatsminda, in the heart of Tbilisi, Georgia (country). The office is linked to the company\'s verified Google Maps location. Geolander rents individually listed AWD and 4x4 vehicles to international travellers exploring Tbilisi, Kazbegi, Gudauri, Kakheti, Svaneti and other parts of Georgia.' )
	. glc_agent_h( 'Business identity and official channels' )
	. '<!-- wp:shortcode -->[geolander_business_identity]<!-- /wp:shortcode -->'
	. glc_agent_h( 'Exact cars, real photographs' )
	. glc_agent_p( 'Each fleet page represents a specific vehicle with its own model year, photographs and registration plate where available. The exact car booked is the car supplied; the fleet is not presented as an “or similar” category. Customers select dates and handover locations on the vehicle page, receive a server-calculated quote, and continue through a prepared WhatsApp booking request.' )
	. glc_agent_h( 'Rental terms customers can verify before booking' )
	. glc_agent_p( 'Full insurance is included with no deductible. Wheels and windshield are covered; tyres are excluded. Mileage is unlimited within Georgia, winter tyres are included free in winter, and there is no cash security deposit, card deposit, or preauthorization hold. A 10% prepayment confirms a booking after availability is checked. If a customer cancels at least 30 days before pickup, 50% of the prepayment is refunded; with fewer than 30 days remaining, the prepayment is non-refundable.' )
	. glc_agent_h( 'Office, airport handovers and contact' )
	. glc_agent_p( 'Tbilisi office and Tbilisi International Airport handovers are free. Kutaisi Airport costs $68 for pickup and $68 for return. Batumi Airport costs $98 for pickup and $98 for return. The live quote displays pickup and return charges separately. Geolander is available by phone and WhatsApp at <a href="tel:+995551330414">+995 551 33 04 14</a> and by email at <a href="mailto:info@geo-lander.com">info@geo-lander.com</a>.' )
	. glc_agent_p( '<a href="https://maps.app.goo.gl/XuY47hmvdEau9HoS9" rel="noopener">Open the Geolander car rental office on Google Maps</a> or <a href="/contact/">view all contact options</a>.' );

glc_agent_upsert(
	'about',
	'About Geolander Car Rental',
	$about,
	[
		'glc_seo_title_en'       => 'About Geolander Car Rental in Tbilisi, Georgia',
		'glc_seo_description_en' => 'Verify Geolander car rental: exact vehicles, transparent insurance and deposit terms, Mtatsminda office, airport handover charges, and direct contact details.',
	]
);

$developers = glc_agent_p( 'Geolander publishes a small public reservation API used by the booking form on geo-lander.com. It supports read-only, date-specific quotes and a customer-approved booking-request handoff. The API does not confirm vehicle availability, accept payment, expose customer records, or provide webhooks. Geolander also operates OAuth-protected read-only MCP and A2A interfaces for verified fleet, policy, and quote retrieval.' )
	. glc_agent_h( 'Machine-readable resources' )
	. glc_agent_ul( [
		'<a href="/openapi.json">Geolander Reservation API — OpenAPI 3.1 specification</a>',
		'<a href="/.well-known/api-catalog">Geolander API catalog — RFC 9727 Linkset discovery</a>',
		'<a href="/.well-known/oauth-authorization-server">Geolander Agent API — RFC 8414 OAuth discovery</a>',
		'<a href="/.well-known/oauth-protected-resource">Geolander Agent API — RFC 9728 protected-resource metadata</a>',
		'<a href="/auth.md">Authentication guide for approved agent clients</a>',
		'<a href="/.well-known/ai-catalog.json">Geolander ARD capability catalog</a>',
		'<a href="/.well-known/agent-skills/index.json">Geolander Agent Skills discovery index</a>',
		'<a href="/.well-known/mcp/server-card.json">Geolander Rental Tools MCP server card</a>',
		'<a href="/.well-known/agent-card.json">Geolander Rental Agent — A2A v1.0 Agent Card</a>',
		'<a href="/.well-known/http-message-signatures-directory">Geolander Web Bot Auth public key directory</a>',
		'<a href="/agent-instructions.md">Agent instructions and when-to-use guidance</a>',
		'<a href="/llms.txt">Geolander site and fleet context for language models</a>',
		'<a href="/pricing.md">Live machine-readable fleet price tables</a>',
		'<a href="/wp-json/">WordPress REST API index</a>',
	] )
	. glc_agent_h( 'Authentication and safe use' )
	. glc_agent_p( 'The customer-facing <code>/wp-json/geolander/v1/</code> routes do not require an account. Authenticated clients operated by Geolander team members use the mirrored <code>/wp-json/geolander-agent/v1/</code> routes through Cloudflare Access Managed OAuth. The MCP endpoint at <code>/wp-json/geolander-agent/v1/mcp</code> and A2A v1.0 JSON-RPC endpoint at <code>/wp-json/geolander-agent/v1/a2a</code> expose read-only fleet, policy, and quote capabilities. REST quote is read-only. REST checkout is rate-limited and creates an internal booking request, so an agent must obtain explicit user approval and a valid customer name and email before calling it. The returned WhatsApp link continues the conversation with Geolander staff. A booking request is not a confirmed reservation; staff confirm availability and the 10% prepayment separately.' )
	. glc_agent_h( 'A2A request format' )
	. glc_agent_p( 'A2A clients send JSON-RPC 2.0 <code>SendMessage</code> requests with <code>A2A-Version: 1.0</code>. Use a structured <code>application/json</code> data part whose <code>skill</code> is <code>fleet-discovery</code>, <code>rental-policy</code>, or <code>rental-quote</code>. The quote skill also needs a published car ID, <code>from</code> and <code>to</code> dates, plus pickup and return location identifiers. The response is an immediate A2A agent Message with both a text summary and structured data.' )
	. glc_agent_h( 'Signed outbound agent requests' )
	. glc_agent_p( 'Geolander-operated WordPress agents opt in to IETF Web Bot Auth by passing <code>glc_web_bot_auth =&gt; true</code> to <code>wp_remote_get()</code> or <code>wp_remote_post()</code>, or by using a <code>Geolander-Agent/</code> User-Agent. The HTTP client then adds <code>Signature-Agent</code>, <code>Signature-Input</code>, and <code>Signature</code> headers. The Ed25519 private key is held in the Railway secret <code>GLC_WEB_BOT_AUTH_PRIVATE_KEY</code>; only its public JWK is published.' )
	. glc_agent_h( 'Support' )
	. glc_agent_p( 'Questions about the API or booking data should be sent to <a href="mailto:info@geo-lander.com">info@geo-lander.com</a>. Do not send SMTP keys, payment credentials, passport data, or other secrets through public API fields.' );

glc_agent_upsert(
	'developers',
	'Geolander Developer Resources',
	$developers,
	[
		'glc_seo_title_en'       => 'Geolander Developer Resources — Reservation API & OpenAPI',
		'glc_seo_description_en' => 'Geolander Reservation API documentation, OpenAPI 3.1 specification, agent instructions, live fleet prices, authentication notes, and safe-use guidance.',
	]
);

WP_CLI::success( 'Agent-readiness pages published.' );
