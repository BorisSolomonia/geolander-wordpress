<?php
define( 'ABSPATH', __DIR__ );
$locale = 'ar'; $admin = false; $translations = [ 'glc_title_ar' => 'عنوان مترجم' ];
$post = (object) [ 'ID' => 1, 'post_type' => 'page', 'post_title' => 'English heading' ];
function is_admin() { return $GLOBALS['admin']; }
function get_post( $id ) { return $id ? $GLOBALS['post'] : null; }
function get_post_meta( $id, $key, $single ) { return $GLOBALS['translations'][$key] ?? ''; }
class GLC_I18n { const DEFAULT_LOCALE = 'en'; public static function locale() { return $GLOBALS['locale']; } }
require __DIR__ . '/../wp-content/plugins/geolander-core/includes/class-glc-content.php';
function check( $yes, $label ) { if ( ! $yes ) { throw new Exception( $label ); } echo 'PASS ' . $label . "\n"; }
check( GLC_Content::localized_title( 'English heading', 1 ) === 'عنوان مترجم', 'Core visible page title uses the locale translation' );
$admin = true;
check( GLC_Content::localized_title( 'English heading', 1 ) === 'English heading', 'Admin title is unchanged' );
$admin = false; $locale = 'en';
check( GLC_Content::localized_title( 'Filtered English', 1 ) === 'Filtered English', 'English preserves existing filters' );
$locale = 'fr';
check( GLC_Content::localized_title( 'Filtered English', 1 ) === 'Filtered English', 'Missing translation preserves fallback title' );
$locale = 'ar'; $post->post_type = 'car';
check( GLC_Content::localized_title( 'Toyota RAV4', 1 ) === 'Toyota RAV4', 'Car model names are not translated' );
