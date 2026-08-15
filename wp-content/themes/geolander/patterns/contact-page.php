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
$glc_map   = GLC_Settings::get( 'google_maps_url' );
$glc_lat   = GLC_Settings::get( 'latitude' );
$glc_lng   = GLC_Settings::get( 'longitude' );
$glc_rating = GLC_Settings::get( 'google_rating' );
$glc_embed  = ( $glc_lat && $glc_lng )
	? 'https://www.google.com/maps?q=' . rawurlencode( $glc_lat . ',' . $glc_lng ) . '&z=16&output=embed'
	: '';
$glc_status = sanitize_key( wp_unslash( $_GET['contact'] ?? '' ) );
$glc_cards = [
	[ glc_t( 'contact_whatsapp' ), $glc_phone, $glc_wa, '#25d366' ],
	[ glc_t( 'contact_phone' ), $glc_phone, 'tel:' . $glc_tel, 'var(--glc-accent)' ],
	[ glc_t( 'contact_email' ), $glc_email, 'mailto:' . $glc_email, 'var(--glc-accent)' ],
	[ glc_t( 'contact_address' ), GLC_Settings::get( 'address' ) . ', ' . GLC_Settings::get( 'address_locality' ) . ' ' . GLC_Settings::get( 'postal_code' ), $glc_map, 'var(--glc-accent)' ],
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

	<?php if ( $glc_rating && $glc_map ) : ?>
		<a href="<?php echo esc_url( $glc_map ); ?>" target="_blank" rel="noopener" style="display:flex;align-items:center;justify-content:center;gap:0.7rem;padding:1rem 1.25rem;border:1px solid color-mix(in srgb, var(--glc-sage) 48%, transparent);border-radius:var(--glc-radius);background:color-mix(in srgb, var(--glc-sage) 10%, var(--glc-surface));color:var(--glc-glacier);text-decoration:none;font-weight:700;">
			<span aria-hidden="true" style="color:#fbbc04;letter-spacing:0.08em;">★★★★★</span>
			<span><?php echo esc_html( sprintf( glc_t( 'contact_google_rating' ), $glc_rating ) ); ?></span>
		</a>
	<?php endif; ?>

	<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,320px),1fr));gap:1.2rem;align-items:stretch;">
		<section style="background:var(--glc-surface);border:1px solid color-mix(in srgb, var(--glc-glacier) 8%, transparent);border-radius:var(--glc-radius);padding:1.6rem;">
			<h2 style="margin-top:0;"><?php echo esc_html( glc_t( 'contact_form_title' ) ); ?></h2>
			<?php if ( 'sent' === $glc_status ) : ?>
				<p role="status" style="padding:0.8rem;border-radius:0.5rem;background:color-mix(in srgb, var(--glc-sage) 18%, transparent);"><?php echo esc_html( glc_t( 'contact_form_sent' ) ); ?></p>
			<?php elseif ( 'error' === $glc_status ) : ?>
				<p role="alert" style="padding:0.8rem;border-radius:0.5rem;background:color-mix(in srgb, var(--glc-orange) 16%, transparent);"><?php echo esc_html( glc_t( 'contact_form_error' ) ); ?></p>
			<?php endif; ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:grid;gap:1rem;">
				<input type="hidden" name="action" value="glc_contact">
				<p aria-hidden="true" style="position:absolute;left:-10000px;"><label>Website <input type="text" name="website" tabindex="-1" autocomplete="off"></label></p>
				<label><?php echo esc_html( glc_t( 'contact_form_name' ) ); ?><input type="text" name="name" autocomplete="name" required maxlength="120" style="display:block;width:100%;margin-top:0.35rem;"></label>
				<label><?php echo esc_html( glc_t( 'contact_form_email' ) ); ?><input type="email" name="email" autocomplete="email" required maxlength="254" style="display:block;width:100%;margin-top:0.35rem;"></label>
				<label><?php echo esc_html( glc_t( 'contact_form_phone' ) ); ?><input type="tel" name="phone" autocomplete="tel" maxlength="40" style="display:block;width:100%;margin-top:0.35rem;"></label>
				<label><?php echo esc_html( glc_t( 'contact_form_message' ) ); ?><textarea name="message" required maxlength="3000" rows="6" style="display:block;width:100%;margin-top:0.35rem;"></textarea></label>
				<button class="wp-element-button glc-btn" type="submit"><?php echo esc_html( glc_t( 'contact_form_send' ) ); ?></button>
			</form>
		</section>

		<section style="background:var(--glc-surface);border:1px solid color-mix(in srgb, var(--glc-glacier) 8%, transparent);border-radius:var(--glc-radius);padding:1.6rem;display:grid;gap:1rem;">
			<h2 style="margin:0;"><?php echo esc_html( glc_t( 'contact_map_title' ) ); ?></h2>
			<?php if ( $glc_embed ) : ?>
				<iframe src="<?php echo esc_url( $glc_embed ); ?>" title="<?php echo esc_attr( glc_t( 'contact_map_title' ) ); ?>" loading="lazy" referrerpolicy="no-referrer-when-downgrade" style="border:0;border-radius:0.75rem;width:100%;min-height:420px;" allowfullscreen></iframe>
			<?php endif; ?>
			<a href="<?php echo esc_url( $glc_map ); ?>" target="_blank" rel="noopener"><?php echo esc_html( glc_t( 'view_on_map' ) ); ?></a>
		</section>
	</div>
</main>
