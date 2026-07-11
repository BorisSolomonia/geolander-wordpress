# Geolander WordPress

Native WordPress rebuild of the Geolander rental platform. Adventure-immersive
"golden path" UX, Georgian UI / English vehicle content, structured-data-first.

## Run locally

```
docker compose up -d wordpress db
```

- Site: http://localhost:8080
- Admin: http://localhost:8080/wp-admin — user `geolander_admin`, password `GeoLander!Dev2026` (dev only — change in production)
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

7 locales, auto-detected from the visitor's browser language on first visit
(cookie remembers the choice; requests without Accept-Language — crawlers —
always get the stable x-default):

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
Settings → Geolander and set provider to `bog_ipay`; the front end is unchanged.
