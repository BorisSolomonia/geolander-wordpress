<?php
/** Studio card covers are separate from the exact car's real WordPress media. */
defined( 'ABSPATH' ) || exit;

class GLC_Fleet_Covers {

	/** Only the homepage and fleet archive may show a generated cover. */
	private static function cover( WP_Post $car ): array {
		if ( is_admin() || is_feed() || ! ( is_front_page() || is_post_type_archive( 'car' ) ) ) {
			return [];
		}
		static $covers = null;
		if ( null === $covers ) {
			$file   = GLC_DIR . 'assets/fleet-covers/manifest.json';
			$covers = is_readable( $file ) ? json_decode( file_get_contents( $file ), true ) : [];
			$covers = is_array( $covers ) ? $covers : [];
		}
		$cover = $covers[ $car->post_name ] ?? null;
		// A changed slug/year falls back to the actual photo, never a guessed model.
		if ( ! is_array( $cover ) || (string) ( $cover['year'] ?? '' ) !== (string) get_post_meta( $car->ID, 'glc_year', true ) ) {
			return [];
		}
		return $cover;
	}

	/** Card-only labels avoid stale colour words and duplicate years in old titles. */
	public static function name( WP_Post $car ): string {
		return (string) ( self::cover( $car )['name'] ?? $car->post_title );
	}

	public static function image( WP_Post $car, int $position ): string {
		$cover = self::cover( $car );
		if ( ! $cover ) {
			return '';
		}
		$prefix = (string) ( $cover['asset'] ?? '' );
		if ( ! preg_match( '/^[a-z0-9-]+$/D', $prefix ) ) {
			return '';
		}
		$srcsets = [];
		foreach ( [ 'webp', 'jpg' ] as $format ) {
			$candidates = [];
			foreach ( [ 360, 480, 720 ] as $width ) {
				$name = $prefix . '-' . $width . '.' . $format;
				if ( ! is_file( GLC_DIR . 'assets/fleet-covers/' . $name ) ) {
					return '';
				}
				$candidates[] = esc_url( GLC_URL . 'assets/fleet-covers/' . $name ) . ' ' . $width . 'w';
			}
			$srcsets[ $format ] = implode( ', ', $candidates );
		}
		$sizes = '(max-width: 640px) calc(100vw - 40px), (max-width: 1024px) calc((100vw - 64px) / 2), 400px';
		return sprintf(
			'<picture data-glc-studio-cover="1"><source type="image/webp" srcset="%s" sizes="%s"><img src="%s" srcset="%s" sizes="%s" width="720" height="480" alt="%s" loading="%s" decoding="async"></picture>',
			esc_attr( $srcsets['webp'] ),
			esc_attr( $sizes ),
			esc_url( GLC_URL . 'assets/fleet-covers/' . $prefix . '-720.jpg' ),
			esc_attr( $srcsets['jpg'] ),
			esc_attr( $sizes ),
			esc_attr( sprintf( glc_ui( 'studio_cover' ), self::name( $car ) . ' ' . $cover['year'] ) ),
			$position <= 3 ? 'eager' : 'lazy'
		);
	}
}
