$ErrorActionPreference = 'Stop'

$root = Split-Path -Parent $PSScriptRoot
$importer = Get-Content -Raw (Join-Path $root '_migration/import-fleet.php')

if ($importer -match 'glob\([^;]*,\s*GLOB_BRACE') {
	throw 'Fleet importer uses GLOB_BRACE, which is unavailable in wordpress:cli-php8.3.'
}

$expectedPattern = "preg_match( '/\.(?:jpe?g|png|webp)`$/i'"
if (-not $importer.Contains($expectedPattern)) {
	throw 'Fleet importer must filter supported image extensions case-insensitively.'
}

Write-Output 'Fleet importer portability contract passed.'
