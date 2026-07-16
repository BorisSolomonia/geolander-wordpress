<?php
/**
 * Title: Contact Page
 * Slug: geolander/contact-page
 * Inserter: no
 */
$glc_phone = GLC_Settings::get( 'phone' );
$glc_tel   = preg_replace( '/[^+0-9]/', '', $glc_phone );
// Built by the one verified builder — the old `wa.me/<bare digits>` link dropped
// the leading "+" and did not reliably open the app. See GLC_Gateway_WhatsApp::url().
$glc_wa    = class_exists( 'GLC_Gateway_WhatsApp' ) ? GLC_Gateway_WhatsApp::url() : '';
$glc_email = GLC_Settings::get( 'email' );
$glc_cards = [
	[ glc_t( 'contact_whatsapp' ), $glc_phone, $glc_wa, '#25d366' ],
	[ glc_t( 'contact_phone' ), $glc_phone, 'tel:' . $glc_tel, 'var(--glc-accent)' ],
	[ glc_t( 'contact_email' ), $glc_email, 'mailto:' . $glc_email, 'var(--glc-accent)' ],
	[ glc_t( 'contact_address' ), GLC_Settings::get( 'address' ) . ', ' . GLC_Settings::get( 'address_locality' ) . ' ' . GLC_Settings::get( 'postal_code' ), GLC_Settings::get( 'google_maps_url' ), 'var(--glc-accent)' ],
];
?>
<main style="width:min(100% - 2.5rem, 1240px);margin-inline:auto;padding-block:var(--wp--preset--spacing--50) var(--wp--preset--spacing--60);display:grid;gap:2.2rem;">
	<div class="glc-section-head" style="margin-bottom:0;">
		<div class="glc-kicker"><?php echo glc_sign( 'contact_title', '24/7 · WHATSAPP · TBILISI' ); // phpcs:ignore WordPress.Security.EscapeOutput ?></div>
		<h1 style="margin:0;font-size:var(--wp--preset--font-size--display);font-family:var(--wp--preset--font-family--georgian);font-feature-settings:'case';"><?php echo esc_html( glc_t( 'contact_title' ) ); ?></h1>
		<p style="margin:0;color:var(--glc-stone);max-width:56ch;"><?php echo esc_html( glc_t( 'contact_subtitle' ) ); ?></p>
	</div>

	<div class="glc-stagger" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:1.2rem;">
		<?php foreach ( $glc_cards as [ $glc_label, $glc_value, $glc_href, $glc_color ] ) : ?>
			<a href="<?php echo esc_url( $glc_href ); ?>" rel="noopener" style="text-decoration:none;background:var(--glc-surface);border:1px solid color-mix(in srgb, var(--glc-glacier) 8%, transparent);border-radius:var(--glc-radius);padding:1.6rem;display:grid;gap:0.5rem;">
				<span class="glc-label" style="color:<?php echo esc_attr( $glc_color ); ?>;"><?php echo esc_html( $glc_label ); ?></span>
				<strong style="font-size:1.05rem;color:var(--glc-glacier);word-break:break-word;"><?php echo esc_html( $glc_value ); ?></strong>
			</a>
		<?php endforeach; ?>
	</div>

	<div style="background:var(--glc-surface);border-radius:var(--glc-radius);padding:1.6rem;display:flex;flex-wrap:wrap;justify-content:space-between;gap:1rem;align-items:center;border:1px solid color-mix(in srgb, var(--glc-glacier) 8%, transparent);">
		<div>
			<span class="glc-label"><?php echo esc_html( glc_t( 'contact_hours' ) ); ?></span>
			<p style="margin:0.3rem 0 0;font-weight:700;font-size:1.2rem;"><?php echo esc_html( GLC_Settings::get( 'business_hours' ) ); ?></p>
		</div>
		<a class="wp-element-button glc-btn" href="<?php echo esc_url( $glc_wa ); ?>" target="_blank" rel="noopener" style="background:#25d366;color:#073b1a;"><?php echo esc_html( glc_t( 'contact_whatsapp' ) ); ?></a>
	</div>
</main>
