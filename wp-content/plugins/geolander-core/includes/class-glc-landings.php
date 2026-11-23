<?php
/**
 * Keyword landing pages: one WordPress page, one URL you choose, one layout.
 *
 * WHAT THIS IS FOR. A landing page targets one search phrase ("car rental
 * Tbilisi airport", "4x4 rental for Tusheti") and wants the phrase in its URL
 * at the root of the site, not under /about/something/. Until now each such URL
 * was two lines of PHP and a deploy: this file carried
 * `^car-rental-kazbegi/?$` and `^fleet/4x4-suv/?$` as literals, so the person
 * who writes the pages could not make one without the person who writes the
 * code. Now the URL is a field on the page, the rules are generated from what
 * is stored, and nothing has to be deployed or flushed by hand.
 *
 * HOW A URL IS CLAIMED. Every page may carry one path in `glc_custom_path`
 * ("car-rental-kutaisi-airport", "fleet/minivan"). For each stored path a
 * rewrite rule is added at the top of the list, before the city pages' broad
 * `car-rental-*` rule, so a curated page always wins over a city that does not
 * exist. The page's permalink becomes that path, and its default WordPress URL
 * redirects to it once (301), so a page never answers on two addresses.
 *
 * WHY NO FLUSH BY HAND. WordPress keeps its rules in one option and only
 * rebuilds it when something asks. The generated set carries a fingerprint: if
 * it differs from the stored one the rules are rebuilt once, on the spot. A
 * save rebuilds them immediately, so a brand-new URL answers on the very next
 * click rather than after `wp rewrite flush`.
 *
 * WHY THE TWO OLD URLS CANNOT BREAK. /car-rental-kazbegi/ and /fleet/4x4-suv/
 * are live and ranking. They are not written into this file any more; they are
 * seeded into the two pages' own field by a one-time step (`landing_paths_seed`
 * in Settings → Geolander, editable like anything else), which runs at the
 * first request after this code is installed. From then on those two pages are
 * ordinary landing pages and the seed list is only history.
 *
 * A LAYOUT IS A PATTERN. The layouts offered are the site's own patterns — the
 * ones anybody can make in wp-admin under Appearance → Patterns — so a new
 * layout needs no deploy either. Choosing one on an empty page copies its
 * blocks into the page as a starting point; after that the page is edited like
 * any other and the layout is never applied over work already there.
 */

defined( 'ABSPATH' ) || exit;

class GLC_Landings {

	/** The page's own URL path, and which layout its blocks came from. */
	public const META_PATH   = 'glc_custom_path';
	public const META_LAYOUT = 'glc_landing_layout';

	/** The list screen, under Pages. */
	public const PAGE = 'glc-landings';

	private const NONCE  = 'glc_landing_nonce';
	private const ACTION = 'glc_landing_save';

	/** The generated [ path => page slug ] set, and the fingerprint that says when it changed. */
	private const RULES_OPTION = 'glc_landing_rules';
	private const HASH_OPTION  = 'glc_landing_rules_hash';

	/**
	 * How far the stored data has been brought. The plugin is already active on
	 * the live site, so an activation hook would never run there; the stored
	 * number does the same job and each step runs once (GLC_Partners' pattern).
	 */
	private const SCHEMA_OPTION  = 'glc_landing_schema_version';
	private const SCHEMA_VERSION = 1;

	/**
	 * Paths nobody may claim whatever the settings say, because WordPress or the
	 * language prefixes own them. Business reservations ("fleet", "news") are a
	 * setting instead: those are someone's decision, and this is not.
	 */
	private const STRUCTURAL = '#^(wp-|xmlrpc|feed$|\.)#';

	public static function init(): void {
		add_action( 'init', [ __CLASS__, 'upgrade' ], 1 );
		/*
		 * The rules go in through the filter WordPress applies as it assembles the
		 * whole list, not through add_rewrite_rule(), and they go at the FRONT of
		 * it. That is the only placement that is actually deterministic.
		 *
		 * add_rewrite_rule( …, 'top' ) prepends each call's rule to the top block,
		 * so the LAST caller ends up FIRST — the opposite of what reading the code
		 * suggests, and it depends on which `init` priority each class happens to
		 * use. The city pages claim `^car-rental-([^/]+)/?$`, which matches every
		 * landing page whose address begins "car-rental-", so losing that race
		 * does not mean "wrong order": it means a 404 on a page that is meant to
		 * rank. Measured on 2026-11-05: a landing rule added this way landed at
		 * position 39, the city rule at 34, and /car-rental-tbilisi-airport/
		 * returned 404 with the rule sitting right there in the list.
		 *
		 * `$mine + $rules` keeps our keys first and never overwrites an existing
		 * one, whatever order anything else registered in.
		 */
		add_filter( 'rewrite_rules_array', [ __CLASS__, 'rules' ], 99 );
		/*
		 * The rebuild waits for `wp_loaded`, not `init`, and the reason is worth
		 * writing down because it cost an afternoon: WP_Rewrite::flush_rules()
		 * POSTPONES ITSELF to `wp_loaded` when called earlier ("prevent this
		 * action from running before everyone has registered their rewrites"),
		 * and returns as if it had worked. Called at init, the fingerprint below
		 * would then be stored for a rebuild that had not happened yet — and
		 * since the fingerprint is what decides whether to try again, a new URL
		 * would 404 until something else flushed. On `wp_loaded` the rebuild is
		 * immediate, so the fingerprint only ever records a rebuild that is done.
		 */
		add_action( 'wp_loaded', [ __CLASS__, 'flush_if_changed' ], 20 );

		add_filter( 'page_link', [ __CLASS__, 'page_link' ], 10, 2 );
		add_filter( 'redirect_canonical', [ __CLASS__, 'redirect_canonical' ] );
		add_action( 'template_redirect', [ __CLASS__, 'redirect_default_path' ], 1 );

		// A path, a slug or a page that goes away changes the generated set.
		foreach ( [ 'save_post_page', 'deleted_post', 'trashed_post', 'untrashed_post' ] as $event ) {
			add_action( $event, [ __CLASS__, 'refresh' ], 99 );
		}

		add_action( 'init', [ __CLASS__, 'register_meta' ] );
		add_action( 'add_meta_boxes_page', [ __CLASS__, 'meta_box' ] );
		add_action( 'save_post_page', [ __CLASS__, 'save' ], 10, 1 );
		add_action( 'admin_menu', [ __CLASS__, 'menu' ] );
		add_action( 'admin_notices', [ __CLASS__, 'notice' ] );
	}

	/* ------------------------------------------------------------ the rules */

	/**
	 * The generated set: [ path => page slug ], one entry per page that carries a
	 * readable path. Kept in an autoloaded option, so a normal request adds no
	 * query at all; it is forgotten whenever a page is saved or removed.
	 */
	public static function paths(): array {
		$stored = get_option( self::RULES_OPTION, null );
		if ( is_array( $stored ) ) {
			return $stored;
		}
		$rows = [];
		$ids  = get_posts( [
			'post_type'      => 'page',
			'post_status'    => [ 'publish', 'private', 'draft', 'pending', 'future' ],
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
			'meta_key'       => self::META_PATH,
			'meta_compare'   => 'EXISTS',
		] );
		foreach ( $ids as $id ) {
			$path = self::clean_path( (string) get_post_meta( (int) $id, self::META_PATH, true ) );
			$slug = (string) get_post_field( 'post_name', (int) $id );
			// First come, first served, so a duplicate can never silently steal a
			// live URL; the edit screen refuses the second one anyway.
			if ( '' !== $path && '' !== $slug && ! isset( $rows[ $path ] ) ) {
				$rows[ $path ] = $slug;
			}
		}
		ksort( $rows );
		update_option( self::RULES_OPTION, $rows, true );
		return $rows;
	}

	/** /{path}/ → that page, resolved by its WordPress slug, ahead of everything else. */
	public static function rules( $rules ) {
		$mine = [];
		foreach ( self::paths() as $path => $slug ) {
			$mine[ '^' . $path . '/?$' ] = 'index.php?pagename=' . $slug;
		}
		return $mine + (array) $rules;
	}

	/**
	 * Rebuild WordPress's rule list when, and only when, the generated set has
	 * changed. A soft flush: it writes the option WordPress routes from, and
	 * never touches .htaccess, which is the image's to write, not ours.
	 */
	public static function flush_if_changed(): void {
		$fingerprint = md5( (string) wp_json_encode( self::paths() ) );
		if ( (string) get_option( self::HASH_OPTION, '' ) === $fingerprint ) {
			return;
		}
		flush_rewrite_rules( false );
		update_option( self::HASH_OPTION, $fingerprint, true );
	}

	/**
	 * Forget the set and rebuild it now, in this request, so a path saved a
	 * second ago answers on the next click instead of after a manual flush.
	 */
	public static function refresh(): void {
		delete_option( self::RULES_OPTION );
		delete_option( self::HASH_OPTION );
		// Only once `wp_loaded` has passed, for the reason in init() above: before
		// it, the rebuild would be postponed and the fingerprint would lie. Before
		// then there is nothing to do anyway, because the rebuild at `wp_loaded`
		// has not run yet and will read the fresh set.
		if ( did_action( 'wp_loaded' ) ) {
			self::flush_if_changed();
		}
	}

	/* ------------------------------------------------------- what is a path */

	/**
	 * A typed path as it is stored, or '' when it cannot be one. Lower case,
	 * words and dashes, slashes between segments, no slash at either end — so
	 * "/Fleet/Minivan Rental/" becomes "fleet/minivan-rental".
	 */
	public static function clean_path( string $typed ): string {
		$path = strtolower( trim( $typed ) );
		$path = (string) preg_replace( '#\s+#', '-', $path );
		$path = (string) preg_replace( '#[^a-z0-9/\-]#', '', $path );
		$path = trim( (string) preg_replace( '#/+#', '/', $path ), '/' );
		if ( '' === $path ) {
			return '';
		}
		$parts = explode( '/', $path );
		$depth = GLC_Settings::whole( 'landing_path_depth' );
		if ( null !== $depth && count( $parts ) > $depth ) {
			return '';
		}
		foreach ( $parts as $part ) {
			if ( ! preg_match( '/^[a-z0-9][a-z0-9\-]*$/', $part ) ) {
				return '';
			}
		}
		// WordPress's own paths, and the language prefixes, are not ours to take.
		if ( preg_match( self::STRUCTURAL, $parts[0] ) ) {
			return '';
		}
		if ( class_exists( 'GLC_I18n' ) && isset( GLC_I18n::LOCALES[ $parts[0] ] ) ) {
			return '';
		}
		// An address another part of the site already answers on, as a whole.
		if ( in_array( $path, GLC_Settings::words( 'landing_reserved_paths' ), true ) ) {
			return '';
		}
		return $path;
	}

	/** The page that already claims this path, if it is not this one. */
	private static function claimed_by( string $path, int $except ): int {
		foreach ( self::paths() as $stored => $slug ) {
			if ( $stored !== $path ) {
				continue;
			}
			$page = get_page_by_path( $slug, OBJECT, 'page' );
			if ( $page && (int) $page->ID !== $except ) {
				return (int) $page->ID;
			}
		}
		return 0;
	}

	/* ------------------------------------------------- the URL a page answers on */

	public static function page_link( string $link, int $post_id ): string {
		$path = self::clean_path( (string) get_post_meta( $post_id, self::META_PATH, true ) );
		return '' !== $path ? home_url( '/' . $path . '/' ) : $link;
	}

	/**
	 * Our permalink already matches the request, but WordPress's canonical
	 * redirect does not understand the generated rule and would bounce the clean
	 * URL to /?page_id=ID.
	 */
	public static function redirect_canonical( $redirect ) {
		return is_singular( 'page' ) && get_post_meta( get_queried_object_id(), self::META_PATH, true )
			? false
			: $redirect;
	}

	/** The page's default WordPress URL answers once, with a 301 to the chosen path. */
	public static function redirect_default_path(): void {
		if ( ! is_singular( 'page' ) ) {
			return;
		}
		$path = self::clean_path( (string) get_post_meta( get_queried_object_id(), self::META_PATH, true ) );
		if ( '' === $path ) {
			return;
		}
		$current = trim( (string) wp_parse_url( $_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH ), '/' );
		if ( $current === $path ) {
			return;
		}
		wp_safe_redirect( get_permalink(), 301, 'Geolander' );
		exit;
	}

	/* ------------------------------------------------------------- layouts */

	/**
	 * The layouts on offer: [ value => name ].
	 *
	 * The site's own patterns — the ones under Appearance → Patterns, which is
	 * where _migration/setup-landing-layouts.php puts the three starting ones —
	 * are always offered, because those are the ones a person can add to, copy
	 * and rewrite in wp-admin with nobody's help and no deploy. That is the whole
	 * point of a reusable template here.
	 *
	 * A pattern that came from code is offered only when its category matches the
	 * `landing_layout_category` setting. Offering every registered pattern
	 * instead sounds more generous and is worse: measured on this install it put
	 * eleven of WordPress's own patterns in the list — six ways of listing blog
	 * posts and five navigation overlays — none of which is a landing page. A
	 * list of eleven wrong answers and three right ones is harder to use than a
	 * list of three. Typing a category into the setting opts a whole family of
	 * coded patterns in, so nothing is locked away; it just is not the default.
	 */
	public static function layouts(): array {
		$out      = [];
		$category = trim( (string) GLC_Settings::get( 'landing_layout_category', '' ) );

		foreach ( get_posts( [
			'post_type'      => 'wp_block',
			'post_status'    => [ 'publish' ],
			'posts_per_page' => -1,
			'orderby'        => 'title',
			'order'          => 'ASC',
			'no_found_rows'  => true,
		] ) as $pattern ) {
			$out[ 'block:' . $pattern->ID ] = (string) $pattern->post_title;
		}

		if ( '' !== $category && class_exists( 'WP_Block_Patterns_Registry' ) ) {
			foreach ( WP_Block_Patterns_Registry::get_instance()->get_all_registered() as $pattern ) {
				if ( ! in_array( $category, (array) ( $pattern['categories'] ?? [] ), true ) ) {
					continue;
				}
				$out[ 'pattern:' . $pattern['name'] ] = (string) ( $pattern['title'] ?? $pattern['name'] );
			}
		}
		return $out;
	}

	/** The blocks a layout is made of; '' when it no longer exists. */
	public static function layout_markup( string $value ): string {
		if ( str_starts_with( $value, 'block:' ) ) {
			$pattern = get_post( (int) substr( $value, 6 ) );
			return $pattern && 'wp_block' === $pattern->post_type ? (string) $pattern->post_content : '';
		}
		if ( str_starts_with( $value, 'pattern:' ) && class_exists( 'WP_Block_Patterns_Registry' ) ) {
			$pattern = WP_Block_Patterns_Registry::get_instance()->get_registered( substr( $value, 8 ) );
			return is_array( $pattern ) ? (string) ( $pattern['content'] ?? '' ) : '';
		}
		return '';
	}

	/* ------------------------------------------------------- the first install */

	public static function upgrade(): void {
		$done = (int) get_option( self::SCHEMA_OPTION, 0 );
		if ( $done >= self::SCHEMA_VERSION ) {
			return;
		}
		for ( $step = $done + 1; $step <= self::SCHEMA_VERSION; $step++ ) {
			self::{ 'upgrade_to_' . $step }();
		}
		update_option( self::SCHEMA_OPTION, self::SCHEMA_VERSION, true );
	}

	/**
	 * Step 1: give the pages that had a rule written in code a path of their own,
	 * so the two live URLs keep answering without anybody running anything. A
	 * page that already carries a path is left exactly as it is.
	 */
	private static function upgrade_to_1(): void {
		foreach ( self::seeded() as $path => $slug ) {
			$page = get_page_by_path( $slug, OBJECT, 'page' );
			if ( ! $page ) {
				continue;
			}
			if ( '' === self::clean_path( (string) get_post_meta( (int) $page->ID, self::META_PATH, true ) ) ) {
				update_post_meta( (int) $page->ID, self::META_PATH, $path );
			}
		}
		delete_option( self::RULES_OPTION );
		delete_option( self::HASH_OPTION );
	}

	/** The seed list from the settings: [ path => page slug ], one "path => slug" per line. */
	public static function seeded(): array {
		$rows = [];
		foreach ( preg_split( '/\R/', (string) GLC_Settings::get( 'landing_paths_seed', '' ) ) ?: [] as $line ) {
			$pair = array_map( 'trim', explode( '=>', (string) $line, 2 ) );
			if ( 2 !== count( $pair ) ) {
				continue;
			}
			$path = self::clean_path( $pair[0] );
			$slug = sanitize_title( $pair[1] );
			if ( '' !== $path && '' !== $slug ) {
				$rows[ $path ] = $slug;
			}
		}
		return $rows;
	}

	/* ----------------------------------------------------------- Admin: a page */

	public static function register_meta(): void {
		register_post_meta( 'page', self::META_PATH, [
			'type'          => 'string',
			'single'        => true,
			'show_in_rest'  => false,
			'auth_callback' => '__return_false', // written here, where it is checked for a clash
		] );
		register_post_meta( 'page', self::META_LAYOUT, [
			'type'          => 'string',
			'single'        => true,
			'show_in_rest'  => false,
			'auth_callback' => '__return_false',
		] );
	}

	public static function meta_box(): void {
		add_meta_box( 'glc-landing', glc_admin_t( 'landing_box' ), [ __CLASS__, 'render_box' ], 'page', 'side', 'high' );
	}

	/**
	 * One question above the fold — what is this page's address? — and everything
	 * else a fold deeper, because most saves change the words on the page and
	 * nothing here.
	 */
	public static function render_box( WP_Post $post ): void {
		wp_nonce_field( self::ACTION, self::NONCE );
		$path = (string) get_post_meta( $post->ID, self::META_PATH, true );

		printf(
			'<p><label for="glc-landing-path"><strong>%s</strong></label><input type="text" id="glc-landing-path" name="%s" value="%s" class="widefat" placeholder="%s" /></p>',
			esc_html( glc_admin_t( 'landing_path' ) ),
			esc_attr( self::META_PATH ),
			esc_attr( $path ),
			esc_attr( glc_admin_t( 'landing_path_placeholder' ) )
		);
		printf( '<p class="description">%s</p>', esc_html( sprintf( glc_admin_t( 'landing_path_help' ), untrailingslashit( home_url( '/' ) ) ) ) );
		if ( '' !== self::clean_path( $path ) ) {
			printf(
				'<p><a href="%1$s" target="_blank" rel="noopener"><code>/%2$s/</code></a></p>',
				esc_url( home_url( '/' . self::clean_path( $path ) . '/' ) ),
				esc_html( self::clean_path( $path ) )
			);
		}

		self::fold( glc_admin_t( 'landing_layout_fold' ), function () use ( $post ) {
			$layouts = self::layouts();
			$chosen  = (string) get_post_meta( $post->ID, self::META_LAYOUT, true );
			printf( '<p><select name="%s" class="widefat"><option value="">%s</option>', esc_attr( self::META_LAYOUT ), esc_html( glc_admin_t( 'landing_layout_none' ) ) );
			foreach ( $layouts as $value => $name ) {
				printf( '<option value="%s"%s>%s</option>', esc_attr( $value ), selected( $chosen, $value, false ), esc_html( $name ) );
			}
			echo '</select></p>';
			printf( '<p class="description">%s</p>', esc_html( glc_admin_t( $layouts ? 'landing_layout_help' : 'landing_layout_empty' ) ) );
		} );

		self::fold( glc_admin_t( 'landing_seo_fold' ), function () use ( $post ) {
			self::text_field( $post->ID, 'glc_seo_title_en', glc_admin_t( 'landing_seo_title' ) );
			self::text_field( $post->ID, 'glc_seo_description_en', glc_admin_t( 'landing_seo_description' ), true );
			printf( '<p class="description">%s</p>', esc_html( glc_admin_t( 'landing_seo_help' ) ) );
		} );

		self::fold( glc_admin_t( 'landing_schema_fold' ), function () use ( $post ) {
			self::text_field( $post->ID, 'glc_service_type', glc_admin_t( 'landing_service_type' ) );
			self::text_field( $post->ID, 'glc_guide_route', glc_admin_t( 'landing_guide_route' ) );
			printf( '<p class="description">%s</p>', esc_html( glc_admin_t( 'landing_schema_help' ) ) );
		} );

		$locales = class_exists( 'GLC_Content' ) ? GLC_Content::locales() : [];
		if ( $locales ) {
			self::fold( glc_admin_t( 'landing_locales_fold' ), function () use ( $post, $locales ) {
				foreach ( $locales as $locale ) {
					printf( '<p style="margin-bottom:.2rem;"><strong>%s</strong> <code>%s</code></p>', esc_html( GLC_I18n::LOCALES[ $locale ]['name'] ?? $locale ), esc_html( $locale ) );
					self::text_field( $post->ID, 'glc_seo_title_' . $locale, glc_admin_t( 'landing_seo_title' ) );
					self::text_field( $post->ID, 'glc_seo_description_' . $locale, glc_admin_t( 'landing_seo_description' ), true );
				}
				printf( '<p class="description">%s</p>', esc_html( glc_admin_t( 'landing_locales_help' ) ) );
			} );
		}
	}

	/** One level deeper: shut until someone wants it, open when it holds something. */
	private static function fold( string $summary, callable $body ): void {
		printf( '<details style="margin:.6rem 0;"><summary style="cursor:pointer;font-weight:600;">%s</summary><div style="padding-top:.4rem;">', esc_html( $summary ) );
		$body();
		echo '</div></details>';
	}

	private static function text_field( int $post_id, string $key, string $label, bool $long = false ): void {
		$value = (string) get_post_meta( $post_id, $key, true );
		printf( '<p style="margin:.2rem 0;"><label for="%1$s">%2$s</label></p>', esc_attr( $key ), esc_html( $label ) );
		if ( $long ) {
			printf( '<textarea id="%1$s" name="%1$s" rows="3" class="widefat">%2$s</textarea>', esc_attr( $key ), esc_textarea( $value ) );
		} else {
			printf( '<input type="text" id="%1$s" name="%1$s" value="%2$s" class="widefat" />', esc_attr( $key ), esc_attr( $value ) );
		}
	}

	/**
	 * Saving. A path that cannot be read, or that another page already answers
	 * on, is not written: the one before it stays and the screen says why — the
	 * same rule the settings follow, because a silently dropped URL is how a
	 * ranking page disappears.
	 */
	public static function save( int $post_id ): void {
		static $busy = false;
		if ( $busy ) {
			return;
		}
		if ( ! isset( $_POST[ self::NONCE ] ) || ! wp_verify_nonce( sanitize_key( $_POST[ self::NONCE ] ), self::ACTION ) ) {
			return;
		}
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		$busy  = true;
		$lines = [];

		if ( isset( $_POST[ self::META_PATH ] ) ) {
			$typed = sanitize_text_field( wp_unslash( (string) $_POST[ self::META_PATH ] ) );
			$path  = self::clean_path( $typed );
			$other = '' !== $path ? self::claimed_by( $path, $post_id ) : 0;
			if ( '' === trim( $typed ) ) {
				delete_post_meta( $post_id, self::META_PATH );
			} elseif ( '' === $path ) {
				$lines[] = sprintf( glc_admin_t( 'landing_path_refused' ), $typed );
			} elseif ( $other ) {
				$lines[] = sprintf( glc_admin_t( 'landing_path_taken' ), $path, get_the_title( $other ) );
			} else {
				update_post_meta( $post_id, self::META_PATH, $path );
			}
		}

		if ( isset( $_POST[ self::META_LAYOUT ] ) ) {
			$layout = sanitize_text_field( wp_unslash( (string) $_POST[ self::META_LAYOUT ] ) );
			$layout = array_key_exists( $layout, self::layouts() ) ? $layout : '';
			if ( '' === $layout ) {
				delete_post_meta( $post_id, self::META_LAYOUT );
			} else {
				update_post_meta( $post_id, self::META_LAYOUT, $layout );
				// Seeded, never imposed: a layout fills a page that has nothing on
				// it and is a starting point from then on. Work already written is
				// never replaced, so choosing a layout twice cannot destroy a page.
				if ( '' === trim( (string) get_post_field( 'post_content', $post_id ) ) ) {
					$markup = self::layout_markup( $layout );
					if ( '' !== $markup ) {
						wp_update_post( [ 'ID' => $post_id, 'post_content' => $markup ] );
						$lines[] = glc_admin_t( 'landing_layout_applied' );
					}
				}
			}
		}

		foreach ( self::seo_keys() as $key ) {
			if ( ! isset( $_POST[ $key ] ) ) {
				continue;
			}
			$value = sanitize_text_field( wp_unslash( (string) $_POST[ $key ] ) );
			if ( '' === trim( $value ) ) {
				delete_post_meta( $post_id, $key );
			} else {
				update_post_meta( $post_id, $key, $value );
			}
		}

		self::flash( $lines );
		self::refresh();
		$busy = false;
	}

	/** Every field this box writes besides the path and the layout. */
	private static function seo_keys(): array {
		$keys = [ 'glc_seo_title_en', 'glc_seo_description_en', 'glc_service_type', 'glc_guide_route' ];
		foreach ( class_exists( 'GLC_Content' ) ? GLC_Content::locales() : [] as $locale ) {
			$keys[] = 'glc_seo_title_' . $locale;
			$keys[] = 'glc_seo_description_' . $locale;
		}
		return $keys;
	}

	/* -------------------------------------------------- what the screen says */

	private static function flash( array $lines ): void {
		$slot = 'glc_landing_said_' . get_current_user_id();
		if ( $lines ) {
			set_transient( $slot, array_values( array_map( 'strval', $lines ) ), 60 );
		} else {
			delete_transient( $slot );
		}
	}

	public static function notice(): void {
		$slot  = 'glc_landing_said_' . get_current_user_id();
		$lines = get_transient( $slot );
		if ( ! is_array( $lines ) || ! $lines ) {
			return;
		}
		delete_transient( $slot );
		echo '<div class="notice notice-warning"><ul style="margin:.5rem 0;">';
		foreach ( $lines as $line ) {
			echo '<li>' . esc_html( (string) $line ) . '</li>';
		}
		echo '</ul></div>';
	}

	/* ----------------------------------------------- Admin: all of them at once */

	public static function menu(): void {
		add_submenu_page(
			'edit.php?post_type=page',
			glc_admin_t( 'landing_list_title' ),
			glc_admin_t( 'landing_list_menu' ),
			'edit_pages',
			self::PAGE,
			[ __CLASS__, 'render_list' ]
		);
	}

	/**
	 * One row per landing page: its address, its layout, and whether its search
	 * fields are filled. The answer to "what exists and what is unfinished" in
	 * one screen; everything else is one click away on the page itself.
	 */
	public static function render_list(): void {
		if ( ! current_user_can( 'edit_pages' ) ) {
			wp_die( esc_html( glc_admin_t( 'landing_refused' ) ) );
		}
		$layouts = self::layouts();
		echo '<div class="wrap"><h1>' . esc_html( glc_admin_t( 'landing_list_title' ) ) . '</h1>';
		echo '<p class="description">' . esc_html( glc_admin_t( 'landing_list_intro' ) ) . '</p>';
		echo '<table class="widefat striped"><thead><tr>';
		foreach ( [ 'landing_col_page', 'landing_col_path', 'landing_col_layout', 'landing_col_seo', 'landing_col_schema' ] as $column ) {
			echo '<th>' . esc_html( glc_admin_t( $column ) ) . '</th>';
		}
		echo '</tr></thead><tbody>';

		$rows = 0;
		foreach ( self::paths() as $path => $slug ) {
			$page = get_page_by_path( $slug, OBJECT, 'page' );
			if ( ! $page ) {
				continue;
			}
			++$rows;
			$layout = (string) get_post_meta( $page->ID, self::META_LAYOUT, true );
			$title  = trim( (string) get_post_meta( $page->ID, 'glc_seo_title_en', true ) );
			$desc   = trim( (string) get_post_meta( $page->ID, 'glc_seo_description_en', true ) );
			$node   = trim( (string) get_post_meta( $page->ID, 'glc_guide_route', true ) )
				? glc_admin_t( 'landing_node_article' )
				: ( trim( (string) get_post_meta( $page->ID, 'glc_service_type', true ) ) ? glc_admin_t( 'landing_node_service' ) : glc_admin_t( 'landing_node_page' ) );
			printf(
				'<tr><td><a href="%1$s"><strong>%2$s</strong></a><br /><span class="description">%3$s</span></td><td><a href="%4$s" target="_blank" rel="noopener"><code>/%5$s/</code></a></td><td>%6$s</td><td>%7$s</td><td>%8$s</td></tr>',
				esc_url( (string) get_edit_post_link( $page->ID ) ),
				esc_html( get_the_title( $page ) ),
				esc_html( (string) $page->post_status ),
				esc_url( home_url( '/' . $path . '/' ) ),
				esc_html( $path ),
				esc_html( '' !== $layout ? ( $layouts[ $layout ] ?? glc_admin_t( 'landing_layout_gone' ) ) : glc_admin_t( 'landing_layout_none' ) ),
				esc_html( sprintf(
					glc_admin_t( 'landing_seo_state' ),
					glc_admin_t( '' !== $title ? 'landing_yes' : 'landing_no' ),
					glc_admin_t( '' !== $desc ? 'landing_yes' : 'landing_no' )
				) ),
				esc_html( $node )
			);
		}
		if ( ! $rows ) {
			printf( '<tr><td colspan="5">%s</td></tr>', esc_html( glc_admin_t( 'landing_list_empty' ) ) );
		}
		echo '</tbody></table></div>';
	}
}
