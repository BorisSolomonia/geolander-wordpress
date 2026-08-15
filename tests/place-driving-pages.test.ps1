$ErrorActionPreference = 'Stop'

$root = Split-Path -Parent $PSScriptRoot
$pattern = Get-Content -Raw (Join-Path $root 'wp-content/themes/geolander/patterns/place-page.php')
$seo = Get-Content -Raw (Join-Path $root 'wp-content/plugins/geolander-core/includes/class-glc-seo.php')

foreach ($contract in @('place_driving_title', 'place_driving_policy', "/fleet/4x4-suv/", "/guides/")) {
	if ($pattern -notmatch [regex]::Escape($contract)) {
		throw "Place driving template is missing: $contract"
	}
}
if ($seo -notmatch "is_singular\( 'place' \)" -or $seo -notmatch 'Driving to %s') {
	throw 'Place pages need driving-intent SEO titles.'
}

foreach ($locale in @('en', 'ka', 'ru', 'uk', 'ar', 'zh', 'fr')) {
	$catalog = Get-Content -Raw (Join-Path $root "wp-content/themes/geolander/inc/strings-$locale.php")
	foreach ($key in @('place_driving_title', 'place_drive_plan', 'place_driving_policy')) {
		if ($catalog -notmatch "'$key'") {
			throw "Locale $locale is missing place driving string: $key"
		}
	}
}

Write-Output 'Place driving-page contract passed: stable URLs, route context, category and guide links.'
