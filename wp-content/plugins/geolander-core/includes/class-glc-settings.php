<?php
/**
 * Site-wide business settings (Settings → Geolander).
 * One option array; everything the theme and gateways need to know
 * about the business lives here, editable without code.
 */

defined( 'ABSPATH' ) || exit;

class GLC_Settings {

	private const OPTION = 'glc_settings';

	private const DEFAULTS = [
		'phone'               => '+995551330414',
		'whatsapp_number'     => '+995551330414',
		'email'               => 'info@geo-lander.com',
		// NAP must match the Google Business Profile exactly.
		'business_name'       => 'Geolander car rental',
		'legal_name'          => '',
		'georgian_id'         => '',
		'address'             => '8/5 Vedzini Street',
		'address_locality'    => 'Tbilisi',
		'postal_code'         => '0108',
		'business_hours'      => '24/7',
		'google_maps_url'     => 'https://maps.app.goo.gl/XuY47hmvdEau9HoS9',
		'google_rating'       => '5.0',
		'office_district'     => 'Mtatsminda',
		'instagram'           => 'https://instagram.com/geolander',
		'facebook'            => 'https://facebook.com/geolander',
		'latitude'            => '41.6980427',
		'longitude'           => '44.7934697',
		'payment_provider'    => '',      // '' = WhatsApp only | 'bog_ipay'
		'payment_currency'    => 'USD',
		'prepayment_percent'  => '10',
		// Delivery charges in USD, per direction: one for handing the car over and
		// one for taking it back. Owner-set 2026-10-11. The booking form, the rental
		// terms, the airport-cost table, llms.txt and the agent manifests all read
		// these, so no sentence anywhere carries a fee as a typed number.
		'tbilisi_city_each_way'    => '10',
		'tbilisi_airport_each_way' => '20',
		'kutaisi_each_way'    => '80',
		'batumi_each_way'     => '100',
		// Headline price range. Single source of truth: the hero, SEO titles,
		// llms.txt and the AutoRental schema all read these, so the advertised
		// range can never drift between them again.
		'price_min'           => '26',
		'price_max'           => '90',
		'bog_client_id'       => '',
		'bog_client_secret'   => '',
		// Google tags — paste IDs when campaigns are created.
		'ga4_id'              => '',      // G-XXXXXXX
		'ads_id'              => '',      // AW-XXXXXXX
		'ads_conversion_label'=> '',      // conversion label for booking_request
		// Search engines — see GLC_IndexNow and GLC_Redirects. Empty = the seeded defaults.
		'indexnow_key'        => '',      // 8–128 chars [A-Za-z0-9-]; generated automatically when empty
		'redirects'           => '',      // one "/old/ -> /new/" per line; overrides GLC_Redirects::DEFAULT_REDIRECTS
		'noindex_slugs'       => '',      // comma-separated page slugs kept out of the index and sitemap
		// Early or late handover: the hours with no extra charge, the charge outside
		// them (per pickup and per return), and the time assumed for a request that
		// names none. A single car may carry its own amount; the hours are site-wide.
		'handover_window_start' => '10:00',
		'handover_window_end'   => '20:00',
		'out_of_hours_fee'      => '15',   // '' = not decided: never quoted, never shown as free | '0' = no charge
		'handover_default_time' => '12:00',
		// What GLC_Price_Store accepts when a car's prices or fees are saved. '' = no limit.
		// The first three were constants in the Localrent import script.
		'rate_min_plausible'    => '5',
		'rate_max_plausible'    => '300',
		'fee_max_plausible'     => '500',
		'season_max_count'      => '24',   // a longer form would run past PHP's max_input_vars
		// The public price tables. '0' = hidden on a car page / one line per car on the
		// long-term page; '1' = the full tables, back without a deploy.
		'price_grid_car_page'   => '0',
		'price_grid_long_term'  => '0',
		// Photos. The first two bound what a partner may upload; the next three what a gallery may hold.
		'partner_upload_max_mb'   => '10',
		'partner_image_types'     => 'jpeg, png, webp', // as in image/jpeg; an upload is stored as a JPEG whatever it came as
		'partner_image_min_width' => '1600',
		'gallery_max_photos'      => '40',
		'photo_alt_max_chars'     => '125',
		// What a car's details may hold when they are saved (GLC_Price_Store, kind "specs").
		'spec_seats_min'            => '1',
		'spec_seats_max'            => '9',
		'spec_transmissions'        => 'automatic, manual',
		'spec_fuel_types'           => 'gasoline, diesel, hybrid, electric',
		'spec_drivetrain_max_chars' => '12',
		'spec_color_max_chars'      => '24',
		'spec_license_max_chars'    => '4',
		'partner_email_copy'    => '0',    // '1' also needs SMTP
		// The panel and the owner's screens. The site's own timezone is UTC and stays
		// UTC (published dates depend on it), so a time printed for a person, and
		// "today" for a sample quote, use this one instead.
		'display_timezone'      => 'Asia/Tbilisi',
		// The sample rentals a preview prices before a save: one per line,
		// "MM-DD days", optionally followed by "pickup return" and "HH:MM HH:MM".
		'preview_samples'       => "07-15 3\n08-01 7\n01-10 31\n08-01 7 kutaisi_airport batumi_airport 09:00 21:00",
		'history_rows'          => '20',   // changes listed on a car's screen; '' = all
		'readonly_excerpt_chars' => '200', // how much of a car's description its screen shows to read
		'counter_months'        => '12',   // months shown on the message count; older ones are added up
		// A change to several cars waits this long between its preview and its Apply; after that it is made again.
		'plan_keep_minutes'     => '30',
		// The Excel upload. The first is the limit a person is told; it must stay under the web server's own
		// (128 KB on admin-post.php, though the upload does not go there). The others bound what one file may
		// unfold to once opened, and how many reasons a refused file lists.
		'xlsx_max_kb'           => '100',
		'xlsx_max_part_kb'      => '2048',
		'xlsx_max_rows'         => '5000',
		'xlsx_max_reasons'      => '50',
		// Landing pages. The seed list is history: it gave the two pages that once
		// had their URL written in PHP a path of their own, once, at the first
		// request after that code was replaced (GLC_Landings::upgrade_to_1). The
		// pages carry their own paths now and emptying this changes nothing.
		'landing_paths_seed'      => "car-rental-kazbegi => car-rental-kazbegi\nfleet/4x4-suv => 4x4-suv",
		'landing_path_depth'      => '3',
		// Addresses another part of the site answers on, so a landing page cannot
		// take one. Only a whole path matches: "fleet" is reserved, "fleet/minivan" is free.
		'landing_reserved_paths'  => 'fleet, places, news, blog, guides, contact, terms, about, developers, music',
		'landing_layout_category' => '',  // '' = every pattern meant for the inserter
		// Whether a content editor may also build layouts (Appearance → Editor).
		'editor_can_edit_layouts' => '0',
		// Pictures. A WebP (and, when asked for, an AVIF) is made beside every new
		// upload and beside each size of it, and browsers that take the format are
		// served it. The original file is never touched, so nothing can be lost.
		'image_webp'            => '1',
		'image_webp_quality'    => '76',
		'image_avif'            => '0',   // slower to make than WebP; on when the gain is worth the upload wait
		'image_avif_quality'    => '55',
		'image_max_width'       => '1920', // the widest size the theme serves; '' = whatever was uploaded
		// Page speed. Measured by Google's PageSpeed Insights, which needs a key of
		// its own: until one is pasted here nothing is measured and the screen says so.
		'speed_api_key'         => '',
		'speed_check_url'       => '',    // '' = the home page
		'speed_check_hours'     => '24',
		'speed_strategy'        => 'mobile',
		'speed_min_score'       => '70',
		'speed_history_points'  => '60',
		// Security. On 2026-11-06 this site was found to have been held by someone
		// else since 18-19 October: the owner's login name was public, the login
		// form took unlimited guesses, and four application passwords were minted
		// on the owner's account - a way in that survives every password change.
		// These close that road. The defaults are the safe end; every one of them
		// can be changed here without a deploy. See class-glc-security.php.
		'security_app_passwords'     => '0',   // '1' allows them again, for a real integration
		'security_hide_users'        => '1',   // author pages and the REST user list answer 404
		'security_login_max_fails'   => '8',   // failed sign-ins from one address before it waits; 0 = no limit
		'security_login_lockout_min' => '15',
		'security_alert_email'       => '',    // '' = the site's own admin email
		'security_log_points'        => '200',
		// Public traffic arrives through the Cloudflare Tunnel, so the address the
		// web server sees is the tunnel's. The real one comes in this header.
		// '' means trust the connection itself, which is right with nothing in front.
		'security_trusted_ip_header' => 'CF-Connecting-IP',
		// What the daily check expects to find. Counting is the only test that
		// catches a change written straight into the database, where no WordPress
		// hook ever fires. Raise a number here when the site legitimately grows.
		'security_expect_admins'        => '1',
		'security_expect_app_passwords' => '0',
		'security_expect_posts'         => '0',   // this site publishes news as glc_news, so `post` should stay empty
	];

	/**
	 * Fields that are more than free text. A value that does not fit is not saved:
	 * the one before it stays and the screen says why.
	 */
	private const FORMATS = [
		'handover_window_start'   => 'time',
		'handover_window_end'     => 'time',
		'handover_default_time'   => 'time',
		'out_of_hours_fee'        => 'amount',
		'rate_min_plausible'      => 'amount',
		'rate_max_plausible'      => 'amount',
		'fee_max_plausible'       => 'amount',
		'partner_upload_max_mb'   => 'amount',
		'season_max_count'        => 'whole',
		'partner_image_min_width' => 'whole',
		'partner_image_types'     => 'list',
		'gallery_max_photos'      => 'whole',
		'photo_alt_max_chars'     => 'count',
		'spec_seats_min'            => 'count',
		'spec_seats_max'            => 'count',
		'spec_transmissions'        => 'list',
		'spec_fuel_types'           => 'list',
		'spec_drivetrain_max_chars' => 'count',
		'spec_color_max_chars'      => 'count',
		'spec_license_max_chars'    => 'count',
		'readonly_excerpt_chars'    => 'count',
		'price_grid_car_page'     => 'switch',
		'price_grid_long_term'    => 'switch',
		'partner_email_copy'      => 'switch',
		'display_timezone'        => 'timezone',
		'preview_samples'         => 'samples',
		'history_rows'            => 'whole',
		'counter_months'          => 'whole',
		'plan_keep_minutes'       => 'count',
		'xlsx_max_kb'             => 'count',
		'xlsx_max_part_kb'        => 'count',
		'xlsx_max_rows'           => 'count',
		'xlsx_max_reasons'        => 'count',
		'landing_path_depth'      => 'count',
		'landing_reserved_paths'  => 'list',
		'editor_can_edit_layouts' => 'switch',
		'image_webp'              => 'switch',
		'image_webp_quality'      => 'count',
		'image_avif'              => 'switch',
		'image_avif_quality'      => 'count',
		'image_max_width'         => 'whole',
		'speed_check_hours'       => 'count',
		'speed_min_score'         => 'count',
		'speed_history_points'    => 'count',
	];

	/**
	 * Settings that exist before the code that reads them is deployed. The screen
	 * says so next to each, so nobody changes one and waits for an effect.
	 * Remove a key from this list in the change that starts reading it.
	 */
	private const NOT_READ_YET = [];

	/** Multi-line settings rendered as a textarea and sanitised line-safe. */
	private const MULTILINE = [ 'redirects', 'preview_samples', 'landing_paths_seed' ];

	/** How a time is written for a person on the admin screens and in the change log. */
	public const TIME_FORMAT = 'Y-m-d H:i';

	public static function init() {
		add_action( 'admin_menu', [ __CLASS__, 'menu' ] );
		add_action( 'admin_init', [ __CLASS__, 'register' ] );
		add_shortcode( 'geolander_business_identity', [ __CLASS__, 'business_identity_shortcode' ] );
	}

	public static function get( string $key, $default = null ) {
		$options = get_option( self::OPTION, [] );
		return $options[ $key ] ?? self::DEFAULTS[ $key ] ?? $default;
	}

	/**
	 * The timezone people who run the cars read their times in. Never the
	 * WordPress site timezone: that one is UTC on the live site and has to stay so.
	 */
	public static function timezone(): DateTimeZone {
		try {
			return new DateTimeZone( (string) self::get( 'display_timezone' ) );
		} catch ( Exception ) {
			return new DateTimeZone( self::DEFAULTS['display_timezone'] );
		}
	}

	/**
	 * A setting that is a list of words ("jpeg, png, webp"), as the words: lower
	 * case, each once, in the order written.
	 */
	public static function words( string $key ): array {
		$found = preg_split( '/[\s,]+/', strtolower( (string) self::get( $key, '' ) ), -1, PREG_SPLIT_NO_EMPTY );
		return array_values( array_unique( $found ?: [] ) );
	}

	/** A setting that is a whole number, as the number; null when the field is empty or is not one (no limit). */
	public static function whole( string $key ): ?int {
		$raw = self::get( $key, '' );
		$raw = is_scalar( $raw ) ? trim( (string) $raw ) : '';
		return ctype_digit( $raw ) ? (int) $raw : null;
	}

	/** A moment (a Unix time, or a GMT "Y-m-d H:i:s" as the database keeps it) written for a person. */
	public static function local_time( $moment, string $format = self::TIME_FORMAT ): string {
		$stamp = is_int( $moment ) ? $moment : strtotime( (string) $moment . ' UTC' );
		if ( ! $stamp ) {
			return '';
		}
		return ( new DateTimeImmutable( '@' . $stamp ) )->setTimezone( self::timezone() )->format( $format );
	}

	/** Public identity block. Legal fields stay absent until the owner supplies them. */
	public static function business_identity_shortcode(): string {
		$rows = [
			[ 'Trading name', self::get( 'business_name' ), '' ],
			[ 'Official website', untrailingslashit( home_url( '/' ) ), home_url( '/' ) ],
			[ 'Official reservation email', self::get( 'email' ), 'mailto:' . self::get( 'email' ) ],
			[ 'Phone and WhatsApp', self::get( 'phone' ), 'tel:' . preg_replace( '/[^+0-9]/', '', self::get( 'phone' ) ) ],
			[ 'Tbilisi office', self::get( 'address' ) . ', ' . self::get( 'office_district' ) . ', ' . self::get( 'address_locality' ) . ' ' . self::get( 'postal_code' ) . ', Georgia', self::get( 'google_maps_url' ) ],
		];
		if ( trim( (string) self::get( 'legal_name' ) ) ) {
			$rows[] = [ 'Registered legal name', self::get( 'legal_name' ), '' ];
		}
		if ( trim( (string) self::get( 'georgian_id' ) ) ) {
			$rows[] = [ 'Georgian identification number', self::get( 'georgian_id' ), '' ];
		}

		$out = '<dl class="glc-business-identity" style="display:grid;grid-template-columns:minmax(12rem,0.45fr) 1fr;gap:0.65rem 1rem;">';
		foreach ( $rows as [ $label, $value, $href ] ) {
			$out .= '<dt><strong>' . esc_html( $label ) . '</strong></dt><dd style="margin:0;">';
			$out .= $href ? '<a href="' . esc_url( $href ) . '" rel="noopener">' . esc_html( $value ) . '</a>' : esc_html( $value );
			$out .= '</dd>';
		}
		return $out . '</dl><p>Geolander publishes email only on the <strong>@geo-lander.com</strong> domain. An address on the separate geolander.com domain is not a contact published by this website.</p>';
	}

	public static function menu() {
		add_options_page(
			__( 'Geolander Settings', 'geolander' ),
			__( 'Geolander', 'geolander' ),
			'manage_options',
			'geolander',
			[ __CLASS__, 'render' ]
		);
	}

	public static function register() {
		register_setting( 'glc_settings_group', self::OPTION, [
			'type'              => 'array',
			'sanitize_callback' => [ __CLASS__, 'sanitize' ],
		] );
	}

	public static function sanitize( $input ): array {
		$clean  = [];
		$stored = (array) get_option( self::OPTION, [] );
		$before = static fn( string $key ): string => (string) ( $stored[ $key ] ?? self::DEFAULTS[ $key ] );
		foreach ( array_keys( self::DEFAULTS ) as $key ) {
			$clean[ $key ] = isset( $input[ $key ] )
				? ( in_array( $key, self::MULTILINE, true )
					? sanitize_textarea_field( (string) $input[ $key ] )
					: sanitize_text_field( (string) $input[ $key ] ) )
				: self::DEFAULTS[ $key ];
			$format = self::FORMATS[ $key ] ?? '';
			if ( $format && ! self::well_formed( $format, $clean[ $key ] ) ) {
				$clean[ $key ] = $before( $key );
				self::complain( $key, sprintf( glc_admin_t( 'settings_bad_' . $format ), glc_admin_t( 'setting_' . $key ) ) );
			}
		}
		// Times are compared as text ("09:30" < "10:00"), which holds because both are zero-padded HH:MM.
		if ( $clean['handover_window_start'] >= $clean['handover_window_end'] ) {
			$clean['handover_window_start'] = $before( 'handover_window_start' );
			$clean['handover_window_end']   = $before( 'handover_window_end' );
			self::complain( 'handover_window', glc_admin_t( 'settings_bad_window' ) );
		}
		// Both are whole numbers above zero by now (format "count").
		if ( (int) $clean['spec_seats_min'] > (int) $clean['spec_seats_max'] ) {
			$clean['spec_seats_min'] = $before( 'spec_seats_min' );
			$clean['spec_seats_max'] = $before( 'spec_seats_max' );
			self::complain( 'spec_seats', glc_admin_t( 'settings_bad_seats' ) );
		}
		return $clean;
	}

	private static function well_formed( string $format, string $value ): bool {
		return match ( $format ) {
			'time'   => (bool) preg_match( '/^([01][0-9]|2[0-3]):[0-5][0-9]$/', $value ),
			'amount' => '' === $value || (bool) preg_match( '/^\d+(\.\d{1,2})?$/', $value ),
			'whole'  => '' === $value || ctype_digit( $value ),
			'count'  => ctype_digit( $value ) && (int) $value > 0, // a limit that cannot be left empty or set to nothing
			'switch' => in_array( $value, [ '0', '1' ], true ),
			'timezone' => in_array( $value, timezone_identifiers_list(), true ),
			'samples'  => null !== GLC_Quote_Check::parse_samples( $value ),
			// Words separated by commas: the kinds of image, the gearboxes, the fuels. Never empty: nothing could then be chosen.
			'list'     => (bool) preg_match( '/^[a-z0-9][a-z0-9_-]*(\s*,\s*[a-z0-9][a-z0-9_-]*)*$/', $value ),
			default  => true,
		};
	}

	private static function complain( string $code, string $message ): void {
		if ( function_exists( 'add_settings_error' ) ) {
			add_settings_error( self::OPTION, 'glc_' . $code, $message );
		}
	}

	/** [ key => label ] for fields whose text is in the admin catalogue (English and Georgian). */
	private static function labels( array $keys ): array {
		$out = [];
		foreach ( $keys as $key ) {
			$out[ $key ] = glc_admin_t( 'setting_' . $key );
		}
		return $out;
	}

	public static function render() {
		$fields = [
			__( 'Contact / NAP (must match Google Business Profile exactly)', 'geolander' ) => [
				'business_name'    => __( 'Business name (as on GBP)', 'geolander' ),
				'legal_name'       => __( 'Registered legal name (leave empty until verified)', 'geolander' ),
				'georgian_id'      => __( 'Georgian identification number (leave empty until verified)', 'geolander' ),
				'phone'            => __( 'Phone', 'geolander' ),
				'whatsapp_number'  => __( 'WhatsApp number', 'geolander' ),
				'email'            => __( 'Email', 'geolander' ),
				'address'          => __( 'Street address', 'geolander' ),
				'address_locality' => __( 'City', 'geolander' ),
				'postal_code'      => __( 'Postal code', 'geolander' ),
				'business_hours'   => __( 'Business hours', 'geolander' ),
				'google_maps_url'  => __( 'Google Maps URL', 'geolander' ),
				'google_rating'    => __( 'Google Maps rating (leave empty to hide)', 'geolander' ),
				'office_district'  => __( 'Office district', 'geolander' ),
				'latitude'         => __( 'Latitude', 'geolander' ),
				'longitude'        => __( 'Longitude', 'geolander' ),
			],
			__( 'Google tags', 'geolander' ) => [
				'ga4_id'               => __( 'GA4 measurement ID (G-…)', 'geolander' ),
				'ads_id'               => __( 'Google Ads tag ID (AW-…)', 'geolander' ),
				'ads_conversion_label' => __( 'Ads conversion label (booking request)', 'geolander' ),
			],
			__( 'Search engines', 'geolander' ) => [
				'indexnow_key'  => __( 'IndexNow key (leave empty to use the generated one shown below)', 'geolander' ),
				'redirects'     => __( 'Permanent redirects — one "/old/ -> /new/" per line (empty = built-in defaults)', 'geolander' ),
				'noindex_slugs' => __( 'Page slugs kept out of search and the sitemap, comma-separated (empty = built-in default)', 'geolander' ),
			],
			__( 'Social', 'geolander' ) => [
				'instagram' => __( 'Instagram URL', 'geolander' ),
				'facebook'  => __( 'Facebook URL', 'geolander' ),
			],
			__( 'Pricing', 'geolander' ) => [
				'price_min' => __( 'Headline price range — from (per day)', 'geolander' ),
				'price_max' => __( 'Headline price range — to (per day)', 'geolander' ),
				'prepayment_percent' => __( 'Booking prepayment (%)', 'geolander' ),
				'tbilisi_city_each_way'    => __( 'Tbilisi city address — delivery or collection (USD each way)', 'geolander' ),
				'tbilisi_airport_each_way' => __( 'Tbilisi Airport (TBS) — delivery or collection (USD each way)', 'geolander' ),
				'kutaisi_each_way'   => __( 'Kutaisi — airport (KUT) or city address (USD each way)', 'geolander' ),
				'batumi_each_way'    => __( 'Batumi — airport (BUS) or city address (USD each way)', 'geolander' ),
			],
			glc_admin_t( 'settings_section_handover' ) => self::labels( [ 'handover_window_start', 'handover_window_end', 'out_of_hours_fee', 'handover_default_time' ] ),
			glc_admin_t( 'settings_section_limits' )   => self::labels( [ 'rate_min_plausible', 'rate_max_plausible', 'fee_max_plausible', 'season_max_count' ] ),
			glc_admin_t( 'settings_section_grid' )     => self::labels( [ 'price_grid_car_page', 'price_grid_long_term' ] ),
			glc_admin_t( 'settings_section_uploads' )  => self::labels( [ 'partner_upload_max_mb', 'partner_image_types', 'partner_image_min_width', 'gallery_max_photos', 'photo_alt_max_chars' ] ),
			glc_admin_t( 'settings_section_specs' )    => self::labels( [ 'spec_seats_min', 'spec_seats_max', 'spec_transmissions', 'spec_fuel_types', 'spec_drivetrain_max_chars', 'spec_color_max_chars', 'spec_license_max_chars' ] ),
			glc_admin_t( 'settings_section_panel' )    => self::labels( [ 'display_timezone', 'preview_samples', 'history_rows', 'readonly_excerpt_chars', 'counter_months', 'plan_keep_minutes', 'xlsx_max_kb', 'xlsx_max_part_kb', 'xlsx_max_rows', 'xlsx_max_reasons' ] ),
			glc_admin_t( 'settings_section_landing' ) => self::labels( [ 'landing_path_depth', 'landing_reserved_paths' ] ) + [
				'landing_layout_category' => __( 'Only offer layouts from this pattern category (empty = every pattern)', 'geolander' ),
				'landing_paths_seed'      => __( 'First-install paths — one "path => page slug" per line. Already applied; kept as a record.', 'geolander' ),
			],
			glc_admin_t( 'settings_section_people' )   => self::labels( [ 'editor_can_edit_layouts' ] ),
			glc_admin_t( 'settings_section_pictures' ) => self::labels( [ 'image_webp', 'image_webp_quality', 'image_avif', 'image_avif_quality', 'image_max_width' ] ),
			glc_admin_t( 'settings_section_speed' )    => self::labels( [ 'speed_check_hours', 'speed_min_score', 'speed_history_points' ] ) + [
				'speed_api_key'   => __( 'PageSpeed Insights API key (empty = nothing is measured)', 'geolander' ),
				'speed_check_url' => __( 'Page to measure (empty = the home page)', 'geolander' ),
				'speed_strategy'  => __( 'Measure as "mobile" or "desktop"', 'geolander' ),
			],
			__( 'Payments', 'geolander' ) => [
				'payment_provider'  => __( 'Payment provider (empty = WhatsApp requests, "bog_ipay" = BOG iPay)', 'geolander' ),
				'payment_currency'  => __( 'Currency', 'geolander' ),
				'bog_client_id'     => __( 'BOG iPay client ID', 'geolander' ),
				'bog_client_secret' => __( 'BOG iPay client secret', 'geolander' ),
			] + self::labels( [ 'partner_email_copy' ] ),
		];
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Geolander Settings', 'geolander' ); ?></h1>
			<form method="post" action="options.php">
				<?php settings_fields( 'glc_settings_group' ); ?>
				<?php foreach ( $fields as $section => $rows ) : ?>
					<h2><?php echo esc_html( $section ); ?></h2>
					<table class="form-table" role="presentation">
						<?php foreach ( $rows as $key => $label ) : ?>
							<tr>
								<th scope="row"><label for="glc-<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></label></th>
								<td>
									<?php if ( in_array( $key, self::MULTILINE, true ) ) : ?>
										<textarea
											class="large-text code"
											rows="4"
											id="glc-<?php echo esc_attr( $key ); ?>"
											name="<?php echo esc_attr( self::OPTION . '[' . $key . ']' ); ?>"
										><?php echo esc_textarea( (string) self::get( $key, '' ) ); ?></textarea>
									<?php elseif ( 'switch' === ( self::FORMATS[ $key ] ?? '' ) ) : ?>
										<select id="glc-<?php echo esc_attr( $key ); ?>" name="<?php echo esc_attr( self::OPTION . '[' . $key . ']' ); ?>">
											<?php foreach ( [ '0', '1' ] as $glc_choice ) : ?>
												<option value="<?php echo esc_attr( $glc_choice ); ?>"<?php selected( (string) self::get( $key, '' ), $glc_choice ); ?>><?php echo esc_html( glc_admin_t( 'setting_' . $key . '_' . $glc_choice ) ); ?></option>
											<?php endforeach; ?>
										</select>
									<?php else : ?>
										<input
											type="<?php echo 'bog_client_secret' === $key ? 'password' : 'text'; ?>"
											class="regular-text"
											id="glc-<?php echo esc_attr( $key ); ?>"
											name="<?php echo esc_attr( self::OPTION . '[' . $key . ']' ); ?>"
											value="<?php echo esc_attr( self::get( $key, '' ) ); ?>"
										/>
									<?php endif; ?>
									<?php if ( 'batumi_each_way' === $key ) : ?>
										<p class="description"><?php esc_html_e( 'Collection and return at the Mtatsminda office are free and have no setting. Each fee is charged once per direction: a pickup and a return at the same place are charged twice. 0 makes a place free, and customers are told so. An empty fee is not decided: it is shown as "confirm with us", never as free, and a car without a fee of its own cannot be booked online for that place until a number is set.', 'geolander' ); ?></p>
									<?php endif; ?>
									<?php if ( GLC_Admin_Strings::has( 'setting_' . $key . '_help' ) ) : ?>
										<p class="description"><?php echo esc_html( glc_admin_t( 'setting_' . $key . '_help' ) ); ?></p>
									<?php endif; ?>
									<?php if ( in_array( $key, self::NOT_READ_YET, true ) ) : ?>
										<p class="description"><em><?php echo esc_html( glc_admin_t( 'settings_not_read_yet' ) ); ?></em></p>
									<?php endif; ?>
									<?php if ( 'indexnow_key' === $key && class_exists( 'GLC_IndexNow' ) ) : ?>
										<p class="description">
											<?php esc_html_e( 'Active key file:', 'geolander' ); ?>
											<a href="<?php echo esc_url( GLC_IndexNow::key_url() ); ?>" target="_blank" rel="noopener"><?php echo esc_html( GLC_IndexNow::key_url() ); ?></a>
											— <?php esc_html_e( 'paste the key into Bing Webmaster Tools if asked. Recent pings:', 'geolander' ); ?>
											<?php $glc_log = GLC_IndexNow::recent(); ?>
											<?php if ( ! $glc_log ) : ?><em><?php esc_html_e( 'none yet', 'geolander' ); ?></em><?php endif; ?>
										</p>
										<?php if ( $glc_log ) : ?>
											<ul class="description" style="margin:0;font-family:monospace;">
												<?php foreach ( array_slice( $glc_log, 0, 5 ) as $glc_row ) : ?>
													<li><?php echo esc_html( sprintf( '%s · %s · %d URLs · HTTP %s', $glc_row['time'], $glc_row['reason'], $glc_row['count'], $glc_row['status'] ) ); ?></li>
												<?php endforeach; ?>
											</ul>
										<?php endif; ?>
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
					</table>
				<?php endforeach; ?>
				<?php submit_button(); ?>
			</form>
			<?php
			/**
			 * Below the form: what the settings above have measured or recorded.
			 * A reading is not a setting, so it is not a field; GLC_Speed prints
			 * its trend here rather than this screen having to know about it.
			 */
			do_action( 'glc_settings_after_form' );
			?>
		</div>
		<?php
	}
}
