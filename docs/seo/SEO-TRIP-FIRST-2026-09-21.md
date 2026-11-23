# Trip-first SEO implementation — 21 September 2026

Implemented in the local WordPress checkout on `seo/evidence-led-growth-2026-09`. No production deployment, Git commit, push, customer message or external booking was performed. Existing uncommitted news, vehicle, gateway and deployment work was preserved.

## The three skills selected

The Skills CLI's `npx skills find seo` returned these as its three highest-installed SEO-specific results. Counts are observations from this session, not evidence that the skills guarantee rankings. The directory reported approximately 51K GitHub stars for their shared source repository.

| Skill | Observed installs | Applied here |
| --- | ---: | --- |
| [SEO Audit](https://skills.sh/coreyhaines31/marketingskills/seo-audit) | 210.9K | Inspected existing indexing, routing, sitemap, schema and content; verified the new page in all locales; found the broken catalog health link. |
| [Programmatic SEO](https://skills.sh/coreyhaines31/marketingskills/programmatic-seo) | 134.2K | A bounded comparison using actual booking settings, with distinct route decisions and seven complete translations. One useful page, rather than airport/route permutations as separate URLs. |
| [AI SEO](https://skills.sh/coreyhaines31/marketingskills/ai-seo) | 128.2K | Sourced direct answers, matching visible content and structured data, equivalent Markdown and HTML, and a link from the existing agent guide. |

SEO Audit and AI SEO were already available locally. Programmatic SEO was read from its [source SKILL.md](https://github.com/coreyhaines31/marketingskills/blob/main/skills/programmatic-seo/SKILL.md), including the playbooks reference, and applied without installing a new application dependency. Find Skills supported discovery; Frontend Design and Agent Browser supported implementation and visual checks. Systematic Debugging was used to resolve validation issues from observed evidence.

## Research and intent

**[OBSERVED]** Public search results and traveler discussions repeatedly ask about first-night logistics, whether a car is useful during a city stay, five-person luggage needs and the difference between a destination and a particular mountain-road section. These are qualitative intent signals. **Search volume, keyword difficulty, traffic, rankings, AI citation rate and conversion lift are UNMEASURED.** No Search Console dataset or paid keyword account was accessed.

| Traveler problem / query family | Evidence | Implementation |
| --- | --- | --- |
| “Kutaisi airport 4 am car rental”, “late flight Kutaisi transfer” | [Traveler arriving at 04:00 before three city days](https://www.reddit.com/r/Sakartvelo/comments/1qdy9sy/boys_trip_car_rental_options_in_georgia/); [official airport bus operators](https://kutaisi.aero/en/transport/bus-transfer) | First bed before first mountain pass; rest/transfer option; flight delay and handover checklist. Operator availability is not presented as a guaranteed departure for a given flight. |
| “Do I need a car in Tbilisi?”, “rent only for Kazbegi” | [Traveler comparing Kazbegi days with Tbilisi days](https://www.reddit.com/r/Sakartvelo/comments/1eeicmr/); [official Tbilisi airport bus page](https://www.tbilisiairport.com/en-EN/bus/page/bus) | Consider collecting on departure from the city; compare actual totals because length discounts can change the answer. No guaranteed saving or hard-coded bus timetable. |
| “Georgia itinerary 7 days car”, “five people rental luggage” | [Seven-day trip and vehicle-choice discussion](https://www.reddit.com/r/Sakartvelo/comments/1kqyjj4/7day_road_trip_in_georgia_advice_on_itinerary_car/) | Ask about actual bags and child seats. Seat count is not used as a luggage-capacity claim. |
| “Do I need 4x4 Kazbegi?”, “Mestia Ushguli rental”, “Tusheti rental permission” | Existing Geolander route guides; [Georgia Travel mountain-pass guidance](https://georgia.travel/the-most-beautiful-road-passes-of-georgia) | Separate paved main routes, side trips and seasonal passes; distinguish rental permission from current suitability; link the detailed existing guides when published. |
| “Kutaisi vs Tbilisi car rental delivery”, “Kutaisi pickup Batumi return” | First-party `GLC_Rental` location settings and booking calculation | Server-render all nine airport pairs. Same-airport return charges both directions. Mixed airports add their respective one-way fees. Missing paid-location fees say “Confirm with us.” |

**[INFERENCE]** Geolander's opportunity is to help travelers make a better rental decision before choosing a vehicle. The homepage line “Two nights in Tbilisi? The car can wait.” makes that position visible. This is a proposed commercial distinction, not a claim that no competitor offers planning advice.

**[FIRST-PARTY]** Google's current [AI search guidance](https://developers.google.com/search/docs/fundamentals/ai-optimization-guide) emphasizes helpful original content and says special AI files are unnecessary for Google visibility. The existing Markdown and discovery surfaces are maintained for agent interoperability; they are not presented as a Google ranking boost. No invented statistics, reviews, expert biography, route approval or road-opening promise was added.

## Delivered behavior

- `/georgia-road-trip-planner/`, with complete English, Georgian, Russian, Ukrainian, Arabic, Chinese and French content.
- A route selector, airport-pair comparison and copyable trip brief. Selection changes discard the previous brief. Clipboard denial has a manual-copy fallback. No personal information is collected; the form makes no booking or external request.
- All five decision sections and the complete fee matrix are present in the initial HTML. Without JavaScript, native anchor navigation and the comparison remain usable.
- The fee matrix and copied brief clearly exclude car rental, flights, fuel and other travel costs. No customer savings are fabricated.
- A homepage entry and a contextual link from the existing airport-cost resource. Detailed route links are conditional on published pages, so absent fixtures/drafts do not create broken links.
- Localized titles and descriptions, self-canonicals, eight reciprocal hreflang entries, native sitemap coverage, `CollectionPage`/`WebPageElement` and breadcrumb schema.
- The same translated advice and delivery values in the negotiated Markdown response; the published page is linked from `/llms.txt`.
- RFC 9727 catalog status links corrected from `/health.php` to `/healthz`. The former is a live 404; the latter returns 200. Optional media-type hints were omitted for the extensionless health resource.
- Plugin version `1.8.1`, theme version `1.5.1` for asset invalidation.

The design keeps the existing Kazbegi Dusk colors and self-hosted fonts. No images, font packages, trackers or third-party JavaScript were added.

## Validation

| Check | Result |
| --- | --- |
| Planner PHP behavior checks | **41 passed**, including changing fractional fees, unknown/negative/nonfinite fees, draft publication guards, all seven catalogues and HTML/Markdown/schema consistency. |
| Planner HTTP integration audit | **224 passed** across all seven locales; includes catalog-linked docs, specification and health reachability. |
| Existing strict HTTP SEO audit | **21/21 passed** locally. |
| Existing PHP SEO and evidence/tool regressions | **19 + 19 passed**. |
| Existing PowerShell contracts | Agent readiness, SEO content, P2/P3 pages and owner-facts contracts passed. |
| PHP syntax | All plugin and theme PHP files passed; new migration and test also checked. |
| JavaScript / diff checks | Syntax checks and `git diff --check` passed. |
| Migration rerun | Existing published planner preserved; no duplicate created. |
| Browser interaction | English KUT → BUS yields $166 from fixture settings; copy succeeds. Arabic KUT → TBS yields 68 USD; changing an input hides the stale brief. |
| Mobile / RTL | English and Arabic checked at 390px; Arabic document scroll width 375px, without horizontal page overflow. Rendered JSON-LD includes the expected localized page and breadcrumb nodes. |
| Requested live agent-readiness scan | `POST https://isitagentready.com/api/scan` for `https://geo-lander.com`: **`checks.discovery.apiCatalog.status = "pass"`**, two APIs, HTTP 200 and correct `application/linkset+json` type. This predates deployment of the health-link correction. |
| Full repository schema guard | **759 checks, 17 errors, 12 warnings. Not a passing release gate.** Nine errors are absent local production fixture URLs; eight concern the unconfigured local Web Bot Auth signing key. No check was disabled or security configuration weakened. |

The full-guard missing URLs are the Wrangler and Forester fixture pages, Gergeti place, Tbilisi city, Kazbegi rental, 4x4 category, and driver/general-driving/winter-driving guides. Its other eight errors follow the local key directory's 503 response. The local test database and public production fleet are different datasets; no local fleet counts or prices were published as live production facts.

Screenshots: [desktop](2026-09-21-trip-desktop.png), [English mobile](2026-09-21-trip-mobile.png), [Arabic mobile](2026-09-21-trip-ar-mobile.png), [homepage entry](2026-09-21-trip-home.png).

## Release scope

Only the following new migration is required for this feature:

```sh
wp eval-file /migration/setup-trip-planner.php --allow-root
```

It creates the planner if absent, including translated title/body metadata. It preserves any existing page at that slug, including a draft. It does not rerun old route/vehicle imports or overwrite editorial work. No new rewrite rule is needed. Restart PHP through the normal release workflow because production opcache does not check changed files.

Local preparation also ran the existing `setup-agent-readiness.php` **only on the isolated test database**, where About and Developers were absent, to supply catalog-link fixtures. That script is **not** part of this production release manifest.

Use the existing [Oracle release process](../ORACLE-DEPLOYMENT-RUNBOOK.md). The local deployment handoff explicitly forbids deploying the dirty checkout and requires agreement on the pre-existing news/application work. This implementation has not silently included that work in a commit or production release. The current checkout is a reviewable implementation, not a reviewed deployable commit.

After a clean reviewed release and the new migration, run:

```sh
node _migration/audit-trip-planner.mjs https://geo-lander.com
node _migration/audit-seo-http.mjs https://geo-lander.com --strict --tools
node _migration/validate-schema.mjs https://geo-lander.com
```

Confirm the scanner remains `pass`, inspect all seven planner variants and complete the ordinary origin/production checks. Do not equate passing implementation checks with Google indexation, Safe Browsing clearance or ranking gains.

## Measurement after publication

Review the planner query/page data in Search Console after Google has crawled it. Compare qualified inquiries that mention a route, dates, party size and luggage against the existing baseline, if one is available. Do not count a copied brief or WhatsApp click as a paid booking. Refresh the dated transport/route advice when its official sources change; configured delivery prices update automatically. Native-language editorial review is appropriate before future expansion, especially for any new contractual wording.
