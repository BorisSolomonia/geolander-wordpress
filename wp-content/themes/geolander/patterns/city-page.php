<?php
/**
 * Title: City Page
 * Slug: geolander/city-page
 * Inserter: no
 *
 * A car-rental-in-{city} landing page: localized title + body (via GLC_Content),
 * this city's airport/delivery facts, then the shared fleet grid and FAQ so the
 * page is a real conversion surface, not a thin doorway.
 */
$glc_id       = get_the_ID();
$glc_airport  = class_exists( 'GLC_City' ) ? GLC_City::airport( $glc_id ) : [ 'name' => '', 'code' => '' ];
$glc_delivery = class_exists( 'GLC_City' ) ? GLC_City::delivery_note( $glc_id ) : '';
$glc_wa       = class_exists( 'GLC_Gateway_WhatsApp' ) ? GLC_Gateway_WhatsApp::url() : '';
?>
<main style="width:min(100% - 2.5rem, 900px);margin-inline:auto;padding-block:var(--wp--preset--spacing--50) var(--wp--preset--spacing--60);display:grid;gap:1.6rem;">

	<?php if ( has_post_thumbnail( $glc_id ) ) : ?>
		<div style="border-radius:var(--glc-radius);overflow:clip;aspect-ratio:21/9;">
			<?php the_post_thumbnail( 'glc-hero', [ 'style' => 'width:100%;height:100%;object-fit:cover;', 'fetchpriority' => 'high' ] ); ?>
		</div>
	<?php endif; ?>

	<header style="display:grid;gap:0.8rem;">
		<h1 style="margin:0;font-size:var(--wp--preset--font-size--xx-large);"><?php echo esc_html( GLC_Content::title( $glc_id ) ); ?></h1>

		<div class="glc-chips">
			<?php if ( $glc_airport['code'] ) : ?>
				<span class="glc-chip glc-chip--4x4">✈ <?php echo esc_html( trim( $glc_airport['name'] . ' ' . $glc_airport['code'] ) ); ?></span>
			<?php endif; ?>
			<span class="glc-chip">✓ <?php echo esc_html( glc_t( 'trust_insurance' ) ); ?></span>
			<?php if ( $glc_delivery ) : ?>
				<span class="glc-chip">📍 <?php echo esc_html( $glc_delivery ); ?></span>
			<?php endif; ?>
		</div>
	</header>

	<div class="glc-answer" style="font-size:1.02rem;line-height:1.7;">
		<?php echo wp_kses_post( wpautop( GLC_Content::body( $glc_id ) ) ); ?>
	</div>

	<?php if ( $glc_wa ) : ?>
		<a class="wp-element-button glc-btn" href="<?php echo esc_url( $glc_wa ); ?>" target="_blank" rel="noopener" style="justify-self:start;background:var(--glc-success);color:var(--glc-paper);">
			💬 <?php echo esc_html( glc_t( 'ask_whatsapp' ) ); ?>
		</a>
	<?php endif; ?>

	<section style="display:grid;gap:1rem;margin-top:1rem;">
		<h2 style="margin:0;"><?php echo esc_html( glc_t( 'fleet_title' ) ); ?></h2>
		<?php echo do_blocks( '<!-- wp:geolander/fleet-grid {"count":6} /-->' ); ?>
		<a href="<?php echo esc_url( get_post_type_archive_link( 'car' ) ); ?>" style="justify-self:start;"><?php echo esc_html( glc_t( 'view_all' ) ); ?> →</a>
	</section>

	<section style="display:grid;gap:1rem;">
		<h2 style="margin:0;"><?php echo esc_html( glc_t( 'faq_title' ) ); ?></h2>
		<?php echo do_blocks( '<!-- wp:geolander/faq-list /-->' ); ?>
	</section>
</main>
