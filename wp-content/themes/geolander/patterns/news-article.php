<?php
/**
 * Title: News Article
 * Slug: geolander/news-article
 * Inserter: no
 *
 * The article body, then the event apparatus. Order is deliberate: the answer
 * summary and verified facts come FIRST, above the prose, because that is what a
 * reader arriving from a search for "when is the concert" actually needs, and it
 * is the block an answer engine lifts. The rental angle comes after the useful
 * part, never before it.
 */
$glc_id = get_the_ID();
?>
<main style="padding-bottom:var(--wp--preset--spacing--60);">
	<?php if ( has_post_thumbnail( $glc_id ) ) : ?>
	<div style="position:relative;max-height:48vh;overflow:clip;isolation:isolate;">
		<?php the_post_thumbnail( 'glc-hero', [ 'style' => 'width:100%;height:100%;object-fit:cover;display:block;max-height:48vh;', 'fetchpriority' => 'high' ] ); ?>
		<div style="position:absolute;inset:0;background:linear-gradient(180deg, rgba(20, 36, 32,0.2), rgba(20, 36, 32,0.75) 90%);"></div>
	</div>
	<?php endif; ?>

	<article style="width:min(100% - 2.5rem, 760px);margin-inline:auto;display:grid;gap:1.4rem;margin-top:<?php echo has_post_thumbnail( $glc_id ) ? '-4rem' : 'var(--wp--preset--spacing--50)'; ?>;position:relative;">
		<nav aria-label="breadcrumb" style="font-size:0.8rem;color:var(--glc-stone);">
			<a href="<?php echo esc_url( home_url( '/' ) ); ?>" style="text-decoration:none;"><?php echo esc_html( glc_t( 'nav_home' ) ); ?></a>
			<span> / </span>
			<a href="<?php echo esc_url( get_post_type_archive_link( GLC_News::POST_TYPE ) ); ?>" style="text-decoration:none;"><?php echo esc_html( glc_t( 'nav_news' ) ); ?></a>
		</nav>

		<h1 style="margin:0;font-size:var(--wp--preset--font-size--xx-large);"><?php echo esc_html( GLC_Content::title( $glc_id ) ); ?></h1>

		<p style="margin:0;font-size:0.85rem;color:var(--glc-stone);">
			<?php
			printf(
				/* translators: %s: publication date */
				esc_html( glc_t( 'news_published' ) ),
				esc_html( get_the_date( '', $glc_id ) )
			);
			?>
		</p>

		<?php echo GLC_News::render_article(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — every field is escaped at source. ?>

		<div style="line-height:1.8;color:color-mix(in srgb, var(--glc-glacier) 88%, transparent);">
			<?php echo wp_kses_post( GLC_Content::body( $glc_id ) ); ?>
		</div>
	</article>
</main>
