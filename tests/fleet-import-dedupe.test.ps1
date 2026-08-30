$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent $PSScriptRoot
$importer = Get-Content -Raw -LiteralPath (Join-Path $root '_migration/import-fleet.php')
$redirector = Get-Content -Raw -LiteralPath (Join-Path $root 'wp-content/plugins/geolander-core/includes/class-glc-rental.php')
$grayFacts = Get-Content -Raw -LiteralPath (Join-Path $root '_migration/fleet-import/Subaru Forester 2019 Gray/car.json.json') | ConvertFrom-Json
$duplicateFacts = Get-Content -Raw -LiteralPath (Join-Path $root '_migration/fleet-import/Mitsubishi Outlander 2018 Gray/car.json.json') | ConvertFrom-Json

if ($importer -notmatch 'glc_fleet_find_by_registration') { throw 'Fleet import does not match physical cars by registration.' }
if ($importer -notmatch "data\['skip_import'\]") { throw 'Fleet import does not honor duplicate-source markers.' }
if ($redirector -notmatch 'glc_fleet_redirects') { throw 'Consolidated fleet URLs have no redirect registry.' }
if ($grayFacts.registration -ne 'TT-902-FT') { throw 'The photographed gray 2019 Forester plate is incorrect.' }
if (-not $duplicateFacts.skip_import) { throw 'The identical Outlander source folder must be skipped.' }

Write-Output 'Fleet import identity and redirect checks passed.'
