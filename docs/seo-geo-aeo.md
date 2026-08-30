# SEO / AEO / GEO — Implementation & Launch Playbook
Audit + implementation 2026-07-10, via seo-audit and ai-seo skills. Canonical domain: **geo-lander.com**.

## Implemented on-site

### Search (SEO)
- **Engineered titles** (English = commercial keywords; other locales use catalog strings):
  home "Car Rental in Tbilisi, Georgia — 4×4 from $26/day | Geolander"; car pages
  "Rent {Car} in Tbilisi from ${N}/day"; fleet/places archives keyworded.
- **Meta descriptions** locale-aware, price-bearing on car pages; OG/Twitter cards; canonicals on
  all page types (date-param variants canonicalize to clean URLs).
- **Sitemap** cleaned: author + unused core taxonomies removed; cars/places/pages/brands/regions in.
- **hreflang** 7 locales + x-default (was already live), self-referencing, reciprocal.
- **Privacy policy** page published + registered (Google Ads compliance requirement).
- Hero H1 area copy now contains "4x4 car rental in Tbilisi" naturally (EN locale).
- Perf: WebP with JPG fallback for hero/CTA/route art (routes 653→123 KB class savings);
  hero 456 KB WebP (high-entropy image — floor for GD encoder; consider a squoosh/AVIF pass later).

### Maps (Local)
- **NAP now matches the GBP exactly** (pulled live from your Maps listing):
  name "Geolander car rental", 8/5 Vedzini Street, Tbilisi 0108, +995 551 33 04 14,
  geo 41.6980427 / 44.7934697 (was generic Tbilisi-center coords — a real local-ranking bug).
- AutoRental schema: streetAddress/postalCode, `hasMap` → GBP link, alternateName "Geolander",
  currenciesAccepted, 24/7 openingHours. Footer + contact page display the full address.

### AI tools (AEO/GEO)
- **`/llms.txt`** — live-generated: business summary, key facts, full fleet with price ranges + links,
  key pages, complete FAQ text. Follows llmstxt.org.
- **`/pricing.md`** — live-generated full seasonal × duration rate tables per car in markdown.
  AI agents answering "how much to rent a 4x4 in Tbilisi" can quote exact numbers with a link.
- **robots.txt** explicitly allows GPTBot, OAI-SearchBot, ChatGPT-User, ClaudeBot, Claude-SearchBot,
  PerplexityBot, Google-Extended, CCBot.
- Server-rendered HTML (no JS-wall), semantic headings, 13-question FAQPage schema, extractable
  answer-first FAQ copy — already strong for citation.

### Ads
- Settings → Geolander → Google tags: GA4 ID, Ads ID, conversion label fields.
- gtag renders only when IDs present. Checkout fires GA4 `booking_request` (value = quote total)
  and an Ads `conversion` with `transaction_id` = booking reference (dedupes).

## Launch checklist (needs you / production)
1. Point geo-lander.com, install site, set WP_HOME/WP_SITEURL, HTTPS.
2. Search Console: verify property, submit `/wp-sitemap.xml`.
3. **GBP ↔ site link**: set geo-lander.com as the website on the GBP; keep name/address/phone
   identical everywhere. Add photos of real cars + office to GBP; enable messaging.
4. Google Ads: create conversion action "Booking request", paste AW-ID + label into settings.
   Landing pages for ads: `/fleet/` (generic) and car pages (model campaigns) — both carry prices,
   trust copy, privacy policy link.
5. Reviews engine: after each rental, WhatsApp the guest the GBP review link. Reviews are the #1
   local-pack and AI-recommendation lever.

## Third-party presence (AI engines cite these more than your site)
- Get listed/kept updated on: TripAdvisor, Google Maps reviews, r/Sakartvelo + r/travel threads
  (authentic answers only), Lonely Planet forum, Caucasus travel Facebook groups.
- Two blog guides to publish (high fan-out coverage): "Driving from Tbilisi to Kazbegi: full guide"
  and "Renting a car in Georgia (country): everything tourists ask" — both mostly answerable from
  existing FAQ + travel-info content.
- Consider a Localrent/EconomyBookings listing: costs commission but earns entity mentions AI
  systems see ("Geolander" appearing on aggregator pages strengthens recommendations).

## Known gaps / phase 2
- Non-EN locale pages share English body content (UI translated) — genuine per-locale content
  (at least FAQ + car descriptions in ru) would remove the thin-locale risk and win ru-language queries.
- Hero image: try AVIF/squoosh offline for <200 KB.
- Real per-car photos for RAV4 + Renegade; original photos boost Product rich results and GBP.
- Once real reviews accumulate on GBP, consider AggregateRating on the business (never fabricate).
- Monitor monthly: run top-20 queries ("car rental tbilisi", "rent 4x4 georgia kazbegi", "tbilisi
  airport car rental no deposit"…) through ChatGPT/Perplexity/Google AI Overviews; log citations.
