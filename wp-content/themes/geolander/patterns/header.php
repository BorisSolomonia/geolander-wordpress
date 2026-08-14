<?php
/**
 * Title: Header
 * Slug: geolander/header
 * Inserter: no
 */
/*
 * Primary navigation.
 *
 * Three of the seven slots used to go to tourist content (Places, Travel Info,
 * Georgian Music) and none went to a city page or a policy page — so the pages
 * that actually earn money were supported only by site-wide footer links, the
 * weakest internal link there is. This order leads with commercial and
 * decision-stage destinations and demotes the rest to the footer.
 *
 * Candidates whose page does not exist yet are skipped, so the nav upgrades
 * itself as the coverage hub, the permission page and the trust pages are
 * published — and never links to a 404 in the meantime.
 */
$glc_nav_candidates = [
	'/fleet/'               => glc_t( 'nav_fleet' ),
	'/where-you-can-drive/' => glc_t( 'nav_where_drive' ),
	'/car-rental/'          => glc_t( 'nav_locations' ),
	'/guides/'              => glc_t( 'nav_guides' ),
	'/trust/'               => glc_t( 'nav_trust' ),
	'/places/'              => glc_t( 'nav_places' ),
	'/contact/'             => glc_t( 'nav_contact' ),
];

$glc_nav = [];
foreach ( $glc_nav_candidates as $glc_path => $glc_label ) {
	// /fleet/ and /places/ are CPT archives, not pages — always present.
	$glc_always = in_array( $glc_path, [ '/fleet/', '/places/' ], true );
	if ( $glc_always || get_page_by_path( trim( $glc_path, '/' ) ) instanceof WP_Post ) {
		$glc_nav[ home_url( $glc_path ) ] = $glc_label;
	}
}
if ( ! $glc_nav ) { // Defensive: never render an empty nav.
	$glc_nav = [
		home_url( '/fleet/' )   => glc_t( 'nav_fleet' ),
		home_url( '/contact/' ) => glc_t( 'nav_contact' ),
	];
}
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
