<?php
/**
 * Translation review mode.
 *
 * A signed-in editor opens any page with ?glc_review=1 (or the admin-bar link),
 * clicks the sentence that reads wrong and leaves a note. Each note is stored as
 * a private "Translation notes" post with the page, locale, the exact text
 * clicked and a status a person sets. Nothing is changed on the page itself:
 * the fix still goes through the normal translation fields, by a person.
 */

defined( 'ABSPATH' ) || exit;

class GLC_Review {

	public const POST_TYPE = 'glc_review_note';
	private const QUERY    = 'glc_review';
	/** Workflow states a person sets on a note; the first is the state of a new note. */
	public const STATUSES  = [ 'open', 'accepted', 'fixed', 'declined' ];

	public static function init(): void {
		add_action( 'init', [ __CLASS__, 'register' ] );
		add_action( 'rest_api_init', [ __CLASS__, 'route' ] );
		add_action( 'wp_enqueue_scripts', [ __CLASS__, 'assets' ] );
		add_action( 'admin_bar_menu', [ __CLASS__, 'admin_bar' ], 90 );
		add_action( 'add_meta_boxes', [ __CLASS__, 'meta_box' ] );
		add_action( 'save_post_' . self::POST_TYPE, [ __CLASS__, 'save' ] );
		add_filter( 'manage_' . self::POST_TYPE . '_posts_columns', [ __CLASS__, 'columns' ] );
		add_action( 'manage_' . self::POST_TYPE . '_posts_custom_column', [ __CLASS__, 'column' ], 10, 2 );
	}

	public static function register(): void {
		register_post_type( self::POST_TYPE, [
			'labels'          => [
				'name'          => __( 'Translation notes', 'geolander' ),
				'singular_name' => __( 'Translation note', 'geolander' ),
			],
			'public'          => false,
			'show_ui'         => true,
			'show_in_menu'    => true,
			'menu_icon'       => 'dashicons-translation',
			'capability_type' => 'post',
			'map_meta_cap'    => true,
			'supports'        => [ 'title', 'editor' ],
		] );
	}

	public static function can_review(): bool {
		return is_user_logged_in() && current_user_can( 'edit_posts' );
	}

	/**
	 * This page with review mode on or off. GLC_I18n strips the language prefix from
	 * REQUEST_URI, so the URL is rebuilt through home_url(), which puts it back.
	 */
	private static function toggle_url( bool $on ): string {
		$here = home_url( (string) ( $_SERVER['REQUEST_URI'] ?? '/' ) );
		return $on ? add_query_arg( self::QUERY, '1', $here ) : remove_query_arg( self::QUERY, $here );
	}

	private static function active(): bool {
		return self::can_review() && ! is_admin() && isset( $_GET[ self::QUERY ] ); // phpcs:ignore WordPress.Security.NonceVerification
	}

	/* --------------------------------------------------------- Front end */

	public static function assets(): void {
		if ( ! self::active() ) {
			return;
		}
		$js = GLC_DIR . 'assets/review.js';
		wp_enqueue_script( 'glc-review', GLC_URL . 'assets/review.js', [], GLC_VERSION . '.' . filemtime( $js ), [ 'strategy' => 'defer', 'in_footer' => true ] );
		wp_add_inline_script( 'glc-review', 'window.glcReview = ' . wp_json_encode( [
			'rest'    => esc_url_raw( rest_url( 'geolander/v1/review-notes' ) ),
			'nonce'   => wp_create_nonce( 'wp_rest' ),
			'post'    => (int) get_queried_object_id(),
			'locale'  => class_exists( 'GLC_I18n' ) ? GLC_I18n::locale() : 'en',
			'exit'    => esc_url_raw( self::toggle_url( false ) ),
			'strings' => [
				'banner'      => glc_ui( 'review_banner' ),
				'exit'        => glc_ui( 'review_exit' ),
				'placeholder' => glc_ui( 'review_placeholder' ),
				'send'        => glc_ui( 'review_send' ),
				'cancel'      => glc_ui( 'review_cancel' ),
				'saved'       => glc_ui( 'review_saved' ),
				'error'       => glc_ui( 'review_error' ),
			],
		] ) . ';', 'before' );
	}

	public static function admin_bar( WP_Admin_Bar $bar ): void {
		if ( is_admin() || ! self::can_review() ) {
			return;
		}
		$bar->add_node( [
			'id'    => 'glc-review',
			'title' => esc_html( glc_ui( self::active() ? 'review_exit' : 'review_toggle' ) ),
			'href'  => esc_url( self::toggle_url( ! self::active() ) ),
		] );
	}

	/* -------------------------------------------------------------- REST */

	public static function route(): void {
		register_rest_route( 'geolander/v1', '/review-notes', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'create' ],
			// Cookie auth needs the wp_rest nonce; without it the user is anonymous and this returns 401.
			'permission_callback' => [ __CLASS__, 'can_review' ],
			'args'                => [
				'post'    => [ 'required' => true, 'type' => 'integer', 'minimum' => 0 ],
				'locale'  => [ 'required' => true, 'type' => 'string', 'maxLength' => 8 ],
				'quote'   => [ 'required' => true, 'type' => 'string', 'minLength' => 1, 'maxLength' => 2000 ],
				'comment' => [ 'required' => true, 'type' => 'string', 'minLength' => 1, 'maxLength' => 4000 ],
				'url'     => [ 'required' => true, 'type' => 'string', 'format' => 'uri', 'maxLength' => 500 ],
			],
		] );
	}

	public static function create( WP_REST_Request $request ) {
		$locale = sanitize_key( (string) $request['locale'] );
		if ( class_exists( 'GLC_I18n' ) && ! isset( GLC_I18n::LOCALES[ $locale ] ) ) {
			return new WP_Error( 'glc_review_locale', 'Unknown locale.', [ 'status' => 400 ] );
		}
		$url = esc_url_raw( (string) $request['url'] );
		if ( ! $url || wp_parse_url( $url, PHP_URL_HOST ) !== wp_parse_url( home_url(), PHP_URL_HOST ) ) {
			return new WP_Error( 'glc_review_url', 'The note must point at a page of this site.', [ 'status' => 400 ] );
		}
		$quote   = sanitize_textarea_field( (string) $request['quote'] );
		$comment = sanitize_textarea_field( (string) $request['comment'] );
		$post_id = (int) $request['post'];
		$id      = wp_insert_post( [
			'post_type'    => self::POST_TYPE,
			'post_status'  => 'publish',
			'post_title'   => sprintf( '[%s] %s', $locale, wp_html_excerpt( $quote, 60, '…' ) ),
			'post_content' => $comment,
			'post_author'  => get_current_user_id(),
		], true );
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		update_post_meta( $id, 'glc_rn_post', $post_id );
		update_post_meta( $id, 'glc_rn_locale', $locale );
		update_post_meta( $id, 'glc_rn_quote', $quote );
		update_post_meta( $id, 'glc_rn_url', $url );
		update_post_meta( $id, 'glc_rn_status', self::STATUSES[0] );
		return rest_ensure_response( [ 'id' => (int) $id, 'status' => self::STATUSES[0] ] );
	}

	/* ------------------------------------------------------------- Admin */

	public static function meta_box(): void {
		add_meta_box( 'glc-review-note', __( 'Note details', 'geolander' ), [ __CLASS__, 'render_box' ], self::POST_TYPE, 'side' );
	}

	public static function render_box( WP_Post $post ): void {
		wp_nonce_field( 'glc_review_save', 'glc_review_nonce' );
		$status = (string) get_post_meta( $post->ID, 'glc_rn_status', true );
		echo '<p><label for="glc_rn_status"><strong>' . esc_html__( 'Status', 'geolander' ) . '</strong></label><br /><select id="glc_rn_status" name="glc_rn_status">';
		foreach ( self::STATUSES as $s ) {
			printf( '<option value="%1$s"%2$s>%1$s</option>', esc_attr( $s ), selected( $status, $s, false ) );
		}
		echo '</select></p>';
		printf( '<p><strong>%s</strong> <code>%s</code></p>', esc_html__( 'Locale', 'geolander' ), esc_html( (string) get_post_meta( $post->ID, 'glc_rn_locale', true ) ) );
		$url = (string) get_post_meta( $post->ID, 'glc_rn_url', true );
		if ( $url ) {
			printf( '<p><a href="%s" target="_blank" rel="noopener">%s</a></p>', esc_url( $url ), esc_html__( 'Open the page', 'geolander' ) );
		}
		printf( '<p><strong>%s</strong><br />%s</p>', esc_html__( 'Text clicked', 'geolander' ), esc_html( (string) get_post_meta( $post->ID, 'glc_rn_quote', true ) ) );
	}

	public static function save( int $post_id ): void {
		if ( ! isset( $_POST['glc_review_nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['glc_review_nonce'] ), 'glc_review_save' ) ) {
			return;
		}
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		$status = sanitize_key( (string) ( $_POST['glc_rn_status'] ?? '' ) );
		if ( in_array( $status, self::STATUSES, true ) ) {
			update_post_meta( $post_id, 'glc_rn_status', $status );
		}
	}

	public static function columns( array $columns ): array {
		return array_slice( $columns, 0, 2, true )
			+ [ 'glc_rn_locale' => __( 'Locale', 'geolander' ), 'glc_rn_status' => __( 'Status', 'geolander' ), 'glc_rn_url' => __( 'Page', 'geolander' ) ]
			+ $columns;
	}

	public static function column( string $column, int $post_id ): void {
		$value = (string) get_post_meta( $post_id, $column, true );
		if ( 'glc_rn_url' === $column && $value ) {
			printf( '<a href="%s" target="_blank" rel="noopener">%s</a>', esc_url( $value ), esc_html( (string) wp_parse_url( $value, PHP_URL_PATH ) ) );
			return;
		}
		echo esc_html( $value );
	}
}
