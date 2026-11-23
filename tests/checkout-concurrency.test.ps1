$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent $PSScriptRoot
$container = (& docker compose ps -q wordpress).Trim()
if (-not $container) { throw 'Start the local WordPress service first.' }
& docker cp (Join-Path $PSScriptRoot 'checkout-concurrency-wp.php') "${container}:/tmp/glc-checkout-concurrency.php"
& docker exec $container wp eval-file /tmp/glc-checkout-concurrency.php setup --allow-root
if ($LASTEXITCODE -ne 0) { throw 'Could not prepare local checkout fixture.' }
$jobs = @()
try {
    $jobs = 1..2 | ForEach-Object {
        Start-Job -ScriptBlock { param($id) & docker exec $id wp eval-file /tmp/glc-checkout-concurrency.php request --allow-root } -ArgumentList $container
    }
    $responses = $jobs | Wait-Job -Timeout 30 | Receive-Job
    $accepted = @($responses | Where-Object { "$_" -eq '200' }).Count
    $refused = @($responses | Where-Object { "$_" -eq '429' }).Count
    if ($accepted -ne 1 -or $refused -ne 1) { throw "One slot must admit exactly one concurrent checkout; observed: $responses" }
    Write-Output 'PASS Concurrent checkout: one admitted, one refused at the global ceiling.'
} finally {
    $jobs | Stop-Job
    $jobs | Remove-Job
    & docker exec $container wp eval-file /tmp/glc-checkout-concurrency.php cleanup --allow-root
}
