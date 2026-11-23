<?php
/**
 * The content editor: somebody who writes the site and nothing else.
 *
 * WHAT IT IS. A WordPress role, `glc_content_editor`, that may write and
 * publish cars, cities, landing pages, places, news and testimonials, put their
 * terms in order (brands, body types, regions) and upload pictures. That is the
 * whole list, and it is built from the list in MANAGED below rather than copied
 * from WordPress's own `editor`, because what a role may NOT do is the point of
 * having one.
 *
 * TO LET IT LOOK AFTER ANOTHER KIND OF CONTENT, add that post type's capability
 * family to MANAGED and add an `upgrade_to_2()` that calls `reconcile()`. Two
 * lines, no capability names typed out, and the stored role catches up by
 * itself on the next request. Nothing speculative is listed there: a family
 * goes in when its post type is real.
 *
 * WHAT IT CANNOT DO, and why each one is left out:
 *  - `manage_options` — the Geolander settings hold the phone number, the
 *    delivery fees and the payment keys. One wrong character there changes every
 *    price on the site.
 *  - `install_plugins`, `activate_plugins`, `switch_themes`, `edit_themes`,
 *    `edit_plugins`, `edit_files`, `update_core` — code. The live site runs from
 *    a built image; anything installed through wp-admin disappears at the next
 *    deploy anyway unless the host explicitly enables persistent plugin
 *    management. That separate opt-in uses the per-account glc_manage_plugins
 *    capability (GLC_Plugin_Management); it is never granted to this whole role.
 *  - `edit_theme_options` — the site's own templates and global styles. Off by
 *    default, and a single setting turns it on (see below) for whoever is
 *    trusted to make layouts.
 *  - `list_users`, `create_users`, `edit_users`, `promote_users`,
 *    `delete_users` — other people's accounts, including the owner's.
 *  - `glc_partner_panel`, `glc_manage_partners` — the car owners' panel: the
 *    season rate tables, the fee tables, the Excel upload, who owns which car.
 *    This is where the money is, and the editor never sees it. The read-only
 *    rate table on the car edit screen is closed by the same capability (see
 *    GLC_Meta_Boxes::add). Booking requests are shut out by the same line, and
 *    that one is not a preference: a booking carries the customer's phone
 *    number, so somebody who was hired to write words about cars has no reason
 *    to be able to read it (Boris, 2026-11-05).
 *  - `unfiltered_html` — raw script in a post. A content role that can write
 *    <script> is an administrator with extra steps.
 *  - `export`, `import`, `edit_dashboard`, `moderate_comments`, `manage_links` —
 *    nothing here needs them.
 *
 * HOW TO GIVE IT TO SOMEBODY. The role shows up in WordPress's own Role list:
 * Users → Add New (or an existing account → Role) → "Content editor" → Update.
 * Nothing in this file creates an account or sends an email.
 *
 * SAFE TO RUN AGAIN. Roles live in the database and this plugin is already
 * active on the live site, so an activation hook would never run there. The
 * stored number below says how far the database has been brought and each step
 * runs once; the step itself reconciles the capability list in both directions,
 * so a capability added by hand is taken back off.
 */

defined( 'ABSPATH' ) || exit;

class GLC_Roles {

	public const ROLE = 'glc_content_editor';

	/**
	 * THE ONE LIST TO EDIT when this role takes on another kind of content.
	 *
	 * Each entry is a **capability family**, which is the `capability_type` a post
	 * type was registered with — not the post type's own name. Today there are
	 * two, and they already cover six kinds of content:
	 *
	 *   'post'  — cars, cities, places, news, testimonials. Every one of those is
	 *             registered with WordPress's default `post` capabilities (see
	 *             class-glc-cpt.php), so they all answer to the same ten.
	 *   'page'  — ordinary pages, which is what a landing page is.
	 *
	 * A post type registered with its own `capability_type` needs its own family
	 * added here, and that is the whole change: the ten capability names are
	 * worked out from the family by WordPress itself, below. Adding a family that
	 * no post type uses grants capabilities no screen ever asks for — harmless,
	 * but pointless, so a family goes in when its post type does, not before.
	 */
	public const MANAGED = [ 'post', 'page' ];

	/** The capabilities that are not about a kind of content at all. */
	private const SHARED = [
		'read',
		'upload_files',
		// Brands, body types, regions.
		'manage_categories',
	];

	/**
	 * Meta capabilities: WordPress decides these per individual post, from the
	 * primitive ones. Granting one on a role would mean "may edit every post
	 * there is", which is the opposite of what it looks like it means.
	 */
	private const META = [ 'edit_post', 'read_post', 'delete_post' ];

	/**
	 * Exactly these, and WordPress maps every screen the role may open onto one
	 * of them. Anything not here is denied by not being here, which is the only
	 * kind of denial that cannot be forgotten.
	 *
	 * The ten names per family are not written out by hand. WordPress's own
	 * `get_post_type_capabilities()` builds them from the family name, which is
	 * the same function `register_post_type()` uses — so the names here cannot
	 * drift from the names the screens actually ask for, however a future post
	 * type spells its plural.
	 */
	public static function caps(): array {
		$caps = self::SHARED;
		foreach ( self::MANAGED as $family ) {
			// `capabilities` has to be here and has to be an array: the core
			// function merges it without checking, so leaving it out is a fatal
			// error, not a default. (It is the per-post-type override list, and
			// an empty one is what "no overrides" means.)
			$family_caps = get_post_type_capabilities( (object) [
				'capability_type' => $family,
				'map_meta_cap'    => true,
				'capabilities'    => [],
			] );
			foreach ( $family_caps as $asked_for => $granted_by ) {
				if ( in_array( $asked_for, self::META, true ) ) {
					continue;
				}
				$caps[] = (string) $granted_by;
			}
		}
		return array_values( array_unique( $caps ) );
	}

	/**
	 * The ones that must never be in the list above. Nothing reads this at run
	 * time: it is here so the check in tests/roles.php can prove the list has not
	 * drifted, and so the reason a capability is absent is written down next to
	 * the absence.
	 */
	public const DENIED = [
		'manage_options',
		'install_plugins',
		'activate_plugins',
		'edit_plugins',
		'switch_themes',
		'edit_themes',
		'edit_files',
		'update_core',
		'update_plugins',
		'update_themes',
		'list_users',
		'create_users',
		'edit_users',
		'delete_users',
		'promote_users',
		'remove_users',
		'export',
		'import',
		'unfiltered_html',
		'edit_dashboard',
		'manage_links',
		'moderate_comments',
	];

	private const SCHEMA_OPTION  = 'glc_roles_schema_version';
	private const SCHEMA_VERSION = 1;

	public static function init(): void {
		self::upgrade();
		add_filter( 'user_has_cap', [ __CLASS__, 'layout_permission' ], 10, 4 );
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

	/** Step 1: the content-editor role, reconciled in both directions. */
	private static function upgrade_to_1(): void {
		self::reconcile();
	}

	/**
	 * Bring the stored role to exactly the capability list, in both directions:
	 * what is missing is added, and what is no longer on the list is taken off
	 * again. Separate from the step above because adding a family to MANAGED
	 * needs this run once more — a new step calls it, and nothing is rewritten.
	 */
	private static function reconcile(): void {
		$wanted = self::caps();
		$role   = get_role( self::ROLE );
		if ( ! $role ) {
			add_role( self::ROLE, glc_admin_t( 'role_editor_name', GLC_Admin_Strings::DEFAULT_LANG ), array_fill_keys( $wanted, true ) );
			return;
		}
		foreach ( array_keys( $role->capabilities ) as $cap ) {
			if ( ! in_array( $cap, $wanted, true ) ) {
				$role->remove_cap( $cap );
			}
		}
		foreach ( $wanted as $cap ) {
			if ( empty( $role->capabilities[ $cap ] ) ) {
				$role->add_cap( $cap );
			}
		}
	}

	/** Whether this account is a content editor (and nothing more). */
	public static function is_editor( $user ): bool {
		return $user instanceof WP_User && in_array( self::ROLE, (array) $user->roles, true );
	}

	/**
	 * Making layouts: off by default, and one setting away from on.
	 *
	 * `edit_theme_options` is the key to Appearance → Editor, where templates,
	 * patterns and the site's global styles are made. A content editor who is
	 * also trusted to build layouts needs it; most do not. Granting it through a
	 * filter rather than storing it on the role means switching it off takes it
	 * back from everyone at once, with nothing left behind in the database.
	 */
	public static function layout_permission( $caps, $required, $args, $user ) {
		if ( ! self::is_editor( $user ) || '1' !== (string) GLC_Settings::get( 'editor_can_edit_layouts' ) ) {
			return $caps;
		}
		$caps['edit_theme_options'] = true;
		return $caps;
	}
}
