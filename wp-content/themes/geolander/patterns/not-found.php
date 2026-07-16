<?php
/**
 * Title: 404 Not Found
 * Slug: geolander/not-found
 * Inserter: no
 *
 * A PHP pattern rather than markup in templates/404.html so the copy can come
 * from the string catalog and the links can run through home_url() — GLC_I18n
 * filters that to keep the visitor inside their locale. The previous hardcoded
 * "/" and "/fleet/" links dumped a Georgian visitor onto the English site.
 */
?>
<main style="width:min(100% - 2.5rem, 1240px);margin-inline:auto;padding-block:var(--wp--preset--spacing--70);display:grid;gap:1rem;">
	<h1 style="margin:0;font-size:var(--wp--preset--font-size--display);"><?php echo esc_html( glc_t( 'nf_code' ) ); ?></h1>
	<p style="margin:0;color:var(--glc-stone);max-width:56ch;">
		<?php echo esc_html( glc_t( 'nf_text' ) ); ?>
		<a href="<?php echo esc_url( home_url( '/' ) ); ?>"><?php echo esc_html( glc_t( 'nf_home' ) ); ?></a>
		<?php echo esc_html( glc_t( 'nf_or' ) ); ?>
		<a href="<?php echo esc_url( home_url( '/fleet/' ) ); ?>"><?php echo esc_html( glc_t( 'nf_fleet' ) ); ?></a>.
	</p>
</main>
