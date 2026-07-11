<?php
/**
 * Title: Places Page
 * Slug: geolander/places-page
 * Inserter: no
 */
?>
<main style="width:min(100% - 2.5rem, 1240px);margin-inline:auto;padding-block:var(--wp--preset--spacing--50) var(--wp--preset--spacing--60);display:grid;gap:2rem;">
	<div class="glc-section-head" style="margin-bottom:0;">
		<div class="glc-kicker"><?php echo glc_sign( 'places_title', '36 DESTINATIONS · 0 → 2196 M' ); // phpcs:ignore WordPress.Security.EscapeOutput ?></div>
		<h1 style="margin:0;font-size:var(--wp--preset--font-size--display);font-family:var(--wp--preset--font-family--georgian);font-feature-settings:'case';"><?php echo esc_html( glc_t( 'places_title' ) ); ?></h1>
		<p style="margin:0;color:var(--glc-stone);max-width:56ch;"><?php echo esc_html( glc_t( 'places_subtitle' ) ); ?></p>
	</div>
	<?php echo do_blocks( '<!-- wp:geolander/places-grid /-->' ); ?>
</main>
