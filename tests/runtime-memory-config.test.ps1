$ErrorActionPreference = 'Stop'

$repoRoot = Split-Path -Parent $PSScriptRoot
$apacheConfigPath = Join-Path $repoRoot 'docker/apache-production.conf'
$snapshotPath = Join-Path $repoRoot 'docker/memory-snapshot.sh'
$dockerfilePath = Join-Path $repoRoot 'Dockerfile'

function Assert-Matches {
	param(
		[Parameter(Mandatory)] [string] $Content,
		[Parameter(Mandatory)] [string] $Pattern,
		[Parameter(Mandatory)] [string] $Message
	)

	if ($Content -notmatch $Pattern) {
		throw $Message
	}
}

if (-not (Test-Path -LiteralPath $apacheConfigPath)) {
	throw 'Missing docker/apache-production.conf memory and request hardening.'
}

if (-not (Test-Path -LiteralPath $snapshotPath)) {
	throw 'Missing docker/memory-snapshot.sh diagnostics command.'
}

$apacheConfig = Get-Content -Raw -LiteralPath $apacheConfigPath
$dockerfile = Get-Content -Raw -LiteralPath $dockerfilePath
$snapshot = Get-Content -Raw -LiteralPath $snapshotPath

# Keep mod_php/prefork inside a predictable memory envelope. These values are
# deliberately contract-tested because the upstream image defaults are suited
# to a host, not a small container.
Assert-Matches $apacheConfig '(?m)^\s*StartServers\s+2\s*$' 'StartServers must remain 2.'
Assert-Matches $apacheConfig '(?m)^\s*MinSpareServers\s+2\s*$' 'MinSpareServers must remain 2.'
Assert-Matches $apacheConfig '(?m)^\s*MaxSpareServers\s+4\s*$' 'MaxSpareServers must remain 4.'
Assert-Matches $apacheConfig '(?m)^\s*MaxRequestWorkers\s+8\s*$' 'MaxRequestWorkers must remain 8.'
Assert-Matches $apacheConfig '(?m)^\s*MaxConnectionsPerChild\s+250\s*$' 'Apache children must be recycled.'

# Stop the verified hostile endpoints before they allocate a PHP worker and
# ensure a future upload-volume compromise cannot execute server-side code.
Assert-Matches $apacheConfig '(?s)<Files\s+"xmlrpc\.php">.*?Require all denied.*?</Files>' 'xmlrpc.php must be denied by Apache.'
Assert-Matches $apacheConfig '(?s)<Files\s+"wp-load\.php">.*?Require all denied.*?</Files>' 'Direct wp-load.php requests must be denied.'
Assert-Matches $apacheConfig '(?s)<Directory\s+"/var/www/html/wp-content/uploads">.*?<FilesMatch.*?Require all denied.*?</FilesMatch>.*?</Directory>' 'PHP-family uploads must not execute.'

# Status is useful over `railway ssh`, but must never be public.
Assert-Matches $apacheConfig '(?s)<Files\s+"_internal-apache-status">.*?SetHandler server-status.*?Require local.*?</Files>' 'Apache status must remain localhost-only.'

Assert-Matches $dockerfile 'COPY docker/apache-production\.conf /etc/apache2/conf-available/geolander-production\.conf' 'Dockerfile must install the production Apache configuration.'
Assert-Matches $dockerfile 'COPY docker/memory-snapshot\.sh /usr/local/bin/geolander-memory-snapshot' 'Dockerfile must install the memory snapshot command.'
Assert-Matches $dockerfile 'COPY docker/apache-status /usr/src/wordpress/_internal-apache-status' 'Dockerfile must install the status marker before WordPress initialization.'
Assert-Matches $dockerfile 'a2enmod status' 'Dockerfile must enable mod_status.'
Assert-Matches $dockerfile 'a2enconf geolander-production' 'Dockerfile must enable the production Apache configuration.'
Assert-Matches $dockerfile 'ENTRYPOINT \["geolander-entrypoint\.sh"\]' 'The MPM wrapper must run before the official WordPress entrypoint.'
Assert-Matches $dockerfile 'CMD \["apache2-foreground"\]' 'WordPress initialization requires apache2-foreground as CMD.'

Assert-Matches $snapshot 'memory\.current' 'Snapshot must report current cgroup memory.'
Assert-Matches $snapshot 'memory\.events' 'Snapshot must report cgroup OOM events.'
Assert-Matches $snapshot '/_internal-apache-status\?auto' 'Snapshot must report Apache worker state.'

Write-Output 'Runtime memory configuration contract passed.'
