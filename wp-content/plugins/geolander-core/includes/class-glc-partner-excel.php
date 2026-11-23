<?php
/**
 * Prices by Excel: download the current seasons and prices of every car a
 * person looks after, fill in new prices, upload the file, see exactly which
 * cells differ, apply.
 *
 * The file carries PRICES and FEES only. Seasons cannot be added, removed or
 * re-dated in it: a file whose season rows do not match the panel's is refused
 * whole, and the message says that season dates are changed in the panel. The
 * season dates, the plate and the car's name are in the file so a person can
 * see what a row is; the upload checks them and never takes them.
 *
 * A file is a snapshot. Its Meta sheet records the revision each car had when
 * the file was made, and a file made before a later change to one of its cars
 * is refused: nobody overwrites, from a spreadsheet, a change they never saw.
 *
 * An uploaded file is hostile until read (see GLC_Xlsx). It is read where PHP
 * put it and never moved, so it is never anywhere a browser could fetch it.
 * Every car in it is checked against the person, and again by the store; one
 * car that is not theirs refuses the whole file.
 *
 * The upload is posted to this screen's own address, not to admin-post.php:
 * the web server cuts a request to admin-post.php off at its own size limit
 * with its own error page, and a person who picked the wrong file should get
 * a sentence from this screen instead. The limit a person is told is the
 * setting `xlsx_max_kb`.
 */

defined( 'ABSPATH' ) || exit;

class GLC_Partner_Excel {

	public const PAGE = 'glc-partner-excel';

	private const DOWNLOAD = 'glc_xlsx_download';
	private const UPLOAD   = 'glc_xlsx_upload';
	private const APPLY    = 'glc_xlsx_apply';
	private const SOURCE   = 'excel';

	public const FILE_FIELD = 'glc_file';

	/** The part of the screen's address that says an upload was too large to arrive, and how large it was (KB). */
	private const TOO_BIG = 'too_big';

	/** The sheets of the file. The reader finds them by these names, and their columns by the heading in row 1. */
	public const SHEET_PRICES = 'Prices';
	public const SHEET_FEES   = 'Fees';
	public const SHEET_META   = 'Meta';

	/** The headings that say what a row is. Everything after them in a sheet is a price or a fee. */
	private const PRICE_IDENTITY = [ 'car_id', 'plate', 'car', 'season', 'from', 'to', 'label' ];
	private const FEE_IDENTITY   = [ 'car_id', 'plate', 'car' ];
	private const META_COLUMNS   = [ 'car_id', 'rev', 'generated_at', 'user_id' ];

	/** What a fee cell says to go back to the Geolander standard. */
	public const WORD_STANDARD = 'site';

	private const MIME = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

	/** Two amounts closer than this are the same price: Excel stores 54.99 as 54.990000000000002. */
	private const SAME_AMOUNT = 0.000001;

	/** Room for columns a person added beside the template's own, as a multiple of the template's width. */
	private const EXTRA_COLUMNS = 2;

	/** Why the reader refused a file, and the sentence for each. */
	private const READER_REASONS = [
		'not_zip'        => 'xlsx_err_not_xlsx',
		'no_workbook'    => 'xlsx_err_not_xlsx',
		'path'           => 'xlsx_err_unsafe',
		'doctype'        => 'xlsx_err_unsafe',
		'bad_xml'        => 'xlsx_err_unreadable',
		'too_big'        => 'xlsx_err_unfolds',
		'too_many_rows'  => 'xlsx_err_rows',
		'too_many_cells' => 'xlsx_err_rows',
	];

	public static function init(): void {
		add_action( 'admin_menu', [ __CLASS__, 'menu' ] );
		add_action( 'admin_post_' . self::DOWNLOAD, [ __CLASS__, 'download' ] );
		add_action( 'admin_post_' . self::APPLY, [ __CLASS__, 'apply' ] );
		foreach ( [ self::DOWNLOAD, self::APPLY ] as $action ) {
			add_action( 'admin_post_nopriv_' . $action, [ 'GLC_Partner_Panel', 'refuse' ] );
		}
	}

	public static function menu(): void {
		$hook = add_submenu_page( GLC_Partner_Panel::parent_slug(), glc_admin_t( 'xlsx_title' ), glc_admin_t( 'xlsx_menu' ), GLC_Partners::CAP_PANEL, self::PAGE, [ __CLASS__, 'render' ], GLC_Partner_Panel::menu_position( 2 ) );
		add_action( 'load-' . $hook, [ __CLASS__, 'load' ] );
	}

	/** Before WordPress prints anything: a refusal is a real 403, and an upload is answered with a redirect. */
	public static function load(): void {
		if ( ! current_user_can( GLC_Partners::CAP_PANEL ) ) {
			GLC_Partner_Panel::refuse();
		}
		if ( 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
			self::upload();
		}
		add_action( 'admin_enqueue_scripts', [ 'GLC_Partner_Car', 'enqueue' ] );
	}

	/* ------------------------------------------------------------ the limits */

	private static function setting_number( string $key ): int {
		$value = GLC_Settings::get( $key );
		return is_numeric( $value ) && (int) $value > 0 ? (int) $value : 0;
	}

	/** The largest file a person may upload, in bytes. */
	public static function max_bytes(): int {
		return self::setting_number( 'xlsx_max_kb' ) * KB_IN_BYTES;
	}

	/** What the reader may take from one file: see GLC_Xlsx::read(). */
	private static function reader_limits(): array {
		$rows  = self::setting_number( 'xlsx_max_rows' );
		$width = count( self::PRICE_IDENTITY ) + count( GLC_Pricing::TIERS );
		return [
			'part_bytes' => self::setting_number( 'xlsx_max_part_kb' ) * KB_IN_BYTES,
			'rows'       => $rows,
			'cells'      => $rows * $width * self::EXTRA_COLUMNS,
		];
	}

	private static function fee_columns(): array {
		return array_merge( GLC_Rental::fee_place_keys(), [ GLC_Price_Store::OUT_OF_HOURS ] );
	}

	/** A plate as two people would agree it is the same one: letters and digits only, capitals. The Localrent import compares plates the same way. */
	private static function plate( string $plate ): string {
		return strtoupper( (string) preg_replace( '/[^A-Za-z0-9]/', '', $plate ) );
	}

	/* ---------------------------------------------------------- the download */

	/**
	 * The three sheets for these cars, as GLC_Xlsx::write() takes them.
	 * A text cell is a string, a number an int or a float, an empty cell null.
	 */
	public static function sheets( array $car_ids, int $user_id ): array {
		$number = static fn( $value ) => is_numeric( $value ) ? ( (float) $value === floor( (float) $value ) ? (int) $value : (float) $value ) : null;
		$prices = [ array_merge( self::PRICE_IDENTITY, GLC_Pricing::TIERS ) ];
		$fees   = [ array_merge( self::FEE_IDENTITY, self::fee_columns() ) ];
		$meta   = [ self::META_COLUMNS ];
		$made   = GLC_Settings::local_time( time(), 'c' );
		foreach ( $car_ids as $car_id ) {
			$plate = trim( (string) get_post_meta( $car_id, 'glc_registration', true ) );
			$title = get_the_title( $car_id );
			$state = GLC_Price_Store::state( $car_id );
			foreach ( array_values( $state['pricing'] ) as $i => $season ) {
				$row = [ (int) $car_id, $plate, $title, $i + 1, (string) ( $season['from'] ?? '' ), (string) ( $season['to'] ?? '' ), (string) ( $season['label'] ?? '' ) ];
				foreach ( GLC_Pricing::TIERS as $band ) {
					$row[] = $number( $season['rates'][ $band ] ?? null );
				}
				$prices[] = $row;
			}
			// A fee cell shows the car's own amount; a car on the Geolander standard has an empty cell, which an upload reads as "keep".
			$row = [ (int) $car_id, $plate, $title ];
			foreach ( GLC_Partner_Car::flat_fees( $state['fees'] ) as $amount ) {
				$row[] = $number( $amount );
			}
			$fees[] = $row;
			$meta[] = [ (int) $car_id, GLC_Price_Store::rev( $car_id ), $made, $user_id ];
		}
		return [ self::SHEET_PRICES => $prices, self::SHEET_FEES => $fees, self::SHEET_META => $meta ];
	}

	/** The file for the person's own cars (every car for the owner). The request names no car, so it cannot name someone else's. */
	public static function download(): void {
		GLC_Partner_Batch::check( self::DOWNLOAD );
		$user = wp_get_current_user();
		// Written in PHP's own temporary folder, outside the site, and removed before a byte is sent.
		$path  = tempnam( sys_get_temp_dir(), 'glcx' );
		$bytes = $path && GLC_Xlsx::write( self::sheets( GLC_Partners::cars_for( (int) $user->ID ), (int) $user->ID ), $path ) ? file_get_contents( $path ) : false;
		if ( $path && is_file( $path ) ) {
			unlink( $path );
		}
		if ( false === $bytes ) {
			wp_die( esc_html( glc_admin_t( 'xlsx_err_write' ) ), 500 );
		}
		nocache_headers();
		header( 'Content-Type: ' . self::MIME );
		header( 'Content-Disposition: attachment; filename="' . sprintf( 'geolander-prices-%s-%s.xlsx', sanitize_title( $user->user_login ), GLC_Settings::local_time( time(), 'Y-m-d' ) ) . '"' );
		header( 'Content-Length: ' . strlen( $bytes ) );
		header( 'X-Content-Type-Options: nosniff' );
		echo $bytes; // phpcs:ignore WordPress.Security.EscapeOutput -- a binary file, not HTML
		exit;
	}

	/* ------------------------------------------------------------ the upload */

	/** Answer an upload: read the file, work out what it changes, keep that for the preview. Writes to no car. */
	private static function upload(): void {
		// A request larger than PHP accepts arrives with nothing in it, the nonce included: say what happened instead of refusing.
		// Nothing proves who sent it, so nothing is kept for it: its size travels in the address of the screen that says so.
		if ( ! $_POST && ! $_FILES && (int) ( $_SERVER['CONTENT_LENGTH'] ?? 0 ) > 0 ) {
			wp_safe_redirect( GLC_Partner_Panel::screen_url( self::PAGE, [ self::TOO_BIG => (int) ceil( (int) $_SERVER['CONTENT_LENGTH'] / KB_IN_BYTES ) ] ) . '#glc-preview', 303 );
			exit;
		}
		GLC_Partner_Batch::check( self::UPLOAD );
		$file = $_FILES[ self::FILE_FIELD ] ?? null;
		$stop = self::upload_problem( $file );
		if ( null !== $stop ) {
			self::answer( [ $stop ] );
		}
		try {
			$book = GLC_Xlsx::read( (string) $file['tmp_name'], self::reader_limits() );
		} catch ( GLC_Xlsx_Refused $refused ) {
			self::answer( [ sprintf( glc_admin_t( self::READER_REASONS[ $refused->reason ] ?? 'xlsx_err_unreadable' ), self::kb( self::setting_number( 'xlsx_max_part_kb' ) * KB_IN_BYTES ), self::setting_number( 'xlsx_max_rows' ) ) ] );
		}
		$plan = self::plan_from( $book, get_current_user_id() );
		self::answer( $plan['stops'], $plan );
	}

	/** Why the upload itself cannot be read (no file, cut off, too large); null when there is a file to open. */
	private static function upload_problem( $file ): ?string {
		if ( ! is_array( $file ) || ! isset( $file['error'], $file['tmp_name'], $file['size'] ) || is_array( $file['error'] ) || UPLOAD_ERR_NO_FILE === $file['error'] ) {
			return glc_admin_t( 'xlsx_err_no_file' );
		}
		if ( in_array( $file['error'], [ UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE ], true ) ) {
			return sprintf( glc_admin_t( 'xlsx_err_size_unknown' ), self::kb( self::max_bytes() ) );
		}
		if ( UPLOAD_ERR_OK !== $file['error'] || ! is_uploaded_file( (string) $file['tmp_name'] ) ) {
			return glc_admin_t( 'xlsx_err_upload' );
		}
		// The size is the file's own on disk, not the number the browser sent with it.
		$size = (int) filesize( (string) $file['tmp_name'] );
		if ( $size > self::max_bytes() ) {
			return sprintf( glc_admin_t( 'xlsx_err_size' ), self::kb( $size ), self::kb( self::max_bytes() ) );
		}
		return null;
	}

	private static function kb( int $bytes ): string {
		return GLC_Format::number( (float) ceil( $bytes / KB_IN_BYTES ), GLC_Admin_Strings::lang() );
	}

	/** Keep the outcome of an upload and go to the screen that shows it. */
	private static function answer( array $stops, array $plan = [] ): void {
		$limit = self::setting_number( 'xlsx_max_reasons' );
		if ( $limit && count( $stops ) > $limit ) {
			$more    = count( $stops ) - $limit;
			$stops   = array_slice( $stops, 0, $limit );
			$stops[] = sprintf( glc_admin_t( 'xlsx_err_more' ), $more );
		}
		$token = GLC_Partner_Batch::keep( [
			'source' => self::SOURCE,
			'stops'  => $stops,
			'items'  => $stops ? [] : (array) ( $plan['items'] ?? [] ),
			'cells'  => $stops ? [] : (array) ( $plan['cells'] ?? [] ),
			'refs'   => $stops ? [] : (array) ( $plan['refs'] ?? [] ),
		] );
		wp_safe_redirect( GLC_Partner_Panel::screen_url( self::PAGE, [ GLC_Partner_Batch::FIELD => $token ] ) . '#glc-preview', 303 );
		exit;
	}

	/* ------------------------------------------------- from a file to a plan */

	/** A cell as a whole number; null when it is not one. Excel may hand a typed number back as text. */
	private static function whole( ?array $cell ): ?int {
		$value = null === $cell ? '' : trim( $cell[1] );
		if ( GLC_Xlsx::NUMBER === ( $cell[0] ?? '' ) && is_numeric( $value ) && (float) $value === floor( (float) $value ) ) {
			return (int) (float) $value;
		}
		return ctype_digit( $value ) ? (int) $value : null;
	}

	/** A cell as text, trimmed; '' for an empty one. */
	private static function text( ?array $cell ): string {
		return null === $cell ? '' : trim( $cell[1] );
	}

	/**
	 * A cell as an amount: null when empty, the amount as plain digits, or false
	 * when it is something else. A number cell is taken as the number Excel shows
	 * (54.990000000000002 is 54.99); a text cell must be plain digits with a dot.
	 */
	private static function amount( ?array $cell ) {
		if ( null === $cell || '' === trim( $cell[1] ) ) {
			return null;
		}
		$value = trim( $cell[1] );
		if ( GLC_Xlsx::NUMBER === $cell[0] ) {
			return is_numeric( $value ) && (float) $value >= 0 ? rtrim( rtrim( sprintf( '%.6F', (float) $value ), '0' ), '.' ) : false;
		}
		return preg_match( '/^\d+(?:\.\d+)?$/', $value ) ? $value : false;
	}

	/** A season date cell as MM-DD. Excel turns a date typed into an ordinary cell into a number; that number is read back as its day. */
	private static function day( ?array $cell, bool $date1904 ): string {
		if ( null !== $cell && GLC_Xlsx::NUMBER === $cell[0] ) {
			$date = GLC_Xlsx::serial_to_date( $cell[1], $date1904 );
			return null === $date ? trim( $cell[1] ) : substr( $date, 5 );
		}
		return self::text( $cell );
	}

	/** [ heading => column letters ] from a sheet's first row; headings are compared without case or outer spaces. */
	private static function columns( array $rows ): array {
		$columns = [];
		foreach ( (array) ( $rows[1] ?? [] ) as $letters => $cell ) {
			$columns += [ strtolower( trim( $cell[1] ) ) => $letters ];
		}
		return $columns;
	}

	private static function same( $a, $b ): bool {
		return is_numeric( $a ) && is_numeric( $b ) ? abs( (float) $a - (float) $b ) < self::SAME_AMOUNT : (string) $a === (string) $b;
	}

	/**
	 * What an uploaded workbook would change.
	 *
	 * @param array $book what GLC_Xlsx::read() returned
	 * @return array stops: every reason the file is refused (each names its cell), in the reader's language;
	 *               items: [ car id => [ changes, rev ] ] for the store, only cars the file really changes;
	 *               cells: every cell that differs from what is stored: ref, car_id, what (season and band, or place), before, after;
	 *               refs:  [ car id => [ 'p:<season>:<band>' | 'f:<place>' => cell ] ], so a refusal by the store can name its cell
	 */
	public static function plan_from( array $book, int $user_id ): array {
		$out    = [ 'stops' => [], 'items' => [], 'cells' => [], 'refs' => [] ];
		$sheets = $book['sheets'];
		$wanted = [
			self::SHEET_PRICES => array_merge( self::PRICE_IDENTITY, GLC_Pricing::TIERS ),
			self::SHEET_FEES   => array_merge( self::FEE_IDENTITY, self::fee_columns() ),
			self::SHEET_META   => self::META_COLUMNS,
		];
		$col = [];
		foreach ( $wanted as $sheet => $headings ) {
			if ( ! isset( $sheets[ $sheet ] ) ) {
				$out['stops'][] = sprintf( glc_admin_t( 'xlsx_err_sheet' ), $sheet );
				continue;
			}
			$col[ $sheet ] = self::columns( $sheets[ $sheet ] );
			foreach ( array_diff( $headings, array_keys( $col[ $sheet ] ) ) as $missing ) {
				$out['stops'][] = sprintf( glc_admin_t( 'xlsx_err_heading' ), $sheet, $missing );
			}
		}
		if ( $out['stops'] ) {
			array_unshift( $out['stops'], glc_admin_t( 'xlsx_err_template' ) );
			return $out;
		}
		$stop = static function ( string $sheet, string $cell, string $key, ...$args ) use ( &$out ): void {
			$out['stops'][] = sprintf( glc_admin_t( 'batch_cell_line' ), $sheet . '!' . $cell, vsprintf( glc_admin_t( $key ), $args ) );
		};
		$at = static fn( array $row, string $sheet, string $heading ): ?array => $row[ $col[ $sheet ][ $heading ] ] ?? null;

		// Meta: the revision each car had when the file was made.
		$revs = [];
		foreach ( $sheets[ self::SHEET_META ] as $r => $row ) {
			$car_id = self::whole( $at( $row, self::SHEET_META, 'car_id' ) );
			$rev    = self::whole( $at( $row, self::SHEET_META, 'rev' ) );
			if ( 1 === $r || ( null === $car_id && null === $rev ) ) {
				continue;
			}
			if ( null === $car_id || null === $rev || isset( $revs[ $car_id ] ) ) {
				$stop( self::SHEET_META, $col[ self::SHEET_META ]['car_id'] . $r, 'xlsx_err_meta' );
				continue;
			}
			$revs[ $car_id ] = $rev;
		}

		/*
		 * Is this row's car one the person may change, is it the car the row says
		 * it is, and is the file as new as the car? Asked once per car; the answer
		 * for a car that fails is the same sentence whether the car is someone
		 * else's, not a car, or not there at all.
		 */
		$cars  = [];
		$known = static function ( string $sheet, int $r, array $row ) use ( &$cars, $revs, $user_id, $stop, $at, $col ): ?int {
			$ref    = $col[ $sheet ]['car_id'] . $r;
			$car_id = self::whole( $at( $row, $sheet, 'car_id' ) );
			if ( null === $car_id ) {
				$stop( $sheet, $ref, 'xlsx_err_car_number', self::text( $at( $row, $sheet, 'car_id' ) ) );
				return null;
			}
			if ( ! isset( $cars[ $car_id ] ) ) {
				$cars[ $car_id ] = false;
				if ( ! GLC_Partners::can_manage( $user_id, $car_id ) ) {
					$stop( $sheet, $ref, 'xlsx_err_car', $car_id );
				} elseif ( ! isset( $revs[ $car_id ] ) ) {
					$stop( $sheet, $ref, 'xlsx_err_no_meta', $car_id );
				} elseif ( GLC_Price_Store::rev( $car_id ) !== $revs[ $car_id ] ) {
					$last = GLC_Change_Log::latest( $car_id, GLC_Price_Store::KINDS );
					$last
						? $stop( $sheet, $ref, 'xlsx_err_stale', GLC_Partner_Batch::car_name( $car_id ), $last['actor_name'], $last['time'] )
						: $stop( $sheet, $ref, 'xlsx_err_stale_unknown', GLC_Partner_Batch::car_name( $car_id ) );
				} else {
					$cars[ $car_id ] = GLC_Price_Store::state( $car_id );
				}
			}
			if ( false === $cars[ $car_id ] ) {
				return null;
			}
			$plate = self::text( $at( $row, $sheet, 'plate' ) );
			if ( self::plate( $plate ) !== self::plate( (string) get_post_meta( $car_id, 'glc_registration', true ) ) ) {
				$stop( $sheet, $col[ $sheet ]['plate'] . $r, 'xlsx_err_plate', $plate, $car_id );
				return null;
			}
			return $car_id;
		};

		// Prices: one row per season of a car. Dates are checked, prices are taken.
		$tables = [];
		$seen   = [];
		foreach ( $sheets[ self::SHEET_PRICES ] as $r => $row ) {
			if ( 1 === $r || ! array_intersect( array_keys( $row ), $col[ self::SHEET_PRICES ] ) ) {
				continue;
			}
			$car_id = $known( self::SHEET_PRICES, $r, $row );
			if ( null === $car_id ) {
				continue;
			}
			$stored = array_values( $cars[ $car_id ]['pricing'] );
			$number = self::whole( $at( $row, self::SHEET_PRICES, 'season' ) );
			$season = null === $number ? null : ( $stored[ $number - 1 ] ?? null );
			if ( null === $season || isset( $seen[ $car_id ][ $number ] ) ) {
				$stop( self::SHEET_PRICES, $col[ self::SHEET_PRICES ]['season'] . $r, isset( $seen[ $car_id ][ $number ?? 0 ] ) ? 'xlsx_err_season_twice' : 'xlsx_err_season_extra', self::text( $at( $row, self::SHEET_PRICES, 'season' ) ), GLC_Partner_Batch::car_name( $car_id ), count( $stored ) );
				continue;
			}
			$seen[ $car_id ][ $number ] = true;
			$tables[ $car_id ]        ??= $stored;
			foreach ( [ 'from', 'to' ] as $end ) {
				$given = self::day( $at( $row, self::SHEET_PRICES, $end ), (bool) $book['date1904'] );
				if ( $given !== (string) $season[ $end ] ) {
					$stop( self::SHEET_PRICES, $col[ self::SHEET_PRICES ][ $end ] . $r, 'xlsx_err_season_date', $given, (string) $season[ $end ] );
				}
			}
			foreach ( GLC_Pricing::TIERS as $band ) {
				$ref    = $col[ self::SHEET_PRICES ][ $band ] . $r;
				$amount = self::amount( $at( $row, self::SHEET_PRICES, $band ) );
				$before = $season['rates'][ $band ] ?? '';
				$out['refs'][ $car_id ][ 'p:' . ( $number - 1 ) . ':' . $band ] = self::SHEET_PRICES . '!' . $ref;
				if ( false === $amount ) {
					$stop( self::SHEET_PRICES, $ref, 'xlsx_err_number', self::text( $at( $row, self::SHEET_PRICES, $band ) ) );
				} elseif ( null !== $amount && ! self::same( $amount, $before ) ) {
					// An empty cell keeps the stored price; so does a cell that still holds it.
					$tables[ $car_id ][ $number - 1 ]['rates'][ $band ] = $amount;
					$out['cells'][] = [ 'ref' => self::SHEET_PRICES . '!' . $ref, 'car_id' => $car_id, 'what' => GLC_Partner_Car::season_text( $season ) . ', ' . GLC_Partner_Car::band_text( $band ), 'before' => is_numeric( $before ) ? GLC_Partner_Car::money( $before ) : glc_admin_t( 'not_set' ), 'after' => GLC_Partner_Car::money( $amount ) ];
				}
			}
		}
		// A car the file prices must come with every one of its seasons: a missing row is a removed season.
		foreach ( $seen as $car_id => $numbers ) {
			foreach ( array_keys( $cars[ $car_id ]['pricing'] ) as $i ) {
				if ( ! isset( $numbers[ $i + 1 ] ) ) {
					$out['stops'][] = sprintf( glc_admin_t( 'xlsx_err_season_missing' ), self::SHEET_PRICES, GLC_Partner_Batch::car_name( $car_id ), $i + 1, GLC_Partner_Car::season_text( $cars[ $car_id ]['pricing'][ $i ] ) );
				}
			}
		}

		// Fees: one row per car. A number, 0 for free, the word for "back to the standard", or empty to keep.
		$fees = [];
		foreach ( $sheets[ self::SHEET_FEES ] as $r => $row ) {
			if ( 1 === $r || ! array_intersect( array_keys( $row ), $col[ self::SHEET_FEES ] ) ) {
				continue;
			}
			$car_id = $known( self::SHEET_FEES, $r, $row );
			if ( null === $car_id ) {
				continue;
			}
			if ( isset( $fees[ $car_id ] ) ) {
				$stop( self::SHEET_FEES, $col[ self::SHEET_FEES ]['car_id'] . $r, 'xlsx_err_car_twice', $car_id );
				continue;
			}
			$flat = GLC_Partner_Car::flat_fees( $cars[ $car_id ]['fees'] );
			$new  = $flat;
			foreach ( self::fee_columns() as $place ) {
				$ref  = $col[ self::SHEET_FEES ][ $place ] . $r;
				$cell = $at( $row, self::SHEET_FEES, $place );
				$out['refs'][ $car_id ][ 'f:' . $place ] = self::SHEET_FEES . '!' . $ref;
				if ( self::WORD_STANDARD === strtolower( self::text( $cell ) ) ) {
					$new[ $place ] = '';
				} elseif ( false === self::amount( $cell ) ) {
					$stop( self::SHEET_FEES, $ref, 'xlsx_err_fee', self::text( $cell ), self::WORD_STANDARD );
				} elseif ( null !== self::amount( $cell ) && ! self::same( self::amount( $cell ), $flat[ $place ] ) ) {
					$new[ $place ] = self::amount( $cell );
				}
				if ( $new[ $place ] !== $flat[ $place ] ) {
					$out['cells'][] = [ 'ref' => self::SHEET_FEES . '!' . $ref, 'car_id' => $car_id, 'what' => glc_admin_t( 'place_' . $place ), 'before' => GLC_Partner_Car::fee_text( $place, $flat[ $place ] ), 'after' => GLC_Partner_Car::fee_text( $place, $new[ $place ] ) ];
				}
			}
			$fees[ $car_id ] = $new === $flat ? null : $new;
		}

		if ( $out['stops'] ) {
			return [ 'items' => [], 'cells' => [], 'refs' => [] ] + $out;
		}
		foreach ( array_keys( array_filter( $cars ) ) as $car_id ) {
			$changes = [];
			if ( isset( $tables[ $car_id ] ) && $tables[ $car_id ] !== array_values( $cars[ $car_id ]['pricing'] ) ) {
				$changes['pricing'] = $tables[ $car_id ];
			}
			if ( isset( $fees[ $car_id ] ) ) {
				$new             = $fees[ $car_id ];
				$out_of_hours    = $new[ GLC_Price_Store::OUT_OF_HOURS ];
				unset( $new[ GLC_Price_Store::OUT_OF_HOURS ] );
				$changes['fees'] = [ 'delivery' => $new, GLC_Price_Store::OUT_OF_HOURS => $out_of_hours ];
			}
			if ( $changes ) {
				$out['items'][ $car_id ] = [ 'changes' => $changes, 'rev' => $revs[ $car_id ] ];
			}
		}
		return $out;
	}

	public static function apply(): void {
		GLC_Partner_Batch::apply( self::APPLY, self::SOURCE, self::PAGE );
	}

	/* ------------------------------------------------------------- rendering */

	public static function render(): void {
		if ( ! current_user_can( GLC_Partners::CAP_PANEL ) ) {
			GLC_Partner_Panel::refuse();
		}
		$cars  = GLC_Partners::cars_for( get_current_user_id() );
		$token = GLC_Partner_Batch::requested_token();
		$plan  = GLC_Partner_Batch::get( $token, self::SOURCE );
		$flash = GLC_Partner_Panel::take_flash( self::SOURCE );
		$max   = self::max_bytes();
		// How large an upload was that arrived empty (see upload()): a whole number of KB from the address, or nothing.
		$too_big = isset( $_GET[ self::TOO_BIG ] ) && is_string( $_GET[ self::TOO_BIG ] ) && ctype_digit( $_GET[ self::TOO_BIG ] ) && strlen( $_GET[ self::TOO_BIG ] ) < 10 ? (int) $_GET[ self::TOO_BIG ] : 0;
		?>
		<div class="wrap glc-panel" lang="<?php echo esc_attr( GLC_Admin_Strings::lang() ); ?>">
			<h1><?php echo esc_html( glc_admin_t( 'xlsx_title' ) ); ?></h1>
			<?php GLC_Partner_Batch::render_result( $flash ); ?>
			<?php if ( '' !== $token && ! $plan && ! $flash ) : ?>
				<div class="notice notice-warning inline"><p><?php echo esc_html( glc_admin_t( 'batch_gone' ) ); ?></p></div>
			<?php endif; ?>
			<?php if ( ! $cars ) : ?>
				<p><?php echo esc_html( glc_admin_t( current_user_can( GLC_Partners::CAP_MANAGE ) ? 'cars_empty_all' : 'cars_empty' ) ); ?></p>
				</div>
				<?php
				return;
			endif;
			?>

			<h2><?php echo esc_html( glc_admin_t( 'xlsx_step_download' ) ); ?></h2>
			<p><?php echo esc_html( sprintf( glc_admin_t( 'xlsx_download_help' ), count( $cars ) ) ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="glc-xlsx-download">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::DOWNLOAD ); ?>" />
				<?php wp_nonce_field( self::DOWNLOAD, '_wpnonce', false ); ?>
				<p><button type="submit" class="button button-primary"><?php echo esc_html( glc_admin_t( 'xlsx_download_button' ) ); ?></button></p>
			</form>

			<h2><?php echo esc_html( glc_admin_t( 'xlsx_step_fill' ) ); ?></h2>
			<ul class="glc-change-list">
				<li><?php echo esc_html( sprintf( glc_admin_t( 'xlsx_fill_prices' ), self::SHEET_PRICES, implode( glc_admin_t( 'price_join' ), GLC_Pricing::TIERS ), implode( glc_admin_t( 'price_join' ), GLC_Pricing::TIER_LABELS ) ) ); ?></li>
				<li><?php echo esc_html( sprintf( glc_admin_t( 'xlsx_fill_fees' ), self::SHEET_FEES, self::WORD_STANDARD ) ); ?></li>
				<li><?php echo esc_html( glc_admin_t( 'xlsx_fill_keep' ) ); ?></li>
				<li><strong><?php echo esc_html( glc_admin_t( 'xlsx_fill_seasons' ) ); ?></strong> <a href="<?php echo esc_url( GLC_Partner_Panel::url() ); ?>"><?php echo esc_html( glc_admin_t( 'xlsx_fill_seasons_link' ) ); ?></a></li>
				<li><?php echo esc_html( glc_admin_t( 'xlsx_fill_fresh' ) ); ?></li>
			</ul>

			<h2><?php echo esc_html( glc_admin_t( 'xlsx_step_upload' ) ); ?></h2>
			<form method="post" enctype="multipart/form-data" action="<?php echo esc_url( GLC_Partner_Panel::screen_url( self::PAGE ) ); ?>" class="glc-xlsx-upload" data-max-bytes="<?php echo (int) $max; ?>" data-too-big="<?php echo esc_attr( sprintf( glc_admin_t( 'xlsx_err_size' ), '%s', self::kb( $max ) ) ); ?>">
				<?php wp_nonce_field( self::UPLOAD, '_wpnonce', false ); ?>
				<p>
					<label for="glc-file"><?php echo esc_html( glc_admin_t( 'xlsx_file_label' ) ); ?></label>
					<input type="file" id="glc-file" name="<?php echo esc_attr( self::FILE_FIELD ); ?>" accept=".xlsx,<?php echo esc_attr( self::MIME ); ?>" required />
				</p>
				<p class="description"><?php echo esc_html( sprintf( glc_admin_t( 'xlsx_file_help' ), self::kb( $max ) ) ); ?></p>
				<p class="glc-upload-out glc-fault" role="alert" hidden></p>
				<p><button type="submit" class="button button-primary"><?php echo esc_html( glc_admin_t( 'xlsx_upload_button' ) ); ?></button></p>
			</form>

			<?php if ( ! $plan && $too_big ) : ?>
				<div id="glc-preview" class="glc-batch-preview">
					<?php GLC_Partner_Batch::render_preview( '', [ 'stops' => [ sprintf( glc_admin_t( 'xlsx_err_size' ), self::kb( $too_big * KB_IN_BYTES ), self::kb( $max ) ) ] ], self::APPLY ); ?>
				</div>
			<?php endif; ?>
			<?php if ( $plan ) : ?>
				<div id="glc-preview" class="glc-batch-preview">
					<h2><?php echo esc_html( glc_admin_t( 'batch_preview_heading' ) ); ?></h2>
					<?php self::render_cells( (array) ( $plan['cells'] ?? [] ) ); ?>
					<?php GLC_Partner_Batch::render_preview( $token, $plan, self::APPLY ); ?>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	/** Every cell of the file that differs from what is stored: where it is, what it is, the price now and the price in the file. */
	private static function render_cells( array $cells ): void {
		if ( ! $cells ) {
			return;
		}
		?>
		<table class="widefat striped glc-cells" data-cells="<?php echo count( $cells ); ?>">
			<caption><?php echo esc_html( sprintf( glc_admin_t( 'xlsx_cells_caption' ), count( $cells ) ) ); ?></caption>
			<thead>
				<tr>
					<th scope="col"><?php echo esc_html( glc_admin_t( 'xlsx_col_cell' ) ); ?></th>
					<th scope="col"><?php echo esc_html( glc_admin_t( 'col_car' ) ); ?></th>
					<th scope="col"><?php echo esc_html( glc_admin_t( 'history_what' ) ); ?></th>
					<th scope="col"><?php echo esc_html( glc_admin_t( 'samples_now' ) ); ?></th>
					<th scope="col"><?php echo esc_html( glc_admin_t( 'xlsx_col_file' ) ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $cells as $cell ) : ?>
					<tr>
						<th scope="row"><?php echo esc_html( (string) $cell['ref'] ); ?></th>
						<td><?php echo esc_html( GLC_Partner_Batch::car_name( (int) $cell['car_id'] ) ); ?></td>
						<td><?php echo esc_html( (string) $cell['what'] ); ?></td>
						<td><?php echo esc_html( (string) $cell['before'] ); ?></td>
						<td class="glc-differs"><?php echo esc_html( (string) $cell['after'] ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}
}
