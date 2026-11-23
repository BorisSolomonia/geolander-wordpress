# SITE-INVENTORY.md — Geolander (geo-lander.com)

**Compiled:** 2026-08-14 · **Method:** live-site fetches + full read of the connected repository at
`C:\Users\Boris\Dell\Projects\APPS\Geolander\Geolander_WordPress` · **Author:** Claude (Cowork)

**Evidence tags:** `[OBSERVED]` seen directly on the live site or in the repository ·
`[INFERENCE]` reasoned conclusion · `[ESTIMATE]` modelled number · `[UNVERIFIED]` could not confirm

> **Access caveat that shapes this whole document.** No Google Search Console, GA4, Ahrefs, Semrush or
> server-log access was granted for this engagement. Every claim below is therefore either read from
> the code, read from the live HTML, or explicitly labelled as inference. Nothing here is a
> tool-reported metric. See `SEO-BASELINE.md` for the exact export list that would upgrade this.

---

## 0. Business identity (Phase 0 partial — the rest needs Boris)

| Field | Value | Source |
|---|---|---|
| Company | Geolander (GBP name: "Geolander car rental") | `[OBSERVED]` `class-glc-schema.php`, `docs/seo-geo-aeo.md` |
| Canonical domain | `https://geo-lander.com` | `[OBSERVED]` `_migration/settings.json`, live |
| Country served | Georgia (the country), `areaServed` = Country/GE | `[OBSERVED]` schema graph |
| Registered address | 8/5 Vedzini Street, Tbilisi 0108 | `[OBSERVED]` live footer, llms.txt |
| Phone / WhatsApp | +995 551 33 04 14 | `[OBSERVED]` live |
| Email | info@geo-lander.com | `[OBSERVED]` live |
| Hours | 24/7 (`00:00–23:59`, all seven days, in schema) | `[OBSERVED]` `class-glc-schema.php` |
| Fleet composition | 100% 4×4 / AWD crossovers and SUVs. No sedans, no economy hatchbacks, no vans, no manual transmission, no hybrids/EVs | `[OBSERVED]` `_migration/cars.json` |
| Price band advertised | "$28–$120/day" (homepage), "$26/day" (title tag), "$28–$120" (llms.txt) | `[OBSERVED]` — **three different floors, see §7 defect D-04** |
| Delivery cities | Tbilisi, Batumi, Kutaisi (airport), Kobuleti | `[OBSERVED]` `_migration/setup-cities.php` |
| Booking model | Form → live seasonal quote → WhatsApp deep link with pre-filled message. No online payment live (`payment_enabled: false`) | `[OBSERVED]` `class-glc-booking.php`, `settings.json` |
| Payment gateway | BOG iPay coded but dormant | `[OBSERVED]` `class-glc-gateways.php` |
| Languages | 7: en (default/x-default), ka, ru, uk, ar (RTL), zh, fr | `[OBSERVED]` `class-glc-i18n.php` |

### Business facts still missing — these gate the economic parts of the strategy

Phase 0 of the brief asks the strategy to favour **gross profit and LTV over booking count**. That is
impossible to do honestly right now. The following are unknown and cannot be inferred from the code:

- Actual booking volume, and the split between WhatsApp / phone / walk-in / repeat
- Gross margin per vehicle class (a $50/day Wrangler and a $26/day Forester almost certainly have very
  different margin and utilisation profiles — but "almost certainly" is not a basis for prioritisation)
- Current fleet utilisation rate and its seasonality
- Average rental duration (the pricing table is tiered 1–2 / 3–4 / 5–7 / 8–12 / 13–18 / 19–30 / 31+ days,
  which implies duration matters enormously to revenue, but the actual distribution is unknown)
- Which nationalities/languages actually book today
- Whether long-term (19–30 / 31+ day) rentals are the profit engine or a rounding error
- Deposit amount and whether it is charged (site says "no prepayment"; terms say "credit or debit card
  for security deposit" — the amount is never stated anywhere `[OBSERVED]`)

**Consequence:** the opportunity model in `FIVE-SEO-STRATEGIES.md` will use *modelled* business value
weights, clearly flagged. Ten minutes of Boris's time answering the above would materially change the
ranking of the top ten opportunities.

---

## 1. Technology stack

| Layer | What | Evidence |
|---|---|---|
| CMS | WordPress, **block theme** (FSE), no page builder | `[OBSERVED]` `theme.json`, `templates/*.html` |
| Theme | Custom `geolander` — 11 HTML templates, 11 PHP block patterns, self-hosted variable fonts | `[OBSERVED]` |
| Plugin | Single custom `geolander-core` (16 classes) — CPTs, pricing engine, booking REST API, gateways, schema, SEO, i18n, AI-surface files | `[OBSERVED]` |
| Third-party SEO plugin | **None.** No Yoast/RankMath/SEOPress. All SEO is hand-rolled in `class-glc-seo.php` + `class-glc-schema.php` | `[OBSERVED]` |
| Rendering | **100% server-rendered PHP.** No React, no Next.js, no hydration, no JS content wall | `[OBSERVED]` |
| Client JS | Two small files only: `reveal.js` (1.2 KB, scroll animation) and `booking.js` (4.9 KB, quote widget) | `[OBSERVED]` |
| Sitemap | WordPress **core** sitemaps at `/wp-sitemap.xml`, with users + category + post_tag providers removed | `[OBSERVED]` `class-glc-seo.php` |
| Hosting | Railway (`railway.json`), Docker image, MySQL | `[OBSERVED]` |
| CDN | Not detectable from the repo. Cloudflare referenced only as a hypothetical in i18n comments | `[UNVERIFIED]` |
| Analytics | gtag scaffolding for GA4 + Google Ads, renders **only if IDs are configured in wp-admin** | `[OBSERVED]` `class-glc-seo.php::gtag()` |

**Architectural verdict, stated up front so it isn't buried:** this is a *well-built* codebase.
Server-rendered, no bloat, one plugin, self-hosted fonts, hand-written schema, a real i18n layer.
The brief warns against recommending a migration out of familiarity — there is no case for one here.
**The problems on this site are content, data-integrity and authority problems, not platform problems.**

---

## 2. Content model (custom post types & taxonomies)

| CPT | Public | URL pattern | Archive | Notes |
|---|---|---|---|---|
| `car` | yes | `/fleet/{slug}/` | `/fleet/` | Vehicle pages |
| `place` | yes | `/places/{slug}/` | `/places/` | Tourist destinations |
| `city` | yes | **`/car-rental-{slug}/`** (custom rewrite, root-level) | none | Delivery/landing pages |
| `testimonial` | **no** | — | — | `publicly_queryable: false` |
| `faq` | **no** | — | — | Feeds FAQPage schema on the front page only |
| `booking_request` | no | — | — | Booking log |

| Taxonomy | Attached to | URL |
|---|---|---|
| `car_brand` | car | `/brand/{term}/` |
| `car_body_type` | car | `/body-type/{term}/` |
| `place_region` | place | `/region/{term}/` |

**Note on the city URL design `[OBSERVED]`:** `/car-rental-batumi/` puts the full target keyword at
root level with no directory. That is a deliberate, defensible choice. But it also means the city pages
have **no parent** — there is no `/car-rental/` hub, so these four pages are structurally orphaned from
each other except via footer links. Covered in `INTERNAL-LINK-GRAPH.md`.

**Note on taxonomy archives `[INFERENCE]`:** `car_brand` and `car_body_type` are `public: true` and are
**included in the sitemap** (only `category` and `post_tag` were removed). With ~19 cars across ~5 brands
and 2 body types, these archives are near-duplicates of `/fleet/` and of each other. This is a live index
-bloat and cannibalisation risk against the fleet archive. Needs a decision, not a reflex — see
`TECHNICAL-SEO-AUDIT.md`.

---

## 3. URL inventory

Reconstructed from the codebase and confirmed by live fetches where noted. **This is not a crawl.**
`/wp-sitemap.xml` returned as unparseable binary to the available fetch tool `[OBSERVED]`, and this
sandbox blocks direct HTTP clients, so a true crawl-based inventory is still outstanding.

### 3.1 Static pages `[OBSERVED — live nav/footer]`

| URL | Purpose | Live word count |
|---|---|---|
| `/` | Home | not measured |
| `/fleet/` | Vehicle archive | not measured |
| `/places/` | Destination archive | not measured |
| `/travel-info/` | Practical driving info | ~550 `[OBSERVED]` |
| `/music/` | **Georgian music** | not measured |
| `/terms/` | Rental terms | ~700 `[OBSERVED — repo source]` |
| `/contact/` | Contact | not measured |
| `/privacy-policy/` | Privacy (Ads compliance) | `[UNVERIFIED]` — referenced in docs, not fetched |

### 3.2 City landing pages `[OBSERVED]`

`/car-rental-tbilisi/` · `/car-rental-batumi/` · `/car-rental-kutaisi/` · `/car-rental-kobuleti/`

Batumi fetched and measured: ~1,100 words, genuinely Batumi-specific (Mtirala National Park,
Machakhela valley, Gonio, Sarpi, Goderdzi Pass, airport code BUS). Headings: *Car Rental in Batumi →
Why you want 4×4 in Adjara → Batumi in summer → Our Fleet → Frequently asked questions*.
**This is the strongest page type on the site.** The FAQ block appears duplicated across city pages
`[OBSERVED]`.

### 3.3 Route guides `[OBSERVED — repo]`

`/driving-to-kazbegi-in-winter/` · `/svaneti-4x4-road-trip-guide/` · `/tusheti-4x4-rental-guide/`

Published via `_migration/publish-route-guides.php` as `page` posts carrying `glc_guide_route` meta,
which triggers `Article` schema. `[UNVERIFIED]` — not fetched live; publish status unconfirmed.

### 3.4 Vehicle pages — **and the biggest problem on the site**

`/fleet/{slug}/`. Three sources disagree about how many vehicles exist:

| Source | Vehicle count | Evidence |
|---|---|---|
| `/fleet/` live page | **19** | `[OBSERVED]` |
| `/llms.txt` live | **15** ("15 exact, individually listed 4x4 vehicles") | `[OBSERVED]` |
| `/pricing.md` live | **8** | `[OBSERVED]` |
| `_migration/cars.json` | **15** | `[OBSERVED]` |

And the live fleet list contains what are almost certainly duplicate records `[OBSERVED]`:
"Jeep Wrangler 2017 White Sport" *and* "Jeep Wrangler 2017"; "Mitsubishi Outlander 2018 Black" *and*
"Mitsubishi Outlander 2018 Gray" — whose source folders in `_migration/fleet-import/` contain
**byte-identical images with identical modification times** `[OBSERVED]`, i.e. the same car imported
twice under two colours; plus a bare "Mitsubishi Outlander" with no year.

`[INFERENCE]` The original `import.php` run (15 cars) and a later `import-fleet.php` run (the
`fleet-import/` folders) both created posts, without dedupe. The result is a fleet archive listing
roughly 19 URLs for what appears to be ~13–15 physical cars.

**Downstream damage, all `[OBSERVED]`:**

- `/pricing.md` shows **"$0/day"** for Jeep Wrangler 2017 White Sport
- The Subaru Forester 2018 Black page's meta description references **"$0/day"**
- `/fleet/` displays **no prices at all**, while the fleet title tag promises "from $26/day" and
  `/llms.txt` promises "Fleet & live prices"
- Vehicle pages carry **~85 words of unique text**; everything else (insurance, mileage, roadside
  assistance, "Kazbegi, Gudauri, Kakheti, Svaneti") is byte-identical boilerplate across all 19

So the site currently publishes ~19 near-duplicate thin pages, several advertising a price of zero,
on a domain with essentially no authority to spend. This is the single highest-priority finding in the
entire engagement and it is a **content-data** fix, not an SEO-tactic fix.

### 3.5 Locale variants

Every route above also exists at `/ka/…`, `/ru/…`, `/uk/…`, `/ar/…`, `/zh/…`, `/fr/…`.

`[INFERENCE]` **~7× URL multiplication.** With ~19 car + 4 city + 3 guide + ~36 place + ~8 static ≈ 70
canonical routes, the crawlable surface is roughly **490 URLs** — on a domain that, on current evidence,
Google barely knows exists. The non-English variants translate the *UI chrome* only; vehicle body
content stays English by design `[OBSERVED — README]`. That is 6 locales × 19 thin car pages = ~114 URLs
that are thin in one language and *also* linguistically mismatched to their own `hreflang` declaration.

---

## 4. SEO implementation as built

Read line-by-line from `class-glc-seo.php`, `class-glc-schema.php`, `class-glc-i18n.php`.

### 4.1 Titles `[OBSERVED]`

Per-locale logic. English gets engineered commercial titles; other locales fall back to catalogue strings.

- Front page (EN): `Car Rental in Tbilisi, Georgia — 4x4 from ${min}/day | Geolander`
- Fleet archive (EN): `Car Rental Fleet in Tbilisi, Georgia — {N} Real 4x4s from ${min}/day`
- Car (EN): `Rent {title} in Tbilisi from ${price}/day`, falling back to `{title} Rental in Tbilisi, Georgia` when price is 0
- Places archive (EN): `Places to Visit in Georgia by Car — 36 Destinations` — **hard-coded "36"** `[OBSERVED]`, will silently lie the moment a place is added or removed
- Per-post override via `glc_seo_title_en` meta

### 4.2 Meta, OG, canonical `[OBSERVED]`

Locale-aware descriptions; car descriptions inject price. OG/Twitter cards on all pages, `og:url`
stripped of tracking params. Core handles `rel=canonical` on singular; `GLC_SEO` adds it for archives
and the front page. `redirect_canonical` is disabled for `city` singles so the custom rewrite survives.

### 4.3 hreflang `[OBSERVED]`

All 7 locales plus `x-default`, self-referencing and reciprocal, emitted at `wp_head` priority 1.
Built mechanically from the stripped request path, so alternates are generated for **every** URL
including ones that arguably shouldn't have them (see audit).

### 4.4 Language negotiation — the riskiest piece of the build

`GLC_I18n::boot()`: a prefix-less request with an `Accept-Language` header preferring a supported
non-English locale gets a **302 redirect** into that locale, remembered in a cookie. Requests without
the header — crawlers — always get English `[OBSERVED]`.

`[INFERENCE]` This is defensively written (sitemaps/feeds excluded, `Vary: Accept-Language` set,
`nocache_headers()` on the redirect, cookie written sparingly to protect shared caches). It will
probably behave correctly for Googlebot. But Google's own guidance is against automatic redirection
based on perceived language, and any crawler or preview fetcher that *does* send `Accept-Language`
will be bounced. It also means a Russian-speaking user who lands on the English page from a Google
result gets silently moved. **Flagged as a risk to verify empirically, not asserted as a defect.**

### 4.5 Structured data `[OBSERVED]`

One `@graph` per page: `AutoRental` (NAP, geo, 24/7 hours, `hasMap` → GBP, `priceRange`,
`currenciesAccepted`, `sameAs`) + `WebSite`, plus per-template:

| Template | Additional nodes |
|---|---|
| Car | `["Product","Car"]` with `AggregateOffer` from the live seasonal table + `BreadcrumbList` |
| Place | `TouristAttraction` + `BreadcrumbList` |
| City | `Service` (serviceType "Car rental", `provider` → business, `areaServed` → City) + `BreadcrumbList` |
| Route guide | `Article` (`about` → `TouristDestination`) + `BreadcrumbList` |
| Fleet archive | `ItemList` of all cars |
| Front page | `FAQPage` from 13 `faq` posts |

Assessed properly in `SCHEMA-PLAN.md`. Two things flagged now: `FAQPage` rich results have been
restricted by Google to authoritative government/health sites since 2023 `[FIRST-PARTY — to re-verify
against current Search Central docs]`, and `Product` markup on a *rental* vehicle sits in a policy grey
area distinct from Google's vehicle-listing (for-sale) guidance.

### 4.6 AI-surface files `[OBSERVED]`

`/llms.txt` and `/pricing.md`, both generated live from post data. `robots.txt` explicitly allows
GPTBot, OAI-SearchBot, ChatGPT-User, ClaudeBot, Claude-SearchBot, PerplexityBot, Google-Extended, CCBot.

`[INFERENCE]` Cheap, harmless, plausibly useful — and currently **propagating the price bug into the
machine-readable layer**, which is worse than not having it. `/pricing.md` tells any AI that a Jeep
Wrangler rents for $0/day.

### 4.7 robots.txt `[OBSERVED — live]`

```
Disallow: /wp-admin/
Allow: /wp-admin/admin-ajax.php
Sitemap: https://geo-lander.com/wp-sitemap.xml
+ Allow: / for the 8 named AI crawlers
```

Clean. No accidental blocks.

---

## 5. Booking funnel `[OBSERVED]`

```
Date selection  →  GET /wp-json/geolander/v1/quote   (live seasonal × duration price)
                →  POST /wp-json/geolander/v1/checkout
                →  booking_request post created, reference GL-XXXX
                →  WhatsApp deep link with pre-filled structured message
```

Conversion events fire on checkout: GA4 `booking_request` (value = quote total) and a Google Ads
`conversion` with `transaction_id` = booking reference for dedupe — **both only if the IDs are
configured in wp-admin, which cannot be verified from here** `[UNVERIFIED]`.

**Funnel observations:**

- No prepayment, no card capture → very low friction, genuinely differentiating
- The handoff leaves the site for WhatsApp → **anything after that point is invisible to analytics**.
  Booking-request-to-confirmed-booking conversion is currently unmeasurable. This is the single
  biggest attribution hole and it is addressed in `SEO-MEASUREMENT-FRAMEWORK.md`
- `/fleet/` shows no prices `[OBSERVED]` — the archive is where price-shopping visitors land and it
  answers the price question with silence

---

## 6. Media `[OBSERVED]`

- Hero 467 KB WebP + 669 KB JPG fallback; CTA background 398 KB WebP
- Logo **699 KB PNG** — and it is used as the schema `logo` *and* `image` for the business entity
- Route art delivered as WebP (61–142 KB) with JPG fallbacks
- 36 place JPGs, several **over 700 KB** (`bodbe_monastery.jpg` 921 KB, `abanotubani.jpg` 735 KB)
- Car photos: 5 per vehicle at 150–450 KB
- Source `fleet-import/` PNGs run to **35 MB each** — not shipped, but they indicate the originals
  exist at high resolution, which is an asset for real-photo differentiation
- Fonts self-hosted, variable, subset per script (Archivo, Noto Georgian/Cyrillic/Arabic, Plex Mono)

---

## 7. Defects found during inventory

Ranked by commercial damage. Full remediation tickets in `SEO-IMPLEMENTATION-BACKLOG.md`.

| ID | Severity | Defect | Evidence |
|---|---|---|---|
| **D-01** | **P0** | Duplicate vehicle records: ~19 published car URLs for ~13–15 physical cars, including two Jeep Wrangler 2017 entries and two Mitsubishi Outlander 2018 entries whose source images are byte-identical | `[OBSERVED]` live `/fleet/` vs `cars.json` vs `fleet-import/` |
| **D-02** | **P0** | Vehicles with `$0` pricing published live — surfaced in `/pricing.md`, in meta descriptions, and as missing prices on `/fleet/` | `[OBSERVED]` live |
| **D-03** | **P0** | Vehicle pages carry ~85 words of unique content each; ~19 near-duplicate thin pages × 7 locales ≈ 133 URLs of near-duplicate thin content | `[OBSERVED]` live |
| **D-04** | **P1** | Price floor stated inconsistently: `$26` (fleet title), `$28` (homepage + llms.txt). A visitor who clicks a "$26" snippet and sees "$28" has been given a reason to distrust | `[OBSERVED]` |
| **D-05** | **P1** | **Terms contradict the sales promise.** Homepage/llms.txt: "full insurance included". `/terms/` §2: "Basic insurance (CDW) … covers collision damage **with a deductible**. Additional full coverage available for an extra fee." | `[OBSERVED]` `setup-pages.php` |
| **D-06** | **P1** | **Terms prohibit the core use case.** §8 Prohibited Uses forbids "off-road driving (unless vehicle is specifically approved)" — on a site whose entire positioning is 4×4 mountain adventure, and whose guides promote Abano Pass and Ushguli | `[OBSERVED]` |
| **D-07** | **P1** | Deposit amount never stated anywhere on the site, while customer research consistently shows deposits are a top-3 rental anxiety | `[OBSERVED]` absence |
| **D-08** | **P2** | `/pricing.md` lists 8 vehicles, `/llms.txt` says 15, `/fleet/` shows 19 — the AI-readable layer contradicts the site and itself | `[OBSERVED]` |
| **D-09** | **P2** | Hard-coded "36 Destinations" in the places archive title | `[OBSERVED]` `class-glc-seo.php` |
| **D-10** | **P2** | `car_brand` / `car_body_type` archives public and in the sitemap; near-duplicates of `/fleet/` | `[OBSERVED]` + `[INFERENCE]` |
| **D-11** | **P2** | 699 KB PNG logo used as the schema business `image` | `[OBSERVED]` |
| **D-12** | **P3** | `/music/` (Georgian music) — off-topic for a rental entity; needs a keep/repurpose/remove decision on evidence, not reflex | `[OBSERVED]` |
| **D-13** | **P3** | Accept-Language 302 redirect contradicts Google's guidance on automatic language redirection; needs empirical verification with a real crawler | `[INFERENCE]` |

**D-05 and D-06 deserve emphasis.** They are not SEO defects in the technical sense — no crawler will
penalise them. They are *trust* defects, and trust is the currency this business is actually short of.
A traveller who has read Reddit horror stories about Georgian rental deposits, who then finds that the
terms page quietly contradicts the homepage on insurance and forbids the exact driving the brand sells,
has been handed a reason to book with someone else. Fixing these costs an afternoon and no ranking risk.

---

## 8. What this inventory could not establish

Stated plainly, because the brief requires it:

1. **A real crawl.** `/wp-sitemap.xml` is unparseable to the fetch tool available here and this sandbox
   blocks direct HTTP clients. Orphan pages, redirect chains, 404s, actual index size and click depth
   are therefore **unknown**. A Screaming Frog crawl (free tier covers 500 URLs — enough) would close this.
2. **Index coverage.** Whether any of these URLs are indexed at all. Requires GSC.
3. **Whether GA4/Ads IDs are configured.** Requires wp-admin.
4. **Live Core Web Vitals.** Requires PageSpeed Insights/CrUX against the live origin.
5. **Route guide publish status.** The three guide URLs were not fetched.
6. **GBP state** — categories, photos, review count, review velocity, whether the website link is set.
7. **Whether the Accept-Language redirect actually fires for Googlebot.**

---

## Sources

- Repository: `C:\Users\Boris\Dell\Projects\APPS\Geolander\Geolander_WordPress` (connected folder)
- [geo-lander.com — homepage](https://geo-lander.com/)
- [geo-lander.com/fleet/](https://geo-lander.com/fleet/)
- [geo-lander.com/fleet/subaru-forester-2018-black/](https://geo-lander.com/fleet/subaru-forester-2018-black/)
- [geo-lander.com/car-rental-batumi/](https://geo-lander.com/car-rental-batumi/)
- [geo-lander.com/travel-info/](https://geo-lander.com/travel-info/)
- [geo-lander.com/llms.txt](https://geo-lander.com/llms.txt)
- [geo-lander.com/pricing.md](https://geo-lander.com/pricing.md)
- [geo-lander.com/robots.txt](https://geo-lander.com/robots.txt)
