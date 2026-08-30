<?php
/**
 * Immutable-container WordPress defaults.
 *
 * Loaded through PHP auto_prepend_file, before wp-config.php. Content editors
 * can still edit posts and upload media; executable code changes require a
 * reviewed image rebuild. Request-driven cron is disabled so arbitrary web
 * traffic cannot start background update/network work inside PHP workers.
 */

if ( '1' === getenv( 'GLC_SKIP_WORDPRESS_HARDENING' ) ) {
	return;
}

defined( 'DISALLOW_FILE_EDIT' ) || define( 'DISALLOW_FILE_EDIT', true );
defined( 'DISALLOW_FILE_MODS' ) || define( 'DISALLOW_FILE_MODS', true );
defined( 'AUTOMATIC_UPDATER_DISABLED' ) || define( 'AUTOMATIC_UPDATER_DISABLED', true );
defined( 'DISABLE_WP_CRON' ) || define( 'DISABLE_WP_CRON', true );
defined( 'WP_MEMORY_LIMIT' ) || define( 'WP_MEMORY_LIMIT', '96M' );
defined( 'WP_MAX_MEMORY_LIMIT' ) || define( 'WP_MAX_MEMORY_LIMIT', '128M' );
defined( 'EMPTY_TRASH_DAYS' ) || define( 'EMPTY_TRASH_DAYS', 7 );
