<?php
define( 'ABSPATH', __DIR__ );
$meta = [];
$fee = 68;
$failures = 0;
function check( $yes, $message ) { if ( ! $yes ) { ++$GLOBALS['failures']; } echo ( $yes ? 'PASS ' : 'FAIL ' ) . $message . "\n"; }
function current_time( $format ) { return '2026-09-09'; }
function sanitize_text_field( $value ) { return trim( strip_tags( $value ) ); }
function is_singular( $type ) { return $type === 'car'; }
function get_the_ID() { return 1; }
function get_post_meta( $id, $key, $single ) { return $GLOBALS['meta'][$key] ?? ''; }
function glc_ui( $key ) { return $key; }
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_attr( $s ) { return esc_html( $s ); }
function esc_url( $s ) { return esc_html( $s ); }
function home_url( $path ) { return 'https://example.test' . $path; }
class GLC_Rental {
	public static function location_fee( $key ) { return $key === 'kutaisi_airport' ? $GLOBALS['fee'] : ( $key === 'batumi_airport' ? 98 : 0 ); }
	public static function location_label( $key ) { return $key; }
}
class GLC_Format { public static function money( $value ) { return '$' . $value; } }
require __DIR__ . '/../wp-content/plugins/geolander-core/includes/class-glc-vehicle-evidence.php';
require __DIR__ . '/../wp-content/plugins/geolander-core/includes/class-glc-trip-tools.php';
check( GLC_Vehicle_Evidence::render() === '', 'Unknown evidence never renders a placeholder section' );
$meta = [ 'glc_evidence_checked_on' => '2026-09-09', 'glc_odometer_km' => '0' ];
check( GLC_Vehicle_Evidence::render() === '', 'Zero odometer is omitted' );
$meta['glc_odometer_km'] = '123456';
check( str_contains( GLC_Vehicle_Evidence::render(), '123456 km' ), 'Verified nonzero odometer displays with check date' );
$meta['glc_evidence_checked_on'] = '2026-09-10';
check( GLC_Vehicle_Evidence::render() === '', 'Future check dates cannot publish evidence' );
check( GLC_Vehicle_Evidence::date( '2026-02-30' ) === '', 'Impossible calendar dates rejected' );
check( GLC_Vehicle_Evidence::date( '2024-02-29' ) === '2024-02-29', 'Valid leap day accepted' );
$values = GLC_Vehicle_Evidence::normalize( [ 'glc_evidence_checked_on' => '2026-09-01', 'glc_last_service_on' => '2026-09-02', 'glc_odometer_km' => '-12', 'glc_tyres_note' => ['bad'] ] );
check( $values['glc_last_service_on'] === '' && $values['glc_odometer_km'] === '' && $values['glc_tyres_note'] === '', 'Malformed inputs and service after observation omitted' );
$meta = [ 'glc_evidence_checked_on' => '2026-09-09', 'glc_tyres_note' => '<script>alert(1)</script><b>Test tyre</b>' ];
check( ! str_contains( GLC_Vehicle_Evidence::render(), '<script>' ), 'Evidence text is sanitized and escaped' );
$html = GLC_Trip_Tools::render();
check( str_contains( $html, '$136' ) && str_contains( $html, '$196' ), 'Round-trip costs use both real delivery fees' );
check( ! str_contains( $html, '$0' ) && str_contains( $html, 'arrival_included' ), 'Confirmed free delivery uses words, not a price zero' );
$fee = 75;
check( str_contains( GLC_Trip_Tools::render(), '$150' ), 'Changing a fee changes the comparison, without editing content' );
$fee = 0;
check( ! str_contains( GLC_Trip_Tools::render(), 'kutaisi_airport' ), 'Missing paid-airport fee is not advertised as free' );
foreach ( [ 'en', 'ka', 'ru', 'uk', 'ar', 'zh', 'fr' ] as $locale ) {
	$strings = require __DIR__ . '/../wp-content/themes/geolander/inc/strings-' . $locale . '.php';
	check( count( array_filter( array_keys( $strings ), fn( $key ) => str_starts_with( $key, 'arrival_' ) || str_starts_with( $key, 'evidence_' ) ) ) === 23, 'Complete evidence/tool catalogue: ' . $locale );
}
exit( $failures ? 1 : 0 );
