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

	/**
	 * Best-effort client IP behind Railway's TLS proxy (rate-limit key only).
	 *
	 * The LEFTMOST X-Forwarded-For entry is client-supplied and spoofable, so we
	 * never trust it. Prefer Railway's `X-Real-IP` (its documented single source
	 * of truth); else walk XFF right-to-left and take the first public address
	 * (the closest hop the edge itself vouches for, skipping private proxy hops);
	 * else the direct peer. Worst case a determined attacker rotates the key —
	 * acceptable for a throttle, and better than a bare REMOTE_ADDR that would
	 * collapse every visitor behind the proxy into one shared bucket.
	 */
	private static function client_ip(): string {
		$real = $_SERVER['HTTP_X_REAL_IP'] ?? '';
		if ( $real && filter_var( $real, FILTER_VALIDATE_IP ) ) {
			return $real;
		}
		$fwd = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
		if ( $fwd ) {
			foreach ( array_reverse( array_map( 'trim', explode( ',', $fwd ) ) ) as $ip ) {
				if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
					return $ip;
				}
			}
		}
		return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
	}

	/**
	 * Per-IP throttle for checkout. Each call logs a booking_request post and
	 * may hit an external gateway, so an unthrottled public endpoint is a DB-
	 * bloat / cost vector. Allow 10 requests/hour/IP — well above any human
	 * booking cadence. Uses transients (Redis once an object cache is attached).
	 */
	private static function rate_limited(): bool {
		$key   = 'glc_rl_' . md5( self::client_ip() );
		$count = (int) get_transient( $key );
		if ( $count >= 10 ) {
			return true;
		}
		set_transient( $key, $count + 1, HOUR_IN_SECONDS );
		return false;
	}

	public static function checkout( WP_REST_Request $req ) {
		// Throttle before pricing work so a blocked IP costs one cache read.
		if ( self::rate_limited() ) {
			return new WP_Error( 'glc_rate_limited', __( 'Too many requests. Please try again in a little while.', 'geolander' ), [ 'status' => 429 ] );
		}
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
