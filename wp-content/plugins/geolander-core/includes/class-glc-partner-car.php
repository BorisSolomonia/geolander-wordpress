<?php
/**
 * One car's screen in the partner panel: seasons and prices, the car's own
 * delivery and early/late fees, its photos, its details, and its history with
 * a way back.
 *
 * The same screen serves a partner (for a car assigned to them) and the site's
 * owner (for every car). Nothing here writes to the car: every save, and every
 * restore, is handed to GLC_Price_Store, which checks it, steps the revision
 * and logs it. This class only reads the form, asks the store, and shows the
 * answer in the reader's language.
 *
 * Every handler runs the same three checks in the same order before it reads
 * anything else from the request: the panel capability, a nonce bound to the
 * action and the car, and can_manage() for that car. A request that fails any
 * of them gets one and the same refusal, which says nothing about the car.
 *
 * The season form posts its whole table as ONE field (`glc_table`, one line per
 * season). PHP reads at most max_input_vars fields and Apache refuses a body
 * over its limit on admin-post.php; with one field the count never grows with
 * the table, and the size is bounded by the calendar, not by a setting: a
 * table that could be saved has at most 365 seasons.
 *
 * Photos and details are saved without a preview: there is no price in them
 * to work out twice. They go through the same store, under the same lock, and
 * each save is in the history with a way back. The details form reads exactly
 * the fields GLC_Price_Store::SPEC_FIELDS names; anything else a request
 * carries (a plate, a year, a title) is not read at all.
 */

defined( 'ABSPATH' ) || exit;

class GLC_Partner_Car {

	public const PAGE = 'glc-partner-car';

	/** admin-post actions and the methods behind them. */
	private const ACTIONS = [
		'glc_partner_save_pricing' => 'save_pricing',
		'glc_partner_save_fees'    => 'save_fees',
		'glc_partner_save_photos'  => 'save_photos',
		'glc_partner_save_specs'   => 'save_specs',
		'glc_partner_preview'      => 'preview',
		'glc_partner_restore'      => 'restore',
	];

	/** The one field the season table travels in, and what separates its lines and cells. */
	public const TABLE_FIELD = 'glc_table';
	private const TABLE_ROW  = "\n";
	private const TABLE_CELL = '|';

	/** The photos form: the gallery's ids in page order (one field), the main photo, and an alt text per photo. */
	public const GALLERY_FIELD  = 'glc_gallery';
	private const GALLERY_JOIN  = ',';
	private const MAIN_FIELD    = 'glc_main';
	private const ALT_FIELD     = 'glc_alt';
	/** The details form: one array field, read key by key against the store's list. */
	private const SPEC_FIELD    = 'glc_spec';
	/** The size of a photo's picture in the gallery editor. */
	private const THUMB_SIZE    = 'thumbnail';

	public static function init(): void {
		add_action( 'admin_menu', [ __CLASS__, 'menu' ] );
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'assets' ] );
		foreach ( self::ACTIONS as $action => $method ) {
			add_action( 'admin_post_' . $action, [ __CLASS__, $method ] );
			add_action( 'admin_post_nopriv_' . $action, [ 'GLC_Partner_Panel', 'refuse' ] );
		}
	}

	/** A page with no menu entry: it is reached from the list of cars. */
	public static function menu(): void {
		$hook = add_submenu_page( '', glc_admin_t( 'car_page_title' ), glc_admin_t( 'car_page_title' ), GLC_Partners::CAP_PANEL, self::PAGE, [ __CLASS__, 'render' ] );
		// Asked before WordPress prints anything, so a refusal is a real 403 and not a message inside a 200 page.
		add_action( 'load-' . $hook, [ __CLASS__, 'guard' ] );
	}

	/** May this person open this car's screen (and, when asked, this restore)? */
	public static function guard(): void {
		$car_id  = self::requested_car();
		$restore = self::requested_restore();
		if ( ! current_user_can( GLC_Partners::CAP_PANEL )
			|| ! $car_id
			|| ! GLC_Partners::can_manage( get_current_user_id(), $car_id )
			|| ( $restore && ! self::restorable( $car_id, $restore ) ) ) {
			GLC_Partner_Panel::refuse();
		}
		// A page with no menu entry has no title of its own, and WordPress's header trips over the missing one.
		$GLOBALS['title'] = glc_admin_t( 'car_page_title' );
	}

	public static function url( int $car_id, array $args = [] ): string {
		return add_query_arg( [ 'page' => self::PAGE, 'car' => $car_id ] + $args, admin_url( 'admin.php' ) );
	}

	public static function assets( $hook ): void {
		if ( ! str_ends_with( (string) $hook, '_page_' . self::PAGE ) ) {
			return;
		}
		// An upload made from this screen says so, and is then stored as a car photo whoever makes it.
		add_filter( 'plupload_default_params', static fn( $params ) => (array) $params + [ GLC_Partner_Media::FLAG => '1' ] );
		wp_enqueue_media();
		self::enqueue();
	}

	/** The panel's stylesheet and script, with what the script needs to know. The bulk and Excel screens ask for the same. */
	public static function enqueue(): void {
		wp_enqueue_style( 'glc-partner', GLC_URL . 'assets/partner.css', [], GLC_VERSION );
		wp_enqueue_script( 'glc-partner', GLC_URL . 'assets/partner.js', [], GLC_VERSION, true );
		$limit  = GLC_Settings::get( 'season_max_count' );
		$months = [];
		for ( $month = 1; $month <= 12; $month++ ) {
			$months[] = glc_admin_t( 'month_' . $month );
		}
		wp_localize_script( 'glc-partner', 'glcPartner', [
			'endpoint'    => admin_url( 'admin-post.php' ),
			'preview'     => 'glc_partner_preview',
			'tableField'  => self::TABLE_FIELD,
			'carsField'   => GLC_Partner_Bulk::CARS_FIELD,
			'rowJoin'     => self::TABLE_ROW,
			'cellJoin'    => self::TABLE_CELL,
			'bands'       => GLC_Pricing::TIERS,
			'yearDays'    => GLC_Price_Store::year_days(),
			// A table cannot hold more seasons than the year has days, whatever the setting says.
			'maxSeasons'  => min( count( GLC_Price_Store::year_days() ), is_numeric( $limit ) ? (int) $limit : count( GLC_Price_Store::year_days() ) ),
			'months'      => $months,
			'dayMonth'    => glc_admin_t( 'day_month' ),
			'dateRange'   => glc_admin_t( 'date_range' ),
			'text'        => [
				'covered'      => glc_admin_t( 'strip_covered' ),
				'gap'          => glc_admin_t( 'strip_gap' ),
				'overlap'      => glc_admin_t( 'strip_overlap' ),
				'badDate'      => glc_admin_t( 'strip_bad_date' ),
				'tooMany'      => glc_admin_t( 'strip_too_many' ),
				'previewFirst' => glc_admin_t( 'preview_first' ),
				'save'         => glc_admin_t( 'save_button' ),
				'saveConfirm'  => glc_admin_t( 'save_confirm' ),
				'previewFail'  => glc_admin_t( 'preview_failed' ),
				'working'      => glc_admin_t( 'preview_working' ),
				'photoPick'    => glc_admin_t( 'photos_pick_title' ),
				'photoUse'     => glc_admin_t( 'photos_pick_use' ),
				'photoNarrow'  => glc_admin_t( 'photos_narrow' ),
				'photoSize'    => glc_admin_t( 'photos_size' ),
			],
			'galleryField' => self::GALLERY_FIELD,
			'galleryJoin'  => self::GALLERY_JOIN,
			'mainField'    => self::MAIN_FIELD,
			'altField'     => self::ALT_FIELD,
			'thumbSize'    => self::THUMB_SIZE,
			'minWidth'     => (int) GLC_Settings::whole( 'partner_image_min_width' ),
		] );
	}

	/* ------------------------------------------------------------ the checks */

	/** The car a request names, as a whole number; 0 for anything else (an array, a word, nothing). */
	private static function requested_car(): int {
		$raw = $_REQUEST['car'] ?? '';
		return is_string( $raw ) && ctype_digit( $raw ) ? (int) $raw : 0;
	}

	/** The change-log entry a request asks to restore, as a whole number; 0 for anything else. */
	private static function requested_restore(): int {
		$raw = $_GET['restore'] ?? '';
		return is_string( $raw ) && ctype_digit( $raw ) ? (int) $raw : 0;
	}

	/**
	 * Capability, then the nonce for this action and this car, then the car
	 * itself. Returns the car id, or ends the request with the one refusal.
	 */
	private static function allowed_car( string $action, string $suffix = '' ): int {
		$car_id = self::requested_car();
		$nonce  = isset( $_POST['_wpnonce'] ) && is_string( $_POST['_wpnonce'] ) ? sanitize_key( $_POST['_wpnonce'] ) : '';
		if ( ! current_user_can( GLC_Partners::CAP_PANEL )
			|| ! $car_id
			|| ! wp_verify_nonce( $nonce, $action . '_' . $car_id . $suffix )
			|| ! GLC_Partners::can_manage( get_current_user_id(), $car_id ) ) {
			GLC_Partner_Panel::refuse();
		}
		return $car_id;
	}

	/** The revision the form started from; null when the form did not say (the store then refuses). */
	private static function posted_rev(): ?int {
		$raw = $_POST['glc_rev'] ?? null;
		return is_string( $raw ) && ctype_digit( $raw ) ? (int) $raw : null;
	}

	/* ------------------------------------------------------ reading the forms */

	/**
	 * The posted season table as rows, exactly as typed: each line is
	 * from|to|seven prices. Only these nine cells are read; anything else in a
	 * line is dropped. Whether the rows make a table that can be saved is the
	 * store's question, not this function's.
	 */
	public static function posted_table(): array {
		$raw = $_POST[ self::TABLE_FIELD ] ?? '';
		if ( ! is_string( $raw ) || '' === trim( $raw ) ) {
			return [];
		}
		$rows = [];
		foreach ( explode( self::TABLE_ROW, str_replace( "\r", '', wp_unslash( $raw ) ) ) as $line ) {
			if ( '' === trim( $line ) ) {
				continue;
			}
			$cells = array_map( 'sanitize_text_field', explode( self::TABLE_CELL, $line ) );
			$rates = [];
			foreach ( GLC_Pricing::TIERS as $i => $band ) {
				$rates[ $band ] = $cells[ $i + 2 ] ?? '';
			}
			$rows[] = [ 'from' => $cells[0] ?? '', 'to' => $cells[1] ?? '', 'rates' => $rates ];
		}
		return $rows;
	}

	/** The posted fees as typed, for the places that carry a fee and the early/late fee: nothing else is read. */
	private static function posted_fees(): array {
		$raw  = isset( $_POST['glc_fee'] ) && is_array( $_POST['glc_fee'] ) ? wp_unslash( $_POST['glc_fee'] ) : [];
		$read = static fn( string $key ): string => isset( $raw[ $key ] ) && is_string( $raw[ $key ] ) ? sanitize_text_field( $raw[ $key ] ) : '';
		$fees = [ 'delivery' => [], GLC_Price_Store::OUT_OF_HOURS => $read( GLC_Price_Store::OUT_OF_HOURS ) ];
		foreach ( GLC_Rental::fee_place_keys() as $place ) {
			$fees['delivery'][ $place ] = $read( $place );
		}
		return $fees;
	}

	/**
	 * The posted photos as sent: the gallery's ids in order, the main photo and
	 * the alt texts. An id that is not a whole number is passed on as it came,
	 * so the store refuses the save and says so, instead of it being dropped here.
	 */
	private static function posted_photos(): array {
		$whole   = static fn( $raw ) => is_string( $raw ) && ctype_digit( $raw ) ? (int) $raw : ( is_string( $raw ) ? sanitize_text_field( wp_unslash( $raw ) ) : '' );
		$gallery = isset( $_POST[ self::GALLERY_FIELD ] ) && is_string( $_POST[ self::GALLERY_FIELD ] ) ? trim( $_POST[ self::GALLERY_FIELD ] ) : '';
		$alts    = [];
		foreach ( isset( $_POST[ self::ALT_FIELD ] ) && is_array( $_POST[ self::ALT_FIELD ] ) ? wp_unslash( $_POST[ self::ALT_FIELD ] ) : [] as $id => $alt ) {
			if ( ( is_int( $id ) || ctype_digit( (string) $id ) ) && is_string( $alt ) ) {
				$alts[ (int) $id ] = sanitize_text_field( $alt );
			}
		}
		return [
			'gallery' => '' === $gallery ? [] : array_map( $whole, explode( self::GALLERY_JOIN, $gallery ) ),
			'main'    => $whole( $_POST[ self::MAIN_FIELD ] ?? '' ),
			'alts'    => $alts,
		];
	}

	/**
	 * The posted details: exactly the fields the store lists, and no other. A
	 * ticked box is "yes", a box that is not there is "no".
	 */
	private static function posted_specs(): array {
		$raw   = isset( $_POST[ self::SPEC_FIELD ] ) && is_array( $_POST[ self::SPEC_FIELD ] ) ? wp_unslash( $_POST[ self::SPEC_FIELD ] ) : [];
		$specs = [];
		foreach ( GLC_Price_Store::SPEC_FIELDS as $key => $type ) {
			$typed         = isset( $raw[ $key ] ) && is_string( $raw[ $key ] ) ? sanitize_text_field( $raw[ $key ] ) : '';
			$specs[ $key ] = 'switch' === $type ? '' !== $typed && '0' !== $typed : $typed;
		}
		return $specs;
	}

	/** The section a preview is for and the change it describes, read from the same fields a save reads. */
	private static function posted_changes( string $section ): array {
		return 'fees' === $section ? [ 'fees' => self::posted_fees() ] : [ 'pricing' => self::posted_table() ];
	}

	/* -------------------------------------------------------------- handlers */

	public static function save_pricing(): void {
		$car_id = self::allowed_car( 'glc_partner_save_pricing' );
		self::save( $car_id, 'pricing', self::posted_table() );
	}

	public static function save_fees(): void {
		$car_id = self::allowed_car( 'glc_partner_save_fees' );
		self::save( $car_id, 'fees', self::posted_fees() );
	}

	public static function save_photos(): void {
		$car_id = self::allowed_car( 'glc_partner_save_photos' );
		self::save( $car_id, 'photos', self::posted_photos() );
	}

	public static function save_specs(): void {
		$car_id = self::allowed_car( 'glc_partner_save_specs' );
		self::save( $car_id, 'specs', self::posted_specs() );
	}

	/** Hand one section to the store and go back to the screen with its answer. */
	private static function save( int $car_id, string $kind, array $typed ): void {
		$before = GLC_Price_Store::read( $car_id, $kind );
		$result = GLC_Price_Store::write( $car_id, [ $kind => $typed ], [ 'source' => 'panel', 'rev' => self::posted_rev() ] );
		if ( is_wp_error( $result ) ) {
			self::flash( $car_id, [
				'section' => $kind,
				'ok'      => false,
				'lines'   => self::error_lines( $result, $car_id ),
				'seasons' => self::error_seasons( $result, $car_id ),
				'draft'   => $typed, // what was typed comes back on the screen, not what is stored
			] );
		} elseif ( in_array( $car_id, $result['unchanged'], true ) ) {
			self::flash( $car_id, [ 'section' => $kind, 'ok' => true, 'lines' => [ glc_admin_t( 'saved_unchanged' ) ] ] );
		} else {
			self::flash( $car_id, [
				'section' => $kind,
				'ok'      => true,
				'lines'   => array_merge( [ sprintf( glc_admin_t( 'saved' ), $result['cars'][ $car_id ]['rev'] ) ], self::describe( $kind, $before, GLC_Price_Store::read( $car_id, $kind ) ) ),
			] );
		}
		wp_safe_redirect( self::url( $car_id ) . '#glc-' . $kind, 303 );
		exit;
	}

	/**
	 * What a save would do, as a piece of HTML for the form to show. Writes
	 * nothing: the store plans the change, and the samples are priced with the
	 * car seen as if the change were saved.
	 */
	public static function preview(): void {
		$car_id  = self::allowed_car( 'glc_partner_preview' );
		$section = isset( $_POST['section'] ) && 'fees' === $_POST['section'] ? 'fees' : 'pricing';
		$plan    = GLC_Price_Store::plan(
			[ $car_id => [ 'changes' => self::posted_changes( $section ), 'rev' => self::posted_rev() ] ],
			[ 'source' => 'panel' ]
		);
		nocache_headers();
		header( 'Content-Type: text/html; charset=' . get_option( 'blog_charset' ) );
		if ( is_wp_error( $plan ) ) {
			self::render_outcome( false, [ $plan->get_error_message() ] );
			exit;
		}
		if ( $plan['errors'] ) {
			$refusal = new WP_Error( 'glc_store_refused', '', [ 'errors' => $plan['errors'] ] );
			self::render_outcome( false, self::error_lines( $refusal, $car_id ), self::error_seasons( $refusal, $car_id ) );
			exit;
		}
		$now    = GLC_Price_Store::state( $car_id );
		$change = $plan['cars'][ $car_id ]['changes'][ $section ] ?? null;
		if ( ! $change ) {
			self::render_outcome( true, [ glc_admin_t( 'preview_no_change' ) ] );
			exit;
		}
		$then = [ $section => $change['after'] ] + $now;
		self::render_outcome( true, self::describe( $section, $change['before'], $change['after'] ) );
		self::render_samples( $car_id, [ 'now' => $now, 'then' => $then ], glc_admin_t( 'samples_now' ), glc_admin_t( 'samples_then' ) );
		exit;
	}

	/** Put back the state an entry of the change log recorded as "before". */
	public static function restore(): void {
		$change_id = isset( $_POST['change'] ) && is_string( $_POST['change'] ) && ctype_digit( $_POST['change'] ) ? (int) $_POST['change'] : 0;
		$car_id    = self::allowed_car( 'glc_partner_restore', '_' . $change_id );
		$entry     = self::restorable( $car_id, $change_id );
		if ( ! $entry ) {
			GLC_Partner_Panel::refuse();
		}
		$kind   = $entry['kind'];
		$before = GLC_Price_Store::read( $car_id, $kind );
		$result = GLC_Change_Log::restore( $change_id, [ 'rev' => self::posted_rev() ] );
		if ( is_wp_error( $result ) ) {
			self::flash( $car_id, [ 'section' => 'history', 'ok' => false, 'lines' => self::error_lines( $result, $car_id ) ] );
		} elseif ( in_array( $car_id, $result['unchanged'], true ) ) {
			self::flash( $car_id, [ 'section' => 'history', 'ok' => true, 'lines' => [ glc_admin_t( 'restore_unchanged' ) ] ] );
		} else {
			self::flash( $car_id, [
				'section' => 'history',
				'ok'      => true,
				'lines'   => array_merge( [ sprintf( glc_admin_t( 'restored' ), $entry['time'], $result['cars'][ $car_id ]['rev'] ) ], self::describe( $kind, $before, GLC_Price_Store::read( $car_id, $kind ) ) ),
			] );
		}
		wp_safe_redirect( self::url( $car_id ) . '#glc-history', 303 );
		exit;
	}

	/** An entry of THIS car that the store can put back; null for anything else, another car's entry included. */
	private static function restorable( int $car_id, int $change_id ): ?array {
		$entry = $change_id ? GLC_Change_Log::get( $change_id ) : null;
		if ( ! $entry || $entry['car_id'] !== $car_id || ! in_array( $entry['kind'], GLC_Price_Store::KINDS, true ) || ! is_array( $entry['before'] ) ) {
			return null;
		}
		return $entry;
	}

	/* ----------------------------------------------- the answer to a request */

	/** The answer to a save of this car waits for the screen the person is sent back to. */
	private static function flash( int $car_id, array $flash ): void {
		GLC_Partner_Panel::flash( 'car_' . $car_id, $flash );
	}

	/** Every reason the store gave for this car, in the reader's language. */
	private static function error_lines( WP_Error $error, int $car_id ): array {
		$found = (array) ( $error->get_error_data()['errors'][ $car_id ] ?? [] );
		$lines = array_values( array_filter( array_column( $found, 'message' ) ) );
		return $lines ?: [ $error->get_error_message() ?: glc_admin_t( 'err_refused' ) ];
	}

	/** The rows of the season form those reasons point at (from 0), so the screen can mark them. */
	private static function error_seasons( WP_Error $error, int $car_id ): array {
		$rows = [];
		foreach ( (array) ( $error->get_error_data()['errors'][ $car_id ] ?? [] ) as $found ) {
			if ( isset( $found['season'] ) ) {
				$rows[] = (int) $found['season'];
			}
			foreach ( 'err_overlap' === ( $found['code'] ?? '' ) ? (array) ( $found['seasons'] ?? [] ) : [] as $row ) {
				$rows[] = (int) $row;
			}
		}
		return array_values( array_unique( $rows ) );
	}

	/* ---------------------------------------------------- words for a change */

	public static function money( $amount ): string {
		return GLC_Format::money_exact( (float) $amount, GLC_Admin_Strings::lang() );
	}

	/** "13 Jul – 30 Aug" in the reader's language. */
	public static function season_text( array $season ): string {
		return sprintf( glc_admin_t( 'date_range' ), GLC_Admin_Strings::day( (string) $season['from'] ), GLC_Admin_Strings::day( (string) $season['to'] ) );
	}

	/** "1-2 days". */
	public static function band_text( string $band ): string {
		return sprintf( glc_admin_t( 'band_days' ), GLC_Pricing::TIER_LABELS[ $band ] ?? $band );
	}

	/** A car's own fee as words: the amount, "free", or the site standard it falls back to. */
	public static function fee_text( string $place, $own ): string {
		if ( is_numeric( $own ) ) {
			return (float) $own > 0 ? self::money( $own ) : glc_admin_t( 'fee_free' );
		}
		return sprintf( glc_admin_t( 'fee_standard' ), self::standard_text( $place ) );
	}

	/** The site's own amount for a place or for the early/late fee: an amount, "free", or "not decided". Never a zero for nothing. */
	public static function standard_text( string $place ): string {
		$setting = GLC_Price_Store::OUT_OF_HOURS === $place ? 'out_of_hours_fee' : GLC_Rental::fee_setting_key( $place );
		$amount  = $setting ? GLC_Settings::get( $setting ) : '';
		if ( ! is_numeric( $amount ) || (float) $amount < 0 ) {
			return glc_admin_t( 'fee_undecided' );
		}
		return (float) $amount > 0 ? self::money( $amount ) : glc_admin_t( 'fee_free' );
	}

	/** A car's fees as one flat list: [ place or 'out_of_hours' => stored amount, '' when it has none ]. */
	public static function flat_fees( array $fees ): array {
		$flat = [];
		foreach ( GLC_Rental::fee_place_keys() as $place ) {
			$flat[ $place ] = (string) ( $fees['delivery'][ $place ] ?? '' );
		}
		$flat[ GLC_Price_Store::OUT_OF_HOURS ] = (string) ( $fees[ GLC_Price_Store::OUT_OF_HOURS ] ?? '' );
		return $flat;
	}

	/** [ lowest, highest ] price in a table; null when it has none. */
	private static function price_range( array $seasons ): ?array {
		$all = [];
		foreach ( $seasons as $season ) {
			foreach ( (array) ( $season['rates'] ?? [] ) as $rate ) {
				if ( is_numeric( $rate ) ) {
					$all[] = (float) $rate;
				}
			}
		}
		return $all ? [ min( $all ), max( $all ) ] : null;
	}

	/** A table's seasons by their dates, so two tables can be compared season by season. */
	private static function by_dates( array $seasons ): array {
		$out = [];
		foreach ( $seasons as $season ) {
			$out[ ( $season['from'] ?? '' ) . '>' . ( $season['to'] ?? '' ) ] = (array) $season;
		}
		return $out;
	}

	/**
	 * What differs between two states of one kind, one line per difference, for
	 * a preview, a "saved" notice and a restore. Seasons are matched by their
	 * dates: the same dates with another price is a changed price; other dates
	 * are a season removed and a season added.
	 */
	public static function describe( string $kind, array $before, array $after ): array {
		$lines = [];
		if ( 'photos' === $kind ) {
			$was     = array_map( 'intval', (array) ( $before['gallery'] ?? [] ) );
			$now     = array_map( 'intval', (array) ( $after['gallery'] ?? [] ) );
			$added   = count( array_diff( $now, $was ) );
			$removed = count( array_diff( $was, $now ) );
			if ( $added ) {
				$lines[] = sprintf( glc_admin_t( 'change_photos_added' ), $added );
			}
			if ( $removed ) {
				$lines[] = sprintf( glc_admin_t( 'change_photos_removed' ), $removed );
			}
			// The photos both states have, in each one's order: a difference there is a change of order.
			if ( array_values( array_intersect( $was, $now ) ) !== array_values( array_intersect( $now, $was ) ) ) {
				$lines[] = glc_admin_t( 'change_photos_order' );
			}
			if ( (int) ( $before['main'] ?? 0 ) !== (int) ( $after['main'] ?? 0 ) ) {
				$lines[] = glc_admin_t( (int) ( $after['main'] ?? 0 ) ? 'change_photos_main' : 'change_photos_main_none' );
			}
			foreach ( (array) ( $after['alts'] ?? [] ) as $id => $alt ) {
				if ( isset( $before['alts'][ $id ] ) && (string) $before['alts'][ $id ] !== (string) $alt ) {
					$lines[] = sprintf( glc_admin_t( 'change_photos_alt' ), '' !== (string) $before['alts'][ $id ] ? (string) $before['alts'][ $id ] : glc_admin_t( 'not_set' ), (string) $alt );
				}
			}
			return $lines;
		}
		if ( 'specs' === $kind ) {
			foreach ( array_keys( GLC_Price_Store::SPEC_FIELDS ) as $key ) {
				$was = self::spec_text( $key, $before[ $key ] ?? '' );
				$now = self::spec_text( $key, $after[ $key ] ?? '' );
				if ( $was !== $now ) {
					$lines[] = sprintf( glc_admin_t( 'change_spec' ), glc_admin_t( GLC_Price_Store::spec_label_key( $key ) ), $was, $now );
				}
			}
			return $lines;
		}
		if ( 'fees' === $kind ) {
			$was = self::flat_fees( $before );
			foreach ( self::flat_fees( $after ) as $place => $amount ) {
				if ( $amount !== $was[ $place ] ) {
					$lines[] = sprintf( glc_admin_t( 'change_fee' ), glc_admin_t( 'place_' . $place ), self::fee_text( $place, $was[ $place ] ), self::fee_text( $place, $amount ) );
				}
			}
			return $lines;
		}
		$was = self::by_dates( $before );
		$now = self::by_dates( $after );
		if ( count( $before ) !== count( $after ) ) {
			$lines[] = sprintf( glc_admin_t( 'change_season_count' ), count( $before ), count( $after ) );
		}
		foreach ( array_diff_key( $was, $now ) as $season ) {
			$lines[] = sprintf( glc_admin_t( 'change_season_removed' ), self::season_text( $season ) );
		}
		foreach ( $now as $dates => $season ) {
			if ( ! isset( $was[ $dates ] ) ) {
				$lines[] = sprintf( glc_admin_t( 'change_season_added' ), self::season_text( $season ), implode( glc_admin_t( 'price_join' ), array_map( [ __CLASS__, 'money' ], array_values( (array) ( $season['rates'] ?? [] ) ) ) ) );
				continue;
			}
			foreach ( GLC_Pricing::TIERS as $band ) {
				$from = $was[ $dates ]['rates'][ $band ] ?? '';
				$to   = $season['rates'][ $band ] ?? '';
				if ( (string) $from !== (string) $to ) {
					$lines[] = sprintf( glc_admin_t( 'change_price' ), self::season_text( $season ), self::band_text( $band ), is_numeric( $from ) ? self::money( $from ) : glc_admin_t( 'not_set' ), is_numeric( $to ) ? self::money( $to ) : glc_admin_t( 'not_set' ) );
				}
			}
		}
		$low_high_before = self::price_range( $before );
		$low_high_after  = self::price_range( $after );
		if ( $low_high_before && $low_high_after && $low_high_before !== $low_high_after ) {
			$lines[] = sprintf( glc_admin_t( 'change_range' ), self::money( $low_high_before[0] ), self::money( $low_high_before[1] ), self::money( $low_high_after[0] ), self::money( $low_high_after[1] ) );
		}
		if ( ! $lines && $before !== $after ) {
			$lines[] = glc_admin_t( 'change_order' ); // same seasons and prices, listed in another order
		}
		return $lines;
	}

	/** A detail as a person reads it: "yes" and "no", a gearbox or a fuel by its name, a dash for nothing. */
	public static function spec_text( string $key, $value ): string {
		$type = GLC_Price_Store::SPEC_FIELDS[ $key ] ?? '';
		if ( 'switch' === $type ) {
			return glc_admin_t( $value ? 'spec_yes' : 'spec_no' );
		}
		if ( 'choice' === $type ) {
			return '' === (string) $value ? glc_admin_t( 'not_set' ) : self::choice_text( (string) $value );
		}
		return '' === (string) $value || ( 'seats' === $type && ! (int) $value ) ? glc_admin_t( 'not_set' ) : (string) $value;
	}

	/** A gearbox or a fuel by its name; a word added in the settings that has no name yet is shown as it is written. */
	private static function choice_text( string $word ): string {
		return GLC_Admin_Strings::has( 'spec_choice_' . $word ) ? glc_admin_t( 'spec_choice_' . $word ) : $word;
	}

	/** How many prices differ between two tables, counting every price of a season that only one of them has. */
	private static function prices_changed( array $before, array $after ): int {
		$was   = self::by_dates( $before );
		$now   = self::by_dates( $after );
		$count = count( GLC_Pricing::TIERS ) * ( count( array_diff_key( $was, $now ) ) + count( array_diff_key( $now, $was ) ) );
		foreach ( array_intersect_key( $now, $was ) as $dates => $season ) {
			foreach ( GLC_Pricing::TIERS as $band ) {
				$count += (string) ( $was[ $dates ]['rates'][ $band ] ?? '' ) !== (string) ( $season['rates'][ $band ] ?? '' ) ? 1 : 0;
			}
		}
		return $count;
	}

	/** One short line for the history list: how much changed, not every cell. */
	private static function summarise( array $entry ): string {
		$before = (array) $entry['before'];
		$after  = (array) $entry['after'];
		if ( 'assignment' === $entry['kind'] ) {
			$who = static function ( $id ): string {
				return (int) $id ? ( GLC_Partners::name( get_userdata( (int) $id ) ) ?: sprintf( glc_admin_t( 'log_actor_unknown' ), (int) $id ) ) : glc_admin_t( 'partner_none' );
			};
			return sprintf( glc_admin_t( 'summary_assignment' ), $who( $before['partner'] ?? 0 ), $who( $after['partner'] ?? 0 ) );
		}
		if ( in_array( $entry['kind'], [ 'fees', 'photos', 'specs' ], true ) ) {
			return implode( glc_admin_t( 'summary_join' ), self::describe( $entry['kind'], $before, $after ) );
		}
		return sprintf( glc_admin_t( 'summary_pricing' ), count( $before ), count( $after ), self::prices_changed( $before, $after ) );
	}

	/* ------------------------------------------------------------- rendering */

	/** The outcome of a preview: a list of reasons it cannot be saved, or of what will change. */
	private static function render_outcome( bool $ok, array $lines, array $seasons = [] ): void {
		?>
		<div class="glc-outcome <?php echo $ok ? 'glc-outcome--ok' : 'glc-outcome--refused'; ?>" data-ok="<?php echo $ok ? '1' : '0'; ?>" data-seasons="<?php echo esc_attr( implode( ',', $seasons ) ); ?>">
			<p><strong><?php echo esc_html( glc_admin_t( $ok ? 'preview_changes' : 'preview_refused' ) ); ?></strong></p>
			<ul>
				<?php foreach ( $lines as $line ) : ?>
					<li><?php echo esc_html( $line ); ?></li>
				<?php endforeach; ?>
			</ul>
		</div>
		<?php
	}

	/** A sample rental in words: dates, length, and the handovers when they are not the plain office ones. */
	private static function sample_text( array $sample ): string {
		$text = sprintf( glc_admin_t( 'sample_dates' ), $sample['from'], $sample['to'], $sample['days'] );
		if ( GLC_Rental::DEFAULT_PICKUP !== $sample['pickup'] || GLC_Rental::DEFAULT_RETURN !== $sample['return'] || $sample['pickup_time'] || $sample['return_time'] ) {
			$text .= ' · ' . sprintf(
				glc_admin_t( 'sample_handover' ),
				glc_admin_t( 'place_' . $sample['pickup'] ),
				$sample['pickup_time'] ?? GLC_Settings::get( 'handover_default_time' ),
				glc_admin_t( 'place_' . $sample['return'] ),
				$sample['return_time'] ?? GLC_Settings::get( 'handover_default_time' )
			);
		}
		return $text;
	}

	/**
	 * The sample quotes for two states side by side. Each figure is printed
	 * twice, the engine's and the separate recompute; a pair that differs is
	 * marked as a fault and both numbers stay on the screen.
	 *
	 * @param array $states exactly two: [ name => state ], the first is "before"
	 */
	public static function render_samples( int $car_id, array $states, string $first_label, string $second_label ): void {
		$rows   = GLC_Quote_Check::rows( $car_id, $states );
		$names  = array_keys( $states );
		$labels = array_combine( $names, [ $first_label, $second_label ] );
		$figure = static fn( ?float $total ): string => null === $total ? glc_admin_t( 'sample_no_quote' ) : self::money( $total );
		if ( ! $rows ) {
			echo '<p>' . esc_html( glc_admin_t( 'samples_none' ) ) . '</p>';
			return;
		}
		$faults = 0;
		?>
		<table class="widefat striped glc-samples">
			<caption><?php echo esc_html( glc_admin_t( 'samples_caption' ) ); ?></caption>
			<thead>
				<tr>
					<th scope="col" rowspan="2"><?php echo esc_html( glc_admin_t( 'samples_rental' ) ); ?></th>
					<?php foreach ( $labels as $label ) : ?>
						<th scope="colgroup" colspan="2"><?php echo esc_html( $label ); ?></th>
					<?php endforeach; ?>
				</tr>
				<tr>
					<?php foreach ( $labels as $label ) : ?>
						<th scope="col"><?php echo esc_html( glc_admin_t( 'samples_engine' ) ); ?></th>
						<th scope="col"><?php echo esc_html( glc_admin_t( 'samples_check' ) ); ?></th>
					<?php endforeach; ?>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $rows as $row ) : ?>
					<tr>
						<th scope="row"><?php echo esc_html( self::sample_text( $row['sample'] ) ); ?></th>
						<?php foreach ( $names as $name ) : ?>
							<?php $faults += $row[ $name ]['agree'] ? 0 : 1; ?>
							<td class="<?php echo $row[ $name ]['agree'] ? '' : 'glc-fault'; ?>"><?php echo esc_html( $figure( $row[ $name ]['engine'] ) ); ?></td>
							<td class="<?php echo $row[ $name ]['agree'] ? '' : 'glc-fault'; ?>"><?php echo esc_html( $figure( $row[ $name ]['check'] ) ); ?></td>
						<?php endforeach; ?>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<p class="<?php echo $faults ? 'glc-fault' : 'description'; ?>" data-faults="<?php echo (int) $faults; ?>"><?php echo esc_html( $faults ? sprintf( glc_admin_t( 'samples_fault' ), $faults ) : glc_admin_t( 'samples_agree' ) ); ?></p>
		<?php
	}

	public static function render(): void {
		self::guard(); // already asked before the page began; asked again so this function is safe on its own
		$car_id  = self::requested_car();
		$restore = self::requested_restore();
		if ( $restore ) {
			self::render_restore( $car_id, self::restorable( $car_id, $restore ) );
			return;
		}

		$flash   = GLC_Partner_Panel::take_flash( 'car_' . $car_id );
		$state   = GLC_Price_Store::state( $car_id );
		$rev     = GLC_Price_Store::rev( $car_id );
		$last    = GLC_Change_Log::latest( $car_id, GLC_Price_Store::KINDS );
		$owner   = current_user_can( GLC_Partners::CAP_MANAGE );
		$partner = get_userdata( GLC_Partners::partner_of( $car_id ) );
		$plate   = trim( (string) get_post_meta( $car_id, 'glc_registration', true ) );
		$year    = (int) get_post_meta( $car_id, 'glc_year', true );
		$blank   = glc_admin_t( 'not_set' );
		$section = (string) ( $flash['section'] ?? '' );
		// After a refused save the form shows what was typed; otherwise what is stored.
		$seasons = 'pricing' === $section && isset( $flash['draft'] ) ? (array) $flash['draft'] : $state['pricing'];
		$fees    = 'fees' === $section && isset( $flash['draft'] ) ? (array) $flash['draft'] : $state['fees'];
		$photos  = GLC_Price_Store::read( $car_id, 'photos' );
		$specs   = GLC_Price_Store::read( $car_id, 'specs' );
		$marked  = 'pricing' === $section ? (array) ( $flash['seasons'] ?? [] ) : [];
		?>
		<div class="wrap glc-panel" lang="<?php echo esc_attr( GLC_Admin_Strings::lang() ); ?>">
			<p><a href="<?php echo esc_url( GLC_Partner_Panel::url() ); ?>">&larr; <?php echo esc_html( glc_admin_t( 'car_back' ) ); ?></a></p>
			<h1><?php echo esc_html( get_the_title( $car_id ) ); ?></h1>
			<ul class="glc-facts">
				<li><?php echo esc_html( glc_admin_t( 'col_plate' ) . ': ' . ( '' !== $plate ? $plate : $blank ) ); ?></li>
				<li><?php echo esc_html( glc_admin_t( 'col_year' ) . ': ' . ( $year > 0 ? (string) $year : $blank ) ); ?></li>
				<?php if ( $owner ) : ?>
					<li><?php echo esc_html( glc_admin_t( 'car_partner' ) . ': ' . ( $partner ? GLC_Partners::name( $partner ) : glc_admin_t( 'partner_none' ) ) ); ?></li>
				<?php endif; ?>
				<li><?php echo esc_html( sprintf( glc_admin_t( 'car_rev' ), $rev ) ); ?></li>
				<li><?php echo esc_html( $last ? sprintf( glc_admin_t( 'car_last_change' ), $last['actor_name'], $last['time'] ) : glc_admin_t( 'car_never_changed' ) ); ?></li>
				<?php if ( 'publish' === get_post_status( $car_id ) ) : ?>
					<li><a href="<?php echo esc_url( (string) get_permalink( $car_id ) ); ?>"><?php echo esc_html( glc_admin_t( 'car_public_page' ) ); ?></a></li>
				<?php endif; ?>
			</ul>
			<noscript><div class="notice notice-warning inline"><p><?php echo esc_html( glc_admin_t( 'car_needs_js' ) ); ?></p></div></noscript>

			<h2 id="glc-pricing"><?php echo esc_html( glc_admin_t( 'pricing_heading' ) ); ?></h2>
			<?php self::render_flash( $flash, 'pricing' ); ?>
			<p class="description"><?php echo esc_html( glc_admin_t( 'pricing_help' ) ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="glc-form glc-form--pricing" data-section="pricing" data-preview-nonce="<?php echo esc_attr( wp_create_nonce( 'glc_partner_preview_' . $car_id ) ); ?>">
				<input type="hidden" name="action" value="glc_partner_save_pricing" />
				<input type="hidden" name="car" value="<?php echo (int) $car_id; ?>" />
				<input type="hidden" name="glc_rev" value="<?php echo (int) $rev; ?>" />
				<input type="hidden" name="<?php echo esc_attr( self::TABLE_FIELD ); ?>" value="" />
				<?php wp_nonce_field( 'glc_partner_save_pricing_' . $car_id, '_wpnonce', false ); ?>
				<?php self::render_season_editor( $seasons, $marked ); ?>
				<p>
					<button type="button" class="button glc-preview-button"><?php echo esc_html( glc_admin_t( 'preview_button' ) ); ?></button>
					<button type="submit" class="button button-primary glc-save-button"><?php echo esc_html( glc_admin_t( 'save_button' ) ); ?></button>
					<span class="description glc-save-hint"><?php echo esc_html( glc_admin_t( 'preview_first' ) ); ?></span>
				</p>
				<div class="glc-preview-out" aria-live="polite"></div>
			</form>

			<h2 id="glc-fees"><?php echo esc_html( glc_admin_t( 'fees_heading' ) ); ?></h2>
			<?php self::render_flash( $flash, 'fees' ); ?>
			<p class="description"><?php echo esc_html( glc_admin_t( 'fees_help' ) ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="glc-form glc-form--fees" data-section="fees" data-preview-nonce="<?php echo esc_attr( wp_create_nonce( 'glc_partner_preview_' . $car_id ) ); ?>">
				<input type="hidden" name="action" value="glc_partner_save_fees" />
				<input type="hidden" name="car" value="<?php echo (int) $car_id; ?>" />
				<input type="hidden" name="glc_rev" value="<?php echo (int) $rev; ?>" />
				<?php wp_nonce_field( 'glc_partner_save_fees_' . $car_id, '_wpnonce', false ); ?>
				<table class="widefat striped glc-fees">
					<thead>
						<tr>
							<th scope="col"><?php echo esc_html( glc_admin_t( 'fees_col_what' ) ); ?></th>
							<th scope="col"><?php echo esc_html( glc_admin_t( 'fees_col_own' ) ); ?></th>
							<th scope="col"><?php echo esc_html( glc_admin_t( 'fees_col_standard' ) ); ?></th>
							<th scope="col"><?php echo esc_html( glc_admin_t( 'fees_col_now' ) ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( self::flat_fees( $fees ) as $place => $typed ) : ?>
							<tr>
								<th scope="row">
									<label for="glc-fee-<?php echo esc_attr( $place ); ?>"><?php echo esc_html( glc_admin_t( 'place_' . $place ) ); ?></label>
									<?php if ( GLC_Price_Store::OUT_OF_HOURS === $place ) : ?>
										<br /><span class="description"><?php echo esc_html( self::window_text() ); ?></span>
									<?php endif; ?>
								</th>
								<td><input type="number" step="0.01" min="0" inputmode="decimal" class="small-text glc-fee" id="glc-fee-<?php echo esc_attr( $place ); ?>" name="glc_fee[<?php echo esc_attr( $place ); ?>]" value="<?php echo esc_attr( $typed ); ?>" /></td>
								<td><?php echo esc_html( self::standard_text( $place ) ); ?></td>
								<td><?php echo esc_html( self::fee_text( $place, self::flat_fees( $state['fees'] )[ $place ] ) ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				<p>
					<button type="button" class="button glc-preview-button"><?php echo esc_html( glc_admin_t( 'preview_button' ) ); ?></button>
					<button type="submit" class="button button-primary glc-save-button"><?php echo esc_html( glc_admin_t( 'save_button' ) ); ?></button>
					<span class="description glc-save-hint"><?php echo esc_html( glc_admin_t( 'preview_first' ) ); ?></span>
				</p>
				<div class="glc-preview-out" aria-live="polite"></div>
			</form>

			<h2 id="glc-photos"><?php echo esc_html( glc_admin_t( 'photos_heading' ) ); ?></h2>
			<?php self::render_flash( $flash, 'photos' ); ?>
			<?php self::render_photos( $car_id, $rev, $photos, 'photos' === $section && isset( $flash['draft'] ) ? (array) $flash['draft'] : null ); ?>

			<h2 id="glc-specs"><?php echo esc_html( glc_admin_t( 'specs_heading' ) ); ?></h2>
			<?php self::render_flash( $flash, 'specs' ); ?>
			<?php self::render_specs( $car_id, $rev, 'specs' === $section && isset( $flash['draft'] ) ? (array) $flash['draft'] + $specs : $specs ); ?>

			<h2 id="glc-history"><?php echo esc_html( glc_admin_t( 'history_heading' ) ); ?></h2>
			<?php self::render_flash( $flash, 'history' ); ?>
			<?php self::render_history( $car_id, $owner ); ?>
		</div>
		<?php
	}

	/**
	 * The season table as a form edits it: a row per season, "add a season", and
	 * the strip of the year's days. It goes inside a form that carries the hidden
	 * field self::TABLE_FIELD; the script packs the rows into that field.
	 *
	 * @param array $seasons the rows to start from (stored, or what was typed)
	 * @param int[] $marked  rows a refusal pointed at (from 0)
	 */
	public static function render_season_editor( array $seasons, array $marked = [] ): void {
		?>
		<table class="widefat glc-seasons">
			<thead>
				<tr>
					<th scope="col"><?php echo esc_html( glc_admin_t( 'season_from' ) ); ?></th>
					<th scope="col"><?php echo esc_html( glc_admin_t( 'season_to' ) ); ?></th>
					<?php foreach ( GLC_Pricing::TIERS as $band ) : ?>
						<th scope="col"><?php echo esc_html( self::band_text( $band ) ); ?></th>
					<?php endforeach; ?>
					<td></td>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( array_values( $seasons ) as $i => $season ) : ?>
					<?php self::render_season_row( (array) $season, in_array( $i, $marked, true ) ); ?>
				<?php endforeach; ?>
			</tbody>
		</table>
		<template class="glc-season-template"><?php self::render_season_row( [], false ); ?></template>
		<p>
			<button type="button" class="button glc-add-season"><?php echo esc_html( glc_admin_t( 'season_add' ) ); ?></button>
			<span class="description"><?php echo esc_html( glc_admin_t( 'pricing_unit' ) ); ?></span>
		</p>
		<div class="glc-strip" aria-hidden="true"></div>
		<p class="glc-strip-text" role="status"></p>
		<?php
	}

	/** "10:00–20:00, set by Geolander": the hours with no extra charge, which a car cannot change. */
	public static function window_text(): string {
		$window = GLC_Rental::handover_window();
		return $window ? sprintf( glc_admin_t( 'fees_window' ), $window[0], $window[1] ) : glc_admin_t( 'fees_window_unset' );
	}

	/** The answer to the last save, shown above the section it was about. */
	private static function render_flash( array $flash, string $section ): void {
		if ( ( $flash['section'] ?? '' ) === $section ) {
			GLC_Partner_Panel::render_flash( $flash );
		}
	}

	/** One season of the form. The inputs carry no `name`: the script packs the table into one field. */
	private static function render_season_row( array $season, bool $marked ): void {
		$part = static fn( string $mm_dd, int $at ): int => preg_match( '/^(\d{1,2})-(\d{1,2})$/', $mm_dd, $m ) ? (int) $m[ $at ] : 0;
		?>
		<tr class="glc-season<?php echo $marked ? ' glc-season--marked' : ''; ?>">
			<?php foreach ( [ 'from', 'to' ] as $end ) : ?>
				<td>
					<select class="glc-day" data-end="<?php echo esc_attr( $end ); ?>" aria-label="<?php echo esc_attr( glc_admin_t( 'season_' . $end ) . ' · ' . glc_admin_t( 'season_day' ) ); ?>">
						<?php for ( $day = 1; $day <= 31; $day++ ) : ?>
							<option value="<?php echo (int) $day; ?>"<?php selected( $part( (string) ( $season[ $end ] ?? '' ), 2 ), $day ); ?>><?php echo (int) $day; ?></option>
						<?php endfor; ?>
					</select>
					<select class="glc-month" data-end="<?php echo esc_attr( $end ); ?>" aria-label="<?php echo esc_attr( glc_admin_t( 'season_' . $end ) . ' · ' . glc_admin_t( 'season_month' ) ); ?>">
						<?php for ( $month = 1; $month <= 12; $month++ ) : ?>
							<option value="<?php echo (int) $month; ?>"<?php selected( $part( (string) ( $season[ $end ] ?? '' ), 1 ), $month ); ?>><?php echo esc_html( glc_admin_t( 'month_' . $month ) ); ?></option>
						<?php endfor; ?>
					</select>
				</td>
			<?php endforeach; ?>
			<?php foreach ( GLC_Pricing::TIERS as $band ) : ?>
				<td><input type="number" step="0.01" min="0" inputmode="decimal" class="glc-rate" data-band="<?php echo esc_attr( $band ); ?>" value="<?php echo esc_attr( (string) ( $season['rates'][ $band ] ?? '' ) ); ?>" aria-label="<?php echo esc_attr( self::band_text( $band ) ); ?>" /></td>
			<?php endforeach; ?>
			<td><button type="button" class="button-link button-link-delete glc-remove-season"><?php echo esc_html( glc_admin_t( 'season_remove' ) ); ?></button></td>
		</tr>
		<?php
	}

	/**
	 * The photos form: the gallery in page order, each photo with its alt text,
	 * the choice of the main photo, the buttons that move it or take it out, and
	 * "Add photos", which opens WordPress's own picker (a partner finds only
	 * their own uploads there). The page works on the ids in one hidden field.
	 *
	 * @param array      $photos the stored state
	 * @param array|null $draft  what was sent, after a refused save
	 */
	private static function render_photos( int $car_id, int $rev, array $photos, ?array $draft ): void {
		$shown = $photos;
		if ( null !== $draft ) {
			// What was sent comes back, except a photo this person may not use: of that one nothing is shown.
			$ids   = array_values( array_filter( array_map( 'intval', array_filter( (array) ( $draft['gallery'] ?? [] ), 'is_int' ) ), static fn( int $id ): bool => GLC_Price_Store::photo_open( $car_id, $id, get_current_user_id() ) ) );
			$shown = [ 'gallery' => $ids, 'main' => is_int( $draft['main'] ?? null ) ? $draft['main'] : 0, 'alts' => array_map( 'strval', array_intersect_key( (array) ( $draft['alts'] ?? [] ), array_flip( $ids ) ) ) + $photos['alts'] ];
		}
		$longest = GLC_Settings::whole( 'photo_alt_max_chars' );
		?>
		<p class="description"><?php echo esc_html( glc_admin_t( 'photos_help' ) ); ?></p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="glc-photos-form">
			<input type="hidden" name="action" value="glc_partner_save_photos" />
			<input type="hidden" name="car" value="<?php echo (int) $car_id; ?>" />
			<input type="hidden" name="glc_rev" value="<?php echo (int) $rev; ?>" />
			<input type="hidden" name="<?php echo esc_attr( self::GALLERY_FIELD ); ?>" value="<?php echo esc_attr( implode( self::GALLERY_JOIN, $shown['gallery'] ) ); ?>" />
			<?php wp_nonce_field( 'glc_partner_save_photos_' . $car_id, '_wpnonce', false ); ?>
			<ol class="glc-photos">
				<?php foreach ( $shown['gallery'] as $at => $id ) : ?>
					<?php self::render_photo( $id, $at + 1, $id === $shown['main'], (string) ( $shown['alts'][ $id ] ?? '' ), (string) get_post_field( 'post_title', $car_id ), $longest ); ?>
				<?php endforeach; ?>
			</ol>
			<template class="glc-photo-template"><?php self::render_photo( 0, 0, false, '', '', $longest ); ?></template>
			<p class="glc-photos-empty"<?php echo $shown['gallery'] ? ' hidden' : ''; ?>><?php echo esc_html( glc_admin_t( 'photos_empty' ) ); ?></p>
			<p>
				<button type="button" class="button glc-photo-add"><?php echo esc_html( glc_admin_t( 'photos_add' ) ); ?></button>
				<button type="submit" class="button button-primary"><?php echo esc_html( glc_admin_t( 'save_button' ) ); ?></button>
			</p>
			<p class="description"><?php echo esc_html( self::photo_rules() ); ?></p>
		</form>
		<?php
	}

	/** What a photo has to be, in one sentence, with the numbers from the settings. */
	private static function photo_rules(): string {
		$megabytes = GLC_Settings::get( 'partner_upload_max_mb' );
		$narrowest = (int) GLC_Settings::whole( 'partner_image_min_width' );
		$widest    = GLC_Partner_Media::max_width();
		$parts     = [ sprintf( glc_admin_t( 'photos_rule_kinds' ), implode( glc_admin_t( 'price_join' ), GLC_Settings::words( 'partner_image_types' ) ) ) ];
		if ( is_numeric( $megabytes ) && GLC_Partners::is_partner( wp_get_current_user() ) ) {
			$parts[] = sprintf( glc_admin_t( 'photos_rule_size' ), $megabytes );
		}
		if ( $narrowest > 0 ) {
			$parts[] = sprintf( glc_admin_t( 'photos_rule_width' ), $narrowest );
		}
		if ( $widest > 0 ) {
			$parts[] = sprintf( glc_admin_t( 'photos_rule_stored' ), $widest );
		}
		return implode( ' ', $parts );
	}

	/**
	 * One photo of the gallery editor. With id 0 it is the blank the script
	 * copies for a photo that has just been picked.
	 */
	private static function render_photo( int $id, int $place, bool $main, string $alt, string $car_title, ?int $longest ): void {
		$picture = $id ? wp_get_attachment_image_src( $id, self::THUMB_SIZE ) : null;
		$full    = $id ? wp_get_attachment_metadata( $id ) : [];
		$default = $id ? sprintf( glc_admin_t( 'photo_alt_default', GLC_Admin_Strings::DEFAULT_LANG ), $car_title, $place ) : '';
		?>
		<li class="glc-photo" data-id="<?php echo (int) $id; ?>">
			<img class="glc-photo-picture" src="<?php echo esc_url( $picture[0] ?? '' ); ?>" alt="" loading="lazy" />
			<div class="glc-photo-fields">
				<label><input type="radio" name="<?php echo esc_attr( self::MAIN_FIELD ); ?>" value="<?php echo (int) $id; ?>"<?php checked( $main ); ?> /> <?php echo esc_html( glc_admin_t( 'photos_main' ) ); ?></label>
				<label class="glc-photo-alt">
					<span><?php echo esc_html( glc_admin_t( 'photos_alt' ) ); ?></span>
					<input type="text" class="regular-text" name="<?php echo esc_attr( $id ? self::ALT_FIELD . '[' . $id . ']' : '' ); ?>" value="<?php echo esc_attr( '' !== $alt ? $alt : $default ); ?>"<?php echo null !== $longest ? ' maxlength="' . (int) $longest . '"' : ''; ?> />
				</label>
				<span class="description glc-photo-size"><?php echo esc_html( ! empty( $full['width'] ) ? sprintf( glc_admin_t( 'photos_size' ), (int) $full['width'], (int) ( $full['height'] ?? 0 ) ) : '' ); ?></span>
				<span class="glc-fault glc-photo-narrow" hidden></span>
			</div>
			<div class="glc-photo-moves">
				<button type="button" class="button button-small glc-photo-up" aria-label="<?php echo esc_attr( glc_admin_t( 'photos_earlier' ) ); ?>" title="<?php echo esc_attr( glc_admin_t( 'photos_earlier' ) ); ?>">&uarr;</button>
				<button type="button" class="button button-small glc-photo-down" aria-label="<?php echo esc_attr( glc_admin_t( 'photos_later' ) ); ?>" title="<?php echo esc_attr( glc_admin_t( 'photos_later' ) ); ?>">&darr;</button>
				<button type="button" class="button-link button-link-delete glc-photo-remove"><?php echo esc_html( glc_admin_t( 'photos_remove' ) ); ?></button>
			</div>
		</li>
		<?php
	}

	/**
	 * The details form, and under it what the car is: set by Geolander and shown
	 * to read. No input exists for those, and a request that carries one is not read.
	 *
	 * @param array $specs the stored details, or what was typed after a refused save
	 */
	private static function render_specs( int $car_id, int $rev, array $specs ): void {
		$field = static fn( string $key ): string => self::SPEC_FIELD . '[' . $key . ']';
		$terms = static function ( string $taxonomy ) use ( $car_id ): string {
			$found = get_the_terms( $car_id, $taxonomy );
			return is_array( $found ) ? implode( glc_admin_t( 'price_join' ), wp_list_pluck( $found, 'name' ) ) : '';
		};
		$shown = (int) GLC_Settings::whole( 'readonly_excerpt_chars' );
		$text  = trim( wp_strip_all_tags( strip_shortcodes( (string) get_post_field( 'post_content', $car_id ) ), true ) );
		$fixed = [
			'specs_title'       => (string) get_post_field( 'post_title', $car_id ),
			'col_plate'         => (string) get_post_meta( $car_id, 'glc_registration', true ),
			'col_year'          => (int) get_post_meta( $car_id, 'glc_year', true ) ? (string) (int) get_post_meta( $car_id, 'glc_year', true ) : '',
			'specs_brand'       => $terms( 'car_brand' ),
			'specs_body_type'   => $terms( 'car_body_type' ),
			'specs_description' => $shown > 0 && mb_strlen( $text ) > $shown ? mb_substr( $text, 0, $shown ) . glc_admin_t( 'specs_cut' ) : $text,
			'specs_fuel_note'   => (string) get_post_meta( $car_id, 'glc_fuel_economy_note', true ),
			'specs_tyres_note'  => (string) get_post_meta( $car_id, 'glc_tyres_note', true ),
			'specs_luggage_note' => (string) get_post_meta( $car_id, 'glc_luggage_note', true ),
		];
		?>
		<p class="description"><?php echo esc_html( glc_admin_t( 'specs_help' ) ); ?></p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="glc-specs-form">
			<input type="hidden" name="action" value="glc_partner_save_specs" />
			<input type="hidden" name="car" value="<?php echo (int) $car_id; ?>" />
			<input type="hidden" name="glc_rev" value="<?php echo (int) $rev; ?>" />
			<?php wp_nonce_field( 'glc_partner_save_specs_' . $car_id, '_wpnonce', false ); ?>
			<table class="form-table glc-specs" role="presentation">
				<?php foreach ( GLC_Price_Store::SPEC_FIELDS as $key => $type ) : ?>
					<?php
					$value = $specs[ $key ] ?? '';
					$most  = isset( GLC_Price_Store::SPEC_LIMITS[ $key ] ) && 'choice' !== $type ? GLC_Settings::whole( GLC_Price_Store::SPEC_LIMITS[ $key ] ) : null;
					$help  = GLC_Price_Store::spec_label_key( $key ) . '_help';
					?>
					<tr>
						<th scope="row"><label for="glc-spec-<?php echo esc_attr( $key ); ?>"><?php echo esc_html( glc_admin_t( GLC_Price_Store::spec_label_key( $key ) ) ); ?></label></th>
						<td>
							<?php if ( 'choice' === $type ) : ?>
								<?php $words = GLC_Settings::words( GLC_Price_Store::SPEC_LIMITS[ $key ] ); ?>
								<select id="glc-spec-<?php echo esc_attr( $key ); ?>" name="<?php echo esc_attr( $field( $key ) ); ?>">
									<?php // A stored word the settings no longer list stays selectable, so opening the form changes nothing by itself. ?>
									<?php foreach ( array_unique( array_merge( '' === (string) $value || in_array( (string) $value, $words, true ) ? [] : [ (string) $value ], $words ) ) as $word ) : ?>
										<option value="<?php echo esc_attr( $word ); ?>"<?php selected( (string) $value, $word ); ?>><?php echo esc_html( self::choice_text( $word ) ); ?></option>
									<?php endforeach; ?>
								</select>
							<?php elseif ( 'switch' === $type ) : ?>
								<input type="checkbox" id="glc-spec-<?php echo esc_attr( $key ); ?>" name="<?php echo esc_attr( $field( $key ) ); ?>" value="1"<?php checked( true === $value || '1' === (string) $value ); ?> />
							<?php elseif ( 'seats' === $type ) : ?>
								<input type="number" class="small-text" step="1" inputmode="numeric" id="glc-spec-<?php echo esc_attr( $key ); ?>" name="<?php echo esc_attr( $field( $key ) ); ?>" min="<?php echo (int) GLC_Settings::whole( 'spec_seats_min' ); ?>" max="<?php echo (int) GLC_Settings::whole( 'spec_seats_max' ); ?>" value="<?php echo esc_attr( (string) ( is_int( $value ) && ! $value ? '' : $value ) ); ?>" />
							<?php elseif ( 'evidence' === $type && 'glc_odometer_km' !== $key ) : ?>
								<input type="date" id="glc-spec-<?php echo esc_attr( $key ); ?>" name="<?php echo esc_attr( $field( $key ) ); ?>" value="<?php echo esc_attr( (string) $value ); ?>" />
							<?php elseif ( 'evidence' === $type ) : ?>
								<input type="number" class="regular-text" step="1" min="1" inputmode="numeric" id="glc-spec-<?php echo esc_attr( $key ); ?>" name="<?php echo esc_attr( $field( $key ) ); ?>" value="<?php echo esc_attr( (string) $value ); ?>" />
							<?php else : ?>
								<input type="text" class="regular-text" id="glc-spec-<?php echo esc_attr( $key ); ?>" name="<?php echo esc_attr( $field( $key ) ); ?>" value="<?php echo esc_attr( (string) $value ); ?>"<?php echo null !== $most ? ' maxlength="' . (int) $most . '"' : ''; ?> />
							<?php endif; ?>
							<?php if ( GLC_Admin_Strings::has( $help ) ) : ?>
								<p class="description"><?php echo esc_html( glc_admin_t( $help ) ); ?></p>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</table>
			<p><button type="submit" class="button button-primary"><?php echo esc_html( glc_admin_t( 'save_button' ) ); ?></button></p>
		</form>

		<h3><?php echo esc_html( glc_admin_t( 'specs_fixed_heading' ) ); ?></h3>
		<p class="description"><?php echo esc_html( glc_admin_t( 'specs_fixed_help' ) ); ?></p>
		<table class="form-table glc-specs glc-specs--fixed" role="presentation">
			<?php foreach ( $fixed as $label => $value ) : ?>
				<tr>
					<th scope="row"><?php echo esc_html( glc_admin_t( $label ) ); ?></th>
					<td><?php echo esc_html( '' !== trim( $value ) ? $value : glc_admin_t( 'not_set' ) ); ?></td>
				</tr>
			<?php endforeach; ?>
		</table>
		<?php
	}

	/** The car's changes, newest first, each with a way back to the state before it. */
	private static function render_history( int $car_id, bool $owner ): void {
		$state = [];
		foreach ( GLC_Price_Store::KINDS as $kind ) {
			$state[ $kind ] = GLC_Price_Store::read( $car_id, $kind );
		}
		$limit   = GLC_Settings::get( 'history_rows' );
		$entries = GLC_Change_Log::entries( [
			'car_id' => $car_id,
			'kinds'  => $owner ? array_merge( GLC_Price_Store::KINDS, [ 'assignment' ] ) : GLC_Price_Store::KINDS,
			'limit'  => is_numeric( $limit ) && (int) $limit > 0 ? (int) $limit : -1,
		] );
		if ( ! $entries ) {
			echo '<p>' . esc_html( glc_admin_t( 'history_empty' ) ) . '</p>';
			return;
		}
		?>
		<p class="description"><?php echo esc_html( glc_admin_t( 'history_help' ) ); ?></p>
		<table class="widefat striped glc-history">
			<thead>
				<tr>
					<th scope="col"><?php echo esc_html( glc_admin_t( 'history_when' ) ); ?></th>
					<th scope="col"><?php echo esc_html( glc_admin_t( 'history_who' ) ); ?></th>
					<th scope="col"><?php echo esc_html( glc_admin_t( 'history_what' ) ); ?></th>
					<th scope="col"><?php echo esc_html( glc_admin_t( 'history_how' ) ); ?></th>
					<th scope="col"><?php echo esc_html( glc_admin_t( 'history_summary' ) ); ?></th>
					<td></td>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $entries as $entry ) : ?>
					<tr>
						<td><?php echo esc_html( $entry['time'] ); ?></td>
						<td><?php echo esc_html( $entry['actor_name'] ); ?></td>
						<td><?php echo esc_html( glc_admin_t( 'kind_' . $entry['kind'] ) ); ?></td>
						<td><?php echo esc_html( glc_admin_t( 'source_' . $entry['source'] ) ); ?></td>
						<td><?php echo esc_html( self::summarise( $entry ) ); ?></td>
						<td>
							<?php if ( ! in_array( $entry['kind'], GLC_Price_Store::KINDS, true ) || ! is_array( $entry['before'] ) ) : ?>
								<?php echo esc_html( glc_admin_t( 'not_set' ) ); ?>
							<?php elseif ( $entry['before'] === $state[ $entry['kind'] ] ) : ?>
								<span class="description"><?php echo esc_html( glc_admin_t( 'history_is_current' ) ); ?></span>
							<?php else : ?>
								<a class="button button-small" href="<?php echo esc_url( self::url( $car_id, [ 'restore' => $entry['id'] ] ) ); ?>"><?php echo esc_html( glc_admin_t( 'history_restore' ) ); ?></a>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/** A season table, read-only, with the cells that differ from another table marked. */
	public static function render_table( array $seasons, array $other ): void {
		$theirs = array_map( static fn( array $season ): array => (array) ( $season['rates'] ?? [] ), self::by_dates( $other ) );
		?>
		<table class="widefat striped glc-table-view">
			<thead>
				<tr>
					<th scope="col"><?php echo esc_html( glc_admin_t( 'season_col' ) ); ?></th>
					<?php foreach ( GLC_Pricing::TIERS as $band ) : ?>
						<th scope="col"><?php echo esc_html( self::band_text( $band ) ); ?></th>
					<?php endforeach; ?>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $seasons as $season ) : ?>
					<?php $same = $theirs[ ( $season['from'] ?? '' ) . '>' . ( $season['to'] ?? '' ) ] ?? null; ?>
					<tr>
						<th scope="row" class="<?php echo null === $same ? 'glc-differs' : ''; ?>"><?php echo esc_html( self::season_text( $season ) ); ?></th>
						<?php foreach ( GLC_Pricing::TIERS as $band ) : ?>
							<?php $rate = $season['rates'][ $band ] ?? ''; ?>
							<td class="<?php echo null === $same || (string) ( $same[ $band ] ?? '' ) !== (string) $rate ? 'glc-differs' : ''; ?>"><?php echo esc_html( is_numeric( $rate ) ? self::money( $rate ) : glc_admin_t( 'not_set' ) ); ?></td>
						<?php endforeach; ?>
					</tr>
				<?php endforeach; ?>
				<?php if ( ! $seasons ) : ?>
					<tr><td colspan="<?php echo count( GLC_Pricing::TIERS ) + 1; ?>"><?php echo esc_html( glc_admin_t( 'table_empty' ) ); ?></td></tr>
				<?php endif; ?>
			</tbody>
		</table>
		<?php
	}

	/** The confirmation before a restore: what is stored now, what would be put back, and the sample quotes for both. */
	private static function render_restore( int $car_id, array $entry ): void {
		$kind   = $entry['kind'];
		$quoted = in_array( $kind, GLC_Price_Store::QUOTED, true );
		$now    = GLC_Price_Store::state( $car_id );
		$then   = [ $kind => $entry['before'] ] + $now;
		$rev    = GLC_Price_Store::rev( $car_id );
		$plan   = GLC_Price_Store::plan( [ $car_id => [ 'changes' => [ $kind => $entry['before'] ], 'rev' => $rev ] ], [ 'source' => 'restore' ] );
		$stops  = is_wp_error( $plan ) ? [ $plan->get_error_message() ] : ( $plan['errors'] ? self::error_lines( new WP_Error( 'glc_store_refused', '', [ 'errors' => $plan['errors'] ] ), $car_id ) : [] );
		// What the store would really write: for photos that is the earlier state with no alt text left empty.
		$change = is_wp_error( $plan ) ? null : ( $plan['cars'][ $car_id ]['changes'][ $kind ] ?? null );
		$lines  = $change ? self::describe( $kind, $change['before'], $change['after'] ) : [];
		?>
		<div class="wrap glc-panel" lang="<?php echo esc_attr( GLC_Admin_Strings::lang() ); ?>">
			<p><a href="<?php echo esc_url( self::url( $car_id ) . '#glc-history' ); ?>">&larr; <?php echo esc_html( glc_admin_t( 'restore_back' ) ); ?></a></p>
			<h1><?php echo esc_html( sprintf( glc_admin_t( 'restore_title' ), get_the_title( $car_id ) ) ); ?></h1>
			<p><?php echo esc_html( sprintf( glc_admin_t( 'restore_intro' ), $entry['time'], $entry['actor_name'], glc_admin_t( 'kind_' . $kind ) ) ); ?></p>
			<?php if ( $stops ) : ?>
				<div class="notice notice-error inline" role="alert">
					<p><strong><?php echo esc_html( glc_admin_t( 'restore_refused' ) ); ?></strong></p>
					<ul>
						<?php foreach ( $stops as $line ) : ?>
							<li><?php echo esc_html( $line ); ?></li>
						<?php endforeach; ?>
					</ul>
				</div>
			<?php elseif ( ! $lines ) : ?>
				<div class="notice notice-info inline"><p><?php echo esc_html( glc_admin_t( 'restore_unchanged' ) ); ?></p></div>
			<?php else : ?>
				<h2><?php echo esc_html( glc_admin_t( 'preview_changes' ) ); ?></h2>
				<ul class="glc-change-list">
					<?php foreach ( $lines as $line ) : ?>
						<li><?php echo esc_html( $line ); ?></li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
			<?php if ( 'pricing' === $kind ) : ?>
				<div class="glc-two">
					<div>
						<h2><?php echo esc_html( glc_admin_t( 'samples_now' ) ); ?></h2>
						<?php self::render_table( $now['pricing'], $then['pricing'] ); ?>
					</div>
					<div>
						<h2><?php echo esc_html( glc_admin_t( 'restore_then' ) ); ?></h2>
						<?php self::render_table( $then['pricing'], $now['pricing'] ); ?>
					</div>
				</div>
			<?php endif; ?>
			<?php if ( ! $stops && $quoted ) : ?>
				<?php self::render_samples( $car_id, [ 'now' => $now, 'then' => $then ], glc_admin_t( 'samples_now' ), glc_admin_t( 'restore_then' ) ); ?>
			<?php endif; ?>
			<?php if ( ! $stops && $lines ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="glc_partner_restore" />
					<input type="hidden" name="car" value="<?php echo (int) $car_id; ?>" />
					<input type="hidden" name="change" value="<?php echo (int) $entry['id']; ?>" />
					<input type="hidden" name="glc_rev" value="<?php echo (int) $rev; ?>" />
					<?php wp_nonce_field( 'glc_partner_restore_' . $car_id . '_' . $entry['id'], '_wpnonce', false ); ?>
					<p>
						<button type="submit" class="button button-primary"><?php echo esc_html( glc_admin_t( 'restore_confirm' ) ); ?></button>
						<a class="button" href="<?php echo esc_url( self::url( $car_id ) . '#glc-history' ); ?>"><?php echo esc_html( glc_admin_t( 'restore_cancel' ) ); ?></a>
					</p>
				</form>
			<?php endif; ?>
		</div>
		<?php
	}
}
