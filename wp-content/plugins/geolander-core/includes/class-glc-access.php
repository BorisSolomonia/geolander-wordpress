<?php
/**
 * Cloudflare Access authentication for the dedicated agent REST API.
 *
 * Access blocks unauthenticated requests at the edge. The origin still
 * verifies the signed application JWT so a direct request to the Railway
 * origin cannot authenticate by merely spoofing the header.
 */

defined( 'ABSPATH' ) || exit;

class GLC_Access {

	private const CERT_CACHE_SECONDS = 3600;
	private const CLOCK_SKEW_SECONDS = 60;

	/** Return the configured Cloudflare Access team hostname, without a scheme. */
	public static function team_domain(): string {
		$domain = strtolower( trim( (string) getenv( 'GLC_CF_ACCESS_TEAM_DOMAIN' ) ) );
		return preg_match( '/^[a-z0-9-]+\.cloudflareaccess\.com$/', $domain ) ? $domain : '';
	}

	/** Return the immutable Application Audience (AUD) tag. */
	public static function audience(): string {
		$audience = trim( (string) getenv( 'GLC_CF_ACCESS_AUD' ) );
		return preg_match( '/^[a-f0-9]{64}$/i', $audience ) ? strtolower( $audience ) : '';
	}

	public static function issuer(): string {
		$domain = self::team_domain();
		return $domain ? 'https://' . $domain : '';
	}

	/** WordPress REST permission callback for Cloudflare-protected agent routes. */
	public static function authorize_agent_request() {
		$assertion = trim( (string) ( $_SERVER['HTTP_CF_ACCESS_JWT_ASSERTION'] ?? '' ) );
		if ( self::verify_assertion( $assertion ) ) {
			return true;
		}

		return new WP_Error(
			'glc_agent_auth_required',
			__( 'A valid Cloudflare Access token is required.', 'geolander' ),
			[ 'status' => 401 ]
		);
	}

	/**
	 * Validate signature, issuer, audience, and time claims on an Access JWT.
	 *
	 * Passing certificates and a clock makes this method deterministic in the
	 * unit harness. Production always fetches Cloudflare's rotating key set.
	 */
	public static function verify_assertion( string $assertion, ?array $certificates = null, ?int $now = null ): bool {
		if ( '' === $assertion || '' === self::issuer() || '' === self::audience() ) {
			return false;
		}

		$parts = explode( '.', $assertion );
		if ( 3 !== count( $parts ) ) {
			return false;
		}

		$header_json  = self::base64url_decode( $parts[0] );
		$payload_json = self::base64url_decode( $parts[1] );
		$signature    = self::base64url_decode( $parts[2] );
		if ( false === $header_json || false === $payload_json || false === $signature ) {
			return false;
		}

		$header  = json_decode( $header_json, true );
		$payload = json_decode( $payload_json, true );
		if ( ! is_array( $header ) || ! is_array( $payload ) || 'RS256' !== ( $header['alg'] ?? '' ) || empty( $header['kid'] ) ) {
			return false;
		}

		$certificates ??= self::certificates();
		$certificate = '';
		foreach ( $certificates as $candidate ) {
			if ( is_array( $candidate ) && hash_equals( (string) $header['kid'], (string) ( $candidate['kid'] ?? '' ) ) ) {
				$certificate = (string) ( $candidate['cert'] ?? '' );
				break;
			}
		}
		if ( '' === $certificate ) {
			return false;
		}

		$key = openssl_pkey_get_public( $certificate );
		if ( false === $key || 1 !== openssl_verify( $parts[0] . '.' . $parts[1], $signature, $key, OPENSSL_ALGO_SHA256 ) ) {
			return false;
		}

		$clock = $now ?? time();
		if ( ! isset( $payload['exp'] ) || ! is_numeric( $payload['exp'] ) || (int) $payload['exp'] + self::CLOCK_SKEW_SECONDS < $clock ) {
			return false;
		}
		if ( isset( $payload['nbf'] ) && ( ! is_numeric( $payload['nbf'] ) || (int) $payload['nbf'] - self::CLOCK_SKEW_SECONDS > $clock ) ) {
			return false;
		}
		if ( isset( $payload['iat'] ) && ( ! is_numeric( $payload['iat'] ) || (int) $payload['iat'] - self::CLOCK_SKEW_SECONDS > $clock ) ) {
			return false;
		}
		if ( ! isset( $payload['iss'] ) || ! hash_equals( self::issuer(), (string) $payload['iss'] ) ) {
			return false;
		}

		$audiences = is_array( $payload['aud'] ?? null ) ? $payload['aud'] : [ $payload['aud'] ?? '' ];
		return in_array( self::audience(), array_map( 'strval', $audiences ), true );
	}

	/** Fetch both current and previous Access signing certificates. */
	private static function certificates(): array {
		$domain = self::team_domain();
		if ( '' === $domain ) {
			return [];
		}

		$cache_key = 'glc_cf_access_certs_' . md5( $domain );
		$cached    = get_transient( $cache_key );
		if ( is_array( $cached ) && $cached ) {
			return $cached;
		}

		$response = wp_remote_get(
			'https://' . $domain . '/cdn-cgi/access/certs',
			[ 'timeout' => 5, 'headers' => [ 'Accept' => 'application/json' ] ]
		);
		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return [];
		}

		$document = json_decode( wp_remote_retrieve_body( $response ), true );
		$certs    = is_array( $document['public_certs'] ?? null ) ? $document['public_certs'] : [];
		$certs    = array_values( array_filter( $certs, static fn( $cert ) => is_array( $cert ) && ! empty( $cert['kid'] ) && ! empty( $cert['cert'] ) ) );
		if ( $certs ) {
			set_transient( $cache_key, $certs, self::CERT_CACHE_SECONDS );
		}
		return $certs;
	}

	private static function base64url_decode( string $value ): string|false {
		$padding = ( 4 - strlen( $value ) % 4 ) % 4;
		return base64_decode( strtr( $value, '-_', '+/' ) . str_repeat( '=', $padding ), true );
	}
}
