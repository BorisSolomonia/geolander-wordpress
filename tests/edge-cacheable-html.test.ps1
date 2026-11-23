$ErrorActionPreference = 'Stop'

# Public pages must stay storable by Cloudflare: a Set-Cookie on a page, or a
# private Cache-Control on a URL-pure page, makes the edge refuse to cache it
# and sends every visit through the Tbilisi-edge -> origin path (2026-11-22 speed audit).

$root = Split-Path -Parent $PSScriptRoot
$i18n = Get-Content -Raw -Encoding utf8 (Join-Path $root 'wp-content/plugins/geolander-core/includes/class-glc-i18n.php')
$perf = Get-Content -Raw -Encoding utf8 (Join-Path $root 'wp-content/plugins/geolander-core/includes/class-glc-perf.php')

foreach ($banned in @('setcookie(', '$_COOKIE', 'HTTP_ACCEPT_LANGUAGE')) {
	if ($i18n.Contains($banned)) {
		throw "GLC_I18n must take the language from the URL only and write no cookie; found: $banned"
	}
}

if ($perf -match 'is_front_page\(\)') {
	throw 'GLC_Perf must not special-case the front page: "/" is URL-pure and cacheable'
}

foreach ($expected in @("Cache-Control: private, no-cache", "isset( `$_GET['from'] )", 'is_user_logged_in()', 'S_MAXAGE')) {
	if (-not $perf.Contains($expected)) {
		throw "GLC_Perf cache contract is missing: $expected"
	}
}

Write-Output 'edge-cacheable-html: ok'
