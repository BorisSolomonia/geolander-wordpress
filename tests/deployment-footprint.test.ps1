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
function Get-JpegDimensions([string]$Path) {
	# Parse SOF metadata directly: System.Drawing is Windows-only on modern .NET.
	$data = [System.IO.File]::ReadAllBytes($Path)
	Assert-True ($data.Length -ge 4 -and $data[0] -eq 255 -and $data[1] -eq 216) "Invalid JPEG: $Path"
	$offset = 2
	while ($offset + 3 -lt $data.Length) {
		Assert-True ($data[$offset] -eq 255) "Invalid JPEG marker: $Path"
		while ($offset -lt $data.Length -and $data[$offset] -eq 255) { $offset++ }
		Assert-True ($offset -lt $data.Length) "Truncated JPEG marker: $Path"
		$marker = [int]$data[$offset]
		$offset++
		if ($marker -eq 217 -or $marker -eq 218) { break }
		if ($marker -eq 1 -or ($marker -ge 208 -and $marker -le 216)) { continue }
		Assert-True ($offset + 1 -lt $data.Length) "Truncated JPEG segment: $Path"
		$length = [int]$data[$offset] * 256 + [int]$data[$offset + 1]
		Assert-True ($length -ge 2 -and $offset + $length -le $data.Length) "Invalid JPEG segment length: $Path"
		if ($marker -in @(192,193,194,195,197,198,199,201,202,203,205,206,207)) {
			Assert-True ($length -ge 8) "Invalid JPEG frame: $Path"
			return [pscustomobject]@{
				Height = [int]$data[$offset + 3] * 256 + [int]$data[$offset + 4]
				Width = [int]$data[$offset + 5] * 256 + [int]$data[$offset + 6]
			}
		}
		$offset += $length
	}
	throw "JPEG frame dimensions were not found: $Path"
}
foreach ($file in $images) {
	$image = Get-JpegDimensions $file.FullName
	Assert-True ($image.Width -gt 0 -and $image.Height -gt 0 -and $image.Width -le 1920 -and $image.Height -le 1920) "Fleet photo exceeds 1920px: $($file.FullName)"
}

Assert-True ($optimizer.Contains('RESOURCETYPE_MEMORY')) 'Optimizer must bound Imagick memory.'
Assert-True ($optimizer.Contains('RESOURCETYPE_THREAD')) 'Optimizer must bound Imagick threads.'
Assert-True ($optimizer.Contains('thumbnailImage( 1920, 1920, true )')) 'Optimizer must cap photo dimensions.'
Assert-True ($optimizer.Contains('setImageCompressionQuality( 82 )')) 'Optimizer quality contract changed unexpectedly.'

Write-Host ("Deployment footprint contract passed: {0} fleet photos, {1:N1} MiB." -f $images.Count, ($bytes / 1MB))
