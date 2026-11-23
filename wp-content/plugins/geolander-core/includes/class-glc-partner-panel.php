<?php
/**
 * The partner panel: the plugin's own admin screens for car partners.
 *
 * Partners never open the WordPress post editor. Everything they may change is
 * on these screens, each of which checks the car against the person before it
 * shows or saves anything. This class holds the list of a person's cars and a
 * partner's own profile; one car's screen is GLC_Partner_Car and the owner's
 * Partners screen is GLC_Partner_Admin.
 *
 * A partner finds the panel as its own menu. The site's owner finds the same
 * list under Cars, with every car in it.
 *
 * Text comes from glc_admin_t(), in the reader's panel language. Every screen
 * of the panel carries the same small switch above it (English / ქართული); the
 * choice is kept on the person's account and is theirs alone.
 */

defined( 'ABSPATH' ) || exit;

class GLC_Partner_Panel {

	public const SLUG         = 'glc-partner';
	public const PROFILE_PAGE = 'glc-partner-profile';

	private const PROFILE_ACTION = 'glc_partner_save_profile';
	private const LANG_ACTION    = 'glc_panel_lang';

	/** The field the language travels in, on the switch and on the profile form. */
	public const LANG_FIELD = 'glc_panel_lang';

	/** A note for the next page load waits this long at most; it is deleted as soon as it is shown. */
	private const FLASH_SECONDS = 10 * MINUTE_IN_SECONDS;

	public static function init(): void {
		add_action( 'admin_menu', [ __CLASS__, 'menu' ] );
		add_action( 'admin_post_' . self::PROFILE_ACTION, [ __CLASS__, 'save_profile' ] );
		add_action( 'admin_post_nopriv_' . self::PROFILE_ACTION, [ __CLASS__, 'refuse' ] );
		add_action( 'admin_post_' . self::LANG_ACTION, [ __CLASS__, 'save_lang' ] );
		add_action( 'admin_post_nopriv_' . self::LANG_ACTION, [ __CLASS__, 'refuse' ] );
		add_action( 'all_admin_notices', [ __CLASS__, 'render_lang_switch' ] );
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'switch_assets' ] );
	}

	public static function url( array $args = [] ): string {
		return add_query_arg( [ 'page' => self::SLUG ] + $args, admin_url( 'admin.php' ) );
	}

	/** Where the panel's screens hang in the menu: under Cars for the owner, under the panel's own entry for a partner. */
	public static function parent_slug(): string {
		return current_user_can( GLC_Partners::CAP_MANAGE ) ? 'edit.php?post_type=car' : self::SLUG;
	}

	/** Where a screen stands in a partner's own menu (after "My cars", before "My profile"); under the owner's Cars menu it goes last, after WordPress's own entries. */
	public static function menu_position( int $place ): ?int {
		return current_user_can( GLC_Partners::CAP_MANAGE ) ? null : $place;
	}

	/** The address of one of the panel's screens for the person signed in (the owner's sit under Cars). */
	public static function screen_url( string $page, array $args = [] ): string {
		return current_user_can( GLC_Partners::CAP_MANAGE )
			? add_query_arg( [ 'post_type' => 'car', 'page' => $page ] + $args, admin_url( 'edit.php' ) )
			: add_query_arg( [ 'page' => $page ] + $args, admin_url( 'admin.php' ) );
	}

	/**
	 * The one refusal of the panel. The same words and the same status whether
	 * the person lacks the capability, the form's nonce is wrong, the car is not
	 * theirs or the car does not exist: the answer must not tell those apart.
	 */
	public static function refuse(): void {
		wp_die( esc_html( glc_admin_t( 'panel_refused' ) ), 403 );
	}

	public static function menu(): void {
		if ( current_user_can( GLC_Partners::CAP_MANAGE ) ) {
			// The owner: the same list, with every car, under Cars.
			add_submenu_page( 'edit.php?post_type=car', glc_admin_t( 'cars_title_all' ), glc_admin_t( 'pricing_menu' ), GLC_Partners::CAP_PANEL, self::SLUG, [ __CLASS__, 'render_cars' ] );
			return;
		}
		add_menu_page( glc_admin_t( 'panel_menu' ), glc_admin_t( 'panel_menu' ), GLC_Partners::CAP_PANEL, self::SLUG, [ __CLASS__, 'render_cars' ], 'dashicons-car', 3 );
		add_submenu_page( self::SLUG, glc_admin_t( 'cars_title' ), glc_admin_t( 'cars_title' ), GLC_Partners::CAP_PANEL, self::SLUG, [ __CLASS__, 'render_cars' ] );
		$profile = add_submenu_page( self::SLUG, glc_admin_t( 'profile_title' ), glc_admin_t( 'profile_title' ), GLC_Partners::CAP_PANEL, self::PROFILE_PAGE, [ __CLASS__, 'render_profile' ] );
		add_action( 'load-' . $profile, [ __CLASS__, 'guard_profile' ] );
	}

	/* --------------------------------------------------- the panel language */

	/** Is the page being built one of the panel's screens (the owner's Partners screen and change log included)? */
	private static function on_panel_screen(): bool {
		$page = isset( $_GET['page'] ) && is_string( $_GET['page'] ) ? sanitize_key( $_GET['page'] ) : '';
		if ( in_array( $page, [ self::SLUG, self::PROFILE_PAGE, GLC_Partner_Car::PAGE, GLC_Partner_Bulk::PAGE, GLC_Partner_Excel::PAGE, GLC_Partner_Admin::PAGE ], true ) ) {
			return true;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		return $screen && GLC_Change_Log::POST_TYPE === $screen->post_type;
	}

	/** The switch needs the panel's stylesheet on the screens that otherwise use none. */
	public static function switch_assets(): void {
		if ( current_user_can( GLC_Partners::CAP_PANEL ) && self::on_panel_screen() ) {
			wp_enqueue_style( 'glc-partner', GLC_URL . 'assets/partner.css', [], GLC_VERSION );
		}
	}

	/**
	 * The language switch, above every panel screen. A plain form: it works
	 * without JavaScript and changes nothing but the reader's own choice. Each
	 * language is named in itself, so a person who cannot read the page can
	 * still find theirs.
	 */
	public static function render_lang_switch(): void {
		if ( ! current_user_can( GLC_Partners::CAP_PANEL ) || ! self::on_panel_screen() ) {
			return;
		}
		$now = GLC_Admin_Strings::lang();
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="glc-panel glc-lang-switch" lang="<?php echo esc_attr( $now ); ?>">
			<input type="hidden" name="action" value="<?php echo esc_attr( self::LANG_ACTION ); ?>" />
			<?php wp_nonce_field( self::LANG_ACTION . '_' . get_current_user_id(), '_wpnonce', true ); ?>
			<label for="glc-panel-lang"><?php echo esc_html( glc_admin_t( 'lang_label' ) ); ?></label>
			<select id="glc-panel-lang" name="<?php echo esc_attr( self::LANG_FIELD ); ?>">
				<?php foreach ( GLC_Admin_Strings::languages() as $lang ) : ?>
					<option value="<?php echo esc_attr( $lang ); ?>" lang="<?php echo esc_attr( $lang ); ?>"<?php selected( $now, $lang ); ?>><?php echo esc_html( GLC_Admin_Strings::name( $lang ) ); ?></option>
				<?php endforeach; ?>
			</select>
			<button type="submit" class="button button-small"><?php echo esc_html( glc_admin_t( 'lang_change' ) ); ?></button>
		</form>
		<?php
	}

	/** The language a request names, when the panel has it; null for anything else. */
	private static function posted_lang(): ?string {
		$raw = $_POST[ self::LANG_FIELD ] ?? '';
		return is_string( $raw ) && in_array( $raw, GLC_Admin_Strings::languages(), true ) ? $raw : null;
	}

	/**
	 * Keep the reader's choice on their own account and send them back to the
	 * screen they were on. The request names no user, so it cannot name another.
	 */
	public static function save_lang(): void {
		$nonce = isset( $_POST['_wpnonce'] ) && is_string( $_POST['_wpnonce'] ) ? sanitize_key( $_POST['_wpnonce'] ) : '';
		$lang  = self::posted_lang();
		if ( ! current_user_can( GLC_Partners::CAP_PANEL ) || ! wp_verify_nonce( $nonce, self::LANG_ACTION . '_' . get_current_user_id() ) || null === $lang ) {
			self::refuse();
		}
		update_user_meta( get_current_user_id(), GLC_Admin_Strings::USER_META, $lang );
		// Back to where they were, when that is a screen of this site's admin; otherwise to the panel.
		// The form names its own page by path, without the site's address; a link from elsewhere would carry it.
		$back  = (string) wp_get_referer();
		$admin = (string) wp_parse_url( admin_url(), PHP_URL_PATH );
		wp_safe_redirect( '' !== $back && ( str_starts_with( $back, admin_url() ) || str_starts_with( $back, $admin ) ) ? $back : self::url(), 303 );
		exit;
	}

	/* ---------------------------------------- a note for the next page load */

	/** Keep the answer to a form for the screen the person is sent back to. $slot says which screen. */
	public static function flash( string $slot, array $flash ): void {
		set_transient( 'glc_flash_' . get_current_user_id() . '_' . $slot, $flash, self::FLASH_SECONDS );
	}

	/** That answer, left where it is: for a place that may be drawn more than once before the person has seen it. */
	public static function peek_flash( string $slot ): array {
		$flash = get_transient( 'glc_flash_' . get_current_user_id() . '_' . $slot );
		return is_array( $flash ) ? $flash : [];
	}

	/** That answer, once: it is deleted as it is read. */
	public static function take_flash( string $slot ): array {
		$key   = 'glc_flash_' . get_current_user_id() . '_' . $slot;
		$flash = get_transient( $key );
		if ( false !== $flash ) {
			delete_transient( $key );
		}
		return is_array( $flash ) ? $flash : [];
	}

	/** A flash as a notice: green with what was done, or red with every reason nothing was. */
	public static function render_flash( array $flash ): void {
		if ( empty( $flash['lines'] ) ) {
			return;
		}
		$ok = ! empty( $flash['ok'] );
		?>
		<div class="notice inline <?php echo $ok ? 'notice-success' : 'notice-error'; ?> glc-flash" role="<?php echo $ok ? 'status' : 'alert'; ?>">
			<?php if ( ! $ok ) : ?>
				<p><strong><?php echo esc_html( glc_admin_t( 'not_saved' ) . ( isset( $flash['draft'] ) ? ' ' . glc_admin_t( 'typed_kept' ) : '' ) ); ?></strong></p>
			<?php endif; ?>
			<ul>
				<?php foreach ( (array) $flash['lines'] as $line ) : ?>
					<li><?php echo esc_html( (string) $line ); ?></li>
				<?php endforeach; ?>
			</ul>
		</div>
		<?php
	}

	/* ------------------------------------------------------------- my cars */

	/** The cars this person looks after: title, plate and year, each leading to the car's own screen. */
	public static function render_cars(): void {
		if ( ! current_user_can( GLC_Partners::CAP_PANEL ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to access this page.' ), 403 );
		}
		$everything = current_user_can( 'manage_options' );
		$cars       = GLC_Partners::cars_for( get_current_user_id() );
		$blank      = glc_admin_t( 'not_set' );
		?>
		<div class="wrap" lang="<?php echo esc_attr( GLC_Admin_Strings::lang() ); ?>">
			<h1><?php echo esc_html( glc_admin_t( $everything ? 'cars_title_all' : 'cars_title' ) ); ?></h1>
			<?php if ( ! $cars ) : ?>
				<p><?php echo esc_html( glc_admin_t( $everything ? 'cars_empty_all' : 'cars_empty' ) ); ?></p>
			<?php else : ?>
				<table class="wp-list-table widefat fixed striped">
					<thead>
						<tr>
							<th scope="col"><?php echo esc_html( glc_admin_t( 'col_car' ) ); ?></th>
							<th scope="col"><?php echo esc_html( glc_admin_t( 'col_plate' ) ); ?></th>
							<th scope="col"><?php echo esc_html( glc_admin_t( 'col_year' ) ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $cars as $car_id ) : ?>
							<?php
							$plate = trim( (string) get_post_meta( $car_id, 'glc_registration', true ) );
							$year  = (int) get_post_meta( $car_id, 'glc_year', true );
							?>
							<tr>
								<td>
									<strong><a href="<?php echo esc_url( GLC_Partner_Car::url( $car_id ) ); ?>"><?php echo esc_html( get_the_title( $car_id ) ); ?></a></strong>
									<div class="row-actions visible"><a href="<?php echo esc_url( GLC_Partner_Car::url( $car_id ) ); ?>"><?php echo esc_html( glc_admin_t( 'cars_open' ) ); ?></a></div>
								</td>
								<td><?php echo esc_html( '' !== $plate ? $plate : $blank ); ?></td>
								<td><?php echo esc_html( $year > 0 ? (string) $year : $blank ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}

	/* ----------------------------------------------------------- my profile */

	/** The profile screen is a partner's: the owner corrects a partner's phone on that partner's WordPress user screen. */
	public static function guard_profile(): void {
		if ( ! current_user_can( GLC_Partners::CAP_PANEL ) || ! GLC_Partners::is_partner( wp_get_current_user() ) ) {
			self::refuse();
		}
	}

	/** Name, surname and the WhatsApp phone booking requests for this person's cars are sent to. */
	public static function render_profile(): void {
		self::guard_profile();
		$user  = wp_get_current_user();
		$flash = self::take_flash( 'profile' );
		$draft = (array) ( $flash['draft'] ?? [] );
		$value = static fn( string $field, string $stored ): string => (string) ( $draft[ $field ] ?? $stored );
		?>
		<div class="wrap" lang="<?php echo esc_attr( GLC_Admin_Strings::lang() ); ?>">
			<h1><?php echo esc_html( glc_admin_t( 'profile_title' ) ); ?></h1>
			<?php self::render_flash( $flash ); ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::PROFILE_ACTION ); ?>" />
				<?php wp_nonce_field( self::PROFILE_ACTION . '_' . $user->ID, '_wpnonce', false ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="glc-first-name"><?php echo esc_html( glc_admin_t( 'profile_first_name' ) ); ?></label></th>
						<td><input type="text" class="regular-text" id="glc-first-name" name="first_name" autocomplete="given-name" value="<?php echo esc_attr( $value( 'first_name', (string) $user->first_name ) ); ?>" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="glc-last-name"><?php echo esc_html( glc_admin_t( 'profile_last_name' ) ); ?></label></th>
						<td><input type="text" class="regular-text" id="glc-last-name" name="last_name" autocomplete="family-name" value="<?php echo esc_attr( $value( 'last_name', (string) $user->last_name ) ); ?>" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="glc-phone"><?php echo esc_html( glc_admin_t( 'profile_phone' ) ); ?></label></th>
						<td>
							<input type="tel" class="regular-text" id="glc-phone" name="<?php echo esc_attr( GLC_Partners::PHONE_FIELD ); ?>" autocomplete="tel" value="<?php echo esc_attr( $value( 'phone', GLC_Partners::phone( (int) $user->ID ) ) ); ?>" />
							<p class="description"><?php echo esc_html( glc_admin_t( 'profile_phone_help' ) ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="glc-profile-lang"><?php echo esc_html( glc_admin_t( 'lang_label' ) ); ?></label></th>
						<td>
							<select id="glc-profile-lang" name="<?php echo esc_attr( self::LANG_FIELD ); ?>">
								<?php foreach ( GLC_Admin_Strings::languages() as $lang ) : ?>
									<option value="<?php echo esc_attr( $lang ); ?>" lang="<?php echo esc_attr( $lang ); ?>"<?php selected( GLC_Admin_Strings::lang(), $lang ); ?>><?php echo esc_html( GLC_Admin_Strings::name( $lang ) ); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html( glc_admin_t( 'profile_email' ) ); ?></th>
						<td>
							<?php echo esc_html( (string) $user->user_email ); ?>
							<p class="description"><a href="<?php echo esc_url( admin_url( 'profile.php' ) ); ?>"><?php echo esc_html( glc_admin_t( 'profile_email_help' ) ); ?></a></p>
						</td>
					</tr>
				</table>
				<p><button type="submit" class="button button-primary"><?php echo esc_html( glc_admin_t( 'save_button' ) ); ?></button></p>
			</form>
		</div>
		<?php
	}

	/**
	 * Save the profile form. It always saves the signed-in person's own account:
	 * the request names no user, so it cannot name someone else's.
	 */
	public static function save_profile(): void {
		$user  = wp_get_current_user();
		$nonce = isset( $_POST['_wpnonce'] ) && is_string( $_POST['_wpnonce'] ) ? sanitize_key( $_POST['_wpnonce'] ) : '';
		if ( ! current_user_can( GLC_Partners::CAP_PANEL )
			|| ! GLC_Partners::is_partner( $user )
			|| ! wp_verify_nonce( $nonce, self::PROFILE_ACTION . '_' . $user->ID ) ) {
			self::refuse();
		}
		$typed = [];
		foreach ( [ 'first_name' => 'first_name', 'last_name' => 'last_name', 'phone' => GLC_Partners::PHONE_FIELD ] as $field => $posted ) {
			$typed[ $field ] = isset( $_POST[ $posted ] ) && is_string( $_POST[ $posted ] ) ? sanitize_text_field( wp_unslash( $_POST[ $posted ] ) ) : '';
		}
		$phone = GLC_Partners::clean_phone( $typed['phone'] );
		if ( null === $phone ) {
			self::flash( 'profile', [ 'ok' => false, 'lines' => [ glc_admin_t( 'err_phone' ) ], 'draft' => $typed ] );
		} else {
			$saved = wp_update_user( [
				'ID'           => $user->ID,
				'first_name'   => $typed['first_name'],
				'last_name'    => $typed['last_name'],
				// The name the change log and the owner's screens show.
				'display_name' => trim( $typed['first_name'] . ' ' . $typed['last_name'] ) ?: $user->display_name,
			] );
			if ( is_wp_error( $saved ) ) {
				self::flash( 'profile', [ 'ok' => false, 'lines' => [ $saved->get_error_message() ], 'draft' => $typed ] );
			} else {
				// The language is saved first, so the answer below is already in it. A form without the field leaves it alone.
				if ( null !== self::posted_lang() ) {
					update_user_meta( (int) $user->ID, GLC_Admin_Strings::USER_META, self::posted_lang() );
				}
				$line = glc_admin_t( 'profile_saved' );
				if ( GLC_Partners::save_phone( (int) $user->ID, $phone ) ) {
					// The phone decides where booking requests go, so a change of it is said out loud.
					$line = '' === $phone ? glc_admin_t( 'profile_saved_no_phone' ) : glc_admin_t( 'profile_saved_phone' );
				}
				self::flash( 'profile', [ 'ok' => true, 'lines' => [ $line ] ] );
			}
		}
		wp_safe_redirect( add_query_arg( [ 'page' => self::PROFILE_PAGE ], admin_url( 'admin.php' ) ), 303 );
		exit;
	}
}
