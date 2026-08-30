# SEO-IMPLEMENTATION-BACKLOG.md — Geolander

**Date:** 2026-08-14 · **No changes have been made to the site.** This is a plan awaiting authorisation.

**Priority:** P0 critical · P1 high · P2 medium · P3 opportunity
**Type:** QW quick win · SP strategic project · EX experiment · LT long-term authority

---

## P0 — Do these before anything else

### GL-001 · Fix the locale homepage redirect loop
**P0 · QW · Dev · ~1 hour**

- **Business rationale:** six of seven language homepages are unreachable. Non-English visitors — the
  largest inbound audience — hit an infinite redirect.
- **SEO rationale:** every page emits `hreflang` pointing at these six URLs, so the site hands Google
  six dead alternate targets from every URL it has.
- **Evidence `[OBSERVED]`:** `/ru/` and `/ka/` both return "Too many redirects", reproduced three times
  including once by an independent research agent. `/ru/car-rental-batumi/` and `/ru/fleet/` load fine.
- **Affected:** `/ka/ /ru/ /uk/ /ar/ /zh/ /fr/`
- **Implementation:** in `GLC_I18n::hooks()`, add
  `add_filter('redirect_canonical', fn($r) => is_front_page() ? false : $r);` — mirroring the guard
  `GLC_City` already applies to city singles. Root cause: `REQUEST_URI` is stripped to `/` while
  `home_url('/')` is filtered to `/ru/`; core's `redirect_canonical()` compares the two and loops.
- **Dependencies:** none · **Risk:** very low — one filter, scoped to the front page
- **Validation:** request all six locale homepages, expect `200`. Then `curl -H "Accept-Language: ru" /`
  and confirm the negotiated redirect terminates.
- **Rollback:** remove one line.

### GL-002 · Never publish a zero price
**P0 · QW · Dev · ~2 hours**

- **Business rationale:** the site tells customers and AI systems that a Jeep Wrangler rents for $0/day.
- **SEO rationale:** a `Product` offer with `lowPrice: 0` is a structured-data/visible-content mismatch
  of exactly the kind that attracts a manual action — and it is served to the AI crawlers `robots.txt`
  explicitly invites.
- **Evidence `[OBSERVED]`:** `/pricing.md` shows "$0/day" for Jeep Wrangler 2017 White Sport; a car page
  meta description references "$0/day"; `/fleet/` shows no prices at all.
- **Root cause `[OBSERVED]`:** `GLC_Pricing::rate_range()` falls back to `glc_price_from`, which is `0`
  for every vehicle imported by `import-fleet.php`.
- **Implementation:** (a) populate real seasonal pricing, **or** set `glc_available = false` and exclude
  unpriced cars from `/fleet/`, `/pricing.md`, `/llms.txt` and the sitemap; (b) guard
  `GLC_Schema::car()` to omit the `offers` node entirely when `lowPrice <= 0`; (c) same guard in
  `GLC_AI::pricing()` and `GLC_SEO::description()`.
- **Validation:** grep live `/pricing.md` for `$0`; Rich Results Test on a car page; confirm no meta
  description contains `$0`.
- **Rollback:** revert the guards; the data fix is additive.

### GL-003 · Deduplicate the fleet
**P0 · SP · Dev + Boris · ~1 day**

- **Business rationale:** `/fleet/` claims 19 vehicles; ~13–15 exist. The title tag says "19 **Real**
  4x4s" — a trust word attached to an inflated number.
- **Evidence `[OBSERVED]`:** `/ru/fleet/` title reads *"…19 Real 4x4s from $28/day"* (generated from
  `wp_count_posts`). `cars.json` has 15. `/llms.txt` says 15. `/pricing.md` lists 8. The
  `fleet-import/Mitsubishi Outlander 2018 Black/` and `.../2018 Gray/` folders contain **byte-identical
  images with identical timestamps**.
- **Implementation:** Boris confirms the physical fleet; merge duplicate posts; **301 the loser to the
  survivor** (never delete); correct `/llms.txt`; re-run `validate-schema.mjs`.
- **Risk:** medium — post merges are destructive. **Export the `car` post table first.**
- **Validation:** `/fleet/`, `/llms.txt`, `/pricing.md` and the forecourt all agree.

### GL-004 · Verify the Google Business Profile
**P0 · QW · **Boris** · ~30 minutes**

- **Rationale:** `[INFERENCE]` plausibly the highest-ROI 30 minutes in the whole engagement, and the one
  asset that could not be inspected at all.
- **Implementation:** record primary + secondary categories, review count and the dates of the last ten
  reviews, photo count, whether the website field points to `geo-lander.com`, verification status,
  service-area config, and whether any duplicate listings exist. **If the primary category is not
  "Car rental agency", change it.**
- **Validation:** a screenshot of each.

### GL-005 · Observe the local pack from Tbilisi
**P0 · QW · **Boris** · ~15 minutes**

- **Rationale:** `[UNVERIFIED]` — the entire Phase 1 thesis rests on the local pack, and it was **never
  observed**. The tool available is US-locale.
- **Implementation:** from a Tbilisi browser or Georgia VPN, search `car rental Tbilisi`,
  `rent a car Tbilisi airport`, `4x4 rental Tbilisi`, `аренда авто Тбилиси`,
  `მანქანის ქირაობა თბილისი`. Screenshot the local pack. **Record who is in it and their review counts.**

### GL-006 · Answer the route-permission question
**P0 · **Boris** · a business decision, not a task**

- **Rationale:** gates the flagship content. `[OBSERVED]` `/terms/` §8 currently prohibits off-road
  driving, which places Geolander in the same position as the competitors it could otherwise beat.
- **Needed:** for each of Tusheti/Abano, Omalo, Shatili/Khevsureti, Truso, Juta, Vashlovani,
  Mestia–Ushguli–Lentekhi, Goderdzi, Zekari, Sairme–Abastumani — permitted yes/no/conditional, which
  vehicles, and whether insurance follows. Plus: does Geolander fit GPS trackers?
- **Risk if guessed `[INFERENCE]`:** publishing a permission that cannot be honoured leads to a seized
  deposit and a one-star review — destroying the exact trust asset the rest of the plan builds.

### GL-007 · Provide the four trust numbers
**P0 · **Boris** · ~15 minutes**

Deposit amount and mechanism · insurance excess · third-party liability limit · winter-tyre policy
(fitted as standard, at no charge, or not).

---

## P1 — High

### GL-008 · Reconcile the terms with the sales claims
**P1 · QW · Boris + content · ~half a day · zero ranking risk**

`[OBSERVED]` Homepage and `/llms.txt` say *"full insurance included"*; `/terms/` §2 says *"Basic
insurance (CDW) … with a deductible. Additional full coverage available for an extra fee."* §8 prohibits
off-road driving on a 4×4 adventure site. The deposit amount appears nowhere.
`[CUSTOMER]` *"full insurance"* is the exact phrase travellers say betrayed them.

### GL-009 · Single source of truth for the price floor
**P1 · QW · Dev · ~1 hour**

`[OBSERVED]` `$26` in one place, `$28` in another. Derive the displayed floor from the lowest *live
priced* vehicle and let copy, title tag, schema `priceRange` and `/llms.txt` all read from it.

### GL-010 · Publish prices on `/fleet/`
**P1 · QW · Dev · ~2 hours**

`[OBSERVED]` The fleet archive shows no prices while its own title promises "from $28/day". This is the
price-shopper's landing page answering the price question with silence. Depends on GL-002.

### GL-011 · Trustpilot + TripAdvisor + geotravelmarket listings
**P1 · QW · Boris · ~2 hours total**

`[OBSERVED]` Nine Georgian competitors hold Trustpilot profiles; Geolander holds none. Trustpilot is
self-serve. `[OBSERVED]` geotravelmarket.com openly solicits vendors and its 4×4 category has two
entries. TripAdvisor: **business listing only** — forum self-promotion is deleted.

### GL-012 · Post-rental review request
**P1 · SP · Boris · ongoing, ~2 minutes per rental**

`[INFERENCE]` The most compounding action available and entirely under Geolander's control. Send the
review link over the WhatsApp thread the customer is already in. Ask for specifics —
*"it helps if you mention where you drove and how the car handled it"* — because reviews naming Kazbegi
and Svaneti build the topical association the whole strategy depends on. **Never gate, never incentivise,
never fabricate.** Target `[ESTIMATE]`: ~20 genuine detailed reviews ≈ one month of ordinary rentals.

### GL-013 · GBP photographs
**P1 · QW · Boris · ~2 hours**

`[OBSERVED]` High-resolution originals already exist in `_migration/fleet-import/`. Upload every vehicle,
the office at 8/5 Vedzini Street, the street exterior, and cars at TBS arrivals. `[INFERENCE]` Vehicles
photographed in Georgian mountain settings do double duty — GBP content *and* the proof asset the site
lacks.

### GL-014 · Rebuild the primary navigation
**P1 · SP · Dev · ~half a day**

`[OBSERVED]` Three of seven nav slots go to tourist content (Places, Travel Info, Georgian Music) and
**none** goes to a page that makes money. Proposed:
`Fleet · Where you can drive · Locations · Guides · Trust · Contact`.
`[INFERENCE]` Moves the four city pages and the flagship from footer-only support into primary nav.

### GL-015 · Per-vehicle honesty pages
**P1 · SP · Boris + content · ~2 days**

`[OBSERVED]` 107 condition complaints vs 13 deposit disputes (8:1) in one 1,453-review set.
`[CUSTOMER]` *"Car was high mileage with lots of scratches but this was all declared on the paperwork at
collection — I felt a lot happier."* Publish per vehicle: year, odometer, last service date, tyre age,
ground clearance in mm, real consumption, dated photos **including existing damage**, permission tier.
**Fixes the thin-content defect and the conversion gap in one pass.**

---

## P2 — Medium

| ID | Task | Type | Notes |
|---|---|---|---|
| GL-016 | **`/where-you-can-drive/`** — the flagship reference page | SP | **Blocked on GL-006.** `[OBSERVED]` No rental company currently answers this |
| GL-017 | `/trust/deposit-policy/` | SP | Blocked on GL-007. Include the WhatsApp photo/video handover protocol |
| GL-018 | `/trust/what-our-insurance-covers/` | SP | Blocked on GL-007. `[OBSERVED]` Localrent's terms are robots-blocked — the query is uncontested |
| GL-019 | `/trust/airport-pickup/` — the arrival protocol | SP | `[OBSERVED]` Two multi-hour airport waits recorded at TBS from international brands |
| GL-020 | `/car-rental-kazbegi/` | SP | `[OBSERVED]` **Localrent's Stepantsminda page is blank.** Best uncontested URL found |
| GL-021 | `/fleet/4x4-suv/` category page | SP | `[OBSERVED]` **Localrent has no 4×4 page for Georgia at all** |
| GL-022 | Add visible breadcrumb UI | QW | `[FIRST-PARTY]` Schema exists without the visible element it describes |
| GL-023 | Resolve `car_brand` / `car_body_type` / **`place_region`** archives | QW | `[OBSERVED]` All three are `public: true` and in the sitemap; near-duplicates. `place_region` alone adds ~42 URLs across locales. Content or `noindex` |
| GL-024 | Link the three mountain guides to each other | QW | `[OBSERVED]` Currently three silos |
| GL-025 | `/guides/mountain-road-opening-calendar/` | LT | The strongest linkable asset. `[CUSTOMER]` *"open only two months"* — nobody says which two |
| GL-026 | Repurpose ~36 place pages to driving pages | SP | No new URLs. `[OBSERVED]` carrentdeme.com proves the framing ranks |
| GL-027 | Business `image` → real fleet photo; resize the 699 KB logo | QW | `[OBSERVED]` |
| GL-028 | Add the four delivery cities to `areaServed` | QW | One line |
| GL-029 | Airport entities with IATA codes on city pages | QW | `[OBSERVED]` `glc_airport_code` is already stored and unused |
| GL-030 | Contact form + embedded map + review proof on `/contact/` | QW | `[OBSERVED]` The page has none of the three |
| GL-031 | Convert place images to WebP | QW | `[OBSERVED]` Several 700–920 KB |
| GL-032 | Fix the hard-coded "36 Destinations" title | QW | `[OBSERVED]` Will silently become false |
| GL-033 | Buy one locale-correct SERP capture | QW | **`gl=ge`, `uule`=Tbilisi, ~20 queries, full SERP.** `[OBSERVED]` Nobody has seen a real google.ge result |
| GL-034 | Schema regression guard in `validate-schema.mjs` | QW | Fail the build if any published `car` has `lowPrice <= 0`. `[INFERENCE]` This one assertion would have prevented GL-002 |

---

## P3 — Opportunity and experiment

| ID | Task | Type |
|---|---|---|
| GL-035 | `/guides/rent-a-car-or-hire-a-driver/` — concede where a driver wins, name GoTrip | SP |
| GL-036 | `/guides/driving-in-georgia/` — the honest reality piece. **No crime content** | SP |
| GL-037 | `/guides/driving-to-armenia/` — `[OBSERVED]` capability exists in `/terms/` §6 | SP |
| GL-038 | `/guides/driving-in-georgia-in-winter/` — counter-seasonal | SP |
| GL-039 | ountravela outreach, leading with the permission table | LT |
| GL-040 | wander-lush approach, leading with the road calendar | LT |
| GL-041 | Localrent listing decision — **name the paved-roads tension openly** | — |
| GL-042 | `noindex, follow` on individual vehicle pages once categories exist | **EX** |
| GL-043 | `/music/` keep / repurpose / remove — decide on GSC evidence | **EX** |
| GL-044 | Drop the Accept-Language auto-redirect; make `/` edge-cacheable | **EX** |
| GL-045 | `/fleet/long-term/` — **only if** booking data shows long rentals matter | **EX** |
| GL-046 | Off-road insurance tier as a product | — | `[OBSERVED]` FSTA sells €33/day off-road vs €9/day standard. **A commercial decision worth more than any page** |

---

## Explicitly not doing

Buying links · PBNs · guest-post farms · mass directory submission (`[OBSERVED]` the directories that
matter are GBP-derived and cannot be submitted to) · TripAdvisor or Reddit astroturfing · fake or gated
reviews · doorway city/route pages · combinatorial pickup×destination pages · a Russian content
programme (`[OBSERVED]` refuted at high confidence) · Arabic/Chinese/French content · FAQ schema
expansion (`[FIRST-PARTY]` FAQ rich results ended 7 May 2026) · `aggregateRating` on Geolander's own
business (`[FIRST-PARTY]` against Google's stated guidance) · chasing head terms · renaming existing URLs.

---

## Execution order

**Week 1:** GL-001 → GL-002 → GL-004 → GL-005 → GL-006 → GL-007 → GL-033
**Weeks 1–2:** GL-003, GL-008, GL-009, GL-010, GL-011, GL-012 (starts, never stops), GL-013
**Weeks 2–4:** GL-014, GL-034, GL-022, GL-023, GL-027–GL-032
**Weeks 3–8:** GL-015, GL-017, GL-018, GL-019, then GL-016 once GL-006 lands
**Weeks 8–16:** GL-020, GL-021, GL-024, GL-025, GL-026, GL-035–GL-038
**Month 3+:** GL-039, GL-040, GL-041, and the experiments

**Nothing after week 2 should be executed before the GSC export and the SERP capture exist.**
`[INFERENCE]` The plan is built on inference where measurement was unavailable, and it should be
re-prioritised the moment measurement arrives.
