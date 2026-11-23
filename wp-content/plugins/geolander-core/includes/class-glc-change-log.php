<?php
/**
 * The change log: who changed what on a car, with the full state before and after.
 *
 * One private post per change. Post revisions would not do (they follow the
 * posts table, and everything a partner changes is meta), and a table of our
 * own would need its own admin screen. An entry is written once and never
 * edited or deleted; putting an old state back writes a new entry, so the log
 * only ever grows.
 *
 * Only the site's owner sees the list (capability `glc_manage_partners`). It is
 * not public, not in REST and not in a sitemap. A partner reads the history of
 * a car they look after on that car's own screen, which asks can_manage()
 * first; WordPress itself never shows them an entry, not even one they wrote.
 */

defined( 'ABSPATH' ) || exit;

class GLC_Change_Log {

	public const POST_TYPE = 'glc_change';

	/** What an entry can be about. The store writes pricing and fees; the other kinds arrive with their screens. */
	public const KINDS = [ 'assignment', 'pricing', 'fees', 'photos', 'specs', 'profile' ];

	/** Where a change came from. */
	public const SOURCES = [ 'panel', 'bulk', 'excel', 'cli', 'metabox', 'restore' ];

	public static function init(): void {
		add_action( 'init', [ __CLASS__, 'register' ] );
		add_filter( 'bulk_actions-edit-' . self::POST_TYPE, '__return_empty_array' );
		add_filter( 'map_meta_cap', [ __CLASS__, 'close_entries' ], 10, 4 );
	}

	/**
	 * What WordPress may do with one entry: holders of `glc_manage_partners` read
	 * it, and nobody edits or deletes it. The post type's capabilities are not
	 * enough on their own: WordPress lets an author edit and read their own
	 * private post whatever those say, and the author of an entry is whoever
	 * saved the change, a partner or the owner.
	 */
	public static function close_entries( $caps, $cap, $user_id, $args ) {
		if ( ! in_array( $cap, [ 'read_post', 'edit_post', 'delete_post' ], true ) || empty( $args[0] ) ) {
			return $caps;
		}
		$post = get_post( (int) $args[0] );
		if ( ! $post || self::POST_TYPE !== $post->post_type ) {
			return $caps;
		}
		return 'read_post' === $cap ? [ GLC_Partners::CAP_MANAGE ] : [ 'do_not_allow' ];
	}

	public static function register(): void {
		$read = GLC_Partners::CAP_MANAGE;
		register_post_type( self::POST_TYPE, [
			'labels'              => [
				'name'          => glc_admin_t( 'log_menu' ),
				'singular_name' => glc_admin_t( 'log_singular' ),
				'all_items'     => glc_admin_t( 'log_menu' ),
			],
			'public'              => false,
			'show_ui'             => true,
			'show_in_menu'        => 'edit.php?post_type=car',
			'show_in_rest'        => false,
			'show_in_nav_menus'   => false,
			'show_in_admin_bar'   => false,
			'publicly_queryable'  => false,
			'exclude_from_search' => true,
			'rewrite'             => false,
			'query_var'           => false,
			'supports'            => [ 'title' ],
			// WordPress deletes a deleted user's posts. An entry outlives the account of whoever wrote it.
			'delete_with_user'    => false,
			'capability_type'     => self::POST_TYPE,
			'map_meta_cap'        => true,
			// Reading the list is the owner's; nobody creates, edits or deletes an entry by hand.
			'capabilities'        => [
				'edit_posts'             => $read,
				'edit_others_posts'      => $read,
				'read_private_posts'     => $read,
				'create_posts'           => 'do_not_allow',
				'publish_posts'          => 'do_not_allow',
				'edit_private_posts'     => 'do_not_allow',
				'edit_published_posts'   => 'do_not_allow',
				'delete_posts'           => 'do_not_allow',
				'delete_others_posts'    => 'do_not_allow',
				'delete_private_posts'   => 'do_not_allow',
				'delete_published_posts' => 'do_not_allow',
			],
		] );
	}

	/**
	 * Write one entry.
	 *
	 * @param array $entry car_id (0 when the change is not about one car), kind, source, batch (uuid
	 *                     shared by every entry of one apply), before, after, note, and under WP-CLI
	 *                     only: actor (user id, 0 = the shell). Anywhere else the actor is whoever
	 *                     is signed in.
	 * @return int|WP_Error the entry's id
	 */
	public static function record( array $entry ) {
		$kind   = (string) ( $entry['kind'] ?? '' );
		$source = (string) ( $entry['source'] ?? '' );
		if ( ! in_array( $kind, self::KINDS, true ) || ! in_array( $source, self::SOURCES, true ) ) {
			return new WP_Error( 'glc_log_entry', glc_admin_t( 'err_log_entry' ), [ 'status' => 400 ] );
		}
		$car_id = (int) ( $entry['car_id'] ?? 0 );
		$actor  = defined( 'WP_CLI' ) && WP_CLI ? (int) ( $entry['actor'] ?? get_current_user_id() ) : (int) get_current_user_id();

		// The title is part of the record, so it is always written in English, whoever is saving.
		// Its time is the one people who run the cars read (the display timezone), not the site's UTC.
		$id = wp_insert_post( wp_slash( [
			'post_type'   => self::POST_TYPE,
			'post_status' => 'private',
			'post_author' => $actor,
			'post_title'  => sprintf( glc_admin_t( 'log_title', GLC_Admin_Strings::DEFAULT_LANG ), $car_id, $kind, self::actor_name( $actor, GLC_Admin_Strings::DEFAULT_LANG ), GLC_Settings::local_time( time() ) ),
		] ), true );
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		$meta = [
			'glc_car_id' => $car_id,
			'glc_actor'  => $actor,
			'glc_kind'   => $kind,
			'glc_source' => $source,
			'glc_batch'  => (string) ( $entry['batch'] ?? '' ),
			'glc_before' => wp_json_encode( $entry['before'] ?? null, JSON_PRESERVE_ZERO_FRACTION ),
			'glc_after'  => wp_json_encode( $entry['after'] ?? null, JSON_PRESERVE_ZERO_FRACTION ),
			'glc_note'   => (string) ( $entry['note'] ?? '' ),
		];
		foreach ( $meta as $key => $value ) {
			// update_post_meta() strips slashes; JSON carries them, so they are doubled first.
			update_post_meta( $id, $key, wp_slash( $value ) );
		}
		return (int) $id;
	}

	/** One entry, decoded; null when there is no such entry. */
	public static function get( int $change_id ): ?array {
		$post = get_post( $change_id );
		if ( ! $post || self::POST_TYPE !== $post->post_type ) {
			return null;
		}
		$actor = (int) get_post_meta( $post->ID, 'glc_actor', true );
		return [
			'id'         => (int) $post->ID,
			'car_id'     => (int) get_post_meta( $post->ID, 'glc_car_id', true ),
			'actor'      => $actor,
			'actor_name' => self::actor_label( $actor ),
			'kind'       => (string) get_post_meta( $post->ID, 'glc_kind', true ),
			'source'     => (string) get_post_meta( $post->ID, 'glc_source', true ),
			'batch'      => (string) get_post_meta( $post->ID, 'glc_batch', true ),
			'note'       => (string) get_post_meta( $post->ID, 'glc_note', true ),
			'before'     => json_decode( (string) get_post_meta( $post->ID, 'glc_before', true ), true ),
			'after'      => json_decode( (string) get_post_meta( $post->ID, 'glc_after', true ), true ),
			'time'       => GLC_Settings::local_time( $post->post_date_gmt ),
		];
	}

	/**
	 * Entries, newest first.
	 *
	 * @param array $filter car_id, kinds (list), batch, limit; all optional
	 */
	public static function entries( array $filter = [] ): array {
		$meta = [];
		if ( isset( $filter['car_id'] ) ) {
			$meta[] = [ 'key' => 'glc_car_id', 'value' => (int) $filter['car_id'] ];
		}
		if ( ! empty( $filter['kinds'] ) ) {
			$meta[] = [ 'key' => 'glc_kind', 'value' => array_map( 'strval', (array) $filter['kinds'] ), 'compare' => 'IN' ];
		}
		if ( ! empty( $filter['batch'] ) ) {
			$meta[] = [ 'key' => 'glc_batch', 'value' => (string) $filter['batch'] ];
		}
		$ids = get_posts( [
			'post_type'      => self::POST_TYPE,
			'post_status'    => 'private',
			'posts_per_page' => isset( $filter['limit'] ) ? (int) $filter['limit'] : -1,
			'orderby'        => [ 'date' => 'DESC', 'ID' => 'DESC' ],
			'fields'         => 'ids',
			'no_found_rows'  => true,
			'meta_query'     => $meta,
		] );
		return array_values( array_filter( array_map( [ __CLASS__, 'get' ], array_map( 'intval', $ids ) ) ) );
	}

	/** The most recent entry for a car, optionally only of some kinds. */
	public static function latest( int $car_id, array $kinds = [] ): ?array {
		return self::entries( [ 'car_id' => $car_id, 'kinds' => $kinds, 'limit' => 1 ] )[0] ?? null;
	}

	/**
	 * Put back what an entry recorded as "before". It goes through the store like
	 * any other save and is logged as a new entry; the old entries stay as they
	 * are. The price limits and the season-count limit are not applied (the state
	 * was live once); a table must still have a season and cover every day once.
	 *
	 * @param array $ctx rev (the car's revision on the confirm screen), batch; under WP-CLI also actor
	 * @return array|WP_Error the store's result
	 */
	public static function restore( int $change_id, array $ctx = [] ) {
		$entry = self::get( $change_id );
		if ( ! $entry ) {
			return new WP_Error( 'glc_restore_missing', sprintf( glc_admin_t( 'err_restore_missing' ), $change_id ), [ 'status' => 404 ] );
		}
		if ( ! in_array( $entry['kind'], GLC_Price_Store::KINDS, true ) ) {
			return new WP_Error( 'glc_restore_kind', sprintf( glc_admin_t( 'err_restore_kind' ), $entry['kind'] ), [ 'status' => 400 ] );
		}
		if ( ! is_array( $entry['before'] ) ) {
			return new WP_Error( 'glc_restore_empty', sprintf( glc_admin_t( 'err_restore_empty' ), $change_id ), [ 'status' => 400 ] );
		}
		return GLC_Price_Store::write(
			$entry['car_id'],
			[ $entry['kind'] => $entry['before'] ],
			[
				'source' => 'restore',
				'note'   => sprintf( glc_admin_t( 'log_note_restore', GLC_Admin_Strings::DEFAULT_LANG ), $change_id ),
			] + array_intersect_key( $ctx, [ 'rev' => true, 'actor' => true, 'batch' => true ] )
		);
	}

	/** A person's name for the log; the shell has no user, and an account may have been removed since. */
	public static function actor_name( int $actor, ?string $lang = null ): string {
		if ( ! $actor ) {
			return glc_admin_t( 'log_actor_cli', $lang );
		}
		$user = get_userdata( $actor );
		return $user ? (string) $user->display_name : sprintf( glc_admin_t( 'log_actor_unknown', $lang ), $actor );
	}

	/**
	 * Who made a change, as the person reading may know it. The owner (and a
	 * shell) reads the name. A partner reads a word for the part that person
	 * played: a car that moved from one partner to another carries the earlier
	 * partner's changes in its history, and neither that partner's name nor the
	 * owner's is the new partner's to learn. Every entry handed out by get()
	 * goes through here, so a screen that prints `actor_name` cannot leak one.
	 */
	public static function actor_label( int $actor ): string {
		if ( ( defined( 'WP_CLI' ) && WP_CLI ) || current_user_can( GLC_Partners::CAP_MANAGE ) ) {
			return self::actor_name( $actor );
		}
		if ( $actor && get_current_user_id() === $actor ) {
			return glc_admin_t( 'log_who_you' );
		}
		// The shell is Geolander's, and so is anyone who may manage partners. Everyone else who ever saved this car had it before.
		return ! $actor || user_can( $actor, GLC_Partners::CAP_MANAGE ) ? glc_admin_t( 'log_who_owner' ) : glc_admin_t( 'log_who_partner' );
	}
}
