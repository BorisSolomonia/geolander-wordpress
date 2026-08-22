$ErrorActionPreference = 'Stop'

$root = Split-Path -Parent $PSScriptRoot
$ai = Get-Content -Raw -Encoding utf8 (Join-Path $root 'wp-content/plugins/geolander-core/includes/class-glc-ai.php')
$seo = Get-Content -Raw -Encoding utf8 (Join-Path $root 'wp-content/plugins/geolander-core/includes/class-glc-seo.php')
$perf = Get-Content -Raw -Encoding utf8 (Join-Path $root 'wp-content/plugins/geolander-core/includes/class-glc-perf.php')
$schema = Get-Content -Raw -Encoding utf8 (Join-Path $root 'wp-content/plugins/geolander-core/includes/class-glc-schema.php')
$notFound = Get-Content -Raw -Encoding utf8 (Join-Path $root 'wp-content/themes/geolander/patterns/not-found.php')
$footer = Get-Content -Raw -Encoding utf8 (Join-Path $root 'wp-content/themes/geolander/patterns/footer.php')
$migration = Get-Content -Raw -Encoding utf8 (Join-Path $root '_migration/setup-agent-readiness.php')
$validator = Get-Content -Raw -Encoding utf8 (Join-Path $root '_migration/validate-schema.mjs')

foreach ($expected in @('text/markdown; charset=utf-8', 'Vary: Accept, Accept-Encoding', 'preferred_representation', 'status_header( 406 )', 'status_header( 404 )')) {
	if (-not $ai.Contains($expected)) {
		throw "Markdown negotiation contract is missing: $expected"
	}
}

foreach ($expected in @('agent-instructions.md', 'openapi.json', 'Geolander Reservation API', 'When to use Geolander', 'explicit user approval')) {
	if (-not $ai.Contains($expected)) {
		throw "Agent/developer resource is missing: $expected"
	}
}

foreach ($bot in @('ChatGPT-User', 'ClaudeBot', 'Claude-SearchBot', 'Google-Extended', 'PerplexityBot', 'Bingbot', 'DeepSeekBot', 'ora-agent')) {
	if (-not $seo.Contains("'$bot'")) {
		throw "robots.txt generator is missing an explicit allow for $bot"
	}
}

foreach ($expected in @("get_query_var( 'robots' )", 'Cache-Control: no-store, max-age=0')) {
	if (-not $perf.Contains($expected)) {
		throw "robots.txt cache-safety contract is missing: $expected"
	}
}

foreach ($expected in @("[ 'Organization', 'LocalBusiness', 'AutoRental' ]", "'contactPoint'", "'PostalAddress'", "'contactType'", "'email'", "'telephone'")) {
	if (-not $schema.Contains($expected)) {
		throw "Organization identity schema is incomplete: $expected"
	}
}

foreach ($expected in @('/llms.txt', '/wp-sitemap.xml', '/developers/')) {
	if (-not $notFound.Contains($expected)) {
		throw "HTML 404 is missing recovery resource: $expected"
	}
}

foreach ($expected in @("'about'", 'About Geolander Car Rental', 'Geolander Developer Resources', 'OpenAPI 3.1 specification')) {
	if (-not $migration.Contains($expected)) {
		throw "Agent-readiness page migration is missing: $expected"
	}
}
if (-not $footer.Contains("get_page_by_path( 'about' )")) {
	throw 'The published About page is not discoverable from the site-wide footer.'
}

foreach ($locale in @('en', 'ka', 'ru', 'uk', 'ar', 'zh', 'fr')) {
	$catalog = Get-Content -Raw -Encoding utf8 (Join-Path $root "wp-content/themes/geolander/inc/strings-$locale.php")
	foreach ($key in @('nav_about', 'nf_agent_recovery')) {
		if (-not $catalog.Contains("'$key'")) {
			throw "Locale $locale is missing UI string $key"
		}
	}
}

foreach ($expected in @('agent content negotiation', 'agent-friendly 404', 'agent crawler reachability', 'developer resources')) {
	if (-not $validator.Contains($expected)) {
		throw "Public endpoint validator is missing: $expected"
	}
}

Write-Output 'Agent readiness contract passed: crawler allows, Markdown negotiation, recovery 404, Organization identity, About, instructions, and OpenAPI.'
