# CUSTOMER-SEARCH-INTELLIGENCE.md — Voice of Customer library

**Date:** 2026-08-14 · **Method:** two parallel research agents mined **64 sources** — TripAdvisor
Georgia/Tbilisi/Kutaisi forum threads, Google/Trustpilot/DiscoverCars/QEEQ/Revieweek review sets,
travel blogs, aggregator policy pages, and operator terms — extracting **verbatim** traveller language.

**Tags:** `[CUSTOMER]` verbatim from a real person · `[OBSERVED]` seen on a page ·
`[INFERENCE]` · `[ESTIMATE]`

---

## Method limits — stated first

- **Reddit is unreachable from this environment.** `reddit.com`, `old.reddit.com` and `api.reddit.com`
  are all blocked at the egress gateway (proxy returned 403 to CONNECT). **There is no Reddit data in
  this document.** The brief asked for it; it could not be obtained. Given r/Sakartvelo and r/travel
  carry real discussion of this topic, **this is a genuine gap** and worth a manual pass.
- **Web search is US-locale**, which repeatedly returned Georgia-the-US-state results and forced agents
  to fetch named Georgian pages directly instead.
- **Blocked or failed fetches, named rather than guessed:** `forum.awd.ru` (404 — no verbatim *Russian*
  forum language about the Omalo road), `otzovik.com` (timeout — no Russian complaint data),
  `zhuanlan.zhihu.com` (robots-disallowed — no verbatim Chinese language), PissedConsumer
  (CAPTCHA-walled — complaint counts visible, text not readable), Trustpilot direct.
- **No frequency figure here is a measured market statistic.** "Dominant" means "appeared in N of the
  sources actually read", and N is stated.

---

## The six findings that overturn standard car-rental SEO assumptions

Presented first because they change what the whole strategy should be.

### 1. Price is almost absent from the conversation

`[OBSERVED]` Across 14 forum threads and ~8 blog/review sources, one agent recorded **almost no
price-shopping discussion at all**. Travellers argue about permitted roads, deposits, insurance voiding
and car age. The only price sentiments observed were incidental.

`[INFERENCE]` Standard car-rental SEO builds price and comparison pages. **In this market the decision
criteria are legal permission and trust.** Geolander's $26–120/day range is not the story — and
competing on price against Localrent's $19 SUV tile would be fighting on the one axis buyers barely
discuss.

### 2. The product category is partly illegal to use as advertised

The whole point of renting an SUV in Georgia is the mountains — **and the mountains are exactly where
rental contracts forbid you to go**, enforced by GPS.

> `[CUSTOMER]` *"For your safety, rental cars are equipped with GPS trackers. The rental company can
> block the car when it enters a forbidden area."* — localrent.com journal
>
> `[CUSTOMER]` *"Driving on any of these roads with a standard rental – even a 4WD – can void your
> insurance. Many companies use GPS trackers to check your movements."* — Emily Lush, wander-lush.org
>
> `[CUSTOMER]` *"I tried to rent a 4x4 with Localrent but noticed the fine prints of not allowing their
> car to Omalo and Tusheti"* — opening post, TripAdvisor thread *"Car rental company forbidden routes"*

`[INFERENCE]` Neither agent had seen this dynamic in any other rental market. **A 100%-4×4 fleet
selling into a market where 4×4 use is contractually restricted is either Geolander's biggest risk or
its biggest opportunity — depending entirely on whether it publishes a clearer permission policy than
everyone else.**

### 3. Customers shopping 4×4 in Georgia are shopping for *permission*, not for specs

The proof is a competitor comparison page. `[OBSERVED]` On ountravela.com's ranked list of Georgian 4×4
agencies, **the decisive line in each entry is a permission statement, not a specification**:

| Operator | The line that decides it `[OBSERVED]` |
|---|---|
| Temo | *"Driving in Tusheti and Vashlovani is authorized"* |
| Nick | *"Driving prohibited at Tusheti and in Vashlovani"* |
| Martyna | *"Tusheti authorized only for Toyota 4runner and Tacoma models"* |

`[OBSERVED]` **Geolander is not mentioned on that page at all** — it is absent from the single page that
decides this category.

And travellers reward permission explicitly:

> `[CUSTOMER]` *"Highly recommended but not really cheap company for 4WD Cars **which allows driving to
> Tusheti**"* — worldcitizen1961, TripAdvisor

### 4. A road got better and the contracts didn't notice — and travellers are hunting for whoever fixes it first

`[OBSERVED]` The **Mestia–Ushguli–Lentekhi** road was sealed in 2024 and *"can now be driven by any
car"* (mrc282) — *"yet most rental car terms still forbid driving on this road using normal cars."*
A poster is openly shopping for *"rental companies with updated terms at reasonable rates."*

`[INFERENCE]` A named, dated, explicitly unserved demand signal is the rarest thing in keyword research.
**Verify the current road and insurance status before acting on it** — but if it holds, this is a page
that writes itself.

### 5. Nobody fears crime

`[OBSERVED]` **Not one poster in any thread raised theft, carjacking or personal safety** — despite
*"is it safe"* being the literal title of a thread. The fear is entirely other drivers, cows and cliffs.

> `[CUSTOMER]` *"Georgian drivers do many risky maneuver, like overtaking on the curve while going
> upwards in the mountains"* — Miskoff, Warsaw
>
> `[CUSTOMER]` *"Right of way (including on roundabouts) goes to the biggest vehicle."* /
> *"Drivers hate stopping. Slowing down seems to be viewed as a sign of weakness."* — wander-lush.org

`[INFERENCE]` Any "Is Georgia safe?" content that discusses crime is answering a question nobody asked.

### 6. The market's trusted voice is one person, not a brand

`[OBSERVED]` **Emily Lush (wander-lush.org)** is simultaneously the top-ranking blog on the head term,
the resident expert on the TripAdvisor Georgia forum (440+ posts, answering most car-rental threads),
and the de facto arbiter of which company to use. She recommends **Localrent** and **Martyna z Gruzji**
by name.

`[INFERENCE]` **Discovery in this market is creator-mediated, not SERP-mediated.** A near-zero-authority
challenger will get further from one credible relationship, or one genuinely superior reference asset
that she and others cite, than from twenty thin location pages.

**And the shortcut is closed `[OBSERVED]`:** TripAdvisor actively removes self-promotional replies — one
was seen deleted mid-thread. Forum astroturfing is not viable.

**Related, and instructive `[OBSERVED]`:** in the thread *"Best car rental company in Tbilisi for a
10-day road trip?"*, four posters praised one competitor in quick succession with brand-brief-sounding
language. `[INFERENCE]` — possibly coordinated; **not provable**. Two takeaways: the channel matters
enough that competitors work it, and the claims they chose to plant — *no deposit, full insurance,
WhatsApp support, airport delivery* — independently confirm what buyers want. They are nearly identical
to Geolander's real inclusions. **Geolander's advantage is that it can substantiate them instead of
planting them.**

---

## The Voice of Customer library

Ordered by commercial value. Each theme carries verbatim language, observed frequency, journey stage,
and the content opportunity.

### VOC-1 · Forbidden roads void your insurance — **the flagship opportunity**

**Frequency:** dominant — 6 of 14 forum threads, the entire subject of 2, plus standalone articles by
both wander-lush.org and localrent.com `[OBSERVED]`
**Stage:** consideration / pre-booking. *High intent, high anxiety, and they are choosing a supplier on
this criterion.*

> `[CUSTOMER]` *"be extremely careful concerning renting car from a local company. We had very bad
> experiences with Mimino Rent Car, Tbilisi - they requested USD100 cash deposit which was not returned
> due to the reason we did not travel on 'approved' roads"* — OndrejDubai, TripAdvisor
>
> `[CUSTOMER]` *"Insurance does not cover these routes, and any damage or accident is the responsibility
> of the renter."* — localrent.com
>
> `[CUSTOMER]` *"It's also worth asking them directly as I know a couple of people who were able to get
> permission despite the T&Cs."* — Emily @ Wander-Lush, TripAdvisor

**The contested map, corroborated by two independent sources** (localrent.com and roadiscalling)
`[OBSERVED]`:

Routes — Akhaltsikhe–Batumi via **Goderdzi Pass** · **Mestia–Ushguli–Lentekhi** · Sairme–Abastumani
Regions — **Truso** · **Juta** · **Vashlovani** · **Tusheti / Omalo** · **Shatili** / Upper Khevsureti ·
Abkhazia · South Ossetia

**Likely queries:** `can I drive a rental car to tusheti` · `rental car forbidden roads georgia` ·
`mestia ushguli lentekhi road rental car allowed` · `abano pass rental car insurance` ·
`does rental car insurance cover omalo road georgia` · `car rental georgia gps tracker restricted areas` ·
`shatili road rental car` · `juta truso rental car allowed`

**Opportunity:** the definitive **"Where you can and cannot drive a Geolander car"** page — a
road-by-road table crossed against the actual fleet, with three columns: *allowed yes/no*, *which
vehicles*, *insurance status*. State plainly whether Geolander uses GPS trackers.

`[OBSERVED]` **The pages currently answering these queries are an aggregator's journal, two travel blogs
and a niche activity site — not a single rental company.** That is the rare case where a
near-zero-authority challenger can rank.

---

### VOC-2 · "Full insurance" is not full

**Frequency:** dominant — subject of 2 dedicated threads, raised in 7 of 14 sources `[OBSERVED]`
**Stage:** consideration, and post-crash panic mid-trip.

> `[CUSTOMER]` *"I specifically paid good money to get the full insurance with zero excess liability
> insurance."* … *"the insurance company is voiding the insurance because I had caused a serious
> accident."* — Cynhtiab2022, TripAdvisor
>
> `[CUSTOMER]` *"never take out extra insurance with the provider. Either take out a global rental car
> insurance policy back home"* — James C
>
> `[CUSTOMER]` *"I want a car with full insurance, also for third party liability. I saw that Herz only
> covers for 50.000 dollar third person liability which is very little in comparison to the EU where
> insurance for third party liability is up to 5 million euro."* — Johanna v, Groningen

**`[OBSERVED]` The market has inflation-proofed against the phrase.** Competitors advertise concretely:
Temo *"$100 deductible and 10% in the event of total loss. Civil liability: $30,000"*; Nick
*"Comprehensive 100% insurance… zero deductible"*; Martyna *"zero deductible in case of accident, theft
or other damage"*.

**This is a direct liability for Geolander `[OBSERVED]`:** the homepage says *"full insurance included"*
while `/terms/` §2 says *"Basic insurance (CDW) … with a deductible. Additional full coverage available
for an extra fee."* **The exact phrase travellers say betrayed them is the one on the homepage, and the
site's own terms contradict it.**

**Opportunity:** name the excess in GEL/USD. Name the third-party liability limit as a number — one
traveller could not get a straight answer from anyone. Name the exclusions travellers get burned on:
tyres, alloys, windscreen, underbody, roof, interior, single-vehicle accidents, off-contract roads.
State the accident procedure step by step.

---

### VOC-3 · Deposit anxiety and deposit-confiscation stories

**Frequency:** common-to-dominant — the pivot of 5 threads; deposit disputes found across 5 independent
review sources `[OBSERVED]`. **Every single negative review one agent read was a deposit or
post-return-charge story, not an accident story.**

> `[CUSTOMER]` *"At drop-off, their agent showed up 40 minutes late… He said everything was OK and that
> the deposit would be refunded, but afterward the company kept my deposit and created a completely
> false story about fines and damages that never happened."* — Jawed I, 1/5, Sept 2025
>
> `[CUSTOMER]` *"Do not use Naniko… they have tried to charge us for 2 tyres. Completely dishonest and a
> real rip off…AVOID AVOID AVOID."* — BOwen
>
> `[CUSTOMER]` *"Free delivery to hotel…do not require credit card. Cash deposit was returned honestly."*
> — Petr13 — **this is the positive framing travellers look for**

**A note on severity vs frequency `[OBSERVED]`:** TakeCars categorises only ~0.9% of its 1,453 Georgia
reviews as deposit disputes — **but they generate the most extreme language**. `[INFERENCE]` This is a
low-frequency, catastrophic-severity fear, which is exactly the kind that dominates pre-purchase
research. Do not dismiss it on volume.

**`[OBSERVED]` Geolander's site never states a deposit amount anywhere**, while at least six Tbilisi
competitors lead with *"No Deposit / No Credit Card"* in their title tags.

**Opportunity:** an explicit deposit policy page — amount (or zero), currency, cash vs card, exactly
when and how it is returned, and an **enumerated list of the only circumstances under which money is
retained**. The Mimino quote shows the killer mechanism: seizure under a vague "approved roads" clause
the customer never saw. **Invert it by publishing the approved-roads list yourself.**

Pair it with a documented handover protocol: *"we photograph and video the car with you at pickup and
at return, and send you the file on WhatsApp."* `[INFERENCE]` **This turns the WhatsApp-led flow from a
conversion weakness into the trust mechanism — a timestamped WhatsApp thread IS the traveller's
evidence against a fabricated damage claim.** Travellers already advise each other to
`[CUSTOMER]` *"Always record a video when you pick up the car"*. Geolander can do it for them.

---

### VOC-4 · "Do I actually need a 4×4?" — the genuinely contested question

**Frequency:** dominant — in 8 of 14 threads, the entire subject of 3. **And the most *contested*
question**: roughly as many "not necessary" answers as "absolutely necessary" `[OBSERVED]`, which is
precisely why people keep re-asking it.
**Stage:** early consideration — vehicle-class selection, *before* supplier selection.
**Whoever answers this well gets to define the shortlist.**

**For:**
> `[CUSTOMER]` *"100% need a high clearance 4x4. Not a low SUV. Unless going via Zugdidi."* — James Kerwin
> `[CUSTOMER]` *"If you plan to drive to Ushguli or Tusheti you will need 4wd plus high clearance for sure."* — NewJerseyTricia
> `[CUSTOMER]` *"self-driving is definitely possible but a 4x4 with good clearance is a necessity… it's a long drive on dirt roads (4-6+ hours)!"* — AdventureBergs, on Tusheti

**Against:**
> `[CUSTOMER]` *"You do not need a 4x4 car, but need a car with a good clearance which in most of the cases is SUV."*
> `[CUSTOMER]` *"You can use a normal car, like sedan or minivan."*
> `[OBSERVED]` wander-lush.org: *"For most routes in Georgia, a standard sedan is perfectly adequate."*
> `[OBSERVED]` georgia.in-facts.info: *"Летом нет необходимости во внедорожнике"* (in summer there is no need for an SUV)

**`[INFERENCE]` The honest answer is route-dependent — and the honest answer is more persuasive than the
salesy one.** Notice what travellers actually converge on: **clearance**, **winter tyres**, and **driver
experience**. Not "4×4". Match their vocabulary.

**Opportunity — do NOT write "you need a 4×4 in Georgia".** Write the route-by-route arbiter page that
does not currently exist: *"Do you need a 4×4 in Georgia? Honest answer by destination."* Three tiers:

1. **Any car is fine** — Kakheti, Military Highway to Kazbegi, Borjomi, Batumi
2. **High clearance strongly advised** — Mestia–Ushguli, Goderdzi/Zekari, the Gergeti Trinity access track, winter anywhere
3. **Proper 4×4 with clearance, and only with permission** — Tusheti/Abano, Shatili, Truso, Juta, Vashlovani

Then map each tier to specific Geolander vehicles. `[INFERENCE]` A page that tells a customer "you
don't need our most expensive car for this trip" is the highest-trust asset a zero-authority site can
publish.

---

### VOC-5 · Car condition, age, and mid-trip breakdown

**Frequency:** dominant — the **most numerous** complaint category by a wide margin. `[OBSERVED]`
TakeCars' own categorisation of its 1,453 Georgia reviews: **37** car-condition, **33** vehicle-age,
**22** mid-trip mechanical, **15** cleanliness — roughly **107 condition complaints vs 13 deposit
disputes, an 8:1 ratio.**

> `[CUSTOMER]` *"The car they gave me had a dangerously cracked tire, which I have on video. Over the
> week I had 7 puncture repairs and 3 air refills, and a mechanic told me the car was unsafe."*
> `[CUSTOMER]` *"Old broken and scratched car with bad smell"* — Sweden customer, Dollar TBS
> `[CUSTOMER]` *"I could rent a little cheaper from the local company, but my concern was that their cars (SUVs) were way too old - 2002, 2003."* — pva1958

**And the single most strategically important quote in this entire document:**

> `[CUSTOMER]` *"Car was high mileage with lots of scratches **but this was all declared on the paperwork
> at collection** - considering the state of some roads I felt a lot happier."*

`[INFERENCE]` **Pre-declared damage does not lose the sale. It wins trust.**

**Opportunity — and this is where a 15-car fleet beats a 1,000-car one.** A large operator *literally
cannot* publish per-unit odometer and service data. Geolander can: for **each** vehicle, publish year,
actual odometer, last service date, tyre age, and a dated photo set **that includes existing cosmetic
damage**. `[INFERENCE]` This simultaneously solves the thin-vehicle-page problem in
`TECHNICAL-SEO-AUDIT.md` P0-3 — the content that fixes the SEO defect is the same content that closes
the sale.

**Tyres deserve their own section:** they are the number-one reported failure mode *and* the number-one
insurance exclusion.

---

### VOC-6 · Bait-and-switch — the car delivered is not the car booked

**Frequency:** common — 5 distinct sources; one reviewer says *"This happens every time"* `[OBSERVED]`

> `[CUSTOMER]` *"Car we chose on the website is not the same car was given. This happens every time"* — Europcar TBS
> `[CUSTOMER]` RACE customers *"received wrong car model"*, vehicle was *"14 years old"*
> `[CUSTOMER]` *"fake pictures on websites"* — LocalRent criticism

`[INFERENCE]` The aggregator model is the structural cause — you book a *category* or an *"or similar"*,
never a car.

**Opportunity:** Geolander's homepage already promises *"Real cars, real photos — the exact car you book
is the one you get"* `[OBSERVED]`. **That promise is the single best-aimed sentence on the site and it
is currently unsupported.** Make it structurally believable: an explicit anti-"or similar" pledge,
timestamped photos of the specific unit, and a stated remedy if that unit is unavailable (free upgrade
/ full refund). `[INFERENCE]` This converts a perceived weakness — a small fleet with limited choice —
into precisely the thing the market is angry about.

---

### VOC-7 · Airport no-show, long waits, and the flight-delay trap

**Frequency:** common — 6 sources, including 3.5-hour and 2.5-hour waits at Tbilisi airport from two
different international brands `[OBSERVED]`

> `[CUSTOMER]` *"We had to wait 3.5hrs for someone to show up at the airport desk"* — Europcar TBS
> `[CUSTOMER]` *"Check the cancellation policy carefully. Some companies are strict if your flight is delayed."*
> `[CUSTOMER]` **Positive counterexample:** *"The car was waiting for us at the airport, although our flight was 2 hours delayed"*

`[OBSERVED]` One agent flagged the single most actionable positive in the dataset: a five-star Sixt
review where the differentiator was that **the rep sent a photo of his location**.

**Opportunity:** turn "free airport delivery" — currently a generic bullet — into a **provable arrival
protocol**: we track your flight number; if you're delayed we wait at no charge; here is the exact
meeting point at TBS **with a photo**; your driver messages you on WhatsApp with his name, photo and
live location before you land; here is the number answered at 03:00.

`[INFERENCE]` This is the second place where the WhatsApp model becomes a product advantage rather than
a handicap — what customers reward is **a named human reachable in advance**.

---

### VOC-8 · The trust vacuum

**Frequency:** dominant — *"which company should I use"* is the literal title or opening question of
**6 of 14 threads** `[OBSERVED]`

> `[CUSTOMER]` *"Any one way rental options here? Please suggest any car rental services here? **All the reviews on Google are really poor.**"*
> `[CUSTOMER]` *"Avoid STAR car rental in Tbilisi at all cost. Some kind of gang run company."*
> `[CUSTOMER]` *"Local Rent is the best I've found. I use them regularly."* — Emily @ Wander-Lush

**Companies named `[OBSERVED]`** — recommended: Localrent (by far the most), Martyna z Gruzji (for
restricted routes), Enterprise via Auto Europe, GSS Car Rental, Cars4Rent, WeRent, parent.ge,
geocarrent.ge, Caucasus Journeys, City Rent Car, RCT, GL Group. Warned against: **Naniko** (multiple
independent complaints), **Mimino**, **STAR**, **RentCarPlus**, **RACE**.

**`[OBSERVED]` Geolander was named zero times in everything read.**

**Opportunity:** on-site, build the trust page these people are begging for — real named staff, the
physical Tbilisi address with a photograph, company registration details. Off-site, see
`LOCAL-SEO-PLAN.md` and `LINK-ACQUISITION-PLAN.md`.

---

### VOC-9 · "Is it safe to drive in Georgia?" — the top-of-funnel fork

**Frequency:** dominant `[OBSERVED]` · **Stage:** awareness — *before* the traveller has decided to rent
at all. **This is where the market is lost to the hire-a-driver alternative.**

See Finding 5: the fear is drivers, cows and cliffs — never crime.

> `[CUSTOMER]` *"didn't experience a single truly dangerous situation"* — sebhoff, after 6 days self-driving

**Opportunity:** the honest reassurance piece. Lead with the specific detail travellers already repeat
(overtaking on blind curves, right of way to the biggest vehicle, cows, weak headlights, avoid night
driving, start before 9am, **don't drive inside Tbilisi — park and walk**), then resolve it.

---

### VOC-10 · Hire a driver instead — the substitute product

**Frequency:** common — the recommended answer in 4 threads, pushed by Tbilisi tour operators active on
the forum. **This is organised competition, not idle advice** `[OBSERVED]`

> `[CUSTOMER]` *"I strongly recommend to hire a car (with local driver) and not drive yourself as the road is one of the moat dangerous roads"* — TrekGeorgia-Tours, on Tusheti
> `[OBSERVED]` GoTrip described as *"a long-distance Uber, designed specifically for travellers in Georgia"*

**`[OBSERVED]` Price anchor:** 500–600 GEL for a one-way Tbilisi–Omalo driver, versus Geolander's
$26–120/day.

`[INFERENCE]` **Geolander is not really competing with Localrent for a fixed pool of renters. It is
competing with GoTrip and driver-guides for the decision to self-drive at all.**

**Opportunity:** the even-handed comparison — concede that for Tusheti, winter passes and wine-tasting
days a driver is better, **and name GoTrip**. Then own multi-day flexible itineraries, trailheads with
no public transport, Kakheti at your own pace, families with luggage. `[INFERENCE]` A page that
recommends against yourself in two of six scenarios is the highest-trust asset available.

---

### VOC-11 · Cross-border to Armenia and Azerbaijan — a binary filter

**Frequency:** occasional-to-common — one dedicated multi-page thread plus 4 mentions. **Consistently
framed as a dealbreaker that eliminates most local companies and pushes travellers to Avis/Hertz**
`[OBSERVED]`

> `[CUSTOMER]` Niclas C on the paperwork: permission documents *"one copy in Georgian and one in Russian. This was checked carefully both when leaving Georgia (in Guguti) and entering Armenia (in Gogavan)."* Crossing took *"maybe 30-40 minutes… Nothing to pay at the border at all."*
> `[CUSTOMER]` *"only few companies"* offer cross-border — trip4realGEORGIA, recommending international brands

**`[OBSERVED]` Geolander's `/terms/` §6 already permits cross-border with 48 hours' notice** — a
capability the market says is rare, currently buried in a terms page.

**Opportunity:** an extremely low-competition, high-intent page walking through lead time, documents to
email ahead, named crossings (Guguti/Gogavan, Sadakhlo/Bagratashen), the Georgian/Russian permission
copies, insurance, expected delay, fee. Add a clear "Azerbaijan: not permitted" line if that is the
case — travellers report it is harder than Armenia.

---

### VOC-12 · Winter, snow chains and seasonal closures

**Frequency:** occasional — 4 threads, strongly seasonal `[OBSERVED]`

> `[OBSERVED]` wander-lush.org: *"Winter travel requires mandatory winter tires (December 1 – March 1) on mountain roads"* and *"Some high-altitude roads close seasonally (typically late October to May)"*
> `[CUSTOMER]` Abano Pass to Tusheti is *"open only two months in the year"* — Maia_Odisharia

`[INFERENCE]` **Counter-seasonal demand for a business whose peak is summer, and the one season where
"you need a 4×4" is unambiguously true and legally reinforced.**

**Opportunity:** (1) a winter driving page — the Dec 1–Mar 1 winter-tyre mandate, whether Geolander fits
winter tyres as standard **at no charge** (a genuine differentiator if yes), snow chains, the
Gudauri/Kazbegi ski use case. (2) **A road-opening calendar** — which passes open when, updated
annually. `[INFERENCE]` That calendar is exactly the kind of repeatedly-linked reference asset a
zero-authority site can win links with, and it feeds the flagship permissions page.

---

### VOC-13 · Speed cameras, unmarked police, and fines that arrive after you fly home

**Frequency:** occasional-to-common `[OBSERVED]`

> `[CUSTOMER]` *"a dark grey Skoda slowing down permanently till it went so slowly we started wondering what is going on… The dark Skoda was a police car and they had a camera in fro[nt]"* — Anu D
> `[CUSTOMER]` *"We too fined more than 3 times by the Georgian police."* — Niyas K, Doha
> `[CUSTOMER]` **What good looks like:** *"I got some small fines and they sent me full explanation with fees charged from my credit card. When I checked about it a week later then deposit was returned"*

`[INFERENCE]` Intersects directly with VOC-3 — *"the company kept my deposit and created a completely
false story about fines"* is exactly the fear a clear fines policy defuses.

---

### VOC-14 · IDP confusion — the highest-authority sources contradict each other

**Frequency:** rare-to-occasional in forums, **but directly contradictory between the two leading blog
guides** `[OBSERVED]`, which is the interesting part.

> `[OBSERVED]` thewholeworldisaplayground.com: *"all foreign license holders need an International Drivers Permit"*
> `[OBSERVED]` **Contradicted by** wander-lush.org: *"no International Driving Permit requirement for Latin-character licenses"*

`[INFERENCE]` A trip-blocking worry — an IDP must be obtained at home before departure — where the
market's own authorities disagree. **A rental company stating its actual documented requirement, with
the legal basis, resolves a real conflict.** `[OBSERVED]` Geolander's `/llms.txt` currently says an IDP
is "recommended", which is the vague answer travellers are already frustrated by.

---

## Ranked: the five promises that would most reduce a nervous traveller's anxiety

Synthesised across both agents, ranked by how often the underlying fear appears.

| # | The promise | Underlying fear | Evidence |
|---|---|---|---|
| 1 | **"Here is exactly where you may drive, per vehicle, and here is what our insurance covers there."** | Insurance voided on a road I didn't know was forbidden; deposit seized under an "approved roads" clause | VOC-1, VOC-3; 6+ sources |
| 2 | **"Here is our deposit: the amount, the mechanism, the release date, and the only reasons we would keep any of it."** | Deposit seized on a fabricated pretext | VOC-3; every negative review read |
| 3 | **"This exact car — here it is, with its odometer, its service date and photographs of its existing scratches."** | Old/unsafe car; bait-and-switch; blamed for pre-existing damage | VOC-5, VOC-6; ~107 condition complaints in one review set alone |
| 4 | **"Our insurance excess is $X, third-party liability is $Y, and these specific things are excluded."** | "Full insurance" turning out not to be | VOC-2; 7 of 14 sources |
| 5 | **"We track your flight. Here is your driver's name, photo and the exact TBS meeting point. This number is answered at 03:00."** | Nobody there at the airport; charged for a delayed flight | VOC-7; two multi-hour waits recorded |

**`[INFERENCE]` Every one of these is a *policy publication*, not a marketing claim.** None requires
authority, backlinks or ranking to be true. Four of the five require a business decision from Boris
before they can be written — which is why `HUMAN-INTERVENTION-MAP.md` matters more here than in a
typical SEO engagement.

**And the uncomfortable observation the evidence forces:** Geolander's stated offer already matches this
market's stated buying criteria almost line for line. `[INFERENCE]` **The problem is not the offer. It
is that nobody can find it, and nobody can verify it.**

---

## Sources

**TripAdvisor threads:** [Best car rental company in Tbilisi for a 10-day road trip](https://www.tripadvisor.com/ShowTopic-g294194-i9343-k15560961-Best_car_rental_company_in_Tbilisi_for_a_10_day_road_trip-Georgia.html) · [Car rental: is 4x4 necessary?](https://www.tripadvisor.com/ShowTopic-g294194-i9343-k11239914-Car_rental_is_4x4_necessary-Georgia.html) · [Is it possible to drive to Tusheti with a hire car?](https://www.tripadvisor.com/ShowTopic-g294194-i9343-k11198757-Is_it_possible_to_drive_to_Tusheti_with_a_hire_car-Georgia.html) · [Our experience with self-drive car rental in Georgia](https://www.tripadvisor.in/ShowTopic-g294194-i9343-k8994075-Our_experience_with_self_drive_car_rental_in_Georgia-Georgia.html) · plus "Car rental company forbidden routes", "Rental car and forbidden Mestia-Ushguli-Lentekhi road", "Car Rental Kutaisi or Tbilisi with no credit card needed"

**Publishers:** [wander-lush.org](https://wander-lush.org/driving-in-georgia-car-rental-tbilisi/) · [ountravela.com](https://ountravela.com/) · roadiscalling · tripwis.com · thewholeworldisaplayground.com · georgia.in-facts.info · tip-to-trip.com · ExpatHub.GE

**Policy pages:** [Localrent prohibited routes](https://www.localrent.com/en/georgia/) · Enterprise, Sixt, Ace Tbilisi terms

**Review sets:** DiscoverCars (Dollar, Europcar, Sixt — Tbilisi Airport), QEEQ (Naniko), Revieweek (GetRentaCar), TakeCars (1,453 Georgia reviews, own categorisation), TripAdvisor operator listings

**Not obtained:** Reddit (egress-blocked), otzovik.com (timeout), forum.awd.ru (404), Zhihu (robots-disallowed), PissedConsumer (CAPTCHA)
