<?php
define( 'ABSPATH', __DIR__ );
$_GET = [];
$rates = [ 'd1_2' => 40, 'd3_4' => 0 ];
function glc_t( $key ) { return $key; }
function get_the_ID() { return 1; }
function get_post_meta( ...$args ) { return [ [ 'label' => 'Test season', 'rates' => $GLOBALS['rates'] ] ]; }
function esc_html( $s ) { return htmlspecialchars( $s ); }
class GLC_Pricing { const TIERS = [ 'd1_2', 'd3_4' ]; const TIER_LABELS = [ '1–2', '3–4' ]; }
class GLC_Format { public static function money( $n ) { return '$' . $n; } }
require __DIR__ . '/../wp-content/plugins/geolander-core/includes/class-glc-blocks.php';
$html = GLC_Blocks::price_table();
if ( str_contains( $html, '$0' ) || ! str_contains( $html, '$40' ) ) { throw new Exception( 'Unpriced tiers must not advertise zero; valid rate must remain.' ); }
foreach ( [ 'unknown', [], -20, null ] as $missing_rate ) {
	$rates = [ 'd1_2' => 40, 'd3_4' => $missing_rate ];
	$html = GLC_Blocks::price_table();
	if ( str_contains( $html, '$0' ) || substr_count( $html, '$' ) !== 1 || ! str_contains( $html, '$40' ) ) { throw new Exception( 'Malformed tiers must be omitted, retaining the valid price.' ); }
}
$rates = [ 'd1_2' => 0, 'd3_4' => 0 ];
if ( GLC_Blocks::price_table() !== '' ) { throw new Exception( 'Unpriced table must be omitted.' ); }
echo "PASS Partial and fully missing seasonal prices never display zero\n";
