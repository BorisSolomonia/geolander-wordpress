<?php
/**
 * Retire the 2026-11-06 intrusion: export the spam, remember its addresses, then remove it.
 *
 * The posts are already drafts and the 14 accounts are already subscribers, so
 * nothing here is urgent. What it adds is permanence, and one thing drafting
 * could not do: it RECORDS EVERY SLUG in the `glc_gone_slugs` option before
 * deleting, so GLC_Security can answer 410 Gone instead of 404 for exactly
 * those addresses. Google treats 410 as "this is never coming back" and drops
 * the URL in days rather than weeks.
 *
 * Order matters: export, then remember, then delete. A deletion that happens
 * before the export leaves no evidence, and one that happens before the slugs
 * are recorded leaves 404s that take a month to clear.
 *
 *   wp eval-file /migration/retire-spam.php export   --allow-root   > evidence.json
 *   wp eval-file /migration/retire-spam.php dry-run  --allow-root
 *   wp eval-file /migration/retire-spam.php apply    --allow-root
 *
 * Positional words, not --flags: wp-cli claims flags for itself.
 * Re-runnable. `apply` twice is harmless; the second run finds nothing.
 */

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	exit( 1 );
}

$mode = '';
foreach ( (array) ( $args ?? [] ) as $candidate ) {
	if ( in_array( $candidate, [ 'export', 'dry-run', 'apply' ], true ) ) {
		$mode = $candidate;
	}
}
if ( '' === $mode ) {
	WP_CLI::error( 'Say which: export | dry-run | apply' );
}

/** Everything of type `post`. This site publishes its own news as `glc_news`, so `post` is entirely the intruder's. */
$posts = get_posts( [
	'post_type'        => 'post',
	'post_status'      => 'any',
	'numberposts'      => -1,
	'suppress_filters' => true,
] );

/** The accounts the intrusion created: administrators then, subscribers now, never authors of anything of yours. */
$keep     = [ 1, 2, 3, 4 ];
$intruder = [];
foreach ( get_users( [ 'fields' => [ 'ID', 'user_login', 'user_email', 'user_registered' ] ] ) as $user ) {
	if ( ! in_array( (int) $user->ID, $keep, true ) ) {
		$intruder[] = $user;
	}
}

if ( 'export' === $mode ) {
	$dump = [ 'taken_at' => gmdate( 'c' ), 'posts' => [], 'accounts' => [] ];
	foreach ( $posts as $post ) {
		$dump['posts'][] = [
			'ID'      => (int) $post->ID,
			'date'    => $post->post_date,
			'status'  => $post->post_status,
			'author'  => (int) $post->post_author,
			'slug'    => $post->post_name,
			'title'   => $post->post_title,
			'content' => $post->post_content,
		];
	}
	foreach ( $intruder as $user ) {
		$dump['accounts'][] = [
			'ID'         => (int) $user->ID,
			'login'      => $user->user_login,
			'email'      => $user->user_email,
			'registered' => $user->user_registered,
		];
	}
	echo wp_json_encode( $dump, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
	return;
}

/** The addresses to answer 410 for. One slug serves every locale, because the locale is only a prefix. */
$slugs = [];
foreach ( $posts as $post ) {
	if ( '' !== (string) $post->post_name ) {
		$slugs[] = (string) $post->post_name;
	}
}
$slugs = array_values( array_unique( $slugs ) );

if ( 'dry-run' === $mode ) {
	WP_CLI::log( sprintf( 'Would delete %d posts and %d accounts, and remember %d slugs as Gone.', count( $posts ), count( $intruder ), count( $slugs ) ) );
	foreach ( array_slice( $intruder, 0, 20 ) as $user ) {
		WP_CLI::log( sprintf( '  account %d %s <%s> registered %s', $user->ID, $user->user_login, $user->user_email, $user->user_registered ) );
	}
	WP_CLI::log( '  first slugs: ' . implode( ', ', array_slice( $slugs, 0, 5 ) ) );
	WP_CLI::success( 'Nothing was written.' );
	return;
}

// apply ---------------------------------------------------------------------

$known = get_option( 'glc_gone_slugs', [] );
$known = is_array( $known ) ? $known : [];
$all   = array_values( array_unique( array_merge( $known, $slugs ) ) );
update_option( 'glc_gone_slugs', $all, true );
WP_CLI::log( sprintf( 'Remembered %d slugs as Gone (%d new).', count( $all ), count( $all ) - count( $known ) ) );

require_once ABSPATH . 'wp-admin/includes/user.php';

$deleted_posts = 0;
foreach ( $posts as $post ) {
	if ( wp_delete_post( (int) $post->ID, true ) ) {
		++$deleted_posts;
	}
}
WP_CLI::log( sprintf( 'Deleted %d posts.', $deleted_posts ) );

$deleted_users = 0;
foreach ( $intruder as $user ) {
	if ( wp_delete_user( (int) $user->ID ) ) {
		++$deleted_users;
	}
}
WP_CLI::log( sprintf( 'Deleted %d accounts.', $deleted_users ) );

WP_CLI::log( sprintf( 'Remaining: %d posts, %d accounts, %d administrators.', (int) wp_count_posts( 'post' )->publish + (int) wp_count_posts( 'post' )->draft, count( get_users( [ 'fields' => 'ID' ] ) ), count( get_users( [ 'role' => 'administrator', 'fields' => 'ID' ] ) ) ) );
WP_CLI::success( 'Done. Your pages, cars, cities and places were never touched.' );
