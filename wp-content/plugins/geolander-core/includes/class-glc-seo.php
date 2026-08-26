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
			/*
			 * Core's category/post_tag were already dropped, but the three custom
			 * taxonomies stayed in. With ~15 cars across ~5 brands and 2 body types,
			 * plus ~6 place regions — and every one of them multiplied by 7 locales —
			 * these archives are near-duplicates of /fleet/ and /places/ with no
			 * unique title, copy or intent. That is a large slice of the crawlable
			 * surface spent on pages that cannot win anything.
			 *
			 * They stay crawlable and followed (so internal equity still flows and
			 * the cars remain reachable) but leave the sitemap and carry noindex —
			 * see robots_archives(). Revisit /body-type/suv/ specifically once a real
			 * 4x4 category page exists: it is a latent landing page, not junk.
			 */
			unset(
				$taxonomies['category'],
				$taxonomies['post_tag'],
				$taxonomies['car_brand'],
				$taxonomies['car_body_type'],
				$taxonomies['place_region']
			);
			return $taxonomies;
		} );
		add_filter( 'wp_robots', [ __CLASS__, 'robots_archives' ] );
		add_filter( 'robots_txt', [ __CLASS__, 'robots' ] );
		add_action( 'wp_head', [ __CLASS__, 'gtag' ], 8 );
	}

	/**
	 * Search-engineered titles. English (default locale) carries the
	 * commercial keywords; other locales use their catalog strings.
	 */
	public static function title( array $parts ): array {
		$en = ! class_exists( 'GLC_I18n' ) || GLC_I18n::DEFAULT_LOCALE === GLC_I18n::locale();
		$custom_title = is_singular() && $en
			? trim( (string) get_post_meta( get_queried_object_id(), 'glc_seo_title_en', true ) )
			: '';

		if ( $custom_title ) {
			// Keep visible post titles natural while allowing each landing page
			// to target one precise, non-overlapping search intent.
			$parts['title'] = $custom_title;
		} elseif ( is_singular( 'place' ) ) {
			$parts['title'] = $en
				? sprintf( 'Driving to %s in Georgia', get_the_title() )
				: sprintf( glc_ui( 'place_driving_title' ), get_the_title() );
		} elseif ( is_singular( 'car' ) ) {
			// Price the title from the real rate table, and never print a zero:
			// glc_price_from is empty on every imported car, which is exactly how
			// "$0/day" reached live meta descriptions.
			[ $price ] = GLC_Pricing::rate_range( get_the_ID() );
			$parts['title'] = $en
				? ( $price > 0
					? sprintf( 'Rent %s in Tbilisi from $%d/day', get_the_title(), $price )
					: sprintf( '%s Rental in Tbilisi, Georgia', get_the_title() ) )
				: ( $price > 0
					? sprintf( '%s — %s · $%d%s', get_the_title(), glc_ui( 'booking_title' ), $price, glc_ui( 'from_per_day' ) )
					: sprintf( '%s — %s', get_the_title(), glc_ui( 'booking_title' ) ) );
		} elseif ( is_post_type_archive( 'car' ) ) {
			// Duplicate plates are unresolved, so a published-post count must not
			// be presented as the number of physical vehicles.
			$floor = (float) GLC_Format::range()[0];
			$parts['title'] = $en
				? ( $floor > 0
					? sprintf( '4x4 Car Rental Fleet in Tbilisi from $%d/day', $floor )
					: '4x4 Car Rental Fleet in Tbilisi, Georgia' )
				: glc_ui( 'fleet_title' ) . ' — ' . glc_ui( 'fleet_subtitle' );
		} elseif ( is_post_type_archive( 'place' ) ) {
			// Was hard-coded "36 Destinations" — silently false the moment a place
			// is added or removed.
			$places = (int) ( wp_count_posts( 'place' )->publish ?? 0 );
			$parts['title'] = $en
				? sprintf( 'Places to Visit in Georgia by Car — %d Destinations', $places )
				: glc_ui( 'places_title' ) . ' — ' . glc_ui( 'places_subtitle' );
		} elseif ( is_front_page() ) {
			// Front page has no separate 'site' part — brand goes inline.
			$floor = (float) GLC_Format::range()[0];
			$parts['title'] = $en
				? ( $floor > 0
					? sprintf( 'Car Rental in Tbilisi, Georgia — 4x4 from $%d/day | Geolander', $floor )
					: 'Car Rental in Tbilisi, Georgia — 4x4 Rental | Geolander' )
				: glc_ui( 'hero_title' ) . ' | Geolander';
			unset( $parts['tagline'] );
		}
		return $parts;
	}

	/**
	 * noindex, follow on the thin taxonomy archives and on paginated / search
	 * results — "follow" so the cars they list stay reachable and internal equity
	 * keeps flowing, "noindex" because none of them is a page anyone should land
	 * on from search.
	 */
	public static function robots_archives( array $robots ): array {
		$thin = is_tax( [ 'car_brand', 'car_body_type', 'place_region' ] )
			|| is_search()
			|| is_paged();

		if ( $thin ) {
			$robots['noindex'] = true;
			$robots['follow']  = true;
			unset( $robots['index'] );
		}
		return $robots;
	}

	/**
	 * robots.txt: point crawlers at the sitemap and explicitly welcome
	 * the AI crawlers behind ChatGPT, Claude, Perplexity, and Gemini
	 * grounding — AI answer visibility is a business channel here.
	 */
	public static function robots( string $output ): string {
		// Permit search and answer-time use while reserving model-training rights.
		$ai = "\nContent-Signal: search=yes, ai-input=yes, ai-train=no\n";
		foreach ( [
			'GPTBot',
			'OAI-SearchBot',
			'ChatGPT-User',
			'ClaudeBot',
			'Claude-SearchBot',
			'Claude-User',
			'anthropic-ai',
			'PerplexityBot',
			'Google-Extended',
			'Bingbot',
			'DeepSeekBot',
			'ora-agent',
			'CCBot',
		] as $bot ) {
			$ai .= "\nUser-agent: {$bot}\nAllow: /\n";
		}
		$ai .= "\nAgentmap: " . home_url( '/.well-known/ai-catalog.json' ) . "\n";
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
			$en   = ! class_exists( 'GLC_I18n' ) || GLC_I18n::DEFAULT_LOCALE === GLC_I18n::locale();
			$custom_description = $en
				? trim( (string) get_post_meta( $post->ID, 'glc_seo_description_en', true ) )
				: '';
			if ( $custom_description ) {
				return wp_html_excerpt( $custom_description, 158, '…' );
			}
			// Localized body/excerpt so the description matches the page's hreflang.
			$text = class_exists( 'GLC_Content' )
				? GLC_Content::excerpt( $post, 40 )
				: ( $post->post_excerpt ?: wp_strip_all_tags( $post->post_content ) );
			if ( is_singular( 'car' ) ) {
				// Same guard as the title: an unpriced car gets a description that
				// simply omits the price rather than advertising "from $0/day".
				[ $price ] = GLC_Pricing::rate_range( $post->ID );
				if ( $price <= 0 ) {
					$text = $en
						? sprintf(
							'Rent a %s in Tbilisi. Full insurance and free Tbilisi Airport delivery. Get an exact seasonal quote with location fees. %s',
							get_the_title( $post ),
							$text
						)
						: sprintf(
							'%s — %s. %s, %s. %s',
							get_the_title( $post ),
							glc_ui( 'booking_title' ),
							glc_ui( 'trust_insurance' ),
							glc_ui( 'trust_delivery' ),
							$text
						);
					return wp_html_excerpt( trim( $text ), 158, '…' );
				}
				// Localized pages must not advertise themselves in English: hreflang
				// declares them e.g. Georgian, so an English description contradicts
				// the page and reads badly in localized search results.
				$text  = $en
					? sprintf(
						'Rent a %s in Tbilisi from %s/day. Full insurance and free Tbilisi Airport delivery. Location fees are shown in the quote. %s',
						get_the_title( $post ),
						GLC_Format::money( $price ),
						$text
					)
					: sprintf(
						'%s — %s %s%s. %s, %s. %s',
						get_the_title( $post ),
						glc_ui( 'booking_title' ),
						GLC_Format::money( $price ),
						glc_ui( 'from_per_day' ),
						glc_ui( 'trust_insurance' ),
						glc_ui( 'trust_delivery' ),
						$text
					);
			}
			if ( is_singular( 'place' ) ) {
				$text = $en
					? sprintf(
						'Plan the drive to %s in Georgia: destination context, map, road conditions, driving guides, and exact rental cars from Tbilisi. %s',
						get_the_title( $post ),
						$text
					)
					: sprintf( '%s. %s', sprintf( glc_ui( 'place_driving_title' ), get_the_title( $post ) ), $text );
			}
		} elseif ( is_post_type_archive( 'car' ) ) {
			$text = glc_ui( 'fleet_title' ) . ' — ' . glc_ui( 'fleet_subtitle' ) . ' ' . glc_ui( 'trust_insurance' ) . ', ' . glc_ui( 'trust_delivery' ) . '.';
		} elseif ( is_post_type_archive( 'place' ) ) {
			$text = glc_ui( 'places_subtitle' ) . ' — ' . glc_ui( 'route_1' ) . ', ' . glc_ui( 'route_2' ) . ', ' . glc_ui( 'route_3' ) . ', ' . glc_ui( 'route_4' ) . '.';
		} elseif ( is_front_page() ) {
			$text = sprintf(
				'%s — Geolander car rental in the heart of Tbilisi, in %s at %s.',
				glc_ui( 'hero_subtitle' ),
				GLC_Settings::get( 'office_district' ),
				GLC_Settings::get( 'address' )
			);
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
		$og_type = is_singular( 'car' )
			? 'product'
			: ( is_singular() && get_post_meta( get_queried_object_id(), 'glc_guide_route', true ) ? 'article' : 'website' );
		printf( '<meta property="og:type" content="%s" />' . "\n", esc_attr( $og_type ) );
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
