# CORRECTIONS-AND-ERRATA.md — Geolander SEO engagement

**Date:** 2026-08-14 · **Read this before acting on any other document in the set.**

After the twenty deliverables were written, an independent fact-checking agent audited all of them
against the source repository, looking specifically for fabricated metrics, inference presented as fact,
unacknowledged contradictions between files, and inaccurate claims about the codebase.

**It found real errors. They are listed here, and they have been corrected in the affected files.**

---

## What the audit confirmed as clean

- **No fabricated metrics anywhere.** Across ~5,900 lines, no invented search volume, keyword
  difficulty, domain authority, backlink count, referring-domain count, traffic estimate, CTR or rank
  position. All 37 rows of `KEYWORD-UNIVERSE.csv` carry `UNMEASURED` / `UNKNOWN` / `NOT OBSERVED`.
- **All seven codebase claims verified correct** against the source: the missing `redirect_canonical`
  filter, the `rate_range()` zero-price path, the sitemap taxonomy exclusions, the front-page cache
  header, the hard-coded "36", the 15 cars with empty descriptions, and the terms-page contradictions.
- **No overclaiming.** No guarantee of rankings, traffic or bookings. The 90-day roadmap includes an
  explicit *"what will NOT happen"* section.

---

## Corrections applied

### C-1 · Fleet arithmetic was wrong, in four documents **[HIGH]**

I asserted **six Foresters** and **five Outlanders**, and twice recommended a **Toyota 4Runner** as an
upsell — including in `PAGE-LEVEL-SEO-BLUEPRINT.md`, which specifies customer-facing page copy.

**The verified position, and it is genuinely ambiguous:**

| Source | Foresters | Outlanders | 4Runner | Renegade | Total |
|---|---|---|---|---|---|
| `_migration/cars.json` (older import) | **7** | **2** | **no** | yes | 15 |
| Live `/fleet/` page | 7 | **5** | **yes** | **no** | 19 |
| `_migration/fleet-import/` folders | 5 | 5 | yes | no | 13 |

So *"six Foresters"* was simply wrong — it is **seven** in both sources, which slightly **understates**
the exposure to starcar.ge's *"no low-range gearbox"* argument. *"Five Outlanders"* is true of the live
site but not of the source data, and at least two of those five appear to be the same physical car.
The **4Runner exists on the live site and in the import folders but not in `cars.json`** — so
recommending it as an upsell was premature rather than invented.

**All four documents now state the source explicitly rather than asserting a single number.**
`[INFERENCE]` This discrepancy is not a side issue — **it is defect D-01 showing up in my own analysis**,
which is a fair demonstration of how much damage the duplicate-import problem is doing.

**Also corrected:** the fleet range. `$26–$120/day` is the **advertised** band from settings; the
**highest actual seasonal day-rate in `cars.json` is $90**. `$120` is a configured ceiling, not a car.

### C-2 · Reversals were disclosed forward but not backward **[HIGH]**

`STRATEGY-STRESS-TEST.md` honestly flagged that it reversed the Russian-opportunity position — but the
documents *containing* that position carried no marker, and a third file was missed entirely. A reader
opening only `CONTENT-GAP.md` or `SEO-SITE-ARCHITECTURE.md` would have received a build instruction the
engagement refuted at high confidence.

**Superseded banners have been added to:**

- `SERP-COMPETITOR-MAP.md` Finding 4 (Russian as "probably the bigger" market) + the compete/don't-compete table
- `CONTENT-GAP.md` CREATE-12 (Russian layer) + Build-order Phase 7
- `SEO-SITE-ARCHITECTURE.md` — the `ru` locale row and the target tree
- `FIVE-SEO-STRATEGIES.md` — Strategy D's 84/100 score, now marked superseded by 64/100
- `CONTENT-GAP.md` Build-order Phase 3 — CREATE-2 was still labelled *"weakest competition"* after the
  stress test found `og.ge` already occupies it

### C-3 · An `[OBSERVED]` tag on an inference **[MEDIUM]**

`STRATEGY-STRESS-TEST.md` claimed, tagged `[OBSERVED]`, that ountravela *"explicitly does not
recommend"* AWD crossovers for the Omalo road. The actual evidence is one operator's own listing
restriction (*"Tusheti authorized only for Toyota 4runner and Tacoma models"*) — **a single vendor's
contract term, not that publisher's vehicle recommendation.** Retagged and rephrased. The underlying
point — 13 of 15 vehicles are `bodyType: Crossover` — stands and is genuinely `[OBSERVED]`.

### C-4 · The `$0` price root cause named the wrong function **[MEDIUM]**

`TECHNICAL-SEO-AUDIT.md` P0-2 traced the fault through `GLC_Pricing::quote()`. **`GLC_Schema::car()`
never calls `quote()`** — it calls `rate_range()`, which has a structurally different fallback. The
conclusion and the fix were right; the causal chain presented as code-observed was not. Corrected.

**And the audit found something I missed, which strengthens the ticket:** `cars.json` stores season rows
as `{"period": …, "prices": {"days1To2": …}}` while `GLC_Pricing` reads `$season['from']`,
`$season['to']` and `$season['rates']` keyed `d1_2, d3_4, …`. **The key names do not match.** If the data
was imported verbatim, `season_for_date()` matches nothing for *every* car — collapsing each
`AggregateOffer` to `lowPrice == highPrice == glc_price_from` while `offerCount` still advertises seven
tiers. Added to `TECHNICAL-SEO-AUDIT.md` P0-2 and to the GL-034 regression guard.

### C-5 · A missing taxonomy **[MEDIUM]**

The sitemap/index-bloat finding named `car_brand` and `car_body_type` but omitted **`place_region`**,
which is also `public: true` and therefore also in the sitemap. With ~6 regions × 7 locales that is
**~42 additional indexable near-duplicate archive URLs** absent from the remediation ticket. Added to
`TECHNICAL-SEO-AUDIT.md` P1-3 and `SEO-IMPLEMENTATION-BACKLOG.md` GL-023.

### C-6 · An over-compressed quotation **[LOW]**

`EXECUTIVE-SEO-STRATEGY.md` compressed the terms clause to *"prohibits off-road driving"*. The actual
text is *"Off-road driving **(unless vehicle is specifically approved)**"*. **The qualifier matters** —
it is what might make the flagship permission page publishable without a full policy rewrite. Restored.

### C-7 · A navigation claim that was too strong **[MEDIUM]**

I wrote that *"none of the seven navigation slots goes to a page that makes money"*. `header.php`
includes **Our Fleet** plus a **Book Now** CTA button pointing at `/fleet/`. The accurate claim — which
`INTERNAL-LINK-GRAPH.md` states correctly — is that **no nav slot goes to a city page or to the
permission page**. Corrected in the executive summary.

---

## Findings acknowledged but not fixed

| # | Finding | Why not changed |
|---|---|---|
| A | *"~20 reviews ≈ one month of ordinary rentals"* silently assumes ~20 rentals/month and a ~100% review-conversion rate | Tagged `[ESTIMATE]` in all five places it appears. **Treat it as a placeholder until Boris supplies booking volume** |
| B | Three different ountravela price ranges quoted across three documents (€50–85 / €50–120 / €50–79 + €109–119) | The granular version in `LINK-ACQUISITION-PLAN.md` is the one to trust. All support the same directional point: Geolander is priced well below that tier |
| C | The Localrent Mestia–Ushguli ban is described as "lifted in 2025" in two documents and "still prohibited" in three | **Genuinely unresolved.** wander-lush reports it lifted; her own commenters report it still shows as prohibited in Dec 2025. This is a live contradiction in the source material and is exactly why the quarterly re-check exists |
| D | The `[CUSTOMER]` tag was applied to some vendor/publisher policy text in `CUSTOMER-SEARCH-INTELLIGENCE.md` | Quotes are real and correctly attributed in-line; only the tag class is wrong. It does mildly inflate the apparent volume of independent customer voice |
| E | The `competition` column in `KEYWORD-UNIVERSE.csv` is populated while `keyword_difficulty` says `UNMEASURED` | Those are qualitative reads of observed SERP composition, not tool metrics. **Do not read that column as a measured score** |
| F | The 25% English/Russian SERP overlap figure does not show its denominator | Directional; derived from a small named sample |

---

## The one thing that could not be verified

**The strongest evidence for defect D-01 — the byte-identical images in
`_migration/fleet-import/Mitsubishi Outlander 2018 Black/` and `.../2018 Gray/` — sits in files that
were listed on Boris's machine but not staged into the analysis container.** I observed the directory
listing (identical file sizes and identical modification timestamps across the two folders) but did not
read the image bytes.

Separately, **`cars.json` itself contains no duplicates** — all 15 registration numbers are distinct.
Every duplicate named in these documents exists only on the live site and in the `fleet-import/` folders.

`[INFERENCE]` The duplication is real — the live `/fleet/` page showing 19 vehicles against 15 in the
source, with two Wranglers and two 2018 Outlanders, is hard to explain otherwise. But **the dedupe
ticket (GL-003) should be executed against the live WordPress database and the physical fleet, not
against the repository**, and Boris should confirm the duplicates himself before any post is merged.

---

## What this episode says about the rest of the work

`[INFERENCE]` The audit's verdict was *"safe to act on, with four corrections applied first"* — and the
errors clustered in a single, telling place: **concrete facts about Geolander's own fleet, repeated
across documents without re-derivation.**

That is worth noticing, because it is the same failure mode the strategy is designed to fix. The site
tells three different stories about how many cars it has; my analysis absorbed that ambiguity and then
propagated a version of it. **The fix for both is the same: one source of truth for the fleet, verified
against the cars in the yard.**

No strategic conclusion changed. The Golden Path — fix the broken data, build the entity, publish the
policies, ask for reviews — survived every check.
