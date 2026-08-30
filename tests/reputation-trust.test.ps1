$ErrorActionPreference = 'Stop'

$root = Split-Path -Parent $PSScriptRoot
$homePattern = Get-Content -Raw -Encoding utf8 (Join-Path $root 'wp-content/themes/geolander/patterns/home-sections.php')
$hero = Get-Content -Raw -Encoding utf8 (Join-Path $root 'wp-content/themes/geolander/patterns/hero.php')
$settings = Get-Content -Raw -Encoding utf8 (Join-Path $root 'wp-content/plugins/geolander-core/includes/class-glc-settings.php')
$schema = Get-Content -Raw -Encoding utf8 (Join-Path $root 'wp-content/plugins/geolander-core/includes/class-glc-schema.php')
$about = Get-Content -Raw -Encoding utf8 (Join-Path $root '_migration/setup-agent-readiness.php')
$pages = Get-Content -Raw -Encoding utf8 (Join-Path $root '_migration/setup-pages.php')
$cleanup = Get-Content -Raw -Encoding utf8 (Join-Path $root '_migration/setup-reputation-trust.php')
$testimonials = Get-Content -Raw -Encoding utf8 (Join-Path $root '_migration/testimonials.json') | ConvertFrom-Json

foreach ($claim in @('ALL-INCLUSIVE', '$0 EXTRAS', 'Free second driver', '24/7 roadside assistance', 'geolander/testimonials')) {
	if ($homePattern.Contains($claim)) {
		throw "Homepage still publishes an unverified or misleading claim: $claim"
	}
}
foreach ($proof in @('google_maps_url', 'contact_google_rating', 'glc-google-reviews-title', 'view_on_map')) {
	if (-not $homePattern.Contains($proof)) {
		throw "Homepage is missing linked Google proof: $proof"
	}
}
if (-not $hero.Contains('price_range_sentence') -or $hero.Contains('range_display()')) {
	throw 'Hero price range must use an explicit localized from/to sentence.'
}

foreach ($locale in @('en', 'ka', 'ru', 'uk', 'ar', 'zh', 'fr')) {
	$catalog = Get-Content -Raw -Encoding utf8 (Join-Path $root "wp-content/themes/geolander/inc/strings-$locale.php")
	foreach ($key in @('included_title', 'included_4', 'included_6', 'testimonials_title', 'price_range_sentence')) {
		if ($catalog -notmatch "'$key'") {
			throw "Locale $locale is missing reputation/trust string: $key"
		}
	}
	foreach ($stale in @('Free second driver', '24/7 roadside assistance', 'Second conducteur gratuit', 'Assistance routière 24h/24')) {
		if ($catalog.Contains($stale)) {
			throw "Locale $locale still contains an unverified inclusion: $stale"
		}
	}
}

if ($testimonials.Count -ne 3 -or ($testimonials | Where-Object visible).Count -ne 0) {
	throw 'Unverifiable seed testimonials must remain unpublished.'
}
foreach ($seed in @('Marco & Elena', 'Sarah Johnson', 'Thomas Weber')) {
	if (-not $cleanup.Contains($seed)) {
		throw "Cleanup migration does not unpublish seed testimonial: $seed"
	}
}
if (-not $cleanup.Contains("'post_status' => 'draft'")) {
	throw 'Cleanup migration must retain seed testimonials as recoverable drafts.'
}

foreach ($field in @('legal_name', 'georgian_id', 'geolander_business_identity')) {
	if (-not $settings.Contains($field)) {
		throw "Business identity settings are missing: $field"
	}
}
foreach ($marker in @("'legalName'", "'identifier'", "'PropertyValue'", "GLC_Settings::get( 'google_maps_url' )")) {
	if (-not $schema.Contains($marker)) {
		throw "Organization schema is missing conditional identity proof: $marker"
	}
}
if (-not $about.Contains('[geolander_business_identity]')) {
	throw 'About page does not publish the business identity block.'
}
if (-not $pages.Contains("glc_upsert_page( 'terms', 'Terms and Conditions'")) {
	throw 'English Terms page title is not corrected.'
}

foreach ($surface in @($settings, $about, $pages)) {
	if ($surface -match '@geolander\.com') {
		throw 'A site-owned trust surface points email at the unrelated geolander.com domain.'
	}
}

Write-Output 'Reputation trust contract passed: no seed testimonials or zero-extra claim, linked Google proof, clear range, official email, and conditional legal identity.'
