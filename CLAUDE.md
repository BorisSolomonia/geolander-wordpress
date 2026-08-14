# CLAUDE.md — Geolander

WordPress site for Geolander, a 4×4 car rental company in Tbilisi, Georgia (the country).
Custom block theme + one custom plugin (`geolander-core`). Server-rendered, 7 locales, WhatsApp-led
booking. No third-party SEO plugin — SEO is hand-rolled in `class-glc-seo.php` and `class-glc-schema.php`.

## Before doing any SEO, content, schema, i18n or marketing work on this project

**Read `docs/seo/SEO-AGENT-MANUAL.md` first.** It is self-contained and authoritative: market research,
competitor teardowns, verbatim customer research, the strategy, the technical spec, the guardrails, and
the implementation status.

Three things from it that will otherwise be got wrong:

1. **Never invent a business fact.** Deposit amounts, insurance excesses, route permissions, odometer
   readings and review counts are supplied by the owner or they are not published. Pages that need one
   exist as drafts with `NEEDS:` markers.
2. **Never publish a zero.** No `$0`, no `lowPrice: 0`, no empty rating. Omit the element instead.
   `_migration/validate-schema.mjs` fails the build on this.
3. **Three attractive strategic claims were tested and refuted** — "we're too small to rank",
   "4×4 is a defensible niche", and "Russian is under-served". See manual §6.2 before proposing any of
   them again.

## Verification

```bash
php -l <file>                                          # every changed PHP file
node _migration/validate-schema.mjs http://localhost:8080   # schema + SEO regression guard
docker compose run --rm cli eval-file /migration/audit-fleet.php   # read-only fleet integrity
```

Bump the `Version:` header in `wp-content/themes/geolander/style.css` when CSS or JS changes — cache
busting is version-keyed. Add new UI strings to **all seven** `inc/strings-{locale}.php` catalogues.

Never delete a URL; 301 it to the survivor.
