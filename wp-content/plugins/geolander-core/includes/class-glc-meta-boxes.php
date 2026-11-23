<?php
/**
 * Admin editing UI for cars: spec fields and the photo gallery, and the
 * season prices to look at.
 *
 * The prices are shown here, not edited here. They are changed on the car's
 * own "Pricing & fees" screen, which saves through GLC_Price_Store: that is
 * what checks a table, keeps its history and refuses a save made from a page
 * that was opened before someone else's change. This screen used to post the
 * whole table back with every save of the car, so correcting a typo in the
 * description an hour after a partner changed a price would have put the old
 * prices back without a trace.
 *
 * The details and the gallery a partner may change too (GLC_Price_Store::
 * SPEC_FIELDS and the gallery) are still edited here by the owner, but they
 * are saved through the store as well, with the revision the page was opened
 * at. When someone has changed the car since, WordPress still saves the title
 * and the text, those fields are left as they are, and a notice names them and
 * says why. What only the owner may set (plate, year, the notes, the headline
 * price, the legacy number) is written directly, as before.
 */

defined( 'ABSPATH' ) || exit;

class GLC_Meta_Boxes {

	public static function init() {
		add_action( 'add_meta_boxes', [ __CLASS__, 'add' ] );
		add_action( 'save_post_car', [ __CLASS__, 'save' ] );
		add_action( 'add_meta_boxes', [ __CLASS__, 'add_testimonial' ] );
		add_action( 'save_post_testimonial', [ __CLASS__, 'save_testimonial' ] );
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'assets' ] );
		add_action( 'admin_notices', [ __CLASS__, 'notice' ] );
		add_action( 'admin_post_' . self::STATUS_ACTION, [ __CLASS__, 'status' ] );
		add_action( 'admin_post_nopriv_' . self::STATUS_ACTION, [ 'GLC_Partner_Panel', 'refuse' ] );
	}

	/** What the car edit screen asks after a save: was anything left unwritten, and which revision is the car at now? */
	private const STATUS_ACTION = 'glc_metabox_status';

	/** The answer to the last save of a car on this screen waits here for the person who made it. */
	private static function slot( int $post_id ): string {
		return 'metabox_' . $post_id;
	}

	public static function assets( $hook ) {
		if ( ! in_array( $hook, [ 'post.php', 'post-new.php' ], true ) || 'car' !== get_current_screen()->post_type ) {
			return;
		}
		wp_enqueue_media();
		wp_enqueue_script( 'glc-admin', GLC_URL . 'assets/admin.js', [ 'jquery' ], GLC_VERSION, true );
		wp_enqueue_style( 'glc-admin', GLC_URL . 'assets/admin.css', [], GLC_VERSION );
		$post_id = (int) ( get_post()->ID ?? 0 );
		wp_localize_script( 'glc-admin', 'glcCarEditor', [
			'pickTitle' => glc_admin_t( 'photos_pick_title' ),
			// The block editor saves these boxes behind the page and never shows what the server answered; the script asks here.
			'status'    => add_query_arg( [ 'action' => self::STATUS_ACTION, 'post' => $post_id, '_wpnonce' => wp_create_nonce( self::STATUS_ACTION . '_' . $post_id ) ], admin_url( 'admin-post.php' ) ),
		] );
	}

	public static function add() {
		add_meta_box( 'glc_specs', __( 'Vehicle Specifications', 'geolander' ), [ __CLASS__, 'render_specs' ], 'car', 'normal', 'high' );
		// The season rate table, to read. Only for whoever may open the panel it
		// is edited on — the owner and the car's partner. A content editor
		// (GLC_Roles) writes the words on a car and never sees its rates: the
		// box posts nothing back, so leaving it out cannot change a save.
		if ( current_user_can( GLC_Partners::CAP_PANEL ) ) {
			add_meta_box( 'glc_pricing', glc_admin_t( 'metabox_pricing_title' ), [ __CLASS__, 'render_pricing' ], 'car', 'normal', 'high' );
		}
		add_meta_box( 'glc_gallery', __( 'Photo Gallery', 'geolander' ), [ __CLASS__, 'render_gallery' ], 'car', 'side', 'default' );
		// WordPress's own "Featured image" box wrote the main photo straight to the car, past the store: no revision,
		// no entry in the history. The main photo is chosen on the car's own screen (the gallery box says so).
		// The block editor's panel of the same name is taken away in assets/admin.js.
		remove_meta_box( 'postimagediv', 'car', 'side' );
	}

	public static function render_specs( WP_Post $post ) {
		wp_nonce_field( 'glc_car_meta', 'glc_car_nonce' );
		// The revision this page shows. A save from a page that shows an older one writes none of the store's fields.
		printf( '<input type="hidden" id="glc_rev" name="glc_rev" value="%d" />', (int) GLC_Price_Store::rev( (int) $post->ID ) );
		// The block editor hides admin notices, so the answer to the last save is here too. It is only looked at,
		// not used up: that editor draws these boxes again, unseen, right after each save.
		self::render_notice( GLC_Partner_Panel::peek_flash( self::slot( (int) $post->ID ) ) );
		$get = fn( $key ) => get_post_meta( $post->ID, $key, true );
		$fields = [
			'glc_registration'     => [ __( 'Registration number', 'geolander' ), 'text' ],
			'glc_year'             => [ __( 'Year', 'geolander' ), 'number' ],
			'glc_color'            => [ __( 'Color', 'geolander' ), 'text' ],
			'glc_seats'            => [ __( 'Seats', 'geolander' ), 'number' ],
			'glc_license_category' => [ __( 'License category', 'geolander' ), 'text' ],
			'glc_drivetrain'       => [ __( 'Drivetrain (for example AWD or 4WD)', 'geolander' ), 'text' ],
			'glc_fuel_economy_note'=> [ __( 'Fuel-economy note (only when owner verified)', 'geolander' ), 'text' ],
			'glc_price_from'       => [ __( 'Headline "from" price ($/day)', 'geolander' ), 'number' ],
			'glc_whatsapp_number'  => [ glc_admin_t( 'metabox_legacy_whatsapp' ), 'text' ],
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

	/** The season table as it is stored, to read. No field of it is posted back with the car. */
	public static function render_pricing( WP_Post $post ) {
		GLC_Partner_Car::render_table( GLC_Price_Store::read_pricing( (int) $post->ID ), GLC_Price_Store::read_pricing( (int) $post->ID ) );
		echo '<p class="description">' . esc_html( glc_admin_t( 'metabox_pricing_note' ) ) . '</p>';
		// A car that has not been saved once has no screen of its own yet.
		if ( 'auto-draft' !== $post->post_status ) {
			printf( '<p><a class="button" href="%s">%s</a></p>', esc_url( GLC_Partner_Car::url( (int) $post->ID ) ), esc_html( glc_admin_t( 'metabox_edit_pricing' ) ) );
		}
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
		echo '<p class="description">' . esc_html( glc_admin_t( 'metabox_main_photo' ) );
		// A car that has not been saved once has no screen of its own yet.
		if ( 'auto-draft' !== $post->post_status ) {
			printf( ' <a href="%s">%s</a>', esc_url( GLC_Partner_Car::url( (int) $post->ID ) . '#glc-photos' ), esc_html( glc_admin_t( 'metabox_main_photo_link' ) ) );
		}
		echo '</p>';
	}

	/** "Not written" with the fields and the reasons, as a notice. Nothing when the last save left nothing out. */
	private static function render_notice( array $flash ): void {
		if ( empty( $flash['lines'] ) ) {
			return;
		}
		echo '<div class="notice notice-error inline glc-store-notice" role="alert"><ul>';
		foreach ( (array) $flash['lines'] as $line ) {
			echo '<li>' . esc_html( (string) $line ) . '</li>';
		}
		echo '</ul></div>';
	}

	/** The classic car edit screen: the answer to the save that led here. */
	public static function notice(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'post' !== $screen->base || 'car' !== $screen->post_type || $screen->is_block_editor() || ! get_post() ) {
			return;
		}
		self::render_notice( GLC_Partner_Panel::take_flash( self::slot( (int) get_post()->ID ) ) );
	}

	/**
	 * For the script on the block editor's car screen, after it has saved the
	 * boxes: the lines to show (none when nothing was left out), and the car's
	 * new revision when this person's save has just been written. Only then:
	 * the page then shows exactly what is stored. A revision moved by someone
	 * else is never handed to a page that still shows the older values, or its
	 * next save would pass the check it exists to fail.
	 * Only for someone who may edit that car.
	 */
	public static function status(): void {
		$post_id = isset( $_GET['post'] ) && is_string( $_GET['post'] ) && ctype_digit( $_GET['post'] ) ? (int) $_GET['post'] : 0;
		$nonce   = isset( $_GET['_wpnonce'] ) && is_string( $_GET['_wpnonce'] ) ? sanitize_key( $_GET['_wpnonce'] ) : '';
		if ( ! $post_id || 'car' !== get_post_type( $post_id ) || ! current_user_can( 'edit_post', $post_id ) || ! wp_verify_nonce( $nonce, self::STATUS_ACTION . '_' . $post_id ) ) {
			GLC_Partner_Panel::refuse();
		}
		$flash = GLC_Partner_Panel::take_flash( self::slot( $post_id ) );
		nocache_headers();
		wp_send_json( [
			'refused' => ! empty( $flash['lines'] ),
			'lines'   => array_values( array_map( 'strval', (array) ( $flash['lines'] ?? [] ) ) ),
			'rev'     => isset( $flash['rev'] ) ? (int) $flash['rev'] : null,
		] );
	}

	/**
	 * What the posted form would change of the fields the store keeps: only the
	 * ones that differ from what is stored. A save that changes none of them
	 * does not go to the store at all, so correcting the description of a car
	 * a partner has just repriced is not refused for a field nobody touched.
	 *
	 * @return array [ changes for GLC_Price_Store::write(), names of the fields in them ]
	 */
	private static function store_changes( int $post_id ): array {
		$stored = GLC_Price_Store::read( $post_id, 'specs' );
		$posted = [];
		foreach ( [ 'glc_color', 'glc_license_category', 'glc_transmission', 'glc_drivetrain', 'glc_fuel_type', 'glc_seats' ] as $key ) {
			$posted[ $key ] = sanitize_text_field( wp_unslash( (string) ( $_POST[ $key ] ?? '' ) ) );
		}
		$posted['glc_available'] = ! empty( $_POST['glc_available'] );
		// The dated facts are in the other box, which has its own nonce; without it they were not on the page.
		if ( isset( $_POST['glc_evidence_nonce'] ) && is_string( $_POST['glc_evidence_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['glc_evidence_nonce'] ) ), 'glc_vehicle_evidence' ) ) {
			foreach ( array_keys( GLC_Price_Store::SPEC_FIELDS, 'evidence', true ) as $key ) {
				$posted[ $key ] = sanitize_text_field( wp_unslash( (string) ( $_POST[ $key ] ?? '' ) ) );
			}
		}
		$specs = [];
		foreach ( $posted as $key => $value ) {
			// An empty seats field on a car that has none is no change; neither is "0".
			$same = 'glc_seats' === $key ? ( ctype_digit( $value ) || '' === $value ) && (int) $value === $stored[ $key ] : $value === $stored[ $key ];
			if ( ! $same ) {
				$specs[ $key ] = $value;
			}
		}
		$changes = $specs ? [ 'specs' => $specs ] : [];
		$names   = array_map( static fn( string $key ): string => glc_admin_t( GLC_Price_Store::spec_label_key( $key ) ), array_keys( $specs ) );

		$gallery = array_values( array_filter( array_map( 'absint', explode( ',', sanitize_text_field( wp_unslash( (string) ( $_POST['glc_gallery'] ?? '' ) ) ) ) ) ) );
		if ( $gallery !== GLC_Price_Store::read( $post_id, 'photos' )['gallery'] ) {
			$changes['photos'] = [ 'gallery' => $gallery ];
			$names[]           = glc_admin_t( 'metabox_gallery_name' );
		}
		return [ $changes, $names ];
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

		// Phone keeps its leading '+': GLC_Gateway_WhatsApp::url() needs it, and
		// stripping it here is what broke the deep link the first time.
		update_post_meta( $post_id, 'glc_whatsapp_number', preg_replace( '/[^0-9+]/', '', (string) wp_unslash( $_POST['glc_whatsapp_number'] ?? '' ) ) );

		// What only the owner sets: written directly, as before.
		foreach ( [ 'glc_registration', 'glc_fuel_economy_note' ] as $key ) {
			update_post_meta( $post_id, $key, sanitize_text_field( wp_unslash( $_POST[ $key ] ?? '' ) ) );
		}
		update_post_meta( $post_id, 'glc_year', absint( $_POST['glc_year'] ?? 0 ) );
		update_post_meta( $post_id, 'glc_price_from', (float) ( $_POST['glc_price_from'] ?? 0 ) );

		// The season table is not written here any more, whatever the request carries:
		// see the note at the top of this file. GLC_Price_Store is its only writer.

		// What a partner may change too: through the store, under the revision this page was opened at.
		[ $changes, $names ] = self::store_changes( $post_id );
		if ( ! $changes ) {
			return;
		}
		$rev    = isset( $_POST['glc_rev'] ) && is_string( $_POST['glc_rev'] ) && ctype_digit( $_POST['glc_rev'] ) ? (int) $_POST['glc_rev'] : null;
		$result = GLC_Price_Store::write( $post_id, $changes, [ 'source' => 'metabox', 'rev' => $rev ] );
		if ( is_wp_error( $result ) ) {
			$reasons = array_values( array_filter( array_column( (array) ( $result->get_error_data()['errors'][ $post_id ] ?? [] ), 'message' ) ) ) ?: [ $result->get_error_message() ];
			GLC_Partner_Panel::flash( self::slot( $post_id ), [
				'ok'    => false,
				'lines' => array_merge( [ sprintf( glc_admin_t( 'metabox_not_written' ), implode( glc_admin_t( 'price_join' ), $names ) ) ], $reasons ),
			] );
		} elseif ( isset( $result['cars'][ $post_id ] ) ) {
			// Written: the page that sent this now shows what is stored, so it may carry the new revision (see status()).
			GLC_Partner_Panel::flash( self::slot( $post_id ), [ 'ok' => true, 'rev' => (int) $result['cars'][ $post_id ]['rev'] ] );
		}
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
