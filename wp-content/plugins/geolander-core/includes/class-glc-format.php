<?php
/**
 * Locale-aware formatting for money, numbers, and dates.
 *
 * Prices, totals, and rental dates were rendered with US conventions on every
 * locale — number_format() (1,234) and a hardcoded "$" prefix — so a French or
 * Georgian visitor saw "$1,234" where their locale expects "1 234 $". Dates were
 * printed as raw ISO ("2026-07-20"). This centralises the rules so every render
 * path formats per the visitor's active locale.
 *
 * Deliberately does NOT use PHP's intl NumberFormatter: the ext-intl extension
 * is not present in the wordpress:php8.3-apache image, so intl calls would fatal
 * in production. These rules are a small, explicit table instead — the site has
 * seven known locales and one currency, so a full ICU dependency buys nothing.
 */

defined( 'ABSPATH' ) || exit;

class GLC_Format {

	/**
	 * Per-locale conventions.
	 *  sep         — thousands separator
	 *  sym_before  — currency symbol before the amount (true) or after (false)
	 *  date        — date() pattern; non-English locales use numeric months so we
	 *                never leak English month names ("Jul") into a localized page.
	 */
	private const RULES = [
		'en' => [ 'sep' => ',',        'sym_before' => true,  'date' => 'M j, Y' ],
		'ka' => [ 'sep' => ' ',        'sym_before' => false, 'date' => 'd.m.Y' ],
		'ru' => [ 'sep' => ' ',        'sym_before' => false, 'date' => 'd.m.Y' ],
		'uk' => [ 'sep' => ' ',        'sym_before' => false, 'date' => 'd.m.Y' ],
		'fr' => [ 'sep' => ' ',        'sym_before' => false, 'date' => 'd/m/Y' ],
		'ar' => [ 'sep' => ',',        'sym_before' => false, 'date' => 'd/m/Y' ],
		'zh' => [ 'sep' => ',',        'sym_before' => true,  'date' => 'Y-m-d' ],
	];

	private const FALLBACK = 'en';

	private static function rules( ?string $locale = null ): array {
		$locale = $locale ?: ( class_exists( 'GLC_I18n' ) ? GLC_I18n::locale() : self::FALLBACK );
		return self::RULES[ $locale ] ?? self::RULES[ self::FALLBACK ];
	}

	/** Currency symbol for the configured currency (USD unless changed). */
	private static function symbol(): string {
		$currency = class_exists( 'GLC_Settings' ) ? GLC_Settings::get( 'payment_currency', 'USD' ) : 'USD';
		return [ 'USD' => '$', 'EUR' => '€', 'GEL' => '₾', 'GBP' => '£' ][ $currency ] ?? $currency;
	}

	/** A plain number with locale thousands separators. No decimals by design. */
	public static function number( float $value, ?string $locale = null ): string {
		$r = self::rules( $locale );
		return number_format( $value, 0, '.', $r['sep'] );
	}

	/** A price, e.g. "$1,234" (en) or "1 234 $" (fr/ka/ru). */
	public static function money( float $value, ?string $locale = null ): string {
		$r      = self::rules( $locale );
		$amount = self::number( $value, $locale );
		$symbol = self::symbol();
		return $r['sym_before'] ? $symbol . $amount : $amount . ' ' . $symbol;
	}

	/** An ISO Y-m-d date rendered per locale; returns input unchanged if unparseable. */
	public static function date( string $iso, ?string $locale = null ): string {
		try {
			$d = new DateTimeImmutable( $iso );
		} catch ( Exception ) {
			return $iso;
		}
		return $d->format( self::rules( $locale )['date'] );
	}

	/** Headline range low/high as raw numbers, from settings. */
	public static function range(): array {
		return [
			(float) GLC_Settings::get( 'price_min', 28 ),
			(float) GLC_Settings::get( 'price_max', 120 ),
		];
	}

	/** Headline range rendered for the active locale, e.g. "$28 – $120". */
	public static function range_display( ?string $locale = null ): string {
		[ $low, $high ] = self::range();
		return self::money( $low, $locale ) . ' – ' . self::money( $high, $locale );
	}

	/** The rules the front-end JS needs so client-side updates match the server. */
	public static function js_config( ?string $locale = null ): array {
		$r = self::rules( $locale );
		return [
			'sep'        => $r['sep'],
			'symBefore'  => $r['sym_before'],
			'symbol'     => self::symbol(),
			'datePattern'=> $r['date'],
		];
	}
}
