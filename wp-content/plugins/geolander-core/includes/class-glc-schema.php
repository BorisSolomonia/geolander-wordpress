<?php
/**
 * Structured data (JSON-LD) for search engines and AI crawlers.
 *
 * Every page gets an @graph with the AutoRental business + WebSite.
 * Car pages add ["Product","Car"] with an AggregateOffer built from the
 * live seasonal rate table; the front page adds FAQPage from `faq` posts;
 * singular pages add BreadcrumbList.
 */

defined( 'ABSPATH' ) || exit;

class GLC_Schema {

	public static function init() {
		add_action( 'init', [ __CLASS__, 'register_faq_cpt' ] );
		add_action( 'wp_head', [ __CLASS__, 'output' ], 5 );
	}

	public static function register_faq_cpt() {
		register_post_type( 'faq', [
			'labels'             => [ 'name' => __( 'FAQs', 'geolander' ), 'singular_name' => __( 'FAQ', 'geolander' ) ],
			'public'             => false,
			'show_ui'            => true,
			'publicly_queryable' => false,
			'menu_icon'          => 'dashicons-editor-help',
			'menu_position'      => 9,
			'supports'           => [ 'title', 'editor', 'page-attributes' ],
			'show_in_rest'       => true,
		] );
	}

	public static function output() {
		$graph   = [];
		$graph[] = self::business();
		$graph[] = self::website();

		// The trail is built once by current_trail() and reused by the visible
		// breadcrumb bar, so markup and page can never drift apart.
		if ( is_singular( 'car' ) ) {
			$graph[] = self::car( get_the_ID() );
			$graph[] = self::breadcrumbs( self::current_trail() );
		} elseif ( is_singular( 'place' ) ) {
			$graph[] = self::place( get_the_ID() );
			$graph[] = self::breadcrumbs( self::current_trail() );
		} elseif ( is_singular( 'city' ) && class_exists( 'GLC_City' ) ) {
			$graph[] = GLC_City::schema( get_the_ID() );
			$graph[] = self::breadcrumbs( self::current_trail() );
		} elseif ( is_singular( 'page' ) && get_post_meta( get_the_ID(), 'glc_guide_route', true ) ) {
			$graph[] = self::guide( get_the_ID() );
			$graph[] = self::breadcrumbs( self::current_trail() );
		} elseif ( is_post_type_archive( 'car' ) ) {
			$graph[] = self::fleet_list();
		} elseif ( is_front_page() ) {
			$faq = self::faq();
			if ( $faq ) {
				$graph[] = $faq;
			}
		}

		printf(
			"<script type=\"application/ld+json\">%s</script>\n",
			wp_json_encode(
				[
					'@context' => 'https://schema.org',
					'@graph'   => array_values( array_filter( array_map( [ __CLASS__, 'prune' ], $graph ) ) ),
				],
				JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
			)
		);
	}

	/**
	 * Drop null/empty properties recursively.
	 *
	 * Several nodes build optional properties with `?: null` and the offers node
	 * is now null for unpriced cars. Emitting `"offers": null` or `"color": null`
	 * is noise at best and, for offers specifically, ambiguous — pruning means an
	 * absent price is genuinely absent rather than declared empty.
	 */
	private static function prune( $value ) {
		if ( ! is_array( $value ) ) {
			return $value;
		}
		$is_list = array_is_list( $value );
		$out     = [];
		foreach ( $value as $key => $item ) {
			$item = self::prune( $item );
			if ( null === $item || '' === $item || [] === $item ) {
				continue;
			}
			$out[ $key ] = $item;
		}
		// Re-index lists so a pruned element can't turn a JSON array into an object.
		return $is_list ? array_values( $out ) : $out;
	}

	private static function business(): array {
		$logo = get_theme_file_uri( 'assets/img/logo.png' );

		/*
		 * `image` was the logo. For a local business entity a real photograph of
		 * the premises or the fleet is a stronger corroborating signal than a
		 * wordmark — and it is what the Google Business Profile should show too.
		 * `logo` stays the logo, which is what that property is for.
		 */
		$image = get_theme_file_uri( 'assets/img/hero.jpg' );

		/*
		 * areaServed was Country:Georgia alone, while the business explicitly
		 * delivers to four named cities and publishes a page for each. Declaring
		 * the actual service footprint costs one loop and makes the entity legible
		 * to both local search and AI grounding.
		 */
		$area = [ [ '@type' => 'Country', 'name' => 'Georgia' ] ];
		if ( class_exists( 'GLC_City' ) ) {
			foreach ( GLC_City::all() as $city ) {
				$name = GLC_City::city_name( $city->ID );
				if ( $name ) {
					$area[] = [ '@type' => 'City', 'name' => $name ];
				}
			}
		}

		return [
			'@type'      => 'AutoRental',
			'@id'        => home_url( '/#business' ),
			// Must match the Google Business Profile name exactly so search
			// engines reconcile the site and the Maps listing as one entity.
			'name'       => GLC_Settings::get( 'business_name', get_bloginfo( 'name' ) ),
			'alternateName' => 'Geolander',
			'description'=> get_bloginfo( 'description' ),
			'url'        => home_url( '/' ),
			'logo'       => $logo,
			'image'      => $image,
			'telephone'  => GLC_Settings::get( 'phone' ),
			'email'      => GLC_Settings::get( 'email' ),
			// Reads the settings range, so schema can't drift from the site copy.
			'priceRange' => GLC_Format::range_display( 'en' ),
			'hasMap'     => GLC_Settings::get( 'google_maps_url' ),
			'currenciesAccepted' => 'GEL, USD',
			'address'    => [
				'@type'           => 'PostalAddress',
				'streetAddress'   => GLC_Settings::get( 'address' ),
				'addressLocality' => GLC_Settings::get( 'address_locality', 'Tbilisi' ),
				'postalCode'      => GLC_Settings::get( 'postal_code' ),
				'addressCountry'  => 'GE',
			],
			'geo'        => [
				'@type'     => 'GeoCoordinates',
				'latitude'  => (float) GLC_Settings::get( 'latitude' ),
				'longitude' => (float) GLC_Settings::get( 'longitude' ),
			],
			'openingHoursSpecification' => [
				'@type'     => 'OpeningHoursSpecification',
				'dayOfWeek' => [ 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday' ],
				'opens'     => '00:00',
				'closes'    => '23:59',
			],
			'sameAs'     => array_values( array_filter( [
				GLC_Settings::get( 'instagram' ),
				GLC_Settings::get( 'facebook' ),
			] ) ),
			'areaServed' => $area,
		];
	}

	private static function website(): array {
		return [
			'@type'     => 'WebSite',
			'@id'       => home_url( '/#website' ),
			'url'       => home_url( '/' ),
			'name'      => get_bloginfo( 'name' ),
			'inLanguage'=> class_exists( 'GLC_I18n' ) ? GLC_I18n::locale() : 'en',
			'publisher' => [ '@id' => home_url( '/#business' ) ],
		];
	}

	private static function car( int $post_id ): array {
		[ $low, $high ] = GLC_Pricing::rate_range( $post_id );

		$images = [];
		if ( has_post_thumbnail( $post_id ) ) {
			$images[] = get_the_post_thumbnail_url( $post_id, 'full' );
		}
		foreach ( (array) get_post_meta( $post_id, 'glc_gallery', true ) as $att_id ) {
			$url = wp_get_attachment_image_url( (int) $att_id, 'full' );
			if ( $url ) {
				$images[] = $url;
			}
		}

		$brand = wp_get_post_terms( $post_id, 'car_brand', [ 'fields' => 'names' ] );
		$year  = get_post_meta( $post_id, 'glc_year', true );

		/*
		 * A Product offer advertising lowPrice 0 is not a missing price — it is a
		 * false price, and it was being published to Google's structured-data
		 * pipeline and to every AI crawler robots.txt invites. When the car has no
		 * usable rate the offers node is omitted entirely: describing the vehicle
		 * without quoting a price is honest; quoting zero is not.
		 */
		$currency = GLC_Settings::get( 'payment_currency', 'USD' );
		$offers   = ( $low > 0 && $high > 0 ) ? [
			'@type'         => 'AggregateOffer',
			'priceCurrency' => $currency,
			'lowPrice'      => $low,
			'highPrice'     => $high,
			'offerCount'    => count( GLC_Pricing::TIERS ),
			'availability'  => get_post_meta( $post_id, 'glc_available', true )
				? 'https://schema.org/InStock'
				: 'https://schema.org/OutOfStock',
			'url'           => get_permalink( $post_id ),
			'seller'        => [ '@id' => home_url( '/#business' ) ],
			'priceSpecification' => [
				'@type'             => 'UnitPriceSpecification',
				'price'             => $low,
				'priceCurrency'     => $currency,
				'unitCode'          => 'DAY',
				'referenceQuantity' => [ '@type' => 'QuantitativeValue', 'value' => 1, 'unitCode' => 'DAY' ],
			],
		] : null;

		return [
			'@type'               => [ 'Product', 'Car' ],
			'@id'                 => get_permalink( $post_id ) . '#car',
			'name'                => get_the_title( $post_id ),
			// Localized description: schema declares inLanguage per locale, so the
			// description must match it rather than always being English.
			'description'         => GLC_Content::excerpt( $post_id, 40 ),
			'image'               => array_values( array_unique( $images ) ),
			'url'                 => get_permalink( $post_id ),
			'brand'               => $brand ? [ '@type' => 'Brand', 'name' => $brand[0] ] : null,
			'modelDate'           => $year ? (string) $year : null,
			'vehicleModelDate'    => $year ? (string) $year : null,
			'color'               => get_post_meta( $post_id, 'glc_color', true ) ?: null,
			'vehicleTransmission' => get_post_meta( $post_id, 'glc_transmission', true ) ?: null,
			'fuelType'            => get_post_meta( $post_id, 'glc_fuel_type', true ) ?: null,
			'seatingCapacity'     => (int) get_post_meta( $post_id, 'glc_seats', true ) ?: null,
			'offers'              => $offers,
		];
	}

	private static function place( int $post_id ): array {
		// The place CPT already stores coordinates and they were never used, so the
		// node claimed authority over a national landmark with an address of
		// "Georgia" and nothing else. Real coordinates make the claim substantive.
		$lat = (float) get_post_meta( $post_id, 'glc_lat', true );
		$lng = (float) get_post_meta( $post_id, 'glc_lng', true );

		return [
			'@type'       => 'TouristAttraction',
			'@id'         => get_permalink( $post_id ) . '#place',
			'name'        => GLC_Content::title( $post_id ),
			'description' => GLC_Content::excerpt( $post_id, 40 ),
			'image'       => has_post_thumbnail( $post_id ) ? get_the_post_thumbnail_url( $post_id, 'full' ) : null,
			'url'         => get_permalink( $post_id ),
			'address'     => [ '@type' => 'PostalAddress', 'addressCountry' => 'GE' ],
			'geo'         => ( $lat && $lng )
				? [ '@type' => 'GeoCoordinates', 'latitude' => $lat, 'longitude' => $lng ]
				: null,
		];
	}

	private static function guide( int $post_id ): array {
		$image = has_post_thumbnail( $post_id )
			? get_the_post_thumbnail_url( $post_id, 'full' )
			: get_theme_file_uri( 'assets/img/hero.jpg' );

		return [
			'@type'            => 'Article',
			'@id'              => get_permalink( $post_id ) . '#article',
			'headline'         => get_the_title( $post_id ),
			'description'      => get_post_meta( $post_id, 'glc_seo_description_en', true )
				?: GLC_Content::excerpt( $post_id, 40 ),
			'image'            => $image,
			'url'              => get_permalink( $post_id ),
			'mainEntityOfPage' => [ '@id' => get_permalink( $post_id ) ],
			'datePublished'    => get_the_date( DATE_W3C, $post_id ),
			'dateModified'     => get_the_modified_date( DATE_W3C, $post_id ),
			'inLanguage'       => 'en',
			'author'           => [ '@id' => home_url( '/#business' ) ],
			'publisher'        => [ '@id' => home_url( '/#business' ) ],
			'about'            => [
				'@type' => 'TouristDestination',
				'name'  => get_post_meta( $post_id, 'glc_guide_route', true ),
			],
		];
	}

	private static function fleet_list(): array {
		// Reuse the per-request memoized fleet (shared with the grid block).
		$cars = glc_fleet_query()->posts;
		return [
			'@type'           => 'ItemList',
			'@id'             => get_post_type_archive_link( 'car' ) . '#fleet',
			'name'            => 'Geolander 4x4 Rental Fleet',
			'numberOfItems'   => count( $cars ),
			'itemListElement' => array_map( fn( $car, $i ) => [
				'@type'    => 'ListItem',
				'position' => $i + 1,
				'name'     => $car->post_title,
				'url'      => get_permalink( $car ),
			], $cars, array_keys( $cars ) ),
		];
	}

	private static function faq(): ?array {
		$faqs = get_posts( [
			'post_type'      => 'faq',
			'posts_per_page' => 50,
			'orderby'        => 'menu_order',
			'order'          => 'ASC',
		] );
		if ( ! $faqs ) {
			return null;
		}
		return [
			'@type'      => 'FAQPage',
			'@id'        => home_url( '/#faq' ),
			'mainEntity' => array_map( fn( $faq ) => [
				'@type'          => 'Question',
				'name'           => GLC_Content::title( $faq ),
				'acceptedAnswer' => [
					'@type' => 'Answer',
					'text'  => wp_strip_all_tags( GLC_Content::body( $faq ) ),
				],
			], $faqs ),
		];
	}

	/**
	 * The breadcrumb trail for the current request, or [] when there isn't one.
	 *
	 * Shared by the JSON-LD node and the visible breadcrumb bar so the markup
	 * always describes something the visitor can actually see — Google expects
	 * structured data to correspond to visible page content, and the site emitted
	 * BreadcrumbList with no breadcrumb anywhere on the page.
	 */
	public static function current_trail(): array {
		if ( is_singular( 'car' ) ) {
			return [
				[ get_post_type_archive_link( 'car' ), glc_ui( 'fleet_title' ) ],
				[ get_permalink(), get_the_title() ],
			];
		}
		if ( is_singular( 'place' ) ) {
			return [
				[ get_post_type_archive_link( 'place' ), glc_ui( 'places_title' ) ],
				[ get_permalink(), GLC_Content::title( get_the_ID() ) ],
			];
		}
		if ( is_singular( 'city' ) && class_exists( 'GLC_City' ) ) {
			return [
				[ get_post_type_archive_link( 'car' ), glc_ui( 'fleet_title' ) ],
				[ get_permalink(), GLC_Content::title( get_the_ID() ) ],
			];
		}
		if ( is_singular( 'page' ) && get_post_meta( get_the_ID(), 'glc_guide_route', true ) ) {
			return [ [ get_permalink(), get_the_title() ] ];
		}
		return [];
	}

	/** Visible breadcrumb bar matching current_trail(). */
	public static function breadcrumb_html(): string {
		$trail = self::current_trail();
		if ( ! $trail ) {
			return '';
		}
		$items = [ sprintf(
			'<a href="%s">%s</a>',
			esc_url( home_url( '/' ) ),
			esc_html( get_bloginfo( 'name' ) )
		) ];
		$last  = count( $trail ) - 1;
		foreach ( $trail as $i => [ $url, $name ] ) {
			$items[] = $i === $last
				? '<span aria-current="page">' . esc_html( $name ) . '</span>'
				: sprintf( '<a href="%s">%s</a>', esc_url( $url ), esc_html( $name ) );
		}
		return sprintf(
			'<nav class="glc-breadcrumbs" aria-label="%s">%s</nav>',
			esc_attr( glc_ui( 'breadcrumb' ) ),
			implode( '<span class="glc-breadcrumb-sep" aria-hidden="true">/</span>', $items )
		);
	}

	private static function breadcrumbs( array $trail ): array {
		$items = [ [
			'@type'    => 'ListItem',
			'position' => 1,
			'name'     => get_bloginfo( 'name' ),
			'item'     => home_url( '/' ),
		] ];
		foreach ( $trail as $i => [ $url, $name ] ) {
			$items[] = [
				'@type'    => 'ListItem',
				'position' => $i + 2,
				'name'     => $name,
				'item'     => $url,
			];
		}
		return [ '@type' => 'BreadcrumbList', 'itemListElement' => $items ];
	}
}
