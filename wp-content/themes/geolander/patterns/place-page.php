<?php
/**
 * Title: Place Page
 * Slug: geolander/place-page
 * Inserter: no
 */
$glc_id     = get_the_ID();
$glc_lat    = get_post_meta( $glc_id, 'glc_lat', true );
$glc_lng    = get_post_meta( $glc_id, 'glc_lng', true );
$glc_region = wp_get_post_terms( $glc_id, 'place_region', [ 'fields' => 'names' ] );
?>
<main style="padding-bottom:var(--wp--preset--spacing--60);">
	<?php if ( has_post_thumbnail( $glc_id ) ) : ?>
	<div style="position:relative;max-height:56vh;overflow:clip;isolation:isolate;">
		<?php the_post_thumbnail( 'glc-hero', [ 'style' => 'width:100%;height:100%;object-fit:cover;display:block;max-height:56vh;', 'fetchpriority' => 'high' ] ); ?>
		<div style="position:absolute;inset:0;background:linear-gradient(180deg, rgba(20, 36, 32,0.2), rgba(20, 36, 32,0.75) 90%);"></div>
	</div>
	<?php endif; ?>

	<div style="width:min(100% - 2.5rem, 760px);margin-inline:auto;display:grid;gap:1.4rem;margin-top:<?php echo has_post_thumbnail( $glc_id ) ? '-4rem' : 'var(--wp--preset--spacing--50)'; ?>;position:relative;">
		<nav aria-label="breadcrumb" style="font-size:0.8rem;color:var(--glc-stone);">
			<a href="<?php echo esc_url( home_url( '/' ) ); ?>" style="text-decoration:none;"><?php echo esc_html( glc_t( 'nav_home' ) ); ?></a>
			<span> / </span>
			<a href="<?php echo esc_url( home_url( '/places/' ) ); ?>" style="text-decoration:none;"><?php echo esc_html( glc_t( 'nav_places' ) ); ?></a>
		</nav>
		<h1 style="margin:0;font-size:var(--wp--preset--font-size--xx-large);"><?php the_title(); ?></h1>
		<?php if ( $glc_region ) : ?>
			<div class="glc-chips"><span class="glc-chip glc-chip--4x4"><?php echo esc_html( $glc_region[0] ); ?></span></div>
		<?php endif; ?>
		<div style="line-height:1.8;color:color-mix(in srgb, var(--glc-glacier) 88%, transparent);">
			<?php echo wp_kses_post( wpautop( get_post_field( 'post_content', $glc_id ) ) ); ?>
		</div>
		<?php if ( $glc_lat && $glc_lng ) : ?>
			<p><a class="wp-element-button glc-btn" href="<?php echo esc_url( "https://maps.google.com/?q={$glc_lat},{$glc_lng}" ); ?>" rel="noopener" style="background:transparent;border:1px solid var(--glc-accent);color:var(--glc-accent);"><?php echo esc_html( glc_t( 'view_on_map' ) ); ?> →</a></p>
		<?php endif; ?>

		<section style="background:var(--glc-surface);border-radius:var(--glc-radius);padding:1.6rem;display:grid;gap:0.8rem;border:1px solid color-mix(in srgb, var(--glc-glacier) 7%, transparent);">
			<h2 style="margin:0;font-size:1.15rem;font-family:var(--wp--preset--font-family--georgian);font-feature-settings:'case';"><?php echo esc_html( glc_t( 'cta_title' ) ); ?></h2>
			<p style="margin:0;font-size:0.9rem;color:var(--glc-stone);"><?php echo esc_html( glc_t( 'terrain_text' ) ); ?></p>
			<p style="margin:0;"><a class="wp-element-button glc-btn" href="<?php echo esc_url( home_url( '/fleet/' ) ); ?>"><?php echo esc_html( glc_t( 'hero_cta' ) ); ?></a></p>
		</section>
	</div>
</main>
