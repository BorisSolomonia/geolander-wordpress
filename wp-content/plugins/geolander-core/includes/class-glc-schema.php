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

		if ( is_singular( 'car' ) ) {
			$graph[] = self::car( get_the_ID() );
			$graph[] = self::breadcrumbs( [
				[ get_post_type_archive_link( 'car' ), __( 'Fleet', 'geolander' ) ],
				[ get_permalink(), get_the_title() ],
			] );
		} elseif ( is_singular( 'place' ) ) {
			$graph[] = self::place( get_the_ID() );
			$graph[] = self::breadcrumbs( [
				[ get_post_type_archive_link( 'place' ), __( 'Places', 'geolander' ) ],
				[ get_permalink(), get_the_title() ],
			] );
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
				[ '@context' => 'https://schema.org', '@graph' => array_values( array_filter( $graph ) ) ],
				JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
			)
		);
	}

	private static function business(): array {
		$logo = get_theme_file_uri( 'assets/img/logo.png' );
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
			'image'      => $logo,
			'telephone'  => GLC_Settings::get( 'phone' ),
			'email'      => GLC_Settings::get( 'email' ),
			'priceRange' => '$26 - $90',
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
			'areaServed' => [ '@type' => 'Country', 'name' => 'Georgia' ],
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

		return [
			'@type'               => [ 'Product', 'Car' ],
			'@id'                 => get_permalink( $post_id ) . '#car',
			'name'                => get_the_title( $post_id ),
			'description'         => get_the_excerpt( $post_id ) ?: wp_strip_all_tags( get_post_field( 'post_content', $post_id ) ),
			'image'               => array_values( array_unique( $images ) ),
			'url'                 => get_permalink( $post_id ),
			'brand'               => $brand ? [ '@type' => 'Brand', 'name' => $brand[0] ] : null,
			'modelDate'           => $year ? (string) $year : null,
			'vehicleModelDate'    => $year ? (string) $year : null,
			'color'               => get_post_meta( $post_id, 'glc_color', true ) ?: null,
			'vehicleTransmission' => get_post_meta( $post_id, 'glc_transmission', true ) ?: null,
			'fuelType'            => get_post_meta( $post_id, 'glc_fuel_type', true ) ?: null,
			'seatingCapacity'     => (int) get_post_meta( $post_id, 'glc_seats', true ) ?: null,
			'offers'              => [
				'@type'         => 'AggregateOffer',
				'priceCurrency' => GLC_Settings::get( 'payment_currency', 'USD' ),
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
					'priceCurrency'     => GLC_Settings::get( 'payment_currency', 'USD' ),
					'unitCode'          => 'DAY',
					'referenceQuantity' => [ '@type' => 'QuantitativeValue', 'value' => 1, 'unitCode' => 'DAY' ],
				],
			],
		];
	}

	private static function place( int $post_id ): array {
		return [
			'@type'       => 'TouristAttraction',
			'@id'         => get_permalink( $post_id ) . '#place',
			'name'        => get_the_title( $post_id ),
			'description' => get_the_excerpt( $post_id ) ?: wp_strip_all_tags( get_post_field( 'post_content', $post_id ) ),
			'image'       => has_post_thumbnail( $post_id ) ? get_the_post_thumbnail_url( $post_id, 'full' ) : null,
			'url'         => get_permalink( $post_id ),
			'address'     => [ '@type' => 'PostalAddress', 'addressCountry' => 'GE' ],
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
				'name'           => $faq->post_title,
				'acceptedAnswer' => [
					'@type' => 'Answer',
					'text'  => wp_strip_all_tags( $faq->post_content ),
				],
			], $faqs ),
		];
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
