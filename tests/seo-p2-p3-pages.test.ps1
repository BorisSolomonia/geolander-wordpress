$ErrorActionPreference = 'Stop'

$root = Split-Path -Parent $PSScriptRoot
$migrationPath = Join-Path $root '_migration/setup-seo-p2-p3.php'
$landingPath = Join-Path $root 'wp-content/plugins/geolander-core/includes/class-glc-landings.php'

if (-not (Test-Path $migrationPath)) {
	throw 'Missing P2/P3 page migration.'
}
if (-not (Test-Path $landingPath)) {
	throw 'Missing custom landing route support.'
}

$migration = Get-Content -Raw $migrationPath
$landing = Get-Content -Raw $landingPath

$published = @(
	'car-rental-kazbegi',
	'4x4-suv',
	'rent-a-car-or-hire-a-driver',
	'driving-in-georgia',
	'driving-in-georgia-in-winter'
)
$drafts = @(
	'mountain-road-opening-calendar',
	'driving-to-armenia'
)

foreach ($slug in $published) {
	$definition = [regex]::Match($migration, "'slug'\s*=>\s*'$([regex]::Escape($slug))'[\s\S]*?'status'\s*=>\s*'(?<status>[^']+)'")
	if (-not $definition.Success -or $definition.Groups['status'].Value -ne 'publish') {
		throw "Expected published SEO page: $slug"
	}
}
foreach ($slug in $drafts) {
	$definition = [regex]::Match($migration, "'slug'\s*=>\s*'$([regex]::Escape($slug))'[\s\S]*?'status'\s*=>\s*'(?<status>[^']+)'")
	if (-not $definition.Success -or $definition.Groups['status'].Value -ne 'draft') {
		throw "Expected fact-blocked draft: $slug"
	}
}

if ($migration -notmatch "'custom_path'\s*=>\s*'/fleet/4x4-suv/'") {
	throw '4x4 category must own the requested /fleet/4x4-suv/ URL.'
}
foreach ($contract in @('glc_custom_path', 'add_rewrite_rule', 'page_link', 'redirect_canonical')) {
	if ($landing -notmatch [regex]::Escape($contract)) {
		throw "Custom landing route support is missing: $contract"
	}
}

if ($landing -notmatch '\^car-rental-kazbegi') {
	throw 'Kazbegi landing must outrank the broad car-rental city rewrite.'
}

if ($migration -match 'aggregateRating') {
	throw 'P2/P3 pages must not add self-serving aggregateRating schema.'
}
if ($migration -notmatch 'Mtatsminda') {
	throw 'Commercial landing pages must reinforce the owner-confirmed Mtatsminda office.'
}

Write-Output 'P2/P3 page contract passed: safe pages publish, fact-blocked pages remain drafts.'
