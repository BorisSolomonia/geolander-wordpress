<?php
/**
 * Machine-readable surfaces for AI systems: /llms.txt, /pricing.md,
 * /agent-instructions.md, /openapi.json, RFC 9727 API catalog, OAuth metadata,
 * OAuth protected-resource metadata, auth.md, A2A/ARD/Agent Skills discovery,
 * and text/markdown content negotiation on canonical public URLs.
 */

defined( 'ABSPATH' ) || exit;

class GLC_AI {
	private const AGENT_SCOPE = 'geolander.agent';

	public static function init() {
		add_action( 'init', [ __CLASS__, 'rewrites' ] );
		add_filter( 'query_vars', fn( $vars ) => array_merge( $vars, [ 'glc_ai_file' ] ) );
		// Core's canonical redirect would rewrite these pseudo-file URLs.
		add_filter( 'redirect_canonical', fn( $redirect ) => get_query_var( 'glc_ai_file' ) ? false : $redirect );
		add_action( 'send_headers', [ __CLASS__, 'vary_accept' ], 20 );
		add_action( 'template_redirect', [ __CLASS__, 'serve' ], 5 );
		add_action( 'wp_footer', [ __CLASS__, 'webmcp' ], 50 );
	}

	public static function rewrites() {
		add_rewrite_rule( '^llms\.txt$', 'index.php?glc_ai_file=llms', 'top' );
		add_rewrite_rule( '^pricing\.md$', 'index.php?glc_ai_file=pricing', 'top' );
		add_rewrite_rule( '^agent-instructions\.md$', 'index.php?glc_ai_file=instructions', 'top' );
		add_rewrite_rule( '^openapi\.json$', 'index.php?glc_ai_file=openapi', 'top' );
		add_rewrite_rule( '^auth\.md$', 'index.php?glc_ai_file=auth', 'top' );
		add_rewrite_rule( '^index\.md$', 'index.php?glc_ai_file=index_markdown', 'top' );
		add_rewrite_rule( '^\.well-known/api-catalog/?$', 'index.php?glc_ai_file=api_catalog', 'top' );
		add_rewrite_rule( '^\.well-known/oauth-authorization-server/?$', 'index.php?glc_ai_file=oauth_metadata', 'top' );
		add_rewrite_rule( '^\.well-known/oauth-protected-resource/?$', 'index.php?glc_ai_file=oauth_resource', 'top' );
		add_rewrite_rule( '^\.well-known/ai-catalog\.json$', 'index.php?glc_ai_file=ai_catalog', 'top' );
		add_rewrite_rule( '^\.well-known/agent-skills/index\.json$', 'index.php?glc_ai_file=skills_index', 'top' );
		add_rewrite_rule( '^\.well-known/agent-skills/geolander-car-rental/SKILL\.md$', 'index.php?glc_ai_file=booking_skill', 'top' );
		add_rewrite_rule( '^\.well-known/mcp/server-card(?:\.json)?$', 'index.php?glc_ai_file=mcp_card', 'top' );
		add_rewrite_rule( '^\.well-known/agent-card\.json$', 'index.php?glc_ai_file=a2a_card', 'top' );
		add_rewrite_rule( '^\.well-known/http-message-signatures-directory$', 'index.php?glc_ai_file=web_bot_auth', 'top' );
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
		header( 'Link: <' . home_url( '/.well-known/api-catalog' ) . '>; rel="api-catalog"; type="application/linkset+json"', false );
		header( 'Link: <' . home_url( '/.well-known/ai-catalog.json' ) . '>; rel="ai-catalog"; type="application/ai-catalog+json"', false );
		header( 'Link: <' . home_url( '/.well-known/agent-skills/index.json' ) . '>; rel="agent-skills"; type="application/json"', false );
		header( 'Link: <' . home_url( '/.well-known/agent-card.json' ) . '>; rel="service-desc"; type="application/a2a+json"', false );
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
		if ( 'web_bot_auth' === $file ) {
			GLC_Web_Bot_Auth::serve_directory();
		}
		$types = [
			'llms'         => 'text/plain; charset=utf-8',
			'pricing'      => 'text/markdown; charset=utf-8',
			'instructions' => 'text/markdown; charset=utf-8',
			'auth'          => 'text/markdown; charset=utf-8',
			'index_markdown'=> 'text/markdown; charset=utf-8',
			'booking_skill' => 'text/markdown; charset=utf-8',
			'openapi'      => 'application/json; charset=utf-8',
			'api_catalog'  => 'application/linkset+json; profile="https://www.rfc-editor.org/info/rfc9727"',
			'oauth_metadata' => 'application/json; charset=utf-8',
			'oauth_resource' => 'application/json; charset=utf-8',
			'ai_catalog'     => 'application/ai-catalog+json; charset=utf-8',
			'skills_index'   => 'application/json; charset=utf-8',
			'mcp_card'       => 'application/mcp-server-card+json; charset=utf-8',
			'a2a_card'       => 'application/a2a+json; charset=utf-8',
		];
		if ( ! isset( $types[ $file ] ) ) {
			status_header( 404 );
			exit;
		}
		$oauth_metadata = 'oauth_metadata' === $file ? self::oauth_metadata() : null;
		$oauth_resource = 'oauth_resource' === $file ? self::oauth_resource_metadata() : null;
		header( 'Content-Type: ' . $types[ $file ] );
		if ( 'oauth_metadata' === $file && ! $oauth_metadata ) {
			status_header( 503 );
			header( 'Cache-Control: no-store' );
			if ( 'HEAD' !== strtoupper( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) ) {
				echo wp_json_encode( [ 'error' => 'oauth_configuration_unavailable' ] ); // phpcs:ignore WordPress.Security.EscapeOutput
			}
			exit;
		}
		if ( 'oauth_resource' === $file && ! $oauth_resource ) {
			status_header( 503 );
			header( 'Cache-Control: no-store' );
			if ( 'HEAD' !== strtoupper( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) ) {
				echo wp_json_encode( [ 'error' => 'oauth_configuration_unavailable' ] ); // phpcs:ignore WordPress.Security.EscapeOutput
			}
			exit;
		}
		if ( in_array( $file, [ 'skills_index', 'booking_skill', 'mcp_card', 'a2a_card' ], true ) ) {
			header( 'Access-Control-Allow-Origin: *' );
		}
		header( 'Cache-Control: public, max-age=' . ( in_array( $file, [ 'oauth_metadata', 'oauth_resource' ], true ) ? '300' : '3600' ) );
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
			} elseif ( 'auth' === $file ) {
				echo self::auth_markdown(); // phpcs:ignore WordPress.Security.EscapeOutput
			} elseif ( 'index_markdown' === $file ) {
				echo self::llms(); // phpcs:ignore WordPress.Security.EscapeOutput
			} elseif ( 'booking_skill' === $file ) {
				echo self::booking_skill(); // phpcs:ignore WordPress.Security.EscapeOutput
			} elseif ( 'openapi' === $file ) {
				echo wp_json_encode( self::openapi(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT ); // phpcs:ignore WordPress.Security.EscapeOutput
			} elseif ( 'oauth_metadata' === $file ) {
				echo wp_json_encode( $oauth_metadata, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT ); // phpcs:ignore WordPress.Security.EscapeOutput
			} elseif ( 'oauth_resource' === $file ) {
				echo wp_json_encode( $oauth_resource, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT ); // phpcs:ignore WordPress.Security.EscapeOutput
			} elseif ( 'ai_catalog' === $file ) {
				echo wp_json_encode( self::ai_catalog(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT ); // phpcs:ignore WordPress.Security.EscapeOutput
			} elseif ( 'skills_index' === $file ) {
				echo wp_json_encode( self::skills_index(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT ); // phpcs:ignore WordPress.Security.EscapeOutput
			} elseif ( 'mcp_card' === $file ) {
				echo wp_json_encode( self::mcp_server_card(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT ); // phpcs:ignore WordPress.Security.EscapeOutput
			} elseif ( 'a2a_card' === $file ) {
				echo wp_json_encode( self::a2a_agent_card(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT ); // phpcs:ignore WordPress.Security.EscapeOutput
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
			. "Authenticated clients operated by Geolander team members may use the mirrored `/wp-json/geolander-agent/v1/` endpoints. Discover the read-only A2A v1.0 agent at `/.well-known/agent-card.json` and the Cloudflare Managed OAuth authorization server at `/.well-known/oauth-authorization-server`. The public customer endpoints remain available for the website booking widget.\n";
	}

	/** RFC 8414 metadata for Geolander's Cloudflare-backed authorization service. */
	public static function oauth_metadata(): array {
		$upstream_issuer = GLC_Access::issuer();
		if ( '' === $upstream_issuer ) {
			return [];
		}
		$issuer                 = untrailingslashit( home_url( '/' ) );
		$authorization_endpoint = $upstream_issuer . '/cdn-cgi/access/oauth/authorization';
		$registration_endpoint  = $upstream_issuer . '/cdn-cgi/access/oauth/registration';
		$revocation_endpoint    = $upstream_issuer . '/cdn-cgi/access/oauth/revoke';

		return [
			'issuer'                                => $issuer,
			'upstream_issuer'                       => $upstream_issuer,
			'authorization_endpoint'                 => $authorization_endpoint,
			'token_endpoint'                         => $upstream_issuer . '/cdn-cgi/access/oauth/token',
			'jwks_uri'                               => $upstream_issuer . '/cdn-cgi/access/certs',
			'registration_endpoint'                   => $registration_endpoint,
			'revocation_endpoint'                     => $revocation_endpoint,
			'grant_types_supported'                   => [ 'authorization_code', 'refresh_token' ],
			'response_types_supported'                => [ 'code' ],
			'response_modes_supported'                => [ 'query' ],
			'scopes_supported'                        => [ self::AGENT_SCOPE ],
			'token_endpoint_auth_methods_supported'   => [ 'client_secret_basic', 'client_secret_post', 'none' ],
			'code_challenge_methods_supported'         => [ 'S256' ],
			/*
			 * "anonymous" describes public-client registration only. A client_id
			 * is not an API credential: the user must authenticate at claim_uri
			 * before Cloudflare Access issues a bearer token.
			 */
			'agent_auth'                              => [
				'skill'                    => home_url( '/auth.md' ),
				'register_uri'             => $registration_endpoint,
				'claim_uri'                => $authorization_endpoint,
				'revocation_uri'           => $revocation_endpoint,
				'identity_types_supported' => [ 'anonymous' ],
				'anonymous'                => [
					'credential_types_supported'  => [ 'access_token', 'refresh_token' ],
					'claim_uri'                    => $authorization_endpoint,
					'requires_user_authentication' => true,
				],
			],
		];
	}

	/** RFC 9728 metadata for the Cloudflare Access protected agent API. */
	public static function oauth_resource_metadata(): array {
		$upstream_issuer = GLC_Access::issuer();
		if ( '' === $upstream_issuer ) {
			return [];
		}

		return [
			'resource'                      => untrailingslashit( home_url( '/' ) ),
			'authorization_servers'         => [ untrailingslashit( home_url( '/' ) ) ],
			'upstream_authorization_server' => $upstream_issuer,
			// One coarse permission: Access still evaluates the resource and policy.
			'scopes_supported'              => [ self::AGENT_SCOPE ],
			'bearer_methods_supported'      => [ 'header' ],
			'resource_name'             => 'Geolander Agent Reservation API',
			'resource_documentation'    => home_url( '/developers/' ),
		];
	}

	/** Human- and agent-readable authentication instructions; no fake signup flow. */
	private static function auth_markdown(): string {
		$home                   = home_url( '/' );
		$issuer                 = untrailingslashit( $home );
		$upstream_issuer        = GLC_Access::issuer();
		$registration_endpoint  = $upstream_issuer . '/cdn-cgi/access/oauth/registration';
		$authorization_endpoint = $upstream_issuer . '/cdn-cgi/access/oauth/authorization';
		$token_endpoint         = $upstream_issuer . '/cdn-cgi/access/oauth/token';
		$revocation_endpoint    = $upstream_issuer . '/cdn-cgi/access/oauth/revoke';
		return "# auth.md — Geolander Agent Registration\n\n"
			. "> You are an agent acting for a traveller or a Geolander operator. This file describes how to register an OAuth public client and obtain credentials for the protected Geolander Agent Reservation API.\n\n"
			. "Resource server: `{$home}wp-json/geolander-agent/v1/`  \n"
			. "Authorization server issuer: `{$issuer}`  \n"
			. "Managed OAuth upstream: `{$upstream_issuer}`\n\n"
			. "The customer-facing website and public quote API require no account. The protected agent API uses Cloudflare Access Managed OAuth. There is no API-key flow and no anonymous access to protected resources. Passive scanners must not POST to the registration endpoint because registration creates persistent OAuth client state.\n\n"
			. "## Discovery\n\n"
			. "- OAuth protected resource metadata: {$home}.well-known/oauth-protected-resource\n"
			. "- OAuth authorization server metadata: {$home}.well-known/oauth-authorization-server\n"
			. "- A2A v1.0 Agent Card: {$home}.well-known/agent-card.json\n"
			. "- OpenAPI 3.1 specification: {$home}openapi.json\n"
			. "- Developer documentation: {$home}developers/\n\n"
			. "## Step 1 — Discover\n\n"
			. "Request a protected endpoint and read the `WWW-Authenticate: Bearer` header. Its `resource_metadata` parameter points to route-specific Cloudflare Protected Resource Metadata. The stable Geolander metadata above publishes `resource`, `authorization_servers`, `scopes_supported`, and `bearer_methods_supported`. Geolander exposes the single coarse scope `" . self::AGENT_SCOPE . "`, representing access to the protected agent API. Cloudflare binds authorization to the RFC 8707 `resource` value and evaluates the Access policy; it may return an empty OAuth `scope` string because permissions are enforced at the protected-resource boundary.\n\n"
			. "Fetch the authorization-server metadata and read its standard OAuth fields plus the `agent_auth` block. That block is the source of truth for the Auth.md skill, registration URI, claim URI, supported credential types, and revocation URI.\n\n"
			. "## Step 2 — Pick the supported method\n\n"
			. "Supported method: anonymous OAuth public-client registration followed by required user authentication. Anonymous means only that creating a `client_id` does not require an existing client credential; it never grants anonymous API access. The user must complete Cloudflare Access authentication before an `access_token` or `refresh_token` is issued.\n\n"
			. "- Registration endpoint: `POST {$registration_endpoint}`\n"
			. "- Claim / user authorization endpoint: `{$authorization_endpoint}`\n"
			. "- Credential types: `access_token`, `refresh_token`\n"
			. "- Credential presentation: `Authorization: Bearer <access_token>`\n"
			. "- Revocation endpoint: `POST {$revocation_endpoint}`\n\n"
			. "### Machine-readable registration profile\n\n"
			. "The same complete registration method is embedded here so an agent can provision credentials even when it cannot parse OAuth metadata:\n\n"
			. "```json\n{\n  \"agent_auth\": {\n    \"skill\": \"{$home}auth.md\",\n    \"register_uri\": \"{$registration_endpoint}\",\n    \"claim_uri\": \"{$authorization_endpoint}\",\n    \"revocation_uri\": \"{$revocation_endpoint}\",\n    \"identity_types_supported\": [\"anonymous\"],\n    \"anonymous\": {\n      \"credential_types_supported\": [\"access_token\", \"refresh_token\"],\n      \"claim_uri\": \"{$authorization_endpoint}\",\n      \"requires_user_authentication\": true\n    }\n  }\n}\n```\n\n"
			. "## Step 3 — Register the OAuth public client\n\n"
			. "Choose a localhost or loopback callback URI. Dynamic client registration is enabled for both by the Geolander Cloudflare Access application. Then send:\n\n"
			. "```http\nPOST {$registration_endpoint}\nContent-Type: application/json\n\n"
			. "{\n  \"redirect_uris\": [\"http://127.0.0.1:8400/callback\"],\n  \"token_endpoint_auth_method\": \"none\",\n  \"grant_types\": [\"authorization_code\"],\n  \"response_types\": [\"code\"],\n  \"resource\": \"{$home}wp-json/geolander-agent/v1/\"\n}\n```\n\n"
			. "A successful registration returns a `client_id`. Store it with the exact `redirect_uri`. The client ID is registration metadata, not a bearer credential and not permission to use the protected API.\n\n"
			. "## Step 4 — Authorize with PKCE S256\n\n"
			. "Generate a high-entropy `code_verifier` and its base64url SHA-256 `code_challenge`. Open the claim URI for the user with `client_id`, the exact `redirect_uri`, `response_type=code`, `code_challenge`, `code_challenge_method=S256`, and the protected API URL in `resource`. The user signs in through Cloudflare Access. Do not claim success until the callback receives an authorization code.\n\n"
			. "## Step 5 — Exchange and use the credential\n\n"
			. "```http\nPOST {$token_endpoint}\nContent-Type: application/x-www-form-urlencoded\n\ngrant_type=authorization_code&code=<authorization_code>&client_id=<client_id>&redirect_uri=http%3A%2F%2F127.0.0.1%3A8400%2Fcallback&code_verifier=<code_verifier>\n```\n\n"
			. "Use the returned credential only in the HTTP header: `Authorization: Bearer <access_token>`. Use the returned `refresh_token` at the same token endpoint when the access token expires. Never put a token in a URL, log, WhatsApp message, or booking note.\n\n"
			. "## Step 6 — Revoke and recover\n\n"
			. "Revoke an access or refresh token at `{$revocation_endpoint}`. On `401`, discard an invalid access token, attempt the refresh-token grant once, and otherwise restart registration and user authorization. Geolander does not advertise Auth.md revocation events because Cloudflare Managed OAuth does not publish them.\n\n"
			. "## Safety and user approval\n\n"
			. "The quote operation is read-only. Before checkout, obtain explicit traveller approval and collect the traveller's name and valid email. Checkout creates a booking request and WhatsApp handoff; it does not confirm availability, take payment, or confirm a reservation.\n\n"
			. "## Support\n\n"
			. "For access or integration questions, contact " . GLC_Settings::get( 'email' ) . ".\n";
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
							'scopes'           => [ self::AGENT_SCOPE => 'Access the Cloudflare-protected Geolander Agent Reservation API' ],
						],
					],
				],
			];

			$agent_quote = $document['paths']['/wp-json/geolander/v1/quote']['get'];
			$agent_quote['operationId'] = 'getAuthenticatedRentalQuote';
			$agent_quote['description'] .= ' This mirror requires Cloudflare Managed OAuth and validates the resulting Access JWT at the origin.';
			$agent_quote['security'] = [ [ 'CloudflareManagedOAuth' => [ self::AGENT_SCOPE ] ] ];
			$agent_quote['responses']['401'] = [ 'description' => 'Missing or invalid Cloudflare Access authorization' ];
			$document['paths']['/wp-json/geolander-agent/v1/quote'] = [ 'get' => $agent_quote ];

			$agent_checkout = $document['paths']['/wp-json/geolander/v1/checkout']['post'];
			$agent_checkout['operationId'] = 'createAuthenticatedBookingRequest';
			$agent_checkout['description'] .= ' This mirror requires Cloudflare Managed OAuth and validates the resulting Access JWT at the origin.';
			$agent_checkout['security'] = [ [ 'CloudflareManagedOAuth' => [ self::AGENT_SCOPE ] ] ];
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

	/** ARD v0.9 capability manifest; catalogs only resources that really exist. */
	private static function ai_catalog(): array {
		return [
			'specVersion' => '1.0',
			'host'        => [
				'displayName'      => 'Geolander car rental',
				'identifier'       => untrailingslashit( home_url( '/' ) ),
				'documentationUrl' => home_url( '/developers/' ),
			],
			'entries'     => [
				[
					'identifier'            => 'urn:air:geo-lander.com:a2a:reservation',
					'displayName'           => 'Geolander Rental Agent',
					'type'                  => 'application/a2a+json',
					'url'                   => home_url( '/.well-known/agent-card.json' ),
					'description'           => 'A2A v1.0 read-only agent for exact-car fleet discovery, verified rental policy, and date-specific quotes.',
					'capabilities'          => [ 'FleetList', 'RentalPolicyLookup', 'RentalQuote' ],
					'representativeQueries' => [
						'Ask the Geolander agent which exact rental cars are published.',
						'Delegate a date-specific quote calculation without creating a reservation.',
					],
					'version'               => '1.0.0',
				],
				[
					'identifier'            => 'urn:air:geo-lander.com:mcp:reservation',
					'displayName'           => 'Geolander Rental Tools MCP Server',
					'type'                  => 'application/mcp-server-card+json',
					'url'                   => home_url( '/.well-known/mcp/server-card.json' ),
					'description'           => 'OAuth-protected read-only MCP tools for exact-car fleet discovery, verified rental policy, and date-specific quotes.',
					'capabilities'          => [ 'FleetList', 'RentalPolicyLookup', 'RentalQuote' ],
					'representativeQueries' => [
						'List exact Geolander rental cars and their published rate ranges.',
						'Get a date-specific quote for an exact car and airport handover.',
						'Explain Geolander insurance, deposit, mileage, and cancellation policy.',
					],
					'version'               => '1.0.0',
				],
				[
					'identifier'            => 'urn:air:geo-lander.com:api:reservation',
					'displayName'           => 'Geolander Reservation API',
					'type'                  => 'application/vnd.oai.openapi+json',
					'url'                   => home_url( '/openapi.json' ),
					'description'           => 'Date-specific rental quotes and customer-approved booking-request handoff for exact Geolander vehicles in Georgia.',
					'capabilities'          => [ 'RentalQuote', 'BookingRequest' ],
					'representativeQueries' => [
						'Get a quote for an exact AWD rental car in Tbilisi.',
						'Calculate Kutaisi Airport pickup and return fees for a Geolander booking.',
						'Prepare a booking request after the traveller approves the quote.',
					],
					'version'               => '1.1.0',
				],
				[
					'identifier'            => 'urn:air:geo-lander.com:skill:car-rental',
					'displayName'           => 'Geolander exact-car rental',
					'type'                  => 'application/ai-skill+md',
					'url'                   => home_url( '/.well-known/agent-skills/geolander-car-rental/SKILL.md' ),
					'description'           => 'Instructions for quoting and requesting an exact Geolander rental car without inventing availability, prices, or route conditions.',
					'capabilities'          => [ 'RentalQuote', 'BookingRequest', 'RentalPolicyLookup' ],
					'representativeQueries' => [
						'Find an exact 4x4 rental for my trip in Georgia.',
						'What insurance and deposit terms apply to this Geolander car?',
					],
				],
			],
		];
	}

	/** A2A Protocol v1.0 public Agent Card for the protected JSON-RPC service. */
	private static function a2a_agent_card(): array {
		return [
			'name'               => 'Geolander Rental Agent',
			'version'            => '1.0.0',
			'description'        => 'Read-only exact-car fleet discovery, verified Geolander rental policy, and date-specific rental quotes. Quotes do not confirm availability or create reservations.',
			'supportedInterfaces' => [
				[
					'url'             => home_url( '/wp-json/geolander-agent/v1/a2a' ),
					'protocolBinding' => 'JSONRPC',
					'protocolVersion' => '1.0',
				],
			],
			'provider'           => [
				'organization' => 'Geolander',
				'url'          => home_url( '/' ),
			],
			'documentationUrl'   => home_url( '/developers/' ),
			'capabilities'       => [
				'streaming'         => false,
				'pushNotifications' => false,
				'extendedAgentCard' => false,
			],
			'securitySchemes'    => [
				'CloudflareAccess' => [
					'httpAuthSecurityScheme' => [
						'description'  => 'Bearer JWT issued through Geolander Cloudflare Access Managed OAuth.',
						'scheme'       => 'Bearer',
						'bearerFormat' => 'JWT',
					],
				],
			],
			'securityRequirements' => [
				[ 'schemes' => [ 'CloudflareAccess' => [ 'list' => [] ] ] ],
			],
			'defaultInputModes'  => [ 'application/json', 'text/plain' ],
			'defaultOutputModes' => [ 'application/json', 'text/plain' ],
			'skills'             => [
				[
					'id'          => 'fleet-discovery',
					'name'        => 'Exact-car fleet discovery',
					'description' => 'Returns published exact Geolander vehicles and verified positive rate ranges. Unpriced cars omit prices instead of reporting zero.',
					'tags'        => [ 'car-rental', 'fleet', '4x4', 'tbilisi', 'georgia' ],
					'examples'    => [ 'List the exact Geolander cars currently published.' ],
					'inputModes'  => [ 'application/json', 'text/plain' ],
					'outputModes' => [ 'application/json', 'text/plain' ],
				],
				[
					'id'          => 'rental-policy',
					'name'        => 'Verified rental policy lookup',
					'description' => 'Returns owner-verified insurance, deposit, mileage, winter-tyre, route, handover, prepayment, and cancellation facts.',
					'tags'        => [ 'car-rental', 'insurance', 'deposit', 'policy', 'georgia' ],
					'examples'    => [ 'What insurance, deposit, and mileage terms apply?' ],
					'inputModes'  => [ 'application/json', 'text/plain' ],
					'outputModes' => [ 'application/json', 'text/plain' ],
				],
				[
					'id'          => 'rental-quote',
					'name'        => 'Date-specific rental quote',
					'description' => 'Calculates a read-only quote for one published exact car, dates, pickup, and return. It does not confirm availability or create a booking.',
					'tags'        => [ 'car-rental', 'quote', 'pricing', 'airport', 'georgia' ],
					'examples'    => [ 'Calculate a quote using a published car ID, pickup date, return date, pickup location, and return location.' ],
					'inputModes'  => [ 'application/json' ],
					'outputModes' => [ 'application/json', 'text/plain' ],
				],
			],
		];
	}

	/** SEP-2127 experimental server card for the real protected MCP endpoint. */
	private static function mcp_server_card(): array {
		return [
			'$schema'     => 'https://static.modelcontextprotocol.io/schemas/v1/server-card.schema.json',
			'name'        => 'com.geo-lander/reservation',
			'title'       => 'Geolander Rental Tools',
			'version'     => '1.0.0',
			'description' => 'Read-only exact-car fleet discovery, verified rental policy, and date-specific Geolander quotes. Quotes do not confirm availability or create reservations.',
			'websiteUrl'  => home_url( '/developers/' ),
			'remotes'     => [
				[
					'type'                      => 'streamable-http',
					'url'                       => home_url( '/wp-json/geolander-agent/v1/mcp' ),
					'headers'                   => [
						[
							'name'        => 'Authorization',
							'description' => 'Bearer token issued through Geolander Cloudflare Access Managed OAuth.',
							'isRequired'  => true,
							'isSecret'    => true,
						],
					],
					'supportedProtocolVersions' => [ '2026-07-28', '2025-11-25', '2025-06-18' ],
				],
			],
		];
	}

	private static function skills_index(): array {
		$skill = self::booking_skill();
		return [
			'$schema' => 'https://schemas.agentskills.io/discovery/0.2.0/schema.json',
			'skills'  => [
				[
					'name'        => 'geolander-car-rental',
					'type'        => 'skill-md',
					'description' => 'Use when a traveller needs verified Geolander fleet facts, a date-specific quote, or a customer-approved exact-car booking request in Georgia.',
					'url'         => home_url( '/.well-known/agent-skills/geolander-car-rental/SKILL.md' ),
					'digest'      => 'sha256:' . hash( 'sha256', $skill ),
				],
			],
		];
	}

	private static function booking_skill(): string {
		$home = home_url( '/' );
		return "---\n"
			. "name: geolander-car-rental\n"
			. "description: Use when a traveller needs verified Geolander fleet facts, a date-specific quote, or a customer-approved exact-car booking request in Georgia.\n"
			. "---\n\n"
			. "# Geolander exact-car rental\n\n"
			. "## When to use this skill\n\n"
			. "Use it for exact AWD or 4x4 vehicle selection, dated rental quotes, Tbilisi/Kutaisi/Batumi handover fees, insurance and deposit questions, or a booking-request handoff.\n\n"
			. "Do not use it to claim live availability, current road safety, or a confirmed reservation. Weather, official closures, and damaged-road conditions override general route permission.\n\n"
			. "## Procedure\n\n"
			. "1. Read the live fleet and prices at {$home}pricing.md. Never invent a missing price or publish a zero.\n"
			. "2. Read {$home}openapi.json and call the read-only quote operation with the exact car ID, dates, pickup, and return location.\n"
			. "3. Show the traveller the exact car, rental subtotal, each location fee, final shown total, 10% prepayment, and balance.\n"
			. "4. Before checkout, obtain explicit approval and a valid traveller name and email.\n"
			. "5. Call checkout only after approval. Explain that it creates a request and WhatsApp handoff, not a confirmed reservation. Geolander staff confirm availability and payment instructions.\n\n"
			. "## Verified policy summary\n\n"
			. "- No security deposit or card preauthorization hold.\n"
			. "- Full insurance is included with no deductible; tyres are excluded.\n"
			. "- Unlimited mileage applies within Georgia.\n"
			. "- Winter tyres are included free in winter.\n"
			. "- Tbilisi office and Tbilisi Airport handovers are free. Location charges for Kutaisi and Batumi must come from the quote.\n"
			. "- A 10% prepayment confirms a booking. Cancellation terms are published at {$home}terms/.\n\n"
			. "## Fallback\n\n"
			. "If the API or price table cannot provide a verified answer, do not guess. Contact Geolander through {$home}contact/.\n";
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
		$out .= "- [RFC 9728 protected-resource metadata]({$home}.well-known/oauth-protected-resource)\n";
		$out .= "- [Authentication guide]({$home}auth.md)\n";
		$out .= "- [ARD capability catalog]({$home}.well-known/ai-catalog.json)\n";
		$out .= "- [Agent Skills discovery index]({$home}.well-known/agent-skills/index.json)\n";
		$out .= "- [MCP server card]({$home}.well-known/mcp/server-card.json)\n";
		$out .= "- [A2A Agent Card]({$home}.well-known/agent-card.json)\n";
		$out .= "- [Web Bot Auth public key directory]({$home}.well-known/http-message-signatures-directory)\n";

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

	/**
	 * Register read-only in-page tools for browsers implementing WebMCP.
	 *
	 * Booking submission is intentionally absent: browser agents can quote and
	 * explain policy, while the human-visible form remains the approval boundary.
	 */
	public static function webmcp(): void {
		if ( is_admin() ) {
			return;
		}
		$quote_url   = rest_url( 'geolander/v1/quote' );
		$pricing_url = home_url( '/pricing.md' );
		?>
<script id="glc-webmcp">
(() => {
	// WebMCP implementations currently expose modelContext on document; retain
	// navigator compatibility for earlier implementations and discovery tools.
	const modelContext = document.modelContext || navigator.modelContext;
	if (!modelContext?.registerTool) return;
	const register = (tool) => modelContext.registerTool(tool).catch(() => {});
	register({
		name: 'get_geolander_policy',
		title: 'Get verified Geolander rental policy',
		description: 'Return verified insurance, deposit, mileage, winter tyre, route, handover, prepayment, cancellation, and confirmation facts.',
		inputSchema: { type: 'object', properties: {}, additionalProperties: false },
		annotations: { readOnlyHint: true, untrustedContentHint: false },
		execute: async () => ({
			security_deposit: 'None; no card preauthorization hold.',
			insurance: 'Full insurance included with no deductible. Wheels and windshield are covered; tyres and the interior are excluded. Single-vehicle accidents are covered. Third-party liability limit: 30,000 GEL.',
			mileage: 'Unlimited within Georgia.',
			winter_tyres: 'Included free in winter.',
			route_policy: 'All vehicles and routes unless bad weather, an official road closure, or damaged-road conditions apply. Current safety and opening status must be checked before departure.',
			prepayment: '10% confirms the booking after availability is checked.',
			cancellation: 'At least 30 days before pickup, 50% of the prepayment is refunded. With fewer than 30 days remaining, the prepayment is non-refundable.',
			confirmation: 'A quote or booking request is not a confirmed reservation.'
		})
	});
	register({
		name: 'list_geolander_fleet',
		title: 'List exact Geolander cars and prices',
		description: 'Fetch the current machine-readable exact-car fleet and positive published rate tables. Missing prices are labelled price on request, never zero.',
		inputSchema: { type: 'object', properties: {}, additionalProperties: false },
		annotations: { readOnlyHint: true, untrustedContentHint: true },
		execute: async () => {
			const response = await fetch(<?php echo wp_json_encode( $pricing_url ); ?>, { headers: { Accept: 'text/markdown' } });
			if (!response.ok) throw new Error(`Fleet price list unavailable (HTTP ${response.status}).`);
			return { pricing_markdown: await response.text(), source: response.url };
		}
	});
	register({
		name: 'get_geolander_quote',
		title: 'Get a date-specific Geolander quote',
		description: 'Calculate a read-only quote for one published exact car, dates, pickup, and return. This does not confirm availability or create a booking.',
		inputSchema: {
			type: 'object',
			additionalProperties: false,
			required: ['car', 'from', 'to'],
			properties: {
				car: { type: 'integer', minimum: 1, description: 'Published car ID from the fleet.' },
				from: { type: 'string', format: 'date' },
				to: { type: 'string', format: 'date' },
				pickup: { type: 'string', enum: ['tbilisi_office', 'tbilisi_airport', 'kutaisi_airport', 'batumi_airport'], default: 'tbilisi_office' },
				return: { type: 'string', enum: ['tbilisi_office', 'tbilisi_airport', 'kutaisi_airport', 'batumi_airport'], default: 'tbilisi_office' }
			}
		},
		annotations: { readOnlyHint: true, untrustedContentHint: false },
		execute: async (input) => {
			const url = new URL(<?php echo wp_json_encode( $quote_url ); ?>);
			for (const [key, value] of Object.entries(input)) if (value !== undefined && value !== '') url.searchParams.set(key, String(value));
			const response = await fetch(url, { headers: { Accept: 'application/json' } });
			const body = await response.json();
			if (!response.ok) throw new Error(body?.message || `Quote unavailable (HTTP ${response.status}).`);
			return { ...body, availability_status: 'not_confirmed', reservation_status: 'not_created' };
		}
	});
})();
</script>
		<?php
	}
}
