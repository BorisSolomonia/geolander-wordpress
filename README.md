# Geolander WordPress

WordPress rental platform with a custom block theme and Geolander Core plugin.
Seven UI languages, seasonal quotes, partner fleet management and WhatsApp booking.

## Run locally.

```
docker compose up -d wordpress db
```

- Site: http://localhost:8080
- Admin: http://localhost:8080/wp-admin — use the local account created during installation. Never reuse a production password locally.
- WP-CLI: `docker compose run --rm cli <command>`

## Structure

| Path | What |
|---|---|
| `wp-content/themes/geolander` | Block theme: templates, PHP patterns, Kazbegi Dusk design system (`assets/css/main.css`), self-hosted variable fonts, Georgian strings (`inc/strings-ka.php`) |
| `wp-content/plugins/geolander-core` | CPTs (car/place/testimonial/faq/booking_request), seasonal pricing engine, booking REST API, WhatsApp + BOG iPay gateways, JSON-LD schema, SEO meta, admin meta boxes |
| `_migration/` | Extracted legacy data (cars, places, FAQs, media), importers, schema validator |
| `docs/` | Design spec, deployment guide, screenshots |

## Key commands

```
docker compose run --rm cli eval-file /migration/import.php        # (re)import content
docker compose run --rm cli eval-file /migration/setup-pages.php   # (re)create pages
node _migration/validate-schema.mjs                                # validate JSON-LD
```

## Languages

Seven locales selected by URL. Language selection writes no cookie and does
not redirect based on the browser language, keeping public pages cacheable:

- `/` — English (x-default)
- `/ka/ /ru/ /uk/ /ar/ /zh/ /fr/` — Georgian, Russian, Ukrainian, Arabic (RTL),
  Chinese, French

UI strings live in `themes/geolander/inc/strings-{locale}.php` (one flat
key→string array per language — edit or add locales there). hreflang
alternates are emitted on every page; schema `inLanguage` and `og:locale`
follow the active locale. Vehicle body content stays English by design.

## Booking flow

Dates → live seasonal quote (`GET /wp-json/geolander/v1/quote`) →
checkout (`POST /wp-json/geolander/v1/checkout`) → request logged as
`booking_request` post with reference `GL-XXXX` → WhatsApp deep link with a
prefilled structured message. Activating BOG iPay later = fill credentials in
Settings → Geolander and set provider to `bog_ipay`. Re-verify its endpoints,
prepayment amount and payment confirmation handling before enabling it.

## Verification

Build the application image with `docker compose build wordpress`. Run the
standalone PHP suites with the image's complete extension set:

```powershell
docker run --rm --mount "type=bind,source=$PWD,target=/src,readonly" --entrypoint sh geolander-wordpress:local /src/tests/run-standalone.sh
node --test tests/booking-state.test.mjs tests/analytics-privacy.test.mjs
powershell -NoProfile -ExecutionPolicy Bypass -File tests/tools/run-integration.ps1
powershell -NoProfile -ExecutionPolicy Bypass -File tests/checkout-concurrency.test.ps1
node --test tests/landing-routing.test.mjs
node _migration/validate-schema.mjs http://localhost:8080
docker compose run --rm cli eval-file /migration/audit-fleet.php
```

The integration runner requires an installed development site, a seeded fleet
and a published page. It snapshots and restores its local database between
suites and creates its own partner/photo fixtures. It refuses production.
The schema guard additionally needs the relevant content migrations and
agent signing/authentication configuration. A bare installation is incomplete.
