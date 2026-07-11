<?php
/**
 * Admin editing UI for cars: spec fields, the seasonal pricing grid,
 * and the photo gallery. Everything a fleet manager needs, no code.
 */

defined( 'ABSPATH' ) || exit;

class GLC_Meta_Boxes {

	public static function init() {
		add_action( 'add_meta_boxes', [ __CLASS__, 'add' ] );
		add_action( 'save_post_car', [ __CLASS__, 'save' ] );
		add_action( 'add_meta_boxes', [ __CLASS__, 'add_testimonial' ] );
		add_action( 'save_post_testimonial', [ __CLASS__, 'save_testimonial' ] );
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'assets' ] );
	}

	public static function assets( $hook ) {
		if ( ! in_array( $hook, [ 'post.php', 'post-new.php' ], true ) || 'car' !== get_current_screen()->post_type ) {
			return;
		}
		wp_enqueue_media();
		wp_enqueue_script( 'glc-admin', GLC_URL . 'assets/admin.js', [ 'jquery' ], GLC_VERSION, true );
		wp_enqueue_style( 'glc-admin', GLC_URL . 'assets/admin.css', [], GLC_VERSION );
	}

	public static function add() {
		add_meta_box( 'glc_specs', __( 'Vehicle Specifications', 'geolander' ), [ __CLASS__, 'render_specs' ], 'car', 'normal', 'high' );
		add_meta_box( 'glc_pricing', __( 'Seasonal Pricing (USD per day)', 'geolander' ), [ __CLASS__, 'render_pricing' ], 'car', 'normal', 'high' );
		add_meta_box( 'glc_gallery', __( 'Photo Gallery', 'geolander' ), [ __CLASS__, 'render_gallery' ], 'car', 'side', 'default' );
	}

	public static function render_specs( WP_Post $post ) {
		wp_nonce_field( 'glc_car_meta', 'glc_car_nonce' );
		$get = fn( $key ) => get_post_meta( $post->ID, $key, true );
		$fields = [
			'glc_registration'     => [ __( 'Registration number', 'geolander' ), 'text' ],
			'glc_year'             => [ __( 'Year', 'geolander' ), 'number' ],
			'glc_color'            => [ __( 'Color', 'geolander' ), 'text' ],
			'glc_seats'            => [ __( 'Seats', 'geolander' ), 'number' ],
			'glc_license_category' => [ __( 'License category', 'geolander' ), 'text' ],
			'glc_price_from'       => [ __( 'Headline "from" price ($/day)', 'geolander' ), 'number' ],
		];
		echo '<div class="glc-grid">';
		foreach ( $fields as $key => [ $label, $type ] ) {
			printf(
				'<p><label for="%1$s"><strong>%2$s</strong></label><br /><input type="%3$s" id="%1$s" name="%1$s" value="%4$s" class="widefat" /></p>',
				esc_attr( $key ),
				esc_html( $label ),
				esc_attr( $type ),
				esc_attr( $get( $key ) )
			);
		}
		$selects = [
			'glc_transmission' => [ __( 'Transmission', 'geolander' ), [ 'automatic' => 'Automatic', 'manual' => 'Manual' ] ],
			'glc_fuel_type'    => [ __( 'Fuel type', 'geolander' ), [ 'gasoline' => 'Gasoline', 'diesel' => 'Diesel', 'hybrid' => 'Hybrid', 'electric' => 'Electric' ] ],
		];
		foreach ( $selects as $key => [ $label, $options ] ) {
			printf( '<p><label for="%1$s"><strong>%2$s</strong></label><br /><select id="%1$s" name="%1$s" class="widefat">', esc_attr( $key ), esc_html( $label ) );
			foreach ( $options as $value => $text ) {
				printf( '<option value="%s"%s>%s</option>', esc_attr( $value ), selected( $get( $key ), $value, false ), esc_html( $text ) );
			}
			echo '</select></p>';
		}
		printf(
			'<p><label><input type="checkbox" name="glc_available" value="1"%s /> <strong>%s</strong></label></p>',
			checked( (bool) $get( 'glc_available' ), true, false ),
			esc_html__( 'Available for booking', 'geolander' )
		);
		echo '</div>';
	}

	public static function render_pricing( WP_Post $post ) {
		$pricing = get_post_meta( $post->ID, 'glc_pricing', true );
		if ( ! is_array( $pricing ) || ! $pricing ) {
			// Sensible default: the four legacy seasons, empty rates.
			$pricing = [
				[ 'label' => 'Apr 01 - Oct 31', 'from' => '04-01', 'to' => '10-31', 'rates' => [] ],
				[ 'label' => 'Nov 01 - Dec 24', 'from' => '11-01', 'to' => '12-24', 'rates' => [] ],
				[ 'label' => 'Dec 25 - Jan 05', 'from' => '12-25', 'to' => '01-05', 'rates' => [] ],
				[ 'label' => 'Jan 06 - Mar 31', 'from' => '01-06', 'to' => '03-31', 'rates' => [] ],
			];
		}
		echo '<table class="widefat glc-pricing-table"><thead><tr><th>' . esc_html__( 'Season', 'geolander' ) . '</th><th>' . esc_html__( 'From (MM-DD)', 'geolander' ) . '</th><th>' . esc_html__( 'To (MM-DD)', 'geolander' ) . '</th>';
		foreach ( GLC_Pricing::TIER_LABELS as $label ) {
			echo '<th>' . esc_html( $label ) . ' ' . esc_html__( 'days', 'geolander' ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		foreach ( $pricing as $i => $season ) {
			echo '<tr>';
			printf( '<td><input type="text" name="glc_pricing[%d][label]" value="%s" /></td>', $i, esc_attr( $season['label'] ?? '' ) );
			printf( '<td><input type="text" name="glc_pricing[%d][from]" value="%s" size="5" pattern="\d{2}-\d{2}" /></td>', $i, esc_attr( $season['from'] ?? '' ) );
			printf( '<td><input type="text" name="glc_pricing[%d][to]" value="%s" size="5" pattern="\d{2}-\d{2}" /></td>', $i, esc_attr( $season['to'] ?? '' ) );
			foreach ( GLC_Pricing::TIERS as $tier ) {
				printf(
					'<td><input type="number" step="0.01" min="0" name="glc_pricing[%d][rates][%s]" value="%s" size="4" /></td>',
					$i,
					esc_attr( $tier ),
					esc_attr( $season['rates'][ $tier ] ?? '' )
				);
			}
			echo '</tr>';
		}
		echo '</tbody></table>';
		echo '<p class="description">' . esc_html__( 'Rates are USD per day. The tier is chosen by the total rental length; each rental day is billed at its own season\'s rate.', 'geolander' ) . '</p>';
	}

	public static function render_gallery( WP_Post $post ) {
		$ids = array_filter( array_map( 'intval', (array) get_post_meta( $post->ID, 'glc_gallery', true ) ) );
		echo '<div id="glc-gallery-preview">';
		foreach ( $ids as $id ) {
			echo wp_get_attachment_image( $id, 'thumbnail' );
		}
		echo '</div>';
		printf( '<input type="hidden" id="glc_gallery" name="glc_gallery" value="%s" />', esc_attr( implode( ',', $ids ) ) );
		echo '<button type="button" class="button" id="glc-gallery-select">' . esc_html__( 'Select images', 'geolander' ) . '</button>';
	}

	public static function save( int $post_id ) {
		if ( ! isset( $_POST['glc_car_nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['glc_car_nonce'] ), 'glc_car_meta' ) ) {
			return;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		foreach ( [ 'glc_registration', 'glc_color', 'glc_license_category', 'glc_transmission', 'glc_fuel_type' ] as $key ) {
			update_post_meta( $post_id, $key, sanitize_text_field( wp_unslash( $_POST[ $key ] ?? '' ) ) );
		}
		foreach ( [ 'glc_year', 'glc_seats' ] as $key ) {
			update_post_meta( $post_id, $key, absint( $_POST[ $key ] ?? 0 ) );
		}
		update_post_meta( $post_id, 'glc_price_from', (float) ( $_POST['glc_price_from'] ?? 0 ) );
		update_post_meta( $post_id, 'glc_available', ! empty( $_POST['glc_available'] ) );

		$pricing = [];
		foreach ( (array) wp_unslash( $_POST['glc_pricing'] ?? [] ) as $row ) {
			$rates = [];
			foreach ( GLC_Pricing::TIERS as $tier ) {
				$rates[ $tier ] = (float) ( $row['rates'][ $tier ] ?? 0 );
			}
			$pricing[] = [
				'label' => sanitize_text_field( $row['label'] ?? '' ),
				'from'  => preg_match( '/^\d{2}-\d{2}$/', $row['from'] ?? '' ) ? $row['from'] : '',
				'to'    => preg_match( '/^\d{2}-\d{2}$/', $row['to'] ?? '' ) ? $row['to'] : '',
				'rates' => $rates,
			];
		}
		update_post_meta( $post_id, 'glc_pricing', $pricing );

		$gallery = array_filter( array_map( 'absint', explode( ',', sanitize_text_field( wp_unslash( $_POST['glc_gallery'] ?? '' ) ) ) ) );
		update_post_meta( $post_id, 'glc_gallery', array_values( $gallery ) );
	}

	public static function add_testimonial() {
		add_meta_box( 'glc_testimonial', __( 'Details', 'geolander' ), [ __CLASS__, 'render_testimonial' ], 'testimonial', 'normal', 'high' );
	}

	public static function render_testimonial( WP_Post $post ) {
		wp_nonce_field( 'glc_testimonial_meta', 'glc_testimonial_nonce' );
		printf(
			'<p><label><strong>%s</strong></label><br /><input type="text" name="glc_route" value="%s" class="widefat" placeholder="Tbilisi - Kazbegi - Kakheti" /></p>',
			esc_html__( 'Route', 'geolander' ),
			esc_attr( get_post_meta( $post->ID, 'glc_route', true ) )
		);
		printf(
			'<p><label><strong>%s</strong></label><br /><input type="number" name="glc_rating" min="1" max="5" value="%s" /></p>',
			esc_html__( 'Rating (1-5)', 'geolander' ),
			esc_attr( get_post_meta( $post->ID, 'glc_rating', true ) ?: 5 )
		);
	}

	public static function save_testimonial( int $post_id ) {
		if ( ! isset( $_POST['glc_testimonial_nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['glc_testimonial_nonce'] ), 'glc_testimonial_meta' ) ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		update_post_meta( $post_id, 'glc_route', sanitize_text_field( wp_unslash( $_POST['glc_route'] ?? '' ) ) );
		update_post_meta( $post_id, 'glc_rating', min( 5, max( 1, absint( $_POST['glc_rating'] ?? 5 ) ) ) );
	}
}
