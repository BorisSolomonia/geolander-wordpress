$ErrorActionPreference = 'Stop'

$root = Split-Path -Parent $PSScriptRoot
$migration = Get-Content -Raw (Join-Path $root '_migration/publish-route-guides.php')
$seo = Get-Content -Raw (Join-Path $root 'wp-content/plugins/geolander-core/includes/class-glc-seo.php')
$schema = Get-Content -Raw (Join-Path $root 'wp-content/plugins/geolander-core/includes/class-glc-schema.php')

$vehicleBlock = [regex]::Match(
	$migration,
	'\$vehicle_titles\s*=\s*\[(?<body>[\s\S]*?)\n\];'
)
if (-not $vehicleBlock.Success) {
	throw 'Vehicle SEO title map was not found.'
}

$vehicleTitles = [regex]::Matches($vehicleBlock.Groups['body'].Value, "=>\s*'(?<title>[^']+)'", 'Multiline') |
	ForEach-Object { $_.Groups['title'].Value }

if ($vehicleTitles.Count -ne 19) {
	throw "Expected 19 vehicle titles, found $($vehicleTitles.Count)."
}

if (($vehicleTitles | Sort-Object -Unique).Count -ne 19) {
	throw 'Vehicle SEO titles must be unique.'
}

foreach ($title in $vehicleTitles) {
	$renderedLength = ($title + ' | Geolander').Length
	if ($renderedLength -lt 50 -or $renderedLength -gt 60) {
		throw "Rendered title is outside 50-60 characters ($renderedLength): $title"
	}
	if ($title -notmatch 'Tbilisi') {
		throw "Vehicle title does not target Tbilisi: $title"
	}
}

foreach ($slug in @(
	'driving-to-kazbegi-in-winter',
	'svaneti-4x4-road-trip-guide',
	'tusheti-4x4-rental-guide'
)) {
	if ($migration -notmatch [regex]::Escape("'slug'        => '$slug'")) {
		throw "Missing guide definition: $slug"
	}
}

if ($migration -notmatch 'glc-related-guides' -or $migration -notmatch 'Related mountain driving guides') {
	throw 'Mountain guides must link to each other.'
}

foreach ($stale in @('Only with prior approval', 'standard terms prohibit off-road driving', 'route approval still depends')) {
	if ($migration -match [regex]::Escape($stale)) {
		throw "Route guide still contradicts owner-confirmed route policy: $stale"
	}
}

if ($seo -notmatch "glc_seo_title_en" -or $seo -notmatch "glc_seo_description_en") {
	throw 'SEO layer does not consume custom title and description metadata.'
}
if ($schema -notmatch "glc_guide_route" -or $schema -notmatch "'@type'\s*=>\s*'Article'") {
	throw 'Guide Article schema contract is missing.'
}

Write-Output 'SEO content contract passed: 19 unique vehicle titles and 3 route guides.'
