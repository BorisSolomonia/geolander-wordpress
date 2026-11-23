<?php
/**
 * Stand up the news section and file the first article.
 *
 *   wp eval-file /migration/setup-news.php dry-run --allow-root   # report only
 *   wp eval-file /migration/setup-news.php --allow-root           # apply
 *
 * Note the positional `dry-run`: wp-cli parses any dash-prefixed argument as one
 * of its own and rejects `--dry-run`.
 *
 * Three jobs, each idempotent:
 *
 *  1. VACATE `/blog/`. An empty placeholder page holds the slug. The custom post
 *     type's archive wants it. The page is re-slugged and drafted rather than
 *     deleted — the URL itself is never lost, it simply starts serving the real
 *     archive, and a redirect covers the moved page.
 *  2. RETIRE `hello-world`. WordPress's default post is still published on the
 *     live site. It is drafted and 301'd, never deleted.
 *  3. FILE the TAEMIN article as a DRAFT. Every event fact below came from
 *     taemintour.com, the official tour site, on 2026-09-16. The offer is left
 *     PENDING on purpose: no discount has been agreed, and inventing one is
 *     barred by prime directive 0.1. The article is complete and publishable the
 *     moment real terms exist.
 */

defined( 'WP_CLI' ) || exit;

$dry = in_array( 'dry-run', (array) ( $args ?? [] ), true );
$say = function ( string $msg ) use ( $dry ) { WP_CLI::log( ( $dry ? '[dry-run] ' : '' ) . $msg ); };

if ( ! class_exists( 'GLC_News' ) ) {
	WP_CLI::error( 'GLC_News is not loaded — is the plugin active and at version 1.7.0 or later?' );
}

/* 1 ------------------------------------------------------------ vacate /blog/ */

$blog_page = get_page_by_path( 'blog', OBJECT, 'page' );
if ( $blog_page ) {
	/*
	 * "Empty" has to mean empty of AUTHORED content, not empty of markup. The page
	 * found here held a single wp:query block pointed at native posts, plus a
	 * Georgian no-results string — a stub index that renders nothing because no
	 * post has ever been published. Stripping tags leaves the no-results text and
	 * makes a stub look like prose, which is why the first pass refused to move a
	 * page that a human would call blank.
	 *
	 * So: drop the query block and its fallback text, then judge what is left.
	 * A page with real prose outside that block still stops the migration.
	 */
	$authored = preg_replace( '#<!-- wp:query .*?<!-- /wp:query -->#s', '', (string) $blog_page->post_content );
	$body     = trim( wp_strip_all_tags( (string) $authored ) );
	if ( '' !== $body ) {
		WP_CLI::warning( sprintf(
			'Page %d at /blog/ is NOT empty (%d chars of content). Refusing to move it. Move it by hand, then re-run.',
			$blog_page->ID,
			strlen( $body )
		) );
	} else {
		$say( sprintf(
			'Page %d ("%s") holds no authored prose (%d bytes of stub query markup) — moving it from /blog/ to /blog-placeholder/ as a draft. Its glc_title_* translations travel with it.',
			$blog_page->ID,
			$blog_page->post_title,
			strlen( (string) $blog_page->post_content )
		) );
		if ( ! $dry ) {
			wp_update_post( [ 'ID' => $blog_page->ID, 'post_name' => 'blog-placeholder', 'post_status' => 'draft' ] );
		}
	}
} else {
	$say( 'No page holds the /blog/ slug — nothing to vacate.' );
}

/* 2 -------------------------------------------------------- retire hello-world */

$hello = get_page_by_path( 'hello-world', OBJECT, 'post' );
if ( $hello && 'publish' === $hello->post_status ) {
	$say( sprintf( 'Post %d ("%s") is WordPress\'s default and is still live — drafting it.', $hello->ID, $hello->post_title ) );
	if ( ! $dry ) {
		wp_update_post( [ 'ID' => $hello->ID, 'post_status' => 'draft' ] );
	}
} else {
	$say( 'hello-world is already gone or already a draft.' );
}

/* 3 ----------------------------------------------------------- the first article */

$slug = 'taemin-liminal-tbilisi-december-2026';
$existing = get_page_by_path( $slug, OBJECT, GLC_News::POST_TYPE );

// Facts. Every one of these is in the sources list below; none is inferred.
$meta = [
	'glc_news_event_name'     => 'TAEMIN 2026-27 World Tour: LiMiNaL',
	'glc_news_event_start'    => '2026-12-09',
	'glc_news_venue'          => 'Tbilisi Sports Palace',
	'glc_news_venue_address'  => '26 May Square 1, Tbilisi',
	'glc_news_city'           => 'Tbilisi',
	'glc_news_event_url'      => 'https://parklive.world/shows/taemin-tbilisi',
	'glc_news_verified_on'    => '2026-09-16',
	'glc_news_sources'        => implode( "\n", [
		'TAEMIN official tour site — tour dates | https://taemintour.com',
		'ParkLive — Tbilisi show ticket page | https://parklive.world/shows/taemin-tbilisi',
		'Tbilisi Sports Palace — venue | https://en.wikipedia.org/wiki/Tbilisi_Sports_Palace',
	] ),
	'glc_news_answer'         => 'TAEMIN plays Tbilisi Sports Palace on 9 December 2026, as part of the 2026-27 LiMiNaL world tour. Tickets are on sale through ParkLive, linked from the official tour site. The venue is in central Tbilisi at 26 May Square.',
	'glc_news_faq'            => implode( "\n", [
		'When and where is the TAEMIN concert in Tbilisi? | 9 December 2026 at Tbilisi Sports Palace, 26 May Square 1, in central Tbilisi.',
		'Where do I buy tickets? | Through ParkLive, the ticketing partner linked from TAEMIN\'s official tour site. Buy from that link rather than a resale listing.',
		'Do I need a car to get to the concert? | No. The venue is central and reachable on foot or by metro from most of the city. A car earns its keep if you are adding days outside Tbilisi around the show, not for the concert itself.',
		'What is driving in Georgia like in December? | Winter conditions, and mountain passes can close at short notice. Our winter driving guide covers what changes and what to ask before you book.',
		'Can I rent for just the concert weekend? | Yes, though short December rentals are the most expensive way to buy days. Ask us on WhatsApp and we will quote both the short and the longer option so you can compare.',
	] ),
	// PENDING, not NONE: an offer is intended, its terms are not agreed.
	'glc_news_offer_status'   => GLC_News::OFFER_PENDING,
	'glc_news_related_pages'  => '/driving-in-georgia-in-winter/, /car-rental-tbilisi/, /fleet/',
];

$content = <<<'HTML'
<!-- wp:paragraph -->
<p>TAEMIN brings the LiMiNaL tour to Tbilisi on 9 December 2026. For anyone flying in for it, the concert is the easy part to plan. What usually catches people out is everything around it: a December arrival in Georgia, a short winter weekend, and the question of whether to bother with a car at all.</p>
<!-- /wp:paragraph -->

<!-- wp:heading -->
<h2>Getting to the venue</h2>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>Tbilisi Sports Palace sits at 26 May Square, in the middle of the city. If you are staying anywhere central you will not need to drive to the show, and on a concert night you would not want to. Walk, take the metro, or take a taxi.</p>
<!-- /wp:paragraph -->

<!-- wp:heading -->
<h2>Whether a car is worth it</h2>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>Honestly: not for the concert. A car is worth it if the concert is the reason for the trip but not the whole of it. December in Georgia is the quiet season, which is exactly when the country is most worth driving through — Kakheti's wine roads without the crowds, the Gudauri road up toward Kazbegi when the weather allows, day trips that are impossible to reach on a tour bus schedule.</p>
<!-- /wp:paragraph -->

<!-- wp:paragraph -->
<p>If you are staying in Tbilisi for three days and leaving, skip it. If you are here for a week, a car changes the trip.</p>
<!-- /wp:paragraph -->

<!-- wp:heading -->
<h2>What December driving actually means</h2>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>Winter is a real constraint here, not a footnote. Mountain passes close at short notice, the weather turns faster than the forecast, and which roads you are permitted to drive can depend on conditions on the day. None of that makes a winter trip a bad idea — it makes asking the right questions before you book important. Our winter driving guide covers what changes and what to confirm with any rental company, not just us.</p>
<!-- /wp:paragraph -->

<!-- wp:paragraph -->
<p>One practical note on cost. A two or three day rental over a concert weekend is the most expensive way to buy rental days, in December more than any other month. If your dates are flexible, ask for both quotes. The longer one is usually better value per day by a wide margin, and we would rather tell you that than not.</p>
<!-- /wp:paragraph -->
HTML;

if ( $existing ) {
	$say( sprintf( 'Article already exists as post %d (%s) — refreshing its fields only, never its status.', $existing->ID, $existing->post_status ) );
	$post_id = $existing->ID;
	if ( ! $dry ) {
		foreach ( $meta as $k => $v ) { update_post_meta( $post_id, $k, $v ); }
	}
} else {
	$say( sprintf( 'Creating DRAFT article "%s" at /%s/%s/.', 'TAEMIN in Tbilisi', GLC_News::SLUG, $slug ) );
	if ( ! $dry ) {
		$post_id = wp_insert_post( [
			'post_type'    => GLC_News::POST_TYPE,
			'post_status'  => 'draft',
			'post_name'    => $slug,
			'post_title'   => 'TAEMIN in Tbilisi, 9 December 2026: what to know, and whether you need a car',
			'post_content' => $content,
			'post_excerpt' => 'TAEMIN plays Tbilisi Sports Palace on 9 December 2026 on the LiMiNaL tour. Where it is, how to get there, and an honest answer on whether renting a car is worth it.',
		], true );
		if ( is_wp_error( $post_id ) ) { WP_CLI::error( $post_id->get_error_message() ); }
		foreach ( $meta as $k => $v ) { update_post_meta( $post_id, $k, $v ); }
		update_post_meta( $post_id, 'glc_seo_title_en', 'TAEMIN Tbilisi Concert, 9 December 2026 — Venue, Tickets, Getting There' );
		update_post_meta( $post_id, 'glc_seo_description_en', 'TAEMIN plays Tbilisi Sports Palace on 9 December 2026 (LiMiNaL tour). Venue, official tickets, how to get there, and whether a rental car is worth it in December.' );
		WP_CLI::log( sprintf( 'Created post %d as a DRAFT. It will not appear publicly until you publish it.', $post_id ) );
	}
}

if ( ! $dry ) {
	flush_rewrite_rules( false );
	WP_CLI::log( 'Rewrite rules flushed.' );
}

WP_CLI::success( $dry
	? 'Dry run complete. Nothing was written.'
	: 'News section is set up. The TAEMIN article is a DRAFT with its offer PENDING — supply the offer terms, set the status to Active, then publish.' );
