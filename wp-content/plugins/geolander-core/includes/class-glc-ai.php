<?php
/**
 * Machine-readable surfaces for AI systems: /llms.txt, /pricing.md,
 * /agent-instructions.md, /openapi.json, RFC 9727 API catalog, OAuth metadata,
 * and text/markdown content negotiation on canonical public URLs.
 */

defined( 'ABSPATH' ) || exit;

class GLC_AI {

	public static function init() {
		add_action( 'init', [ __CLASS__, 'rewrites' ] );
		add_filter( 'query_vars', fn( $vars ) => array_merge( $vars, [ 'glc_ai_file' ] ) );
		// Core's canonical redirect would rewrite these pseudo-file URLs.
		add_filter( 'redirect_canonical', fn( $redirect ) => get_query_var( 'glc_ai_file' ) ? false : $redirect );
		add_action( 'send_headers', [ __CLASS__, 'vary_accept' ], 20 );
		add_action( 'template_redirect', [ __CLASS__, 'serve' ], 5 );
	}

	public static function rewrites() {
		add_rewrite_rule( '^llms\.txt$', 'index.php?glc_ai_file=llms', 'top' );
		add_rewrite_rule( '^pricing\.md$', 'index.php?glc_ai_file=pricing', 'top' );
		add_rewrite_rule( '^agent-instructions\.md$', 'index.php?glc_ai_file=instructions', 'top' );
		add_rewrite_rule( '^openapi\.json$', 'index.php?glc_ai_file=openapi', 'top' );
		add_rewrite_rule( '^\.well-known/api-catalog/?$', 'index.php?glc_ai_file=api_catalog', 'top' );
		add_rewrite_rule( '^\.well-known/oauth-authorization-server/?$', 'index.php?glc_ai_file=oauth_metadata', 'top' );
	}

	public static function serve() {
		$file = get_query_var( 'glc_ai_file' );
		if ( $file ) {
			self::serve_file( $file );
		}

		if ( ! self::is_document_request() ) {
			return;
		}

		$representation = self::preferred_representation( $_SERVER['HTTP_ACCEPT'] ?? '' );
		if ( null === $representation ) {
			status_header( 406 );
			header( 'Content-Type: text/plain; charset=utf-8' );
			header( 'Vary: Accept, Accept-Encoding', true );
			echo "Not Acceptable. Available representations: text/html, text/markdown.\n"; // phpcs:ignore WordPress.Security.EscapeOutput
			exit;
		}
		if ( 'markdown' !== $representation ) {
			return;
		}

		if ( is_404() ) {
			status_header( 404 );
		}
		header( 'Content-Type: text/markdown; charset=utf-8' );
		header( 'Vary: Accept, Accept-Encoding', true );
		header( 'Cache-Control: public, max-age=300' );
		if ( 'HEAD' !== strtoupper( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) ) {
			echo self::document_markdown(); // phpcs:ignore WordPress.Security.EscapeOutput
		}
		exit;
	}

	/** Add Accept to the cache key for every public document representation. */
	public static function vary_accept(): void {
		if ( self::is_document_request() ) {
			header( 'Vary: Accept, Accept-Encoding', true );
		}
	}

	private static function is_document_request(): bool {
		// wp_is_json_request() also becomes true merely because a canonical page
		// was requested with Accept: application/json. That request belongs in the
		// negotiation path (and receives 406), while actual REST routes do not.
		if ( is_admin() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || is_feed() || is_trackback() || is_robots() ) {
			return false;
		}
		return is_front_page()
			|| is_home()
			|| is_singular()
			|| is_post_type_archive()
			|| is_tax()
			|| is_404();
	}

	/**
	 * RFC-style Accept selection for the representations this site can produce.
	 *
	 * Specific media ranges override wildcards for the same representation, so
	 * an explicit Markdown rejection plus a positive wildcard correctly chooses
	 * HTML. A bare wildcard keeps HTML as the browser-safe default.
	 */
	public static function preferred_representation( string $accept ): ?string {
		$accept = trim( strtolower( $accept ) );
		if ( '' === $accept ) {
			return 'html';
		}

		$ranges = [];
		foreach ( explode( ',', $accept ) as $index => $raw ) {
			$parts = array_map( 'trim', explode( ';', $raw ) );
			$media = array_shift( $parts );
			if ( ! preg_match( '~^[a-z0-9!#$&^_.+-]+/(?:[a-z0-9!#$&^_.+-]+|\*)$|^\*/\*$~', $media ) ) {
				continue;
			}
			$q = 1.0;
			foreach ( $parts as $parameter ) {
				if ( preg_match( '/^q\s*=\s*(0(?:\.\d{0,3})?|1(?:\.0{0,3})?)$/', $parameter, $match ) ) {
					$q = (float) $match[1];
				}
			}
			$ranges[] = [ 'media' => $media, 'q' => $q, 'index' => $index ];
		}

		if ( ! $ranges ) {
			return null;
		}

		$candidates = [];
		foreach ( [ 'markdown' => 'text/markdown', 'html' => 'text/html' ] as $name => $type ) {
			[ $major ] = explode( '/', $type, 2 );
			$best = null;
			foreach ( $ranges as $range ) {
				$specificity = $range['media'] === $type ? 2 : ( $range['media'] === $major . '/*' ? 1 : ( '*/*' === $range['media'] ? 0 : -1 ) );
				if ( $specificity < 0 ) {
					continue;
				}
				if ( null === $best || $specificity > $best['specificity'] || ( $specificity === $best['specificity'] && $range['index'] < $best['index'] ) ) {
					$best = $range + [ 'specificity' => $specificity ];
				}
			}
			if ( $best && $best['q'] > 0 ) {
				$candidates[ $name ] = $best;
			}
		}

		// text/plain alone is a non-Markdown request; keep the normal HTML page.
		if ( ! $candidates && array_filter( $ranges, fn( $range ) => 'text/plain' === $range['media'] && $range['q'] > 0 ) ) {
			return 'html';
		}
		if ( ! $candidates ) {
			return null;
		}
		if ( ! isset( $candidates['markdown'] ) ) {
			return 'html';
		}
		if ( ! isset( $candidates['html'] ) ) {
			return 'markdown';
		}
		if ( $candidates['markdown']['q'] !== $candidates['html']['q'] ) {
			return $candidates['markdown']['q'] > $candidates['html']['q'] ? 'markdown' : 'html';
		}
		if ( $candidates['markdown']['index'] !== $candidates['html']['index'] ) {
			return $candidates['markdown']['index'] < $candidates['html']['index'] ? 'markdown' : 'html';
		}
		return 'html';
	}

	private static function serve_file( string $file ): void {
		$types = [
			'llms'         => 'text/plain; charset=utf-8',
			'pricing'      => 'text/markdown; charset=utf-8',
			'instructions' => 'text/markdown; charset=utf-8',
			'openapi'      => 'application/json; charset=utf-8',
			'api_catalog'  => 'application/linkset+json; profile="https://www.rfc-editor.org/info/rfc9727"',
			'oauth_metadata' => 'application/json; charset=utf-8',
		];
		if ( ! isset( $types[ $file ] ) ) {
			status_header( 404 );
			exit;
		}
		$oauth_metadata = 'oauth_metadata' === $file ? self::oauth_metadata() : null;
		header( 'Content-Type: ' . $types[ $file ] );
		if ( 'oauth_metadata' === $file && ! $oauth_metadata ) {
			status_header( 503 );
			header( 'Cache-Control: no-store' );
			if ( 'HEAD' !== strtoupper( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) ) {
				echo wp_json_encode( [ 'error' => 'oauth_configuration_unavailable' ] ); // phpcs:ignore WordPress.Security.EscapeOutput
			}
			exit;
		}
		header( 'Cache-Control: public, max-age=' . ( 'oauth_metadata' === $file ? '300' : '3600' ) );
		if ( 'api_catalog' === $file ) {
			header( 'Link: <' . home_url( '/.well-known/api-catalog' ) . '>; rel="api-catalog"', false );
		}
		if ( 'HEAD' !== strtoupper( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) ) {
			if ( 'llms' === $file ) {
				echo self::llms(); // phpcs:ignore WordPress.Security.EscapeOutput
			} elseif ( 'pricing' === $file ) {
				echo self::pricing(); // phpcs:ignore WordPress.Security.EscapeOutput
			} elseif ( 'instructions' === $file ) {
				echo self::agent_instructions(); // phpcs:ignore WordPress.Security.EscapeOutput
			} elseif ( 'openapi' === $file ) {
				echo wp_json_encode( self::openapi(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT ); // phpcs:ignore WordPress.Security.EscapeOutput
			} elseif ( 'oauth_metadata' === $file ) {
				echo wp_json_encode( $oauth_metadata, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT ); // phpcs:ignore WordPress.Security.EscapeOutput
			} else {
				echo wp_json_encode( self::api_catalog(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT ); // phpcs:ignore WordPress.Security.EscapeOutput
			}
		}
		exit;
	}

	private static function document_markdown(): string {
		$home = home_url( '/' );
		if ( is_404() ) {
			return "# 404 — Page not found\n\n"
				. "The requested Geolander page does not exist. Use one of these verified navigation resources:\n\n"
				. "- [Geolander home]({$home})\n"
				. "- [Rental fleet]({$home}fleet/)\n"
				. "- [Agent guide]({$home}llms.txt)\n"
				. "- [XML sitemap]({$home}wp-sitemap.xml)\n"
				. "- [Contact Geolander]({$home}contact/)\n";
		}
		if ( is_front_page() || is_home() ) {
			return self::llms();
		}
		if ( is_post_type_archive( 'car' ) ) {
			return self::pricing();
		}
		if ( is_post_type_archive( 'place' ) ) {
			$out = "# Places to visit in Georgia by car\n\n";
			foreach ( get_posts( [ 'post_type' => 'place', 'posts_per_page' => -1, 'orderby' => 'menu_order', 'order' => 'ASC' ] ) as $place ) {
				$out .= sprintf( "- [%s](%s)\n", GLC_Content::title( $place ), get_permalink( $place ) );
			}
			return $out;
		}
		if ( is_singular( 'car' ) ) {
			return self::car_markdown( get_the_ID() );
		}
		if ( is_singular() ) {
			$post = get_queried_object();
			$body = $post instanceof WP_Post ? self::html_to_markdown( GLC_Content::body( $post ) ) : '';
			return sprintf(
				"# %s\n\n%s%s\n\nCanonical URL: %s\n",
				$post instanceof WP_Post ? GLC_Content::title( $post ) : wp_get_document_title(),
				$body,
				$body ? "\n" : '',
				$post instanceof WP_Post ? get_permalink( $post ) : home_url( add_query_arg( [], $GLOBALS['wp']->request ?? '' ) )
			);
		}
		return "# Geolander car rental\n\n[Open the agent guide]({$home}llms.txt) or [browse the fleet]({$home}fleet/).\n";
	}

	private static function car_markdown( int $post_id ): string {
		[ $low, $high ] = GLC_Pricing::rate_range( $post_id );
		$out  = '# ' . get_the_title( $post_id ) . " — exact-car rental in Tbilisi, Georgia\n\n";
		$body = self::html_to_markdown( GLC_Content::body( $post_id ) );
		if ( $body ) {
			$out .= $body . "\n\n";
		}
		$out .= "## Vehicle facts\n\n";
		foreach ( [
			'Year'         => get_post_meta( $post_id, 'glc_year', true ),
			'Seats'        => get_post_meta( $post_id, 'glc_seats', true ),
			'Transmission' => get_post_meta( $post_id, 'glc_transmission', true ),
			'Drive system' => get_post_meta( $post_id, 'glc_drivetrain', true ),
			'Fuel'         => get_post_meta( $post_id, 'glc_fuel_type', true ),
		] as $label => $value ) {
			if ( '' !== (string) $value ) {
				$out .= sprintf( "- %s: %s\n", $label, $value );
			}
		}
		if ( $low > 0 && $high > 0 ) {
			$out .= sprintf( "- Published daily-rate range: $%d–$%d USD; exact price depends on dates and rental length\n", $low, $high );
		}
		$out .= "- Insurance: included, no deductible; tyres are excluded\n";
		$out .= "- Security deposit: none\n";
		$out .= "- Mileage: unlimited within Georgia\n";
		$out .= "- Winter tyres: included free in winter\n\n";
		$out .= sprintf( "[Check exact dates, location fees, and availability](%s)\n", get_permalink( $post_id ) );
		return $out;
	}

	/** Convert stored WordPress block HTML into compact, readable Markdown. */
	private static function html_to_markdown( string $html ): string {
		$html = preg_replace( '/<!--\s*\/?wp:[\s\S]*?-->/', '', $html );
		$html = preg_replace_callback(
			'#<a\b[^>]*href=["\']([^"\']+)["\'][^>]*>([\s\S]*?)</a>#i',
			static fn( $match ) => '[' . trim( wp_strip_all_tags( $match[2] ) ) . '](' . esc_url_raw( html_entity_decode( $match[1], ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) . ')',
			$html
		);
		for ( $level = 6; $level >= 1; $level-- ) {
			$html = preg_replace( '#<h' . $level . '\b[^>]*>([\s\S]*?)</h' . $level . '>#i', "\n\n" . str_repeat( '#', $level ) . " $1\n\n", $html );
		}
		$html = preg_replace( '#<li\b[^>]*>([\s\S]*?)</li>#i', "\n- $1", $html );
		$html = preg_replace( '#<br\s*/?>#i', "\n", $html );
		$html = preg_replace( '#</(?:p|div|section|article|ul|ol|table|tr|figure)>#i', "\n\n", $html );
		$text = html_entity_decode( wp_strip_all_tags( $html ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$text = preg_replace( "/[ \t]+\n/", "\n", $text );
		$text = preg_replace( "/\n{3,}/", "\n\n", $text );
		return trim( $text );
	}

	private static function agent_guidance(): string {
		$home = home_url( '/' );
		return "## When to use Geolander\n\n"
			. "Use Geolander when a traveller needs an exact, individually listed rental car in Tbilisi or elsewhere in Georgia (country), especially an AWD or 4x4 for a Caucasus road trip. It is also appropriate for date-specific quotes, Tbilisi/Kutaisi/Batumi handover pricing, insurance questions, winter tyres, no-deposit rentals, and current route-condition checks.\n\n"
			. "Do not use Geolander as proof that a road is currently open or safe. Weather, official closures, and road damage override general route permission. Do not describe a request as confirmed until Geolander verifies availability and receives the 10% prepayment. Do not claim that every tax or mandatory charge is included; that fact has not been confirmed.\n\n"
			. "## How an agent should use Geolander\n\n"
			. "1. Read the [live fleet and price tables]({$home}pricing.md).\n"
			. "2. Use the read-only quote endpoint in the [OpenAPI specification]({$home}openapi.json) for exact dates and pickup/return locations.\n"
			. "3. Present the exact car, rental subtotal, location charges, final shown total, 10% prepayment, and balance to the traveller.\n"
			. "4. Obtain explicit user approval plus the traveller's name and valid email before calling checkout. Checkout creates a booking request; it does not confirm the reservation.\n"
			. "5. Direct the traveller to the returned WhatsApp URL. Geolander staff confirm availability and payment instructions.\n\n"
			. "Authenticated clients operated by Geolander team members may use the mirrored `/wp-json/geolander-agent/v1/` endpoints. Discover their Cloudflare Managed OAuth authorization server at `/.well-known/oauth-authorization-server`. The public customer endpoints remain available for the website booking widget.\n";
	}

	/** RFC 8414 metadata for the Cloudflare authorization server used by agents. */
	public static function oauth_metadata(): array {
		$issuer = GLC_Access::issuer();
		if ( '' === $issuer ) {
			return [];
		}

		return [
			'issuer'                                => $issuer,
			'authorization_endpoint'                 => $issuer . '/cdn-cgi/access/oauth/authorization',
			'token_endpoint'                         => $issuer . '/cdn-cgi/access/oauth/token',
			'jwks_uri'                               => $issuer . '/cdn-cgi/access/certs',
			'registration_endpoint'                   => $issuer . '/cdn-cgi/access/oauth/registration',
			'revocation_endpoint'                     => $issuer . '/cdn-cgi/access/oauth/revoke',
			'grant_types_supported'                   => [ 'authorization_code', 'refresh_token' ],
			'response_types_supported'                => [ 'code' ],
			'response_modes_supported'                => [ 'query' ],
			'token_endpoint_auth_methods_supported'   => [ 'client_secret_basic', 'client_secret_post', 'none' ],
			'code_challenge_methods_supported'         => [ 'S256' ],
		];
	}

	private static function agent_instructions(): string {
		return "# Geolander agent instructions\n\n"
			. "> Safe usage guidance for AI agents helping travellers rent an exact car in Tbilisi and Georgia (country).\n\n"
			. self::agent_guidance()
			. "\n## Human contact\n\n"
			. '- Email: ' . GLC_Settings::get( 'email' ) . "\n"
			. '- Phone / WhatsApp: ' . GLC_Settings::get( 'phone' ) . "\n"
			. '- Office: ' . GLC_Settings::get( 'address' ) . ', ' . GLC_Settings::get( 'office_district' ) . ', Tbilisi ' . GLC_Settings::get( 'postal_code' ) . ", Georgia\n";
	}

	private static function openapi(): array {
		$base = untrailingslashit( home_url( '/' ) );
		$document = [
			'openapi' => '3.1.0',
			'info'    => [
				'title'       => 'Geolander Reservation API',
				'version'     => '1.1.0',
				'description' => 'Public customer quote and booking-request handoff plus Cloudflare Access authenticated mirrors for Geolander-operated agents. Checkout creates a request but does not confirm availability or payment.',
				'contact'     => [ 'name' => 'Geolander car rental', 'email' => GLC_Settings::get( 'email' ), 'url' => home_url( '/contact/' ) ],
			],
			'servers' => [ [ 'url' => $base, 'description' => 'Geolander production website' ] ],
			'externalDocs' => [ 'description' => 'Geolander developer resources', 'url' => home_url( '/developers/' ) ],
			'paths'   => [
				'/wp-json/geolander/v1/quote' => [
					'get' => [
						'operationId' => 'getRentalQuote',
						'summary'     => 'Get a read-only rental quote',
						'description' => 'Returns a server-priced quote for one published car, date range, pickup location, and return location. This operation does not create a booking.',
						'parameters'  => [
							[ 'name' => 'car', 'in' => 'query', 'required' => true, 'schema' => [ 'type' => 'integer', 'minimum' => 1 ] ],
							[ 'name' => 'from', 'in' => 'query', 'required' => true, 'schema' => [ 'type' => 'string', 'format' => 'date' ] ],
							[ 'name' => 'to', 'in' => 'query', 'required' => true, 'schema' => [ 'type' => 'string', 'format' => 'date' ] ],
							[ 'name' => 'pickup', 'in' => 'query', 'required' => false, 'schema' => [ '$ref' => '#/components/schemas/Location' ] ],
							[ 'name' => 'return', 'in' => 'query', 'required' => false, 'schema' => [ '$ref' => '#/components/schemas/Location' ] ],
						],
						'responses' => [
							'200' => [ 'description' => 'Calculated quote', 'content' => [ 'application/json' => [ 'schema' => [ '$ref' => '#/components/schemas/Quote' ] ] ] ],
							'400' => [ 'description' => 'Invalid car, dates, or location' ],
							'404' => [ 'description' => 'Vehicle not found' ],
						],
					],
				],
				'/wp-json/geolander/v1/checkout' => [
					'post' => [
						'operationId' => 'createBookingRequest',
						'summary'     => 'Create a booking request and WhatsApp handoff',
						'description' => 'Requires explicit traveller approval. Creates an internal booking request and returns a prepared WhatsApp URL. It does not confirm availability or the reservation.',
						'requestBody' => [
							'required' => true,
							'content'  => [ 'application/json' => [ 'schema' => [ '$ref' => '#/components/schemas/BookingRequest' ] ] ],
						],
						'responses' => [
							'200' => [ 'description' => 'Booking request saved and handoff prepared', 'content' => [ 'application/json' => [ 'schema' => [ '$ref' => '#/components/schemas/BookingHandoff' ] ] ] ],
							'400' => [ 'description' => 'Invalid customer, vehicle, dates, or location' ],
							'429' => [ 'description' => 'Request rate limit exceeded' ],
						],
					],
				],
			],
			'components' => [
				'schemas' => [
					'Location' => [ 'type' => 'string', 'enum' => [ 'tbilisi_office', 'tbilisi_airport', 'kutaisi_airport', 'batumi_airport' ] ],
					'Quote' => [
						'type' => 'object',
						'required' => [ 'car', 'from', 'to', 'days', 'rental_total', 'pickup_fee', 'return_fee', 'total', 'prepayment', 'balance' ],
						'properties' => [
							'car' => [ 'type' => 'string' ], 'from' => [ 'type' => 'string', 'format' => 'date' ], 'to' => [ 'type' => 'string', 'format' => 'date' ],
							'days' => [ 'type' => 'integer', 'minimum' => 1 ], 'rental_total' => [ 'type' => 'number' ], 'pickup_fee' => [ 'type' => 'number' ],
							'return_fee' => [ 'type' => 'number' ], 'total' => [ 'type' => 'number' ], 'prepayment' => [ 'type' => 'number' ], 'balance' => [ 'type' => 'number' ],
						],
					],
					'BookingRequest' => [
						'type' => 'object',
						'required' => [ 'car', 'from', 'to', 'pickup', 'return', 'name', 'email' ],
						'properties' => [
							'car' => [ 'type' => 'integer', 'minimum' => 1 ], 'from' => [ 'type' => 'string', 'format' => 'date' ], 'to' => [ 'type' => 'string', 'format' => 'date' ],
							'pickup' => [ '$ref' => '#/components/schemas/Location' ], 'return' => [ '$ref' => '#/components/schemas/Location' ],
							'name' => [ 'type' => 'string', 'minLength' => 1 ], 'email' => [ 'type' => 'string', 'format' => 'email' ],
						],
					],
					'BookingHandoff' => [
						'type' => 'object',
						'required' => [ 'redirect', 'reference' ],
						'properties' => [ 'redirect' => [ 'type' => 'string', 'format' => 'uri' ], 'reference' => [ 'type' => 'string', 'pattern' => '^GL-[0-9]+$' ], 'message' => [ 'type' => 'string' ], 'emailSent' => [ 'type' => 'boolean' ] ],
					],
				],
			],
		];

		$issuer = GLC_Access::issuer();
		if ( $issuer ) {
			$document['components']['securitySchemes'] = [
				'CloudflareManagedOAuth' => [
					'type'        => 'oauth2',
					'description' => 'Cloudflare Access Managed OAuth. Authorization is restricted to the identity providers and policies configured for the Geolander Agent API application.',
					'flows'       => [
						'authorizationCode' => [
							'authorizationUrl' => $issuer . '/cdn-cgi/access/oauth/authorization',
							'tokenUrl'         => $issuer . '/cdn-cgi/access/oauth/token',
							'refreshUrl'       => $issuer . '/cdn-cgi/access/oauth/token',
							'scopes'           => (object) [],
						],
					],
				],
			];

			$agent_quote = $document['paths']['/wp-json/geolander/v1/quote']['get'];
			$agent_quote['operationId'] = 'getAuthenticatedRentalQuote';
			$agent_quote['description'] .= ' This mirror requires Cloudflare Managed OAuth and validates the resulting Access JWT at the origin.';
			$agent_quote['security'] = [ [ 'CloudflareManagedOAuth' => [] ] ];
			$agent_quote['responses']['401'] = [ 'description' => 'Missing or invalid Cloudflare Access authorization' ];
			$document['paths']['/wp-json/geolander-agent/v1/quote'] = [ 'get' => $agent_quote ];

			$agent_checkout = $document['paths']['/wp-json/geolander/v1/checkout']['post'];
			$agent_checkout['operationId'] = 'createAuthenticatedBookingRequest';
			$agent_checkout['description'] .= ' This mirror requires Cloudflare Managed OAuth and validates the resulting Access JWT at the origin.';
			$agent_checkout['security'] = [ [ 'CloudflareManagedOAuth' => [] ] ];
			$agent_checkout['responses']['401'] = [ 'description' => 'Missing or invalid Cloudflare Access authorization' ];
			$document['paths']['/wp-json/geolander-agent/v1/checkout'] = [ 'post' => $agent_checkout ];
		}

		return $document;
	}

	/** RFC 9727 API Catalog for public customer and authenticated agent APIs. */
	private static function api_catalog(): array {
		$entry = static function ( string $anchor ): array {
			return [
					'anchor'       => home_url( $anchor ),
					'service-desc' => [
						[
							'href' => home_url( '/openapi.json' ),
							'type' => 'application/json',
						],
					],
					'service-doc'  => [
						[
							'href' => home_url( '/developers/' ),
							'type' => 'text/html',
						],
					],
					'status'       => [
						[
							'href' => home_url( '/health.php' ),
							'type' => 'text/plain',
						],
					],
				];
		};

		return [
			'linkset' => [
				$entry( '/wp-json/geolander/v1/' ),
				$entry( '/wp-json/geolander-agent/v1/' ),
			],
		];
	}

	private static function cars(): array {
		return get_posts( [ 'post_type' => 'car', 'posts_per_page' => -1, 'orderby' => 'menu_order', 'order' => 'ASC' ] );
	}

	private static function llms(): string {
		$home = home_url( '/' );
		// Never hard-code the fleet size: it disagreed with /fleet/ and /pricing.md,
		// and this file is read as fact by AI systems. Count what is published.
		$cars   = self::cars();
		$models = array_values( array_unique( array_map(
			static fn( $car ) => trim( preg_replace( '/\s*\d{4}.*$/', '', $car->post_title ) ),
			$cars
		) ) );

		$out  = "# Geolander — 4x4 Car Rental in Tbilisi, Georgia\n\n";
		$out .= "> Geolander (\"Geolander car rental\") is a tourist-focused car rental company with an office in Mtatsminda, in the heart of Tbilisi, Georgia (country). "
			. sprintf( 'It rents exact, individually listed vehicles, including %s, ', implode( ', ', $models ) )
			. "suited for Caucasus mountain roads — Kazbegi, Gudauri, Kakheti, "
			. "Svaneti. Prices include full insurance with no deductible and free winter tires. Free delivery at Tbilisi International Airport (TBS). "
			. "Booking: pick dates on the site for an exact seasonal price and confirm via WhatsApp. A 10% prepayment confirms the booking; "
			. "the remaining balance is paid at pickup. If cancelled at least 30 days before the rental starts, 50% of the prepayment is refunded. "
			. "With fewer than 30 days remaining, the prepayment is non-refundable.\n\n";

		$out .= "Key facts:\n";
		$out .= "- Address: " . GLC_Settings::get( 'address' ) . ', ' . GLC_Settings::get( 'address_locality' ) . ' ' . GLC_Settings::get( 'postal_code' ) . ", Georgia\n";
		$out .= "- District: " . GLC_Settings::get( 'office_district' ) . " — in the heart of Tbilisi\n";
		$out .= "- Phone / WhatsApp: " . GLC_Settings::get( 'phone' ) . "\n";
		$out .= "- Email: " . GLC_Settings::get( 'email' ) . "\n";
		$out .= "- Hours: " . GLC_Settings::get( 'business_hours' ) . "\n";
		$out .= "- Security deposit: none; no card preauthorization hold\n";
		$out .= "- Insurance: no deductible; third-party liability limit 30,000 GEL; single-vehicle accidents covered\n";
		$out .= "- Route policy: all vehicles and routes, except during bad weather, road closures, or damaged-road conditions\n";
		$out .= "- Cross-border: Armenia is allowed; confirm trip documents and insurance before departure\n";
		[ $glc_low, $glc_high ] = GLC_Format::range();
		$out .= sprintf(
			"- Prices: from \$%d to \$%d per day (USD), seasonal + duration-tiered; long rentals cost less per day\n",
			$glc_low,
			$glc_high
		);
		$out .= "- Requirements: minimum age 21, valid license (IDP recommended), passport\n";
		$out .= "- Website languages: English, Georgian, Russian, Ukrainian, Arabic, Chinese, French\n\n";
		$out .= self::agent_guidance() . "\n";

		/*
		 * This file exists to be quoted verbatim by AI systems, so an unpriced car
		 * must read "price on request" rather than "$0–$0/day". A machine-readable
		 * false price is worse than no file at all.
		 */
		$out .= "## Fleet\n\n";
		foreach ( self::cars() as $car ) {
			[ $low, $high ] = GLC_Pricing::rate_range( $car->ID );
			$out .= sprintf(
				"- [%s](%s): %s, %d seats, %s, %s\n",
				$car->post_title,
				get_permalink( $car ),
				implode( ', ', wp_get_post_terms( $car->ID, 'car_body_type', [ 'fields' => 'names' ] ) ),
				(int) get_post_meta( $car->ID, 'glc_seats', true ),
				get_post_meta( $car->ID, 'glc_transmission', true ),
				( $low > 0 && $high > 0 ) ? sprintf( '$%d–$%d/day', $low, $high ) : 'price on request'
			);
		}

		$cities = class_exists( 'GLC_City' ) ? GLC_City::all() : [];
		if ( $cities ) {
			$out .= "\n## Cities we deliver to\n\n";
			$out .= "Geolander delivers rental cars in these cities. Tbilisi office and TBS handovers are free; Kutaisi and Batumi pickup and return charges are shown separately in the booking quote:\n";
			foreach ( $cities as $city ) {
				$air  = GLC_City::airport( $city->ID );
				$code = $air['code'] ? ' (' . $air['code'] . ' airport)' : '';
				$out .= sprintf( "- [%s](%s)%s\n", GLC_City::city_name( $city->ID ), get_permalink( $city ), $code );
			}
		}

		$out .= "\n## Key pages\n\n";
		$out .= "- [Fleet & live prices]({$home}fleet/)\n";
		$out .= "- [Machine-readable price list]({$home}pricing.md)\n";
		$out .= "- [Places to visit in Georgia by car]({$home}places/)\n";
		$out .= "- [Driving in Georgia — travel info]({$home}travel-info/)\n";
		$out .= "- [Rental terms]({$home}terms/)\n";
		$out .= "- [About Geolander car rental]({$home}about/)\n";
		$out .= "- [Contact]({$home}contact/)\n";
		$out .= "- [Geolander developer resources]({$home}developers/)\n";
		$out .= "- [Agent instructions]({$home}agent-instructions.md)\n";
		$out .= "- [OpenAPI specification]({$home}openapi.json)\n";
		$out .= "- [RFC 9727 API catalog]({$home}.well-known/api-catalog)\n";
		$out .= "- [RFC 8414 OAuth discovery]({$home}.well-known/oauth-authorization-server)\n";

		$faqs = get_posts( [ 'post_type' => 'faq', 'posts_per_page' => 20, 'orderby' => 'menu_order', 'order' => 'ASC' ] );
		if ( $faqs ) {
			$out .= "\n## FAQ\n\n";
			foreach ( $faqs as $faq ) {
				$out .= '### ' . $faq->post_title . "\n" . wp_strip_all_tags( $faq->post_content ) . "\n\n";
			}
		}
		return $out;
	}

	private static function pricing(): string {
		$out  = "# Geolander Car Rental — Price List (USD per day)\n\n";
		$out .= 'Last generated: ' . current_datetime()->format( 'Y-m-d' ) . "\n\n";
		$out .= "Every price includes full insurance with no deductible, free winter tires, and free Tbilisi Airport delivery. Third-party liability is limited to 30,000 GEL. "
			. "The daily rate depends on the season and the TOTAL rental length (longer = cheaper per day). "
			. "No security deposit or card preauthorization hold. A 10% prepayment confirms the booking; the remaining balance is paid at pickup. "
			. "If cancelled at least 30 days before the rental starts, 50% of the prepayment is refunded. With fewer than 30 days remaining, the prepayment is non-refundable.\n\n";

		$glc_unpriced = [];
		foreach ( self::cars() as $car ) {
			$pricing = GLC_Pricing::seasons( $car->ID );
			if ( ! $pricing || ! GLC_Pricing::is_priced( $car->ID ) ) {
				// Listed, not silently dropped: /pricing.md previously showed 8 cars
				// while /fleet/ showed 19, so the file contradicted the site.
				$glc_unpriced[] = $car;
				continue;
			}
			$out .= '## ' . $car->post_title . "\n";
			$out .= sprintf(
				"%d seats · %s · %s · Book: %s\n\n",
				(int) get_post_meta( $car->ID, 'glc_seats', true ),
				get_post_meta( $car->ID, 'glc_transmission', true ),
				get_post_meta( $car->ID, 'glc_fuel_type', true ),
				get_permalink( $car )
			);
			$out .= '| Season | ' . implode( ' | ', array_map( fn( $l ) => $l . ' days', GLC_Pricing::TIER_LABELS ) ) . " |\n";
			$out .= '|' . str_repeat( '---|', count( GLC_Pricing::TIERS ) + 1 ) . "\n";
			foreach ( $pricing as $season ) {
				$cells = array_map(
					static function ( $tier ) use ( $season ) {
						$rate = (float) ( $season['rates'][ $tier ] ?? 0 );
						return $rate > 0 ? '$' . number_format( $rate, 0 ) : '—';
					},
					GLC_Pricing::TIERS
				);
				$out .= '| ' . ( $season['label'] ?? '' ) . ' | ' . implode( ' | ', $cells ) . " |\n";
			}
			$out .= "\n";
		}

		if ( $glc_unpriced ) {
			$out .= "## Price on request\n\n";
			$out .= "These vehicles are in the fleet but have no published rate table yet. "
				. "Ask on WhatsApp for an exact quote — do not assume a price.\n\n";
			foreach ( $glc_unpriced as $car ) {
				$out .= sprintf( "- [%s](%s)\n", $car->post_title, get_permalink( $car ) );
			}
			$out .= "\n";
		}
		return $out;
	}
}
