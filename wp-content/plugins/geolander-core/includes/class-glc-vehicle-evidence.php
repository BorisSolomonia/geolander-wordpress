<?php
/** Dated, owner-supplied vehicle facts. Never fill gaps with generated claims. */
defined( 'ABSPATH' ) || exit;
class GLC_Vehicle_Evidence {
	private const FIELDS = [
		'glc_evidence_checked_on' => [ 'Checked on (YYYY-MM-DD; required to display evidence)', 'date' ],
		'glc_odometer_km' => [ 'Odometer reading in km (as of the check date)', 'number' ],
		'glc_last_service_on' => [ 'Last documented service date (YYYY-MM-DD)', 'date' ],
		'glc_tyres_note' => [ 'Observed tyre brand/model/markings (English; not a safety guarantee)', 'text' ],
		'glc_luggage_note' => [ 'Measured luggage space / tested bag dimensions (English)', 'text' ],
	];
	public static function init(): void {
		add_action( 'add_meta_boxes', static function () {
			add_meta_box( 'glc_vehicle_evidence', 'Dated vehicle evidence — verified facts only', [ __CLASS__, 'admin' ], 'car', 'normal' );
		} );
		add_action( 'save_post_car', [ __CLASS__, 'save' ] );
		add_action( 'init', static function () {
			register_block_type( 'geolander/vehicle-evidence', [ 'render_callback' => [ __CLASS__, 'render' ] ] );
		} );
	}
	public static function date( $value ): string {
		if ( ! is_string( $value ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ) { return ''; }
		[ $y, $m, $d ] = array_map( 'intval', explode( '-', $value ) );
		return checkdate( $m, $d, $y ) && $value <= current_time( 'Y-m-d' ) ? $value : '';
	}
	public static function normalize( array $input ): array {
		$out = [];
		foreach ( self::FIELDS as $key => [ $label, $type ] ) {
			$value = $input[ $key ] ?? '';
			if ( ! is_scalar( $value ) ) { $value = ''; }
			$value = (string) $value;
			$out[ $key ] = 'date' === $type ? self::date( $value ) : ( 'number' === $type
				? ( ctype_digit( $value ) && (int) $value > 0 && (int) $value <= 9999999 ? (string) (int) $value : '' )
				: sanitize_text_field( $value ) );
		}
		if ( $out['glc_last_service_on'] > $out['glc_evidence_checked_on'] ) { $out['glc_last_service_on'] = ''; }
		return $out;
	}
	public static function admin( WP_Post $post ): void {
		wp_nonce_field( 'glc_vehicle_evidence', 'glc_evidence_nonce' );
		echo '<p>Leave unknowns blank. Use actual records, not estimates. The section is hidden without a valid check date and at least one fact. Text observations display in English; no claim of current roadworthiness is generated.</p>';
		foreach ( self::FIELDS as $key => [ $label, $type ] ) {
			printf( '<p><label for="%1$s">%2$s</label><br><input class="widefat" id="%1$s" name="%1$s" type="%3$s" value="%4$s"></p>', esc_attr( $key ), esc_html( $label ), esc_attr( $type ), esc_attr( get_post_meta( $post->ID, $key, true ) ) );
		}
	}
	public static function save( int $id ): void {
		if ( ! isset( $_POST['glc_evidence_nonce'] ) || ! is_string( $_POST['glc_evidence_nonce'] )
			|| ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['glc_evidence_nonce'] ) ), 'glc_vehicle_evidence' )
			|| ! current_user_can( 'edit_post', $id ) || wp_is_post_revision( $id )
			|| ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) ) { return; }
		foreach ( self::normalize( wp_unslash( $_POST ) ) as $key => $value ) {
			update_post_meta( $id, $key, $value );
		}
	}
	public static function render(): string {
		if ( ! is_singular( 'car' ) ) { return ''; }
		$values = [];
		foreach ( self::FIELDS as $key => $field ) { $values[ $key ] = get_post_meta( get_the_ID(), $key, true ); }
		$values = self::normalize( $values );
		if ( ! $values['glc_evidence_checked_on'] ) { return ''; }
		$rows = '';
		foreach ( [ 'glc_odometer_km' => 'evidence_odometer', 'glc_last_service_on' => 'evidence_service', 'glc_tyres_note' => 'evidence_tyres', 'glc_luggage_note' => 'evidence_luggage' ] as $key => $label ) {
			if ( '' === $values[ $key ] ) { continue; }
			$value = $values[ $key ] . ( 'glc_odometer_km' === $key ? ' km' : '' );
			$lang = in_array( $key, [ 'glc_tyres_note', 'glc_luggage_note' ], true ) ? ' lang="en"' : '';
			$rows .= '<div><dt>' . esc_html( glc_ui( $label ) ) . '</dt><dd' . $lang . '>' . esc_html( $value ) . '</dd></div>';
		}
		if ( ! $rows ) { return ''; }
		return '<section><h2 class="glc-label">' . esc_html( glc_ui( 'evidence_title' ) ) . '</h2><p>'
			. esc_html( glc_ui( 'evidence_checked' ) ) . ' <time datetime="' . esc_attr( $values['glc_evidence_checked_on'] ) . '">' . esc_html( $values['glc_evidence_checked_on'] )
			. '</time></p><dl class="glc-rental-facts">' . $rows . '</dl><p class="glc-policy-note">' . esc_html( glc_ui( 'evidence_note' ) ) . '</p></section>';
	}
}
