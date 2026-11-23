<?php
/**
 * Replace exact pieces of stored text (page content, excerpts, post meta) from a
 * reviewed list, when a business fact changes and seeded content has to follow.
 *
 * Run (dry run first — `dry-run` is positional, wp-cli reads dashed words as its own flags):
 *   wp eval-file /migration/apply-text-replacements.php dry-run --allow-root
 *   wp eval-file /migration/apply-text-replacements.php --allow-root
 *
 * Input: /migration/text-replacements.json
 *   { "name": "delivery-fees-2026-10", "reason": "…", "items": [
 *       { "post": 281, "slug": "batumi", "field": "post_excerpt",           "find": "…", "replace": "…" },
 *       { "post": 281, "slug": "batumi", "field": "meta:glc_delivery_note", "find": "…", "replace": "…", "count": 1 } ] }
 *
 * WHY EXACT TEXT AND NOT A PATTERN: every changed sentence is public copy in one
 * of seven languages. A pattern can rewrite a sentence nobody looked at. Here each
 * replacement names the row, the old text and the new text, so the list can be
 * read before it runs and the run changes nothing that is not on it.
 *
 * Optional `"settings": { "kutaisi_each_way": "80", … }`: values written into the
 * `glc_settings` option, so the number of record lives in the database (editable in
 * Settings → Geolander) and a later change to a code default cannot move a live
 * price. A key that is already stored is left alone and reported: the admin's
 * value wins over the list.
 *
 * ORDER MATTERS: run this AFTER the code that understands the new text is live.
 * A list that introduces a shortcode would print it raw under the old code.
 *
 * SAFETY, in order:
 *  - Everything is checked before anything is written: the post exists, its slug
 *    is the one the list expects, and `find` occurs exactly `count` times (default
 *    1) in the current text. One failed check aborts the whole run.
 *  - The first time a field is touched under a given list name, its original value
 *    is stored on the post as `_glc_text_prev_<name>` (a JSON map field → value).
 *    A second run never overwrites that copy, so the original always survives.
 *  - An item whose `find` is already gone and whose `replace` is already present is
 *    reported as "already applied" and skipped, so the script can be re-run.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit( "Run via wp eval-file\n" );
}

const GLC_TEXT_INPUT = '/migration/text-replacements.json';

$glc_dry = in_array( 'dry-run', (array) ( $args ?? [] ), true );
if ( ! file_exists( GLC_TEXT_INPUT ) ) {
	WP_CLI::error( 'Missing ' . GLC_TEXT_INPUT );
}
$glc_list = json_decode( (string) file_get_contents( GLC_TEXT_INPUT ), true );
if ( ! is_array( $glc_list ) || empty( $glc_list['items'] ) || empty( $glc_list['name'] ) ) {
	WP_CLI::error( 'Input is not a readable replacement list (needs "name" and "items").' );
}
$glc_name = sanitize_key( (string) $glc_list['name'] );
$glc_prev = '_glc_text_prev_' . $glc_name;

WP_CLI::log( sprintf( 'Replacement list "%s": %d items.%s', $glc_name, count( $glc_list['items'] ), $glc_dry ? '  DRY RUN — nothing will be written.' : '' ) );

/** Read one field of a post: a column, or `meta:<key>`. */
$glc_read = static function ( WP_Post $post, string $field ) {
	if ( 0 === strpos( $field, 'meta:' ) ) {
		return (string) get_post_meta( $post->ID, substr( $field, 5 ), true );
	}
	return in_array( $field, [ 'post_content', 'post_excerpt', 'post_title' ], true ) ? (string) $post->$field : null;
};

/* ------------------------------------------------------------ validate all */

$glc_plan   = [];   // post id → field → new value
$glc_before = [];   // post id → field → original value
$glc_errors = [];
$glc_done   = 0;

foreach ( $glc_list['items'] as $n => $item ) {
	$id    = (int) ( $item['post'] ?? 0 );
	$field = (string) ( $item['field'] ?? '' );
	$find  = (string) ( $item['find'] ?? '' );
	$repl  = (string) ( $item['replace'] ?? '' );
	$count = (int) ( $item['count'] ?? 1 );
	$tag   = '#' . ( $n + 1 ) . " post {$id} {$field}";
	$post  = $id ? get_post( $id ) : null;

	if ( ! $post ) {
		$glc_errors[] = "{$tag}: no such post";
		continue;
	}
	if ( isset( $item['slug'] ) && $post->post_name !== $item['slug'] ) {
		$glc_errors[] = "{$tag}: slug is '{$post->post_name}', the list expects '{$item['slug']}' — refusing to edit the wrong page";
		continue;
	}
	if ( '' === $find || $find === $repl ) {
		$glc_errors[] = "{$tag}: empty or no-op replacement";
		continue;
	}
	$current = $glc_plan[ $id ][ $field ] ?? $glc_read( $post, $field );
	if ( null === $current ) {
		$glc_errors[] = "{$tag}: unknown field";
		continue;
	}
	$seen = substr_count( $current, $find );
	if ( 0 === $seen && '' !== $repl && false !== strpos( $current, $repl ) ) {
		WP_CLI::log( "  = {$tag}: already applied" );
		$glc_done++;
		continue;
	}
	if ( $seen !== $count ) {
		$glc_errors[] = "{$tag}: expected the old text {$count}×, found {$seen}×";
		continue;
	}
	if ( ! isset( $glc_before[ $id ][ $field ] ) ) {
		$glc_before[ $id ][ $field ] = $glc_read( $post, $field );
	}
	$glc_plan[ $id ][ $field ] = str_replace( $find, $repl, $current );
	WP_CLI::log( sprintf( "  %s %s (%s/%s)\n      − %s\n      + %s", $glc_dry ? '·' : '✓', $tag, $post->post_type, $post->post_name, wp_strip_all_tags( $find ), wp_strip_all_tags( $repl ) ) );
}

if ( $glc_errors ) {
	foreach ( $glc_errors as $e ) {
		WP_CLI::log( '  ✗ ' . $e );
	}
	WP_CLI::error( count( $glc_errors ) . ' check(s) failed. Nothing was written.' );
}
/* ---------------------------------------------------------------- settings */

$glc_seed = [];
if ( ! empty( $glc_list['settings'] ) && is_array( $glc_list['settings'] ) ) {
	$glc_stored = get_option( 'glc_settings', [] );
	$glc_stored = is_array( $glc_stored ) ? $glc_stored : [];
	foreach ( $glc_list['settings'] as $key => $value ) {
		$key = sanitize_key( (string) $key );
		if ( ! is_numeric( $value ) || (float) $value < 0 ) {
			WP_CLI::error( "settings.{$key}: '{$value}' is not a non-negative number. Nothing was written." );
		}
		if ( array_key_exists( $key, $glc_stored ) ) {
			WP_CLI::log( "  = setting {$key}: already stored as '{$glc_stored[ $key ]}' — left alone" . ( (string) $glc_stored[ $key ] !== (string) $value ? " (the list says '{$value}')" : '' ) );
			continue;
		}
		$glc_seed[ $key ] = (string) $value;
		WP_CLI::log( sprintf( "  %s setting %s: not stored (code default in use) → store '%s'", $glc_dry ? '·' : '✓', $key, $value ) );
	}
}

if ( $glc_dry ) {
	WP_CLI::success( sprintf( 'Dry run complete: %d field(s) on %d post(s) would change, %d setting(s) would be stored, %d item(s) already applied.', array_sum( array_map( 'count', $glc_plan ) ), count( $glc_plan ), count( $glc_seed ), $glc_done ) );
	return;
}

if ( $glc_seed ) {
	update_option( 'glc_settings', array_merge( $glc_stored, $glc_seed ) );
}

/* --------------------------------------------------------------- apply all */

$glc_fields = 0;
foreach ( $glc_plan as $id => $fields ) {
	$kept = json_decode( (string) get_post_meta( $id, $glc_prev, true ), true );
	$kept = is_array( $kept ) ? $kept : [];
	foreach ( $glc_before[ $id ] as $field => $original ) {
		if ( ! array_key_exists( $field, $kept ) ) {
			$kept[ $field ] = $original;
		}
	}
	// wp_slash: update_post_meta and wp_update_post both unslash what they are given.
	update_post_meta( $id, $glc_prev, wp_slash( wp_json_encode( $kept, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) ) );

	$columns = [];
	foreach ( $fields as $field => $value ) {
		if ( 0 === strpos( $field, 'meta:' ) ) {
			update_post_meta( $id, substr( $field, 5 ), wp_slash( $value ) );
		} else {
			$columns[ $field ] = $value;
		}
		$glc_fields++;
	}
	if ( $columns ) {
		$result = wp_update_post( wp_slash( array_merge( [ 'ID' => $id ], $columns ) ), true );
		if ( is_wp_error( $result ) ) {
			WP_CLI::error( "post {$id}: " . $result->get_error_message() . ' — earlier posts in this run are already written; originals are in ' . $glc_prev );
		}
	}
}

WP_CLI::success( sprintf( '%d field(s) on %d post(s) rewritten. Originals are on each post as `%s`.', $glc_fields, count( $glc_plan ), $glc_prev ) );
WP_CLI::log( 'Restore one field:  wp eval \'$p=json_decode(get_post_meta(ID,"' . $glc_prev . '",true),true); wp_update_post(wp_slash(["ID"=>ID,"post_content"=>$p["post_content"]]));\' --allow-root' );
