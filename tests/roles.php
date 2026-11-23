<?php
/**
 * Standalone checks for the content-editor role: the capability list is what it
 * says it is, nothing from DENIED has crept into it, and adding a kind of
 * content really is one line. No WordPress, no database.
 *
 * Run: docker run --rm -v "<repo>:/src" php:8.3-cli php /src/tests/roles.php
 */
define( 'ABSPATH', __DIR__ );
$failures = 0;
function check( $condition, $label ) {
	if ( ! $condition ) { ++$GLOBALS['failures']; }
	echo ( $condition ? 'PASS ' : 'FAIL ' ) . $label . "\n";
}

/**
 * WordPress's own function, copied verbatim from wp-includes/post.php so the
 * test exercises the real naming rules rather than a guess at them. If a future
 * WordPress changes how a family becomes capability names, this copy is what
 * will look wrong, and it is one function to re-copy.
 */
function get_post_type_capabilities( $args ) {
	if ( ! is_array( $args->capability_type ) ) {
		$args->capability_type = [ $args->capability_type, $args->capability_type . 's' ];
	}
	[ $singular_base, $plural_base ] = $args->capability_type;
	$default_capabilities = [
		'edit_post'          => 'edit_' . $singular_base,
		'read_post'          => 'read_' . $singular_base,
		'delete_post'        => 'delete_' . $singular_base,
		'edit_posts'         => 'edit_' . $plural_base,
		'edit_others_posts'  => 'edit_others_' . $plural_base,
		'delete_posts'       => 'delete_' . $plural_base,
		'publish_posts'      => 'publish_' . $plural_base,
		'read_private_posts' => 'read_private_' . $plural_base,
	];
	if ( ! empty( $args->map_meta_cap ) ) {
		$default_capabilities_for_mapping = [
			'read'                   => 'read',
			'delete_private_posts'   => 'delete_private_' . $plural_base,
			'delete_published_posts' => 'delete_published_' . $plural_base,
			'delete_others_posts'    => 'delete_others_' . $plural_base,
			'edit_private_posts'     => 'edit_private_' . $plural_base,
			'edit_published_posts'   => 'edit_published_' . $plural_base,
		];
		$default_capabilities = array_merge( $default_capabilities, $default_capabilities_for_mapping );
	}
	// These two lines were missing from an earlier copy of this function, and
	// their absence let this test pass while the real call crashed with
	// "array_merge(): Argument #2 must be of type array, null given". The copy
	// is only worth having if it is faithful, so the array type is asserted
	// rather than defaulted: a caller that forgets `capabilities` fails HERE.
	if ( ! is_array( $args->capabilities ?? null ) ) {
		echo "FAIL caller passed no capabilities array — the real WordPress would fatal here\n";
		++$GLOBALS['failures'];
		$args->capabilities = [];
	}
	$capabilities = array_merge( $default_capabilities, $args->capabilities );
	if ( ! isset( $capabilities['create_posts'] ) ) {
		$capabilities['create_posts'] = $capabilities['edit_posts'];
	}
	return $capabilities;
}

function glc_admin_t( $key, $lang = 'en' ) { return $key; }
class GLC_Admin_Strings { const DEFAULT_LANG = 'en'; }
class GLC_Settings { public static function get( $key, $default = '' ) { return '0'; } }
function add_filter( ...$a ) {}
function get_option( ...$a ) { return 0; }
function get_role( $name ) { return null; }

require __DIR__ . '/../wp-content/plugins/geolander-core/includes/class-glc-roles.php';

$caps = GLC_Roles::caps();

echo "--- the list itself ---\n";
check( count( $caps ) === count( array_unique( $caps ) ), 'no capability is listed twice' );
check( count( $caps ) === 23, 'the role grants 23 capabilities, got ' . count( $caps ) );
check( in_array( 'read', $caps, true ), 'read' );
check( in_array( 'upload_files', $caps, true ), 'upload_files — a page about a car needs a photo of it' );
check( in_array( 'manage_categories', $caps, true ), 'manage_categories — brands, body types, regions' );

echo "--- the content it looks after ---\n";
foreach ( [ 'posts' => 'cars, cities, places, news, testimonials', 'pages' => 'pages, which is what a landing page is' ] as $plural => $what ) {
	foreach ( [ 'edit_', 'edit_others_', 'edit_published_', 'edit_private_', 'publish_', 'read_private_', 'delete_', 'delete_others_', 'delete_published_', 'delete_private_' ] as $prefix ) {
		check( in_array( $prefix . $plural, $caps, true ), $prefix . $plural . "  ($what)" );
	}
}

echo "--- what it must never be able to do ---\n";
$leaked = array_values( array_intersect( $caps, GLC_Roles::DENIED ) );
check( [] === $leaked, 'nothing from DENIED is granted' . ( $leaked ? ': ' . implode( ', ', $leaked ) : '' ) );
check( count( GLC_Roles::DENIED ) === count( array_unique( GLC_Roles::DENIED ) ), 'DENIED lists nothing twice' );
foreach ( [ 'manage_options' => 'the settings hold the phone number and the fees', 'activate_plugins' => 'code', 'edit_themes' => 'code', 'edit_users' => "other people's accounts", 'edit_theme_options' => 'the whole site editor — behind a switch, not on the role', 'unfiltered_html' => 'raw script is an administrator with extra steps', 'glc_partner_panel' => 'rates, fees, and bookings carry customer phone numbers', 'glc_manage_partners' => 'who owns which car' ] as $cap => $why ) {
	check( ! in_array( $cap, $caps, true ), 'not granted: ' . $cap . "  ($why)" );
}

echo "--- meta capabilities are decided per post, never granted ---\n";
foreach ( [ 'edit_post', 'read_post', 'delete_post', 'edit_page', 'read_page', 'delete_page' ] as $meta ) {
	check( ! in_array( $meta, $caps, true ), 'not granted: ' . $meta );
}

echo "--- adding a kind of content is one line ---\n";
// What MANAGED would produce with one more family in it. Nothing here touches
// the real list: this proves the shape, it does not add a post type.
$pretend = array_merge( GLC_Roles::MANAGED, [ 'specimen_type' ] );
$built   = [];
foreach ( $pretend as $family ) {
	foreach ( get_post_type_capabilities( (object) [ 'capability_type' => $family, 'map_meta_cap' => true, 'capabilities' => [] ] ) as $asked_for => $granted_by ) {
		if ( in_array( $asked_for, [ 'edit_post', 'read_post', 'delete_post' ], true ) ) { continue; }
		$built[] = $granted_by;
	}
}
$built = array_values( array_unique( $built ) );
check( count( $built ) === 31, 'one more family adds exactly 10 capabilities (3 shared are added separately), got ' . ( count( $built ) - 21 ) );
check( in_array( 'edit_specimen_types', $built, true ), 'the plural is worked out by WordPress, not typed by hand' );
check( in_array( 'delete_others_specimen_types', $built, true ), 'including the awkward ones' );
check( ! in_array( 'edit_specimen_types', $caps, true ), 'and the real list did NOT change — nothing speculative is granted' );

echo "--- the layout switch ---\n";
check( method_exists( 'GLC_Roles', 'layout_permission' ), 'the switch exists' );
$granted = GLC_Roles::layout_permission( [], 'edit_theme_options', [], null );
check( empty( $granted['edit_theme_options'] ), 'nothing is granted to a non-user' );

echo "\n" . ( $failures ? "FAILED: $failures check(s)\n" : "All checks passed.\n" );
exit( $failures ? 1 : 0 );
