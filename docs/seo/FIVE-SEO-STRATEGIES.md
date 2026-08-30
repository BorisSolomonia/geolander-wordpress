# FIVE-SEO-STRATEGIES.md — Geolander

**Date:** 2026-08-14 · Stress-tested in `STRATEGY-STRESS-TEST.md` · Synthesised in `GOLDEN-PATH.md`

---

## The opportunity model behind the scoring

The brief asks for a normalised score per opportunity. Building one honestly requires stating what the
inputs actually are.

**Inputs I have `[OBSERVED]`:** SERP composition across 7 languages · ~40 competitor pages read in full ·
64 customer-voice sources · the complete codebase · live site behaviour · named tourism-arrival data.

**Inputs I do not have:** search volume · keyword difficulty · any ranking data · any traffic data ·
any backlink data · **any business economics at all** — no margin, no utilisation, no duration mix, no
booking volume.

`[INFERENCE]` **That last gap is the binding one.** The brief rightly insists prioritisation should
follow gross profit. With a 15-car fleet, the economics are unusual and probably decisive:

- **Capacity is hard-capped.** Fifteen cars can be rented at most fifteen times concurrently. Traffic
  beyond full peak-season utilisation has **zero marginal value**. This alone disqualifies any
  volume-maximising strategy.
- **Duration tiers span 1–2 days to 31+ days**, with per-day rates falling roughly 30–40% across the
  range `[OBSERVED]` `cars.json`. `[INFERENCE]` Long rentals almost certainly carry better margin per
  unit of operational effort — one handover instead of ten — but this is an assumption, not a
  measurement, and it changes the ranking if wrong.
- **Advertised range $26–$120/day** `[OBSERVED — settings]`, but the **highest actual seasonal
  day-rate in `cars.json` is $90**; `$120` is a configured ceiling, not a vehicle. `[INFERENCE]` The
  Wrangler is plausibly the highest-margin unit *and* the one that unlocks the restricted-route product.

**Scores below are therefore explicitly modelled.** Each is justified in prose rather than presented as
a computed figure, because a computed figure from these inputs would be false precision.

---

## The five constraints every strategy must survive

Derived from the research, and non-negotiable regardless of which strategy is chosen:

1. **Near-zero domain authority and no history** `[OBSERVED]` — four visibility probes returned nothing
2. **Brand collides with Yokohama GEOLANDAR** `[OBSERVED]` — bare-brand search is not recoverable
3. **Head terms are locked by Sixt and the OTAs** `[OBSERVED]` — six queries, saturated
4. **Fixed capacity of ~15 vehicles** — traffic past utilisation is worthless
5. **Price is nearly absent from how customers choose** `[OBSERVED]` — they choose on permission,
   deposit, insurance and car condition

---

# Strategy A — Local Commercial Dominance

**Thesis:** stop trying to rank and start trying to be *found where the decision happens*. Own the
Tbilisi local pack, the Google Business Profile, the review layer, and the directory tier that is
generated from it.

**Mechanics:** GBP category correction, complete profile, real fleet photographs (the high-resolution
originals already exist in `_migration/fleet-import/` `[OBSERVED]`), a systematic post-rental review
request over the WhatsApp channel the customer is already in, Trustpilot and TripAdvisor listings,
citation consistency, then on-site local signals — third-party review proof, embedded map, contact form,
`areaServed` cities, airport IATA entities.

**Why it could win `[OBSERVED]`:** "car rental Tbilisi" is textbook local intent executed by people
physically arriving in Tbilisi. The local pack sits above organic. Geolander has a real address at
8/5 Vedzini Street, and its schema NAP already matches the GBP exactly. **And crucially — Wanderlog and
wheree.com auto-generate business pages from Google Maps data with no submission route**, so a populated
GBP mechanically manufactures an entire tier of third-party citations that both travellers and AI
systems retrieve.

**Why it might not:** `[UNVERIFIED]` — **the local pack was never observed.** The search tool is
US-locale and returned no map units on any query. Every claim here rests on inference. Proximity also
constrains local ranking, and `[OBSERVED]` five of the six businesses in one directory's Tbilisi list
sit in postcode 0105, the central Rustaveli/Shalva Dadiani cluster, while Geolander is in 0108.

**Modelled score: 78/100** — highest expected value per hour of effort, lowest risk, fastest payback.
Capped below 90 because it is unverified and because a 15-car fleet cannot absorb unlimited local demand.

---

# Strategy B — Transactional Landing-Page Dominance

**Thesis:** build the commercial page layer the site lacks — vehicle *categories*, city pages, airport
pages, duration pages — and win the mid-tail transactional SERPs.

**Mechanics:** deduplicate and rebuild the 19 vehicle pages; create category pages (`/4x4-rental-tbilisi/`,
`/suv-rental-georgia/`, `/7-seater-rental-tbilisi/`, `/automatic-car-rental-tbilisi/`); expand city
coverage; add airport pages; build long-term/monthly rental pages; internal-link everything to money pages.

**Why it could win `[OBSERVED]`:** the 4×4-qualified layer is genuinely soft. **4x4carrental.ge ranks on
the core commercial 4×4 term with unreplaced `Lorem ipsum` placeholder text, no prices, no booking
mechanism and a Gmail address.** rentcarsgeorgia.com ranks a 3,000-word mountain page **with no H1 at
all**. tbilisocarrental.ge ranks on ~1,100 words that never name a single destination. This is not a
defended SERP.

**Why it might not `[OBSERVED]`:** the same evidence shows **price is nearly absent from the
conversation** — travellers argue about permission, deposits and car age, not about which page ranks for
"SUV rental Tbilisi". `[INFERENCE]` Winning transactional pages captures people already searching
commercially; the research suggests the decision is made earlier, in forums and blogs. There is also
real doorway-page risk: the temptation to generate city × vehicle × duration combinations is exactly the
gttoursgeorgia pattern the brief forbids.

**Modelled score: 66/100** — necessary infrastructure, insufficient as a strategy. Much of its value is
already counted as defect remediation.

---

# Strategy C — Authority & Backlink Moat

**Thesis:** the constraint is authority; therefore acquire relevant referring domains through digital PR,
partnerships and linkable assets until the domain can compete.

**Mechanics:** road-opening calendar, permissions reference table, original driving data, outreach to
travel publishers, hotel and tour-operator partnerships, tourism-body relationships.

**Why it could win `[OBSERVED]`:** discovery in this market is **creator-mediated, not SERP-mediated**.
Emily Lush of wander-lush.org is simultaneously the top-ranking blog on the head term, the resident
expert on TripAdvisor's Georgia forum with 440+ posts, and the arbiter of which company travellers use.
`[INFERENCE]` One credible relationship there plausibly outperforms twenty landing pages.

**Why it might not — and this is the strategy's own evidence turning against it `[OBSERVED]`:**
**4x4carrental.ge ranks on the primary commercial 4×4 keyword with lorem ipsum text on the page.**
`[INFERENCE]` If link equity were the binding constraint in this market, that could not happen. My
working hypothesis is that **authority is not what is stopping Geolander** — absence and thin content
are. Pure authority-building is also the slowest path to revenue, needs sustained human effort Geolander
may not have, and `[OBSERVED]` the single highest-value target (wander-lush) already recommends an
aggregator and would need months of relationship-building.

**Modelled score: 58/100** as a standalone strategy — but several of its *components* score far higher
when embedded in another strategy. The assets it proposes are right; making them the organising
principle is wrong.

---

# Strategy D — Topical / Journey Capture

**Thesis:** own the *questions* travellers ask before they shop, and route them into commercial pages.
Compete on knowledge rather than on commercial keywords.

**Mechanics:** the route-permission table, "do you need a 4×4 — honest answer by destination", "rent vs
hire a driver", "driving in Georgia: what it's actually like", winter driving, the road-opening calendar,
cross-border to Armenia, and the repurposing of ~36 place pages from tourist descriptions into
**driving** pages.

**Why it could win — the strongest evidential case of the five:**

- `[OBSERVED]` The highest-intent question in the market — *may I drive a rental car to Tusheti?* — is
  currently answered by **an aggregator's journal, two travel blogs and a niche activity site. Not one
  rental company.**
- `[OBSERVED]` On the one page that ranks Georgian 4×4 agencies, the **decisive line in each entry is a
  permission statement, not a specification**: *"Driving in Tusheti and Vashlovani is authorized"* /
  *"prohibited"* / *"authorized only for Toyota 4runner and Tacoma"*. Customers shopping 4×4 in Georgia
  are shopping for **permission**.
- `[OBSERVED]` Competitors already prove the model works: starcar.ge ranked **twice on one SERP** with
  blog guides; rentcarsgeorgia.com broke into a head-term SERP on destination depth.
- `[INFERENCE]` It is the only strategy whose output is **structurally uncopyable** — permission is set
  by an operator's insurer, and first-hand road knowledge belongs to whoever drives the roads.

**Why it might not:**

- **`[OBSERVED]` The counter-evidence is real and must be respected.** wander-lush: *"For most routes in
  Georgia, a standard sedan is perfectly adequate"*; Abano Pass *"wider, smoother and faster"*;
  Mestia–Ushguli *"fully concreted as of 2024"*. Russian publishers are harsher. **The roads are getting
  easier, which erodes the premise over time.**
- **`[OBSERVED]` A competitor already publishes the argument against Geolander's own fleet:** starcar.ge
  states the Forester *"has no low-range gearbox for extreme terrain"*. Seven of 15 cars are Foresters.
- **`[OBSERVED]` The whole strategy is blocked on a business decision.** `/terms/` §8 currently prohibits
  off-road driving. Without a policy change, the flagship page cannot be written honestly.
- Informational traffic converts more slowly, and with fixed capacity, volume is not the point.

**Modelled score: 84/100 — ⚠ SUPERSEDED. Revised to 64/100 and fourth place after adversarial review; see `STRATEGY-STRESS-TEST.md` Refutation 2.** As originally scored: highest ceiling, highest defensibility, **conditional on the permission
decision**. Without it, this strategy loses roughly a third of its value and drops to the low 60s.

---

# Strategy E — Technical + UX + Conversion Efficiency

**Thesis:** extract far more from existing demand through technical excellence, speed, architecture,
schema, and conversion improvement.

**Mechanics:** fix the redirect loop, kill the `$0` prices, deduplicate the fleet, resolve the terms
contradictions, publish prices on `/fleet/`, add the deposit and insurance pages, build the arrival
protocol, add conversion tracking, close the WhatsApp attribution hole.

**Why it could win `[OBSERVED]`:** the P0s are severe and live. Six of seven locale homepages are in a
redirect loop. Vehicles publish `$0/day` into the AI layer. The fleet count is wrong in three places.
The terms contradict the homepage on insurance and prohibit the driving the brand sells.

**Why it is not a strategy `[INFERENCE]`:** the technical foundation is **already good** — server-rendered,
clean robots, pruned sitemap, complete hreflang, hand-written schema, ~6 KB of JS. Most of the available
technical value has been captured. What remains is **defect remediation**, which is a prerequisite for
every other strategy rather than an alternative to them. And **efficiency multiplies traffic that does
not currently exist.** A 40% conversion-rate improvement on near-zero organic sessions is near-zero.

**Modelled score: 71/100** as *sequencing* — mandatory, urgent, first — but **35/100 as a growth
strategy**, because it has no acquisition mechanism.

---

## Comparison

| | **A** Local | **B** Landing pages | **C** Authority | **D** Topical | **E** Technical |
|---|---|---|---|---|---|
| Revenue potential (20%) | High | Medium | Medium-high | High | Low alone |
| Probability of success (15%) | High | Medium-high | Low-medium | Medium-high | Very high |
| Commercial intent captured (10%) | Very high | High | Low | Medium-high | n/a |
| Time to impact (10%) | **Weeks** | 2–4 months | 6–12 months | 3–6 months | **Days** |
| Defensibility (10%) | Medium | Low | High | **Very high** | Low |
| Authority building (10%) | Medium (via GBP→directories) | Low | **Very high** | High | None |
| Conversion potential (10%) | High | High | Low | Medium | **Very high** |
| Cost efficiency (5%) | **Very high** | Medium | Low | Medium | High |
| Feasibility (5%) | **Very high** | High | Low | Medium | High |
| Resilience to search change (5%) | High | Low | High | **Very high** | Medium |
| **Modelled total** | **78** | **66** | **58** | **84** | **71** seq / 35 growth |

---

## Why no single strategy wins outright

`[INFERENCE]` The scores cluster for a reason, and the reason is structural:

- **E must come first** — you cannot amplify a site whose Russian homepage does not load and whose cars
  advertise $0/day. But it generates no demand.
- **A pays fastest** and mechanically produces the citation tier that C would otherwise chase through
  outreach. But its ceiling is capped by fleet size and by proximity.
- **D has the highest ceiling and the only real moat** — but it is slower, and it is **blocked on a
  business decision Geolander has not yet made**.
- **B is infrastructure**, and most of its value is already counted inside E's remediation.
- **C's own best evidence undermines it** — a page ranking on the core commercial keyword with lorem
  ipsum text is proof that links are not the binding constraint here.

**The synthesis is in `GOLDEN-PATH.md`.** The short version: **E to unblock, A to earn now, D to win
later, C's assets embedded inside D, and B reduced to the handful of category pages that genuinely
have demand behind them.**

---

## The one input that would most change this ranking

`[INFERENCE]` **The answer to the route-permission question.**

- **If Geolander can genuinely permit and insure Tusheti, Omalo, Shatili, Juta, Truso and the Goderdzi
  and Ushguli roads** — Strategy D becomes decisively strongest, because it gains a differentiator no
  aggregator can copy (their insurers forbid it) and no international brand will match.
- **If it cannot** — D drops to roughly the low 60s, the 4×4 positioning is largely rhetorical, and the
  Golden Path tilts much harder toward A plus the trust-and-condition content in D, which stands on its
  own regardless.

**Second most valuable input:** actual gross margin by vehicle class and rental duration. `[INFERENCE]`
If long rentals are the profit engine, a whole cluster — monthly rental, digital nomads, relocation,
long-stay — becomes a first-class target that currently does not appear in any of the five strategies.
That would be a genuine miss, and it is a miss caused by missing data rather than by missing analysis.
