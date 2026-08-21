<?php
/**
 * Rental policies and location-aware quotes.
 *
 * Vehicle day rates remain in GLC_Pricing. This class adds the business rules
 * that apply to a booking: pickup/return locations, delivery charges and the
 * confirmation prepayment. Keeping them server-side means the page, WhatsApp
 * request and confirmation email always use the same final calculation.
 */

defined( 'ABSPATH' ) || exit;

class GLC_Rental {

	public const DEFAULT_PICKUP = 'tbilisi_office';
	public const DEFAULT_RETURN = 'tbilisi_office';

	/** Owner-confirmed location fees in USD, charged per direction. */
	private const LOCATIONS = [
		'tbilisi_office'  => [ 'label' => 'location_tbilisi_office',  'fee_setting' => null ],
		'tbilisi_airport' => [ 'label' => 'location_tbilisi_airport', 'fee_setting' => null ],
		'kutaisi_airport' => [ 'label' => 'location_kutaisi_airport', 'fee_setting' => 'kutaisi_each_way' ],
		'batumi_airport'  => [ 'label' => 'location_batumi_airport',  'fee_setting' => 'batumi_each_way' ],
	];

	public static function init(): void {
		add_action( 'template_redirect', [ __CLASS__, 'redirect_duplicate_rav4' ], 0 );
	}

	public static function locations(): array {
		$out = [];
		foreach ( self::LOCATIONS as $key => $location ) {
			$out[ $key ] = [
				'label' => glc_ui( $location['label'] ),
				'fee'   => self::location_fee( $key ),
			];
		}
		return $out;
	}

	public static function valid_location( string $key ): bool {
		return isset( self::LOCATIONS[ $key ] );
	}

	public static function location_label( string $key ): string {
		return self::valid_location( $key ) ? glc_ui( self::LOCATIONS[ $key ]['label'] ) : '';
	}

	public static function location_fee( string $key ): float {
		if ( ! self::valid_location( $key ) ) {
			return 0.0;
		}
		$setting = self::LOCATIONS[ $key ]['fee_setting'];
		return $setting ? max( 0.0, (float) GLC_Settings::get( $setting ) ) : 0.0;
	}

	/**
	 * Add selected-location charges and payment split to a vehicle quote.
	 *
	 * @return array|null Complete quote, or null when dates/rates are invalid.
	 */
	public static function quote( int $car_id, string $from, string $to, string $pickup, string $return ): ?array {
		if ( ! self::valid_location( $pickup ) || ! self::valid_location( $return ) ) {
			return null;
		}
		$base = GLC_Pricing::quote( $car_id, $from, $to );
		if ( ! $base ) {
			return null;
		}

		$pickup_fee  = self::location_fee( $pickup );
		$return_fee  = self::location_fee( $return );
		$rental      = (float) $base['total'];
		$total       = round( $rental + $pickup_fee + $return_fee, 2 );
		$prepayment  = round( $total * ( (float) GLC_Settings::get( 'prepayment_percent', 10 ) / 100 ), 2 );

		return array_merge( $base, [
			'rental_total' => $rental,
			'pickup'       => $pickup,
			'pickup_label' => self::location_label( $pickup ),
			'pickup_fee'   => $pickup_fee,
			'return'       => $return,
			'return_label' => self::location_label( $return ),
			'return_fee'   => $return_fee,
			'total'        => $total,
			'prepayment'   => $prepayment,
			'balance'      => round( $total - $prepayment, 2 ),
		] );
	}

	/**
	 * The legacy RAV4 URL belongs to the same physical car. It is retained as a
	 * permanent redirect even after the duplicate post is moved to draft.
	 */
	public static function redirect_duplicate_rav4(): void {
		$path = trim( (string) wp_parse_url( $_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH ), '/' );
		if ( ! preg_match( '#^(?:(en|ka|ru|uk|ar|zh|fr)/)?fleet/toyota-rav4-2016-gg581wg$#i', $path, $match ) ) {
			return;
		}
		$prefix = empty( $match[1] ) || 'en' === strtolower( $match[1] ) ? '' : strtolower( $match[1] ) . '/';
		wp_safe_redirect( home_url( '/' . $prefix . 'fleet/toyota-rav4-2016-limited/' ), 301, 'Geolander' );
		exit;
	}
}
