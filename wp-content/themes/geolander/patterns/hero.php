<?php
/**
 * Title: Hero
 * Slug: geolander/hero
 * Inserter: no
 */
$glc_today    = current_datetime()->format( 'Y-m-d' );
$glc_default1 = current_datetime()->modify( '+3 days' )->format( 'Y-m-d' );
$glc_default2 = current_datetime()->modify( '+8 days' )->format( 'Y-m-d' );
?>
<section class="glc-hero">
	<div class="glc-hero-media">
		<picture>
			<source srcset="<?php echo esc_url( get_theme_file_uri( 'assets/img/hero.webp' ) ); ?>" type="image/webp" />
			<img src="<?php echo esc_url( get_theme_file_uri( 'assets/img/hero.jpg' ) ); ?>" alt="Gergeti Trinity Church, Kazbegi — Caucasus mountains" fetchpriority="high" width="1600" height="1067" />
		</picture>
	</div>
	<div class="glc-hero-inner">
		<span class="glc-hero-elev">TBILISI 41.69°N 44.80°E · <strong>0 → 2196 M</strong> · 4X4</span>
		<h1><?php echo esc_html( glc_t( 'hero_title' ) ); ?></h1>
		<p class="glc-hero-slogan"><?php echo esc_html( glc_t( 'slogan' ) ); ?></p>
		<p class="glc-hero-sub"><?php echo esc_html( glc_t( 'hero_subtitle' ) ); ?></p>
		<?php // One honest range instead of a price on every car. ?>
		<p class="glc-hero-range">
			<?php echo esc_html( glc_t( 'price_range_label' ) ); ?>
			<strong><?php echo esc_html( class_exists( 'GLC_Format' ) ? GLC_Format::range_display() : '' ); ?></strong>
			<span><?php echo esc_html( glc_t( 'per_day' ) ); ?></span>
		</p>

		<form class="glc-hero-widget" action="<?php echo esc_url( home_url( '/fleet/' ) ); ?>" method="get">
			<div class="glc-field">
				<label for="glc-from"><?php echo esc_html( glc_t( 'pickup_date' ) ); ?></label>
				<input type="date" id="glc-from" name="from" min="<?php echo esc_attr( $glc_today ); ?>" value="<?php echo esc_attr( $glc_default1 ); ?>" />
			</div>
			<div class="glc-field">
				<label for="glc-to"><?php echo esc_html( glc_t( 'return_date' ) ); ?></label>
				<input type="date" id="glc-to" name="to" min="<?php echo esc_attr( $glc_today ); ?>" value="<?php echo esc_attr( $glc_default2 ); ?>" />
			</div>
			<button type="submit" class="wp-element-button glc-btn"><?php echo esc_html( glc_t( 'hero_cta' ) ); ?></button>
		</form>

		<div class="glc-trust">
			<span><?php echo esc_html( glc_t( 'trust_cancel' ) ); ?></span>
			<span><?php echo esc_html( glc_t( 'trust_insurance' ) ); ?></span>
			<span><?php echo esc_html( glc_t( 'trust_delivery' ) ); ?></span>
			<span><?php echo esc_html( glc_t( 'trust_support' ) ); ?></span>
		</div>
	</div>
</section>
