<?php
/**
 * Text for the partner panel and the other admin screens this plugin adds.
 *
 * WordPress language packs cannot be installed on this site (DISALLOW_FILE_MODS
 * is on), so a Georgian panel has to carry its own words. They live in
 * includes/admin-strings/<lang>/*.php, one small file per screen, each returning
 * key => text. Every file of a language is merged into one catalogue; the same
 * keys must exist in every language (tests/admin-strings.php checks that).
 *
 * The language is the reader's, not the site's: user meta `glc_panel_lang`,
 * else the two-letter start of their WordPress locale when the panel has that
 * language, else English. Visitor-facing text is not here; it stays in the
 * theme's inc/strings-<locale>.php behind glc_t().
 */

defined( 'ABSPATH' ) || exit;

class GLC_Admin_Strings {

	public const DEFAULT_LANG = 'en';
	public const USER_META    = 'glc_panel_lang';

	/** Every panel language: one folder each under admin-strings/. */
	public static function languages(): array {
		static $languages = null;
		if ( null === $languages ) {
			$languages = array_map( 'basename', glob( __DIR__ . '/admin-strings/*', GLOB_ONLYDIR ) ?: [] );
			sort( $languages );
		}
		return $languages;
	}

	/** The panel language of one user (default: whoever is logged in). */
	public static function lang( ?int $user_id = null ): string {
		$user_id = $user_id ?? (int) get_current_user_id();
		if ( ! $user_id ) {
			return self::DEFAULT_LANG;
		}
		$chosen = (string) get_user_meta( $user_id, self::USER_META, true );
		if ( in_array( $chosen, self::languages(), true ) ) {
			return $chosen;
		}
		$locale = strtolower( substr( (string) get_user_locale( $user_id ), 0, 2 ) );
		return in_array( $locale, self::languages(), true ) ? $locale : self::DEFAULT_LANG;
	}

	/** All files of one language, merged. */
	public static function catalogue( string $lang ): array {
		static $loaded = [];
		if ( ! isset( $loaded[ $lang ] ) ) {
			$loaded[ $lang ] = [];
			if ( in_array( $lang, self::languages(), true ) ) {
				foreach ( glob( __DIR__ . '/admin-strings/' . $lang . '/*.php' ) ?: [] as $file ) {
					$loaded[ $lang ] += (array) require $file;
				}
			}
		}
		return $loaded[ $lang ];
	}

	/** A language's own name for itself ("ქართული"), read from that language's catalogue. */
	public static function name( string $lang ): string {
		return self::catalogue( $lang )['lang_name'] ?? $lang;
	}

	public static function has( string $key ): bool {
		return isset( self::catalogue( self::DEFAULT_LANG )[ $key ] );
	}

	/** One string. A key missing in the reader's language falls back to English, then to the key itself. */
	public static function t( string $key, ?string $lang = null ): string {
		$lang = $lang ?? self::lang();
		return self::catalogue( $lang )[ $key ] ?? self::catalogue( self::DEFAULT_LANG )[ $key ] ?? $key;
	}

	/** "Mar 1" / "1 მარ": a day of the year in the reader's language, from a stored MM-DD. */
	public static function day( string $mm_dd, ?string $lang = null ): string {
		[ $month, $day ] = array_map( 'intval', explode( '-', $mm_dd ) + [ 0, 0 ] );
		return sprintf( self::t( 'day_month', $lang ), $day, self::t( 'month_' . $month, $lang ) );
	}
}

function glc_admin_t( string $key, ?string $lang = null ): string {
	return GLC_Admin_Strings::t( $key, $lang );
}
