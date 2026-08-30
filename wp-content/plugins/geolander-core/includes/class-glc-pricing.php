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

	/**
	 * Legacy import shape → canonical shape. Defensive, not the root cause.
	 *
	 * `_migration/cars.json` stores a season as
	 * `{"period": "Apr 01 - Oct 31", "prices": {"days1To2": 50, …}}` while this
	 * class reads `from`/`to`/`rates` keyed `d1_2, d3_4, …`. `import.php` does
	 * convert between the two, so the original fleet is stored correctly — but
	 * anything that writes `glc_pricing` straight from a JSON payload (as
	 * `import-fleet.php` does) is one shape mismatch away from a season table
	 * that silently matches nothing, which collapses every AggregateOffer to the
	 * headline price or, when that is empty too, to zero.
	 *
	 * Normalising on READ means the engine cannot be broken by whichever shape
	 * happens to be in the database, now or after a future import.
	 *
	 * The actual cause of the published "$0/day" was simpler and is fixed in
	 * `import-fleet.php`: every sidecar is named `car.json.json`, the importer
	 * looked for `car.json`, and so no pricing was ever applied at all.
	 */
	public static function normalize( $pricing ): array {
		if ( ! is_array( $pricing ) ) {
			return [];
		}

		static $tier_alias = [
			'days1To2'   => 'd1_2',
			'days3To4'   => 'd3_4',
			'days5To7'   => 'd5_7',
			'days8To12'  => 'd8_12',
			'days13To18' => 'd13_18',
			'days19To30' => 'd19_30',
			'days31Plus' => 'd31p',
		];

		$out = [];
		foreach ( $pricing as $season ) {
			if ( ! is_array( $season ) ) {
				continue;
			}

			$rates = $season['rates'] ?? $season['prices'] ?? [];
			if ( is_array( $rates ) ) {
				foreach ( $tier_alias as $legacy => $canonical ) {
					if ( isset( $rates[ $legacy ] ) && ! isset( $rates[ $canonical ] ) ) {
						$rates[ $canonical ] = $rates[ $legacy ];
					}
				}
			}

			$from  = (string) ( $season['from'] ?? '' );
			$to    = (string) ( $season['to'] ?? '' );
			$label = (string) ( $season['label'] ?? $season['period'] ?? '' );

			// "Apr 01 - Oct 31" → from "04-01", to "10-31".
			if ( ( '' === $from || '' === $to ) && $label
				&& preg_match( '/^\s*([A-Za-z]{3,})\s+(\d{1,2})\s*[-–]\s*([A-Za-z]{3,})\s+(\d{1,2})\s*$/', $label, $m ) ) {
				$month = static function ( string $name ): string {
					$ts = strtotime( $name . ' 1 2001' );
					return $ts ? gmdate( 'm', $ts ) : '';
				};
				$mf = $month( $m[1] );
				$mt = $month( $m[3] );
				if ( '' !== $mf && '' !== $mt ) {
					$from = $mf . '-' . str_pad( $m[2], 2, '0', STR_PAD_LEFT );
					$to   = $mt . '-' . str_pad( $m[4], 2, '0', STR_PAD_LEFT );
				}
			}

			$out[] = [
				'label' => $label,
				'from'  => $from,
				'to'    => $to,
				'rates' => is_array( $rates ) ? $rates : [],
			];
		}
		return $out;
	}

	/** The car's season table, normalised. */
	public static function seasons( int $car_id ): array {
		return self::normalize( get_post_meta( $car_id, 'glc_pricing', true ) );
	}

	/**
	 * Is this car genuinely priced?
	 *
	 * Nothing that advertises a price — schema, meta description, /pricing.md,
	 * /llms.txt, the fleet card — may render a number for a car where this
	 * returns false. No price is a legitimate state; a price of zero is a false
	 * statement about the business, and it was being served to search engines
	 * and to every AI crawler robots.txt invites.
	 */
	public static function is_priced( int $car_id ): bool {
		[ $low, $high ] = self::rate_range( $car_id );
		return $low > 0 && $high > 0;
	}

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

		$pricing = self::seasons( $car_id );
		if ( ! $pricing ) {
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

	/**
	 * Lowest and highest day-rate across all seasons/tiers (for schema offers).
	 *
	 * Returns [0.0, 0.0] when the car has no usable rate anywhere — callers MUST
	 * treat that as "unpriced" and omit the price entirely rather than printing
	 * a zero. Use is_priced() for that check.
	 */
	public static function rate_range( int $car_id ): array {
		$rates = [];
		foreach ( self::seasons( $car_id ) as $season ) {
			foreach ( (array) ( $season['rates'] ?? [] ) as $r ) {
				if ( (float) $r > 0 ) {
					$rates[] = (float) $r;
				}
			}
		}
		if ( ! $rates ) {
			$from = (float) get_post_meta( $car_id, 'glc_price_from', true );
			return $from > 0 ? [ $from, $from ] : [ 0.0, 0.0 ];
		}
		return [ min( $rates ), max( $rates ) ];
	}

	/**
	 * The cheapest day-rate across the whole published fleet — the single source
	 * of truth for the advertised "from $N/day" floor.
	 *
	 * The site previously advertised $26 in one place and $28 in another because
	 * the title tag read a settings value while the copy read another. Deriving
	 * it from the cheapest actually-bookable car means the snippet, the hero, the
	 * schema priceRange and llms.txt cannot disagree again.
	 */
	public static function fleet_floor(): float {
		static $floor = null;
		if ( null !== $floor ) {
			return $floor;
		}
		$lows = [];
		foreach ( get_posts( [
			'post_type'      => 'car',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
		] ) as $car_id ) {
			[ $low ] = self::rate_range( (int) $car_id );
			if ( $low > 0 ) {
				$lows[] = $low;
			}
		}
		$floor = $lows ? min( $lows ) : 0.0;
		return $floor;
	}

	/** Highest real day-rate across published, priced vehicles. */
	public static function fleet_ceiling(): float {
		static $ceiling = null;
		if ( null !== $ceiling ) {
			return $ceiling;
		}
		$highs = [];
		foreach ( get_posts( [
			'post_type'      => 'car',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
		] ) as $car_id ) {
			[ , $high ] = self::rate_range( (int) $car_id );
			if ( $high > 0 ) {
				$highs[] = $high;
			}
		}
		$ceiling = $highs ? max( $highs ) : 0.0;
		return $ceiling;
	}
}
