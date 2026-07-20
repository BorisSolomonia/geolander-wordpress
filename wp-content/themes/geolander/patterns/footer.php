<?php
/**
 * Title: Footer
 * Slug: geolander/footer
 * Inserter: no
 */
$glc_nav = [
	home_url( '/' )             => glc_t( 'nav_home' ),
	home_url( '/fleet/' )       => glc_t( 'nav_fleet' ),
	home_url( '/places/' )      => glc_t( 'nav_places' ),
	home_url( '/travel-info/' ) => glc_t( 'nav_travel_info' ),
	home_url( '/music/' )       => glc_t( 'nav_music' ),
	home_url( '/terms/' )       => glc_t( 'nav_terms' ),
	home_url( '/contact/' )     => glc_t( 'nav_contact' ),
];
$glc_phone = GLC_Settings::get( 'phone' );
// Verified deep-link format (keeps the "+", opens the app) — see
// GLC_Gateway_WhatsApp::url(). The old bare-digit wa.me link was unreliable.
$glc_wa    = class_exists( 'GLC_Gateway_WhatsApp' ) ? GLC_Gateway_WhatsApp::url() : '';
?>
<footer class="glc-footer">
	<div style="width:min(100% - 2.5rem, 1240px);margin-inline:auto;padding-block:3.5rem 2rem;display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:2.5rem;">
		<div style="display:grid;gap:1rem;align-content:start;">
			<img src="<?php echo esc_url( get_theme_file_uri( 'assets/img/logo.png' ) ); ?>" alt="Geolander" width="150" height="47" style="height:47px;width:auto;" loading="lazy" />
			<p style="font-size:0.88rem;color:var(--glc-stone);margin:0;"><?php echo esc_html( glc_t( 'footer_desc' ) ); ?></p>
		</div>
		<div>
			<h3><?php echo esc_html( glc_t( 'quick_links' ) ); ?></h3>
			<ul style="list-style:none;padding:0;margin:0;display:grid;gap:0.5rem;">
				<?php foreach ( $glc_nav as $glc_url => $glc_label ) : ?>
					<li><a href="<?php echo esc_url( $glc_url ); ?>"><?php echo esc_html( $glc_label ); ?></a></li>
				<?php endforeach; ?>
			</ul>
		</div>
		<div>
			<h3><?php echo esc_html( glc_t( 'contact_info' ) ); ?></h3>
			<ul style="list-style:none;padding:0;margin:0;display:grid;gap:0.5rem;">
				<li><a href="<?php echo esc_url( GLC_Settings::get( 'google_maps_url' ) ); ?>" rel="noopener"><?php echo esc_html( GLC_Settings::get( 'address' ) . ', ' . GLC_Settings::get( 'address_locality' ) . ' ' . GLC_Settings::get( 'postal_code' ) ); ?></a></li>
				<li><a href="tel:<?php echo esc_attr( preg_replace( '/[^+0-9]/', '', $glc_phone ) ); ?>"><?php echo esc_html( $glc_phone ); ?></a></li>
				<li><a href="mailto:<?php echo esc_attr( GLC_Settings::get( 'email' ) ); ?>"><?php echo esc_html( GLC_Settings::get( 'email' ) ); ?></a></li>
			</ul>
		</div>
		<div>
			<h3><?php echo esc_html( glc_t( 'follow_us' ) ); ?></h3>
			<ul style="list-style:none;padding:0;margin:0;display:grid;gap:0.5rem;">
				<li><a href="<?php echo esc_url( GLC_Settings::get( 'instagram' ) ); ?>" rel="noopener">Instagram</a></li>
				<li><a href="<?php echo esc_url( GLC_Settings::get( 'facebook' ) ); ?>" rel="noopener">Facebook</a></li>
			</ul>
		</div>
		<?php
		// Delivery coverage — tells visitors which cities we serve AND internally
		// links the city landing pages (local-SEO signal). Only shows cities that
		// actually exist, so the footer never lists a page that isn't published.
		$glc_cities = class_exists( 'GLC_City' ) ? GLC_City::all() : [];
		?>
		<?php if ( $glc_cities ) : ?>
			<div>
				<h3><?php echo esc_html( glc_t( 'we_deliver' ) ); ?></h3>
				<ul style="list-style:none;padding:0;margin:0;display:grid;gap:0.5rem;">
					<?php foreach ( $glc_cities as $glc_city ) : ?>
						<li><a href="<?php echo esc_url( get_permalink( $glc_city ) ); ?>"><?php echo esc_html( GLC_City::city_name( $glc_city->ID ) ); ?></a></li>
					<?php endforeach; ?>
				</ul>
			</div>
		<?php endif; ?>
	</div>
	<div style="border-top:1px solid color-mix(in srgb, var(--glc-glacier) 6%, transparent);">
		<p style="width:min(100% - 2.5rem, 1240px);margin-inline:auto;padding-block:1.1rem;font-size:0.78rem;color:var(--glc-stone);margin-block:0;">
			© <?php echo esc_html( gmdate( 'Y' ) ); ?> Geolander. <?php echo esc_html( glc_t( 'rights' ) ); ?>
		</p>
	</div>
</footer>
<?php if ( $glc_wa ) : ?>
<a class="glc-wa-bubble" href="<?php echo esc_url( $glc_wa ); ?>" target="_blank" rel="noopener" aria-label="WhatsApp">
	<svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.297-.347.446-.52.149-.174.198-.298.297-.497.1-.198.05-.371-.025-.52-.074-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413Z"/></svg>
</a>
<?php endif; ?>
