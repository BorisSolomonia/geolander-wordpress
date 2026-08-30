# SEO-BASELINE.md — Geolander T0

**Baseline date (T0):** 2026-08-14 · **Status: MODELLED, NOT MEASURED**

---

## Read this first

The brief asks for a measurable baseline against which all future work is judged. **I cannot produce
one.** No Google Search Console, no GA4, no Ahrefs/Semrush/DataForSEO, no server logs, no booking data
were available for this engagement (confirmed choice: "None — model everything").

I am not going to manufacture a baseline out of inference and present it as a measurement. Doing so
would poison every subsequent comparison — in three months there would be no honest way to say whether
anything improved, because T0 would have been fiction.

So this document does three things instead:

1. States what **can** be honestly observed today, with its evidence
2. States what is **structurally knowable but unverified**, and why
3. Gives Boris the **exact export list** that converts this into a real T0 — with the specific settings,
   because a wrong date range or an unfiltered brand query makes the export useless

**Until step 3 is done, treat every number in every deliverable in this set as directional.**

---

## Part 1 — What is honestly observable today

### 1.1 Brand-entity visibility: effectively zero `[OBSERVED]`

Three separate web searches were run:

| Query | Result |
|---|---|
| `Geolander car rental Tbilisi geo-lander.com` | No Geolander result. Returned Geo Rent Car, geocarrental.ge, geocarrent.ge, GeoDrive, Expedia |
| `"geo-lander.com" OR "Geolander" car rental Georgia 4x4` | No Geolander result. Returned only Expedia/Kayak pages for the **US state** of Georgia |
| `Geolander car rental Tbilisi` (within workflow) | pending |

**Two caveats I will not paper over.** First, the search tool available here is US-locale, which
mangles "Georgia" queries badly — note that the second search returned US-state results exclusively.
Second, a search tool's index is not Google's index. **Absence here is not proof of absence in Google.**

But an exact-match search on the literal domain string `"geo-lander.com"` returning nothing at all is
still a meaningful signal `[OBSERVED]`. `[INFERENCE]` The most probable reading: the domain has close to
zero referring domains, close to zero brand-entity presence in third-party sources, and is not yet
established as a known entity. That is exactly what `docs/seo-geo-aeo.md` implies too — it is a launch
playbook written in July 2026, i.e. this site is roughly a month old in search terms.

**Working assumption for the whole strategy, flagged as an assumption:** Geolander is a
**near-zero-authority, near-zero-history domain**. If GSC shows otherwise, several strategy weightings
change and I will revise. This is the single most important assumption to falsify first.

### 1.2 Indexable surface `[INFERENCE from code]`

| Layer | Count | Basis |
|---|---|---|
| Canonical EN routes | ~70 | ~19 car + 4 city + 3 guide + ~36 place + ~8 static |
| × 7 locales | **~490 crawlable URLs** | `class-glc-i18n.php` |
| Of which near-duplicate thin car pages | **~133** (19 × 7) | ~85 unique words each `[OBSERVED]` |

`[INFERENCE]` **Roughly 27% of the crawlable surface is near-duplicate thin content.** On an established
domain that is untidy. On a domain with no crawl budget to spare and no authority to absorb quality
signals, it is a materially bad first impression.

### 1.3 Technical foundation: strong `[OBSERVED]`

Genuinely done right, and worth saying so — these are the things that are usually broken and here are not:

- 100% server-rendered HTML, no JS content wall
- robots.txt clean, no accidental blocks, sitemap declared
- Core sitemaps with author/category/tag providers stripped
- hreflang across 7 locales + x-default, self-referencing and reciprocal
- Canonical handling on every template type, including a custom-rewrite edge case
- Structured data hand-written per template into a single `@graph`
- Self-hosted subset variable fonts
- Two small JS files totalling ~6 KB
- NAP in schema matches the Google Business Profile exactly, with real geo coordinates

`[INFERENCE]` The technical layer is **not the bottleneck**. Strategy E ("technical excellence") will
therefore score poorly on incremental upside — most of that value has already been captured. That is a
finding, not a criticism.

### 1.4 Content quality: bimodal `[OBSERVED]`

| Page type | Assessment |
|---|---|
| City pages | ~1,100 words, genuinely local (Batumi page names Mtirala, Machakhela, Gonio, Sarpi, Goderdzi Pass, airport code BUS). **Good.** |
| Route guides | 3 published, substantial per source. **Promising.** `[UNVERIFIED]` live |
| Travel info | ~550 words, practical, thin for its ambition |
| Vehicle pages | **~85 unique words. Bad.** |
| Fleet archive | Lists 19 vehicles with **no prices**. Bad — this is the price-shopper's landing page |

### 1.5 Data integrity: broken `[OBSERVED]`

Fleet count reported as 19 (live) / 15 (llms.txt) / 8 (pricing.md) / 15 (source JSON). Vehicles
publishing `$0/day`. Duplicate car records. Price floor stated as both $26 and $28. Full detail in
`SITE-INVENTORY.md` §7.

`[INFERENCE]` This matters more than its size suggests. Google's quality guidance and every AI grounding
system reward factual consistency about an entity. A site that cannot agree with itself about how many
cars it has, or that publishes a rental price of zero, is supplying contradictory facts about itself
into exactly the layer that decides whether to recommend it.

### 1.6 Core Web Vitals: unmeasured

PageSpeed Insights API returned **HTTP 429 (rate limited)** on attempt `[OBSERVED]`. Will retry.

Lab-level inference from assets `[INFERENCE]`: hero 467 KB WebP as the likely LCP element, ~6 KB total
JS, no render-blocking third-party scripts beyond gtag. LCP is plausibly the only at-risk metric.
INP should be excellent given almost no JavaScript. **CLS unknown** — the `reveal.js` scroll-animation
pattern is a common CLS source and needs checking. `docs/PERFORMANCE_AUDIT.md` exists in the repo and
should be reconciled against live field data rather than trusted as current.

---

## Part 2 — Business KPIs: entirely unknown

The brief is explicit that traffic is not the success metric and that prioritisation should follow
gross profit. None of the following is known:

organic sessions · organic inquiries · WhatsApp click-throughs · booking-request submissions ·
booking-request → confirmed-booking rate · organic revenue · gross profit by vehicle class ·
fleet utilisation · average rental duration · average order value · repeat-customer rate

**There is also a structural measurement hole, independent of access.** The funnel hands off to
WhatsApp `[OBSERVED]`. Everything after that handoff — the actual booking, the actual money — happens
in a channel with no analytics. Even with full GA4 access, `booking_request` events would be
countable but confirmed bookings would not. Fixing this is a prerequisite for honest ROI measurement
and is specified in `SEO-MEASUREMENT-FRAMEWORK.md`.

---

## Part 3 — The export list that makes this real

Ordered by how much each one improves the plan per minute of Boris's time.

### Priority 1 — 20 minutes, transforms the engagement

**1. Google Search Console → Performance → Search results**

- Date range: **Last 16 months** (the maximum; captures whatever history exists)
- Export **four** separate CSVs, each with the full date range:
  - Queries
  - Pages
  - Countries
  - Devices
- Then a fifth: Queries filtered to **Query does not contain `geolander` / `geo lander` / `geo-lander`**
  → this is the non-brand baseline, and it is the number that actually matters

**2. Google Search Console → Indexing → Pages** — export the full report. This settles §1.2 and §1.5:
how many of the ~490 URLs are indexed, how many are excluded, and under which reason
("Duplicate without user-selected canonical" and "Crawled – currently not indexed" are the ones to
watch given the thin car pages).

**3. Confirm GSC property type.** Domain property or URL-prefix? If URL-prefix, are the locale paths
covered? If the property was only added recently, say when — it caps how far back any baseline can go.

### Priority 2 — 15 minutes

**4. GA4** — if configured (`class-glc-seo.php` only renders gtag when an ID is set, so this may be
unconfigured; **please confirm either way**):

- Reports → Acquisition → Traffic acquisition, **Session default channel group = Organic Search**,
  last 12 months, exported
- Reports → Engagement → Landing page, same filter and range
- Explore → any `booking_request` events, by landing page

**5. Google Business Profile** — screenshot or export of: primary + secondary categories, review count,
average rating, review dates over the last 6 months, photo count, whether the website link points to
`geo-lander.com`, and whether messaging is enabled. GBP is likely the highest-leverage asset this
business owns and it is completely invisible from here.

### Priority 3 — 30 minutes, closes the crawl gap

**6. Screaming Frog SEO Spider (free tier, 500 URLs — enough)** on `https://geo-lander.com/`.
Export: Internal → All, Response Codes, Page Titles, Meta Description, H1, Canonicals, Hreflang,
Directives, and the **Orphan Pages** report (needs the sitemap connected in config).
This single run resolves redirect chains, 404s, click depth, orphans and real index size at once.

**7. PageSpeed Insights** on `https://geo-lander.com/`, `/fleet/`, one car page and
`/car-rental-batumi/` — mobile strategy. Note specifically whether the **CrUX field data** section
appears; if it does not, the site has insufficient real traffic to have field data, which is itself a
baseline data point.

### Priority 4 — the business numbers

**8. Answer the Phase 0 gaps** listed in `SITE-INVENTORY.md` §0 — even rough answers. Specifically:
approximate bookings per month, the split of rental durations, which vehicles are booked most, which
have the best margin, deposit amount and policy, and what proportion of customers speak
Russian vs English. **These change the strategy ranking more than any SEO tool export would.**

---

## Part 4 — The metrics this baseline will be judged against

Once the exports land, T0 gets locked on these. Recording them here so the goalposts cannot move.

### Output KPIs (fully in our control)
Duplicate car records resolved · vehicle pages with real unique content · P0/P1 technical tickets
closed · relevant referring domains acquired · outreach conversations opened · pages created

### Leading SEO KPIs (measured in GSC)
**Non-brand** impressions · **non-brand** clicks · commercial-cluster impressions · CTR on commercial
queries · queries in positions 1–3 / 4–10 / 11–20 · indexed strategic pages (target: 100% of city,
guide and vehicle pages) · excluded-page count and reason mix · local-pack visibility for the four
delivery cities

### Business KPIs (require the measurement work in `SEO-MEASUREMENT-FRAMEWORK.md`)
Organic → WhatsApp handoffs · booking requests from organic · **confirmed bookings from organic** ·
booking-request → confirmed conversion rate · organic revenue · gross profit from organic customers

**Traffic is deliberately absent from the business layer.** For a 15-car fleet, incremental traffic
past the point of full utilisation in peak season has zero marginal value. That constraint should
shape the strategy, and it does — see `GOLDEN-PATH.md`.

---

## Honest summary

**Where we are now:** a technically well-built, near-invisible, roughly one-month-old site with good
city pages, three good route guides, a broken vehicle-data layer, and no measurement. The bottleneck
is not technical SEO. It is **authority, entity presence, and content depth on the pages that matter** —
plus a data-integrity problem that must be fixed before anything is amplified, because amplifying
19 near-duplicate pages advertising $0/day is worse than doing nothing.

**Confidence:** medium on the code-derived findings (read directly from source and confirmed live),
**low on all market and ranking claims** until the GSC export exists.

---

## Sources

- [geo-lander.com](https://geo-lander.com/) and sub-pages (fetched 2026-08-14)
- Repository at `C:\Users\Boris\Dell\Projects\APPS\Geolander\Geolander_WordPress`
- [Geo Rent Car](https://www.georentcar.ge/), [geocarrental.ge](https://geocarrental.ge/), [geocarrent.ge](https://www.geocarrent.ge/), [GeoDrive](https://en.geodrive.info/) — returned in place of Geolander on brand-adjacent searches
