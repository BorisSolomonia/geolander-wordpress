<?php
/**
 * Lightweight SEO layer: meta description, Open Graph/Twitter cards,
 * and canonical for archives. Works with core's title-tag and sitemaps.
 */

defined( 'ABSPATH' ) || exit;

class GLC_SEO {

	public static function init() {
		add_action( 'wp_head', [ __CLASS__, 'output' ], 4 );
		add_filter( 'document_title_parts', [ __CLASS__, 'title' ] );
		add_filter( 'document_title_separator', fn() => '|' );
		// Trim sitemap noise: no author archives, no unused core taxonomy.
		add_filter( 'wp_sitemaps_add_provider', fn( $provider, $name ) => 'users' === $name ? false : $provider, 10, 2 );
		add_filter( 'wp_sitemaps_taxonomies', function ( $taxonomies ) {
			unset( $taxonomies['category'], $taxonomies['post_tag'] );
			return $taxonomies;
		} );
		add_filter( 'robots_txt', [ __CLASS__, 'robots' ] );
		add_action( 'wp_head', [ __CLASS__, 'gtag' ], 8 );
	}

	/**
	 * Search-engineered titles. English (default locale) carries the
	 * commercial keywords; other locales use their catalog strings.
	 */
	public static function title( array $parts ): array {
		$en = ! class_exists( 'GLC_I18n' ) || GLC_I18n::DEFAULT_LOCALE === GLC_I18n::locale();

		if ( is_singular( 'car' ) ) {
			$price = (float) get_post_meta( get_the_ID(), 'glc_price_from', true );
			$parts['title'] = $en
				? sprintf( 'Rent %s in Tbilisi from $%d/day', get_the_title(), $price )
				: sprintf( '%s — %s · $%d%s', get_the_title(), glc_ui( 'booking_title' ), $price, glc_ui( 'from_per_day' ) );
		} elseif ( is_post_type_archive( 'car' ) ) {
			$parts['title'] = $en
				? 'Car Rental Fleet in Tbilisi, Georgia — 15 Real 4x4s from $26/day'
				: glc_ui( 'fleet_title' ) . ' — ' . glc_ui( 'fleet_subtitle' );
		} elseif ( is_post_type_archive( 'place' ) ) {
			$parts['title'] = $en
				? 'Places to Visit in Georgia by Car — 36 Destinations'
				: glc_ui( 'places_title' ) . ' — ' . glc_ui( 'places_subtitle' );
		} elseif ( is_front_page() ) {
			// Front page has no separate 'site' part — brand goes inline.
			$parts['title'] = $en
				? 'Car Rental in Tbilisi, Georgia — 4x4 from $26/day | Geolander'
				: glc_ui( 'hero_title' ) . ' | Geolander';
			unset( $parts['tagline'] );
		}
		return $parts;
	}

	/**
	 * robots.txt: point crawlers at the sitemap and explicitly welcome
	 * the AI crawlers behind ChatGPT, Claude, Perplexity, and Gemini
	 * grounding — AI answer visibility is a business channel here.
	 */
	public static function robots( string $output ): string {
		$ai = '';
		foreach ( [ 'GPTBot', 'OAI-SearchBot', 'ChatGPT-User', 'ClaudeBot', 'Claude-SearchBot', 'PerplexityBot', 'Google-Extended', 'CCBot' ] as $bot ) {
			$ai .= "\nUser-agent: {$bot}\nAllow: /\n";
		}
		return $output . $ai;
	}

	/** Google tag (GA4 + Ads) — renders only when IDs are configured. */
	public static function gtag(): void {
		$ga4 = GLC_Settings::get( 'ga4_id' );
		$ads = GLC_Settings::get( 'ads_id' );
		if ( ! $ga4 && ! $ads ) {
			return;
		}
		$primary = $ga4 ?: $ads;
		printf( "<script async src=\"https://www.googletagmanager.com/gtag/js?id=%s\"></script>\n", esc_attr( $primary ) );
		echo "<script>window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments);}gtag('js',new Date());";
		if ( $ga4 ) {
			printf( "gtag('config','%s');", esc_js( $ga4 ) );
		}
		if ( $ads ) {
			printf( "gtag('config','%s');", esc_js( $ads ) );
		}
		echo "</script>\n";
	}

	private static function description(): string {
		if ( is_singular() ) {
			$post = get_queried_object();
			$text = $post->post_excerpt ?: wp_strip_all_tags( $post->post_content );
			if ( is_singular( 'car' ) ) {
				$price = get_post_meta( $post->ID, 'glc_price_from', true );
				$text  = sprintf(
					'Rent a %s in Tbilisi from $%s/day. 4x4, full insurance, free airport delivery. %s',
					get_the_title( $post ),
					number_format( (float) $price, 0 ),
					$text
				);
			}
		} elseif ( is_post_type_archive( 'car' ) ) {
			$text = glc_ui( 'fleet_title' ) . ' — ' . glc_ui( 'fleet_subtitle' ) . ' ' . glc_ui( 'trust_insurance' ) . ', ' . glc_ui( 'trust_delivery' ) . '.';
		} elseif ( is_post_type_archive( 'place' ) ) {
			$text = glc_ui( 'places_subtitle' ) . ' — ' . glc_ui( 'route_1' ) . ', ' . glc_ui( 'route_2' ) . ', ' . glc_ui( 'route_3' ) . ', ' . glc_ui( 'route_4' ) . '.';
		} elseif ( is_front_page() ) {
			$text = glc_ui( 'hero_subtitle' ) . ' — Geolander, Tbilisi.';
		} else {
			$text = get_bloginfo( 'description' );
		}
		return wp_html_excerpt( trim( $text ), 158, '…' );
	}

	public static function output() {
		$description = self::description();
		$title       = wp_get_document_title();
		// Clean, canonical og:url — never echo tracking params (fbclid/utm/…).
		$url         = is_singular()
			? get_permalink()
			: ( is_post_type_archive() ? get_post_type_archive_link( get_post_type() ) : home_url( '/' ) );

		$image = '';
		if ( is_singular() && has_post_thumbnail() ) {
			$image = get_the_post_thumbnail_url( null, 'glc-hero' );
		}
		if ( ! $image ) {
			$image = get_theme_file_uri( 'assets/img/hero.jpg' );
		}

		printf( '<meta name="description" content="%s" />' . "\n", esc_attr( $description ) );
		printf( '<meta property="og:type" content="%s" />' . "\n", is_singular( 'car' ) ? 'product' : 'website' );
		printf( '<meta property="og:title" content="%s" />' . "\n", esc_attr( $title ) );
		printf( '<meta property="og:description" content="%s" />' . "\n", esc_attr( $description ) );
		printf( '<meta property="og:url" content="%s" />' . "\n", esc_url( $url ) );
		printf( '<meta property="og:image" content="%s" />' . "\n", esc_url( $image ) );
		printf( '<meta property="og:site_name" content="%s" />' . "\n", esc_attr( get_bloginfo( 'name' ) ) );
		$og_locales = [ 'en' => 'en_US', 'ka' => 'ka_GE', 'ru' => 'ru_RU', 'uk' => 'uk_UA', 'ar' => 'ar_AR', 'zh' => 'zh_CN', 'fr' => 'fr_FR' ];
		$locale     = class_exists( 'GLC_I18n' ) ? GLC_I18n::locale() : 'en';
		printf( '<meta property="og:locale" content="%s" />' . "\n", esc_attr( $og_locales[ $locale ] ?? 'en_US' ) );
		printf( '<meta name="twitter:card" content="summary_large_image" />' . "\n" );

		if ( ! is_singular() ) {
			// Core only emits rel=canonical for singular content.
			$canonical = is_post_type_archive() ? get_post_type_archive_link( get_post_type() ) : home_url( '/' );
			if ( $canonical ) {
				printf( '<link rel="canonical" href="%s" />' . "\n", esc_url( $canonical ) );
			}
		}
	}
}
