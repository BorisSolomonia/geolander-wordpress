<?php
/** Lightweight behavioral tests; no WordPress/database/network needed. */
define( 'ABSPATH', __DIR__ );
define( 'GLC_URL', 'https://example.test/plugin/' );
define( 'GLC_VERSION', 'test' );
$options = [ 'home' => 'https://example.test', 'glc_indexnow_key' => 'test-key-12345678' ];
$context = [];
$requests = [];
$environment = 'production';
$failures = 0;
function check( $condition, $message ) {
	global $failures;
	if ( ! $condition ) { ++$failures; }
	echo ( $condition ? 'PASS ' : 'FAIL ' ) . $message . "\n";
}
function get_option( $name, $default = false ) { return $GLOBALS['options'][$name] ?? $default; }
function update_option( $name, $value, $autoload = null ) { $GLOBALS['options'][$name] = $value; }
function wp_get_environment_type() { return $GLOBALS['environment']; }
function home_url( $path = '/' ) { return get_option( 'home' ) . $path; }
function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }
function untrailingslashit( $url ) { return rtrim( $url, '/' ); }
function wp_json_encode( $value ) { return json_encode( $value ); }
function wp_remote_post( $url, $args ) { $GLOBALS['requests'][] = $args; return [ 'code' => 202 ]; }
function is_wp_error( $value ) { return false; }
function wp_remote_retrieve_response_code( $r ) { return $r['code']; }
function is_tax( $types = null ) { return ! empty( $GLOBALS['context']['tax'] ); }
function is_search() { return ! empty( $GLOBALS['context']['search'] ); }
function is_paged() { return ! empty( $GLOBALS['context']['paged'] ); }
function is_404() { return ! empty( $GLOBALS['context']['404'] ); }
function is_front_page() { return ! empty( $GLOBALS['context']['front'] ); }
function is_singular( $type = null ) { return false; }
function is_post_type_archive( $type = null ) { return ! empty( $GLOBALS['context']['archive'] ); }
function get_post_type() { return 'car'; }
function get_post_type_archive_link( $type ) { return home_url( '/fleet/' ); }
function get_pagenum_link( $page, $escape = true ) { return home_url( '/fleet/page/' . $page . '/' ); }
function get_query_var( $key ) { return $key === 'paged' ? 2 : null; }
function wp_get_document_title() { return 'Fleet'; }
function get_bloginfo( $key ) { return 'Geolander'; }
function get_theme_file_uri( $path ) { return home_url( '/' . $path ); }
function wp_html_excerpt( $text, $length, $more ) { return substr( $text, 0, $length ); }
function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_url( $s ) { return esc_attr( $s ); }
function esc_js( $s ) { return $s; }
function wp_enqueue_script( ...$args ) {}
function wp_unslash( $s ) { return $s; }
function add_query_arg( $args, $url ) { return $url . ( $args ? '?' . http_build_query( $args ) : '' ); }
function glc_ui( $key ) { return $key; }
class GLC_Settings {
	public static function get( $key, $default = '' ) { return $key === 'ga4_id' ? 'G-TEST' : $default; }
}
require __DIR__ . '/../wp-content/plugins/geolander-core/includes/class-glc-indexnow.php';
require __DIR__ . '/../wp-content/plugins/geolander-core/includes/class-glc-seo.php';
require __DIR__ . '/../wp-content/plugins/geolander-core/includes/class-glc-i18n.php';

GLC_IndexNow::ping( [ home_url( '/' ) ] );
$body = json_decode( $requests[0]['body'], true );
check( ( $body['keyLocation'] ?? '' ) === GLC_IndexNow::key_url(), 'IndexNow uses protocol keyLocation' );
check( ! isset( $body['keyUrlLocation'] ), 'No unregistered IndexNow field' );
$environment = 'development';
$before = count( $requests );
$result = GLC_IndexNow::ping( [ home_url( '/' ) ] );
check( count( $requests ) === $before && $result['count'] === 0, 'Development never submits URLs to IndexNow' );
$environment = 'production';
GLC_IndexNow::ping( [ 'https://elsewhere.test/', home_url( '/fleet/' ), home_url( '/fleet/' ) ] );
$body = json_decode( end( $requests )['body'], true );
check( $body['urlList'] === [ home_url( '/fleet/' ) ], 'IndexNow excludes foreign hosts and deduplicates' );

$robots = GLC_SEO::robots( "User-agent: *\nDisallow: /wp-admin/\nAllow: /wp-admin/admin-ajax.php\n" );
check( ! str_contains( $robots, 'Disallow: /*?glc_lang=' ) && ! str_contains( $robots, 'Disallow: /*?region=' ), 'Duplicate URLs stay crawlable for canonical/noindex discovery' );
check( ! preg_match( '/User-agent: Bingbot\nAllow: \/\n(?!Disallow: \/wp-admin\/)/', $robots ), 'Bot-specific rules do not bypass admin crawl exclusion' );
check( GLC_SEO::robots( "User-agent: *\nDisallow: /\n" ) === "User-agent: *\nDisallow: /\n", 'Private-site crawl exclusion is not overridden' );
$_GET = [ 'glc_lang' => 'en' ];
check( empty( GLC_SEO::robots_archives( [] )['noindex'] ), 'Language parameter uses canonical, not conflicting noindex' );
$_GET = [ 'region' => 'kazbegi' ];
check( ! empty( GLC_SEO::robots_archives( [] )['noindex'] ), 'Filtered results remain noindex' );
$_GET = [];
$context = [ 'paged' => true, 'archive' => true ];
check( GLC_SEO::full_archive_redirect() === home_url( '/fleet/' ), 'All-item archive page aliases consolidate to the complete grid' );
$_GET = [ 'region' => 'kazbegi', 'paged' => '2' ];
check( GLC_SEO::full_archive_redirect() === home_url( '/fleet/?region=kazbegi' ), 'Archive alias keeps the selected filter, not the page parameter' );
$_GET = [];
check( empty( GLC_SEO::robots_archives( [] )['noindex'] ), 'Useful archive pagination remains indexable' );
ob_start(); GLC_SEO::output(); $html = ob_get_clean();
check( str_contains( $html, '<link rel="canonical" href="https://example.test/fleet/page/2/"' ), 'Archive page 2 has its own canonical' );
$context = [ '404' => true ];
check( GLC_SEO::full_archive_redirect() === '', 'Nonexistent archive pages remain 404, not a blanket redirect' );
ob_start(); GLC_I18n::hreflang(); $html = ob_get_clean();
check( $html === '', '404s do not advertise hreflang alternates' );
$context = [ 'search' => true ];
ob_start(); GLC_I18n::hreflang(); $html = ob_get_clean();
check( $html === '', 'Search results do not advertise hreflang alternates' );
$context = [];
$_SERVER['REQUEST_URI'] = '/fleet/?glc_lang=en&from=2026-12-19';
$switcher = GLC_I18n::switcher();
check( ! str_contains( $switcher['en']['url'], 'glc_lang' ) && ! str_contains( $switcher['ru']['url'], 'glc_lang' ), 'Switcher emits clean language URLs' );
check( str_contains( $switcher['ru']['url'], 'from=2026-12-19' ), 'Switcher preserves booking dates' );
ob_start(); GLC_SEO::gtag(); $html = ob_get_clean();
check( ! str_contains( $html, 'link_url:a.href' ), 'Analytics never forwards WhatsApp message URLs' );
exit( $failures ? 1 : 0 );
