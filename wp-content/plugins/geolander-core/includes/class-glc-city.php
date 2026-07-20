<?php
/**
 * City delivery / landing pages (local SEO/AEO/GEO).
 *
 * The booking model is WhatsApp-based, so cities need no inventory or filters —
 * they are content pages that (1) rank for "car rental {city}" queries, (2) tell
 * visitors which cities Geolander delivers to, and (3) give AI answer engines a
 * clean per-city entity to cite.
 *
 * URLs are root-level keyword slugs, e.g. /car-rental-batumi/ (and
 * /ka/car-rental-batumi/ under a locale), per SEO best practice — the full
 * target keyword is in the path. The editable post slug is just the city name
 * ("batumi"); the "car-rental-" prefix lives in the rewrite so it can't be
 * fat-fingered per post.
 *
 * IMPORTANT: adding/removing a city, or first activating this, requires a
 * rewrite flush (`wp rewrite flush`) or the pretty URL 404s.
 */

defined( 'ABSPATH' ) || exit;

class GLC_City {

	private const PREFIX = 'car-rental-';

	public static function init() {
		add_action( 'init', [ __CLASS__, 'register' ] );
		add_action( 'init', [ __CLASS__, 'rewrites' ] );
		add_filter( 'post_type_link', [ __CLASS__, 'permalink' ], 10, 2 );
		// Our permalink already matches the request path, but WordPress's canonical
		// redirect doesn't understand the custom rewrite and can bounce the clean
		// URL to /?post_type=city&p=ID. Skip canonical redirects on city singles.
		add_filter( 'redirect_canonical', fn( $r ) => is_singular( 'city' ) ? false : $r );
		add_action( 'add_meta_boxes', [ __CLASS__, 'meta_box' ] );
		add_action( 'save_post_city', [ __CLASS__, 'save' ], 10, 1 );
	}

	public static function register() {
		register_post_type( 'city', [
			'labels'       => [
				'name'          => __( 'Cities', 'geolander' ),
				'singular_name' => __( 'City', 'geolander' ),
				'add_new_item'  => __( 'Add New City', 'geolander' ),
				'edit_item'     => __( 'Edit City', 'geolander' ),
			],
			'public'       => true,
			'has_archive'  => false,
			// Our own rewrite rule owns the URL; core's would add a /city/ base.
			'rewrite'      => false,
			'menu_icon'    => 'dashicons-location',
			'menu_position'=> 6,
			'supports'     => [ 'title', 'editor', 'thumbnail', 'excerpt', 'page-attributes' ],
			'show_in_rest' => true,
		] );

		$string = [ 'type' => 'string', 'single' => true, 'show_in_rest' => true, 'sanitize_callback' => 'sanitize_text_field', 'auth_callback' => fn() => current_user_can( 'edit_posts' ) ];
		foreach ( [ 'glc_airport_name', 'glc_airport_code', 'glc_delivery_note' ] as $key ) {
			register_post_meta( 'city', $key, $string );
		}
	}

	/** /car-rental-{post_name}/ → the single city, resolved by post slug. */
	public static function rewrites() {
		add_rewrite_rule( '^' . self::PREFIX . '([^/]+)/?$', 'index.php?post_type=city&name=$matches[1]', 'top' );
	}

	public static function permalink( string $link, WP_Post $post ): string {
		if ( 'city' !== $post->post_type ) {
			return $link;
		}
		// home_url() is locale-filtered by GLC_I18n, so this stays inside the
		// visitor's language prefix automatically.
		return home_url( '/' . self::PREFIX . $post->post_name . '/' );
	}

	public static function airport( int $id ): array {
		return [
			'name' => (string) get_post_meta( $id, 'glc_airport_name', true ),
			'code' => (string) get_post_meta( $id, 'glc_airport_code', true ),
		];
	}

	public static function delivery_note( int $id ): string {
		return (string) get_post_meta( $id, 'glc_delivery_note', true );
	}

	/** Cities we deliver to, ordered — for the footer / coverage strip. */
	public static function all(): array {
		static $cities = null;
		if ( null === $cities ) {
			$cities = get_posts( [
				'post_type'      => 'city',
				'posts_per_page' => -1,
				'orderby'        => 'menu_order',
				'order'          => 'ASC',
				'no_found_rows'  => true,
			] );
		}
		return $cities;
	}

	/* ------------------------------------------------------------- Schema */

	/**
	 * Per-city Service node — declares Geolander offers car rental in this
	 * specific city, provided by the main business, serving that city. Given to
	 * GLC_Schema::output() so all JSON-LD stays in one @graph.
	 */
	public static function schema( int $id ): array {
		return [
			'@type'       => 'Service',
			'@id'         => get_permalink( $id ) . '#service',
			'serviceType' => 'Car rental',
			'name'        => get_the_title( $id ),
			'url'         => get_permalink( $id ),
			'provider'    => [ '@id' => home_url( '/#business' ) ],
			'areaServed'  => [ '@type' => 'City', 'name' => get_the_title( $id ) === '' ? '' : self::city_name( $id ) ],
		];
	}

	/** The bare city name (title minus the "Car Rental in " marketing prefix). */
	public static function city_name( int $id ): string {
		$title = class_exists( 'GLC_Content' ) ? GLC_Content::title( $id ) : get_the_title( $id );
		// Strip common EN prefixes; localized titles are stored whole, so this is
		// a best-effort tidy for the schema areaServed only.
		return trim( preg_replace( '/^(car rental in|car rental|rent a car in|rent a car)\s+/i', '', $title ) ) ?: $title;
	}

	/* ----------------------------------------------------------- Admin UI */

	public static function meta_box() {
		add_meta_box( 'glc-city', __( 'City details', 'geolander' ), [ __CLASS__, 'render_box' ], 'city', 'side' );
	}

	public static function render_box( WP_Post $post ) {
		wp_nonce_field( 'glc_city_save', 'glc_city_nonce' );
		$fields = [
			'glc_airport_name'  => __( 'Airport name (e.g. Batumi International)', 'geolander' ),
			'glc_airport_code'  => __( 'Airport IATA code (e.g. BUS)', 'geolander' ),
			'glc_delivery_note' => __( 'Delivery note (e.g. Free delivery to hotels)', 'geolander' ),
		];
		foreach ( $fields as $key => $label ) {
			printf(
				'<p><label for="%1$s"><strong>%2$s</strong></label><input type="text" id="%1$s" name="%1$s" value="%3$s" class="widefat" /></p>',
				esc_attr( $key ),
				esc_html( $label ),
				esc_attr( (string) get_post_meta( $post->ID, $key, true ) )
			);
		}
	}

	public static function save( int $post_id ) {
		if ( ! isset( $_POST['glc_city_nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['glc_city_nonce'] ), 'glc_city_save' ) ) {
			return;
		}
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		foreach ( [ 'glc_airport_name', 'glc_airport_code', 'glc_delivery_note' ] as $key ) {
			if ( isset( $_POST[ $key ] ) ) {
				update_post_meta( $post_id, $key, sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) );
			}
		}
	}
}
