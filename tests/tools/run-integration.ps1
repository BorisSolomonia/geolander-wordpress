param(
    [string[]]$Suites = @('quote-clients-wp.php', 'partners-wp.php', 'price-store-wp.php', 'photos-specs-wp.php', 'bulk-excel-wp.php', 'assignment-atomicity-wp.php', 'plugin-management-wp.php')
)
$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent (Split-Path -Parent $PSScriptRoot)
Push-Location $root
try {
    $container = (& docker compose ps -q wordpress).Trim()
    if (-not $container) { throw 'Start the local WordPress service first.' }
    $environment = & docker exec $container wp eval 'echo wp_get_environment_type();' --allow-root
    if ($LASTEXITCODE -ne 0 -or $environment -ne 'development') { throw 'This runner restores test data and requires a development installation.' }
    & docker cp "$root/tests/." "${container}:/tmp/glc-integration-tests"
    & docker compose exec -T db sh -c 'mariadb-dump -uroot -p"$MARIADB_ROOT_PASSWORD" --single-transaction "$MARIADB_DATABASE" > /tmp/glc-integration-baseline.sql'
    if ($LASTEXITCODE -ne 0) { throw 'Could not snapshot the local database.' }
    $failed = @()
    try {
        foreach ($suite in $Suites) {
            if ($suite -notmatch '^[a-z0-9-]+\.php$' -or -not (Test-Path -LiteralPath (Join-Path "$root/tests" $suite))) { throw "Unknown suite: $suite" }
            & docker exec $container wp eval-file /tmp/glc-integration-tests/integration-fixture-wp.php cleanup --allow-root
            & docker compose exec -T db sh -c 'mariadb -uroot -p"$MARIADB_ROOT_PASSWORD" "$MARIADB_DATABASE" < /tmp/glc-integration-baseline.sql'
            if ($LASTEXITCODE -ne 0) { throw 'Could not restore the local baseline.' }
            & docker exec $container wp eval-file /tmp/glc-integration-tests/integration-fixture-wp.php $suite --allow-root
            if ($LASTEXITCODE -ne 0) { throw "Could not prepare fixtures for $suite" }
            $ErrorActionPreference = 'Continue'
            $output = & docker exec $container php -d max_execution_time=0 /usr/local/bin/wp eval-file "/tmp/glc-integration-tests/$suite" --allow-root 2>&1
            $suiteExitCode = $LASTEXITCODE
            $ErrorActionPreference = 'Stop'
            if ($suiteExitCode -ne 0) {
                $failed += $suite
                Write-Output "FAIL $suite"
                $output | Where-Object { "$_" -match 'FAIL|Fatal|Error:|Warning:' } | Select-Object -First 20
            } else {
                Write-Output "PASS $suite"
            }
        }
    } finally {
        & docker exec $container wp eval-file /tmp/glc-integration-tests/integration-fixture-wp.php cleanup --allow-root
        & docker compose exec -T db sh -c 'mariadb -uroot -p"$MARIADB_ROOT_PASSWORD" "$MARIADB_DATABASE" < /tmp/glc-integration-baseline.sql'
        if ($LASTEXITCODE -ne 0) { throw 'Could not restore local data after testing.' }
    }
    if ($failed.Count) { throw ('Integration failures: ' + ($failed -join ', ')) }
} finally {
    Pop-Location
}
