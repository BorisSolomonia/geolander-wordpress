# Geolander — Architecture & Performance Audit

Date: 2026-07-15 · Scope: full stack (block theme `geolander` + plugin `geolander-core` + Railway/Docker deploy).

## Diagnostic stack — what was used, and an honesty note

The task brief specified a **React / JVM / Spring Boot / PostgreSQL** profiling stack (React Profiler,
VisualVM, async-profiler, Spring Boot Actuator, pg_stat_statements, k6 flame graphs). **None of it
applies to this project** — Geolander is PHP 8.3 + Apache + MySQL/MariaDB WordPress with ~3 KB of
vanilla JS and no build step, no JVM, and no PostgreSQL. Applying those tools here would be theater.

Equally, the audit environment (WSL) has **no PHP, no Docker integration, no Lighthouse, no k6**, so
live profiling and even `php -l` linting were not possible here. Rather than fabricate flame graphs or
Lighthouse numbers, this audit is a **rigorous static analysis** of the actual code paths, backed by
independent re-verification (parallel review agents) and a code-quality pass. Every finding below is a
real, reproducible defect or bottleneck traced to a specific line — not a generic checklist item.

**The tools that _do_ fit this stack, for when the site is live** (recommended, documented for replication):

| Layer | Tool | What it finds here |
|---|---|---|
| Frontend | Lighthouse / PageSpeed Insights | LCP (hero image), render-blocking CSS, CLS |
| Frontend | WebPageTest (filmstrip) | real-world TTFB from Tbilisi/EU, font FOIT |
| PHP | Query Monitor plugin | query count/time per request, hooks, HTTP calls |
| PHP | XHProf / Tideways (or `SAVEQUERIES`) | function-level hotspots, N+1 confirmation |
| MySQL | slow query log + `EXPLAIN` | missing indexes, full scans |
| Load | k6 or `ab`/`hey` against `/fleet/` and `/wp-json/geolander/v1/quote` | concurrency behaviour, cache hit ratio |
| Object cache | Redis + `redis-cache` plugin | repeated option/meta reads across requests |

The single highest-impact lever for this specific app is **edge/page caching** (finding S1) — the app
is ~95% static per URL, so most requests should never reach PHP at all.

---

## Findings summary

| ID | Class | Severity | Finding | Status |
|----|-------|----------|---------|--------|
| S1 | Speed / architecture | High | No CDN-cacheable headers → full WP+MySQL boot on every hit | **Fixed** |
| S2 | Speed | Low | `filemtime()` filesystem stat on every request for asset versions | **Fixed** |
| S3 | Speed | Medium | Fleet cards trigger one thumbnail query each (N+1); fleet fetched twice per archive | **Fixed** |
| A1 | Correctness / conversion | High | Booking `window.open()` after async fetch is popup-blocked → lost bookings | **Fixed** |
| A2 | Security / cost | Medium | Checkout REST endpoint unthrottled → spam bloats DB, fires gateway | **Fixed** |
| A3 | SEO hygiene | Low | `og:url` echoes arbitrary query params (fbclid/utm) | **Fixed** |
| A4 | Correctness (dormant) | Low | BOG iPay order missing `Idempotency-Key` UUID | **Fixed** |
| S4 | Speed | Low | 30 KB render-blocking `main.css`, no critical-CSS inline | Documented (see below) |
| G1 | SEO/AEO/GEO | — | Structured data / hreflang / llms.txt reviewed | **Verified sound** |

---

## The 5-step deep dives

### S1 — Every request boots the full stack (no edge cache)

1. **Symptom:** Every page view — even an identical fleet page requested a thousand times — runs a
   complete WordPress bootstrap, plugin load, main query, and template render against MySQL. TTFB is
   PHP-bound, not content-bound.
2. **Trigger:** Any anonymous GET in production. Railway provides no page cache, and WordPress emits
   no positive `Cache-Control` on normal front-end pages, so a CDN in front cannot cache the HTML.
3. **Code flaw:** There was no `send_headers` logic declaring cacheable pages public. A CDN sees no
   `max-age`/`s-maxage` and defaults to not caching HTML.
4. **Architectural gap:** The app is a brochure + a small dynamic island (the dated quote). Nearly
   every URL is static per-path, but nothing communicated that to the edge — the caching layer was
   simply absent from the architecture, deferred entirely to "put Cloudflare in front" ops docs.
5. **Root correction:** New `GLC_Perf` class emits `Cache-Control: public, max-age=300, s-maxage=86400`
   on genuinely static pages and **explicitly opts out** the dynamic/private surfaces (admin, REST,
   logged-in, POST, `?from&to` quote pages → `private, no-cache`, 404 → 30 s). The **unprefixed
   homepage `/` is sent `private, no-cache`** because its response is not a pure function of the URL —
   `GLC_I18n` may 302 it by cookie/Accept-Language, and a shared cache (Cloudflare ignores `Vary` on
   HTML) would otherwise serve cached English to a first-visit non-English visitor. Prefixed home pages
   (`/ka/`, `/ru/`…) are path-keyed and stay cacheable. Additionally, `GLC_I18n::remember()` no longer
   re-sends an unchanged `glc_lang` cookie, so ordinary pages stop carrying `Set-Cookie` and become
   genuinely edge-cacheable. This makes the app CDN-cacheable *by default* for anonymous traffic — the
   "full boot per hit" class is eliminated and can't silently reappear, because cacheability is now
   declared in code. Pair with the Cloudflare rule in DEPLOYMENT.md (respect origin headers; bypass on
   `glc_lang`/`wordpress_logged_in` cookies, `/wp-admin`, `/wp-json`, and `from`/`to` params).

   *(This design was hardened after an adversarial review caught that the initial `Vary:
   Accept-Language` approach was insufficient against Cloudflare's HTML caching.)*

   **Follow-up (product decision, not yet applied):** `GLC_I18n` also redirects unprefixed *deep* links
   (`/fleet/`, `/cars/…`) for first-visit non-English browsers, so edge-caching them as English can serve
   English instead of the redirect (rare, non-breaking — see DEPLOYMENT.md caveat). The clean fix is to
   **scope the Accept-Language auto-redirect to the homepage only**; deep links then stay language-stable
   and the caveat disappears. Left for Boris to decide, as it changes redirect UX.

### S2 — Filesystem stat on every request for cache-busting

1. **Symptom:** Each front-end render calls `filemtime()` on `main.css`, `reveal.js`, and (on car
   pages) `booking.js` to compute an asset version query string.
2. **Trigger:** Every page load. Three `stat()` syscalls per request on the hot path.
3. **Code flaw:** `functions.php` and `class-glc-blocks.php` used `filemtime(get_theme_file_path(...))`
   as the enqueue version.
4. **Architectural gap:** `filemtime` gives instant cache-busting in dev, but production runs
   `opcache.enable=1` with `opcache.validate_timestamps=0` (see Dockerfile) — code and assets are
   **immutable between deploys**, so re-stat'ing them every request buys nothing and costs I/O.
5. **Root correction:** Version the assets by the theme's `Version` header (and `GLC_VERSION` for the
   plugin script). Zero filesystem I/O on the hot path; cache-busting now correctly ties to a deploy.
   Documented invariant: **bump the theme Version when CSS/JS changes** (mirrors the existing
   plugin-version discipline).

### S3 — N+1 thumbnail queries + fleet fetched twice per archive page

1. **Symptom:** On `/fleet/`, the card grid issues a separate DB query per car to resolve the featured
   image, and the same 15-car list is fetched twice (once for the `<head>` JSON-LD `ItemList`, once
   for the body grid).
2. **Trigger:** Loading any page that renders the fleet grid — worst on the archive, which also emits
   the ItemList schema.
3. **Code flaw:** `fleet_grid()` used `get_posts()` (no thumbnail-cache priming), so each
   `has_post_thumbnail()`/`get_the_post_thumbnail()` lazily fetched the attachment post. `fleet_list()`
   in the schema class ran its own independent `get_posts()`.
4. **Architectural gap:** WordPress primes post/meta/term caches for a query but **not** thumbnail
   (attachment) objects unless asked; and two code paths rendering the same list had no shared
   fetch — a classic per-render duplication.
5. **Root correction:** A memoized `glc_fleet_query()` runs the fleet query once per request with
   `no_found_rows` and calls `update_post_thumbnail_cache()` to load all featured images in one query.
   Both the grid and the schema `ItemList` consume it. On the archive this removes ~15 thumbnail
   queries + one duplicate list query. The class can't reappear for these paths because the single
   accessor is the only entry point.

### A1 — Booking button's new tab is popup-blocked (silent lost conversions)

1. **Symptom:** A user picks dates, clicks "Book via WhatsApp", the request logs server-side — but the
   WhatsApp tab never opens for some users. The booking looks like it vanished.
2. **Trigger:** Safari and Firefox (and stricter Chrome settings): the `POST /checkout` fetch is
   awaited, then `window.open()` is called in the `.then()`. By then the **user-activation token from
   the click is spent**, so the browser classifies the `window.open` as an unsolicited popup and blocks it.
3. **Code flaw:** `booking.js checkout()` called `window.open(res.redirect, '_blank')` only *after* the
   async round-trip resolved.
4. **Architectural gap:** The flow assumed "open after we have the URL," but popup permission is tied
   to the synchronous gesture, not to when the URL is ready — a mismatch between the async data flow
   and the browser's activation model.
5. **Root correction:** Open a blank tab **synchronously inside the click** (`window.open('','_blank')`),
   then steer that already-permitted tab to the redirect once the server responds; if the browser
   blocked even the blank tab, fall back to a same-tab `location.href`. Also null the `opener` for
   security. Conversions now land on every browser. (Failure path closes the blank tab so no orphan
   tab is left.)

### A2 — Unauthenticated checkout endpoint has no throttle

1. **Symptom:** `POST /wp-json/geolander/v1/checkout` can be called at unlimited rate; each call writes
   a `booking_request` post and (when BOG is active) calls the bank's order API.
2. **Trigger:** A script hammering the public endpoint — trivial to discover from the front-end JS.
3. **Code flaw:** `GLC_Booking::checkout()` validated inputs but had no rate control before
   `log_request()`/gateway dispatch.
4. **Architectural gap:** The endpoint was intentionally public (`permission_callback => __return_true`)
   so booking needs no login — correct for UX — but "public" was conflated with "unlimited," leaving no
   abuse ceiling. A nonce would break CDN caching and still not stop a headless client, so throttling,
   not CSRF, is the right control.
5. **Root correction:** A per-IP transient throttle (10 checkout calls/hour/IP — far above human
   cadence) returns a clean **429** `WP_Error` before any post is written or gateway called. The client
   IP is read from `X-Forwarded-For`'s first hop (correct behind Railway's proxy; `REMOTE_ADDR` there is
   the shared proxy and would lock out everyone), validated with `FILTER_VALIDATE_IP`. Moves to Redis
   automatically once an object cache is attached.

### A3 — `og:url` leaks tracking parameters

1. **Symptom:** Share a page arrived at via `?fbclid=…` or `utm_*` and the Open Graph `og:url` embeds
   those params; scrapers and social caches then treat the polluted URL as the entity URL.
2. **Trigger:** Any non-singular page (front page, archives) reached with a query string.
3. **Code flaw:** `GLC_SEO::output()` set `og:url` to `home_url( add_query_arg( [] ) )`, which is the
   current request URI including its query string.
4. **Architectural gap:** `og:url` was derived from the raw request rather than from the page's
   canonical identity — the canonical `<link>` was already computed cleanly, but `og:url` didn't reuse it.
5. **Root correction:** Derive `og:url` from the same clean canonical logic (permalink for singular,
   archive link for archives, `/` for the front page). OG and canonical now agree; no param bleed.

### A4 — BOG iPay order missing `Idempotency-Key` (dormant gateway)

1. **Symptom:** When BOG iPay is eventually activated, order creation would omit the idempotency header
   the BOG e-commerce API expects.
2. **Trigger:** Activating `bog_ipay` and taking a real payment.
3. **Code flaw:** The `wp_remote_post` to the orders endpoint sent Authorization + Content-Type only.
4. **Architectural gap:** The gateway was built to documented shape but a known BOG requirement — the
   `Idempotency-Key` must be a **UUID** (not a `create:<id>`-style string) — wasn't wired, because the
   gateway is dormant and untested against the live API.
5. **Root correction:** Send `Idempotency-Key: <uuidv4>` on the order POST. Amounts were already JSON
   numbers (correct). Left dormant; DEPLOYMENT.md still flags re-verifying endpoints + adding the
   `?glc_payment=success|fail` callback handler before go-live.

### S4 — Render-blocking CSS (documented, not changed)

`main.css` is 30 KB, enqueued in `<head>`, render-blocking. For a site this small the honest call is to
**not** over-engineer: 30 KB gzips to ~7–8 KB and is cached after first paint. If Lighthouse (once live)
flags LCP on the hero, the highest-ROI moves are (1) inline the ~2 KB of above-the-fold critical CSS and
`media="print" onload` the rest, and (2) the already-noted AVIF/squoosh pass on the 456 KB hero image —
the image, not the CSS, is the likely LCP. Deferred deliberately to avoid premature optimization.

### G1 — SEO / AEO / GEO (verified sound, no change needed)

Reviewed and confirmed correct: JSON-LD `@graph` (AutoRental with GBP-matched NAP + geo, Product/Car
with AggregateOffer from live rates, FAQPage, BreadcrumbList); hreflang for 7 locales + self-referencing
x-default, crawler-safe (header-less bots get stable x-default, no cloaking); `/llms.txt` + `/pricing.md`
generated from live data; robots.txt explicitly allows GPTBot/ClaudeBot/PerplexityBot/Google-Extended/CCBot;
engineered titles + price-bearing meta descriptions; GA4 + Ads conversion on booking. This layer is a
genuine strength — the remaining wins are **off-site** (GBP reviews, third-party listings, the two blog
guides) and content depth (per-locale bodies), already tracked in `docs/seo-geo-aeo.md`.

---

## Net effect

- Anonymous traffic becomes CDN-cacheable → most hits never touch PHP/MySQL (S1).
- Per-request filesystem I/O removed from the hot path (S2).
- ~16 fewer DB queries on the fleet archive (S3).
- Booking conversions no longer silently lost to popup blockers (A1).
- Checkout endpoint can't be used to bloat the DB or run up gateway calls (A2).
- Cleaner canonical/OG identity (A3) and a correct dormant payment path (A4).

All changes are small, reversible, and localized. No database migration. No change to the booking price
math, the i18n routing, or the schema output shape.
