<?php
/**
 * Seasonal, duration-tiered pricing engine.
 *
 * A car's price list is a set of seasons (MM-DD ranges, year-agnostic) each
 * holding seven day-rates keyed by total rental duration. A quote prices
 * every rental day at its own season's rate for the tier of the WHOLE
 * rental, so a trip spanning two seasons blends both rates.
 */

defined( 'ABSPATH' ) || exit;

class GLC_Pricing {

	public const TIERS = [ 'd1_2', 'd3_4', 'd5_7', 'd8_12', 'd13_18', 'd19_30', 'd31p' ];

	public const TIER_LABELS = [
		'd1_2'   => '1-2',
		'd3_4'   => '3-4',
		'd5_7'   => '5-7',
		'd8_12'  => '8-12',
		'd13_18' => '13-18',
		'd19_30' => '19-30',
		'd31p'   => '31+',
	];

	public static function tier_for_days( int $days ): string {
		return match ( true ) {
			$days <= 2  => 'd1_2',
			$days <= 4  => 'd3_4',
			$days <= 7  => 'd5_7',
			$days <= 12 => 'd8_12',
			$days <= 18 => 'd13_18',
			$days <= 30 => 'd19_30',
			default     => 'd31p',
		};
	}

	/**
	 * Find the season row covering a date. Seasons wrap year boundaries
	 * (e.g. Dec 25 – Jan 05), so compare on MM-DD ordinals.
	 */
	public static function season_for_date( array $pricing, DateTimeImmutable $date ): ?array {
		$md = $date->format( 'm-d' );
		foreach ( $pricing as $season ) {
			$from = $season['from'] ?? '';
			$to   = $season['to'] ?? '';
			if ( ! $from || ! $to ) {
				continue;
			}
			$in_range = $from <= $to
				? ( $md >= $from && $md <= $to )
				: ( $md >= $from || $md <= $to ); // wraps New Year
			if ( $in_range ) {
				return $season;
			}
		}
		return null;
	}

	/**
	 * Quote a rental. Returns null when input is invalid or no rates exist.
	 *
	 * @return array{days:int, tier:string, total:float, per_day_avg:float, breakdown:array}|null
	 */
	public static function quote( int $car_id, string $from, string $to ): ?array {
		try {
			$start = new DateTimeImmutable( $from );
			$end   = new DateTimeImmutable( $to );
		} catch ( Exception ) {
			return null;
		}
		if ( $end <= $start ) {
			return null;
		}

		$days = (int) $start->diff( $end )->days;
		if ( $days < 1 ) {
			return null;
		}

		$pricing = get_post_meta( $car_id, 'glc_pricing', true );
		if ( ! is_array( $pricing ) || ! $pricing ) {
			return null;
		}

		$tier      = self::tier_for_days( $days );
		$total     = 0.0;
		$breakdown = [];

		for ( $i = 0; $i < $days; $i++ ) {
			$day    = $start->modify( "+{$i} days" );
			$season = self::season_for_date( $pricing, $day );
			$rate   = $season ? (float) ( $season['rates'][ $tier ] ?? 0 ) : 0.0;
			if ( $rate <= 0 ) {
				// Fall back to the car's headline rate so a gap in the
				// season table never produces a free day.
				$rate = (float) get_post_meta( $car_id, 'glc_price_from', true );
			}
			$total += $rate;
			$label  = $season['label'] ?? '';
			if ( ! isset( $breakdown[ $label ] ) ) {
				$breakdown[ $label ] = [ 'days' => 0, 'rate' => $rate ];
			}
			$breakdown[ $label ]['days']++;
		}

		return [
			'days'        => $days,
			'tier'        => $tier,
			'total'       => round( $total, 2 ),
			'per_day_avg' => round( $total / $days, 2 ),
			'breakdown'   => $breakdown,
			'currency'    => 'USD',
		];
	}

	/** Lowest and highest day-rate across all seasons/tiers (for schema offers). */
	public static function rate_range( int $car_id ): array {
		$pricing = get_post_meta( $car_id, 'glc_pricing', true );
		$rates   = [];
		if ( is_array( $pricing ) ) {
			foreach ( $pricing as $season ) {
				foreach ( (array) ( $season['rates'] ?? [] ) as $r ) {
					if ( (float) $r > 0 ) {
						$rates[] = (float) $r;
					}
				}
			}
		}
		if ( ! $rates ) {
			$from = (float) get_post_meta( $car_id, 'glc_price_from', true );
			return [ $from, $from ];
		}
		return [ min( $rates ), max( $rates ) ];
	}
}
