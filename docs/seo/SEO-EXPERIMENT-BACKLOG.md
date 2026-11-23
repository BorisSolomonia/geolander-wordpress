# SEO-EXPERIMENT-BACKLOG.md — Geolander

**Date:** 2026-08-14

**Why this file exists:** several conclusions in this engagement are inferences, not measurements. Where
uncertainty is high and the cost of being wrong is real, the honest response is an experiment rather
than a recommendation.

**A structural warning `[INFERENCE]`:** with fifteen cars and a small booking volume, **most SEO
experiments here will not reach statistical significance.** Monthly booking counts will be small enough
that normal variance swamps the effect being measured. Treat every result below as **directional
evidence**, use 3-month rolling windows, and be very reluctant to declare a winner from a single month.
Where an experiment cannot be measured cleanly, that is stated.

---

## EX-01 · Should individual vehicle pages be indexed at all?

- **Hypothesis:** ~19 near-duplicate vehicle pages dilute the site's quality signal. `noindex, follow`
  on individual vehicles, once category pages exist, will improve category-page performance without
  losing conversions — because visitors reach vehicle pages from the category, not from search.
- **Evidence for `[OBSERVED]`:** ~85 unique words per page; per-model demand is negligible;
  `[INFERENCE]` ~133 URLs across locales is ~27% of the crawlable surface.
- **Evidence against `[OBSERVED]`:** competitors *do* rank single-model pages —
  `gsscarrental.com/jeep-wrangler-rental-tbilisi/` ranks on ~600 words with no FAQ, no reviews and no
  schema. And GL-015 will make these pages genuinely unique.
- **Do this only after GL-015.** `[INFERENCE]` Testing `noindex` on thin pages tests thinness, not the
  architecture question.
- **URLs:** half the fleet `noindex, follow`, half indexed · **Period:** 12 weeks
- **Metric:** impressions and clicks on `/fleet/4x4-suv/` and `/fleet/`; total non-brand impressions
- **Confounders:** seasonality, concurrent content work. **Do not run alongside GL-021**
- **Honest assessment:** `[INFERENCE]` likely underpowered. May only yield a directional read

---

## EX-02 · Does the Accept-Language auto-redirect help or hurt?

- **Hypothesis:** removing it improves TTFB and LCP on `/` (making it edge-cacheable), removes a
  Google-guidance risk, and does not reduce non-English conversion — because `[OBSERVED]` non-English
  pages currently serve English body content anyway.
- **Evidence `[OBSERVED]`:** `GLC_Perf` sends `Cache-Control: private, no-cache` on the front page
  *specifically* to preserve this redirect, so the most important URL on the site can never be
  edge-cached. `[FIRST-PARTY]` Google advises against automatic language redirection.
- **Change:** serve `/` as English to everyone; keep the switcher; make `/` cacheable
- **Period:** 8 weeks · **Metric:** LCP and TTFB on `/`; bounce rate by language; booking requests by
  language
- **Run after GL-001.** `[INFERENCE]` The redirect currently sends non-English visitors into a loop, so
  measuring it before the fix would measure the bug
- **Reversibility:** high — one code path

---

## EX-03 · Does the permission page actually produce bookings?

- **Hypothesis:** `/where-you-can-drive/` produces qualified enquiries at a materially higher rate than
  any other page.
- **Evidence `[OBSERVED]`:** no rental company answers this question; `[CUSTOMER]` a lost booking is on
  record — *"The owner refused to let us drive that route, so we canceled and booked a Mitsubishi
  Outlander from Martyna z Gruzji instead."*
- **Counter-evidence `[OBSERVED]`:** the adversarial review found Tusheti National Park has **70**
  TripAdvisor reviews against Gergeti's **1,283**. **The segment may simply be small**
- **Metric:** WhatsApp requests originating on this page; % mentioning a restricted route; conversion
  rate vs the site average
- **Period:** one full season (June–October) — `[OBSERVED]` the Abano Pass is open ~4 months
- **Kill criterion:** fewer than 5 qualified enquiries across a full season → the segment is too small to
  organise a strategy around, and the page is downgraded to a supporting FAQ
- **This is the most important experiment in the list.** It tests the differentiator the Golden Path
  leans on hardest

---

## EX-04 · Does pre-declared vehicle damage help or hurt conversion?

- **Hypothesis:** publishing existing scratches and real odometer readings **increases** enquiries,
  against the intuition that it would reduce them.
- **Evidence `[CUSTOMER]`:** *"Car was high mileage with lots of scratches but this was all declared on
  the paperwork at collection — considering the state of some roads I felt a lot happier."*
- **Design:** roll out to half the fleet first · **Period:** 8 weeks
- **Metric:** enquiries per vehicle page view; and — more importantly — **damage disputes at return**
- **`[INFERENCE]` Note:** even if enquiries fall slightly, a reduction in disputes may be worth more.
  **Measure both, and weigh them commercially, not just by conversion rate**

---

## EX-05 · Does `/music/` earn its place?

- **Hypothesis:** the Georgian music page attracts no relevant impressions and dilutes topical focus.
- **Counter-hypothesis `[INFERENCE]`:** road-trip playlists are a genuine traveller need and this is
  unusual enough to attract links — which is the opposite conclusion
- **Method:** GSC impressions and clicks over 90 days. **Decide on evidence, not on reflex**
- **Outcomes:** meaningful impressions → keep and improve · negligible but linked → keep, deprioritise ·
  negligible and unlinked → repurpose as a road-trip playlist guide, or remove

---

## EX-06 · Native Russian — a *bounded* test, not a programme

- **Context `[OBSERVED]`:** the adversarial review **refuted the Russian opportunity at high
  confidence.** The layer is densely served, the permission wedge is already owned in Russian, and
  discovery is affiliate-gated
- **But `[OBSERVED]`:** the RU SERPs are **not** OTA-locked — no Sixt, Hertz, Avis, Europcar, Booking,
  Expedia or Kayak appeared on any of six Russian queries. Structurally more open than English
- **Bounded test:** translate **one** page natively — `/ru/trust/deposit-policy/`, chosen because it is
  policy content an aggregator cannot match. **Not a content programme. One page.**
- **Period:** 12 weeks · **Metric:** Russian-language impressions; enquiries in Russian
- **Cost:** one professional translation. `[OBSERVED]` Machine translation will not compete — incumbents
  write colloquial Russian
- **Prerequisite:** GL-001. `[OBSERVED]` `/ru/` currently returns "Too many redirects"

---

## EX-07 · Does the arrival protocol reduce airport friction complaints?

- **Hypothesis:** publishing flight tracking, a named driver with photo, and the exact TBS meeting point
  increases airport-arrival enquiries and reduces first-contact anxiety
- **Evidence `[OBSERVED]`:** two multi-hour airport waits recorded at TBS from international brands; and
  the most actionable positive in the dataset was a five-star review where **the differentiator was that
  the rep sent a photo of his location**
- **Metric:** enquiries citing airport pickup; WhatsApp messages asking "where do we meet?" (should fall)

---

## EX-08 · Category page vs vehicle pages for 4×4 intent

- **Hypothesis:** `/fleet/4x4-suv/` will out-rank and out-convert any individual vehicle page for 4×4
  and SUV queries
- **Evidence `[OBSERVED]`:** **Localrent has no 4×4 page for Georgia at all**; `4x4carrental.ge` ranks
  on the term with lorem ipsum text
- **Metric:** impressions and clicks for 4×4/SUV queries, by landing page
- **Feeds directly into EX-01**

---

## Experiments deliberately NOT run

| Not testing | Why |
|---|---|
| Whether buying links works | `[INFERENCE]` Against Google's policies. The downside is the domain |
| Whether fake or gated reviews work | Risks the GBP — the most valuable asset identified |
| Whether doorway city pages work | Forbidden by the brief, and `[OBSERVED]` those SERPs are worthless anyway |
| Whether "you need a 4×4" messaging works | `[OBSERVED]` Already refuted, by the most authoritative sources in the market. Testing it would waste a season |
| Multivariate on-page tests | `[INFERENCE]` Volume far too low. Would produce noise dressed as insight |

---

## How to run these honestly

1. **One variable at a time.** `[INFERENCE]` Tempting to ship everything at once on a site this small —
   and it would make every result uninterpretable
2. **Write the hypothesis and the kill criterion *before* starting.** Post-hoc rationalisation is the
   default failure mode of SEO testing
3. **Log the confounders** — season, concurrent work, competitor moves, algorithm updates
4. **Accept directional results.** With this volume, "probably helped" is often the strongest honest
   conclusion available, and pretending otherwise is worse than admitting it
5. **Record what was learned, including when nothing was.** A null result that prevents six months of
   wasted effort is a good outcome
