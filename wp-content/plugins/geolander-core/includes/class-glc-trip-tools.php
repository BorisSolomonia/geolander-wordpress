<?php
/** A bounded decision tool, priced from the same source as actual reservations. */
defined( 'ABSPATH' ) || exit;
class GLC_Trip_Tools {
	public static function init(): void {
		add_action( 'init', static function () {
			register_block_type( 'geolander/arrival-costs', [ 'render_callback' => [ __CLASS__, 'render' ] ] );
		} );
	}
	public static function render(): string {
		$out = '<p>' . esc_html( glc_ui( 'arrival_intro' ) ) . '</p><figure class="wp-block-table"><table><caption>' . esc_html( glc_ui( 'arrival_caption' ) ) . '</caption><thead><tr>';
		foreach ( [ 'arrival_airport', 'arrival_one_way', 'arrival_round_trip' ] as $key ) { $out .= '<th scope="col">' . esc_html( glc_ui( $key ) ) . '</th>'; }
		$out .= '</tr></thead><tbody>';
		foreach ( [ 'tbilisi_airport', 'kutaisi_airport', 'batumi_airport' ] as $key ) {
			$fee = GLC_Rental::location_fee( $key );
			// Tbilisi is explicitly confirmed free; missing paid-location fees are not free.
			if ( 'tbilisi_airport' !== $key && $fee <= 0 ) { continue; }
			$out .= '<tr><th scope="row">' . esc_html( GLC_Rental::location_label( $key ) ) . '</th><td>'
				. esc_html( $fee > 0 ? GLC_Format::money( $fee ) : glc_ui( 'arrival_included' ) ) . '</td><td>'
				. esc_html( $fee > 0 ? GLC_Format::money( 2 * $fee ) : glc_ui( 'arrival_included' ) ) . '</td></tr>';
		}
		$out .= '</tbody></table></figure><p>' . esc_html( glc_ui( 'arrival_math' ) ) . '</p><p>' . esc_html( glc_ui( 'arrival_limits' ) ) . '</p>';
		$out .= '<h2>' . esc_html( glc_ui( 'arrival_checklist_title' ) ) . '</h2><ul>';
		foreach ( [ 'arrival_check_car', 'arrival_check_cover', 'arrival_check_cost', 'arrival_check_route', 'arrival_check_confirm' ] as $key ) {
			$out .= '<li>' . esc_html( glc_ui( $key ) ) . '</li>';
		}
		$out .= '</ul><p><a href="' . esc_url( home_url( '/fleet/' ) ) . '">' . esc_html( glc_ui( 'arrival_quote' ) ) . '</a> · <a href="'
			. esc_url( home_url( '/terms/' ) ) . '">' . esc_html( glc_ui( 'nav_terms' ) ) . '</a></p>';
		return $out;
	}
	public static function link(): string {
		$page = get_page_by_path( 'georgia-airport-rental-costs' );
		return $page && 'publish' === $page->post_status ? '<p class="glc-policy-note"><a href="' . esc_url( get_permalink( $page ) ) . '">' . esc_html( glc_ui( 'arrival_title' ) ) . '</a></p>' : '';
	}
}
