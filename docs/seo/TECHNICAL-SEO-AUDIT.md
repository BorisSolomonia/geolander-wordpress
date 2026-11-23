# TECHNICAL-SEO-AUDIT.md — Geolander

**Date:** 2026-08-14 · **Method:** line-by-line source review of `geolander-core` + `geolander` theme,
plus live fetches of 8 URLs · **No changes made to the site.**

**Tags:** `[OBSERVED]` · `[FIRST-PARTY]` Google/Schema.org docs · `[INFERENCE]` · `[UNVERIFIED]`

---

## Headline verdict

**The technical SEO on this site is above average and is not what is holding it back.**

That deserves saying clearly, because the usual output of an audit like this is a long list of
technical sins, and here the list is short. Server-rendered HTML, no JS wall, clean robots.txt, sitemap
declared and pruned, hreflang complete and reciprocal, canonicals handled per template including a
custom-rewrite edge case, hand-written per-template JSON-LD, self-hosted subset variable fonts, ~6 KB
of JavaScript, deliberate CDN cache headers. Someone who understood the problem built this.

The defects that matter are **data-integrity and content defects that happen to live in code**. They are
listed as P0 not because they are technically severe but because they are commercially severe: the site
is currently publishing incorrect prices and duplicate vehicles into both Google and the AI layer.

**Critical caveat: this is not a crawl-based audit.** `/wp-sitemap.xml` returns as unparseable binary to
the only fetch tool available here, and this sandbox blocks direct HTTP clients. **Redirect chains,
orphan pages, 404s, real click depth and actual index size are therefore unknown.** Every finding below
is from source code or from eight individually fetched pages. A 500-URL Screaming Frog run closes this
gap in about 30 minutes and should be done before the backlog is executed.

---

## P0 — Critical (fix before any amplification work)

### P0-0 · **Every non-English homepage is in a redirect loop**

**Evidence `[OBSERVED]`, reproduced independently three times:**

| URL | Result |
|---|---|
| `https://geo-lander.com/ru/` | **"Too many redirects"** |
| `https://geo-lander.com/ka/` | **"Too many redirects"** |
| `https://geo-lander.com/ru/car-rental-batumi/` | Loads fine |
| `https://geo-lander.com/ru/fleet/` | Loads fine |

A research agent hit the `/ru/` failure independently before I tested it, and I then reproduced it on
both `/ru/` and `/ka/`. **Locale sub-pages work; locale homepages do not.**

**Root cause, traced through the code `[INFERENCE — high confidence]`:**

`GLC_I18n::boot()` strips the locale prefix from `REQUEST_URI`, so a request for `/ru/` becomes `/`
internally. `GLC_I18n::hooks()` then filters `home_url` so that `home_url('/')` returns
`https://geo-lander.com/ru/`. WordPress core's `redirect_canonical()` runs on `template_redirect`,
computes the canonical front-page URL as `home_url('/')` — now `/ru/` — compares it to the requested
URL, which the prefix-stripping has reduced to `/`, finds a mismatch, and redirects to `/ru/`.
That request is stripped to `/` again. **Loop.**

This is exactly the failure mode `GLC_City` already guards against for city singles:

```php
add_filter( 'redirect_canonical', fn( $r ) => is_singular( 'city' ) ? false : $r );
```

The same guard was never applied to the localised front page.

**Why this is the single most severe technical defect on the site:**

- **Six of the seven locale homepages are unreachable.** `/ka/`, `/ru/`, `/uk/`, `/ar/`, `/zh/`, `/fr/`
  — the `/ka/` and `/ru/` failures are confirmed; the other four share the identical code path
  `[INFERENCE]`
- **Every page on the site emits `hreflang` alternates pointing at these URLs** `[OBSERVED]`
  `GLC_I18n::hreflang()`. So the site is handing Google six broken alternate targets from every single
  URL, on a domain that has no authority to spare
- **The Accept-Language redirect sends non-English browsers to exactly these URLs.** A Russian-speaking
  visitor arriving at `geo-lander.com` is negotiated to `/ru/` — and hits an infinite redirect. `[INFERENCE]`
  Russian-speaking visitors are, on the tourism data gathered in this research, the **largest single
  inbound audience for Georgia**. They are currently being handed a broken page by design
- It would not show up in any on-page audit, because every individual sub-page renders correctly

**Fix (one line, mirroring the existing city guard):**

```php
// GLC_I18n::hooks() — the front page's canonical URL is locale-prefixed,
// but REQUEST_URI has already been stripped. Core's canonical redirect
// compares the two and loops.
add_filter( 'redirect_canonical', fn( $r ) => is_front_page() ? false : $r );
```

**Validation:** request each of `/ka/ /ru/ /uk/ /ar/ /zh/ /fr/` and confirm a `200`. Then re-test with
`Accept-Language: ru` against `/` and confirm the negotiated redirect terminates.

**This should be fixed today, ahead of everything else in this document.**

---

### P0-1 · Duplicate vehicle records inflating the fleet by ~30%

**Evidence `[OBSERVED]` — now confirmed in the site's own title tag.** `/ru/fleet/` renders the title
`"Car Rental Fleet in Tbilisi, Georgia — 19 Real 4x4s from $28/day | Geolander"`. That count is
generated by `wp_count_posts('car')->publish`, so **the site itself reports 19 published vehicles**.
The word "Real" in that title is doing damage: it is a trust claim attached to an inflated number.

`/fleet/` lists 19 vehicles. `_migration/cars.json` contains 15.
`/llms.txt` states 15. The live list contains both "Jeep Wrangler 2017 White Sport" and
"Jeep Wrangler 2017"; both "Mitsubishi Outlander 2018 Black" and "Mitsubishi Outlander 2018 Gray";
plus a bare "Mitsubishi Outlander" with no year. The `_migration/fleet-import/Mitsubishi Outlander 2018 Black/`
and `.../2018 Gray/` folders contain **byte-identical image files with identical modification
timestamps** — the same ten photographs.

**`[INFERENCE]` Root cause:** `import.php` (15 cars from `cars.json`) and the later `import-fleet.php`
(the `fleet-import/` folders) both created `car` posts with no dedupe key.

**Why it is P0:** each duplicate is a near-identical URL competing with its twin for the same query, on
a domain with no authority to waste. Worse, the fleet page tells a customer you have 19 cars when you
have ~13–15. A customer who arrives and finds the "White Sport" and the plain "2017" are one Jeep has
been misled, and the fleet count is a factual claim about the business that Google and AI systems are
currently ingesting as truth.

**Fix:** audit `car` posts against the physical fleet. Merge duplicates. **301** the losing URL to the
survivor — do not delete, and do not `noindex` first; a redirect preserves whatever equity and any
external link exists. Re-run `validate-schema.mjs`. Then correct the count in `/llms.txt`.

**Validation:** `/fleet/`, `/llms.txt` and `/pricing.md` all report the same number, and that number
matches the cars on the forecourt.

**Rollback:** post merges are destructive — export the `car` post table before touching it.

---

### P0-2 · Vehicles publishing a rental price of `$0/day`

**Evidence `[OBSERVED]`:** `/pricing.md` shows "Jeep Wrangler 2017 White Sport — $0/day (all prices
shown as $0)". The Subaru Forester 2018 Black page's meta description references `$0/day`.
`/pricing.md` lists only 8 vehicles where `/fleet/` shows 19.

**`[OBSERVED]` Root cause, traced through the code:** `GLC_Schema::car()` calls **`GLC_Pricing::rate_range()`**
(not `quote()`). When no season row yields a rate `> 0`, `rate_range()` returns `[ $from, $from ]` where
`$from = (float) get_post_meta( $car_id, 'glc_price_from', true )` — which is `0.0` on empty meta, the
state of every vehicle imported by `import-fleet.php`. `GLC_Schema::car()` then destructures that straight
into `lowPrice`, `highPrice` and `priceSpecification.price` **with no guard**, and `GLC_SEO::description()`
renders "from $0/day".

**Related, and worth checking in the same pass `[OBSERVED]`:** `cars.json` stores season rows as
`{"period": …, "prices": {"days1To2": …}}` while `GLC_Pricing` reads `$season['from']`, `$season['to']` and
`$season['rates']` keyed `d1_2, d3_4, …`. **The key names do not match.** If imported verbatim,
`season_for_date()` matches nothing for *every* car, collapsing each `AggregateOffer` to
`lowPrice == highPrice == glc_price_from` while `offerCount` still advertises seven tiers.

**Why it is P0:** you are telling Google's structured-data pipeline, and every AI crawler you
explicitly invited in `robots.txt`, that a Jeep Wrangler rents for nothing. This is the worst possible
category of error — a *machine-readable false price*, published deliberately into the surfaces designed
to be quoted verbatim.

**Fix, in order:**
1. Populate real seasonal pricing for every imported vehicle, **or**
2. Set `glc_available = false` and exclude unpriced cars from `/fleet/`, `/pricing.md`, `/llms.txt` and
   the sitemap until priced
3. Add a guard in `GLC_Schema::car()` that omits the `offers` node entirely when `lowPrice <= 0` —
   **no price is legitimate; a price of zero is a lie**
4. Add the same guard in `GLC_AI::pricing()` and `GLC_SEO::description()`

**Validation:** `grep` the live `/pricing.md` for `$0`; run the car template through Google's Rich
Results Test; confirm no meta description contains `$0`.

---

### P0-3 · ~133 near-duplicate thin vehicle URLs

**Evidence `[OBSERVED]`:** the Subaru Forester 2018 Black page carries **~85 words of vehicle-specific
text**. Everything else — insurance, unlimited mileage, roadside assistance, "Kazbegi, Gudauri, Kakheti,
Svaneti" — is byte-identical boilerplate across all vehicle pages. `_migration/cars.json` confirms
`descriptionEn` is an **empty string for all 15 source vehicles**.

**Scale `[INFERENCE]`:** 19 vehicles × 7 locales = **133 URLs**, of which the 114 non-English variants
carry English body content while declaring `hreflang="ka"`, `"ru"`, `"ar"` and so on.

**Why it is P0:** this is roughly 27% of the site's entire crawlable surface, and it is the *worst* 27%.
`[FIRST-PARTY]` Google's guidance on thin and duplicate content is unambiguous, and the most likely GSC
outcome is a large "Crawled – currently not indexed" / "Duplicate without user-selected canonical"
bucket. Publish-then-amplify on this base wastes the amplification.

**The honest strategic question, which the brief demands I ask:** *should individual vehicle pages exist
at all?* Real search demand for "rent Subaru Forester 2018 black Tbilisi" is, on any reasonable reading,
negligible. Demand exists for **categories** — "4x4 rental Tbilisi", "SUV rental Georgia", "automatic
car rental Tbilisi", "7 seater rental Georgia". So the recommendation is not "write 19 essays":

- **Keep** individual vehicle pages as *conversion* pages — they close the "is this the exact car I get?"
  question, which the site's own "Real cars, real photos" promise makes central, and which customer
  research repeatedly shows is a top rental anxiety. But stop expecting them to rank.
- **Do not** target them at search. Consider `noindex, follow` on individual vehicles once the
  category layer exists — this needs a decision on evidence, and is flagged as an **experiment**, not
  an instruction, in `SEO-EXPERIMENT-BACKLOG.md`.
- **Build the category layer** — that is where the demand actually is. See `SEO-SITE-ARCHITECTURE.md`.
- **Give each vehicle 100–150 words of genuinely specific content** — ground clearance, real fuel
  consumption on the Kazbegi climb, boot space with two large cases, whether it handled Abano Pass.
  You own these cars; nobody else can write this. That is the moat.

---

## P1 — High

### P1-1 · The homepage can never be edge-cached

**Evidence `[OBSERVED]`:** `GLC_Perf::cache_headers()` sends `Cache-Control: private, no-cache` on the
unprefixed front page, deliberately, so the origin can make the Accept-Language redirect decision.

**`[INFERENCE]`** The comment explaining this is correct and thoughtful. But the consequence is that
the single most important URL on the site takes a **full WordPress + MySQL bootstrap on every hit**, on
Railway, which the code's own comment notes has no page cache. TTFB on `/` is therefore structurally
the worst on the site, and TTFB feeds directly into LCP.

**Options, in preference order:**
1. Drop the automatic language redirect. Serve `/` as English to everyone, offer an explicit language
   switcher (already built), and make `/` fully cacheable. `[FIRST-PARTY]` This also aligns with
   Google's guidance against automatic language redirection. **Recommended.**
2. Keep the redirect but move the decision to the CDN edge, so the origin stays cacheable.
3. Keep as-is and accept the TTFB cost.

Option 1 removes a Google-guidance risk *and* a performance problem at once. The cost is that a Russian
speaker landing on `/` sees English until they click — which, given that Russian body content does not
exist yet anyway (see P1-4), currently costs nothing.

### P1-2 · Core Web Vitals unmeasured

PageSpeed Insights API returned **HTTP 429** on two attempts from this environment `[OBSERVED]`.

`[INFERENCE]` from assets: hero is 467 KB WebP with a 669 KB JPG fallback and is the probable LCP
element — heavy, and served from an origin that (per P1-1) cannot edge-cache the page it sits on.
INP should be excellent (~6 KB JS). **CLS is the unknown** — `reveal.js` is a scroll-reveal pattern and
those commonly shift layout. `docs/PERFORMANCE_AUDIT.md` exists in the repo but predates the current
state and should be re-validated, not trusted.

**Action for Boris:** run PageSpeed Insights (mobile) on `/`, `/fleet/`, one car page and
`/car-rental-batumi/`. Report whether the **CrUX field data** panel appears — its absence is itself a
baseline data point, meaning insufficient real traffic.

### P1-3 · Taxonomy archives are indexable near-duplicates of `/fleet/`

**Evidence `[OBSERVED]`:** `car_brand`, `car_body_type` **and `place_region`** are all `public: true`.
`GLC_SEO::init()` removes only `users`, `category` and `post_tag` from the sitemap — **all three custom
taxonomies remain in it**. With ~6 regions × 7 locales, `place_region` alone adds ~42 indexable archive URLs.

`[INFERENCE]` With ~19 cars across ~5 brands and 2 body types, `/brand/subaru/` is a subset of `/fleet/`
with no unique content, no unique title, and no unique intent.

**But do not reflexively `noindex` them.** `/body-type/suv/` is a *latent category page* — precisely the
layer P0-3 says is missing. The right move is a decision, not a default:

- `/brand/{subaru|toyota|mitsubishi|jeep}/` — genuine but small demand ("rent Subaru Georgia"). Give
  them real intro copy and keep them, **or** `noindex` them. Do not leave them as bare loops.
- `/body-type/suv/` and `/body-type/crossover/` — **these should probably become the primary category
  landing pages**, or be redirected into purpose-built ones at cleaner URLs.

### P1-4 · Six locales declaring languages they do not speak

**Evidence `[OBSERVED]`:** `GLC_I18n::hreflang()` emits all 7 locales + `x-default` on every URL.
`GLC_Content` provides per-locale content meta, and `setup-cities.php` populates ka/ru **titles** for
city pages — but the README states plainly that "Vehicle body content stays English by design", and
`cars.json` has empty descriptions in every language.

**`[INFERENCE]`** So `/ru/fleet/subaru-forester-2018-black/` declares `hreflang="ru"` and serves
translated chrome around English body text — on a page that has ~85 words of body text to begin with.
Six locales × the whole site is a large volume of pages asserting a language claim they only
partially honour.

**Recommendation — narrow before you widen.** Seven locales on a zero-authority domain is
surface-area without depth. `[INFERENCE]` The Russian-speaking market is, on the face of it, the most
plausible second language for inbound tourism to Georgia — **but I am explicitly not asserting that
until the market-research workflow reports back**, and one of the three adversarial agents is tasked
with trying to disprove exactly this claim. The structural recommendation stands regardless:

- Pick **at most two** non-English locales and translate them **fully** — city pages, FAQ, guides,
  vehicle copy
- For the rest, either remove the locale or keep the UI switcher without emitting `hreflang` for
  pages whose content is not actually in that language

### P1-5 · Trust contradictions between the sales pages and the terms

`[OBSERVED]`, from `_migration/setup-pages.php` against live homepage and `/llms.txt`:

| Site claims | Terms say |
|---|---|
| "Full insurance included" (homepage, llms.txt) | §2: "Basic insurance (CDW) … **with a deductible**. Additional full coverage available **for an extra fee**." |
| 4×4 mountain adventure; guides promoting Abano Pass, Ushguli, Tusheti | §8 Prohibited Uses: "**Off-road driving** (unless vehicle is specifically approved)" |
| "No prepayment" | §1: "Credit or debit card for **security deposit**" — **amount never stated anywhere on the site** |

Not a crawler-visible defect. But `[FIRST-PARTY]` Google's quality guidance weights trustworthiness
heavily for transactional pages, and a traveller comparing rental companies after reading deposit
horror stories will find that this site quietly contradicts itself on the two things they are most
anxious about. **Cheapest high-value fix in the entire backlog.** Zero ranking risk.

### P1-6 · Price floor stated inconsistently

`[OBSERVED]` `$26/day` (fleet archive title, per `docs/seo-geo-aeo.md`) vs `$28–$120` (live homepage
and `/llms.txt`). `GLC_Format::range()` reads `price_min` from settings (default 28) while the cheapest
vehicle in `cars.json` is $26. A user clicking a "$26" snippet and landing on "$28" has been given a
small, free reason to distrust — and snippet-to-page price mismatch is a CTR-quality problem too.

Single source of truth: derive the displayed floor from the **lowest live priced vehicle**, and let
copy, title, schema `priceRange` and `/llms.txt` all read from it.

---

## P2 — Medium

| ID | Finding | Evidence |
|---|---|---|
| P2-1 | **Hard-coded "36 Destinations"** in the places archive title tag — will silently become false the moment a place is added or removed | `[OBSERVED]` `class-glc-seo.php` |
| P2-2 | **699 KB PNG logo** used as the schema business `logo` *and* `image`. `[FIRST-PARTY]` Google's logo guidance favours a clean, appropriately sized raster; 699 KB is also an image-weight cost | `[OBSERVED]` |
| P2-3 | **`FAQPage` schema on the front page yields nothing in Search — confirmed.** `[FIRST-PARTY]` Google's FAQPage documentation now states: *"As of May 7, 2026, FAQ rich results are no longer appearing in Google Search"*, on top of the earlier restriction to *"well-known, authoritative websites that are government-focused or health-focused."* The repo doc `docs/seo-geo-aeo.md` counts the "13-question FAQPage schema" as an on-site SEO win — **that assumption is now dead**. Keep the markup for AI/LLM extraction value if you like, but remove it from the list of things expected to produce a SERP feature, and never invest further in it | `[FIRST-PARTY]` |
| P2-4 | **`["Product","Car"]` + `AggregateOffer` on rental vehicles.** `[FIRST-PARTY]` Google's vehicle structured data is titled and scoped *"Vehicle listing structured data **for car dealerships**"* — it is a for-sale feature, not a rental one. A per-day rental rate is not a product price in the sense the merchant pipeline expects. The markup is semantically defensible under Schema.org and is fine to keep, but **should not be counted on for rich results**, and the `$0` case (P0-2) makes it actively risky today: a `Product` offer with `lowPrice: 0` is exactly the kind of thing that draws a structured-data manual action | `[OBSERVED]` + `[FIRST-PARTY]` |
| P2-5 | **Place images 700–920 KB** (`bodbe_monastery.jpg` 921 KB, `abanotubani.jpg` 735 KB). Route art was converted to WebP; place art was not | `[OBSERVED]` |
| P2-6 | **`/llms.txt` and `/pricing.md` disagree with the site and each other** (15 / 8 / 19 vehicles). These files exist to be quoted verbatim by machines — inconsistency here is amplified, not absorbed | `[OBSERVED]` |
| P2-7 | **`Vary: Accept-Language` is set on the 302 but not on the 200s.** Any shared cache in front of the origin could serve a language-negotiated response under a language-agnostic key. `GLC_Perf` mitigates this for `/` but not for other unprefixed URLs | `[OBSERVED]` + `[INFERENCE]` |
| P2-8 | **No `ItemList`/`CollectionPage` schema on city pages** — they carry `Service` + `BreadcrumbList` but not the fleet list they display | `[OBSERVED]` |

---

## P3 — Opportunity / needs a decision

| ID | Finding |
|---|---|
| P3-1 | **`/music/` (Georgian music page).** Off-topic for a car-rental entity and dilutes topical focus. But `[INFERENCE]` it may be a genuine differentiator (road-trip playlists are a real traveller need) and could be a linkable asset. **Do not delete on reflex** — decide once GSC shows whether it has any impressions. Flagged in `CONTENT-GAP.md` as EXPERIMENT |
| P3-2 | **Accept-Language 302 needs empirical verification.** Fetch `/` with and without an `Accept-Language: ru` header and observe. If Googlebot is ever redirected, this becomes P1 |
| P3-3 | **Route guides lack first-hand specificity.** `/driving-to-kazbegi-in-winter/` is ~1,100 words and competent but `[OBSERVED]` contains "no dated road condition reports, no pricing, no personal incident accounts, no checkpoint specifics" — it reads as travel-blog convention rather than operator knowledge. This is the single biggest *un-taken* differentiation opportunity on the site and is developed in `GOLDEN-PATH.md` |
| P3-4 | **No breadcrumb UI**, though `BreadcrumbList` schema is emitted. Schema without the visible element it describes is a mismatch `[FIRST-PARTY]` — Google expects markup to correspond to visible content |
| P3-5 | **City pages have no parent hub.** `/car-rental-{city}/` sits at root with no `/car-rental/` index, so the four pages connect only via the footer |
| P3-6 | **Only 4 city pages** (Tbilisi, Batumi, Kutaisi, Kobuleti). Missing: Kutaisi *city* as distinct from the airport, Mestia, Gudauri, Telavi, Sighnaghi, Borjomi. **Do not mass-produce these** — that is the doorway-page anti-pattern the brief forbids. Add a city only where genuine delivery capability and genuine local knowledge exist |

---

## What this audit could not check

Listed so nobody mistakes silence for a clean bill of health:

1. **Redirect chains and loops** — needs a crawl
2. **Orphan pages** — needs a crawl with the sitemap connected
3. **404s and soft 404s** — needs a crawl + GSC
4. **Actual index coverage and exclusion reasons** — needs GSC
5. **Real click depth** — needs a crawl
6. **Live Core Web Vitals, lab or field** — PSI rate-limited here
7. **Whether GA4/Ads IDs are actually configured** — needs wp-admin
8. **Whether structured data validates** — WebFetch strips `<script>` blocks, so the absence of JSON-LD
   in fetched output is **not evidence of absence**. Needs the Rich Results Test
9. **HTTP response headers in production** — needs `curl -I`, blocked in this sandbox
10. **Whether the three route guides are in the sitemap**

---

## Recommended sequence

**Do not start with the technical items.** Fix the data first, because everything else amplifies it:

1. **P0-2** — kill the `$0` prices (hours). Nothing else should ship before this
2. **P0-1** — dedupe the fleet (half a day)
3. **P1-5** — reconcile terms with sales claims (an afternoon, zero risk)
4. **P1-6** — single source of truth for the price floor (an hour)
5. Run the **crawl + GSC + PSI exports** (`SEO-BASELINE.md` Part 3) — this converts the remaining
   unknowns into findings
6. **Then** re-prioritise P0-3 and the whole architecture question with real index-coverage data

Steps 1–4 are safe, reversible, and can be done before the strategy is finalised. Everything below
step 5 should wait for evidence.

---

## Sources

- Repository source: `geolander-core/includes/class-glc-{seo,schema,i18n,pricing,perf,format,cpt,city,content,ai}.php`, `themes/geolander/functions.php`, `_migration/{cars.json,setup-pages.php,setup-cities.php,publish-route-guides.php}`
- [geo-lander.com/robots.txt](https://geo-lander.com/robots.txt) · [/fleet/](https://geo-lander.com/fleet/) · [/fleet/subaru-forester-2018-black/](https://geo-lander.com/fleet/subaru-forester-2018-black/) · [/car-rental-tbilisi/](https://geo-lander.com/car-rental-tbilisi/) · [/car-rental-batumi/](https://geo-lander.com/car-rental-batumi/) · [/driving-to-kazbegi-in-winter/](https://geo-lander.com/driving-to-kazbegi-in-winter/) · [/travel-info/](https://geo-lander.com/travel-info/) · [/llms.txt](https://geo-lander.com/llms.txt) · [/pricing.md](https://geo-lander.com/pricing.md)
