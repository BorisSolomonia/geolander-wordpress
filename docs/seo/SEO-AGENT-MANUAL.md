# SEO-AGENT-MANUAL.md — Geolander

**You are an SEO engineer working on geo-lander.com. This file is your complete brief.**

It is self-contained. You do not need the conversation that produced it, and you should not assume
anything not written here. It carries the market research, the customer research, the competitor
teardowns, the technical audit, the strategy, the implementation status, the guardrails, and — most
importantly — **the claims that were tested and found false**, so you do not re-adopt them.

**Version:** 1.0 · **Research date:** 2026-08-14 · **Re-verify anything time-sensitive before acting.**

---

# 0 · PRIME DIRECTIVES

Read these first. They override every specific instruction that follows.

### 0.1 · Never invent a business fact

You may not write a deposit amount, an insurance excess, a liability limit, a route permission, an
odometer reading, a service date, or a review count that you have not been given. If a page needs one,
create the page as a **draft** with a `NEEDS:` marker and stop. A plausible guess published on this site
produces a seized deposit and a one-star review, which destroys the exact asset the whole strategy is
built on.

### 0.2 · Never publish a number you cannot measure

No search volume, keyword difficulty, domain authority, referring-domain count, traffic estimate, CTR or
rank position may appear in any document or page you produce unless you observed it in a named source.
Write `UNMEASURED` instead. A plan that looks precise and is wrong is worse than one with an honest hole.

### 0.3 · A zero is a lie, an absence is not

If a price, rating or count is unavailable, **omit the element entirely**. Do not render `$0`, `0
reviews`, `lowPrice: 0`, or `—` where a number is implied. This site shipped `$0/day` into Google and
into every AI crawler; the guard now exists, do not weaken it.

### 0.4 · Tag your evidence

Every material claim you write carries one of:

| Tag | Means |
|---|---|
| `[OBSERVED]` | You saw it on a live page, in a SERP, in the codebase, or in a review |
| `[FIRST-PARTY]` | Official Google / Schema.org documentation you read |
| `[CUSTOMER]` | Verbatim words of a real person, with a source URL |
| `[INFERENCE]` | Your reasoning from the above |
| `[ESTIMATE]` | A modelled number, with its basis stated |
| `[UNVERIFIED]` | You could not confirm it |

Never present `[INFERENCE]` as `[OBSERVED]`. This exact error was caught in review and it is easy to make.

### 0.5 · Traffic is not the goal

The fleet is ~15 cars. Capacity is hard-capped. **Traffic beyond peak-season utilisation has zero
marginal value.** Never set a traffic target, never optimise for volume, never propose scaled thin
content. Optimise for qualified, high-intent, bookable demand.

### 0.6 · When evidence conflicts, show the conflict

Do not smooth it over. Several findings here contradict each other and the contradictions are stated.
Preserve that habit.

### 0.7 · Verify before you claim a fix works

Lint it, run the validator, test the logic against real data. This project has already had one finding
(the `$0` root cause) corrected by implementation, and one document set corrected by an audit.

---

# 1 · THE BUSINESS

## 1.1 · Verified facts

| Field | Value | Source |
|---|---|---|
| Name | Geolander · GBP name **"Geolander car rental"** | `[OBSERVED]` schema, GBP |
| Domain | `https://geo-lander.com` | `[OBSERVED]` |
| Address | 8/5 Vedzini Street, Tbilisi 0108, Georgia | `[OBSERVED]` |
| Geo | 41.6980427 / 44.7934697 | `[OBSERVED]` `GLC_Settings` |
| Phone / WhatsApp | +995 551 33 04 14 | `[OBSERVED]` |
| Email | info@geo-lander.com | `[OBSERVED]` |
| Hours | 24/7 | `[OBSERVED]` |
| Country served | Georgia (**the country**, not the US state) | `[OBSERVED]` |
| Delivery cities | Tbilisi, Batumi, Kutaisi (airport), Kobuleti | `[OBSERVED]` `setup-cities.php` |
| Delivery charges | Tbilisi office and TBS: free. Kutaisi: **$68 pickup and $68 return**. Batumi: **$98 pickup and $98 return** | `[OBSERVED]` owner confirmation, 2026-08-21 |
| Booking model | Dates → live seasonal quote → WhatsApp confirmation. **A 10% prepayment confirms the booking; the remaining balance is paid at pickup.** BOG iPay is coded but dormant | `[OBSERVED]` owner confirmation, 2026-08-21 |
| Cancellation | Cancel at least 30 days before the rental starts: **50% of the booking prepayment is refunded.** Fewer than 30 days before the rental starts: **the prepayment is non-refundable** | `[OBSERVED]` owner confirmation, 2026-08-21 |
| Languages | 7 — `en` (default/x-default), `ka`, `ru`, `uk`, `ar`, `zh`, `fr` | `[OBSERVED]` |
| Pricing | Seasonal × duration-tiered: 1–2 / 3–4 / 5–7 / 8–12 / 13–18 / 19–30 / 31+ days | `[OBSERVED]` |
| RAV4 | The RAV4 records are the same physical car. Survivor: Toyota RAV4 **Hybrid AWD**, plate GG581WG. Owner describes fuel economy as **about 25–30% better** | `[OBSERVED]` owner confirmation, 2026-08-21 |
| Damage cover | Full insurance covers everything except **tyres**; wheels and windshield are covered. Existing driving-behaviour exclusions still apply | `[OBSERVED]` owner confirmation, 2026-08-21 |
| Booking email | No outbound email service is configured; Cloudflare currently provides DNS only. The app generates confirmations and activates automatic delivery through `GLC_SMTP_*` Railway variables | `[OBSERVED]` owner confirmation + implementation, 2026-08-21 |
| **Fleet floor** | **$26/day** — measured from the cheapest bookable car | `[OBSERVED]` computed from `cars.json` |
| Advertised ceiling | $120/day — **a settings value, not a real car.** Highest actual seasonal rate is **$90** | `[OBSERVED]` |

## 1.2 · The fleet — and why it is ambiguous

**Two sources disagree, and the disagreement is itself a defect.**

| Source | Foresters | Outlanders | Crosstreks | RAV4 | Highlander | Renegade | Wrangler | 4Runner | Total |
|---|---|---|---|---|---|---|---|---|---|
| `_migration/cars.json` (original import) | **7** | 2 | 2 | 1 | 1 | 1 | 1 | — | **15** |
| Live `/fleet/` page | 7 | **5** | 2 | 1 | 1 | **—** | 2 | **1** | **19** |
| `fleet-import/` folders | 5 | 5 | — | 1 | — | — | 1 | 1 | 13 |

`[OBSERVED]` **13 of the 15 in `cars.json` are `bodyType: Crossover`.** Only the Wrangler is
body-on-frame. A 4Runner and extra Outlanders appear on the live site but not in `cars.json`; the
Renegade appears in `cars.json` but not on the live site.

**Before you state any fleet number, run `_migration/audit-fleet.php` and use its output.** Do not quote
a count from memory or from this file — that error has already been made once.

## 1.3 · What is NOT known — the blocked register

**Every one of these gates a high-value page. None may be guessed.**

| # | Unknown | Blocks |
|---|---|---|
| B-1 | **Route permissions** per road, and whether insurance follows | `/where-you-can-drive/` — the flagship |
| B-2 | Handover photo/video process, traffic-fine notification/admin process, and dispute contact/response time. **Known:** no security deposit; 10% booking prepayment; cancellation refund timetable | `/trust/deposit-policy/` completion |
| B-3 | **Resolved:** no excess; 30,000 GEL third-party limit; everything except tyres covered; wrong-lane/red-light/speeding/failure-to-report-location exclusions | `/trust/what-our-insurance-covers/` can be completed |
| B-4 | Winter-tyre policy (standard? free?) | winter guide, differentiation claim |
| B-5 | GPS trackers — fitted or not | permission page (silence reads as yes) |
| B-6 | Per-vehicle odometer, service date, tyre age | vehicle pages |
| B-7 | Cross-border Armenia process detail | `/guides/driving-to-armenia/` |
| B-8 | **Margin by vehicle class · utilisation · rental-duration mix · booking volume** | **all economic prioritisation** |
| B-9 | Google Business Profile state (category, reviews, photos, website link) | the entire local strategy |
| B-10 | Google Search Console + GA4 data | any real baseline |
| B-11 | A locale-correct SERP (`gl=ge`, Tbilisi) | validation of nearly every market claim |
| B-12 | Whether rental prices include every applicable tax or any other mandatory charge | Do not call a calculated quote “all-inclusive including taxes” until confirmed |

`[INFERENCE]` **B-8 is the single most valuable unknown.** If long rentals (19–30 / 31+ tiers, priced
30–40% below the daily rate) are the profit engine, an entire cluster — monthly rental, relocation,
long-stay, digital nomads — becomes first priority and appears nowhere in this manual. Ask for it.

---

# 2 · THE CODEBASE

Repo root: the WordPress project. Custom block theme + one custom plugin. **No third-party SEO plugin.**

```
wp-content/plugins/geolander-core/includes/
  class-glc-seo.php       titles · meta · OG · canonical · sitemap filters · robots · gtag · noindex rules
  class-glc-schema.php    the whole JSON-LD @graph · prune() · current_trail() · breadcrumb_html()
  class-glc-i18n.php      locale prefix routing · hreflang · Accept-Language negotiation
  class-glc-pricing.php   seasonal engine · normalize() · seasons() · is_priced() · rate_range() · fleet_floor()
  class-glc-city.php      city CPT · /car-rental-{slug}/ rewrite · per-city Service schema
  class-glc-ai.php        /llms.txt and /pricing.md generators
  class-glc-blocks.php    fleet grid · booking widget · car specs · price table · FAQ · places grid
  class-glc-format.php    locale money/date formatting · range() ← the price floor
  class-glc-content.php   per-locale content meta resolver (glc_body_ru, glc_title_ka, …)
  class-glc-perf.php      Cache-Control headers
  class-glc-settings.php  business NAP, price band, GA4/Ads IDs
  class-glc-cpt.php       car/place/testimonial CPTs + taxonomies

wp-content/themes/geolander/
  patterns/               header · footer · hero · car-page · city-page · place-page · fleet-page · home-sections
  templates/              block templates (thin — patterns carry the markup)
  inc/strings-{locale}.php  7 UI catalogues, flat key→string
  assets/css/main.css     design system
  assets/js/reveal.js     ~1 KB scroll animation (only theme JS)

_migration/
  import.php              original 15-car import (converts key shapes correctly)
  import-fleet.php        folder-based fleet import ← WAS the $0 root cause
  setup-cities.php        the 4 city pages
  setup-pages.php         terms · contact · travel-info · music
  publish-route-guides.php  3 route guides
  setup-seo-pages.php     NEW — hubs + the 3 fact-blocked drafts
  audit-fleet.php         NEW — read-only duplicate/completeness report
  validate-schema.mjs     schema validator + SEO regression guard
  cars.json               source fleet data
```

**Content model:**

| CPT | Public | URL | Notes |
|---|---|---|---|
| `car` | yes | `/fleet/{slug}/` | archive `/fleet/` |
| `place` | yes | `/places/{slug}/` | ~36 destinations |
| `city` | yes | **`/car-rental-{slug}/`** | root-level custom rewrite, no parent |
| `testimonial`, `faq`, `booking_request` | no | — | internal |

Taxonomies: `car_brand` → `/brand/{t}/` · `car_body_type` → `/body-type/{t}/` · `place_region` →
`/region/{t}/`. **All three are now `noindex, follow` and out of the sitemap.**

---

# 3 · IMPLEMENTATION STATUS

Branch `seo/p0-p1-implementation`, commits `2c248b7` and `34e6f44`. **Not merged, not deployed.**

## 3.1 · Done — do not redo

| Area | Change |
|---|---|
| **Redirect loop** | `GLC_I18n::hooks()` now skips `redirect_canonical` on the front page. All six locale homepages returned *"Too many redirects"*; they are every page's `hreflang` targets |
| **Zero prices** | `rate_range()` returns `[0,0]` instead of inventing a floor · `GLC_Schema::car()` omits `offers` entirely · titles and meta descriptions omit the price · `llms.txt` says "price on request" · `pricing.md` prints `—` and lists unpriced cars separately |
| **Root cause** | `import-fleet.php` looked for `car.json`; every sidecar is named **`car.json.json`**. Now accepts variants, falls back to any non-example `*.json`, and **warns loudly** when a folder has none |
| **Price floor** | `GLC_Pricing::fleet_floor()` → `GLC_Format::range()`. One source of truth. Was $26 in the title and $28 in the hero |
| **Counts** | Places archive counts places; `llms.txt` counts the fleet. Both were hard-coded |
| **Fleet cards** | Show an honest "from $N/day" when no dates are chosen; nothing when unpriced |
| **Schema** | `prune()` strips nulls recursively · `areaServed` lists the 4 cities · city `Service` carries `Airport` + IATA + `availableChannel` + `hoursAvailable` · places carry real `geo` · business `image` is a photo, `logo` stays the logo |
| **Navigation** | Rebuilt, commercial-first, **skips pages that don't exist yet** so it self-upgrades |
| **Breadcrumbs** | Visible on city pages from `GLC_Schema::current_trail()` — the same source the JSON-LD reads. *(Car and place pages already had their own.)* |
| **Taxonomies** | `car_brand` / `car_body_type` / `place_region` out of sitemap, `noindex, follow` |
| **i18n** | 7 new UI keys across all 7 catalogues |
| **Guard** | `validate-schema.mjs` fails the build on a zero price, on `$0` in `llms.txt`/`pricing.md`, and on any locale homepage not returning 200 |
| **Tools** | `audit-fleet.php` (read-only) · `setup-seo-pages.php` (hubs published, 3 drafts created) |

## 3.2 · Verified how

```
Pricing harness against real cars.json:
  BEFORE (raw payload stored verbatim): 15/15 cars → lowPrice 0, quote $0
  AFTER: all 15 priced. Forester 2016 $26–$44 (7-day July quote $294);
         Wrangler 2017 $50–$90 ($560). Fleet floor $26.
         Unpriced car → [0,0], is_priced false — no invented floor.
PHP lint clean on all 22 changed files · node --check on the validator ·
git apply --check passed before applying.
```

`[UNVERIFIED]` **Nothing has run against a live WordPress instance.** No database in the build
environment. Run §11.2 before deploying.

## 3.3 · Not done — and why

| Not done | Reason |
|---|---|
| `/where-you-can-drive/`, `/trust/deposit-policy/`, `/trust/what-our-insurance-covers/` | **Created as drafts with `NEEDS:` markers.** Blocked on B-1, B-2, B-3 |
| `/terms/` rewrite | Blocked on the same policy decisions |
| Per-vehicle odometer/service content | Blocked on B-6 |
| Fleet deduplication | **Deliberately not automated.** Merging posts is destructive; `audit-fleet.php` reports, a human picks the survivor, then **301 the loser — never delete** |
| GBP, Trustpilot, TripAdvisor, reviews, outreach | Outside code. See §9 |

---

# 4 · THE MARKET

## 4.1 · The headline finding

`[OBSERVED]` **Geolander does not rank anywhere.** Four visibility probes returned nothing — including a
quoted search for the literal string `"geo-lander.com"`, which returned Wikipedia articles about
landforms. Competitors outrank Geolander on its own brand name.

**And the brand name is structurally compromised.** `[OBSERVED]` "Geolander" collides with **Yokohama
GEOLANDAR**, a global all-terrain SUV tyre line — the same semantic space, bound to the same vehicle
models Geolander stocks. A probe pairing the brand with "Subaru Forester" returned an Instagram page
titled *"Geolander Tires On Subaru Forester 17 Inch Wheels"*.

**Rule:** never target the bare brand term. Target compound phrases — *"Geolander car rental"*,
*"Geolander Tbilisi"* — and solve it as an **entity problem** (GBP, consistent NAP, `sameAs`,
third-party corroboration), not a content problem.

**Related trap `[OBSERVED]`:** the unqualified word "Georgia" retrieves the **US state** in ~4 of 10
results. Every page, anchor and outreach mention must co-occur with a disambiguator: *Tbilisi*,
*Caucasus*, *Sakartvelo*, *Kazbegi*, *"Georgia (country)"*.

## 4.2 · The English market is three separate SERPs

| Layer | Who ranks `[OBSERVED]` | Stance |
|---|---|---|
| **A · Head terms**<br>"car rental tbilisi", "rent a car tbilisi", "tbilisi airport car rental" | Sixt (6 queries), Europcar, Enterprise, Alamo, Avis, Hertz + Expedia, Skyscanner, Kayak, Booking, Rentalcars. *"rent a car tbilisi"* returned **7 results, zero Georgian operators** | **Do not compete.** Independents hold minority slots (geodrive.info, rentcarsgeorgia.com) so it is not impossible — just not worth the budget |
| **B · "No deposit"** | Eleven near-identical operators: roscar.ge, carwaytbilisi.com, gcartbilisi.com, tbilisicar.com, rentalcartbilisi.com, 4drivegeorgia.com, rentcarfy.com, geocarrent.ge, cheapcarrentaltbilisi.com. The literal string **"No Deposit, No Credit Card"** appears in ≥7 title tags | **Compete on proof, never on price.** roscar.ge's SUV entry is €29 and Localrent's is $19 — at or below Geolander's floor |
| **C · 4×4 / mountain** | fstarentcar, rentcarsgeorgia, tbilisocarrental, 4x4carrental, carrentdeme, starcar, og.ge, fill.ge | **The natural home — but see §6.2, the necessity claim was refuted** |

## 4.3 · Competitor teardowns — what each does badly

**The single most useful pattern:** three of the four strongest competitors have used `robots.txt` to
hide their own most valuable content.

| Competitor | Self-inflicted block `[OBSERVED]` | What it vacates |
|---|---|---|
| **Localrent** | `Disallow: /*terms`, `/*?*`, `/booking/*` | The **entire inventory layer and the entire contractual layer**. Every deposit, excess, insurance and cancellation query |
| **Sixt** | `Disallow: /php/` | Georgia deposit, CDW excess, mileage cap, fuel policy |
| **cars4rent.ge** | `Disallow: /*/` with 5 locale allows | **Its own nav-linked FAQ**, and any future guide or blog. A permanent self-imposed ceiling |
| **TripAdvisor** | `Disallow: /` to ClaudeBot, GPTBot, Google-Extended, Applebot-Extended, CCBot, Bytespider, meta-externalagent | **The richest corpus in this market, from every AI answer engine** |

### Localrent — the market leader

`[OBSERVED]` Georgia's entire indexable English footprint is **~17 URLs**. One airport page (TBS only).
**No vehicle category pages. No 4×4/SUV/AWD page for Georgia at all**, confirmed via its own sitemap.

- `/en/georgia/stepantsminda/` and `/en/georgia/telavi/` are **blank shells** — two headings, zero cars,
  zero prices, a loading string. **Stepantsminda is Kazbegi.**
- **Five contradictory "from" prices** across its own pages: $13 (country meta), $15, $19, $20, $30 —
  and the $13 and $30 sit on the *same page*
- The review figure is **hardcoded and wrong**: page says 4.8 from 4,509; actual Trustpilot is
  TrustScore 4.5, 4,712 reviews
- Zero editorial E-E-A-T — no bylines, no dates, image metadata from 2018–19
- **No winter, snow, tyre or mountain-pass content at all** — the traffic-rules article mentions them zero times
- Restricts renters to **paved roads only**

Its moats — supply volume, 4,712 reviews, a **Russian-card payment rail** (*"Принимаем аванс даже
российскими картами"*), an affiliate-fed recommendation layer — **cannot be attacked by a 15-car
operator.** Beat it only on the pages it has structurally chosen not to build.

### fstarentcar.com — the page-level benchmark

`[OBSERVED]` `/4x4-car-rental-tbilisi-georgia/`: 15 vehicles with visible rates **and model years**
(Wrangler 2016 €86, Forester 2019 €63, Renegade 2020 €53), date form, **8 on-page testimonials**, FAQ,
travel-guide link cluster, explicit *"No Deposit"*, and a **priced off-road insurance tier (€33/day vs
€9/day standard)**. **It ranks with exactly fifteen vehicles** — proof fleet size is not the constraint.
Weak: Svaneti and Abano absent from the money page; no live pricing; spread across four countries.

### Others worth knowing

- **4x4carrental.ge** — ranks on the core commercial 4×4 term with **unreplaced `Lorem ipsum`**, no
  prices, no booking, a Gmail address. The clearest proof the layer is undefended
- **rentcarsgeorgia.com** — ~3,000-word mountain page, **no H1 at all**; tells Tusheti searchers to hire
  a driver instead; one page carries Svaneti + Tusheti + Kazbegi so none gets its own URL
- **starcar.ge** — ranked twice on one SERP with blog guides; **no prices anywhere in them**; content
  sits on `/blog/` and doesn't feed money pages. Publishes that the Forester *"has no low-range gearbox
  for extreme terrain"* — an argument against Geolander's most numerous vehicle
- **carrentdeme.com** — H1 *"The Real Roads of Kazbegi — What Your Vehicle Needs to Handle"*
  (terrain-first framing that works); explicitly **forbids** Tusheti, Vashlovani, Juta
- **cars4rent.ge** — ~285-word homepage, zero prices, zero vehicles shown, four unlinked category labels.
  **Ranks on entity strength: 4.9 from 2,707 Google reviews.** Its *terms* define three road classes and
  a **€500 penalty** for taking the wrong car onto the wrong road — none of it on any page
- **werent.ge** — closest analogue; ranks organically *and* is top peer-recommended on TripAdvisor;
  displays Trustpilot 4.8 / Google 4.9 / TripAdvisor 4.9 on-page
- **gttoursgeorgia.com** — programmatic `/{locale}/car-rental/{city}/{destination}`. **Copy the URL
  logic, never the combinatorial padding** — "Sighnaghi to Svaneti" is synthetic

## 4.4 · The gatekeepers — not competitors, placement targets

| Property | Why it matters `[OBSERVED]` |
|---|---|
| **wander-lush.org** | ~15,000-word guide, 105 comments, the only independent on the head term, resident expert on TripAdvisor's Georgia forum with 440+ posts. **Discovery here is creator-mediated, not SERP-mediated.** Recommends Localrent, GoTrip, Martyna z Gruzji. Explicit affiliate disclosure — placement is negotiable |
| **ountravela.com** | The curated 4×4 operator list. **The decisive field per entry is a permission statement**, not a spec. Actively maintained. Lead-capture form per operator. **Geolander is absent** |
| **geotravelmarket.com** | 4×4 category has **two** operators and the page openly solicits vendors: *"get in touch — or list your services directly"*. Lowest-friction target found |
| **TripAdvisor** | Appeared on more queries than any other domain. Its best threads are **closed to new posts and 7–10 years old**, serving a decade-old €65/day price to 2026 searchers. **Self-promotional replies are deleted** — the business listing is the only legitimate route |
| **Trustpilot** | Nine Georgian competitors hold profiles; Geolander holds none. **Self-serve to claim** |
| **Wanderlog / wheree.com** | Auto-generated **from Google Business Profile data. No submission route exists.** A populated GBP manufactures this entire tier with zero outreach |
| Russian listicles — vc.ru, dtf.ru, tip-to-trip, georgia.in-facts | Affiliate roundups listing **only aggregators**. Pay-to-play |

---

# 5 · THE CUSTOMER

64 sources mined. `[CUSTOMER]` quotes below are verbatim from real travellers.

## 5.1 · Six findings that overturn standard car-rental SEO

1. **Price is almost absent from the conversation.** Across 14 forum threads and ~8 review sources,
   almost no price-shopping discussion. They argue about permitted roads, deposits, insurance voiding
   and car age. **Do not build price/comparison pages.**
2. **The product category is partly illegal to use as advertised.** The point of renting an SUV in
   Georgia is the mountains, and the mountains are where contracts forbid you to go — enforced by GPS.
   *"The rental company can block the car when it enters a forbidden area."*
3. **Customers shopping 4×4 in Georgia are shopping for PERMISSION, not specs.** On ountravela's ranked
   list the decisive line per operator is *"Driving in Tusheti and Vashlovani is authorized"* /
   *"prohibited"* / *"authorized only for Toyota 4runner and Tacoma models"*.
4. **A road got better and the contracts didn't notice.** Mestia–Ushguli–Lentekhi was sealed in 2024 and
   *"can now be driven by any car"* — yet *"most rental car terms still forbid driving on this road"*. A
   poster is openly shopping for *"rental companies with updated terms"*. **Verify current status before
   acting.**
5. **Nobody fears crime.** Not one poster raised theft or personal safety — despite *"is it safe"* being
   a thread title. The fear is other drivers, cows and cliffs. **Content about crime answers a question
   nobody asked.**
6. **The market's trusted voice is one person, not a brand** — see wander-lush above.

## 5.2 · The VOC library — 14 themes, ranked by commercial value

**VOC-1 · Forbidden roads void your insurance** — *dominant*, 6 of 14 threads
> *"be extremely careful… they requested USD100 cash deposit which was not returned due to the reason we did not travel on 'approved' roads"*
> *"It's also worth asking them directly as I know a couple of people who were able to get permission despite the T&Cs."*

The contested map, corroborated by two independent sources: **routes** — Akhaltsikhe–Batumi via
**Goderdzi Pass**, **Mestia–Ushguli–Lentekhi**, Sairme–Abastumani. **Regions** — **Truso**, **Juta**,
**Vashlovani**, **Tusheti/Omalo**, **Shatili**/Upper Khevsureti, Abkhazia, South Ossetia.

**VOC-2 · "Full insurance" is not full** — *dominant*, 7 of 14
> *"I specifically paid good money to get the full insurance with zero excess liability insurance"… "the insurance company is voiding the insurance."*
> *"I saw that Herz only covers for 50.000 dollar third person liability which is very little in comparison to the EU"*

Competitors publish *"$100 deductible… civil liability $30,000"*. **Geolander's homepage publishes the
exact phrase travellers say betrayed them — while `/terms/` §2 contradicts it.**

**VOC-3 · Deposit confiscation** — *common-to-dominant*; **every negative review read was a deposit story**
> *"the company kept my deposit and created a completely false story about fines and damages that never happened."*
> *"Free delivery to hotel…do not require credit card. Cash deposit was returned honestly."* ← the positive framing they look for

Severity ≫ frequency: only ~0.9% of one 1,453-review set, but the most extreme language. **Do not dismiss
on volume.**

**VOC-4 · "Do I need a 4×4?" — the contested question** — 8 of 14 threads, **roughly as many "no" as "yes"**
> For: *"100% need a high clearance 4x4. Not a low SUV."* / *"a 4x4 with good clearance is a necessity… 4-6+ hours on dirt roads"*
> Against: *"You do not need a 4x4 car, but need a car with a good clearance which in most of the cases is SUV."* / wander-lush: *"a standard sedan is perfectly adequate"*

**Travellers converge on CLEARANCE, WINTER TYRES and DRIVER EXPERIENCE — not on "4×4". Match their
vocabulary.**

**VOC-5 · Car condition** — *the most numerous complaint category*. One review set: **107
condition-related vs 13 deposit disputes — 8:1**
> *"The car they gave me had a dangerously cracked tire… 7 puncture repairs and 3 air refills"*
> **The strategically decisive quote:** *"Car was high mileage with lots of scratches **but this was all declared on the paperwork at collection** — considering the state of some roads I felt a lot happier."*

**Pre-declared damage does not lose the sale. It wins trust.**

**VOC-6 · Bait-and-switch** — *"Car we chose on the website is not the same car was given. This happens every time"*. Geolander's homepage already promises *"Real cars, real photos — the exact car you book is the one you get"*. **That is the best-aimed sentence on the site and it is currently unsupported.**

**VOC-7 · Airport no-show** — two multi-hour waits recorded at TBS. The most actionable positive in the
dataset: a 5-star review where **the differentiator was that the rep sent a photo of his location**.

**VOC-8 · The trust vacuum** — *"which company should I use"* is the opening question of **6 of 14
threads**. *"All the reviews on Google are really poor."* **Geolander was named zero times in everything
read.** Warned against by name: Naniko, Mimino, STAR, RentCarPlus, RACE.

**VOC-9 · "Is it safe to drive?"** — top of funnel, where the market is lost to drivers. *"Right of way
goes to the biggest vehicle."* / *"Drivers hate stopping."* Practical consensus: **park and walk in
Tbilisi**, don't drive at night, start before 9am.

**VOC-10 · Hire a driver instead** — the recommended answer in 4 threads, **pushed by Tbilisi tour
operators active on the forum. Organised competition.** Price anchor: 500–600 GEL Tbilisi–Omalo.
**Geolander competes with GoTrip for the decision to self-drive at all**, not with Localrent for a fixed
pool.

**VOC-11 · Cross-border to Armenia** — a **binary filter** that eliminates most locals and pushes people
to Avis/Hertz. Documented process: permission docs *"one copy in Georgian and one in Russian"*, checked
at **Guguti/Gogavan**. **`/terms/` §6 already permits it with 48h notice — buried.**

**VOC-12 · Winter** — winter tyres **mandatory Dec 1 – Mar 1** on mountain roads; high passes close
~late Oct–May; **Abano open ~2 months a year and nobody says which two**. Counter-seasonal, and the one
period where the 4×4 case is unambiguous.

**VOC-13 · Fines after you fly home** — unmarked police (a grey Skoda with a camera). Intersects VOC-3:
*"created a completely false story about fines"*.

**VOC-14 · IDP confusion** — the two leading guides **directly contradict each other**. Geolander says
"recommended", which is the vague answer travellers are already frustrated by.

## 5.3 · The five promises that would most reduce anxiety

1. **"Here is exactly where you may drive, per vehicle, and what our insurance covers there."**
2. **"Here is our deposit: amount, mechanism, release date, and the only reasons we'd keep any of it."**
3. **"This exact car — odometer, service date, and photographs of its existing scratches."**
4. **"Our excess is $X, third-party liability is $Y, and these specific things are excluded."**
5. **"We track your flight. Here's your driver's name, photo, and the exact TBS meeting point."**

`[INFERENCE]` **Every one is a policy publication, not a marketing claim.** None requires authority or
backlinks to be true. Four of five need a decision from the owner first.

**And the uncomfortable conclusion:** Geolander's stated offer already matches this market's stated
buying criteria almost line for line. **The problem is not the offer. Nobody can find it, and nobody can
verify it.**

---

# 6 · THE STRATEGY

## 6.1 · The Golden Path

> **Stop trying to rank and start trying to be verifiable: fix what is broken, get onto the platforms
> where this market actually decides, publish the policies nobody else will commit to in writing, and
> let the rankings follow the trust rather than the other way round.**

Five strategies were built and adversarially stress-tested. Scores after testing:

| Strategy | Score | Role |
|---|---|---|
| **A · Local commercial dominance** (GBP, reviews, listings, entity) | **82** | **Primary — earns now** |
| **E · Technical & conversion** | **74** | **Sequencing — unblocks everything, generates no demand** |
| **B · Transactional landing pages** | **68** | Infrastructure; 2–3 category pages only |
| **D · Topical / journey capture** | **64** ▼ from 84 | **Supporting.** The permission sub-cluster survives; the 4×4-necessity thesis does not |
| **C · Authority & backlinks** | **52** | Assets embedded inside D, not an organising principle |

## 6.2 · THREE CLAIMS THAT WERE REFUTED — do not re-adopt them

**This section exists because these ideas are attractive and will occur to you independently.**

### ✗ REFUTED — "We're too small to rank"
`[OBSERVED]` fstarentcar.com ranks on the core 4×4 term with **exactly 15 vehicles** and a near-identical
fleet. gsscarrental.com ranks a 600-word single-model page with no FAQ, no reviews, no schema.
Independents hold head-term slots. **Fleet size was never the constraint** — entity absence, no reviews,
no aggregator distribution and the brand collision are.

### ✗ REFUTED — "4×4 is a defensible niche" *(medium confidence)*
- `[OBSERVED]` Gergeti Trinity (paved, no 4×4 needed): **1,283** TripAdvisor reviews. Tusheti National
  Park (genuinely 4WD-mandatory): **70**. An ~18:1 demand proxy against
- Kazbegi's road is sealed; Mestia–Ushguli concreted 2024. **Both volume destinations de-4×4'd**
- The season is ~4 months (early June – early Oct)
- **og.ge already owns the jeep-vs-sedan page** with live prices and Book Now CTAs
- Kayak runs a Tbilisi-4×4 page quoting a **$27** 4×4 — below Geolander's floor
- **13 of 15 vehicles are crossovers** that ountravela does not recommend for the Omalo road
- **Geolander already publishes mountain/4×4 content and ranks nowhere.** The niche was never the missing ingredient

**What survives:** the **permission** question is a different claim from the **necessity** claim, and
only the second was refuted. Build the permission page. **Never write "you need a 4×4 in Georgia."**

### ✗ REFUTED — "Russian is under-served" *(high confidence)*
`[OBSERVED]` Russian is the **most densely served** layer. `georentcar.ge/ru/turistam/zapreschennye-marshruty.html`
is a dedicated Russian "Prohibited Routes" page **with insurance consequences stated**. Per-model Russian
pages exist. carrental-georgia.com has a ~4,500-word Russian jeep guide in colloquial Russian
(*"паркетник"*, *"гелик"*). The discovery layer is affiliate listicles listing **only aggregators**.
Localrent is Russian-native with a Russian-card payment rail and a $19 SUV.

**What survives:** RU SERPs are **not OTA-locked** (no Sixt/Hertz/Avis/Booking/Kayak on six queries) —
structurally *open*, which is different from *under-served*. **The permission wedge is an ENGLISH gap.**

**Action: fix `/ru/` as hygiene. Do not fund a Russian content programme.** One bounded test only
(EX-06: a single natively-translated policy page). The tourism data was never wrong — Russia is
Georgia's #1 source market, 1.61M arrivals 2025, 21.8% of Q4. **The inference from audience size to
search opportunity was wrong.**

## 6.3 · Where to compete / where not to

| ✅ Compete | ❌ Do not |
|---|---|
| **Route permission, in English** — no rental company answers it | Head terms — 7 results, zero Georgian operators |
| **GBP + reviews** — manufactures the Wanderlog/wheree tier automatically | **Price** — Localrent $19, Kayak $27, both below your floor |
| **Kazbegi / Stepantsminda** — the leader's page is blank | **A Russian content programme** — refuted, high confidence |
| **A 4×4 category page** — the leader has none for Georgia | **"You need a 4×4"** — refuted; og.ge owns that page |
| **Deposit & insurance detail** — the leader robots-blocked itself out | **Hardcore off-road positioning** — 13/15 are crossovers |
| **Per-vehicle honesty** — a 1,000-car operator cannot do this | Arabic / Chinese / French content — total OTA walls, zero `.ge` penetration |
| **AI answer engines** — TripAdvisor blocks every major AI crawler; you welcome them | Georgian-language rental terms — classifieds own that SERP |
| Airport arrival protocol · road calendar · driver comparison · Armenia · winter | Bare-brand "Geolander" · doorway city/route pages · combinatorial city×destination |

## 6.4 · Four advantages, each verified

1. **Fifteen cars is a publishing advantage.** Condition complaints outnumber deposit complaints 8:1, and
   a large operator *cannot* publish per-unit odometer and service history.
2. **Permission is set by an insurer, not a marketing team** — definitionally uncopyable by an aggregator.
   `[CUSTOMER]` A lost booking on record: *"The owner refused to let us drive that route, so we canceled
   and booked a Mitsubishi Outlander from Martyna z Gruzji instead."*
3. **The AI window is open and the strongest competitor opted out** — and it is time-sensitive.
4. **The WhatsApp funnel is a trust mechanism.** Travellers already say *"Always record a video when you
   pick up the car."* Doing it for them puts timestamped evidence in *their* pocket.
   **Honest counter-evidence `[OBSERVED]`:** one traveller explicitly wanted a company *"I can book
   online (and not just book on Whatsapp)"*. Both are true. Make the WhatsApp path visibly excellent;
   don't defend it as ideal.

---

# 7 · CONTENT SPECIFICATIONS

## 7.1 · Universal rules

- **Answer first, sell second.** The most common competitor failure here is intent mismatch —
  rentcarsgeorgia.com runs 3,000 words of destination detail under an H1 that is a pure CTA
- **No word-count targets.** Competitors rank at 600 words and at 3,000
- **Publish prices.** starcar.ge's top-ranking guides have none — that is a gift
- **Date everything factual.** TripAdvisor's best pages are 7–10 years old. Currency is a competitive advantage
- **One intent per URL**
- **No `FAQPage` expansion** — `[FIRST-PARTY]` FAQ rich results ended **7 May 2026**. Keep the visible
  FAQ; stop investing in the markup
- **Disambiguate "Georgia"** in every title, heading and anchor

## 7.2 · The priority pages

### 1 · `/where-you-can-drive/` — **the flagship** *(draft exists; blocked on B-1)*
Title: `Where You Can Drive a Geolander Car — Georgia Road Permissions & Insurance`
H1: `Where you can drive a Geolander car`
Structure: The roads other companies forbid → **the table** → what "permitted" means for insurance →
do we use GPS trackers → which car for which road → seasonal windows → ask before you book
**Table rows:** Tusheti/Abano · Omalo · Shatili/Khevsureti · Truso · Juta · Vashlovani ·
Mestia–Ushguli–Lentekhi · Goderdzi · Zekari · Sairme–Abastumani · Gergeti access track
**Columns:** permitted? · which vehicles · insurance status · open season
Must carry a dated *last reviewed* stamp. CTA: *"Check availability for these dates"* — not "Book now".
Schema: `Service` + `BreadcrumbList`.

### 2 · `/trust/deposit-policy/` *(draft exists; blocked on B-2)*
Amount → how and when returned → **the only reasons we'd keep any of it (enumerated)** → the WhatsApp
photo/video handover → traffic fines → escalation path with a named human.

### 3 · `/trust/what-our-insurance-covers/` *(draft exists; blocked on B-3)*
**Numbers, not adjectives.** Excess · third-party liability limit · exclusions (tyres, alloys,
windscreen, underbody, roof, interior, single-vehicle, off-contract roads) · what voids cover ·
accident procedure step by step. **Must resolve the homepage↔terms contradiction, and `/terms/` must be
rewritten to match.**

### 4 · `/fleet/{vehicle}/` ×~14 — **conversion pages, not ranking pages**
Per-model search demand is negligible. Their job is to close. Publish **odometer · last service date ·
tyre age and type · ground clearance in mm · real fuel consumption · dated photos including existing
scratches** + an explicit **anti-"or similar" pledge** with a stated remedy.

### 5 · `/car-rental-kazbegi/` — **best uncontested URL found**
The drive from Tbilisi → the Military Highway → **the Gergeti access track, what your car actually
needs** → Juta and Truso and whether you may drive them → winter → which of our cars → delivery.
**Be honest:** wander-lush says the Gergeti road is *"fully sealed so there is no issue with insurance
there"*. Say so. A page that tells the reader they don't need the expensive car earns the right to be
believed about Juta.

### 6 · `/fleet/4x4-suv/` — category page
**AWD vs 4WD-with-low-range, explained.** Ground clearance by model. Where each is permitted.
**Address the fleet objection directly:** starcar.ge publishes that the Forester *"has no low-range
gearbox for extreme terrain"* — and seven of fifteen cars are Foresters. The honest answer (symmetrical
AWD + X mm clearance handles A, B, C; for D take the Wrangler) is more credible than silence.

### 7 · `/guides/mountain-road-opening-calendar/` — **the linkable asset**
One row per pass: typical open window · typical close window · **the date last verified** · surface ·
whether Geolander permits it. **Update every April/early May.** A stale calendar is worse than none.

### 8 · `/` homepage
Current H1 is *"Explore Georgia Your Way"* — a slogan, in a market that decides on permission, deposit,
insurance and condition. Recommended: **`4×4 car rental in Tbilisi — the exact car you book is the one
you get`**. Above the fold: quote widget · price range · the three inclusions travellers actually name ·
**third-party review proof with a link**, not a bare "★ 5.0".

### Also build (lower priority)
`/guides/rent-a-car-or-hire-a-driver/` (**concede where a driver wins, name GoTrip**) ·
`/guides/driving-in-georgia/` (**no crime content**) · `/guides/driving-to-armenia/` ·
`/guides/driving-in-georgia-in-winter/` · `/car-rental/` hub · `/guides/` hub

### Repurpose, don't delete
**~36 place pages:** from "what this place is" → **"how you drive there"** — surface, the last
kilometres, clearance needed, where you actually park, seasonal access, drive time, whether the access
track is permitted. **No new URLs.** carrentdeme.com proves the framing ranks.

## 7.3 · DO NOT BUILD

City pages for cities you don't genuinely serve · pickup×destination combinations · price/comparison
pages · head-term landing pages · Arabic/Chinese/French content · Georgian-language rental content ·
a weekly blog · bare-brand optimisation · FAQ schema expansion · `aggregateRating` on your own business.

---

# 8 · TECHNICAL SPECIFICATION

## 8.1 · Schema rules

| Node | Rule |
|---|---|
| `AutoRental` | Keep. Correct subtype. NAP **must match the GBP exactly**. `areaServed` = country + the 4 cities. `image` = photo, `logo` = logo |
| `["Product","Car"]` | Keep as semantics. `[FIRST-PARTY]` Google's vehicle markup is *"for car dealerships"* — a **for-sale** feature. **Do not expect rich results.** **`offers` omitted entirely when price ≤ 0** |
| `Service` (city) | `serviceType` "Car rental" · `provider` → business · `areaServed` = City + **Airport with `iataCode`** (TBS/BUS/KUT) · `availableChannel` · 24/7 `hoursAvailable`. **No per-city `offers`** — the rate is identical everywhere; fabricated granularity is worse than none |
| `BreadcrumbList` | Keep. **Must have a visible breadcrumb** — build both from `GLC_Schema::current_trail()` |
| `TouristAttraction` (places) | Keep **with real `geo`**. You are not the authority on Gergeti Trinity Church — but you are on the road to it |
| `FAQPage` | `[FIRST-PARTY]` **FAQ rich results ended 7 May 2026.** Keep for AI extraction only. Zero further investment |
| `aggregateRating` / `review` on your own business | `[FIRST-PARTY]` **Against guidance** — *"only recommended for sites that capture reviews about other local businesses"*. Let reviews live on the GBP |
| Never add | `Event`, `HowTo` (removed 2023), `Speakable`, `JobPosting`, `VideoObject` (no video yet), dealership vehicle feeds |

## 8.2 · i18n

- `en` = default, unprefixed, `x-default`. `/{ka,ru,uk,ar,zh,fr}/` = prefixed
- `GLC_I18n::boot()` strips the prefix before routing; `home_url` is filtered to re-add it
- **The front page MUST skip `redirect_canonical`** — this is the redirect-loop fix. Do not remove it
- `hreflang` for all 7 + `x-default`, self-referencing, emitted at `wp_head` priority 1
- **Locale plan:** `en` full · `ru` hygiene only (refuted, §6.2) · `ka` UI + city pages · `uk`/`ar`/`zh`/`fr` UI only
- `[FIRST-PARTY]` Google advises **against** automatic language redirection. The Accept-Language 302 also
  forces `Cache-Control: private, no-cache` on `/`, so the most important URL can never be edge-cached.
  **Dropping it is EX-02** — an experiment, run after the loop fix

## 8.3 · Performance

- Server-rendered, ~6 KB JS total. **The technical layer is not the bottleneck**
- LCP risk: hero 467 KB WebP on an origin that cannot edge-cache `/`
- CLS unknown — `reveal.js` is a scroll-reveal pattern, a common source
- `[OBSERVED]` PageSpeed Insights was rate-limited (429) during research. **Real CWV are unmeasured.**
  Run PSI on `/`, `/fleet/`, a car page and `/car-rental-batumi/`, and **report whether CrUX field data
  appears** — its absence is itself a baseline data point
- Place images 700–920 KB, not converted to WebP. Logo is a 699 KB PNG

## 8.4 · Internal linking

**Target graph:** hub → children, children → hub, children ↔ siblings, plus cross-cluster:
permission page → permitted vehicles → category · guides → the specific vehicles they recommend
(already done well) · place pages → the guide covering that road → the vehicle class needed · city pages
→ each other + permission + deposit · every vehicle → its permission tier and category.

**Anchor rules:** compound brand phrases, never the bare name. Always a "Georgia" disambiguator. No
exact-match stuffing — the "No Deposit, No Credit Card" cluster shows what over-optimisation looks like
here.

`[UNVERIFIED]` **No crawl has ever been run.** Orphans, redirect chains, real click depth and true index
size are unknown. A 500-URL Screaming Frog run (free tier) closes this in ~30 minutes.

---

# 9 · OFF-SITE & LOCAL — the highest-value work, and you cannot do most of it

## 9.1 · Google Business Profile — probably the biggest single lever

`[UNVERIFIED]` **The GBP was never inspected and the local pack was never observed** (research tooling
was US-locale). This is the first thing to establish.

**Why it matters beyond ranking `[OBSERVED]`:** Wanderlog and wheree.com auto-generate business pages
from Google Maps data with **no submission route**. A populated GBP manufactures an entire citation tier
with zero outreach. **These citations cannot be pitched — only caused.**

**Checklist:** primary category **must be "Car rental agency"** · complete every field · **upload real
fleet and premises photos** (high-resolution originals already exist in `_migration/fleet-import/`) ·
website link → `geo-lander.com` with a UTM · verify hours are genuinely 24/7 · one profile only.

**Never:** additional GBP listings for delivery cities (`[FIRST-PARTY]` requires a staffed physical
location — a delivery radius is a service area on the existing listing) · name stuffing · category spam.

## 9.2 · Reviews — the compounding asset

**Send the review link over WhatsApp after every single rental.** Ask for specifics: *"it helps if you
mention where you drove and how the car handled it"* — reviews naming Kazbegi and Svaneti build the
topical association the whole strategy depends on. Respond to every review in the reviewer's language.

**Never gate, incentivise or fabricate.** `[ESTIMATE]` ~20 genuine, recent, detailed reviews would
transform the listing's competitiveness — roughly one month of ordinary rentals, though **booking volume
is unknown (B-8)**, so treat "one month" as a placeholder.

`[INFERENCE]` **If only one thing from this entire manual gets done, make it this.** It costs two
minutes, compounds indefinitely, cannot be copied, and simultaneously feeds the local pack, the
auto-generated directory tier, the AI recommendation layer and the buyer's decision.

## 9.3 · Listings and placement, in order

| Tier | Action | Effort |
|---|---|---|
| 0 | **GBP** (§9.1) → manufactures Wanderlog/wheree automatically | Low, highest value |
| 0 | **Trustpilot** — self-serve; nine competitors have profiles, Geolander has none | Low |
| 0 | **TripAdvisor business listing** — listing only; forum self-promotion is deleted | Low |
| 1 | **geotravelmarket.com** — openly solicits vendors, 4×4 category has two | Very low |
| 1 | **ountravela.com** — lead the pitch with the **permission table**, not a link request | Low, high value |
| 1 | **wander-lush.org** — lead with the **road calendar** because it makes her guide better. Months, not weeks | Medium |
| 2 | **Localrent** — **distribution, not citation.** It does not name suppliers, so an AI citing it never learns "Geolander". Also: it restricts to paved roads, which contradicts a permission-led position, and its $19 SUV anchors against your direct price. **Name the tension openly** | — |
| 3 | Hotels, guesthouses, trekking operators, tourism bodies | Human only |

**Never:** bought links · PBNs · guest-post farms · mass directory submission (the directories that
matter here **cannot be submitted to**) · TripAdvisor or Reddit astroturfing (deleted, and visible) ·
fabricated reviews.

**Asset-first principle:** three of the four best placements are unlocked by the **permission table** and
the **road calendar**, not by outreach volume. Build the assets, then reach out. Outreach without them is
a request for a favour; with them it is an offer.

---

# 10 · MEASUREMENT

## 10.1 · The structural hole no tool fixes

The funnel hands off to WhatsApp. **Everything after that is invisible** — even with perfect GA4, you
can count booking *requests* and never know the request→booking rate, real revenue, or which landing
pages produce customers.

**The fix is a spreadsheet, starting now.** Per request: `GL-XXXX` reference · date · **confirmed Y/N** ·
vehicle · duration · value · customer language · **"how did you find us?"** asked in the first WhatsApp
reply. `[INFERENCE]` That last question, asked consistently, is worth more than any analytics config.

## 10.2 · Three KPI layers

**A · Output (fully controlled):** P0 tickets closed · duplicates resolved · vehicles with real content ·
**review requests sent (target: 100% of rentals)** · listings live · pages published · outreach opened.

**B · Leading (needs GSC):** **non-brand** impressions and clicks · permission-cluster impressions ·
trust-cluster impressions · CTR on commercial queries · positions 1–3 / 4–10 / **11–20 (striking
distance)** · indexed strategic pages · excluded pages **by reason** · GBP views/clicks/calls/messages ·
**review velocity** · local-pack position (manual, Tbilisi locale).
**Deliberately excluded: referring-domain count and DA.** A page ranks here on the core commercial term
with lorem ipsum text — links are not the binding constraint.

**C · Business:** organic → WhatsApp handoffs · booking requests · **confirmed bookings (manual log
only)** · conversion rate · organic revenue · **gross profit**.
**Traffic is deliberately absent.**

## 10.3 · Cadence and alerts

**Weekly (20 min):** ranking movement on priority clusters · indexing errors · new reviews and responses ·
were review requests actually sent · anything broken.
**Monthly (60 min):** non-brand impressions/clicks · cluster performance · **striking-distance queries at
11–20** · GBP insights · review velocity · booking log · **which assumptions turned out wrong**.
**Quarterly (½ day):** competitor sweep · **re-check the permission landscape — Localrent lifted a ban in
2025, this market's rules change** · re-run the SERP capture · reallocate.

**Alerts:** commercial query enters 4–20 → improve that page first · high impressions + low CTR → check
for `$0` and price mismatches · a competitor changes its permitted-routes policy → this directly affects
the differentiator · **review velocity below one per two weeks → the process has lapsed** · a GSC query
nobody targeted appears → real demand discovered.

**`[INFERENCE]` Small numbers warning:** with ~15 cars, monthly booking counts are small enough that
variance swamps most changes. Use 3-month rolling comparisons. Treat single-month movements as noise.

---

# 11 · HOW TO WORK

## 11.1 · Making a change safely

1. **Branch.** Never commit to `main`
2. **Read before you edit.** This codebase has deliberate design decisions with explanatory comments —
   e.g. fleet cards originally had no prices *by design*. Understand the reasoning before overriding it,
   and explain in a comment why the balance changed
3. **Small, logical batches** with real commit messages explaining *why*
4. **Lint everything:** `php -l` on every changed PHP file, `node --check` on JS
5. **Test the logic against real data** where possible — a standalone harness with WP function shims
   proved the pricing fix and disproved a wrong theory
6. **Run the validator** (§11.2)
7. **Never delete a URL.** 301 to the survivor — a deleted URL loses whatever equity and external links
   it had
8. **Bump the theme `Version:` header** in `style.css` when CSS or JS changes — cache busting is
   version-keyed, not filemtime-based
9. **Add new UI strings to all 7 locale catalogues**, not just English

## 11.2 · Validation commands

```bash
# Re-import the fleet (now that the sidecar filename is actually found)
docker compose run --rm cli eval-file /migration/import-fleet.php

# Read-only fleet integrity report — changes nothing
docker compose run --rm cli eval-file /migration/audit-fleet.php

# Create hub pages + the three fact-blocked drafts
docker compose run --rm cli eval-file /migration/setup-seo-pages.php

# Flush rewrites (required after adding pages or cities)
docker compose run --rm cli rewrite flush

# THE GUARD — fails on zero prices, on "$0" in llms.txt/pricing.md,
# and on any locale homepage that does not return 200
node _migration/validate-schema.mjs http://localhost:8080
```

**Then check by hand:** `/ru/` and `/ka/` return 200 · `/pricing.md` contains no `$0` · `/fleet/` shows
prices · a car page passes Google's Rich Results Test · the nav renders in every locale · the visible
breadcrumb matches the JSON-LD trail.

## 11.3 · Known environment limits

`[OBSERVED]` These bit during the original work — expect them:

- **Web search tooling is US-locale.** It returned a *golf club* for "car rental georgia country".
  **Nobody has ever seen a real `google.ge` result set.** Buy one locale-correct SERP capture
  (`gl=ge`, `uule`=Tbilisi, full SERP with local pack and AI Overview) before trusting any market claim
- **Reddit is egress-blocked** in some environments — there is no Reddit data in this research at all
- **PageSpeed Insights rate-limits** (HTTP 429)
- **XML sitemaps may return as unparseable binary** to markdown-converting fetch tools
- **Trustpilot, Zhihu, otzovik and PissedConsumer** blocked or CAPTCHA-walled
- On a mounted device filesystem, `git` may leave stale `.git/HEAD.lock` and cannot unlink it — **move
  the lock aside, don't try to delete it**

---

# 12 · CORRECTIONS ALREADY MADE — do not reintroduce

An independent audit and the implementation itself both caught errors. Preserve these.

| # | The error | The truth |
|---|---|---|
| C-1 | "Six Foresters, five Outlanders", and a 4Runner recommended as an upsell | **`cars.json`: 7 Foresters, 2 Outlanders, no 4Runner.** The live site shows 5 Outlanders and a 4Runner. **Run `audit-fleet.php`; never quote a fleet number from memory** |
| C-2 | "$26–$120/day" presented as the real range | `$120` is a **settings ceiling**, not a car. Highest actual seasonal rate: **$90**. Floor: **$26** |
| C-3 | The `$0` root cause blamed on a season-key mismatch | **The real cause: `import-fleet.php` looked for `car.json`; every sidecar is `car.json.json`.** `import.php` converts key shapes correctly. `normalize()` is defence in depth, not the cause |
| C-4 | "No breadcrumb UI anywhere" | **Car and place pages already had one.** Only city pages lacked it |
| C-5 | "No nav slot goes to a page that makes money" | **`/fleet/` and a Book Now button were present.** The accurate claim: no slot went to a *city* or *permission* page |
| C-6 | The taxonomy fix named only `car_brand` and `car_body_type` | **`place_region` is also public** — ~42 more URLs across locales |
| C-7 | `[OBSERVED]` on "ountravela does not recommend crossovers for Omalo" | That was **one vendor's contract term**, not the publisher's recommendation. `[INFERENCE]` at best |
| C-8 | `/terms/` §8 quoted as a flat prohibition | Actual text: *"Off-road driving **(unless vehicle is specifically approved)**"*. **The qualifier is what may make the permission page publishable without a full policy rewrite** |
| C-9 | Russian framed as the bigger opportunity | **Refuted at high confidence.** See §6.2 |

---

# 13 · EXECUTION ORDER

**Week 1 — unblock.** Fix the redirect loop ✅ · kill `$0` prices ✅ · re-import the fleet with the
corrected sidecar lookup · **audit the GBP (owner)** · **screenshot the local pack from Tbilisi (owner)** ·
**answer the route-permission question (owner)** · **supply the four trust numbers (owner)** · buy one
locale-correct SERP capture · export GSC + GA4.

**Weeks 2–4 — become verifiable.** **Start the post-rental review request — every rental, forever** ·
upload real fleet photos to GBP · claim Trustpilot + TripAdvisor + geotravelmarket · resolve fleet
duplicates (301, never delete) · **rewrite `/terms/` so it stops contradicting the homepage** · publish
prices on `/fleet/` ✅.

**Weeks 3–8 — publish the proof.** Deposit page · insurance page · airport arrival protocol ·
per-vehicle honesty pages · **the permission flagship** · nav rebuild ✅ · breadcrumbs ✅ · schema and
image fixes ✅.

**Weeks 8–16 — occupy what nobody defends.** `/car-rental-kazbegi/` · `/fleet/4x4-suv/` · the road
calendar · repurpose the place pages · link the mountain guides to each other · first editorial outreach.

**Month 3+ — earn the citations.** ountravela · wander-lush · local partnerships · the Localrent decision.

**✅ = already implemented on `seo/p0-p1-implementation`.**

---

# 14 · WHAT SUCCESS LOOKS LIKE

Not rankings. Not traffic.

- Every locale homepage returns 200 and no page anywhere quotes a price of zero
- The fleet count is identical on `/fleet/`, `/llms.txt`, `/pricing.md` and the forecourt
- A visitor can find the deposit amount, what the insurance covers, and where they may drive — **in under
  thirty seconds**
- The GBP is complete, correctly categorised, photographed, and gaining genuine reviews **every week**
- Geolander appears on Trustpilot, TripAdvisor, geotravelmarket and at least one editorial listicle
- AI answer engines cite Geolander on route and permission questions
- A booking log exists, and organic bookings can be counted with margin attached
- **Nothing published is untrue**

**And the one-sentence test for any change you are about to make:**

> *Does this make a nervous traveller more able to verify that Geolander is real, honest, and permitted
> to take them where they want to go?*

If not, it is probably not worth doing here.

---

## Appendix · Source documents

Full research lives alongside this file: `EXECUTIVE-SEO-STRATEGY` · `GOLDEN-PATH` ·
`STRATEGY-STRESS-TEST` · `FIVE-SEO-STRATEGIES` · `SERP-COMPETITOR-MAP` · `COMPETITOR-INTELLIGENCE` ·
`CUSTOMER-SEARCH-INTELLIGENCE` · `TECHNICAL-SEO-AUDIT` · `SCHEMA-PLAN` · `LOCAL-SEO-PLAN` ·
`CONTENT-GAP` · `KEYWORD-MAP` · `KEYWORD-UNIVERSE.csv` · `SERP-INTENT-MATRIX` · `BACKLINK-GAP` ·
`LINK-ACQUISITION-PLAN` · `SEO-SITE-ARCHITECTURE` · `INTERNAL-LINK-GRAPH` · `PAGE-LEVEL-SEO-BLUEPRINT` ·
`SEO-IMPLEMENTATION-BACKLOG` · `SEO-MEASUREMENT-FRAMEWORK` · `SEO-EXPERIMENT-BACKLOG` ·
`SEO-90-DAY-ROADMAP` · `SEO-12-MONTH-ROADMAP` · `HUMAN-INTERVENTION-MAP` · `SEO-BASELINE` ·
`SITE-INVENTORY` · `CORRECTIONS-AND-ERRATA` · `IMPLEMENTATION-CHANGELOG`

**Evidence base:** ~90 searches across 7 languages · ~40 competitor pages read in full · 64
customer-voice sources · the complete codebase · 15 research agents over 542 tool calls · 3 adversarial
agents that refuted 3 of 3 core claims · 1 independent fact-check audit.
