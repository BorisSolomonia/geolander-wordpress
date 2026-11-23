<?php
/**
 * The one write path for what a partner may change about a car: its season
 * table, its own fees, its photos and its details.
 *
 * Every editor writes through here: the partner screens, the bulk screen, the
 * Excel upload, a restore from the change log, the Localrent script and the
 * boxes on the owner's WordPress car edit screen. So the rules are applied
 * once, in one place, whoever is saving:
 *
 *  - the table is checked before anything is written (real dates, all seven
 *    prices inside the limits from Settings → Geolander, every day of the year
 *    in exactly one season);
 *  - a save names the revision of the car it started from, and is refused when
 *    someone else has saved since: nobody overwrites a change they never saw;
 *  - the table that was live is kept, and the change log gets the full before
 *    and after;
 *  - search engines are told once per batch, and only about cars whose public
 *    price range or availability actually moved.
 *
 * A batch is written in one database transaction: every car in it, or none.
 *
 * Adding a kind: append it to KINDS and give it the four methods the others
 * have: validate_<kind>, read_<kind>, clean_<kind> and save_<kind>.
 *
 * Photos and details have no price in them, so they have no sample quotes and
 * no preview; everything else (the lock, the log, the way back) is the same.
 */

defined( 'ABSPATH' ) || exit;

class GLC_Price_Store {

	/** What the store can write today. */
	public const KINDS = [ 'pricing', 'fees', 'photos', 'specs' ];

	/** The kinds a quote depends on: these have sample quotes and a preview, the others do not. */
	public const QUOTED = [ 'pricing', 'fees' ];

	/**
	 * `full` is every rule. `shape` is for putting back a table that was live once:
	 * the price limits and the season-count limit may have changed since, so those
	 * two are skipped. Everything else still holds: at least one season, real
	 * dates, and every day of the year in exactly one season.
	 */
	public const LEVEL_FULL  = 'full';
	public const LEVEL_SHAPE = 'shape';

	public const META_PRICING      = 'glc_pricing';
	public const META_PREVIOUS     = 'glc_pricing_previous';
	public const META_DELIVERY     = 'glc_delivery_fees';
	public const META_OUT_OF_HOURS = 'glc_out_of_hours_fee';
	public const META_REV          = 'glc_rev';
	public const META_GALLERY      = 'glc_gallery';
	public const META_MAIN         = '_thumbnail_id';
	public const META_ALT          = '_wp_attachment_image_alt';

	/**
	 * The details of a car a partner may change, and what kind of value each is.
	 * Nothing outside this list is written by the kind "specs": what the car IS
	 * (title, plate, year, brand, body type, its texts) stays the owner's.
	 * The limits and the allowed words are in Settings → Geolander (SPEC_LIMITS).
	 */
	public const SPEC_FIELDS = [
		'glc_seats'               => 'seats',
		'glc_transmission'        => 'choice',
		'glc_fuel_type'           => 'choice',
		'glc_drivetrain'          => 'token',
		'glc_color'               => 'text',
		'glc_license_category'    => 'code',
		'glc_available'           => 'switch',
		'glc_evidence_checked_on' => 'evidence',
		'glc_last_service_on'     => 'evidence',
		'glc_odometer_km'         => 'evidence',
	];

	/** The setting that bounds each detail: its list of allowed words, or its greatest length. */
	public const SPEC_LIMITS = [
		'glc_transmission'     => 'spec_transmissions',
		'glc_fuel_type'        => 'spec_fuel_types',
		'glc_drivetrain'       => 'spec_drivetrain_max_chars',
		'glc_color'            => 'spec_color_max_chars',
		'glc_license_category' => 'spec_license_max_chars',
	];

	/**
	 * What a short token may be made of. A drive system is printed in a chip and
	 * in the structured data ("AWD", "4WD", "4x4"): capital letters, digits and
	 * the small x of "4x4". A licence category is letters and digits ("B", "C1").
	 */
	private const TOKEN_PATTERN = '/^[A-Z0-9x]+$/';
	private const CODE_PATTERN  = '/^[A-Za-z0-9]+$/';

	/** Attachments a save in progress has touched: forgotten from the cache if the save is undone. */
	private static array $touched = [];

	/** True only while write_batch() is saving: the one moment a web request may write a car's details, gallery or main photo. */
	private static bool $writing = false;

	/** Cars WordPress is deleting for good at this moment: every meta row of theirs goes, these too. */
	private static array $deleting = [];

	/** The early/late fee's name next to the place keys, in payloads and in error details. */
	public const OUT_OF_HOURS = 'out_of_hours';

	/** Seasons are yearly MM-DD; any non-leap year gives the 365 days they must cover. */
	private const CALENDAR_YEAR = 2001;

	/**
	 * Season labels are public (pricing.md prints them, quotes are keyed by them),
	 * so this must stay the format the 171 live rows already carry: "Jul 03 - Aug 02".
	 */
	private const LABEL_DAY  = 'M d';
	private const LABEL_JOIN = ' - ';

	public static function init(): void {
		add_action( 'init', [ __CLASS__, 'register_meta' ] );
		add_filter( 'is_protected_meta', [ __CLASS__, 'protect_meta' ], 10, 3 );
		add_filter( 'add_post_metadata', [ __CLASS__, 'guard_meta' ], 10, 3 );
		add_filter( 'update_post_metadata', [ __CLASS__, 'guard_meta' ], 10, 3 );
		add_filter( 'delete_post_metadata', [ __CLASS__, 'guard_meta_delete' ], 10, 5 );
		add_filter( 'update_post_metadata_by_mid', [ __CLASS__, 'guard_meta_row' ], 10, 2 );
		add_filter( 'delete_post_metadata_by_mid', [ __CLASS__, 'guard_meta_row' ], 10, 2 );
		add_action( 'before_delete_post', [ __CLASS__, 'deleting' ] );
		add_action( 'after_delete_post', [ __CLASS__, 'deleted' ] );
		add_filter( 'rest_pre_insert_car', [ __CLASS__, 'refuse_rest' ], 10, 2 );
	}

	/**
	 * The store's own car meta: never in REST, and closed to every editor that is
	 * not this class (auth_callback), so a saved car edit screen cannot resubmit
	 * an old revision number or an old fee.
	 */
	public static function register_meta(): void {
		$closed = [ 'single' => true, 'show_in_rest' => false, 'auth_callback' => '__return_false' ];
		register_post_meta( 'car', self::META_REV, $closed + [ 'type' => 'integer' ] );
		register_post_meta( 'car', self::META_DELIVERY, $closed + [ 'type' => 'object' ] );
		register_post_meta( 'car', self::META_OUT_OF_HOURS, $closed + [ 'type' => 'string' ] );
	}

	/**
	 * Keep them out of the Custom Fields box too. That box posts back every row
	 * it lists with each save of the car, so a gallery or a detail listed there
	 * would be written straight from a page that may be older than a partner's
	 * change, past the revision check.
	 */
	public static function protect_meta( $protected, $meta_key, $meta_type ) {
		if ( 'post' !== $meta_type ) {
			return $protected;
		}
		return in_array( $meta_key, [ self::META_PRICING, self::META_REV, self::META_DELIVERY, self::META_OUT_OF_HOURS, self::META_PREVIOUS, self::META_GALLERY ], true ) || isset( self::SPEC_FIELDS[ $meta_key ] ) ? true : $protected;
	}

	/* ------------------------------------------------- nobody else writes them */

	/** A shell is the server's owner: the fleet import, the other scripts in _migration/ and `wp post meta update` all run there. */
	private static function trusted(): bool {
		return defined( 'WP_CLI' ) && WP_CLI;
	}

	/** The meta of a car that only this class writes from a web request: its details, its gallery and its main photo. */
	private static function guarded( string $meta_key ): bool {
		return isset( self::SPEC_FIELDS[ $meta_key ] ) || self::META_GALLERY === $meta_key || self::META_MAIN === $meta_key;
	}

	/**
	 * WordPress asks here before it adds or changes any post meta. For a car's
	 * details, gallery and main photo the answer is no unless this class is the
	 * one saving, or the request comes from a shell. That closes every other door
	 * at once (REST, the editor's featured-image panel, the classic editor, the
	 * media library's "use as featured image", any plugin calling the meta
	 * functions): a write that came in beside the store would move no revision and
	 * leave no entry in the log, and the next save from a partner's open page
	 * would overwrite it without anyone being told.
	 */
	public static function guard_meta( $check, $object_id, $meta_key ) {
		if ( self::$writing || self::trusted() || ! self::guarded( (string) $meta_key ) || 'car' !== get_post_type( (int) $object_id ) ) {
			return $check;
		}
		return false;
	}

	/**
	 * The same question for a removal. "From every post at once" names no car:
	 * WordPress itself does that to one key only, the main photo, when a picture
	 * is deleted for good, and a car must not keep pointing at a picture that is
	 * gone. For the plugin's own keys it is refused outside a shell.
	 */
	public static function guard_meta_delete( $check, $object_id, $meta_key, $meta_value, $delete_all ) {
		if ( ! $delete_all ) {
			return self::guard_meta( $check, $object_id, $meta_key );
		}
		return self::trusted() || ! self::guarded( (string) $meta_key ) || self::META_MAIN === $meta_key ? $check : false;
	}

	/** And for a change or a removal that names the row itself, as the Custom Fields box does. */
	public static function guard_meta_row( $check, $meta_id ) {
		if ( self::$writing || self::trusted() ) {
			return $check;
		}
		$row = get_metadata_by_mid( 'post', (int) $meta_id );
		if ( ! $row || ! self::guarded( (string) $row->meta_key ) || 'car' !== get_post_type( (int) $row->post_id ) || in_array( (int) $row->post_id, self::$deleting, true ) ) {
			return $check;
		}
		return false;
	}

	public static function deleting( $post_id ): void {
		self::$deleting[] = (int) $post_id;
	}

	public static function deleted( $post_id ): void {
		self::$deleting = array_values( array_diff( self::$deleting, [ (int) $post_id ] ) );
	}

	/**
	 * REST saves a car's title first and its meta afterwards, so a refusal in the
	 * guard above would leave half a request written. A request that would change
	 * a detail, the gallery or the main photo is therefore refused here, whole,
	 * before anything is saved.
	 *
	 * A client that sends back what the car already has (it read the car, changed
	 * the title, sent all of it) is not refused: those values are taken out of
	 * the request, because there is nothing to write, and WordPress would
	 * otherwise try, be told no by the guard, and report a failure for a request
	 * that asked for no change.
	 */
	public static function refuse_rest( $prepared, $request ) {
		if ( is_wp_error( $prepared ) || self::trusted() ) {
			return $prepared;
		}
		$car_id = (int) ( $request['id'] ?? 0 );
		$meta   = $request['meta'] ?? null;
		$photos = self::read_photos( $car_id );
		$moves  = isset( $request['featured_media'] ) && (int) $request['featured_media'] !== $photos['main'];
		if ( is_array( $meta ) ) {
			$specs = self::read_specs( $car_id );
			foreach ( array_intersect_key( $meta, self::SPEC_FIELDS ) as $key => $value ) {
				$moves = $moves || ( null !== $value && ! is_scalar( $value ) ) || self::clean_specs( [ $key => $value ], $specs, self::LEVEL_FULL, $car_id )[ $key ] !== $specs[ $key ];
				unset( $meta[ $key ] );
			}
			if ( array_key_exists( self::META_GALLERY, $meta ) ) {
				$moves = $moves || array_values( array_filter( array_map( 'intval', (array) $meta[ self::META_GALLERY ] ) ) ) !== $photos['gallery'];
				unset( $meta[ self::META_GALLERY ] );
			}
		}
		if ( $moves ) {
			return new WP_Error( 'glc_store_only', glc_admin_t( 'err_store_only' ), [ 'status' => 403 ] );
		}
		if ( is_array( $meta ) ) {
			$request['meta'] = $meta;
		}
		unset( $request['featured_media'] );
		return $prepared;
	}

	/* ------------------------------------------------------------ validation */

	/**
	 * Why a season table cannot be saved; an empty list means it can.
	 *
	 * Each error is [ 'code', 'message' ] plus what locates it: `season` (row
	 * index, from 0), `field` or `band`, and for gaps and overlaps `from`, `to`
	 * (MM-DD), `days` and `seasons`. Codes: err_season_date, err_rate_bounds,
	 * err_gap, err_overlap, err_season_count, err_bounds_setting.
	 */
	public static function validate_pricing( $seasons, string $level = self::LEVEL_FULL ): array {
		if ( ! is_array( $seasons ) ) {
			return [ self::error( 'err_season_count', 'err_season_none' ) ];
		}
		$seasons = array_values( $seasons );
		$full    = self::LEVEL_FULL === $level;
		$errors  = [];
		$min     = $full ? self::limit( 'rate_min_plausible' ) : null;
		$max     = $full ? self::limit( 'rate_max_plausible' ) : null;
		$most    = $full ? self::limit( 'season_max_count' ) : null;
		if ( false === $min || false === $max || false === $most ) {
			return [ self::error( 'err_bounds_setting', 'err_bounds_setting' ) ];
		}

		if ( ! $seasons || ( null !== $most && count( $seasons ) > $most ) ) {
			$errors[] = null === $most
				? self::error( 'err_season_count', 'err_season_none' )
				: self::error( 'err_season_count', 'err_season_count', [ (int) $most, count( $seasons ) ] );
			if ( $seasons ) {
				// Too long is the whole answer. Its rows are not read and the year is not walked for it: the work would grow with a table that is refused anyway.
				return $errors;
			}
		}

		$dated = true;
		foreach ( $seasons as $i => $season ) {
			if ( ! is_array( $season ) || ( isset( $season['rates'] ) && ! is_array( $season['rates'] ) ) ) {
				$errors[] = self::error( 'err_season_date', 'err_season_row', [ $i + 1 ], [ 'season' => $i, 'field' => 'row' ] );
				$dated    = false;
				continue;
			}
			foreach ( [ 'from', 'to' ] as $field ) {
				$value = $season[ $field ] ?? '';
				if ( ! self::is_day( $value ) ) {
					$errors[] = self::error( 'err_season_date', 'err_season_date', [ $i + 1, is_scalar( $value ) ? (string) $value : '' ], [ 'season' => $i, 'field' => $field ] );
					$dated    = false;
				}
			}
			if ( ! $full ) {
				continue;
			}
			foreach ( GLC_Pricing::TIERS as $band ) {
				$problem = self::rate_problem( $season['rates'][ $band ] ?? null, $min, $max );
				if ( $problem ) {
					$errors[] = self::error( 'err_rate_bounds', $problem[0], array_merge( [ $i + 1, self::season_text( $season ), GLC_Pricing::TIER_LABELS[ $band ] ], $problem[1] ), [ 'season' => $i, 'band' => $band ] );
				}
			}
		}

		// Coverage only means something once every season has two real dates.
		if ( $dated && $seasons ) {
			$coverage = self::coverage( $seasons );
			foreach ( $coverage['gaps'] as $gap ) {
				$errors[] = 1 === $gap['days']
					? self::error( 'err_gap', 'err_gap_one', [ GLC_Admin_Strings::day( $gap['from'] ) ], $gap )
					: self::error( 'err_gap', 'err_gap', [ GLC_Admin_Strings::day( $gap['from'] ), GLC_Admin_Strings::day( $gap['to'] ), $gap['days'] ], $gap );
			}
			foreach ( $coverage['overlaps'] as $overlap ) {
				$names = implode( glc_admin_t( 'list_join' ), array_map(
					static fn( int $i ): string => sprintf( glc_admin_t( 'season_ref' ), $i + 1, self::season_text( $seasons[ $i ] ) ),
					$overlap['seasons']
				) );
				$errors[] = 1 === $overlap['days']
					? self::error( 'err_overlap', 'err_overlap_one', [ GLC_Admin_Strings::day( $overlap['from'] ), $names ], $overlap )
					: self::error( 'err_overlap', 'err_overlap', [ GLC_Admin_Strings::day( $overlap['from'] ), GLC_Admin_Strings::day( $overlap['to'] ), $names ], $overlap );
			}
		}
		return $errors;
	}

	/**
	 * Why a car's own fees cannot be saved; an empty list means they can.
	 *
	 * $fees is [ 'delivery' => [ place => amount ], 'out_of_hours' => amount ];
	 * either part may be left out. An amount is a number, 0 for free, or an empty
	 * value for "use the site standard". Each error carries `place` (a place key,
	 * or 'out_of_hours'). Code: err_fee.
	 */
	public static function validate_fees( $fees, string $level = self::LEVEL_FULL ): array {
		if ( ! is_array( $fees )
			|| array_diff( array_keys( $fees ), [ 'delivery', self::OUT_OF_HOURS ] )
			|| ( isset( $fees['delivery'] ) && ! is_array( $fees['delivery'] ) ) ) {
			return [ self::error( 'err_fee', 'err_fee_shape' ) ];
		}
		$max = self::LEVEL_FULL === $level ? self::limit( 'fee_max_plausible' ) : null;
		if ( false === $max ) {
			return [ self::error( 'err_bounds_setting', 'err_bounds_setting' ) ];
		}
		$errors = [];
		$places = GLC_Rental::fee_place_keys();
		foreach ( $fees['delivery'] ?? [] as $place => $value ) {
			if ( ! in_array( $place, $places, true ) ) {
				$errors[] = self::error( 'err_fee', 'err_fee_place', [ (string) $place ], [ 'place' => (string) $place ] );
				continue;
			}
			$errors[] = self::fee_problem( $place, $value, $max );
		}
		if ( array_key_exists( self::OUT_OF_HOURS, $fees ) ) {
			$errors[] = self::fee_problem( self::OUT_OF_HOURS, $fees[ self::OUT_OF_HOURS ], $max );
		}
		return array_values( array_filter( $errors ) );
	}

	/**
	 * Why a car's photos cannot be saved; an empty list means they can.
	 *
	 * $photos is [ 'gallery' => attachment ids in page order, 'main' => the id of
	 * the main photo, 'alts' => [ id => text ] ]; any part may be left out and
	 * then stays as stored. One bad id refuses the whole save. Each error carries
	 * `photo` (the id) where it is about one. Codes: err_photo.
	 *
	 * A photo may go into a gallery when it is an image of an allowed kind and
	 * the person either uploaded it or it is on this car already (the owner may
	 * use any image). An id that fails that gets one and the same sentence
	 * whether it is someone else's, not an image or not there at all: the answer
	 * must not say which. A photo that is new to the car must also be wide
	 * enough; photos already on the car are not measured again.
	 */
	public static function validate_photos( $photos, string $level = self::LEVEL_FULL, int $car_id = 0, array $ctx = [] ): array {
		if ( ! is_array( $photos )
			|| array_diff( array_keys( $photos ), [ 'gallery', 'main', 'alts' ] )
			|| ( isset( $photos['gallery'] ) && ! is_array( $photos['gallery'] ) )
			|| ( isset( $photos['alts'] ) && ! is_array( $photos['alts'] ) )
			|| ( array_key_exists( 'main', $photos ) && null === self::photo_id( $photos['main'], true ) ) ) {
			return [ self::error( 'err_photo', 'err_photo_shape' ) ];
		}
		$full    = self::LEVEL_FULL === $level;
		$stored  = self::read_photos( $car_id );
		$on_car  = array_merge( $stored['gallery'], [ $stored['main'] ] );
		$gallery = [];
		foreach ( $photos['gallery'] ?? $stored['gallery'] as $given ) {
			$id = self::photo_id( $given );
			if ( null === $id ) {
				return [ self::error( 'err_photo', 'err_photo_id', [ is_scalar( $given ) ? (string) $given : '…' ] ) ];
			}
			$gallery[] = $id;
		}

		$errors = [];
		$most   = $full ? GLC_Settings::whole( 'gallery_max_photos' ) : null;
		if ( null !== $most && isset( $photos['gallery'] ) && count( $gallery ) > $most ) {
			$errors[] = self::error( 'err_photo', 'err_photo_count', [ $most, count( $gallery ) ] );
		}
		$narrowest = $full ? (int) GLC_Settings::whole( 'partner_image_min_width' ) : 0;
		$kinds     = self::image_types();
		$actor     = (int) ( $ctx['actor'] ?? 0 );
		// A state that was live once is put back whoever uploaded its photos; a shell is the owner's.
		$any_image = ! $full || ! empty( $ctx['trusted'] );
		// An alt is written to the attachment itself, so an alt for a photo outside the gallery is asked the same question.
		$asked = array_unique( array_merge( $gallery, array_map( 'intval', array_keys( $photos['alts'] ?? [] ) ) ) );
		foreach ( $asked as $id ) {
			$post  = get_post( $id );
			$known = in_array( $id, $on_car, true );
			$mime  = $post && 'attachment' === $post->post_type ? (string) $post->post_mime_type : '';
			if ( '' === $mime || ! ( $any_image || self::photo_open( $car_id, $id, $actor ) ) ) {
				$errors[] = self::error( 'err_photo', 'err_photo_foreign', [ $id ], [ 'photo' => $id ] );
				continue;
			}
			if ( ! in_array( $id, $gallery, true ) ) {
				continue; // a description sent for a photo the person has just taken out: it is written nowhere
			}
			// From here on the photo is one this person may see, so it is called by its file name.
			if ( $full ? ! in_array( $mime, $kinds, true ) : ! str_starts_with( $mime, 'image/' ) ) {
				$errors[] = self::error( 'err_photo', 'err_photo_type', [ self::photo_name( $id ), $mime, implode( glc_admin_t( 'price_join' ), $kinds ) ], [ 'photo' => $id ] );
				continue;
			}
			$width = (int) ( wp_get_attachment_metadata( $id )['width'] ?? 0 );
			if ( $narrowest > 0 && ! $known && $width < $narrowest ) {
				$errors[] = $width > 0
					? self::error( 'err_photo', 'err_photo_narrow', [ self::photo_name( $id ), $width, $narrowest ], [ 'photo' => $id ] )
					: self::error( 'err_photo', 'err_photo_unmeasured', [ self::photo_name( $id ), $narrowest ], [ 'photo' => $id ] );
			}
		}

		if ( array_key_exists( 'main', $photos ) && $full ) {
			$main = (int) self::photo_id( $photos['main'], true );
			if ( $main ? ! in_array( $main, $gallery, true ) : (bool) $gallery ) {
				$errors[] = self::error( 'err_photo', $main ? 'err_photo_main' : 'err_photo_main_none', $main ? [ $main ] : [] );
			}
		}
		$longest = $full ? GLC_Settings::whole( 'photo_alt_max_chars' ) : null;
		foreach ( $photos['alts'] ?? [] as $id => $alt ) {
			if ( ! is_string( $alt ) ) {
				$errors[] = self::error( 'err_photo', 'err_photo_shape' );
			} elseif ( null !== $longest && mb_strlen( trim( sanitize_text_field( $alt ) ) ) > $longest ) {
				$errors[] = self::error( 'err_photo', 'err_photo_alt_long', [ mb_strlen( trim( sanitize_text_field( $alt ) ) ), $longest, trim( sanitize_text_field( $alt ) ) ], [ 'photo' => (int) $id ] );
			}
		}
		return $errors;
	}

	/**
	 * Why a car's details cannot be saved; an empty list means they can.
	 *
	 * $specs is [ meta key => value ] for any of SPEC_FIELDS; a key left out stays
	 * as stored, a key that is not in the list refuses the save. Each error
	 * carries `field` (the meta key). Code: err_spec.
	 */
	public static function validate_specs( $specs, string $level = self::LEVEL_FULL, int $car_id = 0, array $ctx = [] ): array {
		if ( ! is_array( $specs ) ) {
			return [ self::error( 'err_spec', 'err_spec_shape' ) ];
		}
		$errors = [];
		foreach ( array_diff( array_keys( $specs ), array_keys( self::SPEC_FIELDS ) ) as $unknown ) {
			$errors[] = self::error( 'err_spec', 'err_spec_field', [ (string) $unknown ], [ 'field' => (string) $unknown ] );
		}
		foreach ( array_intersect_key( $specs, self::SPEC_FIELDS ) as $key => $value ) {
			if ( ! is_scalar( $value ) ) {
				$errors[] = self::error( 'err_spec', 'err_spec_shape', [], [ 'field' => $key ] );
				unset( $specs[ $key ] );
			}
		}
		if ( $errors || self::LEVEL_FULL !== $level ) {
			return $errors; // a state that was live once is put back as it was
		}

		$fail = static function ( string $key, string $message, array $args = [] ) use ( &$errors ): void {
			$errors[] = self::error( 'err_spec', $message, array_merge( [ glc_admin_t( self::spec_label_key( $key ) ) ], $args ), [ 'field' => $key ] );
		};
		foreach ( $specs as $key => $value ) {
			$typed = is_bool( $value ) ? $value : trim( (string) $value );
			$most  = isset( self::SPEC_LIMITS[ $key ] ) ? GLC_Settings::whole( self::SPEC_LIMITS[ $key ] ) : null;
			switch ( self::SPEC_FIELDS[ $key ] ) {
				case 'seats':
					$low  = GLC_Settings::whole( 'spec_seats_min' );
					$high = GLC_Settings::whole( 'spec_seats_max' );
					if ( ! is_string( $typed ) || ! ctype_digit( $typed ) || ( null !== $low && (int) $typed < $low ) || ( null !== $high && (int) $typed > $high ) ) {
						$fail( $key, 'err_spec_seats', [ is_string( $typed ) ? $typed : '', (int) $low, (int) $high ] );
					}
					break;
				case 'choice':
					$words = GLC_Settings::words( self::SPEC_LIMITS[ $key ] );
					if ( ! in_array( $typed, $words, true ) ) {
						$fail( $key, 'err_spec_choice', [ is_string( $typed ) ? $typed : '', implode( glc_admin_t( 'price_join' ), $words ) ] );
					}
					break;
				case 'token':
				case 'code':
					$token = 'token' === self::SPEC_FIELDS[ $key ];
					if ( ! is_string( $typed ) || ( '' !== $typed && ! preg_match( $token ? self::TOKEN_PATTERN : self::CODE_PATTERN, $typed ) ) ) {
						$fail( $key, $token ? 'err_spec_token' : 'err_spec_code', [ is_string( $typed ) ? $typed : '' ] );
					} elseif ( null !== $most && mb_strlen( $typed ) > $most ) {
						$fail( $key, 'err_spec_long', [ mb_strlen( $typed ), $most ] );
					}
					break;
				case 'text':
					if ( ! is_string( $typed ) || sanitize_text_field( $typed ) !== $typed ) {
						$fail( $key, 'err_spec_text' );
					} elseif ( null !== $most && mb_strlen( $typed ) > $most ) {
						$fail( $key, 'err_spec_long', [ mb_strlen( $typed ), $most ] );
					}
					break;
				case 'switch':
					if ( ! is_bool( $typed ) && ! in_array( $typed, [ '', '0', '1' ], true ) ) {
						$fail( $key, 'err_spec_switch' );
					}
					break;
			}
		}

		// The dated facts are judged together, by the same function that decides what the car page shows.
		// That function drops what it cannot show (a date still to come, a service after the check).
		// Here nothing is dropped quietly: the save is refused and the sentence says which fact and why.
		$dated = array_keys( self::SPEC_FIELDS, 'evidence', true );
		if ( array_intersect_key( $specs, array_flip( $dated ) ) ) {
			$stored = self::read_specs( $car_id );
			$asked  = [];
			foreach ( $dated as $key ) {
				$asked[ $key ] = trim( (string) ( $specs[ $key ] ?? $stored[ $key ] ) );
			}
			$kept = GLC_Vehicle_Evidence::normalize( $asked );
			foreach ( $dated as $key ) {
				if ( '' === $asked[ $key ] || $kept[ $key ] === $asked[ $key ] ) {
					continue;
				}
				if ( 'glc_odometer_km' === $key ) {
					$fail( $key, 'err_spec_odometer', [ $asked[ $key ] ] );
				} elseif ( '' === GLC_Vehicle_Evidence::date( $asked[ $key ] ) ) {
					$fail( $key, 'err_spec_date', [ $asked[ $key ] ] );
				} else {
					$fail( $key, 'err_spec_service_after_check', [ $asked[ $key ], '' !== $asked['glc_evidence_checked_on'] ? $asked['glc_evidence_checked_on'] : glc_admin_t( 'not_set' ) ] );
				}
			}
		}
		return $errors;
	}

	/**
	 * May this person put this attachment into this car's gallery? Yes when it
	 * is on the car already, when they uploaded it, or when they may edit other
	 * people's uploads anyway (the owner). An upload nobody is recorded for
	 * (author 0: the imported fleet photos) is nobody's "own".
	 */
	public static function photo_open( int $car_id, int $attachment_id, int $user_id ): bool {
		$post = get_post( $attachment_id );
		if ( ! $post || 'attachment' !== $post->post_type || $user_id < 1 ) {
			return false;
		}
		$stored = self::read_photos( $car_id );
		return in_array( $attachment_id, $stored['gallery'], true )
			|| $attachment_id === $stored['main']
			|| (int) $post->post_author === $user_id
			|| user_can( $user_id, 'edit_others_posts' );
	}

	/** The catalogue key of a detail's name: `spec_seats` for `glc_seats`. */
	public static function spec_label_key( string $meta_key ): string {
		return 'spec_' . preg_replace( '/^glc_/', '', $meta_key );
	}

	/** The kinds of image a gallery may hold, as WordPress names them ("image/jpeg"). */
	public static function image_types(): array {
		return array_map( static fn( string $word ): string => 'image/' . $word, GLC_Settings::words( 'partner_image_types' ) );
	}

	/** A photo as a person knows it: the name of its file. */
	private static function photo_name( int $attachment_id ): string {
		$file = (string) get_attached_file( $attachment_id );
		return '' !== $file ? wp_basename( $file ) : '#' . $attachment_id;
	}

	/** An attachment id as a whole number above zero (or zero, where "none" is an answer); null for anything else. */
	private static function photo_id( $value, bool $none_allowed = false ): ?int {
		if ( is_int( $value ) ) {
			return $value > 0 || ( $none_allowed && 0 === $value ) ? $value : null;
		}
		if ( ! is_string( $value ) || ! ctype_digit( $value ) || strlen( $value ) > 18 ) {
			return $none_allowed && '' === $value ? 0 : null;
		}
		return (int) $value > 0 || $none_allowed ? (int) $value : null;
	}

	/**
	 * Which days of the year no season covers, and which more than one does.
	 * Runs of days, in calendar order; a run over New Year is one run.
	 *
	 * @return array{gaps: array, overlaps: array} each run: from, to (MM-DD), days, seasons (row indexes)
	 */
	public static function coverage( array $seasons ): array {
		$runs = [];
		foreach ( self::year_days() as $day ) {
			$in = [];
			foreach ( array_values( $seasons ) as $i => $season ) {
				$from = (string) ( $season['from'] ?? '' );
				$to   = (string) ( $season['to'] ?? '' );
				// Same test as GLC_Pricing::season_for_date(): a season may wrap New Year.
				if ( $from <= $to ? ( $day >= $from && $day <= $to ) : ( $day >= $from || $day <= $to ) ) {
					$in[] = $i;
				}
			}
			$last = count( $runs ) - 1;
			if ( $last >= 0 && $runs[ $last ]['seasons'] === $in ) {
				$runs[ $last ]['to'] = $day;
				$runs[ $last ]['days']++;
			} else {
				$runs[] = [ 'from' => $day, 'to' => $day, 'days' => 1, 'seasons' => $in ];
			}
		}
		$last = count( $runs ) - 1;
		if ( $last > 0 && $runs[0]['seasons'] === $runs[ $last ]['seasons'] ) {
			$runs[0]['from']  = $runs[ $last ]['from'];
			$runs[0]['days'] += $runs[ $last ]['days'];
			array_pop( $runs );
		}
		return [
			'gaps'     => array_values( array_filter( $runs, static fn( array $run ): bool => ! $run['seasons'] ) ),
			'overlaps' => array_values( array_filter( $runs, static fn( array $run ): bool => count( $run['seasons'] ) > 1 ) ),
		];
	}

	/** The stored English label of a season, generated from its two dates and never typed. */
	public static function season_label( string $from, string $to ): string {
		$day = static fn( string $mm_dd ): string => gmdate( self::LABEL_DAY, gmmktime( 0, 0, 0, (int) substr( $mm_dd, 0, 2 ), (int) substr( $mm_dd, 3, 2 ), self::CALENDAR_YEAR ) );
		return $day( $from ) . self::LABEL_JOIN . $day( $to );
	}

	/* ----------------------------------------------------------------- reads */

	/** How many times the store has written this car. A form carries it; a save from an older one is refused. */
	public static function rev( int $car_id ): int {
		return (int) get_post_meta( $car_id, self::META_REV, true );
	}

	public static function read_pricing( int $car_id ): array {
		$stored = get_post_meta( $car_id, self::META_PRICING, true );
		return is_array( $stored ) ? $stored : [];
	}

	/** Always both parts: [ 'delivery' => overrides only, 'out_of_hours' => amount or '' ]. */
	public static function read_fees( int $car_id ): array {
		$delivery     = get_post_meta( $car_id, self::META_DELIVERY, true );
		$out_of_hours = get_post_meta( $car_id, self::META_OUT_OF_HOURS, true );
		return [
			'delivery'         => is_array( $delivery ) ? $delivery : [],
			self::OUT_OF_HOURS => is_scalar( $out_of_hours ) ? (string) $out_of_hours : '',
		];
	}

	/** The gallery in page order, the main photo (0 = none) and the alt text of every photo in the gallery. */
	public static function read_photos( int $car_id ): array {
		$gallery = array_values( array_filter( array_map( 'intval', (array) get_post_meta( $car_id, self::META_GALLERY, true ) ) ) );
		$alts    = [];
		foreach ( $gallery as $id ) {
			$alts[ $id ] = (string) get_post_meta( $id, self::META_ALT, true );
		}
		return [ 'gallery' => $gallery, 'main' => (int) get_post_meta( $car_id, self::META_MAIN, true ), 'alts' => $alts ];
	}

	/** Every detail of SPEC_FIELDS: seats as a number, "available" as yes or no, the rest as text ('' = not set). */
	public static function read_specs( int $car_id ): array {
		$out = [];
		foreach ( self::SPEC_FIELDS as $key => $type ) {
			$stored      = get_post_meta( $car_id, $key, true );
			$out[ $key ] = 'seats' === $type ? (int) $stored : ( 'switch' === $type ? (bool) $stored : ( is_scalar( $stored ) ? (string) $stored : '' ) );
		}
		return $out;
	}

	/** One kind of a car's state, as stored. */
	public static function read( int $car_id, string $kind ): array {
		return in_array( $kind, self::KINDS, true ) ? self::{ 'read_' . $kind }( $car_id ) : [];
	}

	/**
	 * What a car costs, in the shape `$changes` and with_state() take: the two
	 * kinds a quote depends on. Photos and details are read with read().
	 */
	public static function state( int $car_id ): array {
		return [ 'pricing' => self::read_pricing( $car_id ), 'fees' => self::read_fees( $car_id ) ];
	}

	/**
	 * Run $fn with the car looking as if $state were saved, and write nothing.
	 * For previews: the quote engine reads the car's meta, so for the length of
	 * the call the meta it is handed is the unsaved state. $state has the shape
	 * of state(); a part left out stays as stored.
	 */
	public static function with_state( int $car_id, array $state, callable $fn ) {
		$as_if = [];
		if ( isset( $state['pricing'] ) ) {
			$as_if[ self::META_PRICING ] = $state['pricing'];
		}
		if ( isset( $state['fees']['delivery'] ) ) {
			$as_if[ self::META_DELIVERY ] = $state['fees']['delivery'] ?: '';
		}
		if ( isset( $state['fees'][ self::OUT_OF_HOURS ] ) ) {
			$as_if[ self::META_OUT_OF_HOURS ] = $state['fees'][ self::OUT_OF_HOURS ];
		}
		$show = static function ( $value, $object_id, $meta_key ) use ( $car_id, $as_if ) {
			// WordPress takes the first element of what a filter returns as the single value.
			return (int) $object_id === $car_id && array_key_exists( (string) $meta_key, $as_if ) ? [ $as_if[ $meta_key ] ] : $value;
		};
		add_filter( 'get_post_metadata', $show, 10, 3 );
		try {
			return $fn();
		} finally {
			remove_filter( 'get_post_metadata', $show, 10 );
		}
	}

	/* ---------------------------------------------------------------- writes */

	/**
	 * Save one car. $changes is [ kind => new state ]; see write_batch() for
	 * $ctx, plus `rev`: the car's revision when the form was rendered.
	 *
	 * @return array|WP_Error the same result as a batch of one
	 */
	public static function write( int $car_id, array $changes, array $ctx = [] ) {
		return self::write_batch( [ $car_id => [ 'changes' => $changes, 'rev' => $ctx['rev'] ?? null ] ], $ctx );
	}

	/**
	 * Save several cars as one batch: every car, or none.
	 *
	 * $items: [ car id => [ 'changes' => [ kind => new state ], 'rev' => int ] ]
	 * $ctx:   source (one of GLC_Change_Log::SOURCES, required), actor (user id,
	 *         default the current user), batch (uuid, default a new one), note.
	 *
	 * A source of `restore` skips the price limits and the season-count limit and
	 * nothing else. Under WP-CLI the caller is the server's owner: the assignment
	 * check is skipped, `rev` may be left out and `actor` may name a user. Anywhere
	 * else the actor is whoever is signed in, whatever the caller says.
	 *
	 * @return array|WP_Error [ batch, cars => [ id => [ rev, kinds, entries ] ], unchanged => ids, pinged => urls ],
	 *                        or `glc_store_refused` with data.errors [ car id => errors ] (nothing written),
	 *                        or `glc_store_failed` when the database refused (rolled back).
	 */
	public static function write_batch( array $items, array $ctx = [] ) {
		$ctx = self::context( $ctx );
		if ( is_wp_error( $ctx ) ) {
			return $ctx;
		}
		ksort( $items );
		$car_ids       = array_map( 'intval', array_keys( $items ) );
		$errors_before = count( $GLOBALS['EZSQL_ERROR'] ?? [] );
		self::begin( $car_ids );

		// Planned inside the lock, so the revisions and "before" states read here are the ones being replaced.
		$plan = self::plan_items( $items, $ctx );
		if ( $plan['errors'] ) {
			self::rollback( $car_ids );
			return self::refusal( $plan['errors'] );
		}

		$result  = [ 'batch' => $ctx['batch'], 'cars' => [], 'unchanged' => [], 'pinged' => [] ];
		$moved   = [];
		$entries = [];
		$intact  = true;
		foreach ( $plan['cars'] as $car_id => $car ) {
			if ( ! $car['changes'] ) {
				$result['unchanged'][] = $car_id;
				continue;
			}
			$face  = self::public_face( $car_id );
			$mine  = [];
			foreach ( $car['changes'] as $kind => $change ) {
				self::$writing = true;
				try {
					self::{ 'save_' . $kind }( $car_id, $change['after'], $change['before'] );
				} finally {
					self::$writing = false;
				}
				$entry = GLC_Change_Log::record( [
					'car_id' => $car_id,
					'actor'  => $ctx['actor'],
					'kind'   => $kind,
					'source' => $ctx['source'],
					'batch'  => $ctx['batch'],
					'before' => $change['before'],
					'after'  => $change['after'],
					'note'   => $ctx['note'],
				] );
				$intact = $intact && ! is_wp_error( $entry );
				$mine[] = $entry;
			}
			update_post_meta( $car_id, self::META_REV, $car['rev'] + 1 );

			// Read the car back from the database, not from what was just handed to it.
			wp_cache_delete( $car_id, 'post_meta' );
			$intact = $intact && self::rev( $car_id ) === $car['rev'] + 1;
			foreach ( $car['changes'] as $kind => $change ) {
				$intact = $intact && self::{ 'read_' . $kind }( $car_id ) === $change['after'];
			}
			if ( $face !== self::public_face( $car_id ) ) {
				$moved[] = $car_id;
			}
			$entries                   = array_merge( $entries, $mine );
			$result['cars'][ $car_id ] = [ 'rev' => $car['rev'] + 1, 'kinds' => array_keys( $car['changes'] ), 'entries' => $mine ];
		}

		if ( ! $intact || count( $GLOBALS['EZSQL_ERROR'] ?? [] ) > $errors_before ) {
			self::rollback( $car_ids, $entries );
			return new WP_Error( 'glc_store_failed', glc_admin_t( 'err_database' ), [ 'status' => 500, 'batch' => $ctx['batch'] ] );
		}
		self::commit();
		self::$touched    = [];
		$result['pinged'] = self::announce( $moved, $ctx['batch'] );
		return $result;
	}

	/**
	 * What write_batch() would do, without writing: for a preview screen.
	 * Same $items and $ctx.
	 *
	 * @return array|WP_Error [ errors => [ car id => errors ], cars => [ id => [ rev, changes => [ kind => [ before, after ] ] ] ] ]
	 */
	public static function plan( array $items, array $ctx = [] ) {
		$ctx = self::context( $ctx );
		return is_wp_error( $ctx ) ? $ctx : self::plan_items( $items, $ctx );
	}

	private static function plan_items( array $items, array $ctx ): array {
		$plan = [ 'errors' => [], 'cars' => [] ];
		foreach ( $items as $car_id => $item ) {
			$car_id = (int) $car_id;
			$post   = get_post( $car_id );
			if ( ! $post || 'car' !== $post->post_type ) {
				$plan['errors'][ $car_id ] = [ self::error( 'err_not_car', 'err_not_car', [ $car_id ] ) ];
				continue;
			}
			if ( ! $ctx['trusted'] && ! GLC_Partners::can_manage( $ctx['actor'], $car_id ) ) {
				$plan['errors'][ $car_id ] = [ self::error( 'err_forbidden', 'err_forbidden' ) ];
				continue;
			}

			$errors = [];
			$rev    = self::rev( $car_id );
			$given  = is_numeric( $item['rev'] ?? null ) ? (int) $item['rev'] : null;
			if ( null === $given && ! $ctx['trusted'] ) {
				$errors[] = self::error( 'err_no_rev', 'err_no_rev' );
			} elseif ( null !== $given && $given !== $rev ) {
				$last     = GLC_Change_Log::latest( $car_id, self::KINDS );
				// actor_name is a name for the owner and a word ("you", "Geolander", "a previous partner") for a partner.
				$errors[] = $last
					? self::error( 'err_stale', 'err_stale', [ $last['actor_name'], $last['time'] ], [ 'rev' => $rev ] )
					: self::error( 'err_stale', 'err_stale_unknown', [], [ 'rev' => $rev ] );
			}

			$changes = (array) ( $item['changes'] ?? [] );
			foreach ( array_diff( array_keys( $changes ), self::KINDS ) as $unknown ) {
				$errors[] = self::error( 'err_unknown_kind', 'err_unknown_kind', [ (string) $unknown ] );
			}
			$changed = [];
			foreach ( self::KINDS as $kind ) {
				if ( ! array_key_exists( $kind, $changes ) ) {
					continue;
				}
				// The car and who is saving matter to photos (whose upload is it?); prices and fees do not look at them.
				$found = self::{ 'validate_' . $kind }( $changes[ $kind ], $ctx['level'], $car_id, $ctx );
				if ( $found ) {
					$errors = array_merge( $errors, $found );
					continue;
				}
				$before = self::{ 'read_' . $kind }( $car_id );
				$after  = self::{ 'clean_' . $kind }( $changes[ $kind ], $before, $ctx['level'], $car_id );
				if ( $before !== $after ) {
					$changed[ $kind ] = [ 'before' => $before, 'after' => $after ];
				}
			}

			if ( $errors ) {
				$plan['errors'][ $car_id ] = $errors;
			} else {
				$plan['cars'][ $car_id ] = [ 'rev' => $rev, 'changes' => $changed ];
			}
		}
		return $plan;
	}

	/* ------------------------------------------------- one kind: season table */

	/** The table as it is stored: label from the dates, the seven prices as numbers, the caller's season order. */
	private static function clean_pricing( array $seasons, array $before, string $level ): array {
		$seasons = array_values( $seasons );
		if ( self::LEVEL_SHAPE === $level ) {
			return $seasons; // a restore puts back exactly what was there
		}
		$out = [];
		foreach ( $seasons as $season ) {
			$rates = [];
			foreach ( GLC_Pricing::TIERS as $band ) {
				$rates[ $band ] = self::number( self::amount( $season['rates'][ $band ] )[0] );
			}
			$out[] = [
				'label' => self::season_label( $season['from'], $season['to'] ),
				'from'  => $season['from'],
				'to'    => $season['to'],
				'rates' => $rates,
			];
		}
		return $out;
	}

	private static function save_pricing( int $car_id, array $after, array $before ): void {
		if ( $before ) {
			update_post_meta( $car_id, self::META_PREVIOUS, wp_slash( $before ) );
		}
		update_post_meta( $car_id, self::META_PRICING, wp_slash( $after ) );
	}

	/* --------------------------------------------------- one kind: car's fees */

	/** Both parts, complete: a part the caller left out keeps its stored value; an empty amount drops the override. */
	private static function clean_fees( array $fees, array $before, string $level ): array {
		$after = $before;
		if ( isset( $fees['delivery'] ) ) {
			$after['delivery'] = [];
			foreach ( GLC_Rental::fee_place_keys() as $place ) {
				$amount = self::amount( $fees['delivery'][ $place ] ?? '' );
				if ( $amount ) {
					$after['delivery'][ $place ] = (string) self::number( $amount[0] );
				}
			}
		}
		if ( array_key_exists( self::OUT_OF_HOURS, $fees ) ) {
			$amount                     = self::amount( $fees[ self::OUT_OF_HOURS ] ?? '' );
			$after[ self::OUT_OF_HOURS ] = $amount ? (string) self::number( $amount[0] ) : '';
		}
		return $after;
	}

	/** An absent meta means "site standard", so an emptied override is removed, never stored as a blank. */
	private static function save_fees( int $car_id, array $after, array $before ): void {
		if ( $after['delivery'] ) {
			update_post_meta( $car_id, self::META_DELIVERY, wp_slash( $after['delivery'] ) );
		} else {
			delete_post_meta( $car_id, self::META_DELIVERY );
		}
		if ( '' !== $after[ self::OUT_OF_HOURS ] ) {
			update_post_meta( $car_id, self::META_OUT_OF_HOURS, wp_slash( $after[ self::OUT_OF_HOURS ] ) );
		} else {
			delete_post_meta( $car_id, self::META_OUT_OF_HOURS );
		}
	}

	/* ------------------------------------------------------ one kind: photos */

	/**
	 * The whole state: a part the caller left out keeps its stored value. No
	 * photo of a gallery is left without an alt text: a field sent empty gets the
	 * generated one ("<car> — photo 3"), and so does a photo that never had any.
	 * That also holds for a state put back from the log.
	 */
	private static function clean_photos( array $photos, array $before, string $level, int $car_id ): array {
		$after = $before;
		if ( isset( $photos['gallery'] ) ) {
			$after['gallery'] = array_values( array_map( 'intval', $photos['gallery'] ) );
		}
		if ( array_key_exists( 'main', $photos ) ) {
			$after['main'] = (int) $photos['main'];
		}
		$after['alts'] = [];
		$title         = (string) get_post_field( 'post_title', $car_id );
		foreach ( $after['gallery'] as $at => $id ) {
			if ( isset( $after['alts'][ $id ] ) ) {
				continue; // the same photo twice in a gallery has one alt
			}
			$alt = isset( $photos['alts'][ $id ] ) ? trim( sanitize_text_field( (string) $photos['alts'][ $id ] ) ) : (string) get_post_meta( $id, self::META_ALT, true );
			// The alt is public and stored once for every language of the site, so the generated one is always the English catalogue's.
			$after['alts'][ $id ] = '' !== $alt ? $alt : sprintf( glc_admin_t( 'photo_alt_default', GLC_Admin_Strings::DEFAULT_LANG ), $title, $at + 1 );
		}
		return $after;
	}

	/**
	 * Only what differs is written. A photo that belongs to no post yet becomes
	 * this car's; a photo taken out of the gallery is left exactly as it is: it
	 * stays in the library, with its file, for the owner.
	 */
	private static function save_photos( int $car_id, array $after, array $before ): void {
		global $wpdb;
		if ( $after['gallery'] !== $before['gallery'] ) {
			update_post_meta( $car_id, self::META_GALLERY, $after['gallery'] );
		}
		if ( $after['main'] !== $before['main'] ) {
			if ( $after['main'] ) {
				update_post_meta( $car_id, self::META_MAIN, $after['main'] );
			} else {
				delete_post_meta( $car_id, self::META_MAIN );
			}
		}
		foreach ( $after['alts'] as $id => $alt ) {
			if ( (string) get_post_meta( $id, self::META_ALT, true ) !== $alt ) {
				update_post_meta( $id, self::META_ALT, wp_slash( $alt ) );
			}
			// Only the parent changes, and only from "none": an upload that already belongs to a post is not taken from it.
			if ( $wpdb->update( $wpdb->posts, [ 'post_parent' => $car_id ], [ 'ID' => $id, 'post_type' => 'attachment', 'post_parent' => 0 ] ) ) {
				clean_post_cache( $id );
			}
			self::$touched[] = (int) $id;
			// So the check after the save reads the alt back from the database.
			wp_cache_delete( $id, 'post_meta' );
		}
	}

	/* ----------------------------------------------- one kind: car's details */

	/** All of SPEC_FIELDS, each in the type it is kept in: a detail the caller left out keeps its stored value. */
	private static function clean_specs( array $specs, array $before, string $level, int $car_id ): array {
		$after = $before;
		foreach ( array_intersect_key( $specs, self::SPEC_FIELDS ) as $key => $value ) {
			$type          = self::SPEC_FIELDS[ $key ];
			$after[ $key ] = 'seats' === $type ? (int) $value : ( 'switch' === $type ? ( true === $value || '1' === (string) $value ) : trim( (string) $value ) );
		}
		return $after;
	}

	/** Only the details that differ are written, so a detail nobody touched keeps the very row it had. */
	private static function save_specs( int $car_id, array $after, array $before ): void {
		foreach ( $after as $key => $value ) {
			if ( $value !== $before[ $key ] ) {
				update_post_meta( $car_id, $key, is_string( $value ) ? wp_slash( $value ) : $value );
			}
		}
	}

	/* --------------------------------------------------------------- helpers */

	/** @return array|WP_Error source, actor, batch, note, level, trusted */
	private static function context( array $ctx ) {
		$source  = (string) ( $ctx['source'] ?? '' );
		$trusted = self::trusted();
		// `cli` skips the per-user checks, so it only counts where there really is a shell.
		if ( ! in_array( $source, GLC_Change_Log::SOURCES, true ) || ( 'cli' === $source && ! $trusted ) ) {
			return new WP_Error( 'glc_store_refused', sprintf( glc_admin_t( 'err_source' ), $source ), [ 'status' => 400, 'errors' => [] ] );
		}
		return [
			'source'  => $source,
			// Who is saving is read from the session. Only a shell may say it is someone else.
			'actor'   => $trusted ? (int) ( $ctx['actor'] ?? get_current_user_id() ) : (int) get_current_user_id(),
			'batch'   => (string) ( $ctx['batch'] ?? wp_generate_uuid4() ),
			'note'    => (string) ( $ctx['note'] ?? '' ),
			'level'   => 'restore' === $source ? self::LEVEL_SHAPE : self::LEVEL_FULL,
			'trusted' => $trusted,
		];
	}

	private static function refusal( array $errors ): WP_Error {
		$codes  = array_merge( ...array_values( array_map( static fn( array $list ): array => array_column( $list, 'code' ), $errors ) ) );
		$status = in_array( 'err_forbidden', $codes, true ) ? 403 : ( in_array( 'err_stale', $codes, true ) ? 409 : 400 );
		$first  = reset( $errors )[0]['message'] ?? glc_admin_t( 'err_refused' );
		return new WP_Error( 'glc_store_refused', $first, [ 'status' => $status, 'errors' => $errors ] );
	}

	/**
	 * Lock the cars, in id order so two batches cannot deadlock. A second save of
	 * the same car waits here until the first is committed, then reads the
	 * revision the first one wrote and is refused as stale.
	 */
	private static function begin( array $car_ids ): void {
		global $wpdb;
		$wpdb->query( 'START TRANSACTION' );
		foreach ( $car_ids as $car_id ) {
			$wpdb->get_var( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE ID = %d FOR UPDATE", $car_id ) );
			wp_cache_delete( $car_id, 'post_meta' );
		}
	}

	private static function commit(): void {
		global $wpdb;
		$wpdb->query( 'COMMIT' );
	}

	/** Undo the database and forget what this request cached about the rows it touched. */
	private static function rollback( array $car_ids, array $entries = [] ): void {
		global $wpdb;
		$wpdb->query( 'ROLLBACK' );
		foreach ( $car_ids as $car_id ) {
			wp_cache_delete( $car_id, 'post_meta' );
		}
		foreach ( $entries as $entry ) {
			if ( is_int( $entry ) ) {
				clean_post_cache( $entry );
			}
		}
		foreach ( array_unique( self::$touched ) as $attachment ) {
			clean_post_cache( $attachment );
		}
		self::$touched = [];
	}

	/** What a visitor or a search engine can see change: the price range and whether the car can be booked. */
	private static function public_face( int $car_id ): array {
		return [ GLC_Pricing::rate_range( $car_id ), (bool) get_post_meta( $car_id, 'glc_available', true ) ];
	}

	/** One IndexNow call for the whole batch: the locale URLs of every published car whose public face moved. */
	private static function announce( array $car_ids, string $batch ): array {
		$urls = [];
		foreach ( $car_ids as $car_id ) {
			if ( 'publish' === get_post_status( $car_id ) ) {
				array_push( $urls, ...GLC_IndexNow::variants( (string) get_permalink( $car_id ) ) );
			}
		}
		if ( $urls ) {
			GLC_IndexNow::ping( $urls, 'pricing:' . $batch );
		}
		return $urls;
	}

	private static function error( string $code, string $message_key, array $args = [], array $details = [] ): array {
		return [ 'code' => $code, 'message' => vsprintf( glc_admin_t( $message_key ), $args ) ] + $details;
	}

	/** A limit from Settings → Geolander: a number; null when the field is empty (no limit); false when it is not a number. */
	private static function limit( string $key ) {
		$raw = GLC_Settings::get( $key );
		$raw = is_scalar( $raw ) ? trim( (string) $raw ) : '';
		if ( '' === $raw ) {
			return null;
		}
		return is_numeric( $raw ) ? (float) $raw : false;
	}

	/**
	 * A money amount as typed or stored: plain digits, optional decimals.
	 *
	 * @return array|null [ the number, how many decimals it really uses ]; null when it is not a plain number
	 */
	private static function amount( $value ): ?array {
		if ( is_int( $value ) ) {
			return [ (float) $value, 0 ];
		}
		if ( is_float( $value ) ) {
			if ( ! is_finite( $value ) ) {
				return null;
			}
			$value = rtrim( rtrim( sprintf( '%.6F', $value ), '0' ), '.' );
		}
		if ( ! is_string( $value ) || ! preg_match( '/^-?\d+(?:\.(\d+))?$/', trim( $value ), $m ) ) {
			return null;
		}
		return [ (float) trim( $value ), strlen( rtrim( $m[1] ?? '', '0' ) ) ];
	}

	/** Whole amounts are stored as integers, like the live tables; anything else to the cent. */
	private static function number( float $value ) {
		return floor( $value ) === $value ? (int) $value : round( $value, 2 );
	}

	/** @return array|null [ message key, extra message arguments ] for one price, or null when it is fine */
	private static function rate_problem( $value, ?float $min, ?float $max ): ?array {
		if ( null === $value || '' === $value ) {
			return [ 'err_rate_missing', [] ];
		}
		$amount = self::amount( $value );
		if ( ! $amount ) {
			return [ 'err_rate_number', [ is_scalar( $value ) ? (string) $value : '…' ] ];
		}
		[ $number, $decimals ] = $amount;
		if ( $number <= 0 ) {
			return [ 'err_rate_zero', [] ];
		}
		if ( $decimals > 2 ) {
			return [ 'err_rate_decimals', [] ];
		}
		if ( null !== $min && $number < $min ) {
			return [ 'err_rate_low', [ self::money( $number ), self::money( $min ) ] ];
		}
		if ( null !== $max && $number > $max ) {
			return [ 'err_rate_high', [ self::money( $number ), self::money( $max ) ] ];
		}
		return null;
	}

	private static function fee_problem( string $place, $value, ?float $max ): ?array {
		if ( null === $value || '' === $value ) {
			return null; // empty = the site standard
		}
		$name   = glc_admin_t( 'place_' . $place );
		$amount = self::amount( $value );
		if ( ! $amount || $amount[0] < 0 ) {
			return self::error( 'err_fee', 'err_fee_number', [ $name, is_scalar( $value ) ? (string) $value : '…' ], [ 'place' => $place ] );
		}
		if ( $amount[1] > 2 ) {
			return self::error( 'err_fee', 'err_fee_decimals', [ $name ], [ 'place' => $place ] );
		}
		if ( null !== $max && $amount[0] > $max ) {
			return self::error( 'err_fee', 'err_fee_high', [ $name, self::money( $amount[0] ), self::money( $max ) ], [ 'place' => $place ] );
		}
		return null;
	}

	private static function money( float $value ): string {
		return GLC_Format::money_exact( $value, GLC_Admin_Strings::lang() );
	}

	/** "Jan 6 – Mar 31" in the reader's language, or a dash while the dates are not valid yet. */
	private static function season_text( array $season ): string {
		if ( ! self::is_day( $season['from'] ?? '' ) || ! self::is_day( $season['to'] ?? '' ) ) {
			return glc_admin_t( 'not_set' );
		}
		return sprintf( glc_admin_t( 'date_range' ), GLC_Admin_Strings::day( $season['from'] ), GLC_Admin_Strings::day( $season['to'] ) );
	}

	private static function is_day( $value ): bool {
		return is_string( $value ) && preg_match( '/^(\d{2})-(\d{2})$/', $value, $m ) && checkdate( (int) $m[1], (int) $m[2], self::CALENDAR_YEAR );
	}

	/** The 365 MM-DD days a season table has to cover. */
	public static function year_days(): array {
		static $days = null;
		if ( null === $days ) {
			$days = [];
			for ( $month = 1; $month <= 12; $month++ ) {
				for ( $day = 1; checkdate( $month, $day, self::CALENDAR_YEAR ); $day++ ) {
					$days[] = sprintf( '%02d-%02d', $month, $day );
				}
			}
		}
		return $days;
	}
}
