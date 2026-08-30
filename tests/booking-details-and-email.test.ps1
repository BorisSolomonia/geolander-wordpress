$ErrorActionPreference = 'Stop'

$root = Split-Path -Parent $PSScriptRoot
$rental = Get-Content -Raw -Encoding utf8 (Join-Path $root 'wp-content/plugins/geolander-core/includes/class-glc-rental.php')
$booking = Get-Content -Raw -Encoding utf8 (Join-Path $root 'wp-content/plugins/geolander-core/includes/class-glc-booking.php')
$gateway = Get-Content -Raw -Encoding utf8 (Join-Path $root 'wp-content/plugins/geolander-core/includes/class-glc-gateways.php')
$email = Get-Content -Raw -Encoding utf8 (Join-Path $root 'wp-content/plugins/geolander-core/includes/class-glc-booking-email.php')
$blocks = Get-Content -Raw -Encoding utf8 (Join-Path $root 'wp-content/plugins/geolander-core/includes/class-glc-blocks.php')
$pattern = Get-Content -Raw -Encoding utf8 (Join-Path $root 'wp-content/themes/geolander/patterns/car-page.php')
$facts = Get-Content -Raw -Encoding utf8 (Join-Path $root '_migration/sync-booking-facts.php')
$cities = Get-Content -Raw -Encoding utf8 (Join-Path $root '_migration/setup-cities.php')

foreach ($expected in @('kutaisi_each_way', 'batumi_each_way', 'pickup_fee', 'return_fee', 'prepayment', 'balance')) {
	if ($rental -notmatch [regex]::Escape($expected)) {
		throw "Location-aware quote is missing: $expected"
	}
}

foreach ($expected in @("'email'", "'pickup'", "'return'", 'is_email')) {
	if ($booking -notmatch [regex]::Escape($expected)) {
		throw "Checkout validation is missing: $expected"
	}
}

foreach ($expected in @('glc_rental_total', 'glc_pickup_fee', 'glc_return_fee', 'glc_prepayment', 'glc_balance', 'glc_email')) {
	if ($gateway -notmatch [regex]::Escape($expected)) {
		throw "Booking record is missing: $expected"
	}
}

foreach ($expected in @('Open email draft', 'Send confirmation now', 'GLC_SMTP_HOST', 'Booking confirmed', 'Not covered: tyres')) {
	if ($email -notmatch [regex]::Escape($expected)) {
		throw "Confirmation workflow is missing: $expected"
	}
}
if ($email -match 'tyres and the vehicle interior') {
	throw 'Confirmation email still contradicts the latest owner-confirmed coverage.'
}

foreach ($expected in @('glc-b-email', 'glc-b-pickup', 'glc-b-return', 'glc-b-total', 'rental_coverage_value')) {
	if ($blocks -notmatch [regex]::Escape($expected)) {
		throw "Vehicle booking page is missing: $expected"
	}
}
if ($blocks -match 'private static function whatsapp_link') {
	throw 'Fleet cards still bypass the email-required reservation form with a direct WhatsApp link.'
}
foreach ($expected in @('$url . ''#glc-booking''', 'glc_ui( ''book_whatsapp'' )')) {
	if ($blocks -notmatch [regex]::Escape($expected)) {
		throw "Fleet card does not route car bookings through the email-required form: $expected"
	}
}
if (-not $gateway.Contains("'Email: ' . `$customer['email']")) {
	throw 'The generated WhatsApp reservation message does not include the required customer email.'
}
if ($pattern -notmatch 'geolander/rental-facts') {
	throw 'Every car page must render the complete rental facts block.'
}

foreach ($expected in @('Hybrid', 'AWD', 'GG581WG', 'toyota-rav4-2016-gg581wg')) {
	if ($facts -notmatch [regex]::Escape($expected)) {
		throw "RAV4 consolidation is missing: $expected"
	}
}
foreach ($expected in @('cars.json', 'GLC_Pricing::normalize', 'glc_pricing')) {
	if ($facts -notmatch [regex]::Escape($expected)) {
		throw "RAV4 production price recovery is missing: $expected"
	}
}
if ($facts -notmatch '25.*30%') {
	throw 'RAV4 consolidation is missing the owner-confirmed fuel-economy claim.'
}

foreach ($stale in @('Free delivery to Batumi Airport', 'Free pickup at Kutaisi Airport', 'Free airport delivery, full insurance')) {
	if ($cities -match [regex]::Escape($stale)) {
		throw "City content still contains a false free-delivery claim: $stale"
	}
}
foreach ($expected in @('$68 pickup and $68 return', '$98 pickup and $98 return')) {
	if ($cities -notmatch [regex]::Escape($expected)) {
		throw "City content is missing owner-confirmed charge: $expected"
	}
}

Write-Output 'Booking details and email contract passed: RAV4 facts, coverage, per-direction charges, final quote fields, and confirmation generator.'
