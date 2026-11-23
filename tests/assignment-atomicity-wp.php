<?php
/** Local-only assignment rollback regression using the public assignment interface. */
if ( ! defined( 'WP_CLI' ) || 'production' === wp_get_environment_type() ) {
	exit( "Local WP-CLI only.\n" );
}
global $wpdb;
$cars = [];
$partner = wp_insert_user( [ 'user_login' => 'assignment-audit-' . wp_generate_uuid4(), 'user_pass' => wp_generate_password( 32 ), 'role' => GLC_Partners::ROLE ] );
$failure = null;
try {
	foreach ( [ 'A', 'B' ] as $name ) {
		$cars[] = wp_insert_post( [ 'post_type' => 'car', 'post_status' => 'draft', 'post_title' => 'Assignment audit ' . $name ] );
	}
	$seen = 0;
	$refuse_log = static function ( $empty, $post ) use ( &$seen ) {
		return GLC_Change_Log::POST_TYPE === ( $post['post_type'] ?? '' ) && 2 === ++$seen ? true : $empty;
	};
	add_filter( 'wp_insert_post_empty_content', $refuse_log, 10, 2 );
	$result = GLC_Partners::assign( array_fill_keys( $cars, (int) $partner ) );
	remove_filter( 'wp_insert_post_empty_content', $refuse_log, 10 );
	$intact = is_wp_error( $result );
	foreach ( $cars as $id ) {
		wp_cache_delete( $id, 'post_meta' );
		$intact = $intact && 0 === GLC_Partners::partner_of( $id );
		$intact = $intact && 0 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = 'glc_car_id' AND meta_value = %d AND post_id IN (SELECT ID FROM {$wpdb->posts} WHERE post_type = %s)", $id, GLC_Change_Log::POST_TYPE ) );
	}
	if ( ! $intact ) {
		$failure = 'Assignment or change-log failure must roll back every car and every log entry.';
	}
	if ( ! $failure ) {
		$saved = GLC_Partners::assign( array_fill_keys( $cars, (int) $partner ), '', array_fill_keys( $cars, 0 ) );
		$again = GLC_Partners::assign( array_fill_keys( $cars, (int) $partner ) );
		$stale = GLC_Partners::assign( array_fill_keys( $cars, 0 ), '', array_fill_keys( $cars, 0 ) );
		if ( $cars !== $saved || [] !== $again || ! is_wp_error( $stale ) || 409 !== $stale->get_error_data()['status'] ) {
			$failure = 'Valid assignments must save, no-ops must stay empty, and stale assignments must be refused.';
		}
		$refuse_meta = static function ( $check, $id, $key ) use ( $cars ) {
			return $cars[1] === (int) $id && GLC_Partners::META_PARTNER === $key ? false : $check;
		};
		add_filter( 'delete_post_metadata', $refuse_meta, 10, 3 );
		$failed = GLC_Partners::assign( array_fill_keys( $cars, 0 ) );
		remove_filter( 'delete_post_metadata', $refuse_meta, 10 );
		foreach ( $cars as $id ) {
			wp_cache_delete( $id, 'post_meta' );
			if ( ! is_wp_error( $failed ) || (int) $partner !== GLC_Partners::partner_of( $id ) ) {
				$failure = 'A refused metadata write must leave every assignment unchanged.';
			}
		}
	}
} finally {
	foreach ( $cars as $id ) {
		foreach ( get_posts( [ 'post_type' => GLC_Change_Log::POST_TYPE, 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids', 'meta_key' => 'glc_car_id', 'meta_value' => $id ] ) as $log ) {
			wp_delete_post( $log, true );
		}
		wp_delete_post( $id, true );
	}
	require_once ABSPATH . 'wp-admin/includes/user.php';
	wp_delete_user( (int) $partner );
}
if ( $failure ) {
	WP_CLI::error( $failure );
}
WP_CLI::success( 'Assignment rollback preserves every car and its log.' );
