<?php
/**
 * Checkout gateways.
 *
 * Every gateway receives a validated, server-priced quote and returns
 * ['redirect' => url, 'reference' => GL-xxxx]. The front end never builds
 * its own price or destination, so swapping WhatsApp for BOG iPay is a
 * settings change, not a redesign. Every request is logged as a
 * `booking_request` post before the user leaves the site.
 */

defined( 'ABSPATH' ) || exit;

abstract class GLC_Gateway {

	abstract public function id(): string;

	abstract public function is_available(): bool;

	/**
	 * @return array{redirect:string, reference:string, message?:string}|WP_Error
	 */
	abstract public function checkout( int $car_id, string $from, string $to, array $quote, array $customer = [] ): array|WP_Error;

	/**
	 * Log the request server-side and mint a human reference like GL-2417.
	 *
	 * $route is where the customer's message is being sent (see
	 * GLC_Gateway_WhatsApp::route_for()); it is stored with the request so the
	 * owner's count of messages per partner is read from the requests themselves
	 * and a later reassignment of the car does not move old requests. A gateway
	 * that sends no message to anyone passes none.
	 */
	protected function log_request( int $car_id, string $from, string $to, array $quote, array $customer, array $route = [] ): array|WP_Error {
		$routing = $route ? [
			'glc_partner_id'     => (int) $route['partner_id'],
			'glc_routed_number'  => (string) $route['number'],
			'glc_routing_source' => (string) $route['source'],
		] : [];
		$post_id = wp_insert_post( [
			'post_type'   => 'booking_request',
			'post_status' => 'publish',
			'post_title'  => 'pending', // replaced below once the ID is known
			'meta_input'  => [
				'glc_car_id'  => $car_id,
				'glc_from'    => $from,
				'glc_to'      => $to,
				'glc_days'    => $quote['days'],
				'glc_rental_total' => $quote['rental_total'],
				'glc_pickup'       => $quote['pickup'],
				'glc_pickup_label' => $quote['pickup_label'],
				'glc_pickup_fee'   => $quote['pickup_fee'],
				'glc_return'       => $quote['return'],
				'glc_return_label' => $quote['return_label'],
				'glc_return_fee'   => $quote['return_fee'],
				// The handover times the total was priced for. "Assumed" means the
				// client sent none and the default time was used: staff must ask.
				'glc_pickup_time'     => $quote['pickup_time'],
				'glc_return_time'     => $quote['return_time'],
				'glc_pickup_time_fee' => $quote['pickup_time_fee'],
				'glc_return_time_fee' => $quote['return_time_fee'],
				'glc_times_assumed'   => $quote['times_assumed'] ? 1 : 0,
				'glc_total'        => $quote['total'],
				'glc_prepayment'   => $quote['prepayment'],
				'glc_balance'      => $quote['balance'],
				'glc_name'         => $customer['name'] ?? '',
				'glc_email'        => $customer['email'] ?? '',
				'glc_booking_status' => 'requested',
				'glc_gateway' => $this->id(),
			] + $routing,
		] );
		if ( is_wp_error( $post_id ) || ! $post_id ) {
			return is_wp_error( $post_id ) ? $post_id : new WP_Error( 'glc_booking_log', __( 'Could not save the booking request.', 'geolander' ) );
		}
		$reference = 'GL-' . ( 1000 + (int) $post_id );
		wp_update_post( [ 'ID' => $post_id, 'post_title' => sprintf( '%s — %s (%s → %s)', $reference, get_the_title( $car_id ), $from, $to ) ] );
		update_post_meta( $post_id, 'glc_reference', $reference );
		return [ 'post_id' => (int) $post_id, 'reference' => $reference ];
	}
}

class GLC_Gateway_WhatsApp extends GLC_Gateway {

	/**
	 * The single place a WhatsApp deep link is built. Format verified working
	 * against the live account (2026-07-16) — do not "tidy" it:
	 *
	 *  - `/send/` WITH the trailing slash.
	 *  - `phone` keeps the leading "+", URL-encoded as %2B. Sending bare digits
	 *    is what broke the old link.
	 *  - `type=phone_number&app_absent=0` are required by that flow; app_absent=0
	 *    is what hands off to the installed app rather than stranding the user on
	 *    WhatsApp Web.
	 *  - api.whatsapp.com, not wa.me: wa.me's redirect re-encodes `text` lossily
	 *    for non-ASCII content.
	 *
	 * @param string $text Prefilled message; English by design (staff read these).
	 * @return string Empty string when no number is configured.
	 */
	/**
	 * Which WhatsApp number answers for a given car, and why that one.
	 *
	 * Not every vehicle on the site is answered by the same phone: some belong to
	 * a partner who takes their own bookings. In order:
	 *
	 *  1. the phone of the partner the car is assigned to (`partner`);
	 *  2. else the number kept on the car itself, `glc_whatsapp_number` (`car`):
	 *     how partner cars were routed before partner accounts existed, and still
	 *     the answer for a car whose partner has no account or no phone yet;
	 *  3. else the site number (`site`), which is why adding a car needs no
	 *     configuration at all.
	 *
	 * None of this is a list of plates in code: the assignment and both numbers
	 * are editable data.
	 *
	 * @return array{number:string, source:string, partner_id:int} number as bare digits; partner_id is
	 *               the partner the message goes to, 0 when it goes to the car's own or the site number
	 */
	public static function route_for( ?int $car_id = null ): array {
		$digits = static fn( $raw ): string => (string) preg_replace( '/[^0-9]/', '', (string) $raw );
		if ( $car_id ) {
			$partner_id = GLC_Partners::partner_of( $car_id );
			$phone      = $partner_id ? $digits( GLC_Partners::phone( $partner_id ) ) : '';
			if ( '' !== $phone ) {
				return [ 'number' => $phone, 'source' => 'partner', 'partner_id' => $partner_id ];
			}
			$own = $digits( get_post_meta( $car_id, 'glc_whatsapp_number', true ) );
			if ( '' !== $own ) {
				return [ 'number' => $own, 'source' => 'car', 'partner_id' => 0 ];
			}
		}
		return [ 'number' => $digits( GLC_Settings::get( 'whatsapp_number' ) ), 'source' => 'site', 'partner_id' => 0 ];
	}

	public static function number_for( ?int $car_id = null ): string {
		return self::route_for( $car_id )['number'];
	}

	public static function url( string $text = '', ?int $car_id = null ): string {
		return self::link( self::number_for( $car_id ), $text );
	}

	/** The link itself, for a number already chosen. See the format note above before touching it. */
	private static function link( string $digits, string $text ): string {
		if ( ! $digits ) {
			return '';
		}
		return 'https://api.whatsapp.com/send/?phone=' . rawurlencode( '+' . $digits )
			. '&text=' . rawurlencode( $text )
			. '&type=phone_number&app_absent=0';
	}

	/**
	 * One handover time for the staff message: the time, the early/late fee when
	 * there is one, and a plain note when nobody chose the time (an app or an AI
	 * assistant sent dates only), so staff do not read a default as a wish.
	 */
	private static function time_line( array $quote, string $side ): string {
		$line = (string) $quote[ $side . '_time' ];
		if ( $quote[ $side . '_time_fee' ] > 0 ) {
			$line .= ' (early/late fee ' . GLC_Format::money_exact( $quote[ $side . '_time_fee' ], 'en' ) . ')';
		}
		return $line . ( $quote['times_assumed'] ? ' (assumed: no time was given)' : '' );
	}

	public function id(): string {
		return 'whatsapp';
	}

	public function is_available(): bool {
		return (bool) GLC_Settings::get( 'whatsapp_number' );
	}

	public function checkout( int $car_id, string $from, string $to, array $quote, array $customer = [] ): array|WP_Error {
		// Decided once: the stored request, and the link the customer is handed, name the same number.
		$route = self::route_for( $car_id );
		if ( ! $route['number'] ) {
			return new WP_Error( 'glc_no_whatsapp', __( 'Booking channel is not configured.', 'geolander' ), [ 'status' => 500 ] );
		}

		$logged = $this->log_request( $car_id, $from, $to, $quote, $customer, $route );
		if ( is_wp_error( $logged ) ) {
			return $logged;
		}
		$reference = $logged['reference'];
		$email_sent = GLC_Booking_Email::send_request_received( $logged['post_id'] );

		// DELIBERATELY ENGLISH — do not "localize" this (decision: 2026-07-16).
		// This message is composed in the customer's WhatsApp but READ BY GEOLANDER
		// STAFF in Tbilisi, or by the car's partner. It never names the partner:
		// the customer sees this text. Translating it to the visitor's locale would deliver
		// bookings the team cannot read (e.g. a Chinese customer's request arriving
		// as 预订请求). It is an operational message, not UI copy, so it stays in one
		// staff-readable language. Amounts stay in plain en-US format for the same
		// reason — this is not a place for GLC_Format's locale rules.
		$lines = [
			'Booking request ' . $reference,
			'Car: ' . get_the_title( $car_id ),
			sprintf( 'Dates: %s -> %s (%d %s)', $from, $to, $quote['days'], 1 === $quote['days'] ? 'day' : 'days' ),
			'Pickup: ' . $quote['pickup_label'],
			'Pickup time: ' . self::time_line( $quote, 'pickup' ),
			'Return: ' . $quote['return_label'],
			'Return time: ' . self::time_line( $quote, 'return' ),
			'Rental: ' . GLC_Format::money_exact( $quote['rental_total'], 'en' ),
		];
		if ( $quote['pickup_fee'] > 0 ) {
			$lines[] = 'Pickup charge: ' . GLC_Format::money_exact( $quote['pickup_fee'], 'en' );
		}
		if ( $quote['return_fee'] > 0 ) {
			$lines[] = 'Return charge: ' . GLC_Format::money_exact( $quote['return_fee'], 'en' );
		}
		$lines[] = 'Final total shown: ' . GLC_Format::money_exact( $quote['total'], 'en' );
		$lines[] = sprintf( '10%% prepayment: %s; balance at pickup: %s', GLC_Format::money_exact( $quote['prepayment'], 'en' ), GLC_Format::money_exact( $quote['balance'], 'en' ) );
		if ( ! empty( $customer['name'] ) ) {
			$lines[] = 'Name: ' . $customer['name'];
		}
		if ( ! empty( $customer['email'] ) ) {
			$lines[] = 'Email: ' . $customer['email'];
		}
		$message = implode( "\n", $lines );
		GLC_Booking_Email::send_partner_copy( $logged['post_id'], $message );

		return [
			'redirect'  => self::link( $route['number'], $message ),
			'reference' => $reference,
			'message'   => $message,
			'emailSent' => $email_sent,
		];
	}
}

/**
 * Bank of Georgia iPay (e-commerce orders API).
 *
 * Implemented against the documented BOG flow — OAuth client-credentials
 * token, then order creation returning a redirect link — but stays dormant
 * until credentials are saved and the gateway is enabled in settings.
 * Endpoints should be re-verified against current BOG docs before go-live.
 */
class GLC_Gateway_BOG_iPay extends GLC_Gateway {

	private const TOKEN_URL = 'https://oauth2.bog.ge/auth/realms/bog/protocol/openid-connect/token';
	private const ORDER_URL = 'https://api.bog.ge/payments/v1/ecommerce/orders';

	public function id(): string {
		return 'bog_ipay';
	}

	public function is_available(): bool {
		return 'bog_ipay' === GLC_Settings::get( 'payment_provider' )
			&& GLC_Settings::get( 'bog_client_id' )
			&& GLC_Settings::get( 'bog_client_secret' );
	}

	public function checkout( int $car_id, string $from, string $to, array $quote, array $customer = [] ): array|WP_Error {
		$token = $this->token();
		if ( is_wp_error( $token ) ) {
			return $token;
		}

		$logged = $this->log_request( $car_id, $from, $to, $quote, $customer );
		if ( is_wp_error( $logged ) ) {
			return $logged;
		}
		$reference = $logged['reference'];

		// BOG requires the Idempotency-Key to be a UUID (a "create:<id>"-style
		// string is rejected). No retry loop here, so a fresh v4 per attempt is
		// correct. total_amount / unit_price are JSON numbers below, not strings.
		$response = wp_remote_post( self::ORDER_URL, [
			'timeout' => 20,
			'headers' => [
				'Authorization'   => 'Bearer ' . $token,
				'Content-Type'    => 'application/json',
				'Idempotency-Key' => wp_generate_uuid4(),
			],
			'body'    => wp_json_encode( [
				'external_order_id' => $reference,
				'purchase_units'    => [
					'currency'     => GLC_Settings::get( 'payment_currency', 'USD' ),
					'total_amount' => $quote['total'],
					'basket'       => [ [
						'product_id' => (string) $car_id,
						'name'       => get_the_title( $car_id ) . " rental {$from} → {$to}",
						'quantity'   => 1,
						'unit_price' => $quote['total'],
					] ],
				],
				'redirect_urls'     => [
					'success' => add_query_arg( [ 'glc_payment' => 'success', 'ref' => $reference ], home_url( '/' ) ),
					'fail'    => add_query_arg( [ 'glc_payment' => 'fail', 'ref' => $reference ], home_url( '/' ) ),
				],
			] ),
		] );

		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$body     = json_decode( wp_remote_retrieve_body( $response ), true );
		$redirect = $body['_links']['redirect']['href'] ?? null;
		if ( ! $redirect ) {
			return new WP_Error( 'glc_bog_order', __( 'Payment provider did not return a redirect.', 'geolander' ), [ 'status' => 502 ] );
		}

		return [
			'redirect'  => $redirect,
			'reference' => $reference,
			'emailSent' => GLC_Booking_Email::send_request_received( $logged['post_id'] ),
		];
	}

	private function token(): string|WP_Error {
		$cached = get_transient( 'glc_bog_token' );
		if ( $cached ) {
			return $cached;
		}
		$response = wp_remote_post( self::TOKEN_URL, [
			'timeout' => 20,
			'headers' => [
				'Authorization' => 'Basic ' . base64_encode( GLC_Settings::get( 'bog_client_id' ) . ':' . GLC_Settings::get( 'bog_client_secret' ) ),
				'Content-Type'  => 'application/x-www-form-urlencoded',
			],
			'body'    => [ 'grant_type' => 'client_credentials' ],
		] );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( empty( $body['access_token'] ) ) {
			return new WP_Error( 'glc_bog_auth', __( 'Payment provider authentication failed.', 'geolander' ), [ 'status' => 502 ] );
		}
		set_transient( 'glc_bog_token', $body['access_token'], max( 60, (int) ( $body['expires_in'] ?? 900 ) - 60 ) );
		return $body['access_token'];
	}
}

class GLC_Gateways {

	public static function active(): GLC_Gateway {
		$bog = new GLC_Gateway_BOG_iPay();
		if ( $bog->is_available() ) {
			return $bog;
		}
		return new GLC_Gateway_WhatsApp();
	}
}

// Internal log of booking requests (ops visibility; never public).
add_action( 'init', function () {
	register_post_type( 'booking_request', [
		'labels'             => [ 'name' => __( 'Booking Requests', 'geolander' ), 'singular_name' => __( 'Booking Request', 'geolander' ) ],
		'public'             => false,
		'show_ui'            => true,
		'publicly_queryable' => false,
		'exclude_from_search'=> true,
		'menu_icon'          => 'dashicons-clipboard',
		'menu_position'      => 8,
		'supports'           => [ 'title' ],
		'capabilities'       => [ 'create_posts' => 'do_not_allow' ], // created only via checkout
		'map_meta_cap'       => true,
	] );
} );
