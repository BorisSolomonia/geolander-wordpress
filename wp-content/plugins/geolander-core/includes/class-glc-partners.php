<?php
/**
 * Car partners: owners who look after their own cars on the site.
 *
 * A partner is a WordPress user with the role `glc_partner`. The role can read,
 * upload a file and open the partner panel, and nothing else: no post
 * capability at all, so the WordPress editor, the Cars list and the REST API
 * stay closed to them without a single rule having to be written for each.
 * Which cars a partner looks after is not a capability either: it is one number
 * on the car (`glc_partner_id`), and every screen that writes asks can_manage()
 * for each car it is about to touch.
 */

defined( 'ABSPATH' ) || exit;

class GLC_Partners {

	public const ROLE         = 'glc_partner';
	public const CAP_PANEL    = 'glc_partner_panel';
	public const CAP_MANAGE   = 'glc_manage_partners';
	public const META_PARTNER = 'glc_partner_id';

	/** A partner's WhatsApp phone and the last time they signed in: user meta. */
	public const META_PHONE      = 'glc_partner_phone';
	public const META_LAST_LOGIN = 'glc_last_login';

	/** International form, the way WhatsApp takes it: a plus and 8 to 15 digits. */
	private const PHONE_PATTERN = '/^\+[0-9]{8,15}$/';

	/** The profile form's field for the phone, on the panel and on WordPress's own user screen. */
	public const PHONE_FIELD = 'glc_partner_phone';

	/** Exactly these. Anything more would open a WordPress screen to partners. */
	private const PARTNER_CAPS = [ 'read', 'upload_files', self::CAP_PANEL ];

	/**
	 * Roles live in the database, and this plugin is already active on the live
	 * site, so an activation hook would never run there. Instead the stored
	 * number says how far the database has been brought; each step runs once.
	 */
	private const SCHEMA_OPTION  = 'glc_schema_version';
	private const SCHEMA_VERSION = 1;

	public static function init(): void {
		self::upgrade();
		add_action( 'init', [ __CLASS__, 'register_meta' ] );
		add_filter( 'is_protected_meta', [ __CLASS__, 'protect_meta' ], 10, 3 );
		add_filter( 'wp_is_application_passwords_available_for_user', [ __CLASS__, 'application_passwords' ], 10, 2 );
		// The web server already refuses xmlrpc.php. This is the second lock, in WordPress itself: an upload over
		// XML-RPC never passes the check every other upload passes (GLC_Partner_Media::check_upload).
		add_filter( 'xmlrpc_enabled', '__return_false' );
		add_filter( 'login_redirect', [ __CLASS__, 'login_redirect' ], 10, 3 );
		add_action( 'template_redirect', [ __CLASS__, 'hide_author_page' ], 0 );
		add_action( 'deleted_user', [ __CLASS__, 'release_cars' ] );
		add_action( 'wp_login', [ __CLASS__, 'note_login' ], 10, 2 );
		add_action( 'show_user_profile', [ __CLASS__, 'profile_field' ] );
		add_action( 'edit_user_profile', [ __CLASS__, 'profile_field' ] );
		add_action( 'user_profile_update_errors', [ __CLASS__, 'profile_field_save' ], 10, 3 );
	}

	public static function upgrade(): void {
		$done = (int) get_option( self::SCHEMA_OPTION, 0 );
		if ( $done >= self::SCHEMA_VERSION ) {
			return;
		}
		for ( $step = $done + 1; $step <= self::SCHEMA_VERSION; $step++ ) {
			self::{ 'upgrade_to_' . $step }();
		}
		update_option( self::SCHEMA_OPTION, self::SCHEMA_VERSION, true );
	}

	/** Step 1: the partner role, and the two new capabilities for the site's owner. Safe to run again. */
	private static function upgrade_to_1(): void {
		$role = get_role( self::ROLE );
		if ( ! $role ) {
			add_role( self::ROLE, glc_admin_t( 'role_partner_name', GLC_Admin_Strings::DEFAULT_LANG ), array_fill_keys( self::PARTNER_CAPS, true ) );
		} else {
			foreach ( array_keys( $role->capabilities ) as $cap ) {
				if ( ! in_array( $cap, self::PARTNER_CAPS, true ) ) {
					$role->remove_cap( $cap );
				}
			}
			foreach ( self::PARTNER_CAPS as $cap ) {
				if ( empty( $role->capabilities[ $cap ] ) ) {
					$role->add_cap( $cap );
				}
			}
		}
		$owner = get_role( 'administrator' );
		if ( $owner ) {
			$owner->add_cap( self::CAP_PANEL );
			$owner->add_cap( self::CAP_MANAGE );
		}
	}

	/**
	 * The assignment. Never in REST, and written only by code: the car edit
	 * screen cannot resubmit it and the Custom Fields box does not show it.
	 */
	public static function register_meta(): void {
		register_post_meta( 'car', self::META_PARTNER, [
			'type'              => 'integer',
			'single'            => true,
			'show_in_rest'      => false,
			'sanitize_callback' => 'absint',
			'auth_callback'     => '__return_false',
		] );
	}

	public static function protect_meta( $protected, $meta_key, $meta_type ) {
		return 'post' === $meta_type && self::META_PARTNER === $meta_key ? true : $protected;
	}

	public static function is_partner( $user ): bool {
		return $user instanceof WP_User && in_array( self::ROLE, (array) $user->roles, true );
	}

	/**
	 * May this user change this car? The site's owner may change every car; a
	 * partner only a car assigned to them. A car with no partner (absent or 0)
	 * is Geolander's own.
	 */
	public static function can_manage( int $user_id, int $car_id ): bool {
		if ( $user_id < 1 || 'car' !== get_post_type( $car_id ) ) {
			return false;
		}
		if ( user_can( $user_id, 'manage_options' ) ) {
			return true;
		}
		return (int) get_post_meta( $car_id, self::META_PARTNER, true ) === $user_id;
	}

	/** The cars a user may change, by title: every car for the site's owner, the assigned ones for a partner. */
	public static function cars_for( int $user_id ): array {
		if ( $user_id < 1 ) {
			return [];
		}
		$query = [
			'post_type'      => 'car',
			'post_status'    => array_values( get_post_stati( [ 'show_in_admin_all_list' => true ] ) ),
			'posts_per_page' => -1,
			'orderby'        => 'title',
			'order'          => 'ASC',
			'fields'         => 'ids',
			'no_found_rows'  => true,
		];
		if ( ! user_can( $user_id, 'manage_options' ) ) {
			$query['meta_key']   = self::META_PARTNER;
			$query['meta_value'] = $user_id;
		}
		return array_map( 'intval', get_posts( $query ) );
	}

	/* ------------------------------------------------------- who has which car */

	/** Every partner account, by name. */
	public static function partners(): array {
		return get_users( [ 'role' => self::ROLE, 'orderby' => 'display_name', 'order' => 'ASC' ] );
	}

	/** The partner a car is assigned to; 0 = Geolander's own car. */
	public static function partner_of( int $car_id ): int {
		return (int) get_post_meta( $car_id, self::META_PARTNER, true );
	}

	/**
	 * Give cars to partners, or take them back (partner 0). All of it or none:
	 * one car that is not a car, or one account that is not a partner, and
	 * nothing is written. Each car that really changes gets a change-log entry.
	 * Who may call this is the caller's business (the Partners screen asks for
	 * `glc_manage_partners`); the access itself follows at the partner's next
	 * request, because can_manage() reads the car every time.
	 *
	 * @param array $changes [ car id => partner user id, or 0 ]
	 * @param array $expected Optional assignments shown by the caller, checked under lock.
	 * @return array|WP_Error the car ids that changed
	 */
	public static function assign( array $changes, string $note = '', array $expected = [] ) {
		foreach ( $changes as $car_id => $partner_id ) {
			if ( 'car' !== get_post_type( (int) $car_id ) ) {
				return new WP_Error( 'glc_assign_car', sprintf( glc_admin_t( 'err_not_car' ), (int) $car_id ), [ 'status' => 400 ] );
			}
			if ( (int) $partner_id && ! self::is_partner( get_userdata( (int) $partner_id ) ) ) {
				return new WP_Error( 'glc_assign_partner', sprintf( glc_admin_t( 'err_assign_partner' ), (int) $partner_id ), [ 'status' => 400 ] );
			}
		}
		global $wpdb;
		ksort( $changes, SORT_NUMERIC );
		$batch         = wp_generate_uuid4();
		$changed       = [];
		$entries       = [];
		$committed     = false;
		$errors_before = count( $GLOBALS['EZSQL_ERROR'] ?? [] );
		$failed = static fn() => new WP_Error( 'glc_assign_failed', glc_admin_t( 'err_database' ), [ 'status' => 500 ] );
		if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
			return $failed();
		}
		try {
			// The price store locks these same rows, in the same order. Revoking a
			// partner and a simultaneous car save therefore cannot pass each other.
			foreach ( $changes as $car_id => $partner_id ) {
				$locked = $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE ID = %d FOR UPDATE", (int) $car_id ) );
				if ( ! $locked || $wpdb->last_error ) {
					return $failed();
				}
				wp_cache_delete( (int) $car_id, 'post_meta' );
				if ( array_key_exists( $car_id, $expected ) && self::partner_of( (int) $car_id ) !== (int) $expected[ $car_id ] ) {
					return new WP_Error( 'glc_assign_stale', sprintf( glc_admin_t( 'assign_stale' ), get_the_title( (int) $car_id ) ), [ 'status' => 409 ] );
				}
			}
			foreach ( $changes as $car_id => $partner_id ) {
				$car_id     = (int) $car_id;
				$partner_id = (int) $partner_id;
				$before     = self::partner_of( $car_id );
				if ( $before === $partner_id ) {
					continue;
				}
				if ( $partner_id ) {
					update_post_meta( $car_id, self::META_PARTNER, $partner_id );
				} else {
					delete_post_meta( $car_id, self::META_PARTNER );
				}
				$entry = GLC_Change_Log::record( [
					'car_id' => $car_id,
					'kind'   => 'assignment',
					'source' => defined( 'WP_CLI' ) && WP_CLI ? 'cli' : 'panel',
					'batch'  => $batch,
					'before' => [ 'partner' => $before ],
					'after'  => [ 'partner' => $partner_id ],
					'note'   => $note,
				] );
				if ( is_wp_error( $entry ) ) {
					return $failed();
				}
				$entries[] = $entry;
				wp_cache_delete( $car_id, 'post_meta' );
				if ( self::partner_of( $car_id ) !== $partner_id || count( $GLOBALS['EZSQL_ERROR'] ?? [] ) > $errors_before ) {
					return $failed();
				}
				$changed[] = $car_id;
			}
			if ( false === $wpdb->query( 'COMMIT' ) ) {
				return $failed();
			}
			$committed = true;
			return $changed;
		} finally {
			if ( ! $committed ) {
				$wpdb->query( 'ROLLBACK' );
				foreach ( array_keys( $changes ) as $car_id ) {
					wp_cache_delete( (int) $car_id, 'post_meta' );
				}
				foreach ( $entries as $entry ) {
					clean_post_cache( $entry );
				}
			}
		}
	}

	/**
	 * A partner's account was deleted: their cars go back to Geolander, and the
	 * log says so. Without this the cars would keep pointing at nobody, and a new
	 * account that happened to get the same number would inherit them.
	 */
	public static function release_cars( $user_id ): void {
		$cars = get_posts( [
			'post_type'      => 'car',
			'post_status'    => array_keys( get_post_stati() ), // a car in the bin is still somebody's
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
			'meta_key'       => self::META_PARTNER,
			'meta_value'     => (int) $user_id,
		] );
		if ( $cars ) {
			self::assign( array_fill_keys( array_map( 'intval', $cars ), 0 ), sprintf( glc_admin_t( 'log_note_account_deleted', GLC_Admin_Strings::DEFAULT_LANG ), (int) $user_id ) );
		}
	}

	/* -------------------------------------------------------- name and phone */

	/** A person's name as the screens print it: first and last name when they gave them, else the account's name. */
	public static function name( $user ): string {
		if ( ! $user instanceof WP_User ) {
			return '';
		}
		$full = trim( $user->first_name . ' ' . $user->last_name );
		return '' !== $full ? $full : (string) $user->display_name;
	}

	/** The partner's phone as stored, with its plus; empty when they have not given one. */
	public static function phone( int $user_id ): string {
		return $user_id ? (string) get_user_meta( $user_id, self::META_PHONE, true ) : '';
	}

	/**
	 * A typed phone in the stored form, or null when it is not a phone. Spaces,
	 * dashes, dots and brackets are dropped first, so "+995 511 28-62-24" is fine.
	 * An empty value is fine too and means "no phone".
	 */
	public static function clean_phone( string $typed ): ?string {
		$phone = (string) preg_replace( '/[\s().\-]+/u', '', $typed );
		return '' === $phone || preg_match( self::PHONE_PATTERN, $phone ) ? $phone : null;
	}

	/**
	 * Store a phone that clean_phone() accepted. Booking requests for this
	 * person's cars go to it from the next request on, so a change is logged.
	 *
	 * @return bool whether it changed
	 */
	public static function save_phone( int $user_id, string $phone ): bool {
		$before = self::phone( $user_id );
		if ( $before === $phone ) {
			return false;
		}
		if ( '' === $phone ) {
			delete_user_meta( $user_id, self::META_PHONE );
		} else {
			update_user_meta( $user_id, self::META_PHONE, $phone );
		}
		GLC_Change_Log::record( [
			'car_id' => 0,
			'kind'   => 'profile',
			'source' => defined( 'WP_CLI' ) && WP_CLI ? 'cli' : 'panel',
			'before' => [ 'user' => $user_id, 'phone' => $before ],
			'after'  => [ 'user' => $user_id, 'phone' => $phone ],
		] );
		return true;
	}

	/** The owner sees when a partner last signed in. Nothing is noted for anyone else. */
	public static function note_login( $login, $user ): void {
		if ( self::is_partner( $user ) ) {
			update_user_meta( $user->ID, self::META_LAST_LOGIN, time() );
		}
	}

	/** The phone on WordPress's own user screens, so the owner can see and correct it there. */
	public static function profile_field( $user ): void {
		if ( ! self::is_partner( $user ) ) {
			return;
		}
		?>
		<h2><?php echo esc_html( glc_admin_t( 'profile_wp_heading' ) ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th><label for="<?php echo esc_attr( self::PHONE_FIELD ); ?>"><?php echo esc_html( glc_admin_t( 'profile_phone' ) ); ?></label></th>
				<td>
					<input type="tel" class="regular-text" id="<?php echo esc_attr( self::PHONE_FIELD ); ?>" name="<?php echo esc_attr( self::PHONE_FIELD ); ?>" value="<?php echo esc_attr( self::phone( (int) $user->ID ) ); ?>" />
					<p class="description"><?php echo esc_html( glc_admin_t( 'profile_phone_help' ) ); ?></p>
				</td>
			</tr>
		</table>
		<?php
	}

	/**
	 * Saving that field. WordPress has already checked its own nonce and that the
	 * person may edit this account before it calls here; a phone that cannot be
	 * read stops the whole save and the screen says why.
	 */
	public static function profile_field_save( $errors, $update, $user ): void {
		if ( ! $update || ! isset( $_POST[ self::PHONE_FIELD ] ) || empty( $user->ID ) || ! self::is_partner( get_userdata( (int) $user->ID ) ) ) {
			return;
		}
		if ( ! current_user_can( 'edit_user', (int) $user->ID ) ) {
			return;
		}
		$phone = self::clean_phone( sanitize_text_field( wp_unslash( (string) $_POST[ self::PHONE_FIELD ] ) ) );
		if ( null === $phone ) {
			$errors->add( 'glc_partner_phone', esc_html( glc_admin_t( 'err_phone' ) ) );
			return;
		}
		if ( ! $errors->has_errors() ) {
			self::save_phone( (int) $user->ID, $phone );
		}
	}

	/** A partner signs in with their password only: an application password would be a second, unattended way in. */
	public static function application_passwords( $available, $user ) {
		return self::is_partner( $user ) ? false : $available;
	}

	/**
	 * A partner has no public page. WordPress serves /author/<login>/ and
	 * /?author=<id> for every account, posts or not, with the person's name in
	 * the title and the login in the address: a public list of who can change
	 * prices, and an empty page for search engines. For a partner it is a 404,
	 * the same answer as for an account that does not exist.
	 */
	public static function hide_author_page(): void {
		if ( ! is_author() || ! self::is_partner( get_queried_object() ) ) {
			return;
		}
		global $wp_query;
		$wp_query->set_404();
		status_header( 404 );
		nocache_headers();
	}

	/** After signing in a partner lands on the panel, unless they followed a link to one of its screens. */
	public static function login_redirect( $redirect_to, $requested, $user ) {
		if ( ! self::is_partner( $user ) ) {
			return $redirect_to;
		}
		return str_contains( (string) $requested, 'page=' . GLC_Partner_Panel::SLUG ) ? $redirect_to : GLC_Partner_Panel::url();
	}
}
