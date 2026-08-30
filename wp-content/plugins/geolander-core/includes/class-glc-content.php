<?php
/**
 * Per-locale content (Phase B of the i18n work).
 *
 * The UI chrome was already fully translated (108 keys x 7 locales), but the
 * CONTENT — car descriptions, place descriptions, FAQ questions/answers — only
 * ever existed in English. That is the real source of "Georgian header, English
 * body": not a state bug, just untranslated copy.
 *
 * Storage: one post meta per field per locale (glc_body_ka, glc_title_ru, …),
 * matching the plugin's existing meta conventions — no new dependency, no schema
 * change, and the English original stays the canonical post_title/post_content.
 *
 * Fallback is total: an empty or missing translation returns the English
 * original, so an untranslated field renders exactly as it does today. Adding a
 * translation is purely additive and can never break a page.
 *
 * Titles: car titles are model names ("Subaru Forester 2020") and are never
 * translated. Place and FAQ titles are prose and are.
 */

defined( 'ABSPATH' ) || exit;

class GLC_Content {

	/** Post type => translatable fields. */
	private const TRANSLATABLE = [
		'car'   => [ 'body' ],
		'place' => [ 'title', 'body' ],
		'faq'   => [ 'title', 'body' ],
		'city'  => [ 'title', 'body' ],
	];

	public static function init() {
		add_action( 'init', [ __CLASS__, 'register_meta' ] );
		add_action( 'add_meta_boxes', [ __CLASS__, 'meta_boxes' ] );
		add_action( 'save_post', [ __CLASS__, 'save' ], 10, 2 );
	}

	/** Locales that need a translation (every locale except the English source). */
	public static function locales(): array {
		if ( ! class_exists( 'GLC_I18n' ) ) {
			return [];
		}
		return array_values( array_diff( array_keys( GLC_I18n::LOCALES ), [ GLC_I18n::DEFAULT_LOCALE ] ) );
	}

	public static function key( string $field, string $locale ): string {
		return "glc_{$field}_{$locale}";
	}

	public static function register_meta() {
		foreach ( self::TRANSLATABLE as $post_type => $fields ) {
			foreach ( $fields as $field ) {
				foreach ( self::locales() as $locale ) {
					register_post_meta( $post_type, self::key( $field, $locale ), [
						'type'          => 'string',
						'single'        => true,
						'show_in_rest'  => true,
						'auth_callback' => fn() => current_user_can( 'edit_posts' ),
					] );
				}
			}
		}
	}

	private static function current_locale(): string {
		return class_exists( 'GLC_I18n' ) ? GLC_I18n::locale() : 'en';
	}

	/**
	 * Localized title, falling back to the English post_title.
	 *
	 * Places carry a legacy `glc_name_ka` from the original migration; prefer the
	 * new uniform key, then that legacy value, so existing Georgian place names
	 * keep working without being re-entered.
	 */
	public static function title( $post ): string {
		$post = get_post( $post );
		if ( ! $post ) {
			return '';
		}
		$locale = self::current_locale();
		if ( ! class_exists( 'GLC_I18n' ) || GLC_I18n::DEFAULT_LOCALE === $locale ) {
			return $post->post_title;
		}
		$value = (string) get_post_meta( $post->ID, self::key( 'title', $locale ), true );
		if ( '' === trim( $value ) && 'place' === $post->post_type && 'ka' === $locale ) {
			$value = (string) get_post_meta( $post->ID, 'glc_name_ka', true );
		}
		return '' !== trim( $value ) ? $value : $post->post_title;
	}

	/** Localized body, falling back to the English post_content. */
	public static function body( $post ): string {
		$post = get_post( $post );
		if ( ! $post ) {
			return '';
		}
		$locale = self::current_locale();
		if ( ! class_exists( 'GLC_I18n' ) || GLC_I18n::DEFAULT_LOCALE === $locale ) {
			return $post->post_content;
		}
		$value = (string) get_post_meta( $post->ID, self::key( 'body', $locale ), true );
		return '' !== trim( $value ) ? $value : $post->post_content;
	}

	/** Plain-text summary of the localized body, for cards and meta descriptions. */
	public static function excerpt( $post, int $words = 22 ): string {
		$post = get_post( $post );
		if ( ! $post ) {
			return '';
		}
		$locale = self::current_locale();
		$is_en  = ! class_exists( 'GLC_I18n' ) || GLC_I18n::DEFAULT_LOCALE === $locale;
		// The hand-written English excerpt is only valid for English.
		if ( $is_en && '' !== trim( (string) $post->post_excerpt ) ) {
			return $post->post_excerpt;
		}
		return wp_trim_words( wp_strip_all_tags( self::body( $post ) ), $words );
	}

	/* ----------------------------------------------------------- Admin UI */

	public static function meta_boxes() {
		foreach ( self::TRANSLATABLE as $post_type => $fields ) {
			add_meta_box(
				'glc-translations',
				__( 'Translations', 'geolander' ),
				[ __CLASS__, 'render_box' ],
				$post_type,
				'normal',
				'default'
			);
		}
	}

	public static function render_box( WP_Post $post ) {
		$fields = self::TRANSLATABLE[ $post->post_type ] ?? [];
		wp_nonce_field( 'glc_translations_save', 'glc_translations_nonce' );
		echo '<p style="color:#666;margin-top:0;">' . esc_html__( 'Leave a field empty to fall back to the English original.', 'geolander' ) . '</p>';
		foreach ( self::locales() as $locale ) {
			$name = GLC_I18n::LOCALES[ $locale ]['name'] ?? $locale;
			printf( '<h3 style="margin-bottom:.4rem;">%s <code>%s</code></h3>', esc_html( $name ), esc_html( $locale ) );
			foreach ( $fields as $field ) {
				$key   = self::key( $field, $locale );
				$value = (string) get_post_meta( $post->ID, $key, true );
				$label = 'title' === $field ? __( 'Title', 'geolander' ) : __( 'Body', 'geolander' );
				printf( '<p style="margin:.2rem 0;"><label for="%1$s"><strong>%2$s</strong></label></p>', esc_attr( $key ), esc_html( $label ) );
				if ( 'title' === $field ) {
					printf(
						'<input type="text" id="%1$s" name="%1$s" value="%2$s" class="widefat" />',
						esc_attr( $key ),
						esc_attr( $value )
					);
				} else {
					printf(
						'<textarea id="%1$s" name="%1$s" rows="4" class="widefat">%2$s</textarea>',
						esc_attr( $key ),
						esc_textarea( $value )
					);
				}
			}
		}
	}

	public static function save( int $post_id, WP_Post $post ) {
		if ( ! isset( $_POST['glc_translations_nonce'] )
			|| ! wp_verify_nonce( sanitize_key( $_POST['glc_translations_nonce'] ), 'glc_translations_save' ) ) {
			return;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		$fields = self::TRANSLATABLE[ $post->post_type ] ?? [];
		foreach ( self::locales() as $locale ) {
			foreach ( $fields as $field ) {
				$key = self::key( $field, $locale );
				if ( ! isset( $_POST[ $key ] ) ) {
					continue;
				}
				$raw   = wp_unslash( $_POST[ $key ] );
				$clean = 'title' === $field ? sanitize_text_field( $raw ) : wp_kses_post( $raw );
				if ( '' === trim( $clean ) ) {
					delete_post_meta( $post_id, $key );
				} else {
					update_post_meta( $post_id, $key, $clean );
				}
			}
		}
	}
}
