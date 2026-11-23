<?php
/**
 * Exercise the A2A v1.0 dispatcher without bypassing route authorization.
 * Run: wp eval-file /migration/test-a2a.php
 */

defined( 'ABSPATH' ) || exit;

function glc_a2a_request( string $skill, array $arguments = [], string $version = '1.0' ): WP_REST_Response {
	$request = new WP_REST_Request( 'POST', '/geolander-agent/v1/a2a' );
	$request->set_header( 'content-type', 'application/json' );
	$request->set_header( 'a2a-version', $version );
	$request->set_body(
		wp_json_encode(
			[
				'jsonrpc' => '2.0',
				'id'      => 'geolander-a2a-test',
				'method'  => 'SendMessage',
				'params'  => [
					'message' => [
						'messageId' => wp_generate_uuid4(),
						'role'      => 'ROLE_USER',
						'parts'     => [
							[
								'data'      => [ 'skill' => $skill ] + $arguments,
								'mediaType' => 'application/json',
							],
						],
					],
				],
			]
		)
	);
	$response = GLC_A2A::handle( $request );
	if ( ! $response instanceof WP_REST_Response ) {
		throw new RuntimeException( 'A2A handler did not return WP_REST_Response.' );
	}
	return $response;
}

function glc_a2a_data( WP_REST_Response $response ): array {
	$body    = $response->get_data();
	$message = $body['result']['message'] ?? [];
	if ( 200 !== $response->get_status() || 'ROLE_AGENT' !== ( $message['role'] ?? '' ) || empty( $message['messageId'] ) || empty( $message['contextId'] ) ) {
		throw new RuntimeException( 'A2A response is not a valid immediate agent Message.' );
	}
	foreach ( $message['parts'] ?? [] as $part ) {
		if ( isset( $part['data'] ) && is_array( $part['data'] ) ) {
			return $part['data'];
		}
	}
	throw new RuntimeException( 'A2A response is missing its structured data part.' );
}

$policy = glc_a2a_data( glc_a2a_request( 'rental-policy' ) );
if ( 'None; no card preauthorization hold.' !== ( $policy['security_deposit'] ?? '' ) ) {
	throw new RuntimeException( 'A2A policy skill returned incorrect data.' );
}

$fleet = glc_a2a_data( glc_a2a_request( 'fleet-discovery' ) );
if ( empty( $fleet['cars'] ) ) {
	throw new RuntimeException( 'A2A fleet skill returned no published cars.' );
}
$priced_car = null;
foreach ( $fleet['cars'] as $car ) {
	if ( isset( $car['published_daily_rate_usd'] ) ) {
		if ( $car['published_daily_rate_usd']['low'] <= 0 || $car['published_daily_rate_usd']['high'] <= 0 ) {
			throw new RuntimeException( 'A2A fleet skill published a zero price.' );
		}
		$priced_car ??= $car;
	}
}
if ( ! $priced_car ) {
	throw new RuntimeException( 'A2A quote test needs at least one published priced car.' );
}

$from  = current_datetime()->modify( '+60 days' )->format( 'Y-m-d' );
$to    = current_datetime()->modify( '+67 days' )->format( 'Y-m-d' );
$quote = glc_a2a_data(
	glc_a2a_request(
		'rental-quote',
		[
			'car'    => $priced_car['id'],
			'from'   => $from,
			'to'     => $to,
			'pickup' => 'tbilisi_office',
			'return' => 'tbilisi_office',
		]
	)
);
if ( 'not_confirmed' !== ( $quote['availability_status'] ?? '' ) || 'not_created' !== ( $quote['reservation_status'] ?? '' ) ) {
	throw new RuntimeException( 'A2A quote skill overstated availability or reservation status.' );
}

$unsupported = glc_a2a_request( 'fleet-discovery', [], '0.3' );
if ( 400 !== $unsupported->get_status() || -32009 !== ( $unsupported->get_data()['error']['code'] ?? null ) ) {
	throw new RuntimeException( 'A2A unsupported-version guard failed.' );
}

$unsupported_operation_request = new WP_REST_Request( 'POST', '/geolander-agent/v1/a2a' );
$unsupported_operation_request->set_header( 'content-type', 'application/json' );
$unsupported_operation_request->set_header( 'a2a-version', '1.0' );
$unsupported_operation_request->set_body( wp_json_encode( [ 'jsonrpc' => '2.0', 'id' => 2, 'method' => 'GetExtendedAgentCard' ] ) );
$unsupported_operation = GLC_A2A::handle( $unsupported_operation_request );
if ( 400 !== $unsupported_operation->get_status() || -32004 !== ( $unsupported_operation->get_data()['error']['code'] ?? null ) ) {
	throw new RuntimeException( 'A2A unsupported-operation guard failed.' );
}

$unsupported_content_request = new WP_REST_Request( 'POST', '/geolander-agent/v1/a2a' );
$unsupported_content_request->set_header( 'content-type', 'text/plain' );
$unsupported_content_request->set_header( 'a2a-version', '1.0' );
$unsupported_content_request->set_body( '{}' );
$unsupported_content = GLC_A2A::handle( $unsupported_content_request );
if ( 400 !== $unsupported_content->get_status() || -32005 !== ( $unsupported_content->get_data()['error']['code'] ?? null ) ) {
	throw new RuntimeException( 'A2A content-type guard failed.' );
}

WP_CLI::success( 'A2A v1.0 policy, fleet, quote, message envelope, zero-price, version, operation, and content-type guards passed.' );
