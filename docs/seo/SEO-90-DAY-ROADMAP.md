# SEO-90-DAY-ROADMAP.md — Geolander

**Start:** on authorisation · **Ticket IDs** refer to `SEO-IMPLEMENTATION-BACKLOG.md`

**Owner key:** **B** = Boris (business decision or account access) · **D** = developer ·
**C** = content · **—** = either

---

## Week 1 — Unblock

**Nothing else in this roadmap starts until this week is done.** Four of the seven items are Boris-only,
and they gate roughly half the remaining ninety days.

| # | Task | Owner | Effort |
|---|---|---|---|
| GL-001 | **Fix the locale-homepage redirect loop** — one filter | D | 1 h |
| GL-002 | **Stop publishing `$0` prices** — schema, `/pricing.md`, meta descriptions | D | 2 h |
| GL-004 | **Audit the Google Business Profile** — category first | **B** | 30 min |
| GL-005 | **Screenshot the local pack from Tbilisi** — 5 queries | **B** | 15 min |
| GL-006 | **Decide the route-permission policy** | **B** | thinking time |
| GL-007 | **Supply four numbers** — deposit, excess, liability limit, winter tyres | **B** | 15 min |
| GL-033 | Buy one locale-correct SERP capture (`gl=ge`, `uule`=Tbilisi) | — | 1 h |
| — | **Export GSC + GA4** per `SEO-BASELINE.md` Part 3 | **B** | 35 min |

**Exit test:** all six locale homepages return `200` · no `$0` anywhere · the GBP category reads
"Car rental agency" · a real Georgian SERP has been seen for the first time in this engagement.

---

## Weeks 2–4 — Become findable and verifiable

`[INFERENCE]` The highest expected value per hour in the whole plan. No developer time on most of it,
no ranking risk on any of it.

| # | Task | Owner |
|---|---|---|
| GL-012 | **Post-rental review request — every rental, starting now. This never stops.** | **B** |
| GL-013 | Upload real fleet + premises photos to GBP (`[OBSERVED]` originals already in the repo) | **B** |
| GL-011 | Claim Trustpilot · create the TripAdvisor **listing** · register on geotravelmarket | **B** |
| GL-003 | Deduplicate the fleet — 301, never delete | D + **B** |
| GL-008 | **Rewrite `/terms/`** so it stops contradicting the homepage on insurance | **B** + C |
| GL-009 | Single source of truth for the price floor | D |
| GL-010 | Publish prices on `/fleet/` | D |
| GL-034 | Schema regression guard — fail the build on `lowPrice <= 0` | D |

**Exit test:** fleet counts agree across `/fleet/`, `/llms.txt` and `/pricing.md` · first genuine reviews
landing · terms no longer self-contradicting · Trustpilot and TripAdvisor live.

---

## Weeks 3–8 — Publish the proof

Gated on GL-006 and GL-007.

| # | Task | Owner |
|---|---|---|
| GL-017 | `/trust/deposit-policy/` — amount, mechanism, release date, and the **enumerated** list of the only reasons money is kept. Include the WhatsApp photo/video handover protocol | C |
| GL-018 | `/trust/what-our-insurance-covers/` — excess, third-party limit, exclusions, accident procedure | C |
| GL-015 | **Per-vehicle honesty pages ×~14** — odometer, service date, tyre age, clearance, dated photos including existing damage | **B** + C |
| GL-019 | `/trust/airport-pickup/` — the arrival protocol | C |
| GL-014 | **Rebuild the primary navigation** | D |
| GL-016 | **`/where-you-can-drive/`** — the flagship | C |
| GL-022 | Visible breadcrumb UI | D |
| GL-027–032 | Schema, image and metadata fixes | D |

**Exit test:** a visitor can find, in under thirty seconds, the deposit amount, what the insurance
actually covers, and where they are allowed to drive.

---

## Weeks 8–13 — Occupy the uncontested pages

| # | Task | Owner |
|---|---|---|
| GL-020 | **`/car-rental-kazbegi/`** — `[OBSERVED]` Localrent's Stepantsminda page is **blank** | C |
| GL-021 | **`/fleet/4x4-suv/`** — `[OBSERVED]` Localrent has **no 4×4 page for Georgia at all** | C + D |
| GL-025 | `/guides/mountain-road-opening-calendar/` — the linkable asset | C + **B** |
| GL-026 | Repurpose ~36 place pages to driving pages — **no new URLs** | C |
| GL-024 | Link the three mountain guides to each other | C |
| GL-023 | Resolve the taxonomy archives — content or `noindex` | D |
| GL-039 | **ountravela outreach**, leading with the permission table | **B** |

**Exit test:** first editorial placement in progress · GSC showing impressions on the permission and
trust clusters · non-brand impressions measurably above the week-1 baseline.

---

## The 90-day objectives

**Deliberately expressed as outputs and leading indicators, not traffic.** `[INFERENCE]` With fifteen
cars, traffic beyond peak utilisation has zero marginal value, and a traffic target would misdirect the
programme.

| Objective | Measured by |
|---|---|
| All P0 defects closed | Backlog |
| GBP complete, verified, correctly categorised, photographed | GBP |
| **Review requests sent after 100% of rentals** | The rental log |
| **~20 genuine, recent, detailed reviews** `[ESTIMATE]` | GBP + Trustpilot |
| Listed on Trustpilot, TripAdvisor, geotravelmarket | Manual |
| Terms consistent with sales claims | Manual |
| Deposit and insurance published as numbers | Manual |
| ~14 vehicle pages with genuine per-unit content | Manual |
| Permission page live, **or a documented decision not to publish one** | Manual |
| Kazbegi + 4×4 category pages live | Manual |
| One editorial placement secured or in progress | Manual |
| **A real T0 baseline exists** | GSC export |
| Non-brand impressions rising | GSC |
| **First organic booking attributable** | The manual log |

---

## What will NOT happen in 90 days

Said plainly to prevent disappointment:

- **No head-term rankings.** `[OBSERVED]` *"rent a car tbilisi"* returned seven results with zero
  Georgian operators. Not a 90-day target — not a target at all
- **No meaningful backlink profile.** `[INFERENCE]` Two or three placements at most, and rightly so
- **No Russian content programme.** `[OBSERVED]` Refuted at high confidence
- **Probably no local-pack dominance.** `[UNVERIFIED]` — the pack has never been observed
- **No statistically significant experiment results.** `[INFERENCE]` The volume does not support them

`[INFERENCE]` **What should happen in 90 days is that Geolander becomes a verifiable business with a
review corpus, a consistent story, and answers to the questions this market actually asks.** Rankings
are a second-order consequence of that, and they take longer than a quarter.
