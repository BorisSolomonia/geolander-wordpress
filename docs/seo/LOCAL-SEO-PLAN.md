# LOCAL-SEO-PLAN.md — Geolander

**Date:** 2026-08-14 · **Tags:** `[OBSERVED]` · `[FIRST-PARTY]` · `[CUSTOMER]` · `[INFERENCE]` · `[UNVERIFIED]`

---

## The single most important sentence in this document

**Google Business Profile is almost certainly a bigger near-term revenue lever for Geolander than
organic ranking is — and it is the one asset I could not inspect at all.**

Two independent research agents reached this conclusion separately `[INFERENCE]`. The reasoning:
"car rental Tbilisi" is textbook local intent, executed by people who are physically in or arriving in
Tbilisi; the local pack sits above organic results; Geolander has a real physical address at
8/5 Vedzini Street; and the domain has effectively zero organic visibility to compete with. A verified,
well-populated, review-rich GBP can produce bookings in weeks. Organic will take months.

**I could not observe the local pack** — the search tool available here is US-locale, and it returned
no map units on any query `[OBSERVED]`. So every local-pack claim below is inference, and the very
first action in this plan is a verification task for Boris, not a recommendation from me.

---

## Part 1 — What I know, and what I had to guess

| Item | Status |
|---|---|
| GBP exists | `[OBSERVED]` — `docs/seo-geo-aeo.md` states NAP was "pulled live from your Maps listing"; `settings.json` holds `office_google_maps_url` = `https://maps.app.goo.gl/WKGqAsFnKuGPK49E7` |
| GBP name | `[OBSERVED]` "Geolander car rental" — and the site's schema `name` is set to match exactly |
| NAP consistency | `[OBSERVED]` **Strong.** Schema, footer, contact page, `/llms.txt` all carry 8/5 Vedzini Street, Tbilisi 0108 and +995 551 33 04 14. Real geo coordinates (41.6980427 / 44.7934697) replaced generic Tbilisi-centre ones — a genuine local-ranking bug that was already caught and fixed |
| `hasMap` → GBP | `[OBSERVED]` present in `AutoRental` schema |
| Hours | `[OBSERVED]` 24/7 in schema and on the contact page |
| **Review count and rating** | **`[UNVERIFIED]`** |
| **Review velocity** | **`[UNVERIFIED]`** |
| **Primary/secondary categories** | **`[UNVERIFIED]`** |
| **Photo count and quality** | **`[UNVERIFIED]`** |
| **Website link set on GBP** | **`[UNVERIFIED]`** |
| **Messaging enabled** | **`[UNVERIFIED]`** |
| **Local-pack position for any query** | **`[UNVERIFIED]`** |
| **Local citations / directory presence** | **`[UNVERIFIED]`** |

**On-site trust signals `[OBSERVED]`:** the contact page displays **no reviews, no ratings, no
testimonials, and no contact form**. The homepage shows a bare "★ 5.0" and three on-site testimonials
with no third-party verification. Compare with the benchmark competitor WeRent, which displays
Trustpilot 4.8 / Google 4.9 / TripAdvisor 4.9 on-page `[OBSERVED]`, and with Localrent, which shows
"4.8 / 5 | 4,509 reviews" with a live Trustpilot link `[OBSERVED]`.

`[INFERENCE]` **This is the widest verifiable gap between Geolander and its closest competitor**, and it
is not a content or technical gap. It is a proof gap.

---

## Part 2 — The structural problem nobody has named yet

### The brand name collides with a global tyre brand

`[OBSERVED]` "Geolander" is a near-homograph of **Yokohama GEOLANDAR**, a worldwide all-terrain SUV
tyre line. The collision is maximally damaging because it sits in the *same semantic space*: SUV, 4×4,
all-terrain, and the exact vehicle models Geolander stocks.

Evidence gathered:

- A search pairing "Geolander" with "Subaru Forester" and "Outlander" returned an Instagram page titled
  *"Geolander Tires On Subaru Forester 17 Inch Wheels"* and a Subaru group post about fitting
  *"Yokohama Geolander"* tyres `[OBSERVED]`
- A **quoted exact-domain search for `"geo-lander.com"`** returned Wikipedia articles on *"Geo
  (landform)"*, *"Lander"*, *"LandSerf"*, and an unrelated South African `geolander.co.za`. No result
  for the actual site `[OBSERVED]`
- The brand query "Geolander car rental Tbilisi" returned georentcar.ge, geocarrental.ge, Localrent,
  Europcar, Enterprise, geodrive.info, Expedia — **competitors outrank Geolander on Geolander's own
  brand name** `[OBSERVED]`

`[INFERENCE]` No amount of content fixes a bare-brand query against a global tyre manufacturer with
decades of entity signal. But that does not mean the situation is hopeless — it means the brand must be
**disambiguated as an entity**, and the compound phrases must be targeted instead of the bare name.

**This is a local-SEO problem before it is anything else**, because entity disambiguation for a physical
local business runs through Google Business Profile, consistent NAP, and third-party corroboration.

---

## Part 3 — The plan

### Phase 0 — Verify (Boris, ~30 minutes, blocks everything else)

Nothing below should be executed until these are known. Open the GBP dashboard and record:

1. Primary category, and all secondary categories
2. Total reviews, average rating, and the **dates of the last 10 reviews** (velocity matters more than
   total)
3. Photo count, split into: real fleet vehicles / office / interior / customer-submitted
4. Whether the website field points to `https://geo-lander.com` (not a redirect, not a social link)
5. Whether messaging is on, and the current response time
6. Whether the listing is verified, and what the service-area configuration is
7. Whether the business is listed with **any** duplicate profiles

Then, **from a Tbilisi-locale browser or a Georgia VPN**, search: `car rental Tbilisi`,
`rent a car Tbilisi airport`, `4x4 rental Tbilisi`, `аренда авто Тбилиси`, `მანქანის ქირაობა თბილისი`.
Screenshot the local pack for each. **Record who is in it.** This is the single most valuable
30 minutes available in this entire engagement, and I cannot do it from here.

### Phase 1 — Category and completeness (week 1)

`[FIRST-PARTY]` Google's own guidance is to use the most specific category available.

- **Primary category: "Car rental agency."** If it is currently anything else — "Car dealer",
  "Transportation service", "Travel agency" — that is a first-order ranking bug, and fixing it is the
  highest-leverage single action available.
- Secondary categories worth considering, only where genuinely true: "Van rental agency" (no — no vans),
  "Truck rental agency" (no), "Airport shuttle service" (only if you actually run one; free *delivery*
  is not a shuttle service). `[INFERENCE]` **Resist the temptation to add categories you do not serve.**
  Category spam is a known suppression trigger and the fleet is 15 cars — there is nothing to gain.
- Complete every field: services, attributes, opening hours (24/7 — verify this is genuinely true, and
  if it is not, **say what is true instead**; a 24/7 claim that fails at 2am is a one-star review).
- Set the website link to `https://geo-lander.com/` and add a UTM so GBP traffic is attributable in
  GA4 (see `SEO-MEASUREMENT-FRAMEWORK.md`).

### Phase 2 — Photographs (week 1–2, high leverage, currently free)

`[OBSERVED]` The repo contains `_migration/fleet-import/` folders holding **real, high-resolution
photographs of the actual fleet** — many at 15–35 MB per image. Geolander has already done the
expensive part.

- Upload real photos of **every vehicle**, plus the office at 8/5 Vedzini Street, plus the exterior and
  street view, plus cars actually parked at Tbilisi airport arrivals if that is where handover happens
- `[INFERENCE]` Photos of vehicles **in Georgian mountain settings** — a Forester at Gergeti, an
  Outlander on the Military Highway — do double duty: they populate the GBP and they are the exact
  proof asset the website is missing
- The homepage promise is *"Real cars, real photos — the exact car you book is the one you get"*
  `[OBSERVED]`. The GBP should demonstrate that claim, not just the website

### Phase 3 — Reviews (ongoing; this is the compounding asset)

`[CUSTOMER]` Real traveller language collected in this research, from TripAdvisor threads about
Georgian car rental:

> *"The car was new, clean and they delivered it to the airport with no deposit required."*
> *"Full insurance was included and the staff was very helpful."*
> *"Smooth pickup, good condition Toyota and responsive WhatsApp support"*
> *"Always record a video when you pick up the car"*
> *"Check the cancellation policy carefully. Some companies are strict if your flight is delayed."*

`[INFERENCE]` These are not Geolander's marketing claims — they are the words travellers use when
recommending a winner, and **Geolander's stated offer matches them almost line for line**. The offer is
not the problem. Verifiable proof of the offer is the problem.

**The process:**

- After every completed rental, send the GBP review link via WhatsApp — the channel the customer is
  already in `[OBSERVED]` the booking flow ends in WhatsApp, so this is a one-line addition to an
  existing conversation
- Ask for specifics, not stars: *"If you have a moment, it helps enormously if you mention where you
  drove and how the car handled it."* `[INFERENCE]` Reviews naming Kazbegi, Gudauri, Svaneti and
  Tusheti build the exact topical association the whole strategy depends on
- **Respond to every review**, positive and negative, in the reviewer's language where possible
- **Never solicit selectively, never incentivise, never fabricate.** Beyond the obvious ethics: review
  gating is against Google's policies and the downside is losing the asset entirely

**Target `[ESTIMATE]`:** Localrent shows 4,509 reviews and WeRent shows three platforms at 4.8–4.9.
Geolander will not close that gap. It does not need to — it needs **enough** reviews with **recent
dates** and **specific content** to clear the local-pack credibility threshold. Twenty genuine,
detailed, recent reviews would transform the listing's competitiveness relative to where it appears to
be now. That is roughly one month of ordinary rentals.

### Phase 4 — Third-party corroboration (weeks 2–8)

This is where local SEO and entity disambiguation converge — and where the tyre-brand collision gets
solved. Each of these is a place the *entity* "Geolander, car rental, Tbilisi" gets asserted by someone
other than Geolander.

**Ranked by value per unit of effort:**

| Target | Why | Effort |
|---|---|---|
| **Localrent listing** | `[OBSERVED]` The dominant aggregator in this market, recommended first by the highest-authority independent publisher in the niche. It lists *local Georgian operators*, so it is a **distribution channel, not purely a competitor**. Geolander's absence is a straightforward missed listing. Costs commission — but also produces entity mentions AI systems and Google see | Low |
| **TripAdvisor presence** | `[OBSERVED]` TripAdvisor appeared on more research queries than any other domain and is where real purchase decisions get made. Geolander appears in **no** thread read during this research | Low |
| **ountravela.com "best 4×4 rental agencies" listicle** | `[OBSERVED]` Ranked on four separate 4×4 queries; actively maintained (last modified July 2026); lists operators **by owner first name** with fleet and price. Its inclusion bar is relationship and reliability, **not domain authority** — so it is reachable by a polite email rather than by link-building. Listed operators run Foresters and Outlanders at €50–85/day, i.e. exactly Geolander's fleet at roughly double the price | Low |
| **wander-lush.org** | `[OBSERVED]` ~15,000-word driving guide, 105 reader comments, the only independent publisher ranking on the head term. It is a monetised referral gateway with an explicit affiliate disclosure — **placement is commercially negotiable** | Medium |
| Georgian business directories, tourism boards, hotel/guesthouse partnerships | Standard citation building; also genuine referral sources | Medium |

Full treatment in `LINK-ACQUISITION-PLAN.md`. Listed here because for a local business, **citations and
local partnerships are local SEO** — they are not a separate discipline.

### Phase 5 — On-site local signals (weeks 2–4)

- **Display third-party review proof on-site.** Not the bare "★ 5.0" currently shown `[OBSERVED]`, but
  the actual Google rating with a link to the GBP. `[FIRST-PARTY]` Do **not** wrap it in
  `aggregateRating` schema on your own business — Google's LocalBusiness documentation states that
  property is *"only recommended for sites that capture reviews about other local businesses"*
  (see `SCHEMA-PLAN.md` F-3)
- **Add a contact form to `/contact/`** — `[OBSERVED]` there is currently none, only a phone number and
  a WhatsApp link. Not every visitor will open WhatsApp, and some markets distrust it
- **Embed the actual map** on the contact page rather than linking out
- **Add the four delivery cities to the `AutoRental` `areaServed`** (see `SCHEMA-PLAN.md`)
- **Add airport entities with IATA codes** — TBS, BUS, KUT — to the city pages `[OBSERVED]` the data is
  already stored in `glc_airport_code` and simply unused

---

## Part 4 — What NOT to do

The brief forbids fake locations and doorway pages, and this market makes both tempting:

- **Do not create city pages for cities you do not genuinely serve.** Four exist (Tbilisi, Batumi,
  Kutaisi, Kobuleti). Adding Mestia, Telavi, Sighnaghi, Gudauri and Borjomi *because they are search
  terms* is exactly the doorway pattern. Add a city when you genuinely deliver there and have something
  specific to say — see `CONTENT-GAP.md`
- **Do not create additional GBP listings for delivery cities.** `[FIRST-PARTY]` Google requires a
  staffed physical location at the listed address. A delivery radius is a service area, configured on
  the *existing* listing — not a second profile. Fake location listings get businesses suspended
- **Do not buy reviews, gate reviews, or incentivise them.** The asymmetry is brutal: small short-term
  gain, total loss of the listing as the downside
- **Do not stuff the GBP business name** ("Geolander Car Rental Tbilisi 4x4 Airport"). It violates
  guidelines, and it breaks the exact NAP match that the schema was carefully built to maintain
- **Do not add categories you do not serve** to widen coverage

---

## Part 5 — How to measure it

| Metric | Where | Cadence |
|---|---|---|
| GBP views (search vs maps) | GBP Insights | Monthly |
| GBP → website clicks | GBP Insights + UTM in GA4 | Monthly |
| GBP calls, direction requests, messages | GBP Insights | Monthly |
| Review count, average rating, **velocity** | GBP | Weekly |
| Local-pack position, 5 core queries, Tbilisi locale | Manual screenshot, or a local rank tracker | Monthly |
| Queries triggering the listing | GBP Insights search-terms report | Monthly |
| Third-party mentions of "Geolander" + "car rental" | Manual search | Monthly |

**The one that matters most `[INFERENCE]`:** review velocity. It is the metric most under Geolander's
direct control, the one competitors cannot copy, the one that most influences local-pack ranking, and
the one that most influences whether a nervous traveller who has read deposit horror stories chooses
this company. It also feeds AI recommendation systems, which lean heavily on third-party review
consensus.

---

## Priority order

1. **Verify the GBP state and observe the local pack from Tbilisi** — blocks everything (30 min)
2. **Fix the primary category if wrong** (5 min, potentially the highest-ROI action in this document)
3. **Upload real fleet and premises photos** — the assets already exist in the repo (2 h)
4. **Start the post-rental WhatsApp review request** — every rental, from tomorrow (ongoing)
5. **Get listed on Localrent and TripAdvisor** (a few hours)
6. **Approach ountravela.com and wander-lush.org** (a few hours, see `LINK-ACQUISITION-PLAN.md`)
7. **On-site: third-party review proof, contact form, embedded map** (1 day)

Items 1–4 need no developer, no content writing, and no ranking risk. `[INFERENCE]` They are plausibly
worth more in the next 90 days than everything else in this engagement combined — which is an
uncomfortable thing for an SEO strategy to say, and is said anyway because the evidence points there.

---

## Sources

- Repository: `_migration/settings.json`, `docs/seo-geo-aeo.md`, `class-glc-schema.php`, `class-glc-city.php`
- [geo-lander.com/contact/](https://geo-lander.com/contact/) (fetched 2026-08-14)
- [Google Search Central — Local business structured data](https://developers.google.com/search/docs/appearance/structured-data/local-business)
- [Localrent — Tbilisi](https://www.localrent.com/en/georgia/tbilisi/) · [Localrent — Georgia](https://www.localrent.com/en/georgia/)
- [wander-lush.org — Renting a Car in Tbilisi & Driving in Georgia](https://wander-lush.org/driving-in-georgia-car-rental-tbilisi/)
- [TripAdvisor — Best car rental company in Tbilisi for a 10-day road trip](https://www.tripadvisor.com/ShowTopic-g294194-i9343-k15560961-Best_car_rental_company_in_Tbilisi_for_a_10_day_road_trip-Georgia.html)
- [TripAdvisor — Our experience with self-drive car rental in Georgia](https://www.tripadvisor.in/ShowTopic-g294194-i9343-k8994075-Our_experience_with_self_drive_car_rental_in_Georgia-Georgia.html)
