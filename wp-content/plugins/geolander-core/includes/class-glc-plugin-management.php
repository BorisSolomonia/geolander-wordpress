<?php
/** Opt-in plugin administration, separate from content and account administration. */
defined( 'ABSPATH' ) || exit;

class GLC_Plugin_Management {
	public const CAP = 'glc_manage_plugins';
	public const CORE_PLUGIN = 'geolander-core/geolander-core.php';
	private const CAPS = [ 'install_plugins', 'upload_plugins', 'activate_plugins', 'update_plugins', 'delete_plugins' ];
	private const CONTEXTS = [ 'capability_install_plugins', 'capability_update_plugins', 'capability_delete_plugins' ];

	public static function init(): void {
		add_filter( 'user_has_cap', [ __CLASS__, 'capabilities' ], 20, 4 );
		add_filter( 'file_mod_allowed', [ __CLASS__, 'file_modifications' ], 20, 2 );
		add_filter( 'filesystem_method', [ __CLASS__, 'filesystem_method' ], 20 );
		add_filter( 'map_meta_cap', [ __CLASS__, 'map_capabilities' ], 20, 4 );
		add_filter( 'upgrader_pre_install', [ __CLASS__, 'protect_core_update' ], 20, 2 );
	}

	private static function enabled(): bool {
		return '1' === getenv( 'GLC_ALLOW_PLUGIN_MANAGEMENT' );
	}

	private static function trusted( array $caps ): bool {
		return ! empty( $caps[ self::CAP ] ) || ! empty( $caps['manage_options'] );
	}

	public static function capabilities( $caps, $required, $args, $user ) {
		if ( self::enabled() && self::trusted( $caps ) ) {
			foreach ( self::CAPS as $cap ) {
				$caps[ $cap ] = true;
			}
		}
		return $caps;
	}

	public static function file_modifications( $allowed, $context ): bool {
		if ( ! self::enabled() || ! in_array( $context, self::CONTEXTS, true ) ) {
			return (bool) $allowed;
		}
		return current_user_can( self::CAP ) || current_user_can( 'manage_options' );
	}

	public static function filesystem_method( $method ): string {
		// The image owns WordPress, so core's owner comparison otherwise asks for
		// FTP even though the persistent plugin and staging directories are writable.
		return self::enabled() && ( current_user_can( self::CAP ) || current_user_can( 'manage_options' ) ) ? 'direct' : (string) $method;
	}

	public static function map_capabilities( $caps, $cap, $user_id, $args ): array {
		if ( self::enabled() && 'deactivate_plugin' === $cap && self::CORE_PLUGIN === ( $args[0] ?? '' ) ) {
			return [ 'do_not_allow' ];
		}
		// Core maps ALL code-install operations through capability_update_core,
		// including plugin installs. Keep that shared context closed and remap
		// only the five plugin operations for explicitly trusted accounts.
		if ( self::enabled() && in_array( $cap, self::CAPS, true ) ) {
			$user = get_userdata( (int) $user_id );
			if ( $user && self::trusted( $user->allcaps ) && ( ! is_multisite() || is_super_admin( $user_id ) ) ) {
				return [ 'upload_plugins' === $cap ? 'install_plugins' : $cap ];
			}
		}
		return (array) $caps;
	}

	public static function protect_core_update( $response, $extra ) {
		if ( self::CORE_PLUGIN === ( $extra['plugin'] ?? '' ) ) {
			return new WP_Error( 'glc_image_plugin', __( 'Sorry, you are not allowed to update plugins for this site.' ) );
		}
		return $response;
	}
}
