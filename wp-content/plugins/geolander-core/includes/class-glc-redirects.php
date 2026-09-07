<?php
/**
 * Retired URLs: 301 map, sitemap exclusion, and a noindex list.
 *
 * House rule (CLAUDE.md): never delete a URL — 301 it to the survivor. Google
 * had indexed the WordPress placeholders (/hello-world/, /sample-page/), the
 * Georgian-music page, and a /contact-1/ duplicate that now 404s while still
 * collecting impressions (Search Console, 2026-09-01). Each now answers with a
 * permanent redirect to the page that should have held that equity, and none
 * of them can reappear in wp-sitemap.
 *
 * Nothing here is hardcoded for good: the seed map below is the default, and
 * Settings → Geolander → "Search engines" lets the owner add or change lines
 * ("/old/ -> /new/", one per line) and the noindex slug list without a deploy.
 */

defined( 'ABSPATH' ) || exit;

class GLC_Redirects {

	/** Seed map, path → path. Overridden entirely by the `redirects` setting when set. */
	public const DEFAULT_REDIRECTS = [
		'/hello-world/' => '/guides/',
		'/sample-page/' => '/about/',
		'/contact-1/'   => '/contact/',
		// Search Console top pages (Aug 2026) that 404 today — equity with live demand.
		'/car-rental-kutaisi-airport/'    => '/car-rental-kutaisi/',   // 33 impressions
		'/car-rental-at-kutaisi-airport/' => '/car-rental-kutaisi/',   // 4
		'/car-rental-at-tbilisi-airport/' => '/car-rental-tbilisi/',   // 13
		'/car-rental/tbilisi/'            => '/car-rental-tbilisi/',
		'/car-rental/batumi/'             => '/car-rental-batumi/',
		'/blog-1/'                        => '/blog/',
		'/author/admin/'                  => '/about/',
		// The site's best-ranking commercial URL (position 9.4, 31 impressions) was a
		// comparison post that no longer exists. Parked on /about/ until an honest
		// comparison page is written — then point this line at it.
		'/blog/geolander-vs-local-rent-vs-premium-auto-rent/' => '/about/',
		'/fleet/00000000-0000-0000-0000-000000000003/' => '/fleet/',
		'/fleet/00000000-0000-0000-0000-000000000015/' => '/fleet/',
		'/places/samegrelo/'                           => '/places/',   // old region path, 2 impressions + 1 click
	];

	/**
	 * Path prefixes that are not locales but were once served (Search Console
	 * shows /he and /he/fleet with impressions from Israel). Stripped to the
	 * same path on the default locale. WordPress's own slug guess sent /he to
	 * /hello-world/, which is why redirect_guess_404_permalink is disabled below.
	 */
	public const PREFIX_REDIRECTS = [ '/he' ];

	/**
	 * Pages that stay live for visitors (linked from the footer) but should not
	 * spend crawl budget or compete in search: noindex,follow and out of the sitemap.
	 */
	public const DEFAULT_NOINDEX = [ 'music', 'blog' ]; // blog: zero real posts as of 2026-09-07 — remove when the first article ships

	public static function init(): void {
		add_action( 'template_redirect', [ __CLASS__, 'redirect' ], 0 );
		add_filter( 'wp_sitemaps_posts_query_args', [ __CLASS__, 'sitemap_exclude' ], 10, 2 );
		add_filter( 'wp_robots', [ __CLASS__, 'robots' ] );
		// No "did you mean" 301s to unrelated posts; the explicit map above decides.
		add_filter( 'redirect_guess_404_permalink', '__return_false' );
	}

	/** Active redirect map, normalised to "/path/" keys. */
	public static function map(): array {
		$raw = class_exists( 'GLC_Settings' ) ? (string) GLC_Settings::get( 'redirects', '' ) : '';
		if ( '' === trim( $raw ) ) {
			return self::DEFAULT_REDIRECTS;
		}
		$map = [];
		foreach ( preg_split( '/\r?\n/', $raw ) as $line ) {
			if ( preg_match( '/^\s*(\S+)\s*->\s*(\S+)\s*$/', $line, $m ) ) {
				$map[ self::norm( $m[1] ) ] = $m[2];
			}
		}
		return $map ?: self::DEFAULT_REDIRECTS;
	}

	/** Slugs kept out of the index. */
	public static function noindex_slugs(): array {
		$raw = class_exists( 'GLC_Settings' ) ? (string) GLC_Settings::get( 'noindex_slugs', '' ) : '';
		if ( '' === trim( $raw ) ) {
			return self::DEFAULT_NOINDEX;
		}
		return array_values( array_filter( array_map( 'trim', explode( ',', $raw ) ) ) ) ?: self::DEFAULT_NOINDEX;
	}

	private static function norm( string $path ): string {
		$path = '/' . trim( $path, '/' ) . '/';
		return '//' === $path ? '/' : $path;
	}

	/**
	 * 301 retired paths. Runs before core's canonical redirect (priority 10) so a
	 * retired URL never gets a 404 or a 302 first. GLC_I18n::boot() has already
	 * stripped any locale prefix from REQUEST_URI, and home_url() re-adds the
	 * visitor's locale, so /ru/hello-world/ lands on /ru/guides/.
	 */
	public static function redirect(): void {
		if ( is_admin() ) {
			return;
		}
		$raw  = (string) ( wp_parse_url( $_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH ) ?? '/' );
		$path = self::norm( $raw );
		$map  = self::map();
		if ( ! isset( $map[ $path ] ) ) {
			foreach ( self::PREFIX_REDIRECTS as $prefix ) {
				if ( $raw === $prefix || str_starts_with( $raw, $prefix . '/' ) ) {
					$map[ $path ] = self::norm( substr( $raw, strlen( $prefix ) ) );
					break;
				}
			}
		}
		if ( ! isset( $map[ $path ] ) ) {
			return;
		}
		$to     = $map[ $path ];
		$target = preg_match( '#^https?://#i', $to ) ? $to : home_url( self::norm( $to ) );
		nocache_headers();
		wp_redirect( esc_url_raw( $target ), 301, 'Geolander' ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect
		exit;
	}

	/** Slugs that must never be listed in wp-sitemap: every redirect source plus the noindex list. */
	private static function retired_slugs(): array {
		$slugs = self::noindex_slugs();
		foreach ( array_keys( self::map() ) as $from ) {
			$slug = trim( $from, '/' );
			if ( $slug && ! str_contains( $slug, '/' ) ) {
				$slugs[] = $slug;
			}
		}
		return array_values( array_unique( $slugs ) );
	}

	/**
	 * Drop retired posts from the sitemap provider query. Core offers no slug
	 * exclusion, so resolve slugs to IDs per post type (one cheap query, cached
	 * for the request) and hand them to post__not_in.
	 */
	public static function sitemap_exclude( array $args, string $post_type ): array {
		static $cache = [];
		if ( ! isset( $cache[ $post_type ] ) ) {
			$ids = [];
			foreach ( self::retired_slugs() as $slug ) {
				foreach ( get_posts( [
					'name'           => $slug,
					'post_type'      => $post_type,
					'post_status'    => 'any',
					'posts_per_page' => 1,
					'fields'         => 'ids',
					'no_found_rows'  => true,
				] ) as $id ) {
					$ids[] = (int) $id;
				}
			}
			$cache[ $post_type ] = $ids;
		}
		if ( $cache[ $post_type ] ) {
			$args['post__not_in'] = array_merge( (array) ( $args['post__not_in'] ?? [] ), $cache[ $post_type ] );
		}
		return $args;
	}

	/** noindex,follow on the noindex list — kept crawlable so its links still count. */
	public static function robots( array $robots ): array {
		if ( is_singular() && in_array( get_post_field( 'post_name', get_queried_object_id() ), self::noindex_slugs(), true ) ) {
			$robots['noindex'] = true;
			$robots['follow']  = true;
			unset( $robots['index'] );
		}
		return $robots;
	}
}
