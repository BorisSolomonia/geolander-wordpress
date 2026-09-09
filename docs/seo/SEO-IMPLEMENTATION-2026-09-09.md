# SEO implementation and release checklist — 9 September 2026

Branch: `seo/evidence-led-growth-2026-09`. No merge, push or production deployment was performed in this task. [Research and owner plan](SEO-EVIDENCE-LED-GROWTH-2026-09-09.md).

## Changes

- Stable language URLs, clean switcher links, permanent English-prefix alias, crawlable duplicate directives, archive pagination canonicals and error-page alternate suppression.
- Same-origin protection for malformed English-prefix redirects; translated visible page headings consistent with translated document titles.
- IndexNow protocol correction and non-production/foreign-host submission guards.
- Race-safe quote display, customer-validation recovery, stable submitted-quote analytics and sanitized first-party analytics URL fields.
- Missing seasonal prices omitted instead of shown as zero.
- New airport delivery-cost decision page in seven languages, priced from live settings, linked from existing rental-facts blocks.
- Optional dated vehicle-evidence admin panel and car-page section. Unknown values stay hidden; no fabricated vehicle records.
- Plugin version 1.6.0 and theme version 1.3.0 for cache invalidation.

## Validation performed

| Check | Result |
|---|---|
| Existing production schema guard, before deployment | 1,117 checks; no errors; one warning: Renegade lacks a Product image |
| Existing PowerShell contracts | All 17 passed |
| Booking state regressions | Five failures reproduced on old code; five pass after repair |
| Analytics privacy regressions | Three pass |
| SEO/IndexNow PHP behavior assertions | Nineteen pass |
| Evidence/tool PHP assertions | Nineteen pass |
| Seasonal zero-price regression | Passed after reproducing zero and malformed-value failures; missing, nonnumeric, negative and array values omitted |
| Local HTTP integration probe | All 21 pass, including the seven new tool variants, same-origin alias guard and duplicate archive redirect |
| Visible-title PHP regressions | Five pass |
| Updated full guard on incomplete local fixtures | 697 checks, 19 errors, 14 warnings; NOT a passing release check |
| Rendered browser check | English and Arabic at 390px width; no page errors reported during the comparison-page check |
| New migration rerun | Existing page preserved; no duplicate created |
| Syntax / whitespace | Changed PHP files passed `php -l`; JavaScript passed syntax checks; `git diff --check` clean |

Local test project: `geolander-seo-audit-20260909`. Its own WordPress/MariaDB volumes were created, with no connection to production data. The fixture fleet import created 12 listings from 13 folders after skipping a duplicate; this is **not** the production fleet count. It deliberately did not import customer bookings.

The isolated test containers were stopped after verification and their volumes retained for reproducibility. The isolated browser session was closed. No production or unrelated containers were stopped.

The full local guard's 19 errors are eleven absent production fixture pages and eight Web Bot Auth failures because a private signing key was not provisioned in the audit environment. Its warnings include the unpriced local RAV4 fixture and missing content. The old live guard result is a production baseline, not proof that the new branch has passed the updated full guard in production. Do not call deployment verified until that post-release check passes. No security check was disabled to obtain a green result. Mobile captures: [English](2026-09-09-airport-mobile.png), [Arabic](2026-09-09-airport-ar-mobile.png). The new page uses existing theme styles with page-scoped gutters; measured English main padding was 20px at a 390px viewport, without horizontal overflow.

The local image’s hardened PHP disables process launch, so `wp rewrite structure` reported a child-process error after saving the structure. A separate `wp rewrite flush` succeeded; the subsequent HTTP tests confirmed pretty permalinks. Do not weaken production PHP hardening to avoid that WP-CLI convenience error.

## Release, only after approval

1. Confirm Google’s security-review state and a recoverable backup. Review the branch diff and the owner facts register.
2. Follow the existing Oracle deployment runbook with the correct repository/project. Do not rerun every legacy importer: some overwrite editorial content or recreate drafts.
3. Ensure the new image/code is running and PHP/opcache has been restarted through the deployment workflow. Then run inside the production WordPress container:

```bash
wp eval-file /migration/setup-evidence-seo.php --allow-root
wp rewrite flush --allow-root
```

4. The migration creates only `/georgia-airport-rental-costs/` when absent; any existing page at that slug is left unchanged. Its tool derives fees on render. The new vehicle fields require no bulk data migration.
5. Purge affected public HTML/robots resources from Cloudflare as needed. Confirm no edge rule restores language-dependent redirects or caches a personalized/HTML variant incorrectly. Preserve origin/WAF protection; do not broadly disable security.
6. From the workstation:

```bash
node _migration/validate-schema.mjs https://geo-lander.com
node _migration/audit-seo-http.mjs https://geo-lander.com --strict --tools
```

7. Check the homepage over HTTP and www, canonical destination, all existing language homepages, `robots.txt`, sitemaps, `/font` and a random missing path. Nonexistent pages must remain real 404s. Open a car and the new tool on a phone-width viewport, including Arabic. Verify the new tool’s table, links and language text.
8. In WordPress, add one genuinely documented vehicle record; verify its visible date/values and omission of absent fields. Do not enter fixture odometers or future dates on production.
9. Inspect analytics/Ads settings with the owner. Disable or sanitize automatic outbound/form events that could independently capture WhatsApp text or personal data. Validate consent configuration. Our first-party events do not prove all external tags are private.
10. Run a labelled test inquiry using an owner-controlled mailbox with approval; verify receipt, manual confirmation and the distinction between inquiry value and actual revenue. Do not send a real customer a test message.
11. Annotate the deployment date in the measurement log. Request indexing only for relevant changed canonical URLs and submit the actual sitemap. Keep any existing URL retired by a later content change on a relevant 301.

Do not mark this release complete merely because the new page returns 200: both regressions and production guard must pass, and the owner must inspect the booking workflow.

## Reproduce isolated tests

```powershell
node --test tests/booking-state.test.mjs tests/analytics-privacy.test.mjs
docker run --rm --mount type=bind,source=C:/Users/Boris/Dell/Projects/APPS/Geolander/Geolander_WordPress,target=/app,readonly --workdir /app php:8.3-cli php tests/seo-regression.php
docker run --rm --mount type=bind,source=C:/Users/Boris/Dell/Projects/APPS/Geolander/Geolander_WordPress,target=/app,readonly --workdir /app php:8.3-cli php tests/evidence-tools.php
docker run --rm --mount type=bind,source=C:/Users/Boris/Dell/Projects/APPS/Geolander/Geolander_WordPress,target=/app,readonly --workdir /app php:8.3-cli php tests/price-table.php
docker run --rm --mount type=bind,source=C:/Users/Boris/Dell/Projects/APPS/Geolander/Geolander_WordPress,target=/app,readonly --workdir /app php:8.3-cli php tests/content-titles.php
node _migration/audit-seo-http.mjs http://localhost:8080 --strict --tools
```

Adjust the explicit workspace mount path on another workstation. The PHP behavior harnesses have stubbed WordPress/HTTP functions; local HTTP tests supply the integration layer. They do not replace a real-browser test or production account checks.

## Owner data workflow

WordPress → Cars → edit the actual car → **Dated vehicle evidence — verified facts only**. Fill the date the observations were recorded and whichever fields you can substantiate. Keep supporting records privately; do not upload invoices with customer/employee personal information. Record real markings rather than inferring tyre condition from a generic model name. Save and inspect the front end. Dates use unambiguous `YYYY-MM-DD`; odometer units are km.

Missing facts suppress the relevant row, and a missing valid check date suppresses the whole panel. A service date after the observation date is rejected. The tool does not automatically certify continuing condition; the visible note asks customers to request updated photographs.

## Rollback

Use the existing release/backup procedure to return to the previous reviewed application image if required. The new metadata is additive. Preserve the new page URL if it has gone public: retain useful content or 301 it to the most relevant survivor rather than deleting it. Do not restore customer bookings from an old full database backup merely to undo these additive SEO fields.
