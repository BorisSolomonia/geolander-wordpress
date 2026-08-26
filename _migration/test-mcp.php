<?php
/**
 * Exercise the stateless MCP dispatcher without bypassing route authorization.
 * Run: wp eval-file /migration/test-mcp.php
 */

defined( 'ABSPATH' ) || exit;

function glc_mcp_request( string $method, array $params = [], ?string $name = null ): array {
	$request = new WP_REST_Request( 'POST', '/geolander-agent/v1/mcp' );
	$request->set_header( 'content-type', 'application/json' );
	$request->set_header( 'mcp-protocol-version', '2026-07-28' );
	$request->set_header( 'mcp-method', $method );
	if ( null !== $name ) {
		$request->set_header( 'mcp-name', $name );
	}
	$params['_meta'] = [
		'io.modelcontextprotocol/protocolVersion' => '2026-07-28',
		'io.modelcontextprotocol/clientInfo'       => [ 'name' => 'geolander-test', 'version' => '1.0.0' ],
		'io.modelcontextprotocol/clientCapabilities' => (object) [],
	];
	$request->set_body( wp_json_encode( [ 'jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => $params ] ) );
	$response = GLC_MCP::handle( $request );
	if ( ! $response instanceof WP_REST_Response ) {
		throw new RuntimeException( 'MCP handler did not return WP_REST_Response.' );
	}
	return $response->get_data();
}

$discover = glc_mcp_request( 'server/discover' );
if ( ! in_array( '2026-07-28', $discover['result']['supportedVersions'] ?? [], true ) ) {
	throw new RuntimeException( 'Modern MCP discovery version missing.' );
}

$listed = glc_mcp_request( 'tools/list' );
$names  = wp_list_pluck( $listed['result']['tools'] ?? [], 'name' );
foreach ( [ 'list_fleet', 'get_booking_policy', 'get_rental_quote' ] as $name ) {
	if ( ! in_array( $name, $names, true ) ) {
		throw new RuntimeException( "Missing MCP tool: {$name}" );
	}
}

$policy = glc_mcp_request( 'tools/call', [ 'name' => 'get_booking_policy', 'arguments' => (object) [] ], 'get_booking_policy' );
if ( ! empty( $policy['result']['isError'] ) || 'None; no card preauthorization hold.' !== ( $policy['result']['structuredContent']['security_deposit'] ?? '' ) ) {
	throw new RuntimeException( 'MCP policy tool returned incorrect data.' );
}

$fleet = glc_mcp_request( 'tools/call', [ 'name' => 'list_fleet', 'arguments' => (object) [] ], 'list_fleet' );
if ( empty( $fleet['result']['structuredContent']['cars'] ) ) {
	throw new RuntimeException( 'MCP fleet tool returned no cars.' );
}
foreach ( $fleet['result']['structuredContent']['cars'] as $car ) {
	if ( isset( $car['published_daily_rate_usd'] ) && ( $car['published_daily_rate_usd']['low'] <= 0 || $car['published_daily_rate_usd']['high'] <= 0 ) ) {
		throw new RuntimeException( 'MCP fleet tool published a zero price.' );
	}
}

WP_CLI::success( 'MCP 2026-07-28 discovery, tools/list, policy, fleet, and zero-price guard passed.' );
