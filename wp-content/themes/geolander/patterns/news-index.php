<?php
/**
 * Title: News Index
 * Slug: geolander/news-index
 * Inserter: no
 */
?>
<main style="padding-bottom:var(--wp--preset--spacing--60);">
	<div style="width:min(100% - 2.5rem, 760px);margin-inline:auto;display:grid;gap:1.4rem;margin-top:var(--wp--preset--spacing--50);">
		<nav aria-label="breadcrumb" style="font-size:0.8rem;color:var(--glc-stone);">
			<a href="<?php echo esc_url( home_url( '/' ) ); ?>" style="text-decoration:none;"><?php echo esc_html( glc_t( 'nav_home' ) ); ?></a>
		</nav>
		<h1 style="margin:0;font-size:var(--wp--preset--font-size--xx-large);"><?php echo esc_html( glc_t( 'news_index_title' ) ); ?></h1>
		<p style="line-height:1.8;color:color-mix(in srgb, var(--glc-glacier) 88%, transparent);"><?php echo esc_html( glc_t( 'news_index_intro' ) ); ?></p>
		<?php echo GLC_News::render_list( [ 'count' => 30 ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — escaped at source. ?>
	</div>
</main>
