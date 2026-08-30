# SCHEMA-PLAN.md — Geolander structured data

**Date:** 2026-08-14 · **Method:** full read of `class-glc-schema.php` + `class-glc-city.php::schema()`,
validated against current Google Search Central documentation fetched today.

**Tags:** `[FIRST-PARTY]` Google/Schema.org docs read today · `[OBSERVED]` in the code or live ·
`[INFERENCE]` · `[UNVERIFIED]`

---

## Governing principle

The brief is explicit: *"Do not add schema merely because Schema.org contains a type."* Applied
strictly here, that principle mostly produces **subtraction and correction**, not addition. Geolander
already emits more structured data than most competitors in this market. The problem is not coverage —
it is that some of it is aimed at features that no longer exist, and one node is currently emitting a
false price.

---

## Part 1 — Three first-party findings that overturn existing assumptions

These were verified against Google's live documentation today, and each contradicts something in the
project's own `docs/seo-geo-aeo.md` playbook.

### F-1 · FAQ rich results are dead. Fully. `[FIRST-PARTY]`

Google's FAQPage documentation states:

> *"As of May 7, 2026, FAQ rich results are no longer appearing in Google Search."*

and, before that:

> *"FAQ rich results are only available for well-known, authoritative websites that are
> government-focused or health-focused."*

**What this overturns:** `docs/seo-geo-aeo.md` lists the "13-question FAQPage schema" under
*"Implemented on-site → Search (SEO)"* as a win. **It is not a search win and cannot become one.**

**What to do:** keep the `FAQPage` markup if you want — it costs nothing and plausibly helps AI
extraction — but reclassify it from an SEO asset to an AEO asset, and never spend another hour on it.
**The visible on-page FAQ content remains genuinely valuable**; it is only the rich-result expectation
that is void. Do not remove the FAQ *content* — that would be the wrong lesson entirely.

### F-2 · Vehicle listing markup is a car-dealership feature `[FIRST-PARTY]`

Google's vehicle structured data is published as *"Vehicle listing structured data **for car
dealerships**"* — a for-sale feature. A per-day rental rate is not a product price in the sense the
merchant pipeline expects.

**What to do:** the existing `["Product","Car"]` node is defensible under Schema.org semantics as a
description of the vehicle, and there is no reason to rip it out. But **stop expecting rich results
from it**, and fix the `$0` case immediately (F-3).

### F-3 · Self-serving `aggregateRating` is against guidance `[FIRST-PARTY]`

Google's LocalBusiness documentation says of both `review` and `aggregateRating`:

> *"This property is only recommended for sites that capture reviews about **other** local businesses."*

**What this overturns:** `docs/seo-geo-aeo.md` says *"Once real reviews accumulate on GBP, consider
AggregateRating on the business (never fabricate)."* The "never fabricate" instinct is right, but the
plan itself is against guidance even with 100% genuine reviews — because they would be reviews of
*yourself*, on *your own* site.

**What to do instead:** let the reviews live on the **Google Business Profile**, where they feed the
local pack and Maps directly and carry far more weight than self-published markup ever would. Display
them on-site as social proof if you like — just don't wrap them in `aggregateRating` on your own
`AutoRental` node.

---

## Part 2 — Audit of what is currently emitted

Every page gets `AutoRental` + `WebSite` in one `@graph`, plus per-template nodes `[OBSERVED]`.

| Node | Where | Verdict | Action |
|---|---|---|---|
| `AutoRental` | every page | **Keep — this is the strongest asset here.** Correct subtype (Google: *"Use the most specific LocalBusiness sub-type possible"* `[FIRST-PARTY]`). Both required properties present (`name`, `address`). Carries `geo`, `telephone`, `priceRange`, `openingHoursSpecification`, `hasMap` → GBP, `currenciesAccepted`, `areaServed`, `sameAs`. NAP matches the GBP exactly | Two fixes below |
| `WebSite` | every page | Keep. Correct `inLanguage` per locale, `publisher` → business | Add `SearchAction` **only if** on-site search exists — `[UNVERIFIED]`, and do not add it otherwise |
| `["Product","Car"]` + `AggregateOffer` | car singles | Keep as semantics, **not** as a rich-result play (F-2). **Currently emitting `lowPrice: 0` for unpriced vehicles** | **P0 — see below** |
| `BreadcrumbList` | car, place, city, guide | Keep — genuinely useful and still a live feature | **Add the visible breadcrumb UI** (P3-4 in the audit): `[FIRST-PARTY]` markup should correspond to visible page content |
| `Service` | city pages | Keep. Well-modelled: `serviceType` "Car rental", `provider` → business, `areaServed` → City | Enrich — see Part 3 |
| `Article` | route guides | Keep. `about` → `TouristDestination` is a nice touch | Consider `speakable`? **No** — not a supported general feature. Leave alone |
| `ItemList` | fleet archive | Keep | Extend to city pages, which display a fleet but don't declare it |
| `TouristAttraction` | place singles | Keep, with a caveat below |
| `FAQPage` | front page | Keep for AEO only (F-1) | Reclassify. Zero further investment |

---

## Part 3 — Prioritised actions

### P0 — Never emit a zero price

**Evidence `[OBSERVED]`:** `GLC_Schema::car()` builds `AggregateOffer` from
`GLC_Pricing::rate_range()`, which returns `[$price_from, $price_from]` when the season table is empty
— and `$price_from` is `0` for every vehicle imported by `import-fleet.php`. `/pricing.md` confirms
this is live.

`[INFERENCE]` A `Product` node advertising `lowPrice: 0` on a commercial rental site is precisely the
kind of structured-data/visible-content mismatch that draws a manual action. It is also being served to
every AI crawler `robots.txt` explicitly invites.

**Fix:**

```php
// GLC_Schema::car() — omit offers entirely rather than advertise zero.
[ $low, $high ] = GLC_Pricing::rate_range( $post_id );
$offers = ( $low > 0 && $high > 0 ) ? [ /* …existing AggregateOffer… */ ] : null;
```

Apply the same guard in `GLC_AI::pricing()` and `GLC_SEO::description()`. **No price is a legitimate
state. A price of zero is a false statement about the business.**

### P1 — Correct two `AutoRental` properties

1. **`image` / `logo` both point at a 699 KB PNG** `[OBSERVED]`. Produce a properly sized logo, and set
   `image` to a **real photograph of the fleet or the office** rather than the logo. `[INFERENCE]` For a
   local business entity, a real premises/fleet photo is a stronger corroborating signal than a
   wordmark — and it is what the GBP should show too.
2. **`priceRange`** derives from `GLC_Format::range()` (settings, default `28`) while the cheapest
   live vehicle is `$26` `[OBSERVED]`. Derive it from the **lowest live priced vehicle** so schema,
   copy and title tag cannot drift apart.

### P1 — Add `areaServed` cities to the business node

Currently `areaServed` is `Country: Georgia` only `[OBSERVED]`, while the business explicitly delivers
to four named cities and has a page for each. Add them as an array of `City` nodes alongside the
country. `[INFERENCE]` This makes the entity's service footprint explicit for both local search and AI
grounding, and it costs one line.

### P2 — Enrich the city `Service` node

Today: `serviceType`, `name`, `url`, `provider`, `areaServed`. Add, where genuinely true:

- `availableChannel` → `ServiceChannel` with `servicePhone` (the WhatsApp number) and `serviceUrl`
- `hoursAvailable` → 24/7, matching the business node
- `ItemList` of the vehicles the page actually displays

**Do not** add `offers` with prices to the city node — the same rate applies everywhere, so a per-city
offer would assert a distinction that does not exist. `[INFERENCE]` Fabricated granularity is worse
than none.

### P2 — Airport entities on city pages

`GLC_City` already stores `glc_airport_name` and `glc_airport_code` `[OBSERVED]` but the schema never
uses them. Airport intent is one of the highest-value clusters in car rental. Reference the airport as
a named `Airport` entity (`iataCode` TBS / BUS / KUT) within the city page's `Service.areaServed` or as
a `location`. `[INFERENCE]` Explicit IATA codes are a strong disambiguation signal for both search and
AI grounding.

### P3 — `TouristAttraction` on place pages: a semantic honesty check

`[OBSERVED]` Place pages emit `TouristAttraction` with `address: { addressCountry: GE }` and nothing
else. `[INFERENCE]` Geolander is not the operator of Gergeti Trinity Church. Declaring yourself the
source of truth for 36 national landmarks, with an address of "Georgia", is a thin claim.

Two honest options:
- Add real `geo` coordinates (the `place` CPT already stores `glc_lat`/`glc_lng` `[OBSERVED]`) and
  make the pages genuinely useful — parking, road surface, whether a 4×4 is needed, seasonal access.
  **This aligns with the differentiation strategy**: driving-specific information about destinations is
  exactly what a rental company legitimately knows and a tourism board does not.
- Or drop to `WebPage`/`Article` and stop claiming to be the attraction's data source.

**Recommended: the first**, because it converts a weak claim into a real one.

---

## Part 4 — What NOT to add

Listed explicitly, because schema plans tend to grow by accretion.

| Type | Why not |
|---|---|
| `AggregateRating` / `Review` on your own business | `[FIRST-PARTY]` Against Google's stated guidance (F-3), regardless of authenticity |
| `Offer` with per-city prices | Would assert a price distinction that does not exist |
| `Event` | No events. Do not invent them |
| `HowTo` | `[FIRST-PARTY]` HowTo rich results were removed from Google Search in 2023 |
| `Speakable` | Limited to news publishers; not applicable |
| `JobPosting` | No jobs |
| `VideoObject` | No video assets exist yet. Add it *when* video exists, not before |
| `Vehicle` dealership feed markup | `[FIRST-PARTY]` For-sale feature (F-2). You rent, you don't sell |
| `Course`, `Recipe`, `SoftwareApplication` | Named only to close the door — none apply |

---

## Part 5 — Validation protocol

Because nothing here has been machine-validated. `[UNVERIFIED]` — WebFetch strips `<script>` blocks, so
the fact that fetched pages showed no JSON-LD is **not** evidence that the markup is missing.

Run before and after any change:

1. **[Rich Results Test](https://search.google.com/test/rich-results)** on one URL of each template:
   home, `/fleet/`, one car page, `/car-rental-batumi/`, one route guide, one place page
2. **[Schema Markup Validator](https://validator.schema.org/)** for pure Schema.org validity
3. **`node _migration/validate-schema.mjs`** — the repo already ships a validator; run it in CI
4. **GSC → Enhancements** once the property is verified — watch for `Merchant listings` errors, which
   is where the `$0` price will surface if it is not fixed first
5. **One locale check** — validate `/ru/car-rental-batumi/` too, confirming `inLanguage` follows the
   locale and the graph does not leak English into a localised node

**Add a regression guard:** the `$0` bug shipped because nothing asserted "a published car has a price".
`validate-schema.mjs` should fail the build when any `car` post is published with `lowPrice <= 0`.
`[INFERENCE]` That one assertion would have prevented the single worst defect found in this engagement.

---

## Summary

| Priority | Action | Effort |
|---|---|---|
| **P0** | Suppress `offers` when price ≤ 0 (schema, `/pricing.md`, meta description) | 1–2 h |
| **P1** | Real fleet/office photo as business `image`; sane logo size | 1 h |
| **P1** | `priceRange` derived from the lowest live priced vehicle | 1 h |
| **P1** | Add four delivery cities to `areaServed` | 30 min |
| **P2** | Enrich city `Service` node; add `Airport` entities with IATA codes | 3–4 h |
| **P3** | Real `geo` on `TouristAttraction`, or downgrade the type | 2 h |
| **P3** | Visible breadcrumb UI to match `BreadcrumbList` | 2–3 h |
| — | **Reclassify** `FAQPage` as AEO-only; **abandon** any `aggregateRating` plan | 0 h, but changes the roadmap |

**Net position:** the schema layer is already good. It needs one urgent correction, a handful of
enrichments, and — most importantly — the removal of two false expectations that the project's own
playbook is currently built on.

---

## Sources

- [Google Search Central — FAQPage structured data](https://developers.google.com/search/docs/appearance/structured-data/faqpage)
- [Google Search Central — Local business structured data](https://developers.google.com/search/docs/appearance/structured-data/local-business)
- [Google Search Central Blog — Vehicle listing structured data for car dealerships](https://developers.google.com/search/blog/2023/10/vehicle-listings-structured-data)
- [Google Search Central — Vehicle listing documentation](https://developers.google.com/search/docs/appearance/structured-data/vehicle-listing)
- Repository: `wp-content/plugins/geolander-core/includes/class-glc-schema.php`, `class-glc-city.php`, `class-glc-pricing.php`, `class-glc-ai.php`; `_migration/validate-schema.mjs`
- [geo-lander.com/pricing.md](https://geo-lander.com/pricing.md)
