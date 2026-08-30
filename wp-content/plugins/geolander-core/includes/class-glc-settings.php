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
		'kutaisi_each_way'    => '68',
		'batumi_each_way'     => '98',
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
	];

	public static function init() {
		add_action( 'admin_menu', [ __CLASS__, 'menu' ] );
		add_action( 'admin_init', [ __CLASS__, 'register' ] );
		add_shortcode( 'geolander_business_identity', [ __CLASS__, 'business_identity_shortcode' ] );
	}

	public static function get( string $key, $default = null ) {
		$options = get_option( self::OPTION, [] );
		return $options[ $key ] ?? self::DEFAULTS[ $key ] ?? $default;
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
		$clean = [];
		foreach ( array_keys( self::DEFAULTS ) as $key ) {
			$clean[ $key ] = isset( $input[ $key ] ) ? sanitize_text_field( (string) $input[ $key ] ) : self::DEFAULTS[ $key ];
		}
		return $clean;
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
			__( 'Social', 'geolander' ) => [
				'instagram' => __( 'Instagram URL', 'geolander' ),
				'facebook'  => __( 'Facebook URL', 'geolander' ),
			],
			__( 'Pricing', 'geolander' ) => [
				'price_min' => __( 'Headline price range — from (per day)', 'geolander' ),
				'price_max' => __( 'Headline price range — to (per day)', 'geolander' ),
				'prepayment_percent' => __( 'Booking prepayment (%)', 'geolander' ),
				'kutaisi_each_way'   => __( 'Kutaisi Airport pickup or return (USD each way)', 'geolander' ),
				'batumi_each_way'    => __( 'Batumi Airport pickup or return (USD each way)', 'geolander' ),
			],
			__( 'Payments', 'geolander' ) => [
				'payment_provider'  => __( 'Payment provider (empty = WhatsApp requests, "bog_ipay" = BOG iPay)', 'geolander' ),
				'payment_currency'  => __( 'Currency', 'geolander' ),
				'bog_client_id'     => __( 'BOG iPay client ID', 'geolander' ),
				'bog_client_secret' => __( 'BOG iPay client secret', 'geolander' ),
			],
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
									<input
										type="<?php echo 'bog_client_secret' === $key ? 'password' : 'text'; ?>"
										class="regular-text"
										id="glc-<?php echo esc_attr( $key ); ?>"
										name="<?php echo esc_attr( self::OPTION . '[' . $key . ']' ); ?>"
										value="<?php echo esc_attr( self::get( $key, '' ) ); ?>"
									/>
								</td>
							</tr>
						<?php endforeach; ?>
					</table>
				<?php endforeach; ?>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}
}
