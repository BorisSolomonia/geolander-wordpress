$ErrorActionPreference = 'Stop'

$root = Split-Path -Parent $PSScriptRoot
$terms = Get-Content -Raw -Encoding utf8 (Join-Path $root '_migration/setup-pages.php')
$termsText = Get-Content -Raw -Encoding utf8 (Join-Path $root '_migration/terms-content.txt')
$faq = Get-Content -Raw -Encoding utf8 (Join-Path $root '_migration/faq.json')
$cities = Get-Content -Raw -Encoding utf8 (Join-Path $root '_migration/setup-cities.php')
$ai = Get-Content -Raw -Encoding utf8 (Join-Path $root 'wp-content/plugins/geolander-core/includes/class-glc-ai.php')
$blocks = Get-Content -Raw -Encoding utf8 (Join-Path $root 'wp-content/plugins/geolander-core/includes/class-glc-blocks.php')
$homeSections = Get-Content -Raw -Encoding utf8 (Join-Path $root 'wp-content/themes/geolander/patterns/home-sections.php')

foreach ($surface in @($terms, $termsText, $faq, $ai)) {
	foreach ($expected in @('10%', '30 days', '50%', 'non-refundable')) {
		if ($surface -notmatch [regex]::Escape($expected)) {
			throw "Booking-policy surface is missing owner-confirmed fact: $expected"
		}
	}
}

foreach ($surface in @($terms, $termsText, $faq)) {
	if ($surface -notmatch 'separate from a security deposit') {
		throw 'Booking prepayment must be distinguished from the no-security-deposit policy.'
	}
}

foreach ($locale in @('en', 'ka', 'ru', 'uk', 'ar', 'zh', 'fr')) {
	$catalog = Get-Content -Raw -Encoding utf8 (Join-Path $root "wp-content/themes/geolander/inc/strings-$locale.php")
	foreach ($key in @('booking_prepayment', 'cancellation_refund')) {
		if ($catalog -notmatch "'$key'") {
			throw "Locale $locale is missing $key."
		}
	}
	if ($catalog -match "'no_prepayment'|'free_cancellation'") {
		throw "Locale $locale still exposes a stale booking-policy key."
	}
}

if ($blocks -notmatch "glc_ui\( 'booking_prepayment' \)" -or $blocks -notmatch "glc_ui\( 'cancellation_refund' \)") {
	throw 'The booking widget does not use the current booking-policy strings.'
}

if ($homeSections -notmatch '10% PREPAYMENT') {
	throw 'The homepage process kicker still contradicts the 10% prepayment policy.'
}

$stalePatterns = @(
	'no prepayment',
	'free cancellation up to 24',
	'cancellations within 24',
	'pay at pickup rather than online',
	'no-show: full rental period charge'
)
$currentSurfaces = @($terms, $termsText, $faq, $cities, $ai, $blocks, $homeSections)
foreach ($surface in $currentSurfaces) {
	foreach ($stale in $stalePatterns) {
		if ($surface -match $stale) {
			throw "A current booking-policy surface still contains stale wording: $stale"
		}
	}
}

Write-Output 'Booking policy contract passed: 10% prepayment, 30-day boundary, 50% refund, and no stale free-cancellation claims.'
