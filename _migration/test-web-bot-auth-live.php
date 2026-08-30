<?php
/**
 * Send one real signed request to Cloudflare's Web Bot Auth test endpoint.
 * A 401 means the signature is well-formed but the key is not registered;
 * a 200 means the registered key was verified. A 400 means malformed.
 *
 * Run in production: wp eval-file /migration/test-web-bot-auth-live.php
 */

defined( 'ABSPATH' ) || exit;

if ( 'https' !== wp_parse_url( home_url( '/' ), PHP_URL_SCHEME ) ) {
	throw new RuntimeException( 'The live Web Bot Auth test requires an HTTPS site URL.' );
}

$response = wp_remote_get(
	'https://crawltest.com/cdn-cgi/web-bot-auth',
	[
		'glc_web_bot_auth' => true,
		'timeout'          => 20,
	]
);
if ( is_wp_error( $response ) ) {
	throw new RuntimeException( $response->get_error_message() );
}

$status = wp_remote_retrieve_response_code( $response );
if ( ! in_array( $status, [ 200, 401 ], true ) ) {
	throw new RuntimeException( "Cloudflare rejected the Web Bot Auth request format with HTTP {$status}." );
}

WP_CLI::success(
	200 === $status
		? 'Cloudflare verified the registered Geolander Web Bot Auth signature (HTTP 200).'
		: 'Cloudflare accepted the signed request format; bot key registration remains optional (HTTP 401 unknown key).'
);
