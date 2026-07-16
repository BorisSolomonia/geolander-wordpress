<?php
/**
 * Title: Car Page
 * Slug: geolander/car-page
 * Inserter: no
 */
$glc_id    = get_the_ID();
$glc_year  = get_post_meta( $glc_id, 'glc_year', true );
$glc_title = preg_replace( '/\s\d{4}$/', '', get_the_title() );
?>
<main style="width:min(100% - 2.5rem, 1240px);margin-inline:auto;padding-block:var(--wp--preset--spacing--40) var(--wp--preset--spacing--60);display:grid;gap:1.6rem;">

	<nav aria-label="breadcrumb" style="font-size:0.8rem;color:var(--glc-stone);">
		<a href="<?php echo esc_url( home_url( '/' ) ); ?>" style="text-decoration:none;"><?php echo esc_html( glc_t( 'nav_home' ) ); ?></a>
		<span> / </span>
		<a href="<?php echo esc_url( home_url( '/fleet/' ) ); ?>" style="text-decoration:none;"><?php echo esc_html( glc_t( 'nav_fleet' ) ); ?></a>
		<span> / </span>
		<span><?php echo esc_html( $glc_title . ' ' . $glc_year ); ?></span>
	</nav>

	<?php echo do_blocks( '<!-- wp:geolander/car-gallery /-->' ); ?>

	<div class="glc-single-layout">
		<div style="display:grid;gap:2.4rem;align-content:start;">
			<div class="glc-section-head" style="margin-bottom:0;">
				<h1 style="margin:0;font-size:var(--wp--preset--font-size--xx-large);font-variation-settings:'wdth' 112;"><?php echo esc_html( $glc_title ); ?> <span style="color:var(--glc-stone);font-weight:500;"><?php echo esc_html( $glc_year ); ?></span></h1>
				<div style="display:flex;align-items:center;gap:0.9rem;flex-wrap:wrap;">
					<?php echo glc_plate( get_post_meta( $glc_id, 'glc_registration', true ), '1.05rem' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
					<?php if ( get_post_meta( $glc_id, 'glc_available', true ) ) : ?>
						<span style="font-family:var(--glc-mono);font-size:0.72rem;letter-spacing:0.1em;color:var(--glc-success);">● <?php echo esc_html( strtoupper( glc_t( 'available' ) ) ); ?></span>
					<?php endif; ?>
				</div>
				<div class="glc-chips">
					<span class="glc-chip glc-chip--4x4">4x4</span>
					<span class="glc-chip"><?php echo esc_html( glc_t( get_post_meta( $glc_id, 'glc_transmission', true ) ?: 'automatic' ) ); ?></span>
					<span class="glc-chip"><?php echo esc_html( get_post_meta( $glc_id, 'glc_seats', true ) . ' ' . glc_t( 'seats' ) ); ?></span>
				</div>
			</div>

			<section>
				<h2 class="glc-label" style="margin:0 0 0.9rem;"><?php echo esc_html( glc_t( 'specs_title' ) ); ?></h2>
				<?php echo do_blocks( '<!-- wp:geolander/car-specs /-->' ); ?>
			</section>

			<section>
				<h2 class="glc-label" style="margin:0 0 0.9rem;"><?php echo esc_html( glc_t( 'pricing_title' ) ); ?></h2>
				<?php
				// Seasonal price table removed from the car page (no prices here by
				// decision). The block itself still exists and renders wherever it is
				// inserted — /pricing.md keeps serving the full tables to AI crawlers.
				?>
			</section>

			<section>
				<h2 class="glc-label" style="margin:0 0 0.9rem;"><?php echo esc_html( glc_t( 'included_title' ) ); ?></h2>
				<ul class="glc-included" style="padding:0;margin:0;">
					<?php for ( $glc_i = 1; $glc_i <= 6; $glc_i++ ) : ?>
						<li><?php echo esc_html( glc_t( "included_{$glc_i}" ) ); ?></li>
					<?php endfor; ?>
				</ul>
			</section>

			<section style="background:var(--glc-surface);border-radius:var(--glc-radius);padding:1.4rem;border:1px solid color-mix(in srgb, var(--glc-glacier) 7%, transparent);">
				<h2 class="glc-label" style="margin:0 0 0.6rem;"><?php echo esc_html( glc_t( 'terrain_title' ) ); ?></h2>
				<p style="margin:0;font-size:0.95rem;line-height:1.7;"><?php echo esc_html( glc_t( 'terrain_text' ) ); ?></p>
			</section>

			<?php if ( trim( GLC_Content::body( $glc_id ) ) ) : ?>
			<section style="max-width:64ch;line-height:1.75;color:color-mix(in srgb, var(--glc-glacier) 85%, transparent);">
				<?php echo wp_kses_post( wpautop( GLC_Content::body( $glc_id ) ) ); ?>
			</section>
			<?php endif; ?>
		</div>

		<aside>
			<?php echo do_blocks( '<!-- wp:geolander/booking-widget /-->' ); ?>
		</aside>
	</div>
</main>
