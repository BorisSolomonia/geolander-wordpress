<?php
/**
 * Sample quotes for a preview, each priced twice.
 *
 * Before a price table or a fee is saved, the screen shows what a few sample
 * rentals cost now and what they would cost after the save. Every figure is
 * computed by two paths that share no arithmetic:
 *
 *  - engine():    the real quote engine (GLC_Rental::quote), looking at the car
 *                 as if the unsaved state were stored. Nothing is written.
 *  - recompute(): a plain walk written here: one day at a time through a
 *                 365-day map of the table, the band found from the band's own
 *                 label, the fees read straight from the car and the settings.
 *
 * The screen prints both. When they differ it says so in red and does not pick
 * one: a difference means one of the two is wrong, and a person has to look.
 *
 * Which rentals are sampled is a setting (`preview_samples`), one per line:
 *   MM-DD days [pickup return [HH:MM HH:MM]]
 * The start is the next time that day of the year comes round.
 */

defined( 'ABSPATH' ) || exit;

class GLC_Quote_Check {

	/** Two totals this close are the same amount of money. */
	private const CENT = 0.005;

	/** Seasons are yearly MM-DD; any ordinary year lays them out over their 365 days. */
	private const CALENDAR_YEAR = 2001;
	private const YEAR_DAYS     = 365;

	/** The leap day is priced like the day before it, as the engine reads it. */
	private const LEAP_DAY         = '02-29';
	private const LEAP_DAY_READ_AS = '02-28';

	/**
	 * The setting's text as a list of samples, or null when a line cannot be read.
	 *
	 * @return array[]|null each: start (MM-DD), days, pickup, return, pickup_time, return_time (null = the default time)
	 */
	public static function parse_samples( string $text ): ?array {
		$samples = [];
		foreach ( preg_split( '/\R/', $text ) as $line ) {
			$line = trim( $line );
			if ( '' === $line ) {
				continue;
			}
			$parts = preg_split( '/\s+/', $line );
			if ( ! in_array( count( $parts ), [ 2, 4, 6 ], true )
				|| ! preg_match( '/^(\d{2})-(\d{2})$/', $parts[0], $day ) || ! checkdate( (int) $day[1], (int) $day[2], self::CALENDAR_YEAR )
				|| ! ctype_digit( $parts[1] ) || (int) $parts[1] < 1 ) {
				return null;
			}
			$pickup = $parts[2] ?? GLC_Rental::DEFAULT_PICKUP;
			$return = $parts[3] ?? GLC_Rental::DEFAULT_RETURN;
			if ( ! GLC_Rental::valid_location( $pickup ) || ! GLC_Rental::valid_location( $return ) ) {
				return null;
			}
			if ( isset( $parts[4] ) && ( ! GLC_Rental::valid_time( $parts[4] ) || ! GLC_Rental::valid_time( $parts[5] ) ) ) {
				return null;
			}
			$samples[] = [
				'start'       => $parts[0],
				'days'        => (int) $parts[1],
				'pickup'      => $pickup,
				'return'      => $return,
				'pickup_time' => $parts[4] ?? null,
				'return_time' => $parts[5] ?? null,
			];
		}
		return $samples;
	}

	/**
	 * The samples with real dates: each starts the next time its day comes round,
	 * counted from today in the display timezone.
	 *
	 * @return array[] each sample plus from, to (Y-m-d)
	 */
	public static function samples( ?DateTimeImmutable $today = null ): array {
		$today = $today ?? new DateTimeImmutable( 'now', GLC_Settings::timezone() );
		$out   = [];
		foreach ( self::parse_samples( (string) GLC_Settings::get( 'preview_samples' ) ) ?? [] as $sample ) {
			$year  = (int) $today->format( 'Y' );
			$start = new DateTimeImmutable( $year . '-' . $sample['start'], GLC_Settings::timezone() );
			if ( $start <= $today ) {
				$start = new DateTimeImmutable( ( $year + 1 ) . '-' . $sample['start'], GLC_Settings::timezone() );
			}
			$out[] = $sample + [
				'from' => $start->format( 'Y-m-d' ),
				'to'   => $start->modify( '+' . $sample['days'] . ' days' )->format( 'Y-m-d' ),
			];
		}
		return $out;
	}

	/**
	 * Every sample priced for each state handed in, by both paths.
	 *
	 * @param array $states [ name => [ 'pricing' => table, 'fees' => [ delivery, out_of_hours ] ] ]
	 * @return array[] each: sample, and per state name [ engine, check, agree ] (a total, or null for "no quote")
	 */
	public static function rows( int $car_id, array $states ): array {
		$rows = [];
		foreach ( self::samples() as $sample ) {
			$row = [ 'sample' => $sample ];
			foreach ( $states as $name => $state ) {
				$engine       = self::engine( $car_id, $state, $sample );
				$check        = self::recompute( $state, $sample );
				$row[ $name ] = [
					'engine' => $engine,
					'check'  => $check,
					'agree'  => null === $engine || null === $check ? $engine === $check : abs( $engine - $check ) < self::CENT,
				];
			}
			$rows[] = $row;
		}
		return $rows;
	}

	/** The real engine's total for one sample, with the car seen in the given state. null = it gives no quote. */
	public static function engine( int $car_id, array $state, array $sample ): ?float {
		$quote = GLC_Price_Store::with_state( $car_id, $state, static fn() => GLC_Rental::quote(
			$car_id,
			$sample['from'],
			$sample['to'],
			$sample['pickup'],
			$sample['return'],
			$sample['pickup_time'],
			$sample['return_time']
		) );
		return is_array( $quote ) ? (float) $quote['total'] : null;
	}

	/**
	 * The same total by a second path. It shares the table, the fees and the
	 * settings with the engine, and none of its code. null = no total can be given.
	 */
	public static function recompute( array $state, array $sample ): ?float {
		$nights = (int) round( ( strtotime( $sample['to'] . ' UTC' ) - strtotime( $sample['from'] . ' UTC' ) ) / DAY_IN_SECONDS );
		$band   = self::band_for( $nights );
		$map    = self::day_map( (array) ( $state['pricing'] ?? [] ) );
		if ( $nights < 1 || null === $band ) {
			return null;
		}
		$sum = 0.0;
		for ( $night = 0; $night < $nights; $night++ ) {
			$day = gmdate( 'm-d', strtotime( $sample['from'] . ' UTC' ) + $night * DAY_IN_SECONDS );
			$day = self::LEAP_DAY === $day ? self::LEAP_DAY_READ_AS : $day;
			$rate = $map[ $day ][ $band ] ?? null;
			if ( ! is_numeric( $rate ) || (float) $rate <= 0 ) {
				return null;
			}
			$sum += (float) $rate;
		}
		$fees = (array) ( $state['fees'] ?? [] );
		foreach ( [ 'pickup', 'return' ] as $side ) {
			$place = self::place_fee( $sample[ $side ], (array) ( $fees['delivery'] ?? [] ) );
			$time  = self::time_fee( $sample[ $side . '_time' ] ?? GLC_Settings::get( 'handover_default_time' ), $fees[ GLC_Price_Store::OUT_OF_HOURS ] ?? '' );
			if ( null === $place || null === $time ) {
				return null;
			}
			$sum += $place + $time;
		}
		return round( $sum, 2 );
	}

	/** The band a rental of this many days falls in, read from the bands' own labels ("1-2" … "31+"). */
	private static function band_for( int $days ): ?string {
		foreach ( GLC_Pricing::TIER_LABELS as $band => $label ) {
			if ( preg_match( '/^(\d+)-(\d+)$/', $label, $m ) && $days >= (int) $m[1] && $days <= (int) $m[2] ) {
				return $band;
			}
			if ( preg_match( '/^(\d+)\+$/', $label, $m ) && $days >= (int) $m[1] ) {
				return $band;
			}
		}
		return null;
	}

	/** [ MM-DD => that day's seven prices ]: every season laid out over an ordinary year; the first season to claim a day keeps it. */
	private static function day_map( array $seasons ): array {
		$map = [];
		foreach ( $seasons as $season ) {
			if ( ! is_array( $season ) || ! preg_match( '/^\d{2}-\d{2}$/', (string) ( $season['from'] ?? '' ) ) || ! preg_match( '/^\d{2}-\d{2}$/', (string) ( $season['to'] ?? '' ) ) ) {
				continue;
			}
			$stamp = gmmktime( 0, 0, 0, (int) substr( $season['from'], 0, 2 ), (int) substr( $season['from'], 3, 2 ), self::CALENDAR_YEAR );
			// At most one year of steps: a season cannot be longer, and a date that never comes must not loop for ever.
			for ( $step = 0; $step < self::YEAR_DAYS; $step++ ) {
				$day = gmdate( 'm-d', $stamp + $step * DAY_IN_SECONDS );
				if ( ! isset( $map[ $day ] ) ) {
					$map[ $day ] = (array) ( $season['rates'] ?? [] );
				}
				if ( $day === $season['to'] ) {
					break;
				}
			}
		}
		return $map;
	}

	/** What one place costs each way for this car: its own amount, else the site's; null = nobody has decided. */
	private static function place_fee( string $place, array $own ): ?float {
		$setting = GLC_Rental::fee_setting_key( $place );
		if ( null === $setting ) {
			return GLC_Rental::valid_location( $place ) ? 0.0 : null;
		}
		foreach ( [ $own[ $place ] ?? '', GLC_Settings::get( $setting ) ] as $amount ) {
			if ( is_numeric( $amount ) && (float) $amount >= 0 ) {
				return (float) $amount;
			}
		}
		return null;
	}

	/** The early/late charge for one handover at this clock time; null = it applies and nobody has decided it. */
	private static function time_fee( $time, $own ): ?float {
		$start = GLC_Settings::get( 'handover_window_start' );
		$end   = GLC_Settings::get( 'handover_window_end' );
		if ( ! GLC_Rental::valid_time( $time ) || ! GLC_Rental::valid_time( $start ) || ! GLC_Rental::valid_time( $end ) ) {
			return null;
		}
		$minutes = static fn( string $hhmm ): int => 60 * (int) substr( $hhmm, 0, 2 ) + (int) substr( $hhmm, 3, 2 );
		if ( $minutes( $time ) >= $minutes( $start ) && $minutes( $time ) <= $minutes( $end ) ) {
			return 0.0;
		}
		foreach ( [ $own, GLC_Settings::get( 'out_of_hours_fee' ) ] as $amount ) {
			if ( is_numeric( $amount ) && (float) $amount >= 0 ) {
				return (float) $amount;
			}
		}
		return null;
	}
}
