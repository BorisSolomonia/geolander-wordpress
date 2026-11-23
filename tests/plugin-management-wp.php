<?php
/** Real WordPress capability mapping under production file-modification defaults. */
if ( ! defined( 'WP_CLI' ) || 'production' === wp_get_environment_type() ) {
	exit( "Local WP-CLI only.\n" );
}
defined( 'DISALLOW_FILE_MODS' ) || define( 'DISALLOW_FILE_MODS', true );
defined( 'DISALLOW_FILE_EDIT' ) || define( 'DISALLOW_FILE_EDIT', true );
$original = getenv( 'GLC_ALLOW_PLUGIN_MANAGEMENT' );
$before_user = get_current_user_id();
$users = [];
$failures = [];
$check = static function ( bool $ok, string $label ) use ( &$failures ) {
	WP_CLI::log( ( $ok ? 'PASS ' : 'FAIL ' ) . $label );
	if ( ! $ok ) { $failures[] = $label; }
};
add_filter( 'pre_wp_mail', '__return_true' );
try {
	putenv( 'GLC_ALLOW_PLUGIN_MANAGEMENT=1' );
	foreach ( [ 'ordinary', 'manager' ] as $kind ) {
		$users[ $kind ] = wp_insert_user( [ 'user_login' => 'plugin-audit-' . $kind . '-' . wp_generate_uuid4(), 'user_pass' => wp_generate_password( 32 ), 'role' => GLC_Roles::ROLE ] );
		if ( is_wp_error( $users[ $kind ] ) ) { WP_CLI::error( 'Could not create local fixture account.' ); }
	}
	$manager = get_userdata( $users['manager'] );
	$manager->add_cap( GLC_Plugin_Management::CAP );
	wp_set_current_user( $users['ordinary'] );
	$check( ! current_user_can( 'install_plugins' ) && ! current_user_can( 'activate_plugins' ), 'Ordinary content editor gains no plugin access' );
	wp_set_current_user( $users['manager'] );
	foreach ( [ 'install_plugins', 'upload_plugins', 'activate_plugins', 'update_plugins', 'delete_plugins' ] as $cap ) {
		$check( current_user_can( $cap ), 'Explicit plugin manager can ' . $cap );
	}
	foreach ( [ 'manage_options', 'create_users', 'promote_users', 'edit_plugins', 'edit_themes', 'install_themes', 'update_core', 'glc_partner_panel', 'glc_manage_partners' ] as $cap ) {
		$check( ! current_user_can( $cap ), 'Plugin access does not grant ' . $cap );
	}
	$check( wp_is_file_mod_allowed( 'capability_install_plugins' ) && ! wp_is_file_mod_allowed( 'capability_update_core' ), 'Production file-modification default opens only plugin contexts' );
	$check( 'direct' === apply_filters( 'filesystem_method', 'ftpext' ), 'Manager uses writable plugin storage without an FTP prompt' );
	$check( ! current_user_can( 'deactivate_plugin', GLC_Plugin_Management::CORE_PLUGIN ), 'Image-managed Geolander Core cannot be deactivated' );
	$check( is_wp_error( apply_filters( 'upgrader_pre_install', true, [ 'plugin' => GLC_Plugin_Management::CORE_PLUGIN ] ) ), 'Image-managed Geolander Core cannot be overwritten by the updater' );
	$check( true === apply_filters( 'upgrader_pre_install', true, [ 'plugin' => 'any-plugin/plugin.php' ] ), 'No third-party plugin allowlist is imposed' );
	putenv( 'GLC_ALLOW_PLUGIN_MANAGEMENT=0' );
	$check( ! current_user_can( 'install_plugins' ), 'Host opt-in can close plugin installation again' );
} finally {
	require_once ABSPATH . 'wp-admin/includes/user.php';
	wp_set_current_user( $before_user );
	foreach ( $users as $id ) { if ( is_int( $id ) ) { wp_delete_user( $id ); } }
	false === $original ? putenv( 'GLC_ALLOW_PLUGIN_MANAGEMENT' ) : putenv( 'GLC_ALLOW_PLUGIN_MANAGEMENT=' . $original );
}
if ( $failures ) { WP_CLI::error( count( $failures ) . ' plugin permission checks failed.' ); }
WP_CLI::success( 'Scoped plugin management passed under WordPress file hardening.' );
