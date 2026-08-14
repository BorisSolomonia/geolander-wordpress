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

Write-Output 'Schema validator HTTP-status contract passed.'
