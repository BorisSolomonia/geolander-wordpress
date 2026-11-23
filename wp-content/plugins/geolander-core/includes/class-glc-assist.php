<?php
/**
 * Page context for the "Ask about this" control, ChatGPT annotation markup,
 * WebMCP site tools, and admin-only design-review controls.
 *
 * Every one of those surfaces reads ONE context object, printed once per page
 * from the same data the page renders, so a WhatsApp message, an annotation
 * and an agent tool can never name a different car or price than the page.
 *
 * Tool definitions are GLC_MCP::tools() and tool results come from the
 * GLC_MCP data functions: the browser tools and the MCP server share one
 * source. Booking stays in the public checkout route with its own validation
 * and rate limit; the browser tool only calls it and hands back the WhatsApp
 * link for the traveller to send.
 */

defined( 'ABSPATH' ) || exit;

class GLC_Assist {

	/** Text placeholder swapped by the browser into the one WhatsApp builder's output. */
	private const TEXT_SLOT = 'GLC_ASK_TEXT';

	public static function init(): void {
		add_action( 'wp_enqueue_scripts', [ __CLASS__, 'assets' ] );
		add_action( 'rest_api_init', [ __CLASS__, 'route' ] );
	}

	public static function assets(): void {
		if ( is_admin() ) {
			return;
		}
		$js  = GLC_DIR . 'assets/assist.js';
		$css = GLC_DIR . 'assets/assist.css';
		wp_enqueue_style( 'glc-assist', GLC_URL . 'assets/assist.css', [], GLC_VERSION . '.' . filemtime( $css ) );
		wp_enqueue_script( 'glc-assist', GLC_URL . 'assets/assist.js', [], GLC_VERSION . '.' . filemtime( $js ), [ 'strategy' => 'defer', 'in_footer' => true ] );
		wp_add_inline_script( 'glc-assist', 'window.glcAssist = ' . wp_json_encode( self::context() ) . ';', 'before' );
	}

	/** The one per-page context every surface reads. Public data only. */
	public static function context(): array {
		$ctx = [
			'locale'  => class_exists( 'GLC_I18n' ) ? GLC_I18n::locale() : 'en',
			'page'    => self::page_url(),
			'rest'    => esc_url_raw( rest_url( 'geolander/v1/' ) ),
			'slot'    => self::TEXT_SLOT,
			// The site number, as for booking requests: a car's own (partner) number never goes into page source.
			'wa'      => class_exists( 'GLC_Gateway_WhatsApp' ) ? GLC_Gateway_WhatsApp::url( self::TEXT_SLOT ) : '',
			'cars'    => self::cars(),
			'car'     => is_singular( 'car' ) ? (int) get_queried_object_id() : 0,
			'tools'   => self::tools(),
			'strings' => [
				'ask'       => glc_ui( 'ask_about_this' ),
				'askQuote'  => glc_ui( 'ask_about_quote' ),
				'askLabel'  => glc_ui( 'ask_about_label' ),
				'design'    => glc_ui( 'design_controls_heading' ),
			],
		];
		// Design-review controls preview token changes; only people who can change the theme get them.
		if ( current_user_can( 'edit_theme_options' ) ) {
			$ctx['design'] = self::palette();
		}
		return $ctx;
	}

	/** Canonical URL of the page the visitor is on, without the query string. */
	private static function page_url(): string {
		$path = (string) wp_parse_url( (string) ( $_SERVER['REQUEST_URI'] ?? '/' ), PHP_URL_PATH );
		return esc_url_raw( home_url( $path ?: '/' ) );
	}

	/**
	 * Published cars keyed by slug, so a fleet card on any page (the slug is the
	 * last part of its link) finds its car. Public fields only.
	 */
	private static function cars(): array {
		$cars = [];
		foreach ( get_posts( [ 'post_type' => 'car', 'post_status' => 'publish', 'posts_per_page' => -1, 'orderby' => 'menu_order', 'order' => 'ASC' ] ) as $car ) {
			$cars[ $car->post_name ] = [
				'id'    => $car->ID,
				'title' => $car->post_title,
				'plate' => strtoupper( (string) get_post_meta( $car->ID, 'glc_registration', true ) ),
			];
		}
		return $cars;
	}

	/**
	 * WebMCP shapes of the MCP tools. WebMCP documents only a few annotation keys,
	 * so the MCP hints are narrowed to readOnlyHint; everything else is copied.
	 */
	private static function tools(): array {
		if ( ! class_exists( 'GLC_MCP' ) || ! method_exists( 'GLC_MCP', 'tools' ) ) {
			return [];
		}
		$out = [];
		foreach ( GLC_MCP::tools() as $tool ) {
			$out[] = [
				'name'        => $tool['name'],
				'description' => $tool['description'],
				'inputSchema' => $tool['inputSchema'],
				'annotations' => [ 'readOnlyHint' => ! empty( $tool['annotations']['readOnlyHint'] ) ],
			];
		}
		return $out;
	}

	/** Theme palette colours (slug + name); current values are read in the browser. */
	private static function palette(): array {
		$palette = function_exists( 'wp_get_global_settings' ) ? wp_get_global_settings( [ 'color', 'palette', 'theme' ] ) : [];
		$out     = [];
		foreach ( is_array( $palette ) ? $palette : [] as $color ) {
			if ( ! empty( $color['slug'] ) ) {
				$out[] = [ 'slug' => (string) $color['slug'], 'name' => (string) ( $color['name'] ?? $color['slug'] ) ];
			}
		}
		return $out;
	}

	/* ------------------------------------------------------- Site tools */

	public static function route(): void {
		register_rest_route( 'geolander/v1', '/site-tool', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'call' ],
			// Public on purpose: these tools return exactly what the public quote route and pages already show.
			'permission_callback' => '__return_true',
			'args'                => [
				'name'      => [ 'required' => true, 'type' => 'string', 'maxLength' => 64 ],
				'arguments' => [ 'required' => false, 'type' => 'object', 'default' => [] ],
			],
		] );
	}

	/** Runs one read-only MCP tool through the same data functions the MCP server uses. */
	public static function call( WP_REST_Request $request ) {
		$name      = sanitize_key( (string) $request['name'] );
		$arguments = is_array( $request['arguments'] ) ? $request['arguments'] : [];
		$tool      = array_values( array_filter( self::tools(), static fn( $t ) => $t['name'] === $name && $t['annotations']['readOnlyHint'] ) )[0] ?? null;
		if ( ! $tool ) {
			return new WP_Error( 'glc_site_tool', 'Unknown or non-read-only tool.', [ 'status' => 404 ] );
		}
		// The tool's own schema, as the public quote route enforces its args: no arrays where a date belongs.
		// MCP keeps an empty properties as an object so it prints as {}; the WordPress validator wants an array.
		$schema               = $tool['inputSchema'];
		$schema['properties'] = (array) ( $schema['properties'] ?? [] );
		$valid                = rest_validate_value_from_schema( $arguments, $schema, 'arguments' );
		if ( is_wp_error( $valid ) ) {
			return new WP_Error( 'glc_site_tool_args', $valid->get_error_message(), [ 'status' => 400 ] );
		}
		$arguments = (array) rest_sanitize_value_from_schema( $arguments, $schema, 'arguments' );
		if ( 'list_fleet' === $name ) {
			return rest_ensure_response( GLC_MCP::fleet_data() );
		}
		if ( 'get_booking_policy' === $name ) {
			return rest_ensure_response( GLC_MCP::policy_data() );
		}
		if ( 'get_rental_quote' === $name ) {
			$data = GLC_MCP::quote_data( $arguments );
			return is_wp_error( $data ) ? $data : rest_ensure_response( $data );
		}
		// A read-only MCP tool added later needs its own branch here before the browser can run it.
		return new WP_Error( 'glc_site_tool', 'This tool has no browser route yet.', [ 'status' => 501 ] );
	}
}
