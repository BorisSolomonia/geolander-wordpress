param([string]$Image = 'geolander-wordpress:local')
$ErrorActionPreference = 'Stop'
$name = 'geolander-plugin-storage-probe'
$volume = 'geolander-plugin-storage-probe'
$pluginRoot = '/var/www/html/wp-content/plugins'
$createdVolume = $false
$probeId = $null
function Assert-Exit([string]$Message) { if ($LASTEXITCODE -ne 0) { throw $Message } }
$existingVolumes = @(& docker volume ls --format '{{.Name}}')
Assert-Exit 'Could not inspect local test volumes.'
if ($existingVolumes -contains $volume) { throw 'Probe volume already exists; refusing to overwrite it.' }
try {
    & docker volume create --label glc.test=plugin-storage $volume *> $null
    Assert-Exit 'Could not create isolated test volume.'
    $createdVolume = $true
    foreach ($pass in 1..2) {
        $probeId = (& docker run -d --name $name --network none --label glc.test=plugin-storage -e GLC_ALLOW_PLUGIN_MANAGEMENT=1 --mount "type=volume,source=$volume,target=$pluginRoot" $Image).Trim()
        Assert-Exit 'Could not start production-style storage probe.'
        $ready = $false
        foreach ($attempt in 1..30) {
            $ErrorActionPreference = 'Continue'
            & docker exec $probeId curl --fail --silent http://127.0.0.1/healthz *> $null
            $probeExit = $LASTEXITCODE
            $ErrorActionPreference = 'Stop'
            if ($probeExit -eq 0) { $ready = $true; break }
            Start-Sleep -Seconds 1
        }
        if (-not $ready) { & docker logs $probeId; throw 'Storage probe did not become healthy.' }
        if ($pass -eq 1) {
            & docker exec --user 33:33 $probeId mkdir "$pluginRoot/glc-storage-probe"
            Assert-Exit 'Web PHP cannot create a third-party plugin directory.'
            & docker exec --user 33:33 $probeId touch "$pluginRoot/glc-storage-probe/persisted.txt"
            Assert-Exit 'Web PHP cannot write a third-party plugin file.'
        }
        & docker exec --user 33:33 $probeId test -f "$pluginRoot/glc-storage-probe/persisted.txt"
        Assert-Exit 'Third-party plugin did not survive a fresh container.'
        & docker exec --user 33:33 $probeId test -w /var/www/html/wp-content/upgrade
        Assert-Exit 'Installer staging directory is not writable.'
        & docker exec --user 33:33 $probeId test -w /var/www/html/wp-content/upgrade-temp-backup
        Assert-Exit 'Update rollback directory is not writable.'
        & docker exec --user 33:33 $probeId test -w "$pluginRoot/geolander-core/geolander-core.php"
        if ($LASTEXITCODE -eq 0) { throw 'Image-managed Geolander Core must remain read-only.' }
        & docker exec --user 33:33 $probeId test -w /var/www/html/index.php
        if ($LASTEXITCODE -eq 0) { throw 'WordPress core must remain read-only.' }
        & docker rm -f -v $probeId *> $null
        Assert-Exit 'Could not dispose of isolated probe container.'
        $probeId = $null
    }
    Write-Output 'PASS Third-party plugins survive container replacement; installer staging is writable; WordPress and Geolander Core stay read-only.'
} finally {
    if ($probeId) { & docker rm -f -v $probeId *> $null }
    if ($createdVolume) { & docker volume rm $volume *> $null }
}
