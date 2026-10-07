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
		// Core sitemaps never list CPT archive pages, so /fleet/ and /places/ —
		// two of the site's most-linked URLs — were absent (crawl, 2026-09-07).
		add_action( 'init', [ __CLASS__, 'register_archive_sitemap' ] );
		add_filter( 'robots_txt', [ __CLASS__, 'robots' ] );
		add_action( 'wp_head', [ __CLASS__, 'gtag' ], 8 );
		add_action( 'template_redirect', [ __CLASS__, 'redirect_full_archives' ], 1 );
	}

	/** These two custom grids render ALL items, not a slice of the WP main query. */
	public static function full_archive_redirect(): string {
		if ( is_404() || ! is_paged() || ! is_post_type_archive( [ 'car', 'place' ] ) ) { return ''; }
		$query = wp_unslash( $_GET );
		unset( $query['paged'] );
		return add_query_arg( $query, get_post_type_archive_link( get_post_type() ) );
	}
	public static function redirect_full_archives(): void {
		$url = self::full_archive_redirect();
		if ( $url && in_array( $_SERVER['REQUEST_METHOD'] ?? 'GET', [ 'GET', 'HEAD' ], true ) ) {
			wp_safe_redirect( $url, 301, 'Geolander full archive' );
			exit;
		}
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
				? sprintf( '%d Places to Visit in Georgia by Car', $places ) // was 63 chars with the brand; now ≤ 60
				: glc_ui( 'places_title' ) . ' — ' . glc_ui( 'places_subtitle' );
		} elseif ( is_singular( 'city' ) && class_exists( 'GLC_City' ) ) {
			/*
			 * The homepage and /car-rental-tbilisi/ both carried "Car Rental in
			 * Tbilisi" and competed for the same query (GSC, 2026-09-01). The city
			 * page now owns "car rental {city}" with the country disambiguated and a
			 * concrete promise; the homepage moves to the 4×4 + country intent.
			 * Localised via the catalogue so every hreflang variant reads natively.
			 */
			$city_name      = GLC_City::city_name( get_the_ID() );
			$parts['title'] = sprintf( glc_ui( 'city_seo_title' ), $city_name );
			if ( $en && preg_match( '/airport/i', $city_name ) ) {
				// "Car Rental at Kutaisi Airport", not "in".
				$parts['title'] = preg_replace( '/^Car Rental in /', 'Car Rental at ', $parts['title'] );
			}
		} elseif ( is_front_page() ) {
			// Front page has no separate 'site' part — brand goes inline.
			$floor = (float) GLC_Format::range()[0];
			$parts['title'] = $en
				? ( $floor > 0
					? sprintf( '4x4 Car Rental in Georgia (Country) from $%d/day | Geolander', $floor )
					: '4x4 Car Rental in Georgia (Country) — Tbilisi Based | Geolander' )
				: glc_ui( 'home_seo_title' ) . ' | Geolander';
			unset( $parts['tagline'] );
		} elseif ( is_singular() && class_exists( 'GLC_Content' ) ) {
			/*
			 * Every other singular page: the localised title. On the English locale
			 * this is post_title (unchanged behaviour); on /ka/, /ru/… it is the
			 * glc_title_{locale} meta when one exists. Root cause of the Georgian
			 * <title> on the English /blog/: the page's post_title WAS Georgian —
			 * see _migration/fix-locale-titles.php, which moves it to glc_title_ka.
			 */
			$localised = GLC_Content::title( get_queried_object_id() );
			if ( '' !== $localised ) {
				$parts['title'] = $localised;
			}
		}
		return $parts;
	}

	/** Sitemap provider for the post-type archive pages. */
	public static function register_archive_sitemap(): void {
		if ( ! class_exists( 'WP_Sitemaps_Provider' ) || ! function_exists( 'wp_register_sitemap_provider' ) ) {
			return;
		}
		wp_register_sitemap_provider( 'archives', new class() extends WP_Sitemaps_Provider {
			public function __construct() {
				$this->name        = 'archives';
				$this->object_type = 'archive';
			}
			public function get_url_list( $page_num, $object_subtype = '' ) {
				$urls = [];
				foreach ( [ 'car', 'place', 'glc_news' ] as $type ) {
					$link = get_post_type_archive_link( $type );
					if ( $link ) {
						$urls[] = [ 'loc' => $link ];
					}
				}
				return $urls;
			}
			public function get_max_num_pages( $object_subtype = '' ) {
				return 1;
			}
		} );
	}

	/**
	 * Thin taxonomies, search and faceted results remain crawlable but noindex.
	 * Pagination has its own canonical; language parameters use a clean canonical.
	 */
	public static function robots_archives( array $robots ): array {
		$thin = is_tax( [ 'car_brand', 'car_body_type', 'place_region' ] )
			|| is_search()
			|| isset( $_GET['region'] );

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
		// Never override WordPress's site-wide crawl exclusion on a private site.
		if ( preg_match( '#^Disallow:\s*/\s*$#mi', $output ) ) { return $output; }
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
			// A specific user-agent group does not inherit wildcard exclusions.
			$ai .= "\nUser-agent: {$bot}\nAllow: /\nDisallow: /wp-admin/\nAllow: /wp-admin/admin-ajax.php\n";
		}
		$ai .= "\nAgentmap: " . home_url( '/.well-known/ai-catalog.json' ) . "\n";
		// Crawlers must fetch parameter pages to see canonical/noindex directives.
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
		// Keep our measurements free of booking messages, customer details and URL queries.
		printf( '<script>window.glcAnalytics=%s;</script>' . "\n", wp_json_encode( [ 'ids' => array_values( array_filter( [ $ga4, $ads ] ) ) ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT ) );
		wp_enqueue_script( 'glc-analytics', GLC_URL . 'assets/analytics.js', [], GLC_VERSION, false );
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
			if ( has_block( 'geolander/arrival-costs', $post ) ) {
				return wp_html_excerpt( glc_ui( 'arrival_intro' ), 158, '…' );
			}
			if ( has_block( 'geolander/trip-planner', $post ) ) {
				return wp_html_excerpt( glc_ui( 'planner_description' ), 158, '…' );
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
			if ( is_singular( 'city' ) && class_exists( 'GLC_City' ) ) {
				$text = sprintf( glc_ui( 'city_seo_description' ), GLC_City::city_name( $post->ID ) );
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
		if ( is_post_type_archive() && is_paged() ) {
			$url = strtok( get_pagenum_link( (int) get_query_var( 'paged' ), false ), '?' );
		}

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
			: ( is_singular( 'glc_news' ) || ( is_singular() && get_post_meta( get_queried_object_id(), 'glc_guide_route', true ) ) ? 'article' : 'website' );
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
			/*
			 * Core only emits rel=canonical for singular content. This used to fall
			 * back to the homepage for EVERYTHING else, so every 404, search page
			 * and taxonomy archive told Google "the canonical version of me is /"
			 * (observed live 2026-09-07). A 404 has no canonical; a noindex archive
			 * needs none. Only the front page and the two CPT archives get one.
			 */
			$canonical = '';
			if ( is_front_page() ) {
				$canonical = home_url( '/' );
			} elseif ( is_post_type_archive() ) {
				$canonical = (string) $url;
			}
			if ( $canonical && ! is_404() && ! is_search() ) {
				printf( '<link rel="canonical" href="%s" />' . "\n", esc_url( $canonical ) );
			}
		}
	}
}
