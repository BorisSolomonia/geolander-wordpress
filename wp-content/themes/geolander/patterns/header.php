<?php
/**
 * Title: Header
 * Slug: geolander/header
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
$glc_tel   = preg_replace( '/[^+0-9]/', '', $glc_phone );
?>
<div class="glc-header">
	<div class="glc-header-inner">
		<a class="glc-logo" href="<?php echo esc_url( home_url( '/' ) ); ?>" aria-label="Geolander">
			<img src="<?php echo esc_url( get_theme_file_uri( 'assets/img/logo.png' ) ); ?>" alt="Geolander" width="140" height="44" />
		</a>
		<nav class="glc-nav" aria-label="Main">
			<?php foreach ( $glc_nav as $glc_url => $glc_label ) : ?>
				<a href="<?php echo esc_url( $glc_url ); ?>"><?php echo esc_html( $glc_label ); ?></a>
			<?php endforeach; ?>
		</nav>
		<div class="glc-header-actions">
			<details class="glc-lang">
				<summary><?php echo esc_html( strtoupper( GLC_I18n::locale() ) ); ?></summary>
				<nav class="glc-lang-menu" aria-label="Language">
					<?php foreach ( GLC_I18n::switcher() as $glc_code => $glc_lang ) : ?>
						<a href="<?php echo esc_url( $glc_lang['url'] ); ?>"<?php echo $glc_lang['active'] ? ' class="glc-lang-active"' : ''; ?> hreflang="<?php echo esc_attr( $glc_code ); ?>"><?php echo esc_html( $glc_lang['name'] ); ?></a>
					<?php endforeach; ?>
				</nav>
			</details>
			<a class="glc-header-phone" href="tel:<?php echo esc_attr( $glc_tel ); ?>"><?php echo esc_html( $glc_phone ); ?></a>
			<a class="wp-element-button glc-btn glc-header-cta" href="<?php echo esc_url( home_url( '/fleet/' ) ); ?>"><?php echo esc_html( glc_t( 'book_now' ) ); ?></a>
			<details class="glc-burger">
				<summary aria-label="Menu">
					<span></span><span></span><span></span>
				</summary>
				<nav class="glc-mobile-nav" aria-label="Mobile">
					<?php foreach ( $glc_nav as $glc_url => $glc_label ) : ?>
						<a href="<?php echo esc_url( $glc_url ); ?>"><?php echo esc_html( $glc_label ); ?></a>
					<?php endforeach; ?>
					<a href="tel:<?php echo esc_attr( $glc_tel ); ?>" class="glc-mobile-phone"><?php echo esc_html( $glc_phone ); ?></a>
				</nav>
			</details>
		</div>
	</div>
</div>
