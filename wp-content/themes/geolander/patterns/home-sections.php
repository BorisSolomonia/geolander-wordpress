<?php
/**
 * Title: Home Sections
 * Slug: geolander/home-sections
 * Inserter: no
 *
 * Everything below the hero on the front page, in golden-path order:
 * fleet → how it works → routes → included → testimonials → FAQ → CTA.
 */
?>
<div style="width:min(100% - 2.5rem, 1240px);margin-inline:auto;display:grid;gap:var(--wp--preset--spacing--70);padding-block:var(--wp--preset--spacing--60);">

	<section class="glc-reveal" id="fleet">
		<div class="glc-section-head">
			<div class="glc-kicker"><?php echo glc_sign( 'fleet_title', '15 × 4X4 · TBILISI' ); // phpcs:ignore WordPress.Security.EscapeOutput ?></div>
			<h2 style="margin:0;font-size:var(--wp--preset--font-size--xx-large);font-family:var(--wp--preset--font-family--georgian);font-feature-settings:'case';"><?php echo esc_html( glc_t( 'fleet_title' ) ); ?></h2>
			<p style="margin:0;color:var(--glc-stone);max-width:56ch;"><?php echo esc_html( glc_t( 'fleet_subtitle' ) ); ?></p>
		</div>
		<?php echo do_blocks( '<!-- wp:geolander/fleet-grid {"count":6} /-->' ); ?>
		<p style="margin-top:1.6rem;text-align:center;">
			<a class="wp-element-button glc-btn" href="<?php echo esc_url( home_url( '/fleet/' ) ); ?>" style="background:transparent;border:1px solid var(--glc-accent);color:var(--glc-accent);"><?php echo esc_html( glc_t( 'view_all' ) ); ?> — 15 →</a>
		</p>
	</section>

	<section class="glc-reveal">
		<div class="glc-section-head">
			<div class="glc-kicker"><?php echo glc_sign( 'process_title', '3 STEPS · NO PREPAYMENT' ); // phpcs:ignore WordPress.Security.EscapeOutput ?></div>
			<h2 style="margin:0;font-size:var(--wp--preset--font-size--xx-large);font-family:var(--wp--preset--font-family--georgian);font-feature-settings:'case';"><?php echo esc_html( glc_t( 'process_title' ) ); ?></h2>
		</div>
		<div class="glc-steps glc-stagger">
			<?php for ( $glc_i = 1; $glc_i <= 3; $glc_i++ ) : ?>
				<div class="glc-step">
					<h3><?php echo esc_html( glc_t( "process_{$glc_i}_t" ) ); ?></h3>
					<p><?php echo esc_html( glc_t( "process_{$glc_i}_d" ) ); ?></p>
				</div>
			<?php endfor; ?>
		</div>
	</section>

	<section class="glc-reveal">
		<div class="glc-section-head">
			<div class="glc-kicker"><?php echo glc_sign( 'routes_title', '0 → 2196 M' ); // phpcs:ignore WordPress.Security.EscapeOutput ?></div>
			<h2 style="margin:0;font-size:var(--wp--preset--font-size--xx-large);font-family:var(--wp--preset--font-family--georgian);font-feature-settings:'case';"><?php echo esc_html( glc_t( 'routes_title' ) ); ?></h2>
			<p style="margin:0;color:var(--glc-stone);max-width:56ch;"><?php echo esc_html( glc_t( 'terrain_text' ) ); ?></p>
		</div>
		<?php
		$glc_routes = [
			[ glc_t( 'route_1' ), 'Georgian Military Highway', '1740 – 2196 M', 'gergeti_trinity.jpg', 'mtskheta-mtianeti' ],
			[ glc_t( 'route_2' ), 'Sighnaghi · Alazani Valley', '≈ 800 M', 'sighnaghi.jpg', 'kakheti' ],
			[ glc_t( 'route_3' ), 'Mestia · Ushguli', '1500 – 2100 M', 'ushguli.jpg', 'samegrelo-zemo-svaneti' ],
			[ glc_t( 'route_4' ), 'Batumi · Gonio', '0 M · SEA LEVEL', 'batumi_boulevard.jpg', 'adjara' ],
		];
		?>
		<div class="glc-routes glc-stagger">
			<?php foreach ( $glc_routes as [ $glc_name, $glc_sub, $glc_elev, $glc_img, $glc_region ] ) : ?>
				<a class="glc-route" href="<?php echo esc_url( home_url( '/places/?region=' . $glc_region ) ); ?>">
					<picture>
						<source srcset="<?php echo esc_url( get_theme_file_uri( 'assets/img/routes/' . str_replace( '.jpg', '.webp', $glc_img ) ) ); ?>" type="image/webp" />
						<img src="<?php echo esc_url( get_theme_file_uri( 'assets/img/routes/' . $glc_img ) ); ?>" alt="<?php echo esc_attr( $glc_name ); ?>" loading="lazy" width="600" height="750" />
					</picture>
					<span class="glc-route-label"><strong><?php echo esc_html( $glc_name ); ?></strong><span><?php echo esc_html( $glc_sub ); ?></span><span class="glc-elev"><?php echo esc_html( $glc_elev ); ?></span></span>
				</a>
			<?php endforeach; ?>
		</div>
	</section>

	<section class="glc-reveal">
		<div class="glc-section-head">
			<div class="glc-kicker"><?php echo glc_sign( 'included_title', 'ALL-INCLUSIVE · $0 EXTRAS' ); // phpcs:ignore WordPress.Security.EscapeOutput ?></div>
			<h2 style="margin:0;font-size:var(--wp--preset--font-size--xx-large);font-family:var(--wp--preset--font-family--georgian);font-feature-settings:'case';"><?php echo esc_html( glc_t( 'included_title' ) ); ?></h2>
		</div>
		<ul class="glc-included glc-stagger" style="padding:0;margin:0;">
			<?php for ( $glc_i = 1; $glc_i <= 6; $glc_i++ ) : ?>
				<li><?php echo esc_html( glc_t( "included_{$glc_i}" ) ); ?></li>
			<?php endfor; ?>
		</ul>
	</section>

	<section class="glc-reveal">
		<div class="glc-section-head">
			<div class="glc-kicker"><?php echo glc_sign( 'testimonials_title', '★ 5.0' ); // phpcs:ignore WordPress.Security.EscapeOutput ?></div>
			<h2 style="margin:0;font-size:var(--wp--preset--font-size--xx-large);font-family:var(--wp--preset--font-family--georgian);font-feature-settings:'case';"><?php echo esc_html( glc_t( 'testimonials_title' ) ); ?></h2>
		</div>
		<?php echo do_blocks( '<!-- wp:geolander/testimonials /-->' ); ?>
	</section>

	<section class="glc-reveal">
		<div class="glc-section-head">
			<div class="glc-kicker"><?php echo glc_sign( 'faq_title', '13 Q&A' ); // phpcs:ignore WordPress.Security.EscapeOutput ?></div>
			<h2 style="margin:0;font-size:var(--wp--preset--font-size--xx-large);font-family:var(--wp--preset--font-family--georgian);font-feature-settings:'case';"><?php echo esc_html( glc_t( 'faq_title' ) ); ?></h2>
		</div>
		<?php echo do_blocks( '<!-- wp:geolander/faq-list /-->' ); ?>
	</section>

	<section class="glc-cta glc-reveal">
		<picture>
			<source srcset="<?php echo esc_url( get_theme_file_uri( 'assets/img/cta-bg.webp' ) ); ?>" type="image/webp" />
			<img src="<?php echo esc_url( get_theme_file_uri( 'assets/img/cta-bg.jpg' ) ); ?>" alt="" loading="lazy" width="1600" height="900" style="position:absolute;inset:0;z-index:-1;width:100%;height:100%;object-fit:cover;" />
		</picture>
		<h2 style="margin:0;font-size:var(--wp--preset--font-size--xx-large);font-family:var(--wp--preset--font-family--georgian);font-feature-settings:'case';max-width:20ch;"><?php echo esc_html( glc_t( 'cta_title' ) ); ?></h2>
		<p style="margin:0;color:color-mix(in srgb, var(--glc-glacier) 80%, transparent);max-width:48ch;"><?php echo esc_html( glc_t( 'cta_subtitle' ) ); ?></p>
		<p style="margin:0;"><a class="wp-element-button glc-btn" href="<?php echo esc_url( home_url( '/fleet/' ) ); ?>"><?php echo esc_html( glc_t( 'hero_cta' ) ); ?></a></p>
	</section>

</div>
