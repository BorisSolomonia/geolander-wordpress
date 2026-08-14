# KEYWORD-MAP.md — Geolander

**Date:** 2026-08-14 · Companion to `KEYWORD-UNIVERSE.csv` (37 seeds) and `SERP-INTENT-MATRIX.md`

---

## Read this before using the map

**Every `search_volume`, `cpc` and `keyword_difficulty` field in `KEYWORD-UNIVERSE.csv` says
`UNMEASURED`.** No keyword tool was available. `[INFERENCE]` I would rather ship a keyword map with an
honest hole in it than one with plausible-looking invented numbers, because invented volumes drive real
budget decisions.

**Every `current_rank` says `UNKNOWN`** — no Search Console access.

**Every `local_pack_presence` says `NOT OBSERVED`** — the search tool is US-locale and showed no map
units on any query. `[OBSERVED]` It also returned *"Car-Rentals-In-Countryland-Golf-Club"* for
"car rental georgia country", which is the clearest possible demonstration of why these results cannot
be treated as Georgian SERPs.

**What the map IS built on:** ~90 real searches across 7 languages, ~40 competitor pages read in full,
64 customer-voice sources, and three adversarial agents that refuted the original thesis. Every row
carries an `evidence_source` naming what it rests on.

**The map is therefore ordered by evidenced customer anxiety and observed SERP weakness — not by
volume.** For a business with fifteen cars and hard-capped capacity, `[INFERENCE]` that is arguably the
better ordering anyway. But it is a substitute, and one locale-correct SERP capture plus a Keyword
Planner pull would materially improve it.

---

## The mapping: demand → intent → URL → purpose → conversion

### Cluster 1 · Route permission — **the flagship**

| | |
|---|---|
| **URL** | `/where-you-can-drive/` + `/tusheti/`, `/svaneti/`, `/kazbegi/` children |
| **Intent** | Commercial investigation — the traveller has an itinerary and needs a company that permits it |
| **Purpose** | Answer, road by road, what Geolander permits and what its insurance covers |
| **Conversion** | Direct to the permitted vehicles, then the quote widget |
| **Why this URL and not another** | `[OBSERVED]` No rental company currently answers this. The pages ranking are an aggregator's journal, two travel blogs and a niche activity site |
| **Gate** | **Blocked on the route-permission decision.** Publishing a permission that cannot be honoured is the worst failure mode in the plan |

Keywords: `can i drive a rental car to tusheti` · `rental car forbidden roads georgia` ·
`mestia ushguli lentekhi road rental car allowed` · `abano pass rental car insurance` ·
`goderdzi pass rental car allowed` · `juta truso valley rental car insurance` ·
`car rental georgia gps tracker restricted areas`

### Cluster 2 · Trust, deposit and insurance

| | |
|---|---|
| **URLs** | `/trust/deposit-policy/` · `/trust/what-our-insurance-covers/` |
| **Intent** | Decision-stage. The last objection before booking |
| **Purpose** | Publish the numbers nobody else commits to in writing |
| **Conversion** | Removes the final objection; links to the quote |
| **Why it can win** | `[OBSERVED]` **Localrent's terms page is robots-blocked (`Disallow: /*terms`)** — the market leader has disqualified itself from every deposit, excess, insurance and cancellation query |
| **Gate** | Blocked on four numbers from Boris |

Keywords: `car rental tbilisi no deposit no credit card` · `car rental georgia deposit not returned` ·
`car rental georgia full insurance what does it cover` · `third party liability rental car georgia limit` ·
`what to check when picking up rental car georgia`

**Honest note `[OBSERVED]`:** the "no deposit" head term is contested by **eleven near-identical
operators** with the literal string *"No Deposit, No Credit Card"* in their title tags. `[INFERENCE]`
Geolander cannot out-shout them. It can out-*evidence* them — none of the ones fetched backs the claim
with a process.

### Cluster 3 · The 4×4 decision — **demoted after the stress test**

| | |
|---|---|
| **URLs** | `/fleet/4x4-suv/` (commercial) · `/guides/do-you-need-a-4x4-in-georgia/` (informational) |
| **Intent** | Split. The category page is transactional; the guide is informational |
| **Purpose** | Category page captures vehicle-type demand; the guide answers honestly, in tiers |
| **Why demoted** | `[OBSERVED]` Gergeti (paved) 1,283 TripAdvisor reviews vs Tusheti (4WD-mandatory) **70**. Roads sealed and concreted. **og.ge already owns the jeep-vs-sedan page** with live prices and CTAs. Kayak quotes a $27 4×4 — below Geolander's floor |
| **What survives** | `[OBSERVED]` **Localrent has no 4×4/SUV page for Georgia at all**, confirmed via its own sitemap. The category page is still worth building |
| **Hard constraint** | `[OBSERVED]` **13 of 15 vehicles are AWD crossovers.** Do not write "you need a 4×4". Write clearance, winter, named routes, permission |

### Cluster 4 · Destination and route

| | |
|---|---|
| **URLs** | `/car-rental-kazbegi/` (new) · existing guides · `/guides/mountain-road-opening-calendar/` |
| **Intent** | Mixed — planning through decision |
| **Best single opportunity** | `[OBSERVED]` **Localrent's `/en/georgia/stepantsminda/` is blank** — two headings, zero cars, zero prices, and a loading string. Stepantsminda *is* Kazbegi |
| **Linkable asset** | The road-opening calendar. `[CUSTOMER]` *"open only two months in the year"* — and nobody says which two |

### Cluster 5 · Local, city and airport

| | |
|---|---|
| **URLs** | Existing `/car-rental-{city}/` pages · new `/trust/airport-pickup/` |
| **Stance** | **Keep, do not chase.** `[OBSERVED]` *"rent a car tbilisi"* returned seven results with zero Georgian operators |
| **But** | `[OBSERVED]` rentcarsgeorgia.com holds an airport slot with ~2,200 words, a 6-question FAQ and a price table. **Not unwinnable — just not worth the primary budget** |
| **Real lever** | The local pack, via GBP — `[UNVERIFIED]`, and the first thing to check |

### Cluster 6 · Substitute product and top of funnel

`/guides/rent-a-car-or-hire-a-driver/` · `/guides/driving-in-georgia/`

`[OBSERVED]` "Hire a driver" is the recommended answer in 4 of 14 forum threads, **pushed by Tbilisi
tour operators active on those forums**. This is organised competition for the decision to self-drive at
all. `[OBSERVED]` And write nothing about crime — not one traveller raised it.

### Cluster 7 · Cross-border

`/guides/driving-to-armenia/` — `[OBSERVED]` the capability already exists in `/terms/` §6 (48 hours'
notice) and is buried in a terms page while travellers are being pushed to Avis and Hertz on paperwork
capability alone.

### Cluster 8 · Winter — counter-seasonal

`/guides/driving-in-georgia-in-winter/` — `[OBSERVED]` winter tyres are mandatory Dec 1 – Mar 1 on
mountain roads. `[INFERENCE]` The one season where the 4×4 argument is unambiguous *and* legally
reinforced, aimed at a business whose peak is summer.

### Cluster 9 · Vehicle condition and models

`/fleet/{vehicle}/` ×~14 — **conversion pages, not ranking pages.**

`[OBSERVED]` 107 condition complaints vs 13 deposit disputes in one 1,453-review set — **8:1**.
`[INFERENCE]` Per-model search demand is negligible; these pages exist to prove condition, and that is
worth more than the ranking would have been.

### Cluster 10 · Brand — an entity problem, not a content problem

`[OBSERVED]` A quoted search for `"geo-lander.com"` returns Wikipedia articles on *"Geo (landform)"*.
Yokohama GEOLANDAR owns the bare term, in the same semantic space, bound to the same vehicle models.
**No page fixes this.** GBP, consistent NAP, `sameAs`, third-party corroboration, and compound phrases.

### Cluster 11 · Russian — **conversion support only**

`[OBSERVED]` **Refuted at high confidence.** `georentcar.ge/ru/turistam/zapreschennye-marshruty.html`
already owns the permission wedge in Russian, with insurance consequences stated. Per-model Russian
pages exist. The discovery layer is affiliate listicles that list only aggregators.

**Fix `/ru/` as hygiene. Write no Russian content programme.** Revisit only after the English proof
layer is built and measured.

---

## Cannibalisation risks to watch

| Risk | Mitigation |
|---|---|
| `/fleet/4x4-suv/` vs `/body-type/suv/` vs `/body-type/crossover/` | `[OBSERVED]` The taxonomy archives are public and in the sitemap. **Pick one, redirect or `noindex` the others** before building |
| `/tusheti-4x4-rental-guide/` vs `/where-you-can-drive/tusheti/` | 301 the guide into the permission page. One URL per intent |
| ~19 near-duplicate vehicle pages | `[OBSERVED]` Already cannibalising each other. Dedupe first |
| `/car-rental-kazbegi/` vs `/driving-to-kazbegi-in-winter/` vs `/where-you-can-drive/kazbegi/` | **Three genuinely different intents** — commercial, seasonal-informational, permission. Keep separate, cross-link deliberately, and check GSC in 90 days for overlap |
| 7 locale copies of everything | `[OBSERVED]` The `hreflang` is correct, so this is duplication *by design* — but non-EN pages serving English body content weaken the whole set. Narrow to en + ru |

---

## What would most improve this map

1. **A locale-correct SERP capture** (`gl=ge`, `uule`=Tbilisi, ~20 queries, full SERP with local pack and
   AI Overview). A few dollars, one hour. `[OBSERVED]` **Nobody in this engagement has seen a real
   google.ge result set.**
2. **Google Keyword Planner**, country = Georgia *and* the real source markets, languages EN/RU/KA,
   with the month-by-month split — which settles the seasonality question the stress test raised.
3. **GSC Performance, 16 months, queries** — converts every `UNKNOWN` rank into a number and reveals the
   queries already producing impressions that nobody has thought to target.
