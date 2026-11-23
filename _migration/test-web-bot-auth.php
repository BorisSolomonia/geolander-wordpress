<?php
/**
 * Verify the Web Bot Auth directory and outbound RFC 9421 signatures.
 * Run: wp eval-file /migration/test-web-bot-auth.php
 */

defined( 'ABSPATH' ) || exit;

function glc_wba_b64url_decode( string $value ): string {
	$padding = ( 4 - strlen( $value ) % 4 ) % 4;
	$result  = base64_decode( strtr( $value, '-_', '+/' ) . str_repeat( '=', $padding ), true );
	if ( ! is_string( $result ) ) {
		throw new RuntimeException( 'Invalid base64url value.' );
	}
	return $result;
}

function glc_wba_signature_bytes( string $header, string $label ): string {
	if ( ! preg_match( '/^' . preg_quote( $label, '/' ) . '=\:([^:]+)\:$/', $header, $matches ) ) {
		throw new RuntimeException( "Malformed {$label} Signature header." );
	}
	$signature = base64_decode( $matches[1], true );
	if ( ! is_string( $signature ) || SODIUM_CRYPTO_SIGN_BYTES !== strlen( $signature ) ) {
		throw new RuntimeException( "Invalid {$label} Ed25519 signature." );
	}
	return $signature;
}

$original_key = getenv( 'GLC_WEB_BOT_AUTH_PRIVATE_KEY' );
$keypair      = sodium_crypto_sign_keypair();
$secret       = sodium_crypto_sign_secretkey( $keypair );
putenv( 'GLC_WEB_BOT_AUTH_PRIVATE_KEY=' . base64_encode( $secret ) );

$created = 1760000000;
$bundle  = GLC_Web_Bot_Auth::directory_bundle( $created );
if ( is_wp_error( $bundle ) ) {
	throw new RuntimeException( $bundle->get_error_message() );
}
$directory = json_decode( $bundle['body'], true, 16, JSON_THROW_ON_ERROR );
$jwk       = $directory['keys'][0] ?? [];
if ( 'OKP' !== ( $jwk['kty'] ?? '' ) || 'Ed25519' !== ( $jwk['crv'] ?? '' ) || empty( $jwk['x'] ) || empty( $jwk['kid'] ) ) {
	throw new RuntimeException( 'The Web Bot Auth directory does not contain a usable Ed25519 public JWK.' );
}
if ( isset( $jwk['d'] ) ) {
	throw new RuntimeException( 'The Web Bot Auth directory exposed private key material.' );
}
$thumbprint = rtrim(
	strtr(
		base64_encode( hash( 'sha256', '{"crv":"Ed25519","kty":"OKP","x":"' . $jwk['x'] . '"}', true ) ),
		'+/',
		'-_'
	),
	'='
);
if ( $thumbprint !== $jwk['kid'] ) {
	throw new RuntimeException( 'The Web Bot Auth JWK thumbprint does not match kid.' );
}

$digest = 'sha-256=:' . base64_encode( hash( 'sha256', $bundle['body'], true ) ) . ':';
if ( $digest !== ( $bundle['headers']['Content-Digest'] ?? '' ) ) {
	throw new RuntimeException( 'The Web Bot Auth directory Content-Digest is invalid.' );
}
$directory_params = substr( $bundle['headers']['Signature-Input'], strlen( 'binding0=' ) );
$site              = wp_parse_url( home_url( '/' ) );
$authority         = strtolower( (string) $site['host'] );
if ( ! empty( $site['port'] ) ) {
	$authority .= ':' . (int) $site['port'];
}
$directory_base = '"@authority";req: ' . $authority . "\n"
	. '"content-digest": ' . $digest . "\n"
	. '"@signature-params": ' . $directory_params;
$public = glc_wba_b64url_decode( $jwk['x'] );
if ( ! sodium_crypto_sign_verify_detached( glc_wba_signature_bytes( $bundle['headers']['Signature'], 'binding0' ), $directory_base, $public ) ) {
	throw new RuntimeException( 'The Web Bot Auth directory self-signature is invalid.' );
}

$target          = 'https://receiver.example:8443/resource';
$request_headers = GLC_Web_Bot_Auth::request_signature_headers( $target, $created );
if ( is_wp_error( $request_headers ) ) {
	throw new RuntimeException( $request_headers->get_error_message() );
}
$signature_agent = '"' . home_url( GLC_Web_Bot_Auth::DIRECTORY_PATH ) . '"';
if ( $signature_agent !== ( $request_headers['Signature-Agent'] ?? '' ) ) {
	throw new RuntimeException( 'Signature-Agent does not identify the public key directory.' );
}
$request_params = substr( $request_headers['Signature-Input'], strlen( 'sig1=' ) );
$request_base   = '"@authority": receiver.example:8443' . "\n"
	. '"signature-agent": ' . $signature_agent . "\n"
	. '"@signature-params": ' . $request_params;
if ( ! sodium_crypto_sign_verify_detached( glc_wba_signature_bytes( $request_headers['Signature'], 'sig1' ), $request_base, $public ) ) {
	throw new RuntimeException( 'The outbound Web Bot Auth request signature is invalid.' );
}
foreach ( [ 'signature-agent', 'created=', 'keyid=', 'alg="ed25519"', 'expires=', 'nonce=', 'tag="web-bot-auth"' ] as $marker ) {
	if ( ! str_contains( $request_headers['Signature-Input'], $marker ) ) {
		throw new RuntimeException( "Signature-Input is missing {$marker}." );
	}
}

$signed_args = GLC_Web_Bot_Auth::sign_request_args(
	[
		'glc_web_bot_auth' => true,
		'headers'          => [ 'signature' => 'forged' ],
	],
	$target
);
if ( 'forged' === ( $signed_args['headers']['signature'] ?? '' ) || empty( $signed_args['headers']['Signature-Agent'] ) || empty( $signed_args['headers']['Signature-Input'] ) || empty( $signed_args['headers']['Signature'] ) ) {
	throw new RuntimeException( 'The WordPress HTTP opt-in did not replace untrusted signature headers.' );
}
if ( false !== GLC_Web_Bot_Auth::reject_unsigned_opt_in_request( false, $signed_args, $target ) ) {
	throw new RuntimeException( 'A correctly signed opt-in request was rejected.' );
}
$plain_args = [ 'headers' => [ 'User-Agent' => 'WordPress' ] ];
if ( $plain_args !== GLC_Web_Bot_Auth::sign_request_args( $plain_args, $target ) ) {
	throw new RuntimeException( 'Ordinary WordPress HTTP traffic was unexpectedly signed.' );
}
if ( ! is_wp_error( GLC_Web_Bot_Auth::request_signature_headers( 'http://receiver.example/' ) ) ) {
	throw new RuntimeException( 'Web Bot Auth signed an insecure HTTP URL.' );
}

if ( false === $original_key ) {
	putenv( 'GLC_WEB_BOT_AUTH_PRIVATE_KEY' );
} else {
	putenv( 'GLC_WEB_BOT_AUTH_PRIVATE_KEY=' . $original_key );
}

WP_CLI::success( 'Web Bot Auth JWKS, thumbprint, content digest, directory signature, outbound headers, and opt-in guards passed.' );
