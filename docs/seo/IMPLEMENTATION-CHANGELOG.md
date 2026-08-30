# IMPLEMENTATION-CHANGELOG.md — Geolander

**Date:** 2026-08-14 · **Branch:** `seo/p0-p1-implementation` · **Commits:** `2c248b7`, `34e6f44`
**Nothing has been merged or deployed.** `main` is untouched.

---

## The finding that came out of implementing, not researching

The research said vehicles were publishing `$0/day` and blamed the pricing engine's fallback. Building
the fix found the real cause, and it is much simpler.

**Every sidecar file in `_migration/fleet-import/` is named `car.json.json`. The importer looks for
`car.json`.**

```php
$json = $dir . '/car.json';        // never matched
if ( is_file( $json ) ) { … }      // silently skipped, all 13 folders
```

That is Windows saving a file with *"hide extensions for known file types"* switched on. `is_file()`
returned false for every folder and `import-fleet.php` carried on without a word. **No registration
plate, no seasonal pricing, no body type and no specs were applied to a single imported car.**

Everything the audit found downstream follows from that one extra `.json`:

| Symptom | Cause |
|---|---|
| `Product` schema publishing `lowPrice: 0` | no `glc_pricing`, no `glc_price_from` |
| `/pricing.md` listing 8 cars while `/fleet/` showed 19 | the other 11 had no rate table to print |
| Meta descriptions reading "from $0/day" | same |
| "Duplicate" records indistinguishable | **none of them had a plate**, so nothing could tell two Outlanders apart |

**The sidecars themselves are perfectly fine** — correct shape, real rates ($90 in high season down to
$45 for 31+ days). The data was always there. A filename kept it out of the database.

`[INFERENCE]` This also partly explains the fleet-count confusion in `CORRECTIONS-AND-ERRATA.md`: with
no plates and no specs, the imported cars were never reconcilable against `cars.json` in the first place.

---

## What was changed

### P0 — correctness

| # | Change | File |
|---|---|---|
| 1 | **Locale homepage redirect loop.** `redirect_canonical` is now skipped on the front page. `boot()` strips the locale prefix so `/ru/` reaches WP as `/`, while `home_url('/')` is filtered back to `/ru/`; core compared the two and redirected forever. All six localised homepages failed — and they are every page's `hreflang` targets. `GLC_City` already had this guard; the front page never did | `class-glc-i18n.php` |
| 2 | **Never publish a zero price.** `rate_range()` returns `[0,0]` instead of inventing a floor; `GLC_Schema::car()` omits the `offers` node entirely; titles and meta descriptions drop the price; `llms.txt` says "price on request"; `pricing.md` prints `—` and lists unpriced cars in their own section rather than silently dropping them | `class-glc-pricing.php`, `class-glc-schema.php`, `class-glc-seo.php`, `class-glc-ai.php` |
| 3 | **The importer finds its sidecar** — accepts `car.json` / `car.json.json` / `car.JSON`, falls back to any non-example `*.json`, and **warns loudly** when a folder has none instead of failing silently | `import-fleet.php` |
| 4 | **`normalize()`** — season tables are normalised on *read*, so the engine cannot be broken by whichever key shape is in the database. Defence in depth, not the root cause | `class-glc-pricing.php` |

### P1 — truthfulness

| # | Change |
|---|---|
| 5 | **One source of truth for the price floor.** `GLC_Pricing::fleet_floor()` derives it from the cheapest actually-bookable car. The fleet title advertised `$26`, the hero said `$28`. **Measured against real data, the floor is `$26`** |
| 6 | Places archive title **counts places** instead of hard-coding "36" |
| 7 | `llms.txt` **counts the fleet** and lists real model names instead of hard-coding 15 |
| 8 | Fleet cards show an honest **"from $N/day"** — the archive's own title advertises a price and the page showed none |
| 9 | `"Real 4x4s"` in the title now drops the price clause rather than pairing a trust word with a zero |

### Schema

| # | Change |
|---|---|
| 10 | `offers` omitted when unpriced + a recursive `prune()` so *absent* means absent, not `"offers": null` |
| 11 | `areaServed` lists the **four delivery cities**, not just `Country: Georgia` |
| 12 | City `Service` nodes carry the **`Airport` + IATA code** (TBS/BUS/KUT) that was already stored and unused, plus `availableChannel` and 24/7 `hoursAvailable` |
| 13 | Place nodes carry the **`geo` coordinates** already stored and unused |
| 14 | Business `image` is a photograph; `logo` stays the logo |

### Architecture

| # | Change |
|---|---|
| 15 | **Primary navigation rebuilt** — leads with Fleet, Where you can drive, Locations, Guides, Trust, Contact. Candidates whose page doesn't exist yet are **skipped**, so the nav upgrades itself as pages are published and never links to a 404 |
| 16 | **Visible breadcrumbs on city pages**, rendered from `GLC_Schema::current_trail()` — the same source the JSON-LD reads |
| 17 | `car_brand` / `car_body_type` / **`place_region`** archives removed from the sitemap and set `noindex, follow` |
| 18 | 7 new UI keys added across **all seven locale catalogues** |

### Tooling

| # | Change |
|---|---|
| 19 | **`validate-schema.mjs` is now a regression guard** — fails the build on a zero price, on `$0` anywhere in `llms.txt`/`pricing.md`, and on any locale homepage that doesn't return 200. All three defects shipped because nothing asserted otherwise |
| 20 | **`audit-fleet.php`** — read-only duplicate and completeness report. Merging car posts is destructive; a human picks the survivor |
| 21 | **`setup-seo-pages.php`** — publishes the coverage and guides hubs, creates the three fact-blocked pages as **drafts** |

---

## Verified, not assumed

A standalone harness ran the real `cars.json` through the pricing engine, before and after:

```
BEFORE (raw payload stored verbatim, as import-fleet.php does):
  15 of 15 cars → lowPrice 0, quote $0

AFTER:
  Jeep Renegade 2017        $28–$50   7-day July quote: $308  (avg $44)
  Subaru Forester 2016      $26–$44   7-day July quote: $294  (avg $42)
  Toyota Highlander 2017    $45–$80   7-day July quote: $490  (avg $70)
  Jeep Wrangler 2017        $50–$90   7-day July quote: $560  (avg $80)
  … all 15 priced, all quotes non-zero
  fleet floor: $26

  unpriced car → rate_range [0,0], is_priced false — no false floor invented
```

Also: **PHP lint clean on all 22 changed files** · `node --check` on the validator · zero-price detector
unit-tested (finds 2 in a bad string, 0 in a good one) · `git apply --check` passed before applying.

---

## What was NOT done, and why

**Nothing here invents a business fact.** Five of the highest-value items from the strategy are blocked
on facts only Boris and his insurer hold, so they exist as **drafts with `NEEDS:` markers**, not as
published pages:

| Page | Blocked on |
|---|---|
| `/where-you-can-drive/` | The route-permission decision per road, and whether insurance follows |
| `/trust/deposit-policy/` | The deposit amount, mechanism and release timeline |
| `/trust/what-our-insurance-covers/` | Excess, third-party liability limit, exclusion list |
| Per-vehicle honesty content | Odometer readings, service dates, tyre ages |
| `/terms/` rewrite | The same policy decisions |

`[INFERENCE]` Publishing a permission that cannot be honoured produces a seized deposit and a one-star
review — destroying precisely the trust asset the strategy is built on. A draft with a marker is worth
more than a page with a plausible guess.

**Also not done** — outside code entirely: Google Business Profile, Trustpilot, TripAdvisor, the
post-rental review request, editorial outreach. These are `HUMAN-INTERVENTION-MAP.md` Tiers 1–5 and
remain the highest-value work available.

**Fleet deduplication is deliberately not automated.** `audit-fleet.php` reports; it does not merge.
Now that the importer applies plates, re-running it should make the duplicates resolvable for the first
time.

---

## To run it

```bash
git checkout seo/p0-p1-implementation

# 1. Re-import the fleet — now that the sidecar is actually found
docker compose run --rm cli eval-file /migration/import-fleet.php

# 2. See what the fleet really contains (read-only, changes nothing)
docker compose run --rm cli eval-file /migration/audit-fleet.php

# 3. Create the hubs + the three drafts
docker compose run --rm cli eval-file /migration/setup-seo-pages.php

# 4. Flush rewrites (new pages + the nav depends on them existing)
docker compose run --rm cli rewrite flush

# 5. Guard: fails on zero prices, on "$0" in the AI files, on a locale loop
node _migration/validate-schema.mjs http://localhost:8080
```

**Then check by hand, because I could not:** `/ru/` and `/ka/` return 200 · `/pricing.md` contains no
`$0` · `/fleet/` shows prices · a car page's Rich Results Test passes · the nav renders in every locale.

`[UNVERIFIED]` **None of this has been run against a live WordPress instance.** The container has no
database and the device has no PHP runtime, so every change is verified by lint, by the standalone
pricing harness, and by reading — not by a rendered page. Run step 5 before deploying.
