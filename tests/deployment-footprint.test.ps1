$ErrorActionPreference = 'Stop'

$root = Split-Path -Parent $PSScriptRoot
$fleet = Join-Path $root '_migration/fleet-import'
$rawRule = '_migration/fleet-import-raw/'

function Assert-True([bool]$Condition, [string]$Message) {
	if (-not $Condition) { throw $Message }
}

$dockerIgnore = Get-Content -Raw -LiteralPath (Join-Path $root '.dockerignore')
$gitIgnore = Get-Content -Raw -LiteralPath (Join-Path $root '.gitignore')
$optimizer = Get-Content -Raw -LiteralPath (Join-Path $root '_migration/optimize-fleet-source.php')

Assert-True (Test-Path -LiteralPath $fleet -PathType Container) 'Optimized fleet import tree is missing.'
Assert-True ($dockerIgnore.Contains($rawRule)) 'Raw fleet archive must not enter the Docker build context.'
Assert-True ($gitIgnore.Contains($rawRule)) 'Raw fleet archive must not enter the replacement Git repository.'

$images = @(Get-ChildItem -LiteralPath $fleet -Recurse -File | Where-Object { $_.Extension -match '^\.(jpe?g|png|webp)$' })
$bytes = ($images | Measure-Object Length -Sum).Sum
Assert-True ($images.Count -gt 0) 'Optimized fleet import contains no photos.'
Assert-True (($images | Where-Object Extension -ne '.jpg').Count -eq 0) 'Deployment fleet photos must be normalized JPEG files.'
Assert-True ($bytes -lt 80MB) "Optimized fleet photos exceed the 80 MiB deployment ceiling: $bytes bytes."
Assert-True (($images | Measure-Object Length -Maximum).Maximum -lt 4MB) 'A fleet source photo still exceeds 4 MiB.'
Add-Type -AssemblyName System.Drawing
foreach ($file in $images) {
	$image = [System.Drawing.Image]::FromFile($file.FullName)
	try {
		Assert-True ($image.Width -le 1920 -and $image.Height -le 1920) "Fleet photo exceeds 1920px: $($file.FullName)"
	} finally {
		$image.Dispose()
	}
}

Assert-True ($optimizer.Contains('RESOURCETYPE_MEMORY')) 'Optimizer must bound Imagick memory.'
Assert-True ($optimizer.Contains('RESOURCETYPE_THREAD')) 'Optimizer must bound Imagick threads.'
Assert-True ($optimizer.Contains('thumbnailImage( 1920, 1920, true )')) 'Optimizer must cap photo dimensions.'
Assert-True ($optimizer.Contains('setImageCompressionQuality( 82 )')) 'Optimizer quality contract changed unexpectedly.'

Write-Host ("Deployment footprint contract passed: {0} fleet photos, {1:N1} MiB." -f $images.Count, ($bytes / 1MB))
