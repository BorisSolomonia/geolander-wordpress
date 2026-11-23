<?php
/**
 * The front door: who may knock, how often, and who is told when it opens.
 *
 * WHY THIS FILE EXISTS. On 2026-11-06 this site was found to have been held by
 * someone else since 18–19 October. The chain was short and every link was a
 * default:
 *
 *   1. /?author=1 answered with /author/geolander-admin/, and
 *      /wp-json/wp/v2/users answered with every account. The owner's login
 *      name was public.
 *   2. /wp-login.php accepted unlimited guesses from one address.
 *   3. Once in, FOUR APPLICATION PASSWORDS were minted on the owner's account.
 *      That is the part that mattered: an application password authenticates
 *      over the REST API without the login form and SURVIVES EVERY PASSWORD
 *      CHANGE. Three of the four were still in use on the morning they were
 *      found.
 *   4. With that, 236 casino pages were published and 14 spare administrator
 *      accounts were created, one a night, between 01:00 and 05:00.
 *
 * So this file closes each link, and then watches the door, because the cost of
 * the incident was not the break-in. It was the nineteen days nobody knew.
 *
 * WHAT IS DELIBERATELY NOT HERE. No malware scanner, no firewall, no blocklist
 * of countries or addresses. Those need constant feeding to stay honest, and a
 * stale security tool reports safety it cannot see. Everything here is a door
 * that is either shut or watched, and it needs no updates to keep working.
 *
 * EVERY NUMBER IS A SETTING. The lockout, the thresholds, the header the real
 * visitor address is read from, whether application passwords exist at all:
 * Settings → Geolander, no deploy required. The defaults are the safe end.
 *
 * THE TRIPWIRE, PLAINLY:
 *  - trigger: an account becoming an administrator, an account being created as
 *    one, or any application password being minted.
 *  - owner: whoever holds `manage_options`.
 *  - what happens: one email to `security_alert_email` (the site's admin email
 *    when that is blank) AND a line in a log kept on the site, because email is
 *    the part most likely to be broken when it is needed.
 *  - stop condition: none. It cannot be switched off from the settings screen
 *    on purpose. An intruder's first move is to silence the alarm, and an alarm
 *    with an off switch in the room it guards is decoration.
 *  - on failure: the log line is still written. A tripwire that only emails is
 *    a tripwire you lose when SMTP breaks.
 */

defined( 'ABSPATH' ) || exit;

class GLC_Security {

	/** The log, newest last, and the one-per-address failure counters. */
	private const LOG_OPTION    = 'glc_security_log';
	private const FAIL_PREFIX   = 'glc_sec_fail_';
	private const LOCK_PREFIX   = 'glc_sec_lock_';

	public static function init(): void {
		// Application passwords. GLC_Partners already denies them to partners;
		// this covers everyone else, which is where they were actually abused.
		add_filter( 'wp_is_application_passwords_available', [ __CLASS__, 'app_passwords_available' ] );

		if ( self::on( 'security_hide_users' ) ) {
			add_filter( 'rest_endpoints', [ __CLASS__, 'close_user_routes' ] );
			add_action( 'template_redirect', [ __CLASS__, 'hide_author_pages' ], 0 );
		}

		if ( self::max_fails() > 0 ) {
			add_filter( 'authenticate', [ __CLASS__, 'refuse_while_locked' ], 30, 3 );
			add_action( 'wp_login_failed', [ __CLASS__, 'note_failure' ] );
			add_action( 'wp_login', [ __CLASS__, 'clear_failures' ], 10, 2 );
		}

		// The tripwire. Never conditional.
		add_action( 'set_user_role', [ __CLASS__, 'watch_role_change' ], 10, 3 );
		add_action( 'add_user_role', [ __CLASS__, 'watch_role_added' ], 10, 2 );
		add_action( 'user_register', [ __CLASS__, 'watch_new_user' ] );
		add_action( 'wp_create_application_password', [ __CLASS__, 'watch_app_password' ], 10, 2 );

		// Bookings hold a customer's phone number. Never conditional, and both
		// halves must run: the first shuts the door, the second keeps the key.
		add_filter( 'register_post_type_args', [ __CLASS__, 'close_bookings' ], 10, 2 );
		add_filter( 'user_has_cap', [ __CLASS__, 'grant_bookings' ], 10, 4 );

		add_action( 'template_redirect', [ __CLASS__, 'serve_gone' ], 1 );
		add_action( 'glc_settings_after_form', [ __CLASS__, 'render' ], 20 );
	}

	/* ------------------------------------------------------- booking privacy */

	/**
	 * WHY. A booking request carries the customer's name and phone number. The
	 * post type is registered (class-glc-gateways.php) without a capability
	 * family of its own, so WordPress falls back to the ordinary `post` ones —
	 * which means every `editor`, and the `glc_content_editor` role built from
	 * the same family, could open all of them. That was found on 2026-11-07,
	 * after moving an editor to `glc_content_editor` failed to take the screen
	 * away. The role's own notes said bookings were shut out by
	 * `glc_partner_panel`; that is true of the partners' booking counter and
	 * was never true of WordPress's own Booking Requests screen.
	 *
	 * THE FIX, in two halves that only work together:
	 *  - `close_bookings()` gives the post type its own capability family, so
	 *    asking for it stops meaning "may edit posts".
	 *  - `grant_bookings()` hands that family to whoever holds `manage_options`,
	 *    at the moment the question is asked. Nothing is written to the roles
	 *    table, so there is no migration to run and nothing to fall out of step
	 *    on the next deploy — and the owner cannot be locked out of their own
	 *    bookings by a half-applied upgrade, which is the usual way this kind
	 *    of change goes wrong.
	 *
	 * WHO IS UNAFFECTED. Partners hold `read`, `upload_files` and
	 * `glc_partner_panel` only (GLC_Partners::PARTNER_CAPS). They never had
	 * `edit_posts`, so this screen was never theirs, and their own panel reads
	 * bookings by direct query rather than by capability.
	 */
	private const BOOKING_TYPE   = 'booking_request';
	private const BOOKING_FAMILY = [ 'glc_booking', 'glc_bookings' ];

	public static function close_bookings( $args, $type ) {
		if ( self::BOOKING_TYPE !== $type || ! is_array( $args ) ) {
			return $args;
		}
		$already = ( isset( $args['capabilities'] ) && is_array( $args['capabilities'] ) ) ? $args['capabilities'] : [];

		$args['capability_type'] = self::BOOKING_FAMILY;
		$args['map_meta_cap']    = true;
		// Keep whatever the registration already refused; bookings are created
		// by the checkout, never by hand, and that stays true.
		$args['capabilities']    = array_merge( $already, [ 'create_posts' => 'do_not_allow' ] );
		return $args;
	}

	/**
	 * The family's capability names are worked out by WordPress from the family
	 * itself, the same way `register_post_type()` does it, so they cannot drift
	 * from the names the screens actually ask for. The meta ones are left out:
	 * WordPress decides those per post, from the primitives below.
	 */
	private static function booking_caps(): array {
		static $caps = null;
		if ( null !== $caps ) {
			return $caps;
		}
		$meta  = [ 'edit_post', 'read_post', 'delete_post', 'create_posts' ];
		$found = [];
		foreach ( get_post_type_capabilities( (object) [
			'capability_type' => self::BOOKING_FAMILY,
			'map_meta_cap'    => true,
			'capabilities'    => [],
		] ) as $asked_for => $granted_by ) {
			if ( ! in_array( $asked_for, $meta, true ) ) {
				$found[] = (string) $granted_by;
			}
		}
		$caps = array_values( array_unique( $found ) );
		return $caps;
	}

	/** Whoever may change the site's settings may read its bookings. Nobody else. */
	public static function grant_bookings( $allcaps, $caps, $args, $user ) {
		if ( ! is_array( $allcaps ) || empty( $allcaps['manage_options'] ) ) {
			return $allcaps;
		}
		foreach ( self::booking_caps() as $cap ) {
			$allcaps[ $cap ] = true;
		}
		return $allcaps;
	}

	/* ------------------------------------------------------------- settings */

	private static function on( string $key ): bool {
		return '1' === (string) GLC_Settings::get( $key );
	}

	private static function max_fails(): int {
		return max( 0, (int) ( GLC_Settings::whole( 'security_login_max_fails' ) ?? 0 ) );
	}

	private static function lockout_seconds(): int {
		return max( 60, (int) ( GLC_Settings::whole( 'security_login_lockout_min' ) ?? 15 ) * 60 );
	}

	/**
	 * The visitor's real address. Public traffic reaches this site only through
	 * the Cloudflare Tunnel, so REMOTE_ADDR is always the tunnel and never the
	 * visitor; the real one arrives in a header. The header NAME is a setting
	 * because it belongs to whoever sits in front, and that can change without
	 * this file changing. An empty setting means "trust REMOTE_ADDR", which is
	 * the right answer when nothing sits in front.
	 */
	public static function client_ip(): string {
		$header = trim( (string) GLC_Settings::get( 'security_trusted_ip_header' ) );
		if ( '' !== $header ) {
			$key = 'HTTP_' . strtoupper( str_replace( '-', '_', $header ) );
			if ( ! empty( $_SERVER[ $key ] ) ) {
				$first = trim( explode( ',', (string) $_SERVER[ $key ] )[0] );
				if ( filter_var( $first, FILTER_VALIDATE_IP ) ) {
					return $first;
				}
			}
		}
		$remote = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : '';
		return filter_var( $remote, FILTER_VALIDATE_IP ) ? $remote : '';
	}

	/** Addresses are counted, never stored: the key is a hash, so the log holds no raw address. */
	private static function bucket( string $ip ): string {
		return substr( hash( 'sha256', $ip . wp_salt( 'nonce' ) ), 0, 20 );
	}

	/* ------------------------------------------ 1. application passwords off */

	/**
	 * An application password is a second, unattended way in. This site has no
	 * integration that needs one, and the only ones it ever had belonged to an
	 * intruder. Off by default; the setting exists so a real integration can be
	 * allowed later without editing code.
	 */
	public static function app_passwords_available( $available ) {
		return self::on( 'security_app_passwords' ) ? $available : false;
	}

	/* ------------------------------------------------- 2. no public user list */

	/**
	 * WordPress serves the whole user list to anyone at /wp-json/wp/v2/users.
	 * Names are harmless; the `slug` is the LOGIN NAME, which is half of a
	 * password guess. Signed-in people who are allowed to manage users still
	 * get the route, so wp-admin keeps working.
	 */
	public static function close_user_routes( $endpoints ) {
		if ( current_user_can( 'list_users' ) ) {
			return $endpoints;
		}
		foreach ( [ '/wp/v2/users', '/wp/v2/users/(?P<id>[\d]+)' ] as $route ) {
			unset( $endpoints[ $route ] );
		}
		return $endpoints;
	}

	/**
	 * /author/<login>/ is the same leak with a prettier address, and /?author=1
	 * redirects straight to it, so the owner's login is one request away. This
	 * site has no author archives in its design: nothing links to them.
	 */
	public static function hide_author_pages(): void {
		if ( ! is_author() ) {
			return;
		}
		global $wp_query;
		$wp_query->set_404();
		status_header( 404 );
		nocache_headers();
	}

	/* --------------------------------------------------- 3. guessing costs time */

	/**
	 * After `security_login_max_fails` failures from one address, that address
	 * waits. The wait applies to the ADDRESS, not the account, so trying a
	 * hundred different usernames costs the same as trying one a hundred times
	 * — which is what actually happened here.
	 */
	public static function refuse_while_locked( $user, $username, $password ) {
		if ( '' === $username && '' === $password ) {
			return $user;   // an empty form, not an attempt
		}
		$ip = self::client_ip();
		if ( '' === $ip ) {
			return $user;
		}
		$until = (int) get_transient( self::LOCK_PREFIX . self::bucket( $ip ) );
		if ( $until > time() ) {
			return new WP_Error(
				'glc_locked',
				esc_html( sprintf( glc_admin_t( 'security_locked' ), (int) ceil( ( $until - time() ) / 60 ) ) )
			);
		}
		return $user;
	}

	public static function note_failure( $username ): void {
		$ip = self::client_ip();
		if ( '' === $ip ) {
			return;
		}
		$bucket = self::bucket( $ip );
		$fails  = 1 + (int) get_transient( self::FAIL_PREFIX . $bucket );
		$max    = self::max_fails();
		set_transient( self::FAIL_PREFIX . $bucket, $fails, self::lockout_seconds() );
		if ( $fails >= $max ) {
			set_transient( self::LOCK_PREFIX . $bucket, time() + self::lockout_seconds(), self::lockout_seconds() );
			delete_transient( self::FAIL_PREFIX . $bucket );
			self::log( 'login_locked', sprintf( 'address %s… locked for %d min after %d failures (last tried: %s)', substr( $bucket, 0, 6 ), self::lockout_seconds() / 60, $fails, (string) $username ) );
		}
	}

	public static function clear_failures( $login, $user = null ): void {
		$ip = self::client_ip();
		if ( '' !== $ip ) {
			delete_transient( self::FAIL_PREFIX . self::bucket( $ip ) );
			delete_transient( self::LOCK_PREFIX . self::bucket( $ip ) );
		}
	}

	/* ----------------------------------------------------------- 4. tripwire */

	private static function is_powerful( string $role ): bool {
		$object = get_role( $role );
		return $object instanceof WP_Role && ( ! empty( $object->capabilities['manage_options'] ) || ! empty( $object->capabilities['promote_users'] ) );
	}

	public static function watch_role_change( $user_id, $role, $old_roles = [] ): void {
		if ( self::is_powerful( (string) $role ) ) {
			self::alarm( 'role_elevated', sprintf( '%s was made "%s" (was: %s)', self::who( (int) $user_id ), (string) $role, implode( ', ', (array) $old_roles ) ?: 'nothing' ) );
		}
	}

	public static function watch_role_added( $user_id, $role ): void {
		if ( self::is_powerful( (string) $role ) ) {
			self::alarm( 'role_added', sprintf( '%s also given "%s"', self::who( (int) $user_id ), (string) $role ) );
		}
	}

	public static function watch_new_user( $user_id ): void {
		$user = get_userdata( (int) $user_id );
		if ( ! $user ) {
			return;
		}
		foreach ( (array) $user->roles as $role ) {
			if ( self::is_powerful( (string) $role ) ) {
				self::alarm( 'admin_created', sprintf( 'new account %s created as "%s"', self::who( (int) $user_id ), (string) $role ) );
				return;
			}
		}
	}

	/**
	 * The one that would have caught this in October. An application password
	 * being minted is never routine on this site, so it is always news.
	 */
	public static function watch_app_password( $user_id, $item ): void {
		self::alarm( 'app_password', sprintf( 'an application password named "%s" was created on %s', (string) ( $item['name'] ?? '?' ), self::who( (int) $user_id ) ) );
	}

	private static function who( int $user_id ): string {
		$user = get_userdata( $user_id );
		return $user ? sprintf( '%s (ID %d)', $user->user_login, $user_id ) : sprintf( 'user %d', $user_id );
	}

	/** Log first, then try to email. The log is the part that cannot fail. */
	private static function alarm( string $kind, string $what ): void {
		self::log( $kind, $what );

		$to = trim( (string) GLC_Settings::get( 'security_alert_email' ) );
		if ( '' === $to ) {
			$to = (string) get_option( 'admin_email' );
		}
		if ( '' === $to || ! is_email( $to ) ) {
			return;
		}
		$site = (string) wp_parse_url( (string) home_url(), PHP_URL_HOST );
		wp_mail(
			$to,
			sprintf( glc_admin_t( 'security_mail_subject' ), $site ),
			sprintf( glc_admin_t( 'security_mail_body' ), $what, GLC_Settings::local_time( time() ), $site )
		);
	}

	private static function log( string $kind, string $what ): void {
		$keep = GLC_Settings::whole( 'security_log_points' ) ?: 200;
		$log  = get_option( self::LOG_OPTION, [] );
		$log  = is_array( $log ) ? $log : [];
		$log[] = [ 'at' => time(), 'kind' => $kind, 'what' => $what ];
		if ( count( $log ) > $keep ) {
			$log = array_slice( $log, -$keep );
		}
		update_option( self::LOG_OPTION, $log, false );
	}

	/* ------------------------------------------------- 5. gone, not merely missing */

	/**
	 * An address that held spam answers 410 Gone, not 404 Not Found.
	 *
	 * The two look identical to a person and mean opposite things to a crawler.
	 * 404 says "nothing here today", so Google keeps the URL and re-checks it
	 * for weeks. 410 says "this existed and is deliberately gone", and Google
	 * drops it in days. With 236 posts multiplied across seven locales, that
	 * difference is the whole clean-up.
	 *
	 * The list is DATA, written by _migration/retire-spam.php at the moment it
	 * deletes each post. Nothing here is written in code, so a future clean-up
	 * needs no deploy. One slug covers every locale, because the locale is only
	 * a prefix on the same slug.
	 */
	public static function serve_gone(): void {
		if ( ! is_404() ) {
			return;
		}
		$slugs = get_option( 'glc_gone_slugs', [] );
		if ( ! is_array( $slugs ) || ! $slugs ) {
			return;
		}
		$path = (string) wp_parse_url( (string) ( $_SERVER['REQUEST_URI'] ?? '' ), PHP_URL_PATH );
		$last = basename( rtrim( $path, '/' ) );
		if ( '' !== $last && in_array( $last, $slugs, true ) ) {
			status_header( 410 );
			nocache_headers();
		}
	}

	/* ------------------------------------------------- 6. the daily reconciliation */

	/**
	 * Count what is there and compare it with what should be there.
	 *
	 * WHY THIS EXISTS ON TOP OF THE TRIPWIRE. Every hook above fires only when
	 * a change goes THROUGH WordPress. Someone with database access writes a
	 * row directly and no hook ever runs — the alarm stays silent while an
	 * administrator appears. Counting is the only check that does not care how
	 * the change arrived.
	 *
	 * It compares three numbers and one fingerprint against settings you can
	 * edit, so legitimate growth (a second administrator, a real blog) is one
	 * field change and not a code change:
	 *   - administrators            vs security_expect_admins
	 *   - application passwords     vs security_expect_app_passwords
	 *   - posts of type `post`      vs security_expect_posts
	 *   - the set of account logins vs the fingerprint of its last known state
	 *
	 * NOT scheduled by WordPress cron. This installation sets DISABLE_WP_CRON
	 * and the VM runs no cron at all, so a wp-cron event would never fire and
	 * would be a monitor that silently never ran. It is driven from the host:
	 *   wp eval 'GLC_Security::watch();' --allow-root
	 */
	public static function watch(): array {
		$admins = get_users( [ 'role' => 'administrator', 'fields' => 'ID' ] );
		$users  = get_users( [ 'fields' => [ 'ID', 'user_login' ] ] );

		$app_passwords = 0;
		foreach ( $users as $user ) {
			$held = get_user_meta( (int) $user->ID, '_application_passwords', true );
			$app_passwords += is_array( $held ) ? count( $held ) : 0;
		}

		$counts = (array) wp_count_posts( 'post' );
		$posts  = (int) ( $counts['publish'] ?? 0 ) + (int) ( $counts['future'] ?? 0 ) + (int) ( $counts['draft'] ?? 0 ) + (int) ( $counts['private'] ?? 0 );

		$logins = array_map( static fn( $u ) => (string) $u->user_login, $users );
		sort( $logins );
		$fingerprint = hash( 'sha256', implode( "\n", $logins ) );

		$found    = [ 'admins' => count( $admins ), 'app_passwords' => $app_passwords, 'posts' => $posts ];
		$expected = [
			'admins'        => (int) ( GLC_Settings::whole( 'security_expect_admins' ) ?? 1 ),
			'app_passwords' => (int) ( GLC_Settings::whole( 'security_expect_app_passwords' ) ?? 0 ),
			'posts'         => (int) ( GLC_Settings::whole( 'security_expect_posts' ) ?? 0 ),
		];

		$drift = [];
		foreach ( $expected as $what => $want ) {
			if ( $found[ $what ] > $want ) {
				$drift[] = sprintf( '%s: %d, expected %d', $what, $found[ $what ], $want );
			}
		}

		$was = (string) get_option( 'glc_security_user_fingerprint', '' );
		if ( '' === $was ) {
			update_option( 'glc_security_user_fingerprint', $fingerprint, false );
		} elseif ( $was !== $fingerprint ) {
			$drift[] = sprintf( 'the set of accounts changed (now %d: %s)', count( $logins ), implode( ', ', $logins ) );
			update_option( 'glc_security_user_fingerprint', $fingerprint, false );
		}

		if ( $drift ) {
			self::alarm( 'drift', 'the daily check found ' . implode( '; ', $drift ) );
		}
		update_option( 'glc_security_last_watch', [ 'at' => time(), 'found' => $found, 'drift' => $drift ], false );
		return [ 'found' => $found, 'expected' => $expected, 'drift' => $drift ];
	}

	/* --------------------------------------------------------------- the screen */

	/** The log under Settings → Geolander, newest first, so the door is visible. */
	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$log = get_option( self::LOG_OPTION, [] );
		$log = is_array( $log ) ? array_reverse( $log ) : [];

		echo '<h2>' . esc_html( glc_admin_t( 'security_heading' ) ) . '</h2>';
		echo '<p>' . esc_html( sprintf(
			glc_admin_t( 'security_state' ),
			self::on( 'security_app_passwords' ) ? glc_admin_t( 'security_allowed' ) : glc_admin_t( 'security_blocked' ),
			self::on( 'security_hide_users' ) ? glc_admin_t( 'security_hidden' ) : glc_admin_t( 'security_public' ),
			self::max_fails(),
			(int) ( self::lockout_seconds() / 60 )
		) ) . '</p>';

		$watch = get_option( 'glc_security_last_watch', [] );
		if ( is_array( $watch ) && ! empty( $watch['at'] ) ) {
			$found = (array) ( $watch['found'] ?? [] );
			echo '<p>' . esc_html( sprintf(
				glc_admin_t( 'security_last_watch' ),
				GLC_Settings::local_time( (int) $watch['at'] ),
				(int) ( $found['admins'] ?? 0 ),
				(int) ( $found['app_passwords'] ?? 0 ),
				(int) ( $found['posts'] ?? 0 )
			) ) . '</p>';
		} else {
			echo '<p>' . esc_html( glc_admin_t( 'security_never_watched' ) ) . '</p>';
		}

		if ( ! $log ) {
			echo '<p>' . esc_html( glc_admin_t( 'security_quiet' ) ) . '</p>';
			return;
		}
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html( glc_admin_t( 'security_col_when' ) ) . '</th><th>' . esc_html( glc_admin_t( 'security_col_what' ) ) . '</th></tr></thead><tbody>';
		foreach ( $log as $line ) {
			printf(
				'<tr><td>%s</td><td>%s</td></tr>',
				esc_html( GLC_Settings::local_time( (int) ( $line['at'] ?? 0 ) ) ),
				esc_html( (string) ( $line['what'] ?? '' ) )
			);
		}
		echo '</tbody></table>';
	}
}
