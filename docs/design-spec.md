# Geolander — Golden Path Design Spec
Synthesized from two research passes (booking-platform UX + avant-garde travel design), 2026-07-09.
Design-lead identity pass 2026-07-10 — see "Identity layer v2" below.

## Identity layer v2 (2026-07-10)
Critique: the v1 execution (dark base + single orange accent) was structurally the stock
"near-black + hot accent" look; nothing on the page was *Georgian* except the words.
Additions, all grounded in the subject's world:
- **Signature — registration plates.** Every car card and detail page renders the car's
  real Georgian plate (white, blue GE band, mono type): `glc_plate()` in functions.php.
  Visual proof of "the exact car you book" — the core Localrent-style trust promise.
- **Dual-script signage eyebrows.** Section labels follow Georgian Military Highway road
  signs: Georgian Mtavruli first, visitor's language/data-fact beneath: `glc_sign()`.
  Replaced decorative 01–06 section numbering (sections aren't a sequence; the 3-step
  process and the 01–15 fleet index keep their numbers because those ARE sequences/counts).
- **Mono data layer.** IBM Plex Mono (2 static woff2, ~30 KB, latin) for plates, prices,
  price-table cells, spec values, coordinates, elevations. Emotion in Georgian/Archivo;
  computed numbers in mono.
- **Elevation as content.** Route cards carry real altitude ranges (Batumi 0 m → Gudauri
  2196 m); hero detail line: `TBILISI 41.69°N 44.80°E · 0 → 2196 M · 4X4`.
True to a 4x4 brand: driving in Georgia is a story about altitude.

## Design DNA
Adventure-immersive + editorial edge. Dark for emotion, light for transaction.

### Palette — "Expedition Green" (v2, 2026-07-10, user-approved; replaced navy "Kazbegi Dusk")
Deep Caucasus fir + expedition/racing-green automotive heritage.
| Token | Hex | Role |
|---|---|---|
| ink | `#142420` | base dark (deep fir) |
| surface | `#1E332C` | elevated spruce cards |
| glacier | `#F2F4F1` | text on dark |
| paper | `#F7F5F0` | light sections (booking/forms/legal) |
| ink-text | `#141414` | text on light |
| stone | `#9FB4A8` | secondary text, rules (moss stone) |
| accent | `#FF6B35` | CTAs + price tags ONLY (hover `#E4531F`) — expedition livery orange |
| success | `#8FBE6E` | availability/free-cancellation (brightened for green base) |
| plate-blue | `#123F9D` | registration plate band only |

### Typography
- Georgian UI: **Noto Sans Georgian variable** (wght 100-900 + wdth). Nav/labels/buttons in **Mtavruli caps**; display = wght 700-800 wdth 75.
- English display: **Archivo variable** — headlines wght 800-900 wdth 125 (Expanded Black), `letter-spacing:-0.02em`, `clamp(2.5rem,8vw,8rem)`.
- Self-hosted woff2, `unicode-range: U+10A0-10FF, U+1C90-1CBF, U+2D00-2D2F` on Georgian file; preload Latin only. Fallback incl. Sylfaen (Windows Georgian).

### Motion (≤15KB JS total, prefers-reduced-motion everywhere)
1. IntersectionObserver reveals (opacity+24px rise, 500-700ms, 60-80ms stagger)
2. CSS scroll-driven hero slow-zoom + parallax behind `@supports (animation-timeline: view())`
3. Cross-document View Transitions: fleet card image → car page morph (shared view-transition-name)
4. Numbered fleet index w/ hover-reveal photo (rAF pointermove → --x/--y custom props)
5. Never animate LCP element from opacity 0. No preloaders, no scroll-jacking, no WebGL, no custom cursors.

## Golden path
LAND (hero + dates) → DATES (all prices recompute; persist in URL/localStorage) → BROWSE (fleet w/ totals for dates) → INSPECT (detail) → SUMMARIZE (in-page booking summary + name + booking reference GL-XXXX) → HANDOFF (server-side log, then wa.me prefilled structured message) → REASSURE ("confirmed within 30 min, nothing to pay now").

Price shown on site is LOCKED — WhatsApp reply confirms, never renegotiates.

## Homepage section order
1. Hero + date widget (pickup point select: Tbilisi office / TBS airport / hotel; dates+times; CTA)
2. Trust strip fused under widget: rating, free cancellation, no hidden fees, airport delivery, 24/7 WhatsApp
3. Fleet teaser (6 cars) → "all 15"
4. How it works, 3 steps (explain WhatsApp step BEFORE user hits it)
5. "Where our cars take you": Kazbegi/Kakheti/Svaneti route cards — "all our cars permitted on these roads"
6. Everything-included grid (insurance, unlimited km, winter tires…)
7. Reviews (real names/routes)
8. FAQ (deposit, IDP, borders, gravel, fuel)
9. Footer CTA; floating WhatsApp bubble on non-detail pages

## Fleet card anatomy (in order)
Exact-car photo carousel → "Brand Model YYYY" (never "or similar") → badge row (4x4/Automatic) → spec icons (seats/fuel) → price block (dates set: "TOTAL first · $X/day · N days"; else "from $X/day") → deposit line → green microline "✓ Free cancellation ✓ Insurance included" → availability state (honest "Booked for these dates").
Filter chips (no sidebar): Transmission · Seats · 4x4 · Price. Sort: price asc.

## Car detail page
1. Gallery: Airbnb mosaic desktop (1+4, "show all"), mobile swipe w/ counter
2. Title + badges; 3. Spec grid 6-8 icon tiles; 4. Seasonal price table with user's cell HIGHLIGHTED
5. Availability calendar (seasons tinted); 6. Everything-included checklist; 7. Terrain permissions block ("Yes, Kazbegi allowed")
8. Reviews; 9. Policies (deposit/insurance/fuel/cancellation timeline)
10. Booking widget: desktop sticky right rail (dates editable, live line-item breakdown, total, CTA "Book via WhatsApp" + microcopy "No prepayment — free cancellation until 48h before pickup"); mobile sticky bottom bar 64px (total left tap→sheet, green CTA right)

## WhatsApp handoff (the trust package)
Summary sheet: car thumb, dates/times, pickup point, line-item price, total USD, deposit, cancellation restated. Fields: Name + flight number (optional) ONLY. Booking reference GL-XXXX prominent. POST to server first (logged as booking_request), then wa.me deep link:
```
Booking request GL-2417
🚙 Subaru Forester 2021
📅 12 Jul 10:00 → 18 Jul 18:00 (6 days)
📍 Pickup: Tbilisi Airport
💰 Total: $290 ($48/day) · Deposit $200
👤 Name: Anna K. · Flight: TK 382
```
Post-handoff: "✓ Request GL-2417 sent. We confirm within 30 minutes (09:00–22:00). Nothing to pay now."

## Anti-patterns (banned)
Fake urgency; late-revealed costs; "or similar"; stock photos; login/email walls; bare WhatsApp button without summary+reference; multi-step upsell funnel.

## Reference sites
Rivian (hero handoff), Ineos Grenadier (spec-plate type), 66°North + Klättermusen (editorial contrast), Sidetracked (chapter layouts for route guides), White Desert (slow zoom), Pelorus (numbered hover index), Blue Car Rental (booking widget + all-inclusive positioning), Localrent (deposit transparency, exact cars, low-prepay trust model), Polestar (numeral specs), Visit Faroe Islands (map hotspots).

## Note for Boris
Research strongly recommends an EN language toggle for international tourists (majority of buyers) — current requirement is strictly-Georgian UI; flagging for a future decision. Prices stay USD per legacy data.
