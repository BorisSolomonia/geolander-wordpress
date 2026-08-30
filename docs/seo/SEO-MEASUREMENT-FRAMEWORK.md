# SEO-MEASUREMENT-FRAMEWORK.md — Geolander

**Date:** 2026-08-14

---

## The problem that has to be solved first

`[OBSERVED]` The booking funnel is:

```
dates → live quote → checkout → booking_request post → WhatsApp deep link
```

**Everything after the WhatsApp handoff is invisible.** GA4 fires a `booking_request` event with the
quote total, and Google Ads fires a conversion with `transaction_id` = booking reference — but the
actual booking, the actual money, and the actual customer all happen in a channel with no analytics.

`[INFERENCE]` So even with perfect GA4 access, Geolander could count *booking requests* and never know
its request-to-booking conversion rate, its true organic revenue, or which landing pages produce
customers rather than enquiries. **That gap is not caused by missing tool access. It is structural, and
it must be closed manually.**

**The fix is a spreadsheet, and it should start this week.** For every WhatsApp booking request, record:

| Field | Why |
|---|---|
| Booking reference (`GL-XXXX`) | The join key — already generated `[OBSERVED]` |
| Date of request | Cohorting |
| Confirmed? Y/N | **The single most important number in this framework** |
| Vehicle | Margin analysis |
| Rental duration | `[OBSERVED]` The pricing engine tiers 1–2 to 31+ days; duration drives revenue |
| Total value | Revenue attribution |
| Customer language | Settles the Russian question with data instead of argument |
| **"How did you find us?"** — asked in the first WhatsApp reply | The only offline-attribution mechanism available |

`[INFERENCE]` That last question, asked consistently, is worth more than any analytics configuration on
this site. It is the only thing that connects an organic session to a paid rental.

---

## Layer A — Output KPIs (fully in our control)

Reported weekly. `[INFERENCE]` These are the leading edge — they move first, they are unambiguous, and
they are the only layer that cannot be blamed on Google.

| Metric | Source | Target |
|---|---|---|
| P0 tickets closed | Backlog | 7 of 7 in week 1 |
| Duplicate car records resolved | `wp_count_posts('car')` vs the forecourt | Counts agree |
| Vehicles with real unique content | Manual | 100% by week 8 |
| **Review requests sent** | The rental log | **100% of completed rentals** |
| Third-party listings live | Manual | GBP, Trustpilot, TripAdvisor, geotravelmarket by week 2 |
| GBP photos uploaded | GBP | Every vehicle + premises |
| Pages published | Manual | Per the roadmap |
| Outreach conversations opened | Manual | 2–3 by month 3 |

---

## Layer B — Leading SEO KPIs

Reported monthly. **Requires the GSC export in `SEO-BASELINE.md` Part 3.**

| Metric | Why it matters here |
|---|---|
| **Non-brand impressions** | The headline number. `[INFERENCE]` Brand impressions are contaminated by Yokohama GEOLANDAR anyway, so brand-inclusive totals are actively misleading for this business |
| **Non-brand clicks** | |
| Impressions on the permission cluster | Whether the flagship thesis is working |
| Impressions on the trust cluster | Deposit / insurance queries |
| CTR on commercial queries | Snippet quality; also catches the `$0` and price-mismatch problems |
| Queries in positions 1–3 / 4–10 / **11–20** | `[INFERENCE]` 11–20 is the striking-distance band and the most actionable |
| **Indexed strategic pages** | Target 100% of city, guide, permission and trust pages |
| Excluded pages **by reason** | Watch "Duplicate without user-selected canonical" and "Crawled – currently not indexed" — the expected symptoms of the thin vehicle pages |
| **GBP: views, clicks, calls, direction requests, messages** | Plausibly the highest-intent surface, and invisible to GSC |
| **Review count, rating, and velocity** | `[INFERENCE]` Velocity matters more than total — it is the metric most under direct control and the one competitors cannot copy |
| Local-pack position, 5 core queries, Tbilisi locale | Manual screenshot. `[UNVERIFIED]` today |
| Third-party properties mentioning "Geolander" + a disambiguating token | The entity metric |

**Deliberately excluded:** referring-domain count and domain authority. `[INFERENCE]` The brief forbids
optimising for DA, and the research found a page ranking on the core commercial term **with unreplaced
lorem ipsum text** — link count is not the binding constraint in this market.

---

## Layer C — Business KPIs

Reported monthly. **This is the layer that decides whether any of this was worth doing.**

| Metric | Source |
|---|---|
| Organic sessions → WhatsApp handoffs | GA4 event |
| Booking requests from organic | GA4 `booking_request` + landing-page dimension |
| **Confirmed bookings from organic** | **The manual log. There is no other way** |
| Request → confirmed conversion rate | The log |
| Organic revenue | The log |
| **Gross profit from organic customers** | The log + margin data Boris holds |
| Average rental duration, organic vs overall | The log |
| Repeat rate | The log |

**Traffic is deliberately absent.** `[INFERENCE]` With fifteen cars, traffic beyond peak utilisation has
**zero marginal value**. A traffic target would misdirect the entire programme toward volume the business
cannot serve — and would make a successful quarter look like a failed one, or vice versa.

---

## Attribution: search query → gross profit

```
Search query        →  GSC (query-level, non-brand filtered)
     ↓ landing page →  GA4 landing-page report, organic filter
     ↓ session      →  GA4
     ↓ WhatsApp     →  GA4 booking_request event  ← LAST AUTOMATED STEP
     ↓ booking      →  MANUAL LOG (booking reference is the join key)
     ↓ revenue      →  MANUAL LOG
     ↓ gross profit →  MANUAL LOG + margin data
```

### Limitations, stated plainly

1. **GSC and GA4 do not join.** GSC gives queries, GA4 gives behaviour; the link is the landing page,
   not the session. Query→booking attribution is **directional, never exact**.
2. **The WhatsApp step breaks automated attribution completely.** The `GL-XXXX` reference is the only
   bridge, and it works **only if someone records the outcome**.
3. **Brand vs non-brand is contaminated** by the Yokohama collision — filtering on "geolander" will
   catch tyre-adjacent queries that were never about this business.
4. **The local pack is not in GSC.** GBP Insights is a separate, non-joinable dataset.
5. **AI-search referrals are largely unmeasurable.** Some arrive with no referrer at all.
6. **Small numbers.** `[INFERENCE]` With a 15-car fleet, monthly booking counts will be small enough
   that normal variance swamps most changes. **Treat single-month movements as noise. Use 3-month rolling
   comparisons and year-on-year once history exists.**

---

## The operating cadence

### Weekly — 20 minutes
Ranking movement on the priority clusters · indexing errors in GSC · new reviews and responses ·
review requests actually sent · anything broken.

### Monthly — 60 minutes
Non-brand impressions and clicks · cluster performance · **striking-distance queries at positions
11–20** · GBP insights · review velocity · booking log vs previous month · **which assumptions turned
out wrong.**

### Quarterly — half a day
Re-run the competitor sweep · re-check the permission landscape (`[OBSERVED]` Localrent lifted its
Mestia–Ushguli ban in 2025 — **this market's rules change**) · re-run the SERP capture · recalculate
opportunity · reallocate.

---

## Opportunity alerts

| Trigger | Action |
|---|---|
| **Commercial query enters positions 4–20** | Improve that page first — cheapest available win |
| **High impressions, low CTR** | Title/description mismatch, or an intent mismatch. Check for `$0` and price inconsistencies |
| **High traffic, low conversion** | Page/business mismatch — probably an informational page with no conversion path |
| **High conversion, low traffic** | Increase internal links and promote it |
| **A competitor changes its permitted-routes policy** | `[OBSERVED]` This has already happened once. It directly affects the flagship differentiator |
| **New reviews mention a route or destination** | Free topical signal — link that review's subject to the matching page |
| **Review velocity drops below one per two weeks** | The process has lapsed. Fix immediately |
| **A GSC query appears that nobody targeted** | Real demand discovered. Consider a page |

---

## The three questions every monthly review must answer

1. **What changed, and why?**
2. **What did customers actually experience?** — read the new reviews and the WhatsApp threads, not just
   the dashboards
3. **Which assumption in this strategy turned out to be wrong?**

`[INFERENCE]` The third question is the important one. Three of this engagement's core claims were
refuted by adversarial review **before** implementation. More will be refuted by contact with reality.
A measurement framework that only tracks progress against the plan, and never tests the plan itself,
produces confident failure.
