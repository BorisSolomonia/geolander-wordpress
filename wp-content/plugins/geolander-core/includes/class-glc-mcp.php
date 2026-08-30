<?php
/**
 * Stateless Model Context Protocol endpoint for Geolander-operated agents.
 *
 * The server deliberately exposes read-only tools only. Booking creation stays
 * in the documented checkout API, where explicit traveller approval, customer
 * identity fields, throttling, and the WhatsApp handoff are already enforced.
 */

defined( 'ABSPATH' ) || exit;

class GLC_MCP {

	private const MODERN_VERSION = '2026-07-28';
	private const LEGACY_VERSION = '2025-11-25';

	public static function init(): void {
		add_action( 'rest_api_init', [ __CLASS__, 'route' ] );
	}

	public static function route(): void {
		register_rest_route(
			'geolander-agent/v1',
			'/mcp',
			[
				'methods'             => 'POST',
				'callback'            => [ __CLASS__, 'handle' ],
				'permission_callback' => [ __CLASS__, 'authorize' ],
			],
		);
	}

	/** Reject browser-originated cross-site calls, then verify Cloudflare JWT. */
	public static function authorize( WP_REST_Request $request ) {
		$origin = trim( (string) $request->get_header( 'origin' ) );
		if ( '' !== $origin && untrailingslashit( $origin ) !== untrailingslashit( home_url( '/' ) ) ) {
			return new WP_Error( 'glc_mcp_origin', 'Cross-origin browser requests are not allowed.', [ 'status' => 403 ] );
		}
		return GLC_Access::authorize_agent_request();
	}

	public static function handle( WP_REST_Request $request ) {
		$message = $request->get_json_params();
		if ( ! is_array( $message ) || '2.0' !== ( $message['jsonrpc'] ?? null ) || ! isset( $message['method'] ) ) {
			return self::error( $message['id'] ?? null, -32600, 'Invalid Request', 400 );
		}

		$id     = $message['id'] ?? null;
		$method = (string) $message['method'];
		$params = is_array( $message['params'] ?? null ) ? $message['params'] : [];
		$modern = self::is_modern( $request, $params );
		if ( is_wp_error( $modern ) ) {
			return self::error( $id, -32022, $modern->get_error_message(), 400, [ 'supported' => [ self::MODERN_VERSION, self::LEGACY_VERSION ] ] );
		}

		if ( $modern ) {
			$header_error = self::validate_modern_headers( $request, $method, $params );
			if ( is_wp_error( $header_error ) ) {
				return self::error( $id, -32020, $header_error->get_error_message(), 400 );
			}
		}

		if ( ! array_key_exists( 'id', $message ) ) {
			return new WP_REST_Response( null, 202 );
		}

		switch ( $method ) {
			case 'server/discover':
				return self::result( $id, self::discover_result() );

			case 'initialize':
				return self::initialize( $id, $params );

			case 'tools/list':
				return self::result(
					$id,
					[
						'resultType' => 'complete',
						'tools'      => self::tools(),
						'ttlMs'      => 3600000,
						'cacheScope' => 'public',
						'_meta'      => self::server_meta(),
					]
				);

			case 'tools/call':
				return self::call_tool( $id, $params );
		}

		return self::error( $id, -32601, 'Method not found', 404 );
	}

	private static function is_modern( WP_REST_Request $request, array $params ) {
		$header  = trim( (string) $request->get_header( 'mcp-protocol-version' ) );
		$envelope = is_array( $params['_meta'] ?? null ) ? $params['_meta'] : [];
		$embedded = trim( (string) ( $envelope['io.modelcontextprotocol/protocolVersion'] ?? '' ) );
		$version  = $header ?: $embedded;
		if ( '' === $version ) {
			return false;
		}
		if ( self::MODERN_VERSION === $version ) {
			return true;
		}
		if ( self::LEGACY_VERSION === $version || '2025-06-18' === $version ) {
			return false;
		}
		return new WP_Error( 'glc_mcp_version', 'Unsupported MCP protocol version.' );
	}

	private static function validate_modern_headers( WP_REST_Request $request, string $method, array $params ) {
		if ( self::MODERN_VERSION !== trim( (string) $request->get_header( 'mcp-protocol-version' ) ) ) {
			return new WP_Error( 'glc_mcp_header_version', 'MCP-Protocol-Version must match the modern request.' );
		}
		if ( $method !== trim( (string) $request->get_header( 'mcp-method' ) ) ) {
			return new WP_Error( 'glc_mcp_header_method', 'Mcp-Method must match the JSON-RPC method.' );
		}
		if ( in_array( $method, [ 'tools/call' ], true ) ) {
			$name = trim( (string) ( $params['name'] ?? '' ) );
			if ( $name !== trim( (string) $request->get_header( 'mcp-name' ) ) ) {
				return new WP_Error( 'glc_mcp_header_name', 'Mcp-Name must match params.name.' );
			}
		}
		return true;
	}

	private static function discover_result(): array {
		return [
			'resultType'        => 'complete',
			'supportedVersions' => [ self::MODERN_VERSION, self::LEGACY_VERSION, '2025-06-18' ],
			'capabilities'      => [ 'tools' => [ 'listChanged' => false ] ],
			'instructions'      => 'Read-only Geolander tools. Use them for verified fleet facts, policy, and dated quotes. Never describe a quote as confirmed availability or a reservation.',
			'ttlMs'             => 3600000,
			'cacheScope'        => 'public',
			'_meta'             => self::server_meta(),
		];
	}

	private static function initialize( $id, array $params ): WP_REST_Response {
		$requested = (string) ( $params['protocolVersion'] ?? '' );
		$version   = in_array( $requested, [ self::LEGACY_VERSION, '2025-06-18' ], true ) ? $requested : self::LEGACY_VERSION;
		return self::result(
			$id,
			[
				'protocolVersion' => $version,
				'capabilities'    => [ 'tools' => [ 'listChanged' => false ] ],
				'serverInfo'      => [ 'name' => 'com.geo-lander/reservation', 'title' => 'Geolander Rental Tools', 'version' => '1.0.0' ],
				'instructions'    => 'Read-only fleet, rental-policy, and dated-quote tools. A quote is not confirmed availability or a reservation.',
			]
		);
	}

	private static function tools(): array {
		$locations = [ 'tbilisi_office', 'tbilisi_airport', 'kutaisi_airport', 'batumi_airport' ];
		return [
			[
				'name'        => 'list_fleet',
				'title'       => 'List exact Geolander rental cars',
				'description' => 'List published exact vehicles and positive published price ranges. Unpriced cars omit prices instead of reporting zero.',
				'inputSchema' => [ 'type' => 'object', 'properties' => (object) [], 'additionalProperties' => false ],
				'annotations' => [ 'readOnlyHint' => true, 'idempotentHint' => true, 'openWorldHint' => false ],
			],
			[
				'name'        => 'get_booking_policy',
				'title'       => 'Get verified Geolander rental policy',
				'description' => 'Return verified insurance, deposit, mileage, winter-tyre, route, handover, prepayment, and cancellation facts.',
				'inputSchema' => [ 'type' => 'object', 'properties' => (object) [], 'additionalProperties' => false ],
				'annotations' => [ 'readOnlyHint' => true, 'idempotentHint' => true, 'openWorldHint' => false ],
			],
			[
				'name'        => 'get_rental_quote',
				'title'       => 'Get a date-specific Geolander quote',
				'description' => 'Calculate a read-only quote for one published exact car, dates, pickup, and return. It does not confirm availability or create a booking.',
				'inputSchema' => [
					'type'                 => 'object',
					'additionalProperties' => false,
					'required'             => [ 'car', 'from', 'to' ],
					'properties'           => [
						'car'    => [ 'type' => 'integer', 'minimum' => 1, 'description' => 'Published WordPress car ID from list_fleet.' ],
						'from'   => [ 'type' => 'string', 'format' => 'date' ],
						'to'     => [ 'type' => 'string', 'format' => 'date' ],
						'pickup' => [ 'type' => 'string', 'enum' => $locations, 'default' => GLC_Rental::DEFAULT_PICKUP ],
						'return' => [ 'type' => 'string', 'enum' => $locations, 'default' => GLC_Rental::DEFAULT_RETURN ],
					],
				],
				'annotations' => [ 'readOnlyHint' => true, 'idempotentHint' => true, 'openWorldHint' => false ],
			],
		];
	}

	private static function call_tool( $id, array $params ): WP_REST_Response {
		$name      = sanitize_key( (string) ( $params['name'] ?? '' ) );
		$arguments = is_array( $params['arguments'] ?? null ) ? $params['arguments'] : [];

		if ( 'list_fleet' === $name ) {
			return self::tool_result( $id, self::fleet_data() );
		}

		if ( 'get_booking_policy' === $name ) {
			return self::tool_result( $id, self::policy_data() );
		}

		if ( 'get_rental_quote' === $name ) {
			$data = self::quote_data( $arguments );
			if ( is_wp_error( $data ) ) {
				return self::tool_error( $id, $data->get_error_message() );
			}
			return self::tool_result( $id, $data );
		}

		return self::error( $id, -32602, 'Unknown tool name.', 400 );
	}

	/** Shared read-only fleet result for MCP and A2A transports. */
	public static function fleet_data(): array {
		$cars = [];
		foreach ( get_posts( [ 'post_type' => 'car', 'post_status' => 'publish', 'posts_per_page' => -1, 'orderby' => 'menu_order', 'order' => 'ASC' ] ) as $car ) {
			$item = [
				'id'           => $car->ID,
				'name'         => $car->post_title,
				'url'          => get_permalink( $car ),
				'year'         => (int) get_post_meta( $car->ID, 'glc_year', true ),
				'seats'        => (int) get_post_meta( $car->ID, 'glc_seats', true ),
				'transmission' => (string) get_post_meta( $car->ID, 'glc_transmission', true ),
				'drivetrain'   => (string) get_post_meta( $car->ID, 'glc_drivetrain', true ),
			];
			[ $low, $high ] = GLC_Pricing::rate_range( $car->ID );
			if ( $low > 0 && $high > 0 ) {
				$item['published_daily_rate_usd'] = [ 'low' => $low, 'high' => $high ];
			}
			$cars[] = array_filter( $item, static fn( $value ) => '' !== $value && 0 !== $value );
		}
		return [ 'cars' => $cars, 'count' => count( $cars ) ];
	}

	/** Shared owner-verified policy result for MCP and A2A transports. */
	public static function policy_data(): array {
		return [
			'security_deposit' => 'None; no card preauthorization hold.',
			'insurance'        => 'Full insurance included with no deductible. Wheels and windshield are covered; tyres and the interior are excluded. Single-vehicle accidents are covered. Third-party liability limit: 30,000 GEL.',
			'mileage'          => 'Unlimited within Georgia.',
			'winter_tyres'     => 'Included free in winter.',
			'route_policy'     => 'All vehicles and routes unless bad weather, an official road closure, or damaged-road conditions apply. Current safety and opening status must be checked before departure.',
			'cross_border'     => 'Armenia is allowed; confirm trip documents and insurance before departure.',
			'handover'         => 'Tbilisi office and Tbilisi Airport are free. Kutaisi and Batumi charges must be calculated by the dated quote.',
			'prepayment'       => '10% confirms the booking after availability is checked.',
			'cancellation'     => 'At least 30 days before pickup, 50% of the prepayment is refunded. With fewer than 30 days remaining, the prepayment is non-refundable.',
			'confirmation'     => 'A quote or booking request is not a confirmed reservation. Geolander staff confirm availability and payment instructions.',
		];
	}

	/** Shared dated quote result; never creates a booking or claims availability. */
	public static function quote_data( array $arguments ) {
		$wp_request = new WP_REST_Request( 'GET', '/geolander-agent/v1/quote' );
		foreach ( [ 'car', 'from', 'to', 'pickup', 'return' ] as $field ) {
			if ( array_key_exists( $field, $arguments ) ) {
				$wp_request->set_param( $field, $arguments[ $field ] );
			}
		}
		$response = GLC_Booking::quote( $wp_request );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$data = $response instanceof WP_REST_Response ? $response->get_data() : $response;
		if ( ! is_array( $data ) ) {
			return new WP_Error( 'glc_agent_quote', 'The quote service returned an invalid response.' );
		}
		$data['availability_status'] = 'not_confirmed';
		$data['reservation_status']  = 'not_created';
		return $data;
	}

	private static function tool_result( $id, array $data ): WP_REST_Response {
		return self::result(
			$id,
			[
				'resultType'        => 'complete',
				'content'           => [ [ 'type' => 'text', 'text' => wp_json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ] ],
				'structuredContent' => $data,
				'isError'           => false,
				'_meta'             => self::server_meta(),
			]
		);
	}

	private static function tool_error( $id, string $message ): WP_REST_Response {
		return self::result(
			$id,
			[
				'resultType' => 'complete',
				'content'    => [ [ 'type' => 'text', 'text' => $message ] ],
				'isError'    => true,
				'_meta'      => self::server_meta(),
			]
		);
	}

	private static function server_meta(): array {
		return [ 'io.modelcontextprotocol/serverInfo' => [ 'name' => 'com.geo-lander/reservation', 'title' => 'Geolander Rental Tools', 'version' => '1.0.0' ] ];
	}

	private static function result( $id, array $result ): WP_REST_Response {
		return new WP_REST_Response( [ 'jsonrpc' => '2.0', 'id' => $id, 'result' => $result ], 200 );
	}

	private static function error( $id, int $code, string $message, int $status, array $data = [] ): WP_REST_Response {
		$error = [ 'code' => $code, 'message' => $message ];
		if ( $data ) {
			$error['data'] = $data;
		}
		return new WP_REST_Response( [ 'jsonrpc' => '2.0', 'id' => $id, 'error' => $error ], $status );
	}
}
