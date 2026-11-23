# SEO-SITE-ARCHITECTURE.md — Geolander

**Date:** 2026-08-14 · Derived from keyword clusters, customer journey evidence and SERP composition —
not from a generic template.

---

## Principles this architecture follows

1. **Fewer, deeper pages.** `[INFERENCE]` On a near-zero-authority domain, 12 excellent pages beat 100
   adequate ones. The current ~490-URL crawlable surface is already too large for the authority available.
2. **Category over unit.** `[OBSERVED]` Demand exists for "4×4 rental Tbilisi", not for "Subaru Forester
   2018 black rental". Vehicle pages should convert, not rank.
3. **Permission and knowledge are the hub, not an afterthought.** `[OBSERVED]` The decisive field in this
   market's own comparison pages is a permission statement.
4. **No combinatorial padding.** `[OBSERVED]` gttoursgeorgia.com generates pickup-city × destination
   pages like "Sighnaghi to Svaneti". Nobody starts a Svaneti trip from Sighnaghi. Copy the URL logic,
   never the cross-product.
5. **Preserve URLs.** `[OBSERVED]` The existing `/car-rental-{city}/` and `/fleet/{car}/` patterns are
   good. Nothing below renames them.

---

## Target hierarchy

```
/                                          Home — proposition, trust proof, live quote entry
│
├── /car-rental/                           ★ NEW — coverage hub (parent for the city pages)
│   ├── /car-rental-tbilisi/               KEEP  ~1,850 words, strong
│   ├── /car-rental-batumi/                KEEP  ~1,100 words, strong
│   ├── /car-rental-kutaisi/               KEEP
│   ├── /car-rental-kobuleti/              KEEP
│   └── (further cities ONLY where real delivery + real local knowledge exist)
│
├── /fleet/                                IMPROVE — must show prices
│   ├── /fleet/4x4-suv/                    ★ NEW — the primary category page
│   ├── /fleet/7-seater/                   ★ NEW — Highlander; a real distinct need
│   ├── /fleet/long-term/                  ★ NEW — 19–30 / 31+ day tiers (see note)
│   └── /fleet/{vehicle}/  ×~14            REBUILD — conversion pages, not ranking pages
│
├── /where-you-can-drive/                  ★★ NEW — THE FLAGSHIP
│   │                                      Road-by-road permission table × fleet × insurance
│   ├── /where-you-can-drive/tusheti/      ★ NEW — the highest-intent single destination
│   ├── /where-you-can-drive/svaneti/      ★ NEW — incl. the 2024 Ushguli sealing
│   └── /where-you-can-drive/kazbegi/      ★ NEW
│
├── /guides/                               ★ NEW — knowledge hub
│   ├── /do-you-need-a-4x4-in-georgia/     ★★ NEW — the market's most contested question
│   ├── /driving-in-georgia/               ★ NEW — the honest reality piece (from /travel-info/)
│   ├── /rent-a-car-or-hire-a-driver/      ★ NEW — the substitute-product comparison
│   ├── /mountain-road-opening-calendar/   ★★ NEW — the linkable asset
│   ├── /driving-in-georgia-in-winter/     ★ NEW — counter-seasonal
│   ├── /driving-to-armenia/               ★ NEW — cross-border; capability already exists
│   ├── /driving-to-kazbegi-in-winter/     KEEP + deepen
│   ├── /svaneti-4x4-road-trip-guide/      KEEP + update for 2024 sealing
│   └── /tusheti-4x4-rental-guide/         MERGE into /where-you-can-drive/tusheti/
│
├── /trust/                                ★ NEW — the proof cluster
│   ├── /deposit-policy/                   ★★ NEW
│   ├── /what-our-insurance-covers/        ★★ NEW
│   ├── /airport-pickup/                   ★ NEW — the arrival protocol (also an intent page)
│   ├── /about/                            ★ NEW — real people, real address, registration
│   └── /terms/                            REWRITE
│
├── /places/                               REPURPOSE — "how you drive there", not "what it is"
│   └── /places/{place}/  ×~36             REPURPOSE — surface, clearance, parking, season
│
├── /contact/                              IMPROVE — form, embedded map, review proof
├── /music/                                EXPERIMENT — decide on GSC evidence
├── /privacy-policy/                       KEEP
├── /llms.txt  ·  /pricing.md              KEEP — fix the data they emit
│
└── /ru/…                                  Fix the redirect loop. NO content programme (refuted)
```

`★` new · `★★` new and flagship

---

## The five structural changes that matter

### 1. Add `/car-rental/` as a real coverage hub

`[OBSERVED]` The four city pages sit at root with **no parent**, connected only via the footer. A hub
gives them a shared parent, a natural internal-link source, a place for the delivery-radius story, and
somewhere for future cities to attach without cluttering root.

**Do not** change the city URLs — `/car-rental-batumi/` puts the full keyword at root and that is right.
The hub links down; the children keep their URLs.

### 2. Introduce a category layer under `/fleet/`

`[OBSERVED]` `car_body_type` and `car_brand` archives already exist, are public, and are in the sitemap —
they are latent category pages currently doing nothing but duplicating `/fleet/`.

`[INFERENCE]` Convert `/body-type/suv/` into a real `/fleet/4x4-suv/` page with genuine intro content,
the fleet grid, prices, and links to the permission table. This is where "4×4 rental Tbilisi" demand
should land — **not** on an individual vehicle. Then either give `/brand/{term}/` real content or
`noindex` them; do not leave them as bare loops.

**`/fleet/long-term/` is speculative and flagged as such `[EXPERIMENT]`.** `[OBSERVED]` The pricing engine
has 19–30 and 31+ day tiers with rates ~30–40% below the daily rate, which implies long rentals matter
commercially. But **no booking-duration data was available**, so whether this deserves a page is unknown.
It is included because if long rentals *are* the profit engine, its absence would be the biggest miss in
this architecture — and one question to Boris settles it.

### 3. `/where-you-can-drive/` becomes a top-level section

`[INFERENCE]` This is the architectural expression of the strategic thesis. It is not a blog post filed
under guides — it is a **product specification**, because in this market permission *is* the product
differentiator. Top-level placement signals that, keeps click depth at 1, and gives the three
highest-intent destinations their own URLs rather than sharing one page (which `[OBSERVED]` is exactly
the weakness of rentcarsgeorgia.com's otherwise-strong mountain page).

### 4. `/trust/` cluster — turning policies into pages

`[OBSERVED]` The deposit amount appears nowhere on the site. "Full insurance" is asserted on the homepage
and contradicted in the terms. `[CUSTOMER]` These are the two things travellers argue about most.
`[INFERENCE]` Policies buried in a terms page cannot rank, cannot be cited by an AI, and cannot reassure
anyone who does not read terms pages — which is almost everyone.

### 5. Repurpose `/places/` rather than deleting it

`[OBSERVED]` 36 pages currently claim `TouristAttraction` authority with an address of "Georgia".
`[INFERENCE]` Geolander is not the authority on Gergeti Trinity Church, but **it is the authority on the
road to it**. Repurposing converts 36 weak pages into 36 pages nobody else can write, **without creating
a single new URL or losing any equity**. `[OBSERVED]` carrentdeme.com proves the framing works, ranking
on Kazbegi with the H1 *"The Real Roads of Kazbegi — What Your Vehicle Needs to Handle"*.

---

## What gets removed or reduced

| Item | Action | Reason |
|---|---|---|
| ~5 duplicate `car` posts | **301 → survivor** | `[OBSERVED]` Same physical cars imported twice. Redirect, never delete |
| `/tusheti-4x4-rental-guide/` | **301 → `/where-you-can-drive/tusheti/`** | Consolidates the highest-intent topic into one authoritative URL |
| `/brand/{term}/` archives | **Content or `noindex`** | `[INFERENCE]` Near-duplicates of `/fleet/` as they stand |
| `ar`, `zh`, `fr` locales | **Keep UI, stop emitting content-level `hreflang`** | `[OBSERVED]` Total OTA walls with zero `.ge` penetration; the pages serve English body content under a foreign language declaration |
| Individual vehicle pages in the sitemap | **`[EXPERIMENT]`** | Once categories exist, test whether `noindex, follow` on units improves category performance. **Test, do not assume** |

---

## Locale architecture

**Current `[OBSERVED]`:** 7 locales × ~70 routes ≈ 490 URLs, with six of the seven homepages returning
**"Too many redirects"**, and non-English pages serving English body content under foreign `hreflang`.

**Target:**

| Locale | Treatment | Basis |
|---|---|---|
| `en` | Full — the source of truth | Default |
| `ru` | ⚠ **REVISED — hygiene fix only, no content programme** (`STRATEGY-STRESS-TEST.md` Refutation 3 refuted the Russian opportunity at high confidence). Optionally one natively-translated page as a bounded test — `SEO-EXPERIMENT-BACKLOG.md` EX-06 | `[OBSERVED]` Russia is Georgia's #1 source market (1.61M arrivals 2025, 21.8% of Q4). The Russian 4×4 SERP returned eight local operators with no OTA above them |
| `ka` | UI + city pages only | `[OBSERVED]` The Georgian SERP is owned by classifieds (manqanebi.ge, servisebi.ge); no rental homepage ranked. Keep for local credibility and GBP consistency |
| `uk` | UI only | `[OBSERVED]` Collapses into Russian results |
| `ar`, `zh`, `fr` | UI only, no content `hreflang` | `[OBSERVED]` Zero `.ge` penetration in all three |

`[INFERENCE]` **Narrow before you widen.** Two languages done properly beat seven done partially — and
seven partial locales currently multiply the thin-content problem sevenfold.

---

## Click depth targets

| Page type | Max depth | Route |
|---|---|---|
| City pages | 2 | Home → `/car-rental/` → city |
| `/where-you-can-drive/` | **1** | Home → flagship |
| Destination permission pages | 2 | Flagship → destination |
| `/fleet/4x4-suv/` | 2 | Home → `/fleet/` → category |
| Vehicle pages | 3 | Home → `/fleet/` → category → vehicle |
| Trust pages | 2 | Home → `/trust/` → page |
| Guides | 2 | Home → `/guides/` → guide |
| Place pages | 3 | Home → `/places/` → region → place |

`[OBSERVED]` Nothing currently exceeds depth 3 via the footer, which links every city page site-wide.
`[INFERENCE]` The footer is carrying architectural load that the navigation should carry — see
`INTERNAL-LINK-GRAPH.md`.

---

## Migration safety

`[OBSERVED]` The site is roughly a month old and has near-zero visibility, which makes this the
**cheapest moment it will ever have** to restructure. That is a genuine argument for acting now rather
than later. Even so:

1. **No existing URL is renamed** by this plan — only added, merged and repurposed
2. Every merge is a **301 to the survivor**, never a delete
3. `/tusheti-4x4-rental-guide/` is the only content URL retired, and it redirects
4. Run a crawl **before** and **after** (`SEO-BASELINE.md` Part 3, item 6) and diff
5. Sequence: **P0 defects → trust pages → flagship → categories → repurpose → Russian**

---

## Honest uncertainties

- `/fleet/long-term/` is a guess without booking-duration data
- Whether individual vehicle pages should be indexed at all is an **experiment**, not a conclusion
- Whether `/car-rental/` should be a hub or a redirect to `/fleet/` depends on how the four city pages
  actually perform, which needs GSC
- Splitting `/where-you-can-drive/` into three destination children assumes each has enough distinct
  demand to justify a URL — `[INFERENCE]` supported by SERP evidence, unconfirmed by volume data
- The `ar`/`zh`/`fr` recommendation rests on US-locale search results and would be worth re-testing from
  the relevant regions before removing anything
