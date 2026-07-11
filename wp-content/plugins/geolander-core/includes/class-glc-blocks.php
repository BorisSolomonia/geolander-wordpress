<?php
/**
 * Dynamic blocks: fleet grid, booking widget, car gallery/specs/pricing,
 * FAQ, testimonials, places grid. Server-rendered; the booking widget
 * hydrates with assets/booking.js for live quotes.
 */

defined( 'ABSPATH' ) || exit;

/** Georgian UI string helper with graceful fallback if the theme is absent. */
function glc_ui( string $key ): string {
	return function_exists( 'glc_t' ) ? glc_t( $key ) : $key;
}

class GLC_Blocks {

	public static function init() {
		add_action( 'init', [ __CLASS__, 'register' ] );
		add_action( 'wp_enqueue_scripts', [ __CLASS__, 'assets' ] );
	}

	public static function register() {
		$blocks = [
			'fleet-grid'     => [ 'render_callback' => [ __CLASS__, 'fleet_grid' ], 'attributes' => [ 'count' => [ 'type' => 'integer', 'default' => -1 ] ] ],
			'booking-widget' => [ 'render_callback' => [ __CLASS__, 'booking_widget' ] ],
			'car-gallery'    => [ 'render_callback' => [ __CLASS__, 'car_gallery' ] ],
			'car-specs'      => [ 'render_callback' => [ __CLASS__, 'car_specs' ] ],
			'price-table'    => [ 'render_callback' => [ __CLASS__, 'price_table' ] ],
			'faq-list'       => [ 'render_callback' => [ __CLASS__, 'faq_list' ] ],
			'testimonials'   => [ 'render_callback' => [ __CLASS__, 'testimonials' ] ],
			'places-grid'    => [ 'render_callback' => [ __CLASS__, 'places_grid' ] ],
		];
		foreach ( $blocks as $name => $args ) {
			register_block_type( 'geolander/' . $name, $args );
		}
	}

	public static function assets() {
		if ( is_singular( 'car' ) ) {
			wp_enqueue_script( 'glc-booking', GLC_URL . 'assets/booking.js', [], (string) filemtime( GLC_DIR . 'assets/booking.js' ), [ 'strategy' => 'defer' ] );
			$ads_id    = GLC_Settings::get( 'ads_id' );
			$ads_label = GLC_Settings::get( 'ads_conversion_label' );
			wp_localize_script( 'glc-booking', 'glcBooking', [
				'restQuote'    => rest_url( 'geolander/v1/quote' ),
				'restCheckout' => rest_url( 'geolander/v1/checkout' ),
				'adsSendTo'    => $ads_id && $ads_label ? "{$ads_id}/{$ads_label}" : '',
				'carId'        => get_the_ID(),
				'i18n'         => [
					'days'       => glc_ui( 'days' ),
					'perDay'     => glc_ui( 'per_day' ),
					'quoteError' => glc_ui( 'quote_error' ),
					'nextTitle'  => glc_ui( 'whats_next_title' ),
					'nextText'   => glc_ui( 'whats_next_text' ),
				],
			] );
		}
	}

	/* -------------------------------------------------------------- Dates */

	private static function requested_dates(): array {
		$from = isset( $_GET['from'] ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $_GET['from'] ) ? $_GET['from'] : '';
		$to   = isset( $_GET['to'] ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $_GET['to'] ) ? $_GET['to'] : '';
		if ( $from && $to && $to <= $from ) {
			$to = '';
		}
		return [ $from, $to ];
	}

	/* --------------------------------------------------------- Fleet grid */

	public static function fleet_grid( array $attrs ): string {
		[ $from, $to ] = self::requested_dates();

		$cars = get_posts( [
			'post_type'      => 'car',
			'posts_per_page' => (int) ( $attrs['count'] ?? -1 ),
			'orderby'        => 'menu_order',
			'order'          => 'ASC',
		] );
		if ( ! $cars ) {
			return '';
		}

		ob_start();
		echo '<div class="glc-fleet-grid glc-stagger">';
		foreach ( $cars as $i => $car ) {
			echo self::card( $car, $i + 1, $from, $to ); // phpcs:ignore WordPress.Security.EscapeOutput
		}
		echo '</div>';
		return ob_get_clean();
	}

	private static function card( WP_Post $car, int $num, string $from, string $to ): string {
		$id           = $car->ID;
		$registration = get_post_meta( $id, 'glc_registration', true );
		$year         = get_post_meta( $id, 'glc_year', true );
		$seats        = get_post_meta( $id, 'glc_seats', true );
		$transmission = get_post_meta( $id, 'glc_transmission', true );
		$fuel         = get_post_meta( $id, 'glc_fuel_type', true );
		$available    = (bool) get_post_meta( $id, 'glc_available', true );
		$price_from   = (float) get_post_meta( $id, 'glc_price_from', true );
		$brand_title  = preg_replace( '/\s\d{4}$/', '', $car->post_title );

		$url = get_permalink( $id );
		if ( $from && $to ) {
			$url = add_query_arg( [ 'from' => $from, 'to' => $to ], $url );
		}

		$quote = ( $from && $to ) ? GLC_Pricing::quote( $id, $from, $to ) : null;

		ob_start();
		?>
		<article class="glc-card">
			<div class="glc-card-media">
				<span class="glc-card-num"><?php printf( '%02d', $num ); ?></span>
				<?php if ( has_post_thumbnail( $id ) ) : ?>
					<?php echo get_the_post_thumbnail( $id, 'glc-card', [ 'loading' => $num <= 3 ? 'eager' : 'lazy' ] ); ?>
				<?php else : ?>
					<div class="glc-no-photo"><?php echo esc_html( glc_ui( 'photos_soon' ) ); ?></div>
				<?php endif; ?>
				<?php if ( $registration && function_exists( 'glc_plate' ) ) : ?>
					<span style="position:absolute;bottom:0.7rem;inset-inline-start:0.9rem;"><?php echo glc_plate( $registration, '0.8rem' ); // phpcs:ignore WordPress.Security.EscapeOutput ?></span>
				<?php endif; ?>
				<?php if ( ! $available ) : ?>
					<div class="glc-unavailable-veil"><?php echo esc_html( glc_ui( 'unavailable' ) ); ?></div>
				<?php endif; ?>
			</div>
			<div class="glc-card-body">
				<h3 class="glc-card-title"><a href="<?php echo esc_url( $url ); ?>"><?php echo esc_html( $brand_title ); ?> <span class="glc-year"><?php echo esc_html( $year ); ?></span></a></h3>
				<div class="glc-chips">
					<span class="glc-chip glc-chip--4x4">4x4</span>
					<span class="glc-chip"><?php echo esc_html( glc_ui( $transmission ?: 'automatic' ) ); ?></span>
					<span class="glc-chip"><?php echo esc_html( $seats . ' ' . glc_ui( 'seats' ) ); ?></span>
					<span class="glc-chip"><?php echo esc_html( glc_ui( $fuel ?: 'gasoline' ) ); ?></span>
				</div>
				<div class="glc-card-price">
					<?php if ( $quote ) : ?>
						<span class="glc-price glc-price--total">$<?php echo esc_html( number_format( $quote['total'], 0 ) ); ?>
							<span class="glc-price-unit">$<?php echo esc_html( number_format( $quote['per_day_avg'], 0 ) ); ?><?php echo esc_html( glc_ui( 'per_day' ) ); ?> · <?php echo esc_html( $quote['days'] . ' ' . glc_ui( 'days' ) ); ?></span>
						</span>
					<?php else : ?>
						<span class="glc-price">$<?php echo esc_html( number_format( $price_from, 0 ) ); ?>
							<span class="glc-price-unit"><?php echo esc_html( glc_ui( 'from_per_day' ) ); ?></span>
						</span>
					<?php endif; ?>
				</div>
				<span class="glc-microline">✓ <?php echo esc_html( glc_ui( 'trust_cancel' ) ); ?> · ✓ <?php echo esc_html( glc_ui( 'trust_insurance' ) ); ?></span>
			</div>
		</article>
		<?php
		return ob_get_clean();
	}

	/* ----------------------------------------------------- Booking widget */

	public static function booking_widget(): string {
		$id = get_the_ID();
		if ( ! $id || 'car' !== get_post_type( $id ) ) {
			return '';
		}
		[ $from, $to ] = self::requested_dates();
		if ( ! $from || ! $to ) {
			$from = current_datetime()->modify( '+3 days' )->format( 'Y-m-d' );
			$to   = current_datetime()->modify( '+8 days' )->format( 'Y-m-d' );
		}
		$quote = GLC_Pricing::quote( $id, $from, $to );
		$today = current_datetime()->format( 'Y-m-d' );

		ob_start();
		?>
		<div class="glc-booking" id="glc-booking" data-car="<?php echo esc_attr( $id ); ?>">
			<h2><?php echo esc_html( glc_ui( 'booking_title' ) ); ?></h2>
			<div class="glc-field">
				<label for="glc-b-from"><?php echo esc_html( glc_ui( 'pickup_date' ) ); ?></label>
				<input type="date" id="glc-b-from" min="<?php echo esc_attr( $today ); ?>" value="<?php echo esc_attr( $from ); ?>" />
			</div>
			<div class="glc-field">
				<label for="glc-b-to"><?php echo esc_html( glc_ui( 'return_date' ) ); ?></label>
				<input type="date" id="glc-b-to" min="<?php echo esc_attr( $today ); ?>" value="<?php echo esc_attr( $to ); ?>" />
			</div>
			<div class="glc-field">
				<label for="glc-b-name"><?php echo esc_html( glc_ui( 'your_name' ) ); ?></label>
				<input type="text" id="glc-b-name" autocomplete="name" />
			</div>
			<div class="glc-booking-lines" id="glc-b-lines" <?php echo $quote ? '' : 'hidden'; ?>>
				<div class="glc-line"><span><?php echo esc_html( glc_ui( 'total_days' ) ); ?></span><span id="glc-b-days"><?php echo esc_html( $quote['days'] ?? '' ); ?></span></div>
				<div class="glc-line"><span><?php echo esc_html( glc_ui( 'price_per_day' ) ); ?></span><span id="glc-b-perday">$<?php echo esc_html( number_format( $quote['per_day_avg'] ?? 0, 0 ) ); ?></span></div>
				<div class="glc-line glc-line--total"><span><?php echo esc_html( glc_ui( 'total_price' ) ); ?></span><span class="glc-amount" id="glc-b-total">$<?php echo esc_html( number_format( $quote['total'] ?? 0, 0 ) ); ?></span></div>
			</div>
			<p class="glc-micro" id="glc-b-error" hidden></p>
			<button type="button" id="glc-b-submit"><?php echo esc_html( glc_ui( 'book_whatsapp' ) ); ?></button>
			<p class="glc-micro"><strong>✓</strong> <?php echo esc_html( glc_ui( 'no_prepayment' ) ); ?><br /><strong>✓</strong> <?php echo esc_html( glc_ui( 'free_cancellation' ) ); ?><br /><?php echo esc_html( glc_ui( 'price_locked' ) ); ?></p>
			<div id="glc-b-next" hidden style="background:#eef5ea;border-radius:10px;padding:0.9rem;font-size:0.85rem;">
				<strong id="glc-b-next-title"></strong>
				<p style="margin:0.3rem 0 0;" id="glc-b-next-text"></p>
			</div>
		</div>

		<div class="glc-bar" id="glc-bar">
			<span class="glc-bar-price">
				<strong id="glc-bar-total"><?php echo $quote ? '$' . esc_html( number_format( $quote['total'], 0 ) ) : '$' . esc_html( number_format( (float) get_post_meta( $id, 'glc_price_from', true ), 0 ) ) . esc_html( glc_ui( 'from_per_day' ) ); ?></strong>
				<span id="glc-bar-dates"><?php echo $quote ? esc_html( "{$from} → {$to}" ) : esc_html( glc_ui( 'select_dates' ) ); ?></span>
			</span>
			<a href="#glc-booking" id="glc-bar-cta"><?php echo esc_html( glc_ui( 'book_whatsapp' ) ); ?></a>
		</div>
		<?php
		return ob_get_clean();
	}

	/* -------------------------------------------------------- Car gallery */

	public static function car_gallery(): string {
		$id      = get_the_ID();
		$gallery = array_filter( array_map( 'intval', (array) get_post_meta( $id, 'glc_gallery', true ) ) );
		if ( ! $gallery ) {
			return '<div class="glc-gallery"><div class="glc-no-photo" style="grid-column:1/-1;aspect-ratio:21/9;border-radius:var(--glc-radius);">' . esc_html( glc_ui( 'photos_soon' ) ) . '</div></div>';
		}
		$gallery = array_slice( $gallery, 0, 5 );
		$out     = '<div class="glc-gallery">';
		foreach ( $gallery as $i => $att ) {
			$full = wp_get_attachment_image_url( $att, 'full' );
			$out .= sprintf(
				'<a href="%s" target="_blank" rel="noopener">%s</a>',
				esc_url( $full ),
				wp_get_attachment_image( $att, 0 === $i ? 'glc-hero' : 'glc-card', false, [ 'loading' => 0 === $i ? 'eager' : 'lazy', 'fetchpriority' => 0 === $i ? 'high' : 'auto' ] )
			);
		}
		return $out . '</div>';
	}

	/* ---------------------------------------------------------- Car specs */

	public static function car_specs(): string {
		$id    = get_the_ID();
		$specs = [
			'spec_year'         => get_post_meta( $id, 'glc_year', true ),
			'spec_seats'        => get_post_meta( $id, 'glc_seats', true ),
			'spec_transmission' => glc_ui( get_post_meta( $id, 'glc_transmission', true ) ?: 'automatic' ),
			'spec_fuel'         => glc_ui( get_post_meta( $id, 'glc_fuel_type', true ) ?: 'gasoline' ),
			'spec_color'        => get_post_meta( $id, 'glc_color', true ),
			'spec_body'         => implode( ', ', wp_get_post_terms( $id, 'car_body_type', [ 'fields' => 'names' ] ) ),
		];
		$out = '<dl class="glc-specs">';
		foreach ( $specs as $label => $value ) {
			if ( '' === (string) $value ) {
				continue;
			}
			$out .= '<div class="glc-spec"><dt>' . esc_html( glc_ui( $label ) ) . '</dt><dd>' . esc_html( $value ) . '</dd></div>';
		}
		return $out . '</dl>';
	}

	/* -------------------------------------------------------- Price table */

	public static function price_table(): string {
		$id      = get_the_ID();
		$pricing = get_post_meta( $id, 'glc_pricing', true );
		if ( ! is_array( $pricing ) || ! $pricing ) {
			return '';
		}
		[ $from, $to ] = self::requested_dates();
		$active_tier   = '';
		$active_labels = [];
		if ( $from && $to ) {
			$quote = GLC_Pricing::quote( $id, $from, $to );
			if ( $quote ) {
				$active_tier   = $quote['tier'];
				$active_labels = array_keys( $quote['breakdown'] );
			}
		}

		$out  = '<table class="glc-price-table"><thead><tr><th>' . esc_html( glc_ui( 'season' ) ) . '</th>';
		foreach ( GLC_Pricing::TIER_LABELS as $label ) {
			$out .= '<th>' . esc_html( $label ) . ' ' . esc_html( glc_ui( 'days_label' ) ) . '</th>';
		}
		$out .= '</tr></thead><tbody>';
		foreach ( $pricing as $season ) {
			$out .= '<tr><td>' . esc_html( $season['label'] ?? '' ) . '</td>';
			foreach ( GLC_Pricing::TIERS as $tier ) {
				$is_active = $tier === $active_tier && in_array( $season['label'] ?? '', $active_labels, true );
				$out      .= sprintf(
					'<td%s>$%s</td>',
					$is_active ? ' class="glc-active-cell"' : '',
					esc_html( number_format( (float) ( $season['rates'][ $tier ] ?? 0 ), 0 ) )
				);
			}
			$out .= '</tr>';
		}
		return $out . '</tbody></table>';
	}

	/* ---------------------------------------------------------------- FAQ */

	public static function faq_list(): string {
		$faqs = get_posts( [ 'post_type' => 'faq', 'posts_per_page' => 50, 'orderby' => 'menu_order', 'order' => 'ASC' ] );
		if ( ! $faqs ) {
			return '';
		}
		$out = '<div class="glc-faq">';
		foreach ( $faqs as $i => $faq ) {
			$out .= sprintf(
				'<details%s><summary>%s</summary><div class="glc-answer">%s</div></details>',
				0 === $i ? ' open' : '',
				esc_html( $faq->post_title ),
				wp_kses_post( wpautop( $faq->post_content ) )
			);
		}
		return $out . '</div>';
	}

	/* ------------------------------------------------------- Testimonials */

	public static function testimonials(): string {
		$quotes = get_posts( [ 'post_type' => 'testimonial', 'posts_per_page' => 6, 'orderby' => 'menu_order', 'order' => 'ASC' ] );
		if ( ! $quotes ) {
			return '';
		}
		$out = '<div class="glc-testimonials glc-stagger">';
		foreach ( $quotes as $quote ) {
			$rating = min( 5, max( 1, (int) get_post_meta( $quote->ID, 'glc_rating', true ) ?: 5 ) );
			$out   .= sprintf(
				'<figure class="glc-quote"><span class="glc-stars" aria-label="%1$d/5">%2$s</span><blockquote>%3$s</blockquote><figcaption><strong>%4$s</strong><span>%5$s</span></figcaption></figure>',
				$rating,
				esc_html( str_repeat( '★', $rating ) ),
				esc_html( wp_strip_all_tags( $quote->post_content ) ),
				esc_html( $quote->post_title ),
				esc_html( get_post_meta( $quote->ID, 'glc_route', true ) )
			);
		}
		return $out . '</div>';
	}

	/* ------------------------------------------------------------- Places */

	public static function places_grid(): string {
		$region  = isset( $_GET['region'] ) ? sanitize_title( $_GET['region'] ) : '';
		$args    = [ 'post_type' => 'place', 'posts_per_page' => -1, 'orderby' => 'menu_order', 'order' => 'ASC' ];
		if ( $region ) {
			$args['tax_query'] = [ [ 'taxonomy' => 'place_region', 'field' => 'slug', 'terms' => $region ] ];
		}
		$places  = get_posts( $args );
		$regions = get_terms( [ 'taxonomy' => 'place_region', 'hide_empty' => true ] );

		ob_start();
		if ( $regions && ! is_wp_error( $regions ) ) {
			echo '<div class="glc-chips" style="margin-bottom:1.6rem;">';
			printf(
				'<a class="glc-chip%s" href="%s" style="text-decoration:none;">%s</a>',
				$region ? '' : ' glc-chip--4x4',
				esc_url( get_post_type_archive_link( 'place' ) ),
				esc_html( glc_ui( 'all_regions' ) )
			);
			foreach ( $regions as $term ) {
				printf(
					'<a class="glc-chip%s" href="%s" style="text-decoration:none;">%s</a>',
					$region === $term->slug ? ' glc-chip--4x4' : '',
					esc_url( add_query_arg( 'region', $term->slug, get_post_type_archive_link( 'place' ) ) ),
					esc_html( $term->name )
				);
			}
			echo '</div>';
		}
		echo '<div class="glc-places-grid glc-stagger">';
		foreach ( $places as $place ) {
			$terms = wp_get_post_terms( $place->ID, 'place_region', [ 'fields' => 'names' ] );
			?>
			<article class="glc-place-card">
				<?php if ( $terms ) : ?><span class="glc-region-chip"><?php echo esc_html( $terms[0] ); ?></span><?php endif; ?>
				<div class="glc-card-media">
					<?php if ( has_post_thumbnail( $place->ID ) ) : ?>
						<?php echo get_the_post_thumbnail( $place->ID, 'glc-card', [ 'loading' => 'lazy' ] ); ?>
					<?php else : ?>
						<div class="glc-no-photo"><?php echo esc_html( $place->post_title ); ?></div>
					<?php endif; ?>
				</div>
				<div class="glc-card-body">
					<h3><a href="<?php echo esc_url( get_permalink( $place->ID ) ); ?>" style="text-decoration:none;color:inherit;"><?php echo esc_html( $place->post_title ); ?></a></h3>
					<p><?php echo esc_html( wp_trim_words( $place->post_excerpt ?: $place->post_content, 22 ) ); ?></p>
				</div>
			</article>
			<?php
		}
		echo '</div>';
		return ob_get_clean();
	}
}
