<?php
/**
 * Lightweight multilingual layer.
 *
 * URL scheme: `/` = English (x-default); `/{ka|ru|uk|ar|zh|fr}/…` = localized
 * variants of the same routes. The prefix is stripped before WP routing, and
 * home_url() is filtered so every generated link stays inside the current
 * locale. First visit without a prefix redirects by Accept-Language (browsers
 * only — requests without the header, e.g. crawlers, always get x-default).
 */

defined( 'ABSPATH' ) || exit;

class GLC_I18n {

	public const LOCALES = [
		'en' => [ 'name' => 'English',    'dir' => 'ltr', 'hreflang' => 'en' ],
		'ka' => [ 'name' => 'ქართული',    'dir' => 'ltr', 'hreflang' => 'ka' ],
		'ru' => [ 'name' => 'Русский',    'dir' => 'ltr', 'hreflang' => 'ru' ],
		'uk' => [ 'name' => 'Українська', 'dir' => 'ltr', 'hreflang' => 'uk' ],
		'ar' => [ 'name' => 'العربية',    'dir' => 'rtl', 'hreflang' => 'ar' ],
		'zh' => [ 'name' => '中文',        'dir' => 'ltr', 'hreflang' => 'zh' ],
		'fr' => [ 'name' => 'Français',   'dir' => 'ltr', 'hreflang' => 'fr' ],
	];

	public const DEFAULT_LOCALE = 'en';
	private const COOKIE        = 'glc_lang';

	private static string $locale = self::DEFAULT_LOCALE;

	/** Called from the plugin bootstrap as early as possible. */
	public static function boot() {
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() ) {
			return;
		}
		$uri  = $_SERVER['REQUEST_URI'] ?? '/';
		$path = wp_parse_url( $uri, PHP_URL_PATH ) ?? '/';

		// Never touch system paths.
		if ( preg_match( '#^/(wp-admin|wp-json|wp-login|wp-content|wp-includes|wp-cron|xmlrpc)#', $path ) ) {
			return;
		}

		if ( preg_match( '#^/(' . implode( '|', array_keys( self::LOCALES ) ) . ')(/|$)#', $path, $m ) ) {
			self::$locale = $m[1];
			if ( 'en' === $m[1] ) {
				// /en/ is not a canonical prefix — send to root.
				self::redirect( self::strip_prefix( $uri, $m[1] ) );
			}
			$_SERVER['REQUEST_URI'] = self::strip_prefix( $uri, $m[1] );
			self::remember();
			add_action( 'init', [ __CLASS__, 'hooks' ] );
			return;
		}

		// Explicit switch to the default locale (switcher link) wins over
		// the stored preference — otherwise the cookie would bounce the
		// visitor straight back to their previous language.
		if ( self::DEFAULT_LOCALE === ( $_GET['glc_lang'] ?? '' ) ) {
			self::$locale = self::DEFAULT_LOCALE;
			self::remember();
			add_action( 'init', [ __CLASS__, 'hooks' ] );
			return;
		}

		// No prefix: default locale, unless the visitor prefers another.
		$preferred = $_COOKIE[ self::COOKIE ] ?? self::negotiate();
		if ( $preferred && self::DEFAULT_LOCALE !== $preferred && isset( self::LOCALES[ $preferred ] )
			&& 'GET' === ( $_SERVER['REQUEST_METHOD'] ?? 'GET' )
			// Sitemaps and feeds must stay stable for crawlers.
			&& ! preg_match( '#^/(wp-sitemap|feed)#', $path ) ) {
			self::redirect( '/' . $preferred . $uri );
		}
		self::$locale = self::DEFAULT_LOCALE;
		self::remember();
		add_action( 'init', [ __CLASS__, 'hooks' ] );
	}

	private static function strip_prefix( string $uri, string $locale ): string {
		$stripped = preg_replace( '#^/' . $locale . '(/|$)#', '/', $uri );
		return $stripped ?: '/';
	}

	private static function redirect( string $to ): void {
		header( 'X-Redirect-By: geolander-i18n' );
		header( 'Vary: Accept-Language' );
		header( 'Location: ' . esc_url_raw( $to ), true, 302 );
		exit;
	}

	/** Best supported match from Accept-Language; null when header absent. */
	private static function negotiate(): ?string {
		$header = $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '';
		if ( ! $header ) {
			return null;
		}
		$candidates = [];
		foreach ( explode( ',', $header ) as $part ) {
			$bits = explode( ';q=', trim( $part ) );
			$tag  = strtolower( substr( trim( $bits[0] ), 0, 2 ) );
			$q    = isset( $bits[1] ) ? (float) $bits[1] : 1.0;
			if ( isset( self::LOCALES[ $tag ] ) ) {
				$candidates[ $tag ] = max( $candidates[ $tag ] ?? 0, $q );
			}
		}
		if ( ! $candidates ) {
			return null;
		}
		arsort( $candidates );
		return array_key_first( $candidates );
	}

	private static function remember(): void {
		if ( headers_sent() ) {
			return;
		}
		setcookie( self::COOKIE, self::$locale, [
			'expires'  => time() + 180 * DAY_IN_SECONDS,
			'path'     => '/',
			'samesite' => 'Lax',
		] );
	}

	public static function hooks() {
		add_filter( 'home_url', [ __CLASS__, 'localize_url' ], 10, 2 );
		add_filter( 'language_attributes', [ __CLASS__, 'language_attributes' ] );
		add_action( 'wp_head', [ __CLASS__, 'hreflang' ], 1 );
		add_filter( 'body_class', fn( $classes ) => array_merge( $classes, [ 'glc-locale-' . self::$locale, 'rtl' === self::dir() ? 'glc-rtl' : 'glc-ltr' ] ) );
	}

	public static function locale(): string {
		return self::$locale;
	}

	public static function dir(): string {
		return self::LOCALES[ self::$locale ]['dir'];
	}

	public static function localize_url( string $url, string $path ): string {
		if ( self::DEFAULT_LOCALE === self::$locale ) {
			return $url;
		}
		// Only front-end content URLs.
		if ( preg_match( '#/(wp-admin|wp-json|wp-login|wp-content|wp-includes|wp-sitemap|feed)#', $url ) ) {
			return $url;
		}
		$home = untrailingslashit( get_option( 'home' ) );
		if ( ! str_starts_with( $url, $home ) ) {
			return $url;
		}
		$rest = substr( $url, strlen( $home ) );
		if ( str_starts_with( $rest, '/' . self::$locale . '/' ) ) {
			return $url; // already prefixed
		}
		return $home . '/' . self::$locale . ( $rest ?: '/' );
	}

	public static function language_attributes( string $output ): string {
		return sprintf( 'lang="%s" dir="%s"', esc_attr( self::$locale ), esc_attr( self::dir() ) );
	}

	/** Current request path without the locale prefix (already stripped). */
	private static function current_path(): string {
		return wp_parse_url( $_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH ) ?? '/';
	}

	public static function hreflang() {
		$home = untrailingslashit( get_option( 'home' ) );
		$path = self::current_path();
		foreach ( self::LOCALES as $code => $meta ) {
			$href = self::DEFAULT_LOCALE === $code ? $home . $path : $home . '/' . $code . $path;
			printf( '<link rel="alternate" hreflang="%s" href="%s" />' . "\n", esc_attr( $meta['hreflang'] ), esc_url( $href ) );
		}
		printf( '<link rel="alternate" hreflang="x-default" href="%s" />' . "\n", esc_url( $home . $path ) );
	}

	/** Switcher data: [code => [name, url-for-current-page, active]] */
	public static function switcher(): array {
		$home  = untrailingslashit( get_option( 'home' ) );
		$path  = self::current_path();
		$query = wp_parse_url( $_SERVER['REQUEST_URI'] ?? '/', PHP_URL_QUERY );
		$items = [];
		foreach ( self::LOCALES as $code => $meta ) {
			$url = self::DEFAULT_LOCALE === $code ? $home . $path : $home . '/' . $code . $path;
			$args = $query ? $query : '';
			if ( self::DEFAULT_LOCALE === $code ) {
				$args .= ( $args ? '&' : '' ) . 'glc_lang=' . self::DEFAULT_LOCALE;
			}
			if ( $args ) {
				$url .= '?' . $args;
			}
			$items[ $code ] = [
				'name'   => $meta['name'],
				'url'    => $url,
				'active' => $code === self::$locale,
			];
		}
		return $items;
	}
}
