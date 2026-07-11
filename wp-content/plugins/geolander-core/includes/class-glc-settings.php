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
		'address'             => '8/5 Vedzini Street',
		'address_locality'    => 'Tbilisi',
		'postal_code'         => '0108',
		'business_hours'      => '24/7',
		'google_maps_url'     => 'https://maps.app.goo.gl/WKGqAsFnKuGPK49E7',
		'instagram'           => 'https://instagram.com/geolander',
		'facebook'            => 'https://facebook.com/geolander',
		'latitude'            => '41.6980427',
		'longitude'           => '44.7934697',
		'payment_provider'    => '',      // '' = WhatsApp only | 'bog_ipay'
		'payment_currency'    => 'USD',
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
	}

	public static function get( string $key, $default = null ) {
		$options = get_option( self::OPTION, [] );
		return $options[ $key ] ?? self::DEFAULTS[ $key ] ?? $default;
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
				'phone'            => __( 'Phone', 'geolander' ),
				'whatsapp_number'  => __( 'WhatsApp number', 'geolander' ),
				'email'            => __( 'Email', 'geolander' ),
				'address'          => __( 'Street address', 'geolander' ),
				'address_locality' => __( 'City', 'geolander' ),
				'postal_code'      => __( 'Postal code', 'geolander' ),
				'business_hours'   => __( 'Business hours', 'geolander' ),
				'google_maps_url'  => __( 'Google Maps URL', 'geolander' ),
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
