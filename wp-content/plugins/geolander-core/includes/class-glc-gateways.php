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
	abstract public function checkout( int $car_id, string $from, string $to, array $quote, string $name = '' ): array|WP_Error;

	/** Log the request server-side and mint a human reference like GL-2417. */
	protected function log_request( int $car_id, string $from, string $to, array $quote, string $name ): string {
		$post_id = wp_insert_post( [
			'post_type'   => 'booking_request',
			'post_status' => 'publish',
			'post_title'  => 'pending', // replaced below once the ID is known
			'meta_input'  => [
				'glc_car_id'  => $car_id,
				'glc_from'    => $from,
				'glc_to'      => $to,
				'glc_days'    => $quote['days'],
				'glc_total'   => $quote['total'],
				'glc_name'    => $name,
				'glc_gateway' => $this->id(),
			],
		] );
		$reference = 'GL-' . ( 1000 + (int) $post_id );
		wp_update_post( [ 'ID' => $post_id, 'post_title' => sprintf( '%s — %s (%s → %s)', $reference, get_the_title( $car_id ), $from, $to ) ] );
		update_post_meta( $post_id, 'glc_reference', $reference );
		return $reference;
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
	public static function url( string $text = '' ): string {
		$digits = preg_replace( '/[^0-9]/', '', (string) GLC_Settings::get( 'whatsapp_number' ) );
		if ( ! $digits ) {
			return '';
		}
		return 'https://api.whatsapp.com/send/?phone=' . rawurlencode( '+' . $digits )
			. '&text=' . rawurlencode( $text )
			. '&type=phone_number&app_absent=0';
	}

	public function id(): string {
		return 'whatsapp';
	}

	public function is_available(): bool {
		return (bool) GLC_Settings::get( 'whatsapp_number' );
	}

	public function checkout( int $car_id, string $from, string $to, array $quote, string $name = '' ): array|WP_Error {
		$number = preg_replace( '/[^0-9]/', '', (string) GLC_Settings::get( 'whatsapp_number' ) );
		if ( ! $number ) {
			return new WP_Error( 'glc_no_whatsapp', __( 'Booking channel is not configured.', 'geolander' ), [ 'status' => 500 ] );
		}

		$reference = $this->log_request( $car_id, $from, $to, $quote, $name );

		// DELIBERATELY ENGLISH — do not "localize" this (decision: 2026-07-16).
		// This message is composed in the customer's WhatsApp but READ BY GEOLANDER
		// STAFF in Tbilisi. Translating it to the visitor's locale would deliver
		// bookings the team cannot read (e.g. a Chinese customer's request arriving
		// as 预订请求). It is an operational message, not UI copy, so it stays in one
		// staff-readable language. Amounts stay in plain en-US format for the same
		// reason — this is not a place for GLC_Format's locale rules.
		$lines = [
			'Booking request ' . $reference,
			'Car: ' . get_the_title( $car_id ),
			sprintf( 'Dates: %s -> %s (%d %s)', $from, $to, $quote['days'], 1 === $quote['days'] ? 'day' : 'days' ),
			sprintf( 'Total: $%s ($%s/day)', number_format( $quote['total'], 0 ), number_format( $quote['per_day_avg'], 0 ) ),
		];
		if ( $name ) {
			$lines[] = 'Name: ' . $name;
		}
		$message = implode( "\n", $lines );

		return [
			'redirect'  => self::url( $message ),
			'reference' => $reference,
			'message'   => $message,
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

	public function checkout( int $car_id, string $from, string $to, array $quote, string $name = '' ): array|WP_Error {
		$token = $this->token();
		if ( is_wp_error( $token ) ) {
			return $token;
		}

		$reference = $this->log_request( $car_id, $from, $to, $quote, $name );

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

		return [ 'redirect' => $redirect, 'reference' => $reference ];
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
