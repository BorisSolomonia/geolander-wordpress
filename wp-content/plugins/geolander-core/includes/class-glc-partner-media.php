<?php
/**
 * Photos a partner uploads: what is accepted, what is stored, and whose
 * uploads a partner can see.
 *
 * WordPress gives the role `upload_files` and nothing else, which already
 * closes editing and deleting (both ask for a post capability a partner does
 * not have). Three things it does not do on its own, and this class does:
 *
 *  - It shows every user with `upload_files` the whole media library. A partner
 *    sees their own uploads only, in the picker, on the Media screen and in REST.
 *  - It takes any file type the site allows and any size the server allows. A
 *    partner's upload must be an image of a kind named in Settings → Geolander
 *    and no larger than the size set there; the refusal is a sentence.
 *  - It keeps an upload as it came. A car photo is stored the way the fleet
 *    import stores one: turned upright, shrunk to the widest size the site
 *    serves and saved as a JPEG, before it reaches the uploads folder. So a
 *    12 MB phone picture never lands on the disk, and neither does where it
 *    was taken: what the camera wrote beside the pixels is removed, and only
 *    the colour profile is kept.
 *
 * It also keeps a partner's uploads when their account is deleted: the photos
 * are on public car pages and are the site's from then on.
 *
 * Which photo may go into which car's gallery is not decided here: that is
 * GLC_Price_Store, at the moment the gallery is saved.
 */

defined( 'ABSPATH' ) || exit;

class GLC_Partner_Media {

	/**
	 * Sent with an upload made from a car's screen, so the owner's photos for a
	 * car are stored like a partner's. A partner's upload is always treated so.
	 */
	public const FLAG = 'glc_car_photo';

	public static function init(): void {
		add_filter( 'wp_handle_upload_prefilter', [ __CLASS__, 'check_upload' ] );
		add_filter( 'wp_handle_sideload_prefilter', [ __CLASS__, 'check_upload' ] );
		add_filter( 'upload_mimes', [ __CLASS__, 'partner_mimes' ], 10, 2 );
		add_filter( 'upload_size_limit', [ __CLASS__, 'partner_size_limit' ] );
		add_filter( 'ajax_query_attachments_args', [ __CLASS__, 'own_uploads_only' ] );
		add_filter( 'rest_attachment_query', [ __CLASS__, 'own_uploads_only' ] );
		add_action( 'pre_get_posts', [ __CLASS__, 'own_uploads_in_lists' ] );
		add_action( 'delete_user', [ __CLASS__, 'keep_uploads' ], 10, 3 );
		add_filter( 'post_types_to_delete_with_user', [ __CLASS__, 'keep_upload_type' ], 10, 2 );
	}

	/* ------------------------------------------------- whose uploads are seen */

	/** The picker (admin-ajax) and REST: a partner's query is narrowed to what they uploaded, whatever it asked for. */
	public static function own_uploads_only( $query ) {
		if ( GLC_Partners::is_partner( wp_get_current_user() ) ) {
			$query           = (array) $query;
			$query['author'] = get_current_user_id();
			unset( $query['author__in'], $query['author__not_in'], $query['author_name'] );
		}
		return $query;
	}

	/** The Media screen's list (and any other admin list of attachments). */
	public static function own_uploads_in_lists( $query ): void {
		if ( ! is_admin() || ! $query instanceof WP_Query || ! GLC_Partners::is_partner( wp_get_current_user() ) ) {
			return;
		}
		if ( in_array( 'attachment', (array) $query->get( 'post_type' ), true ) ) {
			$query->set( 'author', get_current_user_id() );
		}
	}

	/* ------------------------------------------------- what may be uploaded */

	/** The kinds of image a partner may upload, as [ extensions => mime ] the way WordPress lists file types. */
	public static function partner_mimes( $mimes, $user = null ) {
		$user = $user instanceof WP_User ? $user : ( is_numeric( $user ) && $user ? get_userdata( (int) $user ) : wp_get_current_user() );
		return GLC_Partners::is_partner( $user ) ? array_intersect( (array) $mimes, GLC_Price_Store::image_types() ) : $mimes;
	}

	/** The largest file a partner may upload, in bytes; null when the setting is empty (the server's own limit). */
	public static function max_bytes(): ?int {
		$megabytes = GLC_Settings::get( 'partner_upload_max_mb' );
		return is_numeric( $megabytes ) ? (int) round( (float) $megabytes * MB_IN_BYTES ) : null;
	}

	/** What the uploader in the browser is told, so it can stop a large file before sending it. */
	public static function partner_size_limit( $bytes ) {
		$limit = GLC_Partners::is_partner( wp_get_current_user() ) ? self::max_bytes() : null;
		return null === $limit ? $bytes : min( (int) $bytes, $limit );
	}

	/**
	 * The widest photo the site serves: the widest crop the theme registers
	 * (GLC_IMAGE_SIZES, the "hero"). 0 when the theme is not the active one;
	 * nothing is shrunk then.
	 */
	public static function max_width(): int {
		return defined( 'GLC_IMAGE_SIZES' ) && is_array( GLC_IMAGE_SIZES ) && GLC_IMAGE_SIZES ? (int) max( array_column( GLC_IMAGE_SIZES, 0 ) ) : 0;
	}

	/**
	 * Every upload passes here before WordPress moves it into the uploads folder.
	 * A partner's file is checked (size, then what the file really is, not what
	 * its name says) and refused with a sentence; then a car photo is rewritten
	 * in place as an upright JPEG no wider than the site serves.
	 */
	public static function check_upload( $file ) {
		$partner = GLC_Partners::is_partner( wp_get_current_user() );
		if ( ! is_array( $file ) || ( ! $partner && empty( $_REQUEST[ self::FLAG ] ) ) || empty( $file['tmp_name'] ) || ! is_file( $file['tmp_name'] ) ) {
			return $file;
		}
		$found = wp_getimagesize( $file['tmp_name'] );
		$kind  = is_array( $found ) ? (string) ( $found['mime'] ?? '' ) : '';
		if ( $partner ) {
			$limit = self::max_bytes();
			$bytes = (int) filesize( $file['tmp_name'] );
			if ( 0 === $limit ) {
				$file['error'] = glc_admin_t( 'err_upload_closed' );
				return $file;
			}
			if ( null !== $limit && $bytes > $limit ) {
				$file['error'] = sprintf( glc_admin_t( 'err_upload_too_big' ), size_format( $bytes, 1 ), size_format( $limit, 1 ) );
				return $file;
			}
			if ( ! in_array( $kind, GLC_Price_Store::image_types(), true ) ) {
				$file['error'] = sprintf( glc_admin_t( 'err_upload_type' ), implode( glc_admin_t( 'price_join' ), GLC_Settings::words( 'partner_image_types' ) ) );
				return $file;
			}
		}
		// The owner's upload from a car's screen that is not a picture is the owner's business: it goes on as it is.
		if ( ! str_starts_with( $kind, 'image/' ) ) {
			return $file;
		}
		$photo = self::as_car_photo( $file );
		if ( null === $photo && $partner ) {
			// Its first bytes say "photo" and nothing can open it: stored as it came, it would keep whatever else is in it.
			$file['error'] = glc_admin_t( 'err_upload_unreadable' );
		}
		return $photo ?? $file;
	}

	/**
	 * Rewrite an uploaded picture where it lies. The file keeps its temporary
	 * path, because WordPress will only move a file PHP itself received there.
	 * Null when the picture could not be opened or saved: the file is then as it
	 * came, and the caller decides (the owner's goes on, a partner's is refused).
	 */
	private static function as_car_photo( array $file ): ?array {
		$editor = self::editor( $file['tmp_name'] );
		if ( is_wp_error( $editor ) ) {
			return null;
		}
		// A phone stores "which way is up" beside the pixels; that note goes with the rest of the camera's data, so turn the pixels first.
		$editor->maybe_exif_rotate();
		if ( $editor instanceof GLC_Photo_Editor && ! $editor->drop_camera_data() ) {
			// It could not be removed there, so the picture is rewritten by the editor that keeps nothing but pixels.
			$editor = self::editor( $file['tmp_name'], false );
			if ( is_wp_error( $editor ) ) {
				return null;
			}
			$editor->maybe_exif_rotate();
		}
		$most = self::max_width();
		$size = $editor->get_size();
		if ( $most > 0 && ( (int) $size['width'] > $most || (int) $size['height'] > $most ) ) {
			$editor->resize( $most, $most, false ); // fits inside the square, keeps its shape, never enlarges
		}
		$blank = wp_tempnam( 'glc-photo' );
		$saved = $editor->save( $blank, 'image/jpeg' );
		$done  = ! is_wp_error( $saved ) && ! empty( $saved['path'] ) && filesize( $saved['path'] ) > 0 && copy( $saved['path'], $file['tmp_name'] );
		if ( $done ) {
			clearstatcache( true, $file['tmp_name'] );
			$file['size'] = filesize( $file['tmp_name'] );
			$file['type'] = 'image/jpeg';
			$file['name'] = pathinfo( (string) $file['name'], PATHINFO_FILENAME ) . '.jpg';
		}
		// The two working files, never the upload itself.
		foreach ( array_unique( array_filter( [ $blank, is_array( $saved ) ? ( $saved['path'] ?? '' ) : '' ] ) ) as $path ) {
			if ( $path !== $file['tmp_name'] && is_file( $path ) ) {
				unlink( $path );
			}
		}
		return $done ? $file : null;
	}

	/**
	 * The editor a car photo is rewritten with: the plugin's own (Imagick, which
	 * can drop the camera's data and keep the colour profile) when the server has
	 * Imagick, else whatever WordPress picks, which is then GD: it keeps nothing
	 * but pixels anyway.
	 *
	 * @return WP_Image_Editor|WP_Error
	 */
	private static function editor( string $path, bool $own = true ) {
		require_once __DIR__ . '/class-glc-photo-editor.php';
		if ( $own && GLC_Photo_Editor::test() ) {
			$editor = new GLC_Photo_Editor( $path );
			$loaded = $editor->load();
			if ( ! is_wp_error( $loaded ) ) {
				return $editor;
			}
		}
		// Imagick out of the way: a second try with it would keep what the first could not remove.
		$without = static fn( $editors ) => array_values( array_diff( (array) $editors, [ 'WP_Image_Editor_Imagick' ] ) );
		add_filter( 'wp_image_editors', $without );
		$editor = wp_get_image_editor( $path );
		remove_filter( 'wp_image_editors', $without );
		return $editor;
	}

	/* --------------------------------------- when a partner's account is deleted */

	/**
	 * WordPress deletes a deleted user's attachments, files and all, unless the
	 * owner picks "attribute all content to" someone. A partner's photos are in
	 * car galleries, so they are kept either way: left without an uploader
	 * (author 0), which no later account can claim as its own.
	 */
	public static function keep_uploads( $user_id, $reassign = null, $user = null ): void {
		global $wpdb;
		$user = $user instanceof WP_User ? $user : get_userdata( (int) $user_id );
		if ( null !== $reassign || ! GLC_Partners::is_partner( $user ) ) {
			return;
		}
		$ids = array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_author = %d", (int) $user_id ) ) );
		if ( $ids ) {
			$wpdb->update( $wpdb->posts, [ 'post_author' => 0 ], [ 'post_type' => 'attachment', 'post_author' => (int) $user_id ] );
			array_map( 'clean_post_cache', $ids );
		}
	}

	/** The same rule said a second way, should anything have put the uploads back under the account in between. */
	public static function keep_upload_type( $post_types, $user_id ) {
		return GLC_Partners::is_partner( get_userdata( (int) $user_id ) ) ? array_values( array_diff( (array) $post_types, [ 'attachment' ] ) ) : $post_types;
	}
}
