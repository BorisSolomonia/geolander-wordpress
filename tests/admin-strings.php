<?php
/**
 * Standalone checks for the admin string catalogues (includes/admin-strings/<lang>/*.php):
 * every language has the same files and the same keys, placeholders agree, and
 * every key the code asks for exists. No WordPress needed.
 *
 * Run: docker run --rm -v "<repo>:/src" php:8.3-cli php /src/tests/admin-strings.php
 */
define( 'ABSPATH', __DIR__ );
$failures  = 0;
$user_meta = '';
$locale    = 'en_US';
function check( $condition, $label ) {
	if ( ! $condition ) { ++$GLOBALS['failures']; }
	echo ( $condition ? 'PASS ' : 'FAIL ' ) . $label . "\n";
}
function get_current_user_id() { return 7; }
function get_user_meta( $id, $key, $single = false ) { return $GLOBALS['user_meta']; }
function get_user_locale( $id = 0 ) { return $GLOBALS['locale']; }
function get_option( $name, $default = false ) { return $default; } // nothing stored: the settings answer with their defaults
$includes = __DIR__ . '/../wp-content/plugins/geolander-core/includes';
require $includes . '/class-glc-admin-strings.php';
require $includes . '/class-glc-rental.php';
require $includes . '/class-glc-settings.php';
require $includes . '/class-glc-price-store.php';

$languages = GLC_Admin_Strings::languages();
check( in_array( 'en', $languages, true ) && in_array( 'ka', $languages, true ), 'English and Georgian catalogues exist' );

$files = [];
foreach ( $languages as $lang ) {
	$files[ $lang ] = array_map( 'basename', glob( $includes . '/admin-strings/' . $lang . '/*.php' ) );
	sort( $files[ $lang ] );
}
foreach ( $languages as $lang ) {
	check( $files[ $lang ] === $files['en'], 'Same files as English: ' . $lang );
	$seen = [];
	foreach ( $files[ $lang ] as $file ) {
		$strings = require $includes . '/admin-strings/' . $lang . '/' . $file;
		$english = require $includes . '/admin-strings/en/' . $file;
		check( array_keys( $strings ) === array_keys( $english ), "Same keys in the same order as English: {$lang}/{$file}" );
		check( ! array_filter( $strings, fn( $text ) => ! is_string( $text ) || '' === trim( $text ) ), "No empty text: {$lang}/{$file}" );
		check( ! array_intersect_key( $strings, $seen ), "No key defined in two files: {$lang}/{$file}" );
		$seen += $strings;
		$mismatch = [];
		foreach ( $strings as $key => $text ) {
			preg_match_all( '/%(?:\d+\$)?[sd]/', $text, $here );
			preg_match_all( '/%(?:\d+\$)?[sd]/', $english[ $key ] ?? '', $there );
			sort( $here[0] );
			sort( $there[0] );
			if ( $here[0] !== $there[0] ) { $mismatch[] = $key; }
		}
		check( ! $mismatch, "Placeholders match English: {$lang}/{$file}" . ( $mismatch ? ' (' . implode( ', ', $mismatch ) . ')' : '' ) );
	}
}

/* Every key the code names must exist; a typo would print the raw key on a screen. */
$asked = [];
foreach ( array_merge( glob( $includes . '/*.php' ), [ __DIR__ . '/../_migration/audit-pricing.php', __DIR__ . '/../_migration/apply-localrent-prices.php' ] ) as $file ) {
	if ( is_file( $file ) && preg_match_all( "/(?:glc_admin_t|GLC_Admin_Strings::t)\(\s*(?:\\\$\w+\s*\?\s*)?'([a-z0-9_]+)'(?:\s*:\s*'([a-z0-9_]+)')?\s*[,)]/", file_get_contents( $file ), $m ) ) {
		$asked = array_merge( $asked, $m[1], array_filter( $m[2] ) );
	}
	// Message keys handed to the store's error() helper as its second argument.
	if ( is_file( $file ) && preg_match_all( "/self::error\(\s*'[a-z_]+',\s*'([a-z0-9_]+)'/", file_get_contents( $file ), $m ) ) {
		$asked = array_merge( $asked, $m[1] );
	}
	if ( is_file( $file ) && preg_match_all( "/return \[ '(err_[a-z_]+)', \[/", file_get_contents( $file ), $m ) ) {
		$asked = array_merge( $asked, $m[1] );
	}
	// Message keys handed to the store's $fail() helper for a car's details, alone or as one of two.
	if ( is_file( $file ) && preg_match_all( "/\$fail\(\s*\$key,\s*(?:\$\w+\s*\?\s*)?'([a-z0-9_]+)'(?:\s*:\s*'([a-z0-9_]+)')?/", file_get_contents( $file ), $m ) ) {
		$asked = array_merge( $asked, $m[1], array_filter( $m[2] ) );
	}
}
$asked   = array_values( array_unique( $asked ) );
$missing = array_values( array_filter( $asked, fn( $key ) => ! GLC_Admin_Strings::has( $key ) ) );
check( count( $asked ) > 40 && ! $missing, 'Every key named in the code exists (' . count( $asked ) . ' keys)' . ( $missing ? ': missing ' . implode( ', ', $missing ) : '' ) );

/* Keys that are built at run time, family by family. */
$settings = new ReflectionClass( 'GLC_Settings' );
$built    = [ 'day_month', 'date_range' ];
for ( $month = 1; $month <= 12; $month++ ) { $built[] = 'month_' . $month; }
foreach ( array_merge( GLC_Rental::location_keys(), [ 'out_of_hours' ] ) as $place ) { $built[] = 'place_' . $place; }
// One name per kind and per source of a change (the history list), one sentence per way a request is routed.
foreach ( [ 'assignment', 'pricing', 'fees', 'photos', 'specs', 'profile' ] as $kind ) { $built[] = 'kind_' . $kind; }
foreach ( [ 'panel', 'bulk', 'excel', 'cli', 'metabox', 'restore' ] as $source ) { $built[] = 'source_' . $source; }
foreach ( [ 'partner', 'car', 'site' ] as $route ) { $built[] = 'answers_' . $route; }
foreach ( [ 'from', 'to' ] as $end ) { $built[] = 'season_' . $end; }
// A car's details: one name per field a partner may change, and one per gearbox and fuel the settings start with.
foreach ( array_keys( GLC_Price_Store::SPEC_FIELDS ) as $field ) { $built[] = GLC_Price_Store::spec_label_key( $field ); }
foreach ( [ 'spec_transmissions', 'spec_fuel_types' ] as $list ) {
	foreach ( GLC_Settings::words( $list ) as $word ) { $built[] = 'spec_choice_' . $word; }
}
foreach ( $settings->getConstant( 'FORMATS' ) as $key => $format ) {
	$built[] = 'setting_' . $key;
	$built[] = 'settings_bad_' . $format;
	if ( 'switch' === $format ) { $built[] = 'setting_' . $key . '_0'; $built[] = 'setting_' . $key . '_1'; }
}
$missing = array_values( array_filter( array_unique( $built ), fn( $key ) => ! GLC_Admin_Strings::has( $key ) ) );
check( ! $missing, 'Every month, place, kind and source of a change, route and settings field has its text' . ( $missing ? ': missing ' . implode( ', ', $missing ) : '' ) );

/* The other way round: a text nothing asks for is dead weight, and often a key that was renamed in one place only. */
$source = '';
foreach ( array_merge( glob( $includes . '/*.php' ), glob( __DIR__ . '/../_migration/*.php' ) ) as $file ) {
	$source .= file_get_contents( $file );
}
$unused = array_values( array_filter( array_keys( GLC_Admin_Strings::catalogue( 'en' ) ), fn( $key ) => ! in_array( $key, $built, true ) && ! str_contains( $source, "'" . $key . "'" ) && ! preg_match( '/^(setting_.*_(help|0|1)|spec_.*_help)$/', $key ) ) );
check( ! $unused, 'Every text in the catalogue is asked for somewhere' . ( $unused ? ': unused ' . implode( ', ', $unused ) : '' ) );
check( ! array_diff( $settings->getConstant( 'NOT_READ_YET' ), array_keys( $settings->getConstant( 'DEFAULTS' ) ) ) && ! array_diff( array_keys( $settings->getConstant( 'FORMATS' ) ), array_keys( $settings->getConstant( 'DEFAULTS' ) ) ), 'Every formatted or not-yet-read setting has a default' );

/* Whose language. */
check( 'en' === GLC_Admin_Strings::lang(), 'An English account reads English' );
$locale = 'ka_GE';
check( 'ka' === GLC_Admin_Strings::lang(), 'A Georgian WordPress locale reads Georgian' );
$locale    = 'en_US';
$user_meta = 'ka';
check( 'ka' === GLC_Admin_Strings::lang(), 'The panel language chosen on the account wins over the locale' );
$user_meta = 'xx';
check( 'en' === GLC_Admin_Strings::lang(), 'A language the panel does not have falls back to English' );
check( 'en' === GLC_Admin_Strings::lang( 0 ), 'Nobody signed in reads English' );
check( 'ჩემი ავტომობილები' === glc_admin_t( 'cars_title', 'ka' ) && 'My cars' === glc_admin_t( 'cars_title', 'en' ), 'The same key gives each language its own text' );
check( 'no_such_key' === glc_admin_t( 'no_such_key', 'ka' ), 'A missing key prints itself rather than nothing' );
check( 'English' === GLC_Admin_Strings::name( 'en' ) && 'ქართული' === GLC_Admin_Strings::name( 'ka' ) && 'xx' === GLC_Admin_Strings::name( 'xx' ), 'Each language names itself in its own words, whoever is reading' );
$stray = array_values( array_filter( array_keys( GLC_Admin_Strings::catalogue( 'en' ) ), fn( $key ) => preg_match( '/^spec_.*_help$/', $key ) && ! isset( GLC_Price_Store::SPEC_FIELDS[ 'glc_' . substr( $key, 5, -5 ) ] ) ) );
check( ! $stray, 'Every explanation of a detail belongs to a detail a partner may change' . ( $stray ? ': ' . implode( ', ', $stray ) : '' ) );
check( 'Mar 1' === GLC_Admin_Strings::day( '03-01', 'en' ) && '1 მარ' === GLC_Admin_Strings::day( '03-01', 'ka' ), 'A day of the year is written in the reader\'s language' );

echo "\n" . ( $failures ? "{$failures} FAILED" : 'ALL PASS' ) . "\n";
exit( $failures ? 1 : 0 );
