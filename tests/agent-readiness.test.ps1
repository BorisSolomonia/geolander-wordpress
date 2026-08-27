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
$mcp = Get-Content -Raw -Encoding utf8 (Join-Path $root 'wp-content/plugins/geolander-core/includes/class-glc-mcp.php')
$a2a = Get-Content -Raw -Encoding utf8 (Join-Path $root 'wp-content/plugins/geolander-core/includes/class-glc-a2a.php')
$webBotAuth = Get-Content -Raw -Encoding utf8 (Join-Path $root 'wp-content/plugins/geolander-core/includes/class-glc-web-bot-auth.php')

foreach ($expected in @('text/markdown; charset=utf-8', 'Vary: Accept, Accept-Encoding', 'preferred_representation', 'status_header( 406 )', 'status_header( 404 )')) {
	if (-not $ai.Contains($expected)) {
		throw "Markdown negotiation contract is missing: $expected"
	}
}

foreach ($expected in @('agent-instructions.md', 'openapi.json', 'auth.md', 'index_markdown', "^\.well-known/api-catalog/?$", 'oauth-protected-resource', 'ai-catalog.json', 'agent-skills/index.json', 'geolander-car-rental/SKILL.md', 'mcp/server-card', 'agent-card.json', 'http-message-signatures-directory', 'application/mcp-server-card+json', 'application/a2a+json', 'application/linkset+json', 'application/ai-catalog+json', 'https://www.rfc-editor.org/info/rfc9727', 'https://schemas.agentskills.io/discovery/0.2.0/schema.json', "'service-desc'", "'service-doc'", "'status'", "'digest'", "'scopes_supported'", "'bearer_methods_supported'", "'agent_auth'", "'register_uri'", "'identity_types_supported'", "'credential_types_supported'", "'claim_uri'", "'revocation_uri'", "'upstream_issuer'", "'upstream_authorization_server'", "untrailingslashit( home_url( '/' ) )", 'geolander.agent', 'supportedInterfaces', 'protocolBinding', 'protocolVersion', 'defaultInputModes', 'defaultOutputModes', 'securityRequirements', 'Geolander Reservation API', 'When to use Geolander', 'explicit user approval', 'OAuth public-client registration', 'client_id', 'access_token', 'refresh_token')) {
	if (-not $ai.Contains($expected)) {
		throw "Agent/developer resource is missing: $expected"
	}
}

foreach ($expected in @('GLC_WEB_BOT_AUTH_PRIVATE_KEY', 'application/http-message-signatures-directory+json', 'Content-Digest', 'Signature-Agent', 'Signature-Input', 'Signature', 'sodium_crypto_sign_detached', '"@authority";req', '"signature-agent"', 'REQUEST_TAG', 'web-bot-auth', 'glc_web_bot_auth', 'Geolander-Agent/')) {
	if (-not $webBotAuth.Contains($expected)) {
		throw "Web Bot Auth contract is missing: $expected"
	}
}

foreach ($expected in @('SendMessage', 'A2A-Version', 'ROLE_USER', 'ROLE_AGENT', 'fleet-discovery', 'rental-policy', 'rental-quote', 'GLC_MCP::fleet_data', 'GLC_MCP::policy_data', 'GLC_MCP::quote_data', 'readOnly')) {
	if (-not $a2a.Contains($expected)) {
		throw "A2A v1.0 server contract is missing: $expected"
	}
}

foreach ($expected in @('server/discover', '2026-07-28', 'tools/list', 'tools/call', 'list_fleet', 'get_booking_policy', 'get_rental_quote', 'readOnlyHint', 'Cloudflare JWT')) {
	if (-not $mcp.Contains($expected)) {
		throw "MCP server contract is missing: $expected"
	}
}

foreach ($expected in @('Content-Signal: search=yes, ai-input=yes, ai-train=no', 'Agentmap:')) {
	if (-not $seo.Contains($expected)) {
		throw "robots.txt agent policy is missing: $expected"
	}
}

foreach ($expected in @('document.modelContext || navigator.modelContext', 'modelContext.registerTool', 'get_geolander_policy', 'list_geolander_fleet', 'get_geolander_quote', 'availability_status', 'reservation_status', 'readOnlyHint')) {
	if (-not $ai.Contains($expected)) {
		throw "WebMCP contract is missing: $expected"
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

foreach ($expected in @("'about'", 'About Geolander Car Rental', 'Geolander Developer Resources', 'OpenAPI 3.1 specification', 'RFC 9727 Linkset discovery')) {
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

foreach ($expected in @('agent content negotiation', 'agent-friendly 404', 'agent crawler reachability', 'developer resources', 'RFC 9727 API catalog', 'RFC 9728 protected resource metadata and auth.md', 'ARD capability manifest and Agent Skills discovery', 'skill digest mismatch', 'MCP server discovery and OAuth boundary', 'application/mcp-server-card+json', 'A2A v1.0 Agent Card and OAuth boundary', 'application/a2a+json', 'Web Bot Auth signed key directory', 'application/http-message-signatures-directory+json', 'WebMCP read-only browser tools')) {
	if (-not $validator.Contains($expected)) {
		throw "Public endpoint validator is missing: $expected"
	}
}

Write-Output 'Agent readiness contract passed: crawler allows, Markdown negotiation, recovery 404, Organization identity, About, instructions, and OpenAPI.'
