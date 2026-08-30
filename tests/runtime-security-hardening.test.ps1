$ErrorActionPreference = 'Stop'

$repoRoot = Split-Path -Parent $PSScriptRoot
$dockerfile = Get-Content -Raw -Encoding utf8 (Join-Path $repoRoot 'Dockerfile')
$apache = Get-Content -Raw -Encoding utf8 (Join-Path $repoRoot 'docker/apache-production.conf')
$booking = Get-Content -Raw -Encoding utf8 (Join-Path $repoRoot 'wp-content/plugins/geolander-core/includes/class-glc-booking.php')
$blocks = Get-Content -Raw -Encoding utf8 (Join-Path $repoRoot 'wp-content/plugins/geolander-core/includes/class-glc-blocks.php')
$contact = Get-Content -Raw -Encoding utf8 (Join-Path $repoRoot 'wp-content/plugins/geolander-core/includes/class-glc-contact.php')
$railway = Get-Content -Raw -Encoding utf8 (Join-Path $repoRoot 'railway.json')
$snapshot = Get-Content -Raw -Encoding utf8 (Join-Path $repoRoot 'docker/memory-snapshot.sh')

function Assert-Contains {
	param([string] $Content, [string] $Expected, [string] $Message)
	if (-not $Content.Contains($Expected)) { throw $Message }
}

foreach ($file in @('docker/wordpress-hardening.php', 'docker/hardened-apache-start.sh', 'docker/healthz')) {
	if (-not (Test-Path -LiteralPath (Join-Path $repoRoot $file))) {
		throw "Missing runtime hardening asset: $file"
	}
}

Assert-Contains $dockerfile 'FROM wordpress:7.0.4-php8.3-apache' 'Production must use the patched WordPress 7.0.4 image line.'
Assert-Contains $dockerfile 'apt-get upgrade -y' 'The Debian packages in the WordPress base must be patched during the build.'
Assert-Contains $dockerfile 'WP_CLI_VERSION=2.12.0' 'WP-CLI must be version-pinned.'
Assert-Contains $dockerfile 'sha512sum -c -' 'The downloaded WP-CLI PHAR must be checksum-verified.'
Assert-Contains $dockerfile 'memory_limit = 128M' 'Per-request PHP memory must remain bounded at 128 MB.'
Assert-Contains $dockerfile 'opcache.memory_consumption = 64' 'OPcache must use the measured small-site allocation.'
Assert-Contains $dockerfile 'max_execution_time = 30' 'Web PHP execution must have a finite timeout.'
Assert-Contains $dockerfile 'disable_functions = exec,passthru,shell_exec,system,proc_open,popen,pcntl_exec' 'OS command execution must stay disabled in the web image.'
Assert-Contains $dockerfile 'auto_prepend_file = /usr/local/etc/wordpress/wordpress-hardening.php' 'WordPress hardening constants must load before wp-config.php.'
Assert-Contains $dockerfile 'COPY docker/healthz /usr/src/wordpress/healthz' 'Railway health must use a static file instead of a PHP worker.'
Assert-Contains $dockerfile 'CMD ["apache2-geolander-foreground"]' 'The hardened Apache launcher must run after WordPress initialization.'
Assert-Contains $dockerfile 'zz-geolander-production' 'Security overrides must load after Apache defaults.'

Assert-Contains $apache "MaxRequestWorkers 4" 'Apache PHP concurrency must remain capped at four workers.'
Assert-Contains $apache '<Files "wp-cron.php">' 'Direct request-driven WordPress cron must be denied.'
Assert-Contains $apache 'LimitRequestBody 65536' 'Public Geolander APIs must have a small request-body ceiling.'
Assert-Contains $apache "req('Content-Length') -gt 65536" 'Public Geolander APIs must reject a declared oversized body before PHP.'
Assert-Contains $apache 'RequestReadTimeout' 'Slow request bodies must not pin PHP workers.'
Assert-Contains $apache 'TraceEnable Off' 'HTTP TRACE must remain disabled.'
Assert-Contains $apache 'ServerTokens Prod' 'Apache must not disclose its package version.'

$hardening = Get-Content -Raw -Encoding utf8 (Join-Path $repoRoot 'docker/wordpress-hardening.php')
foreach ($constant in @('DISALLOW_FILE_EDIT', 'DISALLOW_FILE_MODS', 'DISABLE_WP_CRON', 'WP_MEMORY_LIMIT', 'WP_MAX_MEMORY_LIMIT')) {
	Assert-Contains $hardening $constant "WordPress hardening is missing $constant."
}

$launcher = Get-Content -Raw -Encoding utf8 (Join-Path $repoRoot 'docker/hardened-apache-start.sh')
foreach ($expected in @('find "$WEB_ROOT"', 'chown root:root', 'chmod 0644', 'chown -R www-data:www-data "$UPLOADS"', 'chmod 0640 "$WEB_ROOT/wp-config.php"', 'exec apache2-foreground')) {
	Assert-Contains $launcher $expected "Immutable runtime launcher is missing: $expected"
}
Assert-Contains $launcher 'plugins/akismet' 'Bundled Akismet must be removed after stock web-root initialization.'
Assert-Contains $launcher 'plugins/hello.php' 'Bundled Hello Dolly must be removed after stock web-root initialization.'

Assert-Contains $railway '"healthcheckPath": "/healthz"' 'Railway must probe the static /healthz endpoint.'
Assert-Contains $snapshot '--sort=-%cpu' 'Runtime diagnostics must show non-Apache high-CPU processes such as a miner.'
Assert-Contains $snapshot "-perm /022" 'Runtime diagnostics must report writable PHP-family files.'

Assert-Contains $booking "private const RATE_LIMIT_OPTION = 'glc_checkout_rate_limit';" 'Checkout throttling must use one fixed-size option.'
Assert-Contains $booking 'private const RATE_LIMIT_MAX = 20;' 'Checkout global write ceiling must remain bounded.'
Assert-Contains $booking "update_option( self::RATE_LIMIT_OPTION, `$state, false )" 'The fixed rate-limit state must not autoload.'
if ($booking.Contains("'glc_rl_' . md5")) {
	throw 'Per-IP transient keys cause attacker-controlled wp_options growth.'
}
foreach ($expected in @("'maxLength' => 120", "'maxLength' => 254")) {
	Assert-Contains $booking $expected "Booking REST schema is missing $expected."
}
foreach ($expected in @('maxlength="120"', 'maxlength="254"')) {
	Assert-Contains $blocks $expected "Booking form is missing $expected."
}
foreach ($expected in @('strlen( $name ) > 120', 'strlen( $email ) > 254', 'strlen( $phone ) > 40', 'strlen( $message ) > 3000')) {
	Assert-Contains $contact $expected "Contact handler is missing server-side bound: $expected."
}

Write-Output 'Runtime security hardening contract passed.'
