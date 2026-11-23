<?php
/**
 * Modern picture formats, made on upload and served to whoever takes them.
 *
 * THE MEASUREMENT THIS ANSWERS. Lighthouse on the live site, 2026-11-03: about
 * 797 KiB of the page was pictures that could have been smaller. The other half
 * of that run — 821 KiB of missing cache headers — was fixed in Apache and is
 * waiting to be deployed; this is the picture half.
 *
 * WHAT IT DOES. When WordPress has finished making an upload's sizes, a WebP is
 * written beside the original and beside each size (and an AVIF too, when that
 * is switched on). The original files are never read again for this and never
 * changed, so nothing that already works can break and a backfill can be run
 * twice with the same result.
 *
 * HOW IT IS SERVED, AND WHY NOT THE OTHER WAY. Every <img> this site renders is
 * wrapped in a <picture> that offers the modern formats first and keeps the
 * original <img> as the last word. The browser chooses, so a browser that
 * cannot read WebP sees the JPEG it has always seen.
 *
 * The obvious alternative — look at the request's `Accept` header and swap the
 * srcset — is wrong HERE, and the reason is Cloudflare: it caches HTML and
 * ignores `Vary`, so one visitor whose browser takes WebP would populate the
 * cache with a WebP page that is then served to a visitor whose browser does
 * not. <picture> moves the choice into the browser, where no shared cache can
 * get it wrong.
 *
 * A FORMAT IS OFFERED ONLY WHOLE. If one candidate in a srcset has no sibling,
 * that whole format is left out of the <picture> rather than offered with a
 * hole in it: a browser that picks a missing candidate shows no picture at all.
 *
 * NOTHING IS A LITERAL. Which formats, at what quality, and how wide an upload
 * may be are all in Settings → Geolander.
 */

defined( 'ABSPATH' ) || exit;

class GLC_Images {

	/** [ mime => [ original file name => new file name ] ], on the attachment. */
	public const META = 'glc_modern_formats';

	/** Offered in this order: the first one a browser takes, it uses. */
	public const FORMATS = [
		'image/avif' => [ 'setting' => 'image_avif', 'quality' => 'image_avif_quality', 'extension' => 'avif' ],
		'image/webp' => [ 'setting' => 'image_webp', 'quality' => 'image_webp_quality', 'extension' => 'webp' ],
	];

	/** Formats that are already modern: a WebP upload needs no WebP beside it. */
	private const ALREADY = [ 'image/webp', 'image/avif' ];

	public static function init(): void {
		add_filter( 'big_image_size_threshold', [ __CLASS__, 'max_width' ] );
		// After WordPress has made the sizes, so each one gets a sibling.
		add_filter( 'wp_generate_attachment_metadata', [ __CLASS__, 'make_siblings' ], 20, 2 );
		add_filter( 'wp_get_attachment_image', [ __CLASS__, 'picture' ], 20, 2 );
		add_filter( 'wp_content_img_tag', [ __CLASS__, 'picture_in_content' ], 20, 3 );
		add_action( 'delete_attachment', [ __CLASS__, 'remove_siblings' ] );
	}

	/* --------------------------------------------------------------- settings */

	/** The widest an upload is kept; null when the setting is empty (as uploaded). */
	public static function max_width( $threshold = 0 ) {
		$width = GLC_Settings::whole( 'image_max_width' );
		return null === $width ? $threshold : $width;
	}

	/** The formats switched on, in the order they are offered, that this server can actually write. */
	public static function wanted(): array {
		$out = [];
		foreach ( self::FORMATS as $mime => $about ) {
			if ( '1' !== (string) GLC_Settings::get( $about['setting'] ) ) {
				continue;
			}
			if ( ! wp_image_editor_supports( [ 'mime_type' => $mime, 'methods' => [ 'save' ] ] ) ) {
				continue;
			}
			$out[ $mime ] = $about;
		}
		return $out;
	}

	/* ------------------------------------------------------------- the making */

	/**
	 * One sibling per format per size. Re-runnable: a sibling that is already
	 * there and newer than its source is left alone, so this is also what the
	 * backfill script calls for an upload that came before any of this existed.
	 *
	 * A sibling that comes out no smaller than its source is thrown away. The
	 * point is fewer bytes; a bigger "modern" file is a slower page with a
	 * newer file extension.
	 */
	public static function make_siblings( $metadata, $attachment_id ) {
		$formats = self::wanted();
		if ( ! $formats || ! is_array( $metadata ) || empty( $metadata['file'] ) ) {
			return $metadata;
		}
		$source = (string) get_attached_file( (int) $attachment_id );
		if ( '' === $source || ! is_file( $source ) ) {
			return $metadata;
		}
		if ( in_array( (string) get_post_mime_type( (int) $attachment_id ), self::ALREADY, true ) ) {
			return $metadata;
		}

		$directory = trailingslashit( dirname( $source ) );
		$files     = [ basename( $source ) ];
		foreach ( (array) ( $metadata['sizes'] ?? [] ) as $size ) {
			if ( ! empty( $size['file'] ) ) {
				$files[] = (string) $size['file'];
			}
		}
		$files = array_values( array_unique( $files ) );

		// Not (array) on the raw value: an absent meta is '' and (array) '' is [ 0 => '' ],
		// which then rides along in the stored map as a format called "0".
		$made = get_post_meta( (int) $attachment_id, self::META, true );
		$made = is_array( $made ) ? $made : [];
		foreach ( $formats as $mime => $about ) {
			$map = is_array( $made[ $mime ] ?? null ) ? $made[ $mime ] : [];
			foreach ( $files as $name ) {
				$from = $directory . $name;
				$to   = $directory . pathinfo( $name, PATHINFO_FILENAME ) . '.' . $about['extension'];
				if ( ! is_file( $from ) ) {
					unset( $map[ $name ] );
					continue;
				}
				if ( is_file( $to ) && filemtime( $to ) >= filemtime( $from ) ) {
					$map[ $name ] = basename( $to );
					continue;
				}
				$written = self::write( $from, $to, $mime, (int) GLC_Settings::get( $about['quality'] ) );
				if ( $written ) {
					$map[ $name ] = basename( $to );
				} else {
					unset( $map[ $name ] );
				}
			}
			$made[ $mime ] = $map;
		}
		update_post_meta( (int) $attachment_id, self::META, $made );
		return $metadata;
	}

	/**
	 * Write one sibling. False when it could not be made, or came out no smaller.
	 *
	 * WHY THIS DOES NOT SIMPLY CALL WordPress. It tries Imagick itself first and
	 * keeps WordPress's own image editor as the fallback, because WordPress's
	 * Imagick editor gives away most of the saving on this exact job. Measured
	 * here on the theme's own hero.jpg (668,964 bytes) at quality 76:
	 *
	 *   through WordPress's editor   738,026 bytes   10% BIGGER than the JPEG
	 *   Imagick directly             526,210 bytes   21% smaller
	 *
	 * The cause is in wp-includes/class-wp-image-editor-imagick.php: before
	 * writing, it sets the alpha channel to opaque when the loaded image has
	 * none ("If alpha channel is not defined, set it opaque"). A JPEG has none,
	 * so every WebP made from a JPEG is written with a fourth, entirely pointless
	 * channel. There is no filter around that line, so there is nothing to turn
	 * off; the only way to the smaller file is to encode the picture ourselves.
	 *
	 * This is not a wrapper around WordPress's editor and does not replace it:
	 * WordPress still makes every size, still owns the originals, and still does
	 * this job wherever Imagick is missing. The one thing done here is the final
	 * encode, with the numbers above as the reason.
	 *
	 * THE CAMERA'S DATA DOES NOT TRAVEL, THE COLOUR DOES. The sibling is stripped
	 * of metadata (a public page is not the place for the lens, the timestamp or
	 * the GPS fix) but keeps the colour profile, so it looks the same as the
	 * JPEG beside it. That is the rule class-glc-photo-editor.php already follows.
	 */
	private static function write( string $from, string $to, string $mime, int $quality ): bool {
		$written = self::write_with_imagick( $from, $to, $mime, $quality )
			|| self::write_with_wordpress( $from, $to, $mime, $quality );
		if ( ! $written || ! is_file( $to ) ) {
			return false;
		}
		// A bigger "modern" file is a slower page with a newer file extension.
		if ( filesize( $to ) >= filesize( $from ) ) {
			unlink( $to );
			return false;
		}
		return true;
	}

	private static function write_with_imagick( string $from, string $to, string $mime, int $quality ): bool {
		if ( ! class_exists( 'Imagick' ) || empty( self::FORMATS[ $mime ]['extension'] ) ) {
			return false;
		}
		try {
			$image = new Imagick( $from );
			$image->setImageFormat( self::FORMATS[ $mime ]['extension'] );
			if ( $quality > 0 ) {
				$image->setImageCompressionQuality( $quality );
			}
			$profiles = $image->getImageProfiles( 'icc', true );
			$image->stripImage();
			if ( ! empty( $profiles['icc'] ) ) {
				$image->profileImage( 'icc', $profiles['icc'] );
			}
			$done = $image->writeImage( $to );
			$image->clear();
			$image->destroy();
			return (bool) $done;
		} catch ( Exception $error ) {
			// A format this build of Imagick cannot write, or a file it cannot
			// read. Either way WordPress gets its turn below.
			if ( is_file( $to ) ) {
				unlink( $to );
			}
			return false;
		}
	}

	private static function write_with_wordpress( string $from, string $to, string $mime, int $quality ): bool {
		$editor = wp_get_image_editor( $from );
		if ( is_wp_error( $editor ) ) {
			return false;
		}
		if ( $quality > 0 ) {
			$editor->set_quality( $quality );
		}
		$saved = $editor->save( $to, $mime );
		return ! is_wp_error( $saved ) && ! empty( $saved['path'] ) && is_file( $saved['path'] );
	}

	/**
	 * WordPress deletes an attachment's own files and sizes; these siblings are
	 * ours, so they go with them. Without this they would stay on the disk with
	 * nothing pointing at them, which is how an uploads folder fills up.
	 */
	public static function remove_siblings( $attachment_id ): void {
		$source = (string) get_attached_file( (int) $attachment_id );
		if ( '' === $source ) {
			return;
		}
		$directory = trailingslashit( dirname( $source ) );
		foreach ( (array) get_post_meta( (int) $attachment_id, self::META, true ) as $map ) {
			foreach ( (array) $map as $name ) {
				$path = $directory . basename( (string) $name );
				if ( is_file( $path ) ) {
					unlink( $path );
				}
			}
		}
	}

	/* ------------------------------------------------------------ the serving */

	/** Pictures the theme renders (wp_get_attachment_image, the featured image, the galleries). */
	public static function picture( $html, $attachment_id = 0 ) {
		return self::wrap( (string) $html, (int) $attachment_id );
	}

	/** Pictures inside a post's own text (the image block). */
	public static function picture_in_content( $html, $context = '', $attachment_id = 0 ) {
		return self::wrap( (string) $html, (int) $attachment_id );
	}

	private static function wrap( string $html, int $attachment_id ): string {
		if ( ! $attachment_id || '' === $html || false !== stripos( $html, '<picture' ) ) {
			return $html;
		}
		$made = (array) get_post_meta( $attachment_id, self::META, true );
		if ( ! $made || ! preg_match( '/<img\b[^>]*>/i', $html, $found ) ) {
			return $html;
		}
		$img    = $found[0];
		$srcset = self::attribute( $img, 'srcset' );
		if ( '' === $srcset ) {
			$srcset = self::attribute( $img, 'src' );
		}
		if ( '' === $srcset ) {
			return $html;
		}
		$sizes   = self::attribute( $img, 'sizes' );
		$sources = '';
		foreach ( array_keys( self::FORMATS ) as $mime ) {
			$map = is_array( $made[ $mime ] ?? null ) ? $made[ $mime ] : [];
			if ( ! $map ) {
				continue;
			}
			$swapped = self::swap( $srcset, $map );
			if ( null === $swapped ) {
				continue;
			}
			$sources .= sprintf(
				'<source type="%s" srcset="%s"%s />',
				esc_attr( $mime ),
				esc_attr( $swapped ),
				'' !== $sizes ? ' sizes="' . esc_attr( $sizes ) . '"' : ''
			);
		}
		return '' === $sources ? $html : str_replace( $img, '<picture>' . $sources . $img . '</picture>', $html );
	}

	/**
	 * The same srcset with every file name swapped for its sibling, or null when
	 * even one candidate has none — see the note at the top of this file.
	 */
	private static function swap( string $srcset, array $map ): ?string {
		$out = [];
		foreach ( explode( ',', $srcset ) as $candidate ) {
			$candidate = trim( $candidate );
			if ( '' === $candidate ) {
				continue;
			}
			$parts     = preg_split( '/\s+/', $candidate, 2 );
			$url       = (string) ( $parts[0] ?? '' );
			$descriptor = isset( $parts[1] ) ? ' ' . $parts[1] : '';
			$name      = basename( (string) wp_parse_url( $url, PHP_URL_PATH ) );
			if ( '' === $name || empty( $map[ $name ] ) ) {
				return null;
			}
			$out[] = str_replace( $name, (string) $map[ $name ], $url ) . $descriptor;
		}
		return $out ? implode( ', ', $out ) : null;
	}

	private static function attribute( string $tag, string $name ): string {
		return preg_match( '/\b' . preg_quote( $name, '/' ) . '="([^"]*)"/i', $tag, $found ) ? $found[1] : '';
	}

	/* ----------------------------------------------------------- what is there */

	/**
	 * How many uploads already have a sibling in each format, for the backfill
	 * script and the screen that reports on it.
	 *
	 * @return array [ mime => [ 'files' => int, 'bytes' => int ] ]
	 */
	public static function tally( array $attachment_ids ): array {
		$out = [];
		foreach ( array_keys( self::FORMATS ) as $mime ) {
			$out[ $mime ] = [ 'files' => 0, 'bytes' => 0 ];
		}
		foreach ( $attachment_ids as $id ) {
			$source = (string) get_attached_file( (int) $id );
			if ( '' === $source ) {
				continue;
			}
			$directory = trailingslashit( dirname( $source ) );
			foreach ( (array) get_post_meta( (int) $id, self::META, true ) as $mime => $map ) {
				if ( ! isset( $out[ $mime ] ) ) {
					continue;
				}
				foreach ( (array) $map as $name ) {
					$path = $directory . basename( (string) $name );
					if ( is_file( $path ) ) {
						++$out[ $mime ]['files'];
						$out[ $mime ]['bytes'] += (int) filesize( $path );
					}
				}
			}
		}
		return $out;
	}
}
