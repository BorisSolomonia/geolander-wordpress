$ErrorActionPreference = 'Stop'

$root = Split-Path -Parent $PSScriptRoot
$validator = Get-Content -Raw (Join-Path $root '_migration/validate-schema.mjs')
$pageLoop = [regex]::Match(
	$validator,
	'for \(const \[page, url\] of PAGES\)(?<body>[\s\S]*?)/\* -+ Regression guards'
)

if (-not $pageLoop.Success) {
	throw 'Could not locate the validator page-fetch loop.'
}

if ($pageLoop.Groups['body'].Value -notmatch 'if \(!res\.ok\)') {
	throw 'Validator page loop does not fail non-2xx HTTP responses.'
}

if ($validator -notmatch '/wp-json/wp/v2/car\?status=publish') {
	throw 'Validator must discover every published car, not only sample fixtures.'
}

foreach ($page in @('car-rental-kazbegi', 'fleet/4x4-suv', 'rent-a-car-or-hire-a-driver', 'driving-in-georgia-in-winter')) {
	if ($validator -notmatch [regex]::Escape($page)) {
		throw "Validator is missing published SEO page: $page"
	}
}

Write-Output 'Schema validator HTTP-status contract passed.'
