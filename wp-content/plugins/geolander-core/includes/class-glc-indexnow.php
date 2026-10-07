<?php
/**
 * IndexNow: tell Bing (and every engine sharing the protocol — Yandex, Naver,
 * Seznam, Yep) about a URL the moment it is published, updated, or retired.
 *
 * Why this matters here: ChatGPT's browsing and search lean on Bing's index,
 * and a page Bing has never crawled is invisible to that path. Google does not
 * use IndexNow; its discovery still runs through wp-sitemap.xml and links.
 *
 * Protocol: https://www.indexnow.org/documentation — the site proves key
 * ownership by serving the key at https://host/{key}.txt, then POSTs
 * {host, key, keyLocation, urlList} to api.indexnow.org.
 *
 * The key is generated once and stored in the options table; the owner may
 * override it in Settings → Geolander → Search engines (paste the same key into
 * Bing Webmaster Tools if verifying there). Every ping is logged so the settings
 * screen can show the last responses without touching server logs.
 */

defined( 'ABSPATH' ) || exit;

class GLC_IndexNow {

	private const ENDPOINT     = 'https://api.indexnow.org/indexnow';
	private const OPTION_KEY   = 'glc_indexnow_key';
	private const OPTION_LOG   = 'glc_indexnow_log';
	private const LOG_LENGTH   = 20;
	/** IndexNow accepts at most 10,000 URLs per POST; keep batches well under that. */
	private const BATCH        = 500;
	/** Public post types whose URLs are worth announcing. */
	/**
	 * Every public post type, derived rather than listed.
	 *
	 * This was a hardcoded array written before the news section existed, so when
	 * the first article was published on 2026-10-07 nothing was announced to Bing,
	 * Yandex or Seznam — and Bing's index is what ChatGPT search reads, which is
	 * precisely the audience a news article is written for. A list like that fails
	 * silently and only on the thing you just added, which is the worst shape a
	 * bug can have. Asking WordPress which types are public means the next content
	 * type is covered the day it is registered.
	 *
	 * Attachments are excluded: media URLs are not pages worth announcing.
	 */
	private static function public_types(): array {
		$types = get_post_types( [ 'public' => true ], 'names' );
		unset( $types['attachment'] );
		return array_values( $types );
	}

	public static function init(): void {
		add_action( 'init', [ __CLASS__, 'serve_key' ], 1 );
		add_action( 'transition_post_status', [ __CLASS__, 'on_transition' ], 10, 3 );
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			WP_CLI::add_command( 'geolander indexnow', [ __CLASS__, 'cli' ] );
		}
	}

	/** The active key: settings override when valid, else a generated one persisted once. */
	public static function key(): string {
		$key = class_exists( 'GLC_Settings' ) ? trim( (string) GLC_Settings::get( 'indexnow_key', '' ) ) : '';
		if ( self::valid_key( $key ) ) {
			return $key;
		}
		$key = (string) get_option( self::OPTION_KEY, '' );
		if ( ! self::valid_key( $key ) ) {
			$key = bin2hex( random_bytes( 16 ) ); // 32 hex chars — inside the 8–128 [a-zA-Z0-9-] spec.
			update_option( self::OPTION_KEY, $key, false );
		}
		return $key;
	}

	private static function valid_key( string $key ): bool {
		return (bool) preg_match( '/^[A-Za-z0-9-]{8,128}$/', $key );
	}

	public static function key_url(): string {
		return home_url( '/' . self::key() . '.txt' );
	}

	/**
	 * Serve /{key}.txt as plain text. Handled at `init` by path so no rewrite
	 * flush is needed when the key changes. GLC_I18n has already stripped any
	 * locale prefix, so /ru/{key}.txt resolves too — harmless.
	 */
	public static function serve_key(): void {
		if ( is_admin() ) {
			return;
		}
		$path = (string) ( wp_parse_url( $_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH ) ?? '/' );
		if ( $path !== '/' . self::key() . '.txt' ) {
			return;
		}
		status_header( 200 );
		header( 'Content-Type: text/plain; charset=utf-8' );
		header( 'Cache-Control: public, max-age=86400' );
		echo self::key(); // phpcs:ignore WordPress.Security.EscapeOutput
		exit;
	}

	/** All locale variants of one canonical URL — every one is a separate page to Bing. */
	public static function variants( string $url ): array {
		if ( ! class_exists( 'GLC_I18n' ) ) {
			return [ $url ];
		}
		$home = untrailingslashit( get_option( 'home' ) );
		if ( ! str_starts_with( $url, $home ) ) {
			return [ $url ];
		}
		$path = substr( $url, strlen( $home ) ) ?: '/';
		$out  = [];
		foreach ( array_keys( GLC_I18n::LOCALES ) as $code ) {
			$out[] = GLC_I18n::DEFAULT_LOCALE === $code ? $home . $path : $home . '/' . $code . $path;
		}
		return $out;
	}

	/** Publish, update, or unpublish of a public post → announce all its locale URLs. */
	public static function on_transition( string $new, string $old, WP_Post $post ): void {
		if ( ! in_array( $post->post_type, self::public_types(), true ) || ! empty( $post->post_password ) ) {
			return;
		}
		if ( 'publish' !== $new && 'publish' !== $old ) {
			return; // draft ↔ draft: nothing public changed
		}
		if ( wp_is_post_revision( $post ) || wp_is_post_autosave( $post ) ) {
			return;
		}
		// get_permalink() is locale-filtered through home_url(); on the admin
		// side GLC_I18n::boot() returns early so this is the unprefixed URL.
		$url = get_permalink( $post );
		if ( ! $url ) {
			return;
		}
		self::ping( self::variants( $url ), sprintf( '%s:%s %s→%s', $post->post_type, $post->post_name, $old, $new ) );
	}

	/**
	 * POST a batch to IndexNow. Returns [ 'status' => int|null, 'count' => int ].
	 * 200/202 = accepted · 400 bad request · 403 key not found · 422 URL/host
	 * mismatch · 429 too many requests.
	 */
	public static function ping( array $urls, string $reason = 'manual' ): array {
		// Local imports and staging edits must never announce test URLs publicly.
		if ( 'production' !== wp_get_environment_type() ) {
			return [ 'status' => null, 'count' => 0 ];
		}
		$host = wp_parse_url( home_url( '/' ), PHP_URL_HOST );
		$urls = array_values( array_unique( array_filter( array_map( 'strval', $urls ) ) ) );
		$urls = array_values( array_filter( $urls, static function ( $url ) use ( $host ) {
			return wp_parse_url( $url, PHP_URL_HOST ) === $host
				&& in_array( wp_parse_url( $url, PHP_URL_SCHEME ), [ 'http', 'https' ], true );
		} ) );
		if ( ! $urls ) {
			return [ 'status' => null, 'count' => 0 ];
		}
		$status = null;
		foreach ( array_chunk( $urls, self::BATCH ) as $chunk ) {
			$response = wp_remote_post( self::ENDPOINT, [
				'timeout' => 8,
				'headers' => [ 'Content-Type' => 'application/json; charset=utf-8' ],
				'body'    => wp_json_encode( [
					'host'           => $host,
					'key'            => self::key(),
					'keyLocation'    => self::key_url(),
					'urlList'        => $chunk,
				] ),
			] );
			$status = is_wp_error( $response ) ? null : (int) wp_remote_retrieve_response_code( $response );
			self::log( [
				'time'   => gmdate( 'c' ),
				'reason' => $reason,
				'count'  => count( $chunk ),
				'status' => $status ?? ( is_wp_error( $response ) ? $response->get_error_message() : 'unknown' ),
				'first'  => $chunk[0],
			] );
		}
		return [ 'status' => $status, 'count' => count( $urls ) ];
	}

	private static function log( array $entry ): void {
		$log = (array) get_option( self::OPTION_LOG, [] );
		array_unshift( $log, $entry );
		update_option( self::OPTION_LOG, array_slice( $log, 0, self::LOG_LENGTH ), false );
	}

	public static function recent(): array {
		return (array) get_option( self::OPTION_LOG, [] );
	}

	/** Every public URL in every locale — what a full re-announcement sends. */
	public static function all_urls(): array {
		$urls = [];
		foreach ( self::variants( home_url( '/' ) ) as $u ) {
			$urls[] = $u;
		}
		foreach ( [ 'car', 'place' ] as $type ) {
			$archive = get_post_type_archive_link( $type );
			if ( $archive ) {
				array_push( $urls, ...self::variants( $archive ) );
			}
		}
		$retired = class_exists( 'GLC_Redirects' ) ? array_map( fn( $p ) => trim( $p, '/' ), array_keys( GLC_Redirects::map() ) ) : [];
		$noindex = class_exists( 'GLC_Redirects' ) ? GLC_Redirects::noindex_slugs() : [];
		foreach ( get_posts( [
			'post_type'      => self::public_types(),
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'no_found_rows'  => true,
		] ) as $post ) {
			if ( in_array( $post->post_name, $retired, true ) || in_array( $post->post_name, $noindex, true ) ) {
				continue;
			}
			array_push( $urls, ...self::variants( get_permalink( $post ) ) );
		}
		return array_values( array_unique( $urls ) );
	}

	/**
	 * wp geolander indexnow --key           print the key and its URL
	 * wp geolander indexnow --all           announce every public URL (all locales)
	 * wp geolander indexnow <url> [<url>…]  announce specific URLs (locale variants added)
	 * wp geolander indexnow --log           show the last responses
	 */
	public static function cli( array $args, array $assoc ): void {
		if ( isset( $assoc['key'] ) ) {
			WP_CLI::log( self::key() );
			WP_CLI::log( self::key_url() );
			return;
		}
		if ( isset( $assoc['log'] ) ) {
			foreach ( self::recent() as $row ) {
				WP_CLI::log( sprintf( '%s  %-28s %4d urls  → %s  (%s)', $row['time'], $row['reason'], $row['count'], $row['status'], $row['first'] ) );
			}
			return;
		}
		$urls = isset( $assoc['all'] ) ? self::all_urls() : array_merge( ...array_map( [ __CLASS__, 'variants' ], $args ) );
		if ( ! $urls ) {
			WP_CLI::error( 'Nothing to announce. Pass URLs or --all.' );
		}
		$result = self::ping( $urls, isset( $assoc['all'] ) ? 'cli:all' : 'cli' );
		WP_CLI::log( sprintf( '%d URLs → HTTP %s', $result['count'], $result['status'] ?? 'no response' ) );
		if ( ! in_array( $result['status'], [ 200, 202 ], true ) ) {
			WP_CLI::error( 'IndexNow did not accept the batch. 403 = key file not reachable at ' . self::key_url() . '; 422 = URL host mismatch.' );
		}
		WP_CLI::success( 'Accepted.' );
	}
}
