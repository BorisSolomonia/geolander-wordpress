<?php
/**
 * Rental policies and location-aware quotes.
 *
 * Vehicle day rates remain in GLC_Pricing. This class adds the business rules
 * that apply to a booking: pickup/return locations, delivery charges, the
 * charge for an early or late handover, and the confirmation prepayment.
 * Keeping them server-side means the page, WhatsApp request and confirmation
 * email always use the same final calculation.
 *
 * Every charge here has three states, and every surface follows them:
 *   free    — nothing to pay: the office, or an amount somebody typed 0 into;
 *   priced  — an amount above zero;
 *   unknown — nothing decided: an empty or non-numeric field. Shown as "confirm
 *             with us" or left out, never as free and never as $0, and never
 *             quoted: a zero printed or charged as a fact is a lie.
 * A single car may carry its own amount (GLC_Price_Store); it wins over the
 * site setting for that car's quote, form and facts, and for nothing else.
 * Pages that speak for the whole fleet print a summary of the cars instead.
 */

defined( 'ABSPATH' ) || exit;

class GLC_Rental {

	public const DEFAULT_PICKUP = 'tbilisi_office';
	public const DEFAULT_RETURN = 'tbilisi_office';

	/** A handover time as a client sends it: 24-hour HH:MM. The one pattern every API schema quotes. */
	public const TIME_PATTERN = '^([01][0-9]|2[0-3]):[0-5][0-9]$';

	/**
	 * Handover places, in the order the booking form lists them. Fees are in USD
	 * and charged per direction; each one is a setting (Settings → Geolander →
	 * Pricing), owner-set 2026-10-11. A place with no fee setting is free by
	 * policy: collecting at the office is not a delivery.
	 *
	 * The Kutaisi and Batumi keys keep the name `_airport` because machine clients
	 * already send them; each also covers a city address there at the same fee.
	 */
	private const LOCATIONS = [
		'tbilisi_office'  => [ 'label' => 'location_tbilisi_office',  'fee_setting' => null ],
		'tbilisi_city'    => [ 'label' => 'location_tbilisi_city',    'fee_setting' => 'tbilisi_city_each_way' ],
		'tbilisi_airport' => [ 'label' => 'location_tbilisi_airport', 'fee_setting' => 'tbilisi_airport_each_way' ],
		'kutaisi_airport' => [ 'label' => 'location_kutaisi_airport', 'fee_setting' => 'kutaisi_each_way' ],
		'batumi_airport'  => [ 'label' => 'location_batumi_airport',  'fee_setting' => 'batumi_each_way' ],
	];

	/** The places as the English sentence for machine clients names them. */
	private const SENTENCE_NAMES = [
		'tbilisi_city'    => 'Tbilisi city address',
		'tbilisi_airport' => 'Tbilisi Airport (TBS)',
		'kutaisi_airport' => 'Kutaisi (KUT airport or city)',
		'batumi_airport'  => 'Batumi (BUS airport or city)',
	];

	public static function init(): void {
		add_action( 'template_redirect', [ __CLASS__, 'redirect_duplicate_fleet' ], 0 );
		add_shortcode( 'geolander_fee', [ __CLASS__, 'fee_shortcode' ] );
	}

	/** Every place with its charge and state: the site's, or one car's when a car is named. */
	public static function locations( ?int $car_id = null ): array {
		$out = [];
		foreach ( self::LOCATIONS as $key => $location ) {
			$amount      = self::place_amount( $key, $car_id );
			$out[ $key ] = [
				'label' => glc_ui( $location['label'] ),
				'fee'   => (float) $amount,
				'free'  => 0.0 === $amount,
				'state' => self::state_of( $amount ),
			];
		}
		return $out;
	}

	/**
	 * `[geolander_fee place="tbilisi_airport"]` — the one way stored page text may
	 * show a delivery fee, so a sentence in the database never carries the number.
	 * On a car's own page it is that car's fee, exact; anywhere else it speaks for
	 * the fleet ("from $80" when cars differ). A fee that is not set prints
	 * "confirm with us", never a zero and never "free".
	 */
	public static function fee_shortcode( $atts ): string {
		$place = sanitize_key( (string) ( shortcode_atts( [ 'place' => '' ], $atts, 'geolander_fee' )['place'] ) );
		if ( ! self::valid_location( $place ) ) {
			return '';
		}
		$car_id = self::page_car();
		return self::fee_text( $car_id ? self::exact( self::place_amount( $place, $car_id ) ) : self::fee_summary( $place ) );
	}

	/** The car whose page this is, or null anywhere else: decides "this car's fee" against "the fleet's". */
	public static function page_car(): ?int {
		return is_singular( 'car' ) ? ( (int) get_queried_object_id() ?: null ) : null;
	}

	/**
	 * A charge in the visitor's language, from its state and amount. $free_key is
	 * the word for "free" where a table has its own ("Included").
	 */
	public static function fee_text( array $fee, string $free_key = 'free' ): string {
		switch ( $fee['state'] ) {
			case 'free':
				return glc_ui( $free_key );
			case 'priced':
				return GLC_Format::money_exact( (float) $fee['fee'] );
			case 'from':
				return sprintf( glc_ui( 'fee_from' ), GLC_Format::money_exact( (float) $fee['fee'] ) );
			case 'free_or_from':
				return sprintf( glc_ui( 'fee_free_or_from' ), GLC_Format::money_exact( (float) $fee['fee'] ) );
		}
		return glc_ui( 'planner_confirm_fee' );
	}

	/**
	 * The handover charges as English sentences, read by the machine-facing texts
	 * (pricing.md, the agent skill, the MCP and WebMCP policy answers): delivery
	 * per place, then the early/late handover. For the fleet by default, for one
	 * car when a car is named.
	 */
	public static function fee_sentence( ?int $car_id = null ): string {
		return trim( self::delivery_sentence( $car_id ) . ' ' . self::out_of_hours_sentence( $car_id ) );
	}

	/** The delivery half. A place whose fee is not set is left out rather than quoted as free. */
	public static function delivery_sentence( ?int $car_id = null ): string {
		$parts = [];
		$free  = [];
		foreach ( self::SENTENCE_NAMES as $key => $name ) {
			$fee   = $car_id ? self::exact( self::place_amount( $key, $car_id ) ) : self::fee_summary( $key );
			$money = GLC_Format::money_exact( (float) $fee['fee'], 'en' );
			if ( 'free' === $fee['state'] ) {
				$free[] = $name;
			} elseif ( 'priced' === $fee['state'] ) {
				$parts[] = $name . ' ' . $money;
			} elseif ( 'from' === $fee['state'] ) {
				$parts[] = $name . ' from ' . $money;
			} elseif ( 'free_or_from' === $fee['state'] ) {
				$parts[] = $name . ' free or from ' . $money . ' depending on the car';
			}
		}
		$out = 'Handover at the Tbilisi office (Mtatsminda) is free.';
		if ( $free ) {
			$out .= ' Delivery or collection is also free at: ' . implode( ', ', $free ) . '.';
		}
		if ( $parts ) {
			$out .= ' Delivery or collection is charged per direction: ' . implode( ', ', $parts )
				. '. A pickup and a return at the same place are charged twice; the quote shows them separately.';
		}
		return $out;
	}

	/** The early/late half, in the terms the quote charges it. Empty while the amount is not decided. */
	public static function out_of_hours_sentence( ?int $car_id = null ): string {
		$window = self::handover_window();
		if ( ! $window ) {
			return '';
		}
		$fee   = $car_id ? self::exact( self::out_of_hours_fee( $car_id ) ) : self::out_of_hours_summary();
		$money = GLC_Format::money_exact( (float) $fee['fee'], 'en' );
		$when  = sprintf( 'Pickup or return before %s or after %s', $window[0], $window[1] );
		switch ( $fee['state'] ) {
			case 'free':
				return $when . ' carries no extra charge.';
			case 'priced':
				return $when . ' is charged ' . $money . ' per handover, at the office too.';
			case 'from':
				return $when . ' is charged from ' . $money . ' per handover depending on the car, at the office too.';
			case 'free_or_from':
				return $when . ' is free or charged from ' . $money . ' per handover depending on the car, at the office too.';
		}
		return '';
	}

	/** Every handover key, for the API, MCP and OpenAPI enums: one list, read from here. */
	public static function location_keys(): array {
		return array_keys( self::LOCATIONS );
	}

	/** The places that carry a delivery fee, so the ones a single car may override. The office has none. */
	public static function fee_place_keys(): array {
		return array_keys( array_filter( self::LOCATIONS, static fn( array $location ): bool => null !== $location['fee_setting'] ) );
	}

	/** The setting that holds a place's site-wide fee; null for the office (no fee) and for a key that is no place. */
	public static function fee_setting_key( string $key ): ?string {
		return self::LOCATIONS[ $key ]['fee_setting'] ?? null;
	}

	public static function valid_location( string $key ): bool {
		return isset( self::LOCATIONS[ $key ] );
	}

	public static function location_label( string $key ): string {
		return self::valid_location( $key ) ? glc_ui( self::LOCATIONS[ $key ]['label'] ) : '';
	}

	/** The charge for one place, each way; 0.0 when it is free and also when it is unknown, so ask fee_state() first. */
	public static function location_fee( string $key, ?int $car_id = null ): float {
		return (float) self::place_amount( $key, $car_id );
	}

	/** `free`, `priced` or `unknown` for one place: the site's, or one car's when a car is named. */
	public static function fee_state( string $key, ?int $car_id = null ): string {
		return self::state_of( self::place_amount( $key, $car_id ) );
	}

	public static function is_free( string $key, ?int $car_id = null ): bool {
		return 'free' === self::fee_state( $key, $car_id );
	}

	public static function fee_is_set( string $key, ?int $car_id = null ): bool {
		return 'unknown' !== self::fee_state( $key, $car_id );
	}

	/**
	 * One place's charge across the published cars, for pages that speak for the
	 * whole fleet: [ 'state' => free | priced | from | free_or_from | unknown,
	 * 'fee' => the lowest amount above zero, 'known' => cars with an answer ].
	 * While no car has a fee of its own this is the site's own state.
	 */
	public static function fee_summary( string $key ): array {
		return self::summarise( self::across_fleet( static fn( ?int $car_id ): ?float => self::place_amount( $key, $car_id ) ) );
	}

	/**
	 * A pickup and a return together, across the published cars. Each car's two
	 * fees are added first and the cars compared after, so "from" is a total one
	 * car really charges: the two lowest fees may belong to two different cars.
	 */
	public static function pair_summary( string $pickup, string $return ): array {
		return self::summarise( self::across_fleet( static function ( ?int $car_id ) use ( $pickup, $return ): ?float {
			$out  = self::place_amount( $pickup, $car_id );
			$back = self::place_amount( $return, $car_id );
			return null === $out || null === $back ? null : $out + $back;
		} ) );
	}

	/* ------------------------------------------------ early or late handover */

	/** The hours with no extra charge, [ first minute, last minute ], or null when either setting is not a time. */
	public static function handover_window(): ?array {
		$start = GLC_Settings::get( 'handover_window_start' );
		$end   = GLC_Settings::get( 'handover_window_end' );
		return self::valid_time( $start ) && self::valid_time( $end ) ? [ $start, $end ] : null;
	}

	public static function valid_time( $time ): bool {
		return is_string( $time ) && 1 === preg_match( '/' . self::TIME_PATTERN . '/D', $time );
	}

	/**
	 * Is this wall-clock time at the car outside the free hours? Exactly the first
	 * or the last minute is inside. Text compares like time here because both
	 * sides are zero-padded HH:MM; no clock and no timezone is involved.
	 */
	public static function is_out_of_hours( string $hhmm ): bool {
		$window = self::handover_window();
		return null !== $window && ( $hhmm < $window[0] || $hhmm > $window[1] );
	}

	/** The charge for one early or late handover: a car's own amount if it has one, else the setting. null = not decided. */
	public static function out_of_hours_fee( ?int $car_id = null ): ?float {
		if ( $car_id ) {
			$own = self::decided( GLC_Price_Store::read_fees( $car_id )[ GLC_Price_Store::OUT_OF_HOURS ] );
			if ( null !== $own ) {
				return $own;
			}
		}
		return self::decided( GLC_Settings::get( 'out_of_hours_fee' ) );
	}

	/** That charge across the published cars; same shape as fee_summary(). */
	public static function out_of_hours_summary(): array {
		return self::summarise( self::across_fleet( [ __CLASS__, 'out_of_hours_fee' ] ) );
	}

	/**
	 * The early/late amount as a page prints it beside the hours: a car's own on
	 * its page, the fleet's elsewhere. Empty when there is nothing true to print
	 * (not decided for that car, or for any car), so the caller leaves the line out.
	 */
	public static function out_of_hours_text( ?int $car_id = null ): string {
		if ( $car_id ) {
			$amount = self::out_of_hours_fee( $car_id );
			return null === $amount ? '' : self::fee_text( self::exact( $amount ) );
		}
		$summary = self::out_of_hours_summary();
		return 'unknown' === $summary['state'] && ! $summary['known'] ? '' : self::fee_text( $summary );
	}

	/* ------------------------------------------------------------- the quote */

	/**
	 * Add the handover charges and the payment split to a vehicle quote.
	 *
	 * The two times are the clock at the car, HH:MM. One that is not sent is
	 * taken from the `handover_default_time` setting and the quote says so
	 * (`times_assumed`), which keeps clients that send only dates working.
	 * Days are still counted by the dates alone.
	 *
	 * @return array|WP_Error|null Complete quote; null when the places, dates or
	 *                             rates give none; WP_Error `glc_bad_time` for a
	 *                             time that is not HH:MM, `glc_fee_unknown` when
	 *                             a charge this request needs is not decided.
	 */
	public static function quote( int $car_id, string $from, string $to, string $pickup, string $return, ?string $pickup_time = null, ?string $return_time = null ) {
		if ( ! self::valid_location( $pickup ) || ! self::valid_location( $return ) ) {
			return null;
		}
		$assumed = false;
		$times   = [];
		foreach ( [ 'pickup' => $pickup_time, 'return' => $return_time ] as $side => $time ) {
			if ( null === $time ) {
				$time    = GLC_Settings::get( 'handover_default_time' );
				$assumed = true;
				if ( ! self::valid_time( $time ) ) {
					return self::fee_unknown();
				}
			} elseif ( ! self::valid_time( $time ) ) {
				return new WP_Error( 'glc_bad_time', __( 'Pickup and return times must be written as HH:MM in 24-hour time, for example 09:30.', 'geolander' ), [ 'status' => 400 ] );
			}
			$times[ $side ] = $time;
		}

		$base = GLC_Pricing::quote( $car_id, $from, $to );
		if ( ! $base ) {
			return null;
		}

		$pickup_fee      = self::place_amount( $pickup, $car_id );
		$return_fee      = self::place_amount( $return, $car_id );
		$pickup_time_fee = self::time_fee( $times['pickup'], $car_id );
		$return_time_fee = self::time_fee( $times['return'], $car_id );
		// A charge nobody has decided is not a charge of zero: no total is given at all.
		if ( null === $pickup_fee || null === $return_fee || null === $pickup_time_fee || null === $return_time_fee ) {
			return self::fee_unknown();
		}

		$rental     = (float) $base['total'];
		$total      = round( $rental + $pickup_fee + $return_fee + $pickup_time_fee + $return_time_fee, 2 );
		$prepayment = round( $total * ( (float) GLC_Settings::get( 'prepayment_percent', 10 ) / 100 ), 2 );

		return array_merge( $base, [
			'rental_total'    => $rental,
			'pickup'          => $pickup,
			'pickup_label'    => self::location_label( $pickup ),
			'pickup_fee'      => $pickup_fee,
			'return'          => $return,
			'return_label'    => self::location_label( $return ),
			'return_fee'      => $return_fee,
			'pickup_time'     => $times['pickup'],
			'return_time'     => $times['return'],
			'pickup_time_fee' => $pickup_time_fee,
			'return_time_fee' => $return_time_fee,
			'times_assumed'   => $assumed,
			'total'           => $total,
			'prepayment'      => $prepayment,
			'balance'         => round( $total - $prepayment, 2 ),
		] );
	}

	/** The early/late charge for one handover: 0.0 inside the hours, null when it cannot be known. */
	private static function time_fee( string $time, int $car_id ): ?float {
		if ( ! self::handover_window() ) {
			return null;
		}
		return self::is_out_of_hours( $time ) ? self::out_of_hours_fee( $car_id ) : 0.0;
	}

	private static function fee_unknown(): WP_Error {
		return new WP_Error( 'glc_fee_unknown', __( 'A charge for this pickup or return is not published yet, so it cannot be priced online. Ask us for the total.', 'geolander' ), [ 'status' => 400 ] );
	}

	/* --------------------------------------------------------------- amounts */

	/**
	 * What one place costs, each way: 0.0 = free, above zero = that amount,
	 * null = not known. With a car, the car's own fee wins over the site setting.
	 */
	private static function place_amount( string $key, ?int $car_id = null ): ?float {
		if ( ! self::valid_location( $key ) ) {
			return null;
		}
		$setting = self::LOCATIONS[ $key ]['fee_setting'];
		if ( null === $setting ) {
			return 0.0;
		}
		if ( $car_id ) {
			$own = self::decided( GLC_Price_Store::read_fees( $car_id )['delivery'][ $key ] ?? '' );
			if ( null !== $own ) {
				return $own;
			}
		}
		return self::decided( GLC_Settings::get( $setting ) );
	}

	/** A stored amount as a number, or null when it is not one: empty, a word, negative, not finite. */
	private static function decided( $raw ): ?float {
		if ( ! is_numeric( $raw ) ) {
			return null;
		}
		$amount = (float) $raw;
		return is_finite( $amount ) && $amount >= 0 ? $amount : null;
	}

	private static function state_of( ?float $amount ): string {
		return null === $amount ? 'unknown' : ( $amount > 0 ? 'priced' : 'free' );
	}

	/** One known amount in the shape a summary has, so fee_text() prints both. */
	private static function exact( ?float $amount ): array {
		return [ 'state' => self::state_of( $amount ), 'fee' => (float) $amount ];
	}

	/**
	 * Each published car's amount for something, or the site's own when no car is
	 * published. The car list and each car's meta come from WordPress's
	 * per-request caches, so asking again on one page costs no further query.
	 */
	private static function across_fleet( callable $amount ): array {
		$cars = get_posts( [ 'post_type' => 'car', 'post_status' => 'publish', 'posts_per_page' => -1, 'fields' => 'ids', 'no_found_rows' => true ] );
		if ( ! $cars ) {
			return [ $amount( null ) ];
		}
		return array_map( static fn( $car_id ): ?float => $amount( (int) $car_id ), $cars );
	}

	/**
	 * One answer for the fleet from each car's amount (null = that car has none).
	 * A single car without an answer makes the whole answer unknown; otherwise all
	 * free, all one price, "from" the lowest, or free for some and "from" the
	 * lowest amount above zero for the rest: a zero is never the "from".
	 */
	private static function summarise( array $amounts ): array {
		$known = array_filter( $amounts, static fn( $amount ): bool => null !== $amount );
		$paid  = array_filter( array_map( static fn( $amount ): float => round( (float) $amount, 2 ), $known ), static fn( float $amount ): bool => $amount > 0 );
		$fee   = $paid ? min( $paid ) : 0.0;
		if ( count( $known ) < count( $amounts ) ) {
			$state = 'unknown';
			$fee   = 0.0;
		} elseif ( ! $paid ) {
			$state = 'free';
		} elseif ( count( $paid ) < count( $known ) ) {
			$state = 'free_or_from';
		} else {
			$state = $fee === max( $paid ) ? 'priced' : 'from';
		}
		return [ 'state' => $state, 'fee' => $fee, 'known' => count( $known ) ];
	}

	/** Retain every consolidated fleet URL as a permanent localized redirect. */
	public static function redirect_duplicate_fleet(): void {
		$path = trim( (string) wp_parse_url( $_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH ), '/' );
		if ( ! preg_match( '#^(?:(en|ka|ru|uk|ar|zh|fr)/)?(fleet/[^/]+)$#i', $path, $match ) ) {
			return;
		}
		$redirects = (array) get_option( 'glc_fleet_redirects', [
			'fleet/toyota-rav4-2016-gg581wg' => 'fleet/toyota-rav4-2016-limited',
		] );
		$key = strtolower( $match[2] );
		if ( empty( $redirects[ $key ] ) ) {
			return;
		}
		$prefix = empty( $match[1] ) || 'en' === strtolower( $match[1] ) ? '' : strtolower( $match[1] ) . '/';
		wp_safe_redirect( home_url( '/' . $prefix . trim( $redirects[ $key ], '/' ) . '/' ), 301, 'Geolander' );
		exit;
	}
}
