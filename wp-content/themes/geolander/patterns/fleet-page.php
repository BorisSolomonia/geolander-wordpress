<?php
/**
 * Title: Fleet Page
 * Slug: geolander/fleet-page
 * Inserter: no
 */
$glc_today = current_datetime()->format( 'Y-m-d' );
$glc_from  = isset( $_GET['from'] ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $_GET['from'] ) ? $_GET['from'] : '';
$glc_to    = isset( $_GET['to'] ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $_GET['to'] ) ? $_GET['to'] : '';
?>
<main style="width:min(100% - 2.5rem, 1240px);margin-inline:auto;padding-block:var(--wp--preset--spacing--50) var(--wp--preset--spacing--60);display:grid;gap:2rem;">
	<div class="glc-section-head" style="margin-bottom:0;">
		<div class="glc-kicker"><?php echo glc_sign( 'fleet_title', '15 × 4X4 · TBILISI' ); // phpcs:ignore WordPress.Security.EscapeOutput ?></div>
		<h1 style="margin:0;font-size:var(--wp--preset--font-size--display);font-family:var(--wp--preset--font-family--georgian);font-feature-settings:'case';"><?php echo esc_html( glc_t( 'fleet_title' ) ); ?></h1>
		<p style="margin:0;color:var(--glc-stone);max-width:56ch;"><?php echo esc_html( glc_t( 'fleet_subtitle' ) ); ?></p>
	</div>

	<form class="glc-hero-widget" method="get">
		<div class="glc-field">
			<label for="glc-f-from"><?php echo esc_html( glc_t( 'pickup_date' ) ); ?></label>
			<input type="date" id="glc-f-from" name="from" min="<?php echo esc_attr( $glc_today ); ?>" value="<?php echo esc_attr( $glc_from ); ?>" />
		</div>
		<div class="glc-field">
			<label for="glc-f-to"><?php echo esc_html( glc_t( 'return_date' ) ); ?></label>
			<input type="date" id="glc-f-to" name="to" min="<?php echo esc_attr( $glc_today ); ?>" value="<?php echo esc_attr( $glc_to ); ?>" />
		</div>
		<button type="submit" class="wp-element-button glc-btn"><?php echo esc_html( glc_t( 'total_price' ) ); ?> →</button>
		<div class="glc-trust" style="flex-basis:100%;padding-top:0.2rem;">
			<span><?php echo esc_html( glc_t( 'trust_cancel' ) ); ?></span>
			<span><?php echo esc_html( glc_t( 'trust_fees' ) ); ?></span>
			<span><?php echo esc_html( glc_t( 'trust_delivery' ) ); ?></span>
		</div>
	</form>

	<?php echo do_blocks( '<!-- wp:geolander/fleet-grid /-->' ); ?>
</main>
