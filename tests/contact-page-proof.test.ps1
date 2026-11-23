$ErrorActionPreference = 'Stop'

$root = Split-Path -Parent $PSScriptRoot
$plugin = Get-Content -Raw (Join-Path $root 'wp-content/plugins/geolander-core/geolander-core.php')
$handler = Get-Content -Raw (Join-Path $root 'wp-content/plugins/geolander-core/includes/class-glc-contact.php')
$contact = Get-Content -Raw (Join-Path $root 'wp-content/themes/geolander/patterns/contact-page.php')

if ($plugin -notmatch "class-glc-contact\.php" -or $plugin -notmatch 'GLC_Contact::init\(\)') {
	throw 'The contact handler is not loaded and initialized by Geolander Core.'
}

foreach ($contract in @('admin_post_nopriv_', 'GLC_Gateway_WhatsApp::url', 'wp_redirect')) {
	if ($handler -notmatch [regex]::Escape($contract)) {
		throw "Contact handler is missing: $contract"
	}
}

if ($handler -match 'wp_mail') {
	throw 'Contact form must not rely on unconfigured container mail transport.'
}
if ($handler -notmatch "implode\(\s*' \| '") {
	throw 'WhatsApp contact fields must retain visible separators through the HTTP Location header.'
}

if ($contact -notmatch '<form' -or $contact -notmatch '<iframe' -or $contact -notmatch "contact_google_rating") {
	throw 'Contact page must include a form, embedded map, and linked Google rating proof.'
}

foreach ($locale in @('en', 'ka', 'ru', 'uk', 'ar', 'zh', 'fr')) {
	$catalog = Get-Content -Raw (Join-Path $root "wp-content/themes/geolander/inc/strings-$locale.php")
	foreach ($key in @('contact_form_title', 'contact_google_rating', 'contact_map_title')) {
		if ($catalog -notmatch "'$key'") {
			throw "Locale $locale is missing UI string: $key"
		}
	}
}

if ($contact -match 'aggregateRating') {
	throw 'Self-serving aggregateRating markup must not be added.'
}

Write-Output 'Contact page contract passed: form, map, linked Google proof, and 7 locale catalogs.'
