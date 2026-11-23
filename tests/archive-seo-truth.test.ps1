$ErrorActionPreference = 'Stop'

$root = Split-Path -Parent $PSScriptRoot
$fleetPattern = Get-Content -Raw (Join-Path $root 'wp-content/themes/geolander/patterns/fleet-page.php')
$placesPattern = Get-Content -Raw (Join-Path $root 'wp-content/themes/geolander/patterns/places-page.php')
$seo = Get-Content -Raw (Join-Path $root 'wp-content/plugins/geolander-core/includes/class-glc-seo.php')
$pricing = Get-Content -Raw (Join-Path $root 'wp-content/plugins/geolander-core/includes/class-glc-pricing.php')
$format = Get-Content -Raw (Join-Path $root 'wp-content/plugins/geolander-core/includes/class-glc-format.php')

if ($fleetPattern -match "fleet_title',\s*'\d+") {
	throw 'Fleet archive must not publish a hard-coded physical fleet count.'
}

if ($placesPattern -match "places_title',\s*'\d+") {
	throw 'Places archive must not publish a hard-coded destination count.'
}
if ($placesPattern -notmatch 'wp_count_posts') {
	throw 'Places archive should derive its content count from published place posts.'
}

if ($seo -match "Car Rental Fleet.*%d Real 4x4s") {
	throw 'Fleet title must not present unresolved published posts as a physical fleet count.'
}

if ($pricing -notmatch 'function fleet_ceiling') {
	throw 'Pricing layer must derive the advertised ceiling from real rate tables.'
}
if ($format -notmatch 'GLC_Pricing::fleet_ceiling') {
	throw 'Display range must consume the real fleet ceiling.'
}

Write-Output 'Archive SEO truth contract passed: dynamic place count, no fleet guess, real price ceiling.'
