<?php
/**
 * Edge/CDN cacheability.
 *
 * Railway has no built-in page cache, so a full WordPress + MySQL bootstrap
 * runs on every hit. WordPress sends no positive Cache-Control on normal
 * front-end pages, so a CDN (Cloudflare) in front won't cache the HTML unless
 * we tell it the response is public and for how long. This emits a public
 * Cache-Control on pages that are truly static per-URL, and stays silent
 * (leaving WP's default no-store behaviour) on anything dynamic or private.
 *
 * Excluded from public caching:
 *  - non-GET requests and logged-in users
 *  - the /llms.txt and /pricing.md pseudo-files (they set their own headers)
 *  - car/fleet pages carrying ?from&to (live seasonal quotes vary by dates)
 *  - 404s (short cache only)
 *  - the unprefixed front page (language negotiation makes it origin-decided)
 *
 * Pair with a CDN rule that bypasses cache when the `glc_lang` or
 * `wordpress_logged_in` cookie is present (see docs/DEPLOYMENT.md).
 */

defined( 'ABSPATH' ) || exit;

class GLC_Perf {

	/** Public TTL for a browser; CDN edge TTL via s-maxage. */
	private const MAXAGE   = 300;    // 5 min at the browser
	private const S_MAXAGE = 86400;  // 1 day at the edge

	public static function init() {
		add_action( 'send_headers', [ __CLASS__, 'cache_headers' ] );
	}

	public static function cache_headers( $wp ): void {
		// This action fires only from WP::main() on real front-end page requests,
		// so REST/cron/ajax/admin never reach it — no guards needed for those.
		// Cheapest check first; logged-in is the one live exclusion.
		if ( 'GET' !== ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) ) {
			return;
		}
		if ( is_user_logged_in() ) {
			return;
		}
		// AI pseudo-files manage their own caching.
		if ( get_query_var( 'glc_ai_file' ) ) {
			return;
		}
		// Live-quote pages must not be shared-cached under a dateless key.
		if ( isset( $_GET['from'] ) || isset( $_GET['to'] ) ) {
			header( 'Cache-Control: private, no-cache' );
			return;
		}
		if ( is_404() ) {
			header( 'Cache-Control: public, max-age=30' );
			return;
		}

		// The UNPREFIXED front page ("/") is not a pure function of its URL:
		// GLC_I18n may 302 it by cookie/Accept-Language. A shared cache (Cloudflare
		// ignores Vary on HTML) would then serve the cached English "/" to a
		// first-visit non-English browser, or to a cookie-switched visitor whose
		// Accept-Language still reads "en". So the origin must always decide "/".
		// Prefixed home pages (/ka/, /ru/…) are path-keyed and never redirect, so
		// they stay fully cacheable below.
		if ( is_front_page()
			&& ( ! class_exists( 'GLC_I18n' ) || GLC_I18n::DEFAULT_LOCALE === GLC_I18n::locale() ) ) {
			header( 'Cache-Control: private, no-cache' );
			return;
		}

		header( sprintf( 'Cache-Control: public, max-age=%d, s-maxage=%d', self::MAXAGE, self::S_MAXAGE ) );
	}
}
