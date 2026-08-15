$ErrorActionPreference = 'Stop'

$root = Split-Path -Parent $PSScriptRoot
$settings = Get-Content -Raw (Join-Path $root 'wp-content/plugins/geolander-core/includes/class-glc-settings.php')
$terms = Get-Content -Raw (Join-Path $root '_migration/setup-pages.php')
$faq = Get-Content -Raw (Join-Path $root '_migration/faq.json')
$cities = Get-Content -Raw (Join-Path $root '_migration/setup-cities.php')
$ai = Get-Content -Raw (Join-Path $root 'wp-content/plugins/geolander-core/includes/class-glc-ai.php')
$hero = Get-Content -Raw (Join-Path $root 'wp-content/themes/geolander/patterns/hero.php')
$contact = Get-Content -Raw (Join-Path $root 'wp-content/themes/geolander/patterns/contact-page.php')
$cityPage = Get-Content -Raw (Join-Path $root 'wp-content/themes/geolander/patterns/city-page.php')
$header = Get-Content -Raw (Join-Path $root 'wp-content/themes/geolander/patterns/header.php')

foreach ($expected in @('Mtatsminda', 'https://maps.app.goo.gl/XuY47hmvdEau9HoS9')) {
	if ($settings -notmatch [regex]::Escape($expected)) {
		throw "Business settings are missing owner-confirmed fact: $expected"
	}
}

foreach ($surface in @($hero, $contact, $cityPage)) {
	if ($surface -notmatch 'office_location') {
		throw 'Mtatsminda office proof must appear on the homepage, contact page, and Tbilisi city content.'
	}
}

foreach ($locale in @('en', 'ka', 'ru', 'uk', 'ar', 'zh', 'fr')) {
	$catalog = Get-Content -Raw (Join-Path $root "wp-content/themes/geolander/inc/strings-$locale.php")
	if ($catalog -notmatch "'office_location'") {
		throw "Locale $locale is missing office_location."
	}
}

foreach ($stale in @(
	'Credit or debit card for security deposit',
	'collision damage with a deductible',
	'Additional full coverage insurance is available for an extra fee',
	'Off-road driving \(unless vehicle is specifically approved\)',
	'damage exceeding the insurance deductible'
)) {
	if ($terms -match $stale) {
		throw "Terms still publish contradicted policy: $stale"
	}
}

foreach ($expected in @('No security deposit', '30,000 GEL', 'bad weather', 'road closures', 'damaged road')) {
	if ($terms -notmatch [regex]::Escape($expected)) {
		throw "Terms are missing owner-confirmed policy: $expected"
	}
}

if ($faq -match 'with a deductible' -or $faq -match 'Additional full coverage') {
	throw 'FAQ still contradicts the confirmed no-deductible insurance policy.'
}
foreach ($expected in @('no security deposit', '30,000 GEL', 'Mtatsminda')) {
	if ($faq -notmatch [regex]::Escape($expected)) {
		throw "FAQ is missing owner-confirmed fact: $expected"
	}
}

if ($ai -match 'own fleet of %d' -or $ai -match 'count\( \$cars \)') {
	throw 'llms.txt must not turn unresolved duplicate posts into a physical fleet count.'
}

if ($cities -match 'usually within the hour') {
	throw 'City content must not publish an unverified delivery-time promise.'
}

if ($header -notmatch '''publish''\s*===\s*\$glc_page->post_status') {
	throw 'Primary navigation must not link to fact-blocked draft pages.'
}

Write-Output 'Owner-facts SEO contract passed: Mtatsminda, deposit, insurance, routes, and no invented fleet count.'
