<?php
/**
 * A2A Protocol v1.0 JSON-RPC interface for Geolander's read-only rental agent.
 */

defined( 'ABSPATH' ) || exit;

class GLC_A2A {

	private const PROTOCOL_VERSION = '1.0';

	public static function init(): void {
		add_action( 'rest_api_init', [ __CLASS__, 'route' ] );
	}

	public static function route(): void {
		register_rest_route(
			'geolander-agent/v1',
			'/a2a',
			[
				'methods'             => 'POST',
				'callback'            => [ __CLASS__, 'handle' ],
				'permission_callback' => [ __CLASS__, 'authorize' ],
			]
		);
	}

	/** Reject cross-site browser calls, then verify the Cloudflare Access JWT. */
	public static function authorize( WP_REST_Request $request ) {
		$origin = trim( (string) $request->get_header( 'origin' ) );
		if ( '' !== $origin && untrailingslashit( $origin ) !== untrailingslashit( home_url( '/' ) ) ) {
			return new WP_Error( 'glc_a2a_origin', 'Cross-origin browser requests are not allowed.', [ 'status' => 403 ] );
		}
		return GLC_Access::authorize_agent_request();
	}

	public static function handle( WP_REST_Request $request ): WP_REST_Response {
		$content_type = strtolower( trim( explode( ';', (string) $request->get_header( 'content-type' ) )[0] ) );
		if ( 'application/json' !== $content_type ) {
			return self::error( null, -32005, 'The request Content-Type is not supported.', 400 );
		}

		$message = $request->get_json_params();
		if ( ! is_array( $message ) || '2.0' !== ( $message['jsonrpc'] ?? null ) || ! isset( $message['method'] ) || ! array_key_exists( 'id', $message ) ) {
			return self::error( $message['id'] ?? null, -32600, 'Request payload validation error', 400 );
		}

		$version = trim( (string) $request->get_header( 'a2a-version' ) );
		if ( self::PROTOCOL_VERSION !== $version ) {
			return self::error( $message['id'], -32009, 'The A2A protocol version is not supported.', 400 );
		}

		if ( 'SendMessage' !== (string) $message['method'] ) {
			$known_methods = [
				'SendStreamingMessage',
				'GetTask',
				'ListTasks',
				'CancelTask',
				'SubscribeToTask',
				'CreateTaskPushNotificationConfig',
				'GetTaskPushNotificationConfig',
				'ListTaskPushNotificationConfigs',
				'DeleteTaskPushNotificationConfig',
				'GetExtendedAgentCard',
			];
			if ( in_array( (string) $message['method'], $known_methods, true ) ) {
				return self::error( $message['id'], -32004, 'This A2A operation is not supported.', 400 );
			}
			return self::error( $message['id'], -32601, 'Method not found', 404 );
		}

		$params   = is_array( $message['params'] ?? null ) ? $message['params'] : [];
		$incoming = is_array( $params['message'] ?? null ) ? $params['message'] : [];
		if ( 'ROLE_USER' !== ( $incoming['role'] ?? null ) || '' === trim( (string) ( $incoming['messageId'] ?? '' ) ) || empty( $incoming['parts'] ) || ! is_array( $incoming['parts'] ) ) {
			return self::error( $message['id'], -32602, 'Invalid parameters', 400 );
		}

		[ $skill, $arguments ] = self::requested_skill( $incoming['parts'] );
		if ( null === $skill ) {
			return self::message_result(
				$message['id'],
				$incoming,
				'help',
				[
					'acceptedSkills' => [ 'fleet-discovery', 'rental-policy', 'rental-quote' ],
					'usage'          => 'Send a text request about fleet or policy, or an application/json data part with skill and quote arguments.',
				],
				'I can list Geolander cars, return verified rental policy, or calculate a dated quote. A quote requires a structured data part with skill, car, from, to, pickup, and return fields.'
			);
		}

		if ( 'fleet-discovery' === $skill ) {
			$data = GLC_MCP::fleet_data();
			return self::message_result( $message['id'], $incoming, $skill, $data, 'Published exact-car fleet returned. Cars without a verified positive rate omit the price.' );
		}

		if ( 'rental-policy' === $skill ) {
			return self::message_result( $message['id'], $incoming, $skill, GLC_MCP::policy_data(), 'Owner-verified Geolander rental policy returned.' );
		}

		$data = GLC_MCP::quote_data( $arguments );
		if ( is_wp_error( $data ) ) {
			return self::error( $message['id'], -32602, 'Invalid parameters', 400 );
		}
		return self::message_result( $message['id'], $incoming, $skill, $data, 'Date-specific quote returned. Availability is not confirmed and no reservation was created.' );
	}

	/** Resolve a documented skill from structured data first, then safe text intents. */
	private static function requested_skill( array $parts ): array {
		$text = '';
		foreach ( $parts as $part ) {
			if ( ! is_array( $part ) ) {
				continue;
			}
			if ( isset( $part['data'] ) && is_array( $part['data'] ) ) {
				$data  = $part['data'];
				$skill = sanitize_key( (string) ( $data['skill'] ?? $data['action'] ?? '' ) );
				$aliases = [
					'list-fleet'         => 'fleet-discovery',
					'list_fleet'         => 'fleet-discovery',
					'get-booking-policy' => 'rental-policy',
					'get_booking_policy' => 'rental-policy',
					'get-rental-quote'   => 'rental-quote',
					'get_rental_quote'   => 'rental-quote',
				];
				$skill = $aliases[ $skill ] ?? $skill;
				if ( in_array( $skill, [ 'fleet-discovery', 'rental-policy', 'rental-quote' ], true ) ) {
					unset( $data['skill'], $data['action'] );
					return [ $skill, $data ];
				}
			}
			if ( isset( $part['text'] ) && is_string( $part['text'] ) ) {
				$text .= ' ' . strtolower( $part['text'] );
			}
		}

		if ( preg_match( '/\b(quote|price|cost|rate)\b/', $text ) ) {
			return [ 'rental-quote', [] ];
		}
		if ( preg_match( '/\b(policy|insurance|deposit|mileage|tyres|tires|cancellation|prepayment|route)\b/', $text ) ) {
			return [ 'rental-policy', [] ];
		}
		if ( preg_match( '/\b(fleet|cars?|vehicles?|suv|4x4|awd)\b/', $text ) ) {
			return [ 'fleet-discovery', [] ];
		}
		return [ null, [] ];
	}

	private static function message_result( $id, array $incoming, string $skill, array $data, string $summary ): WP_REST_Response {
		$context_id = trim( (string) ( $incoming['contextId'] ?? '' ) );
		if ( '' === $context_id ) {
			$context_id = wp_generate_uuid4();
		}
		return self::response(
			[
				'jsonrpc' => '2.0',
				'id'      => $id,
				'result'  => [
					'message' => [
						'messageId' => wp_generate_uuid4(),
						'contextId' => $context_id,
						'role'      => 'ROLE_AGENT',
						'parts'     => [
							[ 'text' => $summary, 'mediaType' => 'text/plain' ],
							[ 'data' => $data, 'mediaType' => 'application/json' ],
						],
						'metadata'  => [ 'skillId' => $skill, 'readOnly' => true ],
					],
				],
			]
		);
	}

	private static function error( $id, int $code, string $message, int $status ): WP_REST_Response {
		return self::response( [ 'jsonrpc' => '2.0', 'id' => $id, 'error' => [ 'code' => $code, 'message' => $message ] ], $status );
	}

	private static function response( array $body, int $status = 200 ): WP_REST_Response {
		$response = new WP_REST_Response( $body, $status );
		$response->header( 'A2A-Version', self::PROTOCOL_VERSION );
		return $response;
	}
}
