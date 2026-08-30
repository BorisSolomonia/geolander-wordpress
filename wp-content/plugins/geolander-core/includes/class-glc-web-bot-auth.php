<?php
/**
 * IETF Web Bot Auth identity for Geolander-operated outbound agent requests.
 *
 * The Ed25519 private key must be supplied through the
 * GLC_WEB_BOT_AUTH_PRIVATE_KEY environment variable as base64. Only the
 * derived public key is exposed through the signed key directory.
 */

defined( 'ABSPATH' ) || exit;

class GLC_Web_Bot_Auth {

	public const DIRECTORY_PATH = '/.well-known/http-message-signatures-directory';
	private const KEY_ENV       = 'GLC_WEB_BOT_AUTH_PRIVATE_KEY';
	private const REQUEST_TAG   = 'web-bot-auth';
	private const DIRECTORY_TAG = 'http-message-signatures-directory';

	public static function init(): void {
		add_filter( 'http_request_args', [ __CLASS__, 'sign_request_args' ], 10, 2 );
		add_filter( 'pre_http_request', [ __CLASS__, 'reject_unsigned_opt_in_request' ], 10, 3 );
	}

	/**
	 * Opt in with `glc_web_bot_auth => true`, or use a Geolander-Agent/* UA.
	 * Payment gateways and ordinary WordPress traffic remain untouched.
	 */
	public static function sign_request_args( array $args, string $url ): array {
		if ( ! self::should_sign( $args ) ) {
			return $args;
		}

		$headers = self::request_signature_headers( $url );
		if ( is_wp_error( $headers ) ) {
			$args['glc_web_bot_auth_error'] = $headers->get_error_message();
			return $args;
		}

		if ( ! is_array( $args['headers'] ?? null ) ) {
			$args['headers'] = [];
		}
		foreach ( array_keys( $args['headers'] ) as $name ) {
			if ( in_array( strtolower( (string) $name ), [ 'signature-agent', 'signature-input', 'signature' ], true ) ) {
				unset( $args['headers'][ $name ] );
			}
		}
		$args['headers'] = array_merge( $args['headers'], $headers );
		if ( '' === self::header_value( $args['headers'], 'user-agent' ) ) {
			$args['headers']['User-Agent'] = 'Geolander-Agent/1.0 (+https://geo-lander.com/.well-known/agent-card.json)';
		}
		return $args;
	}

	/** Never silently downgrade an explicitly signed agent request. */
	public static function reject_unsigned_opt_in_request( $response, array $args, string $url ) {
		if ( false !== $response || ! self::should_sign( $args ) ) {
			return $response;
		}
		if ( ! empty( $args['glc_web_bot_auth_error'] ) ) {
			return new WP_Error( 'glc_web_bot_auth_signing', (string) $args['glc_web_bot_auth_error'], [ 'url' => $url ] );
		}
		if ( '' === self::header_value( is_array( $args['headers'] ?? null ) ? $args['headers'] : [], 'signature' ) ) {
			return new WP_Error( 'glc_web_bot_auth_unsigned', 'The Web Bot Auth request was not signed.', [ 'url' => $url ] );
		}
		return $response;
	}

	/** RFC 9421 request signature headers using the Cloudflare Web Bot Auth profile. */
	public static function request_signature_headers( string $url, ?int $created = null ) {
		$secret = self::private_key();
		if ( is_wp_error( $secret ) ) {
			return $secret;
		}

		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || 'https' !== strtolower( (string) ( $parts['scheme'] ?? '' ) ) || empty( $parts['host'] ) ) {
			return new WP_Error( 'glc_web_bot_auth_url', 'Web Bot Auth signs HTTPS requests only.' );
		}

		$authority = strtolower( (string) $parts['host'] );
		$port      = (int) ( $parts['port'] ?? 0 );
		if ( $port && 443 !== $port ) {
			$authority .= ':' . $port;
		}
		$created         = $created ?? time();
		$expires         = $created + 60;
		$nonce           = base64_encode( random_bytes( 64 ) );
		$key_id          = self::key_id_from_secret( $secret );
		$directory_uri   = home_url( self::DIRECTORY_PATH );
		$signature_agent = '"' . addcslashes( $directory_uri, "\\\"" ) . '"';
		$params           = '("@authority" "signature-agent")'
			. ';created=' . $created
			. ';keyid="' . $key_id . '"'
			. ';alg="ed25519"'
			. ';expires=' . $expires
			. ';nonce="' . $nonce . '"'
			. ';tag="' . self::REQUEST_TAG . '"';
		$base             = '"@authority": ' . $authority . "\n"
			. '"signature-agent": ' . $signature_agent . "\n"
			. '"@signature-params": ' . $params;
		$signature        = base64_encode( sodium_crypto_sign_detached( $base, $secret ) );

		return [
			'Signature-Agent' => $signature_agent,
			'Signature-Input' => 'sig1=' . $params,
			'Signature'       => 'sig1=:' . $signature . ':',
		];
	}

	/** Build the signed JWKS representation and headers for the public directory. */
	public static function directory_bundle( ?int $created = null ) {
		$secret = self::private_key();
		if ( is_wp_error( $secret ) ) {
			return $secret;
		}

		$public = sodium_crypto_sign_publickey_from_secretkey( $secret );
		$x      = self::base64url( $public );
		$key_id = self::jwk_thumbprint( $x );
		$body   = wp_json_encode(
			[
				'keys' => [
					[
						'kty' => 'OKP',
						'crv' => 'Ed25519',
						'x'   => $x,
						'kid' => $key_id,
						'use' => 'sig',
						'alg' => 'EdDSA',
					],
				],
			],
			JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
		);
		if ( ! is_string( $body ) ) {
			return new WP_Error( 'glc_web_bot_auth_json', 'The Web Bot Auth directory could not be encoded.' );
		}

		$created        = $created ?? time();
		$expires        = $created + 60;
		$nonce          = base64_encode( random_bytes( 64 ) );
		$content_digest = 'sha-256=:' . base64_encode( hash( 'sha256', $body, true ) ) . ':';
		$params          = '("@authority";req "content-digest")'
			. ';created=' . $created
			. ';keyid="' . $key_id . '"'
			. ';alg="ed25519"'
			. ';expires=' . $expires
			. ';nonce="' . $nonce . '"'
			. ';tag="' . self::DIRECTORY_TAG . '"';
		$base            = '"@authority";req: ' . self::site_authority() . "\n"
			. '"content-digest": ' . $content_digest . "\n"
			. '"@signature-params": ' . $params;
		$signature       = base64_encode( sodium_crypto_sign_detached( $base, $secret ) );

		return [
			'body'    => $body,
			'headers' => [
				'Content-Type'    => 'application/http-message-signatures-directory+json',
				'Content-Digest'  => $content_digest,
				'Signature-Input' => 'binding0=' . $params,
				'Signature'       => 'binding0=:' . $signature . ':',
			],
		];
	}

	/** Serve a fresh self-signed directory so a CDN never replays expired proof. */
	public static function serve_directory(): void {
		$bundle = self::directory_bundle();
		if ( is_wp_error( $bundle ) ) {
			status_header( 503 );
			header( 'Content-Type: application/json; charset=utf-8' );
			header( 'Cache-Control: no-store, max-age=0' );
			if ( 'HEAD' !== strtoupper( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) ) {
				echo wp_json_encode( [ 'error' => 'web_bot_auth_configuration_unavailable' ] ); // phpcs:ignore WordPress.Security.EscapeOutput
			}
			exit;
		}

		status_header( 200 );
		foreach ( $bundle['headers'] as $name => $value ) {
			header( $name . ': ' . $value );
		}
		header( 'Cache-Control: no-store, max-age=0' );
		header( 'Access-Control-Allow-Origin: *' );
		header( 'Vary: Accept-Encoding', true );
		if ( 'HEAD' !== strtoupper( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) ) {
			echo $bundle['body']; // phpcs:ignore WordPress.Security.EscapeOutput
		}
		exit;
	}

	private static function should_sign( array $args ): bool {
		if ( ! empty( $args['glc_web_bot_auth'] ) ) {
			return true;
		}
		$headers = is_array( $args['headers'] ?? null ) ? $args['headers'] : [];
		return str_starts_with( self::header_value( $headers, 'user-agent' ), 'Geolander-Agent/' );
	}

	private static function header_value( array $headers, string $wanted ): string {
		foreach ( $headers as $name => $value ) {
			if ( strtolower( (string) $name ) === strtolower( $wanted ) ) {
				return trim( is_array( $value ) ? implode( ', ', $value ) : (string) $value );
			}
		}
		return '';
	}

	private static function private_key() {
		if ( ! function_exists( 'sodium_crypto_sign_detached' ) ) {
			return new WP_Error( 'glc_web_bot_auth_sodium', 'The Sodium extension is required for Web Bot Auth.' );
		}
		$encoded = trim( (string) getenv( self::KEY_ENV ) );
		$secret  = '' !== $encoded ? base64_decode( $encoded, true ) : false;
		if ( ! is_string( $secret ) || SODIUM_CRYPTO_SIGN_SECRETKEYBYTES !== strlen( $secret ) ) {
			return new WP_Error( 'glc_web_bot_auth_key', 'The Web Bot Auth signing key is not configured.' );
		}
		return $secret;
	}

	private static function key_id_from_secret( string $secret ): string {
		return self::jwk_thumbprint( self::base64url( sodium_crypto_sign_publickey_from_secretkey( $secret ) ) );
	}

	private static function jwk_thumbprint( string $x ): string {
		return self::base64url( hash( 'sha256', '{"crv":"Ed25519","kty":"OKP","x":"' . $x . '"}', true ) );
	}

	private static function base64url( string $bytes ): string {
		return rtrim( strtr( base64_encode( $bytes ), '+/', '-_' ), '=' );
	}

	private static function site_authority(): string {
		$parts     = wp_parse_url( home_url( '/' ) );
		$authority = strtolower( (string) ( $parts['host'] ?? '' ) );
		$port      = (int) ( $parts['port'] ?? 0 );
		$scheme    = strtolower( (string) ( $parts['scheme'] ?? 'https' ) );
		if ( $port && ! ( 443 === $port && 'https' === $scheme ) && ! ( 80 === $port && 'http' === $scheme ) ) {
			$authority .= ':' . $port;
		}
		return $authority;
	}
}
