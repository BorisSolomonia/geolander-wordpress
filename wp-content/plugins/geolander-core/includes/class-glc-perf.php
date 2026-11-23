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
 *  - robots.txt (crawler policy changes must become visible immediately)
 *  - AI pseudo-files such as /llms.txt, /pricing.md, and /openapi.json
 *    (they set their own headers)
 *  - car/fleet pages carrying ?from&to (live seasonal quotes vary by dates)
 *  - 404s (short cache only)
 *
 * Pair with a CDN rule that bypasses cache for /wp-admin, /wp-json, /wp-login,
 * from/to quote queries and the `wordpress_logged_in` cookie (see docs/DEPLOYMENT.md).
 */

defined( 'ABSPATH' ) || exit;

class GLC_Perf {

	/** Public TTL for a browser; CDN edge TTL via s-maxage. */
	private const MAXAGE   = 300;    // 5 min at the browser
	private const S_MAXAGE = 86400;  // 1 day at the edge
	private const REVISION_LIMIT = 5;
	private const CLEANUP_VERSION = 1;

	public static function init() {
		add_action( 'init', [ __CLASS__, 'cleanup_legacy_runtime_state' ], 1 );
		add_action( 'send_headers', [ __CLASS__, 'cache_headers' ] );
		add_filter( 'wp_revisions_to_keep', [ __CLASS__, 'revision_limit' ] );
	}

	/** Remove the attacker-amplifiable per-IP transient format once. */
	public static function cleanup_legacy_runtime_state(): void {
		if ( (int) get_option( 'glc_runtime_cleanup_version', 0 ) >= self::CLEANUP_VERSION ) {
			return;
		}
		global $wpdb;
		$value_prefix   = $wpdb->esc_like( '_transient_glc_rl_' ) . '%';
		$timeout_prefix = $wpdb->esc_like( '_transient_timeout_glc_rl_' ) . '%';
		$wpdb->query( $wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
			$value_prefix,
			$timeout_prefix
		) );
		update_option( 'glc_runtime_cleanup_version', self::CLEANUP_VERSION, false );
	}

	/** Keep editor history useful without allowing revision tables to grow forever. */
	public static function revision_limit( int $limit ): int {
		return self::REVISION_LIMIT;
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
		// A stale edge copy can keep newly allowed agents blocked for a full day.
		// robots.txt is tiny and infrequently requested, so always serve it fresh.
		if ( get_query_var( 'robots' ) ) {
			header( 'Cache-Control: no-store, max-age=0' );
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

		// Every page, the unprefixed front page "/" included, is a pure function of
		// its URL: GLC_I18n takes the language from the path only and never
		// redirects by cookie or Accept-Language, so "/" is cacheable like the rest.
		header( sprintf( 'Cache-Control: public, max-age=%d, s-maxage=%d', self::MAXAGE, self::S_MAXAGE ) );
	}
}
