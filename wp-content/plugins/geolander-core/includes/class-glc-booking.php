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
	private const RATE_LIMIT_OPTION = 'glc_checkout_rate_limit';
	private const RATE_LIMIT_MAX = 20;
	private const RATE_LIMIT_WINDOW = HOUR_IN_SECONDS;

	public static function init() {
		add_action( 'rest_api_init', [ __CLASS__, 'routes' ] );
	}

	public static function routes() {
		$quote = [
			'methods'             => 'GET',
			'callback'            => [ __CLASS__, 'quote' ],
			'permission_callback' => '__return_true',
			'args'                => [
				'car'  => [ 'required' => true, 'type' => 'integer' ],
				'from' => [ 'required' => true, 'type' => 'string', 'maxLength' => 10 ],
				'to'   => [ 'required' => true, 'type' => 'string', 'maxLength' => 10 ],
				'pickup' => [ 'required' => false, 'type' => 'string', 'maxLength' => 32, 'default' => GLC_Rental::DEFAULT_PICKUP ],
				'return' => [ 'required' => false, 'type' => 'string', 'maxLength' => 32, 'default' => GLC_Rental::DEFAULT_RETURN ],
			],
		];

		$checkout = [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'checkout' ],
			'permission_callback' => '__return_true',
			'args'                => [
				'car'  => [ 'required' => true, 'type' => 'integer' ],
				'from' => [ 'required' => true, 'type' => 'string', 'maxLength' => 10 ],
				'to'   => [ 'required' => true, 'type' => 'string', 'maxLength' => 10 ],
				'name'   => [ 'required' => true, 'type' => 'string', 'maxLength' => 120 ],
				'email'  => [ 'required' => true, 'type' => 'string', 'format' => 'email', 'maxLength' => 254 ],
				'pickup' => [ 'required' => true, 'type' => 'string', 'maxLength' => 32 ],
				'return' => [ 'required' => true, 'type' => 'string', 'maxLength' => 32 ],
			],
		];

		// Customer-facing routes remain public so the booking widget does not
		// acquire an identity-provider login step.
		register_rest_route( 'geolander/v1', '/quote', $quote );
		register_rest_route( 'geolander/v1', '/checkout', $checkout );

		// Automated clients use a distinct Cloudflare Access application. The
		// edge performs OAuth; WordPress independently validates its signed JWT.
		$quote['permission_callback']    = [ 'GLC_Access', 'authorize_agent_request' ];
		$checkout['permission_callback'] = [ 'GLC_Access', 'authorize_agent_request' ];
		register_rest_route( 'geolander-agent/v1', '/quote', $quote );
		register_rest_route( 'geolander-agent/v1', '/checkout', $checkout );
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
		$pickup = sanitize_key( (string) ( $req['pickup'] ?: GLC_Rental::DEFAULT_PICKUP ) );
		$return = sanitize_key( (string) ( $req['return'] ?: GLC_Rental::DEFAULT_RETURN ) );
		if ( ! GLC_Rental::valid_location( $pickup ) || ! GLC_Rental::valid_location( $return ) ) {
			return new WP_Error( 'glc_bad_location', __( 'Invalid pickup or return location.', 'geolander' ), [ 'status' => 400 ] );
		}
		$quote = GLC_Rental::quote( $car_id, $from, $to, $pickup, $return );
		if ( ! $quote ) {
			return new WP_Error( 'glc_no_quote', __( 'Could not price these dates.', 'geolander' ), [ 'status' => 400 ] );
		}
		return [ 'car_id' => $car_id, 'post' => $post, 'from' => $from, 'to' => $to, 'pickup' => $pickup, 'return' => $return, 'quote' => $quote ];
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

	/**
	 * Fixed-size global write ceiling for checkout. The previous per-IP
	 * transient scheme let an attacker rotate/spoof addresses and create two new
	 * wp_options rows per request. One non-autoloaded option bounds storage while
	 * Cloudflare supplies the finer per-client edge limit.
	 */
	private static function rate_limited(): bool {
		$now   = time();
		$state = get_option( self::RATE_LIMIT_OPTION, [] );
		if ( ! is_array( $state ) || (int) ( $state['expires_at'] ?? 0 ) <= $now ) {
			$state = [ 'count' => 1, 'expires_at' => $now + self::RATE_LIMIT_WINDOW ];
			update_option( self::RATE_LIMIT_OPTION, $state, false );
			return false;
		}

		if ( (int) ( $state['count'] ?? 0 ) >= self::RATE_LIMIT_MAX ) {
			return true;
		}
		$state['count'] = (int) ( $state['count'] ?? 0 ) + 1;
		update_option( self::RATE_LIMIT_OPTION, $state, false );
		return false;
	}

	public static function checkout( WP_REST_Request $req ) {
		$ctx = self::validate( $req );
		if ( is_wp_error( $ctx ) ) {
			return $ctx;
		}
		$name  = sanitize_text_field( (string) $req['name'] );
		$email = sanitize_email( (string) $req['email'] );
		if ( '' === $name || ! is_email( $email ) ) {
			return new WP_Error( 'glc_customer_details', __( 'Enter your name and a valid email address.', 'geolander' ), [ 'status' => 400 ] );
		}
		// Count only structurally valid booking attempts. Invalid probes can no
		// longer create rate-limit rows, and accepted writes have one global cap.
		if ( self::rate_limited() ) {
			return new WP_Error( 'glc_rate_limited', __( 'Too many requests. Please try again in a little while.', 'geolander' ), [ 'status' => 429 ] );
		}
		$gateway = GLC_Gateways::active();
		$result  = $gateway->checkout( $ctx['car_id'], $ctx['from'], $ctx['to'], $ctx['quote'], [
			'name'  => $name,
			'email' => $email,
		] );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return rest_ensure_response( $result );
	}
}
