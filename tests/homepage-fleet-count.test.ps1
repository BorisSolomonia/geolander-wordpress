$ErrorActionPreference = 'Stop'

$root = Split-Path -Parent $PSScriptRoot
$pattern = Get-Content -Raw (Join-Path $root 'wp-content/themes/geolander/patterns/home-sections.php')

if ($pattern -match "fleet_title',\s*'\d+" -or $pattern -match "view_all'\s*\).*?\d+") {
	throw 'Homepage must not publish a hard-coded fleet count while duplicate plates remain unresolved.'
}

if ($pattern -notmatch 'contact_google_rating' -or $pattern -notmatch 'google_maps_url') {
	throw 'Homepage Google rating proof must link to the configured Maps profile.'
}

if ($pattern -match 'aggregateRating') {
	throw 'Homepage must not add self-serving aggregateRating markup.'
}

Write-Output 'Homepage trust contract passed: linked Google proof and no invented fleet count.'
