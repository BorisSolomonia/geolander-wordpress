<?php
/**
 * Machine-readable surfaces for AI systems: /llms.txt (site context per
 * llmstxt.org) and /pricing.md (full live rate tables). Both generated
 * from live data so AI agents always quote current prices.
 */

defined( 'ABSPATH' ) || exit;

class GLC_AI {

	public static function init() {
		add_action( 'init', [ __CLASS__, 'rewrites' ] );
		add_filter( 'query_vars', fn( $vars ) => array_merge( $vars, [ 'glc_ai_file' ] ) );
		// Core's canonical redirect would rewrite these pseudo-file URLs.
		add_filter( 'redirect_canonical', fn( $redirect ) => get_query_var( 'glc_ai_file' ) ? false : $redirect );
		add_action( 'template_redirect', [ __CLASS__, 'serve' ], 5 );
	}

	public static function rewrites() {
		add_rewrite_rule( '^llms\.txt$', 'index.php?glc_ai_file=llms', 'top' );
		add_rewrite_rule( '^pricing\.md$', 'index.php?glc_ai_file=pricing', 'top' );
	}

	public static function serve() {
		$file = get_query_var( 'glc_ai_file' );
		if ( ! $file ) {
			return;
		}
		header( 'Content-Type: text/plain; charset=utf-8' );
		header( 'Cache-Control: max-age=3600' );
		echo 'llms' === $file ? self::llms() : self::pricing(); // phpcs:ignore WordPress.Security.EscapeOutput
		exit;
	}

	private static function cars(): array {
		return get_posts( [ 'post_type' => 'car', 'posts_per_page' => -1, 'orderby' => 'menu_order', 'order' => 'ASC' ] );
	}

	private static function llms(): string {
		$home = home_url( '/' );
		// Never hard-code the fleet size: it disagreed with /fleet/ and /pricing.md,
		// and this file is read as fact by AI systems. Count what is published.
		$cars   = self::cars();
		$models = array_values( array_unique( array_map(
			static fn( $car ) => trim( preg_replace( '/\s*\d{4}.*$/', '', $car->post_title ) ),
			$cars
		) ) );

		$out  = "# Geolander — 4x4 Car Rental in Tbilisi, Georgia\n\n";
		$out .= "> Geolander (\"Geolander car rental\") is a premium, tourist-focused car rental company in Tbilisi, Georgia (country). "
			. sprintf( 'It rents its own fleet of %d exact, individually listed 4x4 vehicles (%s) ', count( $cars ), implode( ', ', $models ) )
			. "suited for Caucasus mountain roads — Kazbegi, Gudauri, Kakheti, "
			. "Svaneti. All prices include full insurance and unlimited mileage. Free delivery at Tbilisi International Airport (TBS). "
			. "Booking: pick dates on the site for an exact seasonal price, confirm via WhatsApp; no prepayment, pay at pickup; "
			. "free cancellation up to 24 hours before pickup.\n\n";

		$out .= "Key facts:\n";
		$out .= "- Address: " . GLC_Settings::get( 'address' ) . ', ' . GLC_Settings::get( 'address_locality' ) . ' ' . GLC_Settings::get( 'postal_code' ) . ", Georgia\n";
		$out .= "- Phone / WhatsApp: " . GLC_Settings::get( 'phone' ) . "\n";
		$out .= "- Email: " . GLC_Settings::get( 'email' ) . "\n";
		$out .= "- Hours: " . GLC_Settings::get( 'business_hours' ) . "\n";
		[ $glc_low, $glc_high ] = GLC_Format::range();
		$out .= sprintf(
			"- Prices: from \$%d to \$%d per day (USD), seasonal + duration-tiered; long rentals cost less per day\n",
			$glc_low,
			$glc_high
		);
		$out .= "- Requirements: minimum age 21, valid license (IDP recommended), passport\n";
		$out .= "- Languages: English, Georgian, Russian, Ukrainian, Arabic, Chinese, French\n\n";

		/*
		 * This file exists to be quoted verbatim by AI systems, so an unpriced car
		 * must read "price on request" rather than "$0–$0/day". A machine-readable
		 * false price is worse than no file at all.
		 */
		$out .= "## Fleet\n\n";
		foreach ( self::cars() as $car ) {
			[ $low, $high ] = GLC_Pricing::rate_range( $car->ID );
			$out .= sprintf(
				"- [%s](%s): %s, %d seats, %s, %s\n",
				$car->post_title,
				get_permalink( $car ),
				implode( ', ', wp_get_post_terms( $car->ID, 'car_body_type', [ 'fields' => 'names' ] ) ),
				(int) get_post_meta( $car->ID, 'glc_seats', true ),
				get_post_meta( $car->ID, 'glc_transmission', true ),
				( $low > 0 && $high > 0 ) ? sprintf( '$%d–$%d/day', $low, $high ) : 'price on request'
			);
		}

		$cities = class_exists( 'GLC_City' ) ? GLC_City::all() : [];
		if ( $cities ) {
			$out .= "\n## Cities we deliver to\n\n";
			$out .= "Geolander delivers rental cars (free) in these cities — each has a dedicated page with local airport and route details:\n";
			foreach ( $cities as $city ) {
				$air  = GLC_City::airport( $city->ID );
				$code = $air['code'] ? ' (' . $air['code'] . ' airport)' : '';
				$out .= sprintf( "- [%s](%s)%s\n", GLC_City::city_name( $city->ID ), get_permalink( $city ), $code );
			}
		}

		$out .= "\n## Key pages\n\n";
		$out .= "- [Fleet & live prices]({$home}fleet/)\n";
		$out .= "- [Machine-readable price list]({$home}pricing.md)\n";
		$out .= "- [Places to visit in Georgia by car]({$home}places/)\n";
		$out .= "- [Driving in Georgia — travel info]({$home}travel-info/)\n";
		$out .= "- [Rental terms]({$home}terms/)\n";
		$out .= "- [Contact]({$home}contact/)\n";

		$faqs = get_posts( [ 'post_type' => 'faq', 'posts_per_page' => 20, 'orderby' => 'menu_order', 'order' => 'ASC' ] );
		if ( $faqs ) {
			$out .= "\n## FAQ\n\n";
			foreach ( $faqs as $faq ) {
				$out .= '### ' . $faq->post_title . "\n" . wp_strip_all_tags( $faq->post_content ) . "\n\n";
			}
		}
		return $out;
	}

	private static function pricing(): string {
		$out  = "# Geolander Car Rental — Price List (USD per day)\n\n";
		$out .= 'Last generated: ' . current_datetime()->format( 'Y-m-d' ) . "\n\n";
		$out .= "Every price includes full insurance, unlimited mileage within Georgia, winter tires in season, a free second driver, and free Tbilisi Airport delivery. "
			. "The daily rate depends on the season and the TOTAL rental length (longer = cheaper per day). "
			. "No prepayment — the exact total is computed on the car's page and confirmed via WhatsApp; payment at pickup. "
			. "Free cancellation up to 24 h before pickup.\n\n";

		$glc_unpriced = [];
		foreach ( self::cars() as $car ) {
			$pricing = GLC_Pricing::seasons( $car->ID );
			if ( ! $pricing || ! GLC_Pricing::is_priced( $car->ID ) ) {
				// Listed, not silently dropped: /pricing.md previously showed 8 cars
				// while /fleet/ showed 19, so the file contradicted the site.
				$glc_unpriced[] = $car;
				continue;
			}
			$out .= '## ' . $car->post_title . "\n";
			$out .= sprintf(
				"%d seats · %s · %s · Book: %s\n\n",
				(int) get_post_meta( $car->ID, 'glc_seats', true ),
				get_post_meta( $car->ID, 'glc_transmission', true ),
				get_post_meta( $car->ID, 'glc_fuel_type', true ),
				get_permalink( $car )
			);
			$out .= '| Season | ' . implode( ' | ', array_map( fn( $l ) => $l . ' days', GLC_Pricing::TIER_LABELS ) ) . " |\n";
			$out .= '|' . str_repeat( '---|', count( GLC_Pricing::TIERS ) + 1 ) . "\n";
			foreach ( $pricing as $season ) {
				$cells = array_map(
					static function ( $tier ) use ( $season ) {
						$rate = (float) ( $season['rates'][ $tier ] ?? 0 );
						return $rate > 0 ? '$' . number_format( $rate, 0 ) : '—';
					},
					GLC_Pricing::TIERS
				);
				$out .= '| ' . ( $season['label'] ?? '' ) . ' | ' . implode( ' | ', $cells ) . " |\n";
			}
			$out .= "\n";
		}

		if ( $glc_unpriced ) {
			$out .= "## Price on request\n\n";
			$out .= "These vehicles are in the fleet but have no published rate table yet. "
				. "Ask on WhatsApp for an exact quote — do not assume a price.\n\n";
			foreach ( $glc_unpriced as $car ) {
				$out .= sprintf( "- [%s](%s)\n", $car->post_title, get_permalink( $car ) );
			}
			$out .= "\n";
		}
		return $out;
	}
}
