# INTERNAL-LINK-GRAPH.md — Geolander

**Date:** 2026-08-14

**Hard caveat up front:** this is built from **live page fetches and source code**, not from a crawl.
`/wp-sitemap.xml` is unparseable to the available fetch tool and this sandbox blocks direct HTTP clients.
**Orphan pages, true click depth, and the full link graph are therefore unknown.** A 500-URL Screaming
Frog run resolves this in ~30 minutes and should precede any execution.

---

## The current graph, as observed

Reconstructed from fetches of `/`, `/fleet/`, a car page, both major city pages, `/travel-info/`,
`/contact/` and a route guide.

```
Header nav (site-wide, 7 links)
  Home · Our Fleet · Places to Visit · Travel Info · Georgian Music · Terms · Contact

Footer (site-wide)
  Quick links · Contact info · Follow us
  "We deliver to": car-rental-tbilisi · -batumi · -kutaisi · -kobuleti

/fleet/  →  19 vehicle pages
/car-rental-{city}/  →  ~6 vehicle pages + "View all" → /fleet/
/driving-to-kazbegi-in-winter/  →  2 named vehicles + /fleet/ + 4 city pages
Vehicle page  →  14 links total (nav + breadcrumb + footer)
```

### What is right about it `[OBSERVED]`

- Every page reaches every city page via the footer — **nothing is orphaned at depth**
- City pages link **down to specific vehicles**, not just to `/fleet/` — good, contextual, and it passes
  relevance rather than just equity
- The Kazbegi guide links to the **two specific vehicles it recommends** (Forester 2020, Wrangler 2017)
  — this is textbook, and it is already working
- Breadcrumb schema exists on car, place, city and guide templates

### What is wrong with it

| Problem | Evidence | Consequence |
|---|---|---|
| **The footer is carrying the architecture** | `[OBSERVED]` City pages are reachable *only* via the footer and a few in-body links | `[INFERENCE]` Site-wide footer links are the weakest kind of internal link. The four highest-commercial-intent pages on the site are being supported by the weakest link type available |
| **No breadcrumb UI** | `[OBSERVED]` `BreadcrumbList` schema is emitted with no visible breadcrumb | `[FIRST-PARTY]` Markup should correspond to visible content. Also loses a real navigational link row |
| **City pages don't link to each other** | `[OBSERVED]` Only via the shared footer | `[INFERENCE]` No lateral relevance signal between the four commercial pages |
| **Guides don't link to guides** | `[OBSERVED]` The Kazbegi guide links to vehicles and cities, not to the Svaneti or Tusheti guides | `[INFERENCE]` Three related mountain guides sit in three silos, each failing to reinforce the others' topic |
| **~36 place pages appear to be a dead end** | `[OBSERVED]` `/places/` is in the nav; individual place pages were not fetched | `[UNVERIFIED]` — but if they don't link back to vehicles, cities or guides, that is ~36 pages of equity going nowhere |
| **19 vehicle pages, ~14 links each, nearly all boilerplate** | `[OBSERVED]` | `[INFERENCE]` The largest page group on the site contributes almost no unique link signal |
| **`/music/` and `/travel-info/` sit in top-level nav** | `[OBSERVED]` | `[INFERENCE]` Two of seven primary navigation slots — the most valuable internal links on the site — go to a music page and a fuel-station list, while the four city pages get none |
| **Taxonomy archives are unlinked but indexable** | `[OBSERVED]` `/brand/`, `/body-type/` are public and in the sitemap but appear in no navigation | `[INFERENCE]` Probable orphans-in-effect: crawlable via sitemap, unsupported by links |

---

## The single most consequential observation

`[OBSERVED]` The primary navigation is:

> Home · Our Fleet · **Places to Visit** · **Travel Info** · **Georgian Music** · Terms · Contact

`[INFERENCE]` Three of seven slots go to tourist content. **Zero go to the pages that make money** — the
city pages — and zero to the pages the research says decide the purchase: permission, deposit, insurance.

The navigation reflects the site's *original* concept (an adventure-immersive travel brand) rather than
the market's actual decision criteria. `[OBSERVED]` Travellers choose on permitted roads, deposit terms,
insurance detail and car condition. **None of those has a navigation slot.**

---

## Target link graph

```
Home
 ├─ nav → /car-rental/ (hub)      → 4 city pages          [NEW nav slot]
 ├─ nav → /fleet/                 → categories → vehicles
 ├─ nav → /where-you-can-drive/   → 3 destination pages   [NEW nav slot — flagship]
 ├─ nav → /guides/                → 8 guides              [NEW hub]
 ├─ nav → /trust/                 → deposit · insurance · airport · about  [NEW]
 └─ nav → /contact/

Hub-and-spoke, every cluster:
  hub → each child · each child → hub · children ↔ siblings

Cross-cluster (the links that actually matter):
  /where-you-can-drive/tusheti/  → the vehicles permitted there  → /fleet/4x4-suv/
  /do-you-need-a-4x4/            → /where-you-can-drive/  → /fleet/4x4-suv/
  /guides/{route}/               → the specific vehicles recommended  (already done well)
  /places/{place}/               → the guide covering that road → the vehicle class needed
  /car-rental-{city}/            → the other three cities · /where-you-can-drive/ · /trust/deposit-policy/
  Every vehicle page             → /where-you-can-drive/ (its permission tier) · its category
  /trust/*                       ↔ each other · → /fleet/
```

---

## Prioritised fixes

### P1 · Rebuild the primary navigation

`[INFERENCE]` The highest-leverage internal-linking change available, and it costs a template edit.

**Proposed:** `Fleet · Where you can drive · Locations · Guides · Trust · Contact`

Demote `Places to Visit`, `Travel Info` and `Georgian Music` into `/guides/` or the footer. **This alone
moves the four city pages and the flagship permission page from footer-only support to primary
navigation.**

### P1 · Add the visible breadcrumb UI

`[OBSERVED]` Schema exists; the visible element does not. Adding it satisfies `[FIRST-PARTY]` guidance,
gives every deep page a real path back to its hub, and is a small template change.

### P1 · Link the three mountain guides to each other

`[OBSERVED]` Kazbegi, Svaneti and Tusheti guides currently do not reference one another. `[INFERENCE]`
A reader planning a Caucasus road trip wants all three, and Google wants to see them as one topical
cluster rather than three unrelated posts.

### P2 · Give the place pages an outbound job

`[UNVERIFIED]` but likely. Each place page should link to: the guide covering its road, the vehicle class
its access track needs, and the nearest city page. `[INFERENCE]` This turns ~36 dead ends into a
relevance web feeding the money pages — and it is the same edit as the CREATE-11 repurpose in
`CONTENT-GAP.md`, so it costs nothing extra.

### P2 · Make vehicle pages contribute

Each vehicle links to its permission tier, its category, and the two or three destinations it genuinely
suits. `[INFERENCE]` Nineteen pages currently contributing only boilerplate become nineteen pages
reinforcing the flagship.

### P2 · City pages link laterally

Each city → the other three, plus `/where-you-can-drive/` and `/trust/deposit-policy/`.

### P3 · Resolve the taxonomy archives

Either give `/body-type/suv/` a navigation link and real content — making it the category page the
architecture needs — or `noindex` it. `[INFERENCE]` Indexable-but-unlinked is the worst of both.

---

## Anchor-text guidance

`[OBSERVED]` Two constraints specific to this site:

1. **Yokohama GEOLANDAR owns the bare brand.** Internal anchors should reinforce compound entity phrases
   where natural — *"Geolander car rental in Tbilisi"*, not *"Geolander"*.
2. **The unqualified word "Georgia" retrieves the US state** — `[OBSERVED]` 4 of 10 results on one query.
   Anchors should carry a disambiguating token: *"4×4 rental in Tbilisi"*, *"driving in the Caucasus"*,
   *"mountain roads in Georgia (country)"*.

Otherwise: descriptive, varied, natural. No exact-match stuffing — `[OBSERVED]` the "No Deposit, No
Credit Card" cluster demonstrates what over-optimisation looks like in this market, and it is not a
model to copy.

---

## What to verify with a crawl

1. Do the ~36 place pages link out at all, or are they dead ends?
2. Are the taxonomy archives genuinely orphaned?
3. Are the three route guides in the sitemap and internally linked from anywhere but the footer?
4. Are there redirect chains from the earlier React → WordPress migration?
5. What is the real click depth distribution across all ~490 URLs?
6. Are any of the 19 vehicle pages unreachable except via `/fleet/` pagination?
