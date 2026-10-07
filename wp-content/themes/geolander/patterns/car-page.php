<?php
/**
 * Title: Car Page
 * Slug: geolander/car-page
 * Inserter: no
 */
$glc_id    = get_the_ID();
$glc_year  = get_post_meta( $glc_id, 'glc_year', true );
$glc_title = preg_replace( '/\s\d{4}$/', '', get_the_title() );
$glc_drive = get_post_meta( $glc_id, 'glc_drivetrain', true );
/*
 * The page's own rate range, from the SAME call that feeds the <title>, the meta
 * description and the Product/AggregateOffer lowPrice/highPrice
 * (GLC_Pricing::rate_range). Before this, the snippet promised "from $39/day"
 * and the schema claimed lowPrice 39 while the page body showed no per-day rate
 * at all — only a 5-day quote that worked out to $86. Google's structured-data
 * guidance requires markup to match visible text, so the number a searcher is
 * shown has to exist on the page they land on.
 */
[ $glc_rate_low, $glc_rate_high ] = GLC_Pricing::rate_range( $glc_id );
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
				<?php if ( $glc_rate_low > 0 ) : ?>
					<p class="glc-card-price" style="margin:0;">
						<span class="glc-price"><?php echo esc_html( glc_t( 'from' ) ); ?> <?php echo esc_html( GLC_Format::money( $glc_rate_low ) ); ?><span class="glc-price-unit"><?php echo esc_html( glc_t( 'per_day' ) ); ?></span></span>
						<?php if ( $glc_rate_high > $glc_rate_low ) : ?>
							<span style="color:var(--glc-stone);font-size:0.85rem;">· <?php echo esc_html( sprintf( glc_t( 'price_range_sentence' ), GLC_Format::money( $glc_rate_low ), GLC_Format::money( $glc_rate_high ) ) ); ?></span>
						<?php endif; ?>
					</p>
				<?php endif; ?>
				<div class="glc-chips">
					<?php if ( $glc_drive ) : ?><span class="glc-chip glc-chip--4x4"><?php echo esc_html( $glc_drive ); ?></span><?php endif; ?>
					<span class="glc-chip"><?php echo esc_html( glc_t( get_post_meta( $glc_id, 'glc_transmission', true ) ?: 'automatic' ) ); ?></span>
					<span class="glc-chip"><?php echo esc_html( get_post_meta( $glc_id, 'glc_seats', true ) . ' ' . glc_t( 'seats' ) ); ?></span>
				</div>
			</div>

			<section>
				<h2 class="glc-label" style="margin:0 0 0.9rem;"><?php echo esc_html( glc_t( 'specs_title' ) ); ?></h2>
				<?php echo do_blocks( '<!-- wp:geolander/car-specs /-->' ); ?>
			</section>

			<section>
				<h2 class="glc-label" style="margin:0 0 0.9rem;"><?php echo esc_html( glc_t( 'rental_facts_title' ) ); ?></h2>
				<?php echo do_blocks( '<!-- wp:geolander/rental-facts /-->' ); ?>
			</section>

			<?php if ( $glc_rate_low > 0 ) : ?>
			<section>
				<h2 class="glc-label" style="margin:0 0 0.9rem;"><?php echo esc_html( glc_t( 'rates_title' ) ); ?></h2>
				<?php echo do_blocks( '<!-- wp:geolander/price-table /-->' ); ?>
			</section>
			<?php endif; ?>

			<section style="background:var(--glc-surface);border-radius:var(--glc-radius);padding:1.4rem;border:1px solid color-mix(in srgb, var(--glc-glacier) 7%, transparent);">
				<h2 class="glc-label" style="margin:0 0 0.6rem;"><?php echo esc_html( glc_t( 'terrain_title' ) ); ?></h2>
				<p style="margin:0;font-size:0.95rem;line-height:1.7;"><?php echo esc_html( glc_t( 'terrain_text' ) ); ?></p>
			</section>

			<?php echo do_blocks( '<!-- wp:geolander/vehicle-evidence /-->' ); ?>

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
