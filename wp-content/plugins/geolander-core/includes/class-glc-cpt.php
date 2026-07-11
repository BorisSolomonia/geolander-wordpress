<?php
/**
 * Custom post types, taxonomies, and post meta.
 *
 * Front-end UI labels are Georgian (site language); admin labels stay
 * English-capable via i18n. Vehicle content itself is English by design.
 */

defined( 'ABSPATH' ) || exit;

class GLC_CPT {

	public static function init() {
		add_action( 'init', [ __CLASS__, 'register_all' ] );
	}

	public static function register_all() {
		self::register_car();
		self::register_place();
		self::register_testimonial();
		self::register_meta();
	}

	private static function register_car() {
		register_post_type( 'car', [
			'labels' => [
				'name'          => __( 'Cars', 'geolander' ),
				'singular_name' => __( 'Car', 'geolander' ),
				'add_new_item'  => __( 'Add New Car', 'geolander' ),
				'edit_item'     => __( 'Edit Car', 'geolander' ),
			],
			'public'       => true,
			'has_archive'  => true,
			'rewrite'      => [ 'slug' => 'fleet', 'with_front' => false ],
			'menu_icon'    => 'dashicons-car',
			'menu_position'=> 5,
			'supports'     => [ 'title', 'editor', 'thumbnail', 'excerpt', 'page-attributes', 'custom-fields' ],
			'show_in_rest' => true,
		] );

		register_taxonomy( 'car_brand', 'car', [
			'labels'       => [ 'name' => __( 'Brands', 'geolander' ), 'singular_name' => __( 'Brand', 'geolander' ) ],
			'public'       => true,
			'hierarchical' => false,
			'rewrite'      => [ 'slug' => 'brand', 'with_front' => false ],
			'show_in_rest' => true,
			'show_admin_column' => true,
		] );

		register_taxonomy( 'car_body_type', 'car', [
			'labels'       => [ 'name' => __( 'Body Types', 'geolander' ), 'singular_name' => __( 'Body Type', 'geolander' ) ],
			'public'       => true,
			'hierarchical' => false,
			'rewrite'      => [ 'slug' => 'body-type', 'with_front' => false ],
			'show_in_rest' => true,
			'show_admin_column' => true,
		] );
	}

	private static function register_place() {
		register_post_type( 'place', [
			'labels' => [
				'name'          => __( 'Places', 'geolander' ),
				'singular_name' => __( 'Place', 'geolander' ),
			],
			'public'       => true,
			'has_archive'  => true,
			'rewrite'      => [ 'slug' => 'places', 'with_front' => false ],
			'menu_icon'    => 'dashicons-location-alt',
			'menu_position'=> 6,
			'supports'     => [ 'title', 'editor', 'thumbnail', 'excerpt', 'page-attributes', 'custom-fields' ],
			'show_in_rest' => true,
		] );

		register_taxonomy( 'place_region', 'place', [
			'labels'       => [ 'name' => __( 'Regions', 'geolander' ), 'singular_name' => __( 'Region', 'geolander' ) ],
			'public'       => true,
			'hierarchical' => true,
			'rewrite'      => [ 'slug' => 'region', 'with_front' => false ],
			'show_in_rest' => true,
			'show_admin_column' => true,
		] );
	}

	private static function register_testimonial() {
		register_post_type( 'testimonial', [
			'labels' => [
				'name'          => __( 'Testimonials', 'geolander' ),
				'singular_name' => __( 'Testimonial', 'geolander' ),
			],
			'public'             => false,
			'show_ui'            => true,
			'publicly_queryable' => false,
			'menu_icon'          => 'dashicons-format-quote',
			'menu_position'      => 7,
			'supports'           => [ 'title', 'editor', 'page-attributes', 'custom-fields' ],
			'show_in_rest'       => true,
		] );
	}

	/**
	 * Car meta. Seasonal pricing is an array of season rows, each with a
	 * label/date period and the seven duration-tier day rates (USD).
	 */
	private static function register_meta() {
		$string = [ 'type' => 'string',  'single' => true, 'show_in_rest' => true, 'sanitize_callback' => 'sanitize_text_field' ];
		$int    = [ 'type' => 'integer', 'single' => true, 'show_in_rest' => true, 'sanitize_callback' => 'absint' ];
		$number = [ 'type' => 'number',  'single' => true, 'show_in_rest' => true ];
		$bool   = [ 'type' => 'boolean', 'single' => true, 'show_in_rest' => true ];

		foreach ( [
			'glc_registration'     => $string,
			'glc_year'             => $int,
			'glc_color'            => $string,
			'glc_seats'            => $int,
			'glc_transmission'     => $string,
			'glc_fuel_type'        => $string,
			'glc_license_category' => $string,
			'glc_price_from'       => $number,
			'glc_available'        => $bool,
		] as $key => $args ) {
			register_post_meta( 'car', $key, array_merge( $args, [ 'auth_callback' => [ __CLASS__, 'can_edit' ] ] ) );
		}

		register_post_meta( 'car', 'glc_pricing', [
			'type'          => 'array',
			'single'        => true,
			'auth_callback' => [ __CLASS__, 'can_edit' ],
			'show_in_rest'  => [
				'schema' => [
					'type'  => 'array',
					'items' => [
						'type'       => 'object',
						'properties' => [
							'label'      => [ 'type' => 'string' ],
							'from'       => [ 'type' => 'string' ], // MM-DD
							'to'         => [ 'type' => 'string' ], // MM-DD
							'rates'      => [
								'type'       => 'object',
								'properties' => array_fill_keys( GLC_Pricing::TIERS, [ 'type' => 'number' ] ),
								'additionalProperties' => false,
							],
						],
					],
				],
			],
		] );

		register_post_meta( 'car', 'glc_gallery', [
			'type'          => 'array',
			'single'        => true,
			'auth_callback' => [ __CLASS__, 'can_edit' ],
			'show_in_rest'  => [ 'schema' => [ 'type' => 'array', 'items' => [ 'type' => 'integer' ] ] ],
		] );

		// Place meta.
		register_post_meta( 'place', 'glc_name_ka', array_merge( $string, [ 'auth_callback' => [ __CLASS__, 'can_edit' ] ] ) );
		register_post_meta( 'place', 'glc_lat', array_merge( $number, [ 'auth_callback' => [ __CLASS__, 'can_edit' ] ] ) );
		register_post_meta( 'place', 'glc_lng', array_merge( $number, [ 'auth_callback' => [ __CLASS__, 'can_edit' ] ] ) );

		// Testimonial meta.
		register_post_meta( 'testimonial', 'glc_rating', array_merge( $int, [ 'auth_callback' => [ __CLASS__, 'can_edit' ] ] ) );
		register_post_meta( 'testimonial', 'glc_route', array_merge( $string, [ 'auth_callback' => [ __CLASS__, 'can_edit' ] ] ) );
	}

	public static function can_edit() {
		return current_user_can( 'edit_posts' );
	}
}
