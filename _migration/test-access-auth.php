<?php
/** Cloudflare Access JWT and OAuth metadata unit harness. Run via wp eval-file. */

if ( ! defined( 'ABSPATH' ) ) {
	exit( "Run via wp eval-file\n" );
}

$original_team = getenv( 'GLC_CF_ACCESS_TEAM_DOMAIN' );
$original_aud  = getenv( 'GLC_CF_ACCESS_AUD' );
$test_team     = 'geolander-test.cloudflareaccess.com';
$test_aud      = str_repeat( 'a', 64 );
$clock         = 1_800_000_000;

putenv( 'GLC_CF_ACCESS_TEAM_DOMAIN=' . $test_team );
putenv( 'GLC_CF_ACCESS_AUD=' . $test_aud );

$base64url = static fn( string $value ): string => rtrim( strtr( base64_encode( $value ), '+/', '-_' ), '=' );
$key       = openssl_pkey_new( [ 'private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA ] );
if ( false === $key ) {
	throw new RuntimeException( 'Could not create RSA test key.' );
}
$details = openssl_pkey_get_details( $key );
$certs   = [ [ 'kid' => 'test-key', 'cert' => $details['key'] ] ];

$token = static function ( array $payload ) use ( $base64url, $key ): string {
	$header  = $base64url( wp_json_encode( [ 'alg' => 'RS256', 'kid' => 'test-key', 'typ' => 'JWT' ] ) );
	$claims  = $base64url( wp_json_encode( $payload ) );
	$input   = $header . '.' . $claims;
	$success = openssl_sign( $input, $signature, $key, OPENSSL_ALGO_SHA256 );
	if ( ! $success ) {
		throw new RuntimeException( 'Could not sign test JWT.' );
	}
	return $input . '.' . $base64url( $signature );
};

$claims = [
	'iss' => 'https://' . $test_team,
	'aud' => [ $test_aud ],
	'iat' => $clock - 30,
	'nbf' => $clock - 30,
	'exp' => $clock + 300,
];

$cases = [
	'valid assertion'   => [ $token( $claims ), true ],
	'expired assertion' => [ $token( array_merge( $claims, [ 'exp' => $clock - 61 ] ) ), false ],
	'wrong issuer'      => [ $token( array_merge( $claims, [ 'iss' => 'https://wrong.cloudflareaccess.com' ] ) ), false ],
	'wrong audience'    => [ $token( array_merge( $claims, [ 'aud' => [ str_repeat( 'b', 64 ) ] ] ) ), false ],
];

try {
	foreach ( $cases as $label => [ $assertion, $expected ] ) {
		$actual = GLC_Access::verify_assertion( $assertion, $certs, $clock );
		if ( $actual !== $expected ) {
			throw new RuntimeException( sprintf( '%s: expected %s, got %s', $label, var_export( $expected, true ), var_export( $actual, true ) ) );
		}
		WP_CLI::log( sprintf( '  ✓ %s', $label ) );
	}

	$valid    = $cases['valid assertion'][0];
	$tampered = substr( $valid, 0, -1 ) . ( str_ends_with( $valid, 'A' ) ? 'B' : 'A' );
	if ( GLC_Access::verify_assertion( $tampered, $certs, $clock ) ) {
		throw new RuntimeException( 'Tampered assertion passed signature validation.' );
	}
	WP_CLI::log( '  ✓ tampered assertion rejected' );

	$metadata = GLC_AI::oauth_metadata();
	foreach ( [ 'issuer', 'authorization_endpoint', 'token_endpoint', 'jwks_uri' ] as $field ) {
		if ( empty( $metadata[ $field ] ) || ! str_starts_with( $metadata[ $field ], 'https://' ) ) {
			throw new RuntimeException( 'OAuth metadata missing HTTPS ' . $field );
		}
	}
	foreach ( [ 'grant_types_supported', 'response_types_supported' ] as $field ) {
		if ( empty( $metadata[ $field ] ) || ! is_array( $metadata[ $field ] ) ) {
			throw new RuntimeException( 'OAuth metadata missing ' . $field );
		}
	}
	WP_CLI::log( '  ✓ RFC 8414 metadata fields present' );

	WP_CLI::success( 'Cloudflare Access authentication cases passed.' );
} finally {
	false === $original_team ? putenv( 'GLC_CF_ACCESS_TEAM_DOMAIN' ) : putenv( 'GLC_CF_ACCESS_TEAM_DOMAIN=' . $original_team );
	false === $original_aud ? putenv( 'GLC_CF_ACCESS_AUD' ) : putenv( 'GLC_CF_ACCESS_AUD=' . $original_aud );
}
