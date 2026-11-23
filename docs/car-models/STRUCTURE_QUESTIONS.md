---
provenance: da
created: 2026-11-05
feature: car_model entity above the existing car (plate) post type
status: WAITING FOR BORIS — nothing is built until these are answered
source_request: SEO manager, 2026-11-05, item 1 of 4 ("Car Model Management GUI")
boris_decision: 2026-11-05 — chose "Model pages above cars (SEO play)" over a better plate admin
---

# Structure questions — car models

> **Why this file exists.** Boris, 2026-09-17: *"my main flow is to have structures first; for example
> 9 tones was built incorrectly by you and then I spent a lot to correct it… when there is a new
> project you must provide the list for these questions and I will answer them, after we move."*
>
> This is a **schema change on a live, indexed site**. 19 car pages are currently ranking at
> `/fleet/<slug>/`, partner pricing and specs hang off each plate, and the LocalRent price sync
> writes per plate. Getting the shape wrong here costs re-indexing time, not just code.
>
> Research and reading continue. **No code until you answer.**

## How to answer

Write after **A:**. `skip` = not relevant, `later` = decide later (I build nothing that depends on it).
Where I wrote **Recommended**, you can just write `yes` and I take that option.

---

## What I already know (do not re-answer these)

Established from the code and the LocalRent captures, 2026-11-05 — no question needed:

| Fact | Evidence |
|---|---|
| One `car` post = one physical plate, identified by `glc_registration` | `class-glc-cpt.php:25`, `:112` |
| Car pages live at `/fleet/<slug>/` | `class-glc-cpt.php:34` (`'slug' => 'fleet'`) |
| `car_brand` (`/brand/`) and `car_body_type` (`/body-type/`) taxonomies already exist | `class-glc-cpt.php:41`, `:50` |
| Specs, pricing, fees and photos are saved **per plate**, by partners | `class-glc-partner-car.php:39-43` |
| Per-plate meta includes year, colour, seats, transmission, drivetrain, fuel | `class-glc-cpt.php:112-122` |
| 7 locales (en, ka, ru, fr, uk, ar, zh); city page bodies in uk/ar/zh are still English | `class-glc-i18n.php`; prior session note |
| Keyword-slug landing pages already work this way for cities | `class-glc-city.php` — the pattern to copy |

### The fleet as it actually is (19 plates, LocalRent capture 2026-11-03)

Strip the year from the name and **8 models cover all 19 plates**:

| Model | Plates | Years present |
|---|---|---|
| Subaru Forester | **7** | 2014, 2016, 2017, 2017, 2019, 2020, 2023 |
| Mitsubishi Outlander | **4** | 2016, 2018, 2021, 2021 |
| Jeep Wrangler | 2 | 2017, 2017 |
| Subaru Crosstrek | 2 | 2017, 2021 |
| Toyota Rav 4 | 1 | 2016 |
| Toyota Highlander | 1 | 2017 |
| Toyota 4Runner | 1 | 2021 |
| Jeep Renegade | 1 | 2017 |

This is the whole SEO case: **8 strong pages instead of 19 thin ones.** It is also why M1 below is the
question everything else depends on.

---

## M. The model entity

### M1 — Does a model include the year, or not? (decides everything else)

This is the one question that changes the entire build.

- **Without year** → 8 models. `/rent/subaru-forester/` is one page covering 7 cars from 2014 to
  2023. Strongest ranking signal, but the page must honestly handle a 9-year spread in age, price
  and condition.
- **With year** → 16 models. `/rent/subaru-forester-2017/` has 2 cars; eleven models have exactly 1.
  Barely different from the 19 plate pages we already have, so it buys almost no SEO.

**Recommended: without the year.** A renter searches "Subaru Forester rental Tbilisi", not "Forester
2014 rental". The year belongs on the plate, shown as a range on the model page ("2014–2023 available").

**A: INCLUDE THE YEAR** (Boris, 2026-11-05). My recommendation was declined. Defensible: price and
condition differ sharply across a 2014 and a 2023 Forester, and LocalRent itself names cars with the
year, so the trade thinks in year terms. Consequence recorded in M1a below.

### M1a — The arithmetic that M1 + M2 together produce (raised 2026-11-05, needs one decision)

Year-in-model gives **16 models for 19 plates**. Only three have more than one car:

| Model (with year) | Plates |
|---|---|
| Subaru Forester 2017 | BC-402-CC, JZ602ZJ |
| Jeep Wrangler 2017 | DE-300-JO, YS-105-SY |
| Mitsubishi Outlander 2021 | IW-355-IW, LC-235-LL |
| *the other 13 models* | **1 plate each** |

So 13 of 16 model pages would describe exactly one car — and under M2 that car's own plate page
canonicals to them. A model page holding one car *is* the plate page, with a second URL. That removes
the reason the model layer was wanted: it does not consolidate 19 thin pages, it adds 16 more.

Three shapes resolve it. See the answer below for Boris's pick.

**A: HUB + YEAR SPOKES** (Boris, 2026-11-05). Settled structure:

```
car_model  "Subaru Forester"          /rent/subaru-forester/          hub, 7 plates
  └ car_model  "Subaru Forester 2017" /rent/subaru-forester-2017/     spoke, 2 plates
      ├ car  BC-402-CC                /fleet/<slug>/  canonical → the spoke
      └ car  JZ602ZJ                  /fleet/<slug>/  canonical → the spoke
  └ (2014, 2016, 2019, 2020, 2023 have one plate each — no spoke; those
     plate pages canonical straight to the HUB)
```

Rules that follow, and that the build must honour:

1. A **hub** is yearless and always exists, one per make+model. 8 hubs.
2. A **spoke** is created only where it earns one: 2+ plates of that model-year, or Boris names it
   for a search term. 3 spokes at launch (Forester 2017, Wrangler 2017, Outlander 2021).
3. A plate's `rel=canonical` points at its **spoke if one exists, else its hub**. Never at a page
   that describes a single car, which is what M1a was raised to avoid.
4. Whether a year earns a spoke is **data, not code** — a flag on the model, so Boris or Nika can
   promote a year without a deploy.
5. Spoke count is expected to change as the fleet changes; nothing may assume 8 and 3.

### M2 — What happens to the 19 live `/fleet/<plate>/` URLs?

They are indexed today. Three options, and this one is hard to walk back:

1. **301 every plate page to its model page.** Link equity consolidates into 8 strong pages. The
   plate pages stop existing publicly; inventory becomes admin-only.
2. **Keep plate pages, add `rel=canonical` to the model.** Both URLs resolve; Google is told the
   model is the real one. Safer, reversible, but 19 near-duplicate pages stay crawlable.
3. **Keep both fully indexed.** Risks thin/duplicate content across 19 pages that differ only by
   year and colour.

**Recommended: option 2 for the first month, then 1.** Canonical first means nothing breaks while we
watch Search Console; once the model pages are indexed and ranking, the 301 consolidates. There are
already legacy `/fleet/<uuid>/` 301s in place, so the redirect machinery exists.

**A: OPTION 2** — keep the plate pages, add `rel=canonical` to the model (Boris, 2026-11-05). Nothing
breaks while Search Console is watched. Whether we later move to the 301 stays open.

### M3 — What URL path for a model page?

`/brand/` and `/body-type/` are already taken by the taxonomies. Candidates: `/rent/<model>/`,
`/cars/<model>/`, `/4x4/<model>/`, or keeping the fleet space as `/fleet/<model>/` with plates moved
somewhere else.

**Recommended: `/rent/<model>/`** — it puts a commercial-intent word in the path, which is what the
city pages do (`/car-rental-batumi/`), and it leaves `/fleet/` free for the plate-level pages during
the canonical phase in M2.

**A:**

### M4 — A new post type, or the `car_brand` taxonomy you already have?

A real approach fork, because a taxonomy term page is already a rankable archive:

| Option | Gets you | Costs |
|---|---|---|
| **New `car_model` post type** (recommended) | Full editor, gallery, per-locale SEO body, schema.org `Car`, its own meta | One more CPT; a migration to link 19 plates |
| Extend `car_brand` taxonomy | Nearly free; archive pages exist | Terms hold no gallery or rich body well; brand ≠ model (Subaru has Forester *and* Crosstrek) |
| A third taxonomy `car_model` | Cheap | Same content-thinness problem; weak for ranking |

**Recommended: a `car_model` post type**, because the point of the exercise is a page with real
content on it, and a taxonomy term is a list, not a page.

**A:**

### M5 — Specs: do they live on the model, the plate, or both?

Partners already edit specs per plate (`class-glc-partner-car.php:285`). Seats, transmission and
drivetrain are almost certainly the same across 7 Foresters; year and colour are not.

So: which fields move up to the model, which stay on the plate, and **when they disagree, which wins**?

**Recommended:** model holds the shared spec (seats, body type, drivetrain, fuel, licence category)
as the default; the plate holds year, colour, registration, and may override any model spec, keeping
the model value visible as the default — your standing "document value is a default, not a fact" rule.

**A:**

### M6 — What price does a model page show, when its 7 plates have 7 prices?

The LocalRent sync writes prices **per plate**, and a 2014 Forester is not priced like a 2023 one.

Options: cheapest plate ("from ₾X"), a range ("₾X–₾Y"), or a price typed on the model by hand.

**Recommended: derived, never stored** — "from ₾X" computed from the cheapest available plate at
render time. A number typed on the model goes stale the next time the LocalRent sync runs, and you
have an explicit rule against that.

**A: LOWEST PRICE, BY DEFAULT** (Boris, 2026-11-05). So: the hub and spoke headline is "from ₾X"
where X is the cheapest **available** plate, computed at render time and never stored; and the plate
list on the page is sorted cheapest first by default. "By default" is read as a default, not a lock,
so the sort stays changeable without a deploy.

### M7 — What does a model page show when every one of its plates is booked or unavailable?

Your standing rule: the empty screen *is* the specification, never silently hide the requirement.

Options: show the page with "not available for these dates, here is what is" plus alternatives; show
it with no price; or `noindex` it while empty (bad — it is indexed, and flapping noindex hurts).

**Recommended:** page always renders with its content and specs, the price block says no availability
for the chosen dates, and it offers the nearest available models. Never 404, never noindex.

**A:**

### M8 — Who writes the model SEO copy, and in which of the 7 locales?

8 models × 7 locales = 56 bodies. City page bodies in uk/ar/zh are still English today, so this debt
is already real and I do not want to double it silently.

Options: en + ka now and the rest English until translated (current city behaviour); en only; all 7
up front; or machine-translate with a human pass.

**Recommended: en + ka written properly now**, other locales fall back to en with the existing
i18n fallback, and a visible admin list of what is untranslated. Is Nika writing these?

**A:**

### M9 — Do partner-owned plates appear on your model pages?

Partner cars already have their own pricing, fees and photo pipeline. A `/rent/subaru-forester/` page
that lists 7 plates may be mixing your cars with a partner's.

Does the model page show every plate of that model regardless of owner, only yours, or yours first
with partner cars as fallback? This is a commercial question, not a technical one.

**A: not answered after two asks, so I picked a default rather than ask a third time** (DA,
2026-11-05, reversible): a model page lists **every plate of that model regardless of owner**,
sorted cheapest first per M6. Reasoning: booking runs through WhatsApp, so you still choose which
car you actually offer, and hiding a partner's car would weaken the page for no commercial gain.
One line of config flips it to yours-only. Say the word if that is wrong and nothing has to be
rebuilt to change it.

### M10 — When a model is renamed or a plate moves model, what happens?

Renaming "Toyota Rav 4" to "Toyota RAV4" changes a live, indexed URL.

**Recommended:** the model slug is set once and never auto-follows the title; renaming the title
leaves the URL alone, and changing the URL deliberately writes a 301 from the old one (the redirect
table already exists in `class-glc-redirects.php`). Past bookings keep pointing at the plate, which
never moves.

**A:**

---

## A. Purpose and people

### A1 — What job does this exist for?
**What I have:** rank for "<model> rental Tbilisi/Georgia" queries and give AI answer engines a clean
per-model entity, while plates stay as inventory. Pre-filled from the SEO manager's note and your
choice of 2026-11-05 — correct it if that is not the goal.
**A:**

### A2 — Who creates and edits models, and what may they change?
**What I have:** Nika gets a content-only role (your answer, 2026-11-05): cars, cities, landing
pages, news. Does that include creating models and editing their SEO copy? Do partners ever touch a
model, or only their own plates?
**A:**

### A3 — What is explicitly NOT part of this?
**What I have:** my assumption — no change to booking, payments, quote logic or the LocalRent price
sync; no change to the srcset/image ladders; plate-level pricing stays where it is.
**A:**

### A4 — What does done look like for version 1, and how do you check it?
**What I have:** my proposal — 8 model pages live, each listing its real plates, a derived "from"
price, en+ka copy, schema.org validating, the 19 old URLs still resolving per M2, and Search Console
showing the model pages indexed. Your own check matters more than mine.
**A:**

## C. Entities and structure

### C1 — Confirm the entity list after this change
**What I have:** `car_model` (new, 8 rows) → `car` (19 plates) → `glc_pricing` per plate, plus the
existing `city`, `place`, `news`, landing pages, partner records.
**A:**

### C2 — Can one plate belong to two models, or a model to a parent model?
**What I have:** my assumption — one plate, exactly one model; no model hierarchy. A trim level
("Forester XT") would be a separate model, not a child. Say if you want trims.
**A:**

### C3 — What identifies a model uniquely, and what happens on a duplicate?
**What I have:** my proposal — the slug is the identity (`subaru-forester`); brand + model name must
be unique; creating a second "Subaru Forester" is refused with a link to the existing one.
**A:**

## D. Time

### D1 — What about a model do you need to see change over time?
Candidates: how many plates it has, its cheapest price, its bookings and revenue, its search
position. Which of these do you actually want on screen?
**A:**

### D2 — When a plate leaves the fleet, what happens to its model page?
**What I have:** my proposal — the model page stays (it holds the rankings) and shows the remaining
plates; a model with zero plates goes to draft, not deleted, and its URL 301s to the body-type page.
Deleting anything asks you first.
**A:**

## E. Rules and configuration

### E1 — Confirm what must be editable without a deploy
**What I have:** per your standing rule, all of it — the URL path prefix from M3, the template
choice, which specs are model-level vs plate-level, the "from price" basis from M6, the fallback
locale behaviour, and every user-facing string through the existing admin-strings layer. Flag
anything you would rather have fixed in code.
**A:**

## G. Access

### G1 — This is your own business data (owned), correct?
**What I have:** `PROJECTS.md` says geo-lander.com is owned by you. Partner car data belongs to the
partner, so M9's answer decides whether a partner's plate is published on your page.
**A:**

## H. Delivery

### H1 — Stack: no change, correct?
**What I have:** WordPress plugin `geolander-core` + block theme, Docker on the Oracle VM
(89.168.113.238), Cloudflare in front. Built locally, uncommitted, you deploy.
**A:**

### H3 — When does this need to be live?
**What I have:** nothing. Model pages take weeks to index, so the answer changes whether we ship all
8 at once or start with Forester and Outlander (11 of the 19 plates) as a tracer bullet.
**A:**

---

## Status, 2026-11-05

**Settled by Boris:** M1 (year included), M1a (hub + year spokes), M2 (canonical, not 301).
**Taken as recommended unless he says otherwise:** M3 (`/rent/<model>/`), M4 (a `car_model` post type).
**Still genuinely open, and I will not assume M9:** M5 spec precedence · M6 derived price · M7 empty
state · M8 which locales · **M9 whether a partner's plate is published on Boris's model page** ·
M10 rename policy · sections A2, D1, D2, H3.

M5–M8 and M10 have recommendations in place and are cheap to change later; none of them is a schema
decision. M9 is commercial, not technical, so it waits for him.

## Sequencing constraint (why the build has not started)

A separate build agent is currently working in the **same rewrite space** — generalising
`class-glc-landings.php` into a reusable landing-page template, which is exactly the machinery model
pages need. Two builders editing the same rewrite rules in one working tree is how 9T F26 shipped
code without its table (OPERATIONAL_RULES: *shared resources are assigned once, at spawn*).

So the car-model build starts **after** that agent lands, and reuses its template mechanism rather
than adding a second parallel one.

## The tracer bullet, when it starts

Not the whole thing: **Subaru Forester end to end** — the hub post with its 7 plates linked, the 2017
spoke with its 2 plates, a derived "from" price, `/rent/subaru-forester/` and
`/rent/subaru-forester-2017/` both returning 200 with valid schema.org, and all 7 plate pages still
resolving with their canonical pointing at the right level per rule 3 above. That slice tests the
riskiest assumption: that hub, spoke, plate and the existing city and landing rewrites can coexist
without one capturing another's URL. Only once it holds do the other 7 hubs get generated.
