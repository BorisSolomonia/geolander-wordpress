<?php
/**
 * Booking REST API: live quotes and checkout handoff.
 *
 * Current stage: quote → WhatsApp deep link with a prefilled request.
 * The gateway abstraction (GLC_Gateways) lets BOG iPay replace WhatsApp
 * without touching the front end: the widget always POSTs /checkout and
 * follows the returned redirect URL.
 */

defined( 'ABSPATH' ) || exit;

class GLC_Booking {

	public static function init() {
		add_action( 'rest_api_init', [ __CLASS__, 'routes' ] );
	}

	public static function routes() {
		register_rest_route( 'geolander/v1', '/quote', [
			'methods'             => 'GET',
			'callback'            => [ __CLASS__, 'quote' ],
			'permission_callback' => '__return_true',
			'args'                => [
				'car'  => [ 'required' => true, 'type' => 'integer' ],
				'from' => [ 'required' => true, 'type' => 'string' ],
				'to'   => [ 'required' => true, 'type' => 'string' ],
			],
		] );

		register_rest_route( 'geolander/v1', '/checkout', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'checkout' ],
			'permission_callback' => '__return_true',
			'args'                => [
				'car'  => [ 'required' => true, 'type' => 'integer' ],
				'from' => [ 'required' => true, 'type' => 'string' ],
				'to'   => [ 'required' => true, 'type' => 'string' ],
				'name' => [ 'required' => false, 'type' => 'string' ],
			],
		] );
	}

	private static function validate( WP_REST_Request $req ): array|WP_Error {
		$car_id = (int) $req['car'];
		$post   = get_post( $car_id );
		if ( ! $post || 'car' !== $post->post_type || 'publish' !== $post->post_status ) {
			return new WP_Error( 'glc_no_car', __( 'Unknown vehicle.', 'geolander' ), [ 'status' => 404 ] );
		}
		$from = preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $req['from'] ) ? $req['from'] : null;
		$to   = preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $req['to'] ) ? $req['to'] : null;
		if ( ! $from || ! $to ) {
			return new WP_Error( 'glc_bad_dates', __( 'Invalid dates.', 'geolander' ), [ 'status' => 400 ] );
		}
		$quote = GLC_Pricing::quote( $car_id, $from, $to );
		if ( ! $quote ) {
			return new WP_Error( 'glc_no_quote', __( 'Could not price these dates.', 'geolander' ), [ 'status' => 400 ] );
		}
		return [ 'car_id' => $car_id, 'post' => $post, 'from' => $from, 'to' => $to, 'quote' => $quote ];
	}

	public static function quote( WP_REST_Request $req ) {
		$ctx = self::validate( $req );
		if ( is_wp_error( $ctx ) ) {
			return $ctx;
		}
		return rest_ensure_response( array_merge( $ctx['quote'], [
			'car'   => $ctx['post']->post_title,
			'from'  => $ctx['from'],
			'to'    => $ctx['to'],
		] ) );
	}

	public static function checkout( WP_REST_Request $req ) {
		$ctx = self::validate( $req );
		if ( is_wp_error( $ctx ) ) {
			return $ctx;
		}
		$gateway = GLC_Gateways::active();
		$result  = $gateway->checkout( $ctx['car_id'], $ctx['from'], $ctx['to'], $ctx['quote'], sanitize_text_field( (string) $req['name'] ) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return rest_ensure_response( $result );
	}
}
