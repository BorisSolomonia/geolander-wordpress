# Deployment & Go-Live Checklist

## 0. Railway (chosen path — git-driven)

Repo ships `Dockerfile` + `railway.json` + `.github/workflows/deploy.yml` (verified building).
Code (theme + plugin) is baked into the image; **plugins installed via wp-admin do NOT survive
redeploys** — add plugins to the repo instead. Media uploads persist on a volume.

1. Push the repo to GitHub:
   `git init && git add -A && git commit -m "Geolander WordPress"` then create the GitHub repo and push.
2. Railway dashboard → New Project:
   - **MySQL** service (note the generated credentials).
   - **App service** from the GitHub repo (Railway builds the Dockerfile on push — if you use this,
     delete `.github/workflows/deploy.yml`; keep the workflow only if you prefer Actions-driven
     deploys, then add the `RAILWAY_TOKEN` repo secret and do NOT also connect the repo in Railway).
3. App service → Variables:
   - `WORDPRESS_DB_HOST` = `${{MySQL.MYSQLHOST}}:${{MySQL.MYSQLPORT}}`
   - `WORDPRESS_DB_USER` = `${{MySQL.MYSQLUSER}}`, `WORDPRESS_DB_PASSWORD` = `${{MySQL.MYSQLPASSWORD}}`,
     `WORDPRESS_DB_NAME` = `${{MySQL.MYSQLDATABASE}}`
   - `WORDPRESS_CONFIG_EXTRA` =
     `define('WP_HOME','https://geo-lander.com'); define('WP_SITEURL','https://geo-lander.com'); define('FORCE_SSL_ADMIN', true);`
4. App service → Settings: **target port 80**; attach a **Volume** mounted at
   `/var/www/html/wp-content/uploads`.
5. First boot: open the app URL, run the WP install (STRONG password), then in the service shell
   (or `railway ssh`): activate plugin + theme, permalinks, import content:
   `wp plugin activate geolander-core && wp theme activate geolander && wp rewrite structure '/%postname%/' && wp rewrite flush && wp eval-file /migration/import.php && wp eval-file /migration/setup-pages.php && wp eval-file /migration/setup-seo.php`
6. Custom domain: add `geo-lander.com` to the service, set the CNAME at your DNS. **Recommended:**
   put Cloudflare (free) in front for CDN + page caching — Railway has no built-in page cache.
   The `geolander-core` plugin (`GLC_Perf`) now emits `Cache-Control: public, max-age=300,
   s-maxage=86400` on static pages and `private, no-cache` on dynamic ones, so configure Cloudflare to
   **respect origin cache headers** (Caching → Browser Cache TTL: "Respect Existing Headers"), then add
   a Cache Rule that **bypasses cache** when ANY of these hold, so the origin's own decision is honored:
   - path starts with `/wp-admin` or `/wp-json`
   - request has a `from` or `to` query param (live seasonal quotes)
   - Cookie contains `glc_lang` **or** `wordpress_logged_in` (language-switched or logged-in visitors)

   The unprefixed homepage `/` is deliberately sent `private, no-cache` by the plugin (its response
   depends on cookie/Accept-Language language negotiation), so it is always decided at the origin —
   do **not** add a "cache everything" rule that would override this. Prefixed pages (`/ka/…`,
   `/ru/…`) are path-keyed and fully cacheable.

   **Caveat (unprefixed deep links):** `GLC_I18n` also 302-redirects unprefixed deep links (`/fleet/`,
   `/cars/…`) for a *first-visit* non-English browser (no cookie yet). Because those pages ARE edge-cached
   as English, such a visitor can be served English HTML instead of the redirect. In practice this is
   rare and non-breaking: search engines follow hreflang straight to the `/ru/…` URL, and the on-page
   switcher is always present; the cookie-bypass rule covers every *returning* visitor. The clean,
   recommended fix (a small product decision) is to **scope the Accept-Language auto-redirect to the
   homepage only** and let deep links stay language-stable — standard i18n practice, and it removes the
   caveat entirely while keeping the cache win. Tracked in `docs/PERFORMANCE_AUDIT.md` (finding S1).
7. Email: containers can't send mail reliably — install an SMTP plugin (add to repo) wired to a
   free tier (e.g. Brevo) so booking notifications/password resets work.
8. Backups: enable Railway MySQL backups; the uploads volume + repo are the rest of the state.

## 1. Production hosting (alternative: managed WP host)
Any PHP 8.1+ / MySQL host works. Recommended: managed WP hosting with server-level
page caching, or a VPS with the same `docker-compose.yml` (add TLS via a reverse
proxy). Steps:

1. Install WordPress, copy `wp-content/themes/geolander` and `wp-content/plugins/geolander-core`.
2. Activate plugin, then theme. Set permalinks to `/%postname%/`.
3. Copy `_migration/` to the server, run `wp eval-file _migration/import.php`
   then `_migration/setup-pages.php` (adjust the `/migration` constant path).
4. Settings → Geolander: verify phone/WhatsApp/email/socials/coordinates.
5. Point DNS: **geolander.ge currently has no DNS records** (site_url in legacy
   settings says `geo-lander.com` — decide the canonical domain, set both
   WP_HOME/WP_SITEURL, 301 the other).
6. Search Console: submit `wp-sitemap.xml`; the legacy Google verification file
   `googlecaf9dc315ab07aac.html` can be re-uploaded if the same GSC property is kept.
7. Run https://search.google.com/test/rich-results on /, /fleet/, one car page
   (local validator already passes 101 checks / 0 errors, but Google's live test
   needs a public URL).

## 2. BOG iPay activation (later)
1. Get production `client_id` / `client_secret` from BOG business banking.
2. Settings → Geolander → Payments: fill credentials, set provider to `bog_ipay`.
3. The checkout endpoint automatically switches from WhatsApp deep link to a BOG
   order redirect. **Before go-live, re-verify the two endpoint URLs in
   `class-glc-gateways.php` against current BOG e-commerce API docs** (token URL,
   orders URL, response shape `_links.redirect.href`) and add the payment callback
   handler for the success/fail redirect URLs (`?glc_payment=success|fail&ref=GL-…`).
4. Consider a partial-prepayment amount (e.g. 20%) rather than full charge —
   matches how Localrent built trust in this market.

## 3. Content gaps (flagged during migration)
- **Photos missing** for: Toyota RAV4 2016, Jeep Renegade 2017 (no photos existed
  in the legacy repo either — they show a designed placeholder). 7 more cars
  **borrow photos from same-model units** (`glc_gallery_borrowed` meta = 1).
  A real photo shoot is the single highest-ROI improvement (see design research).
- Car `descriptionEn` was empty in legacy data — descriptions were auto-generated
  from specs; owner review recommended.
- Blog is empty (`/blog/` shows "coming soon"); Kazbegi/Kakheti route guides
  would be strong GEO/AEO content.
- Google reviews embed + real per-car ratings once data exists.

## 4. Performance notes
- Fonts: 2 variable woff2 files (~131 KB), Georgian subset loads via unicode-range.
- JS: reveal.js (~0.5 KB) + booking.js (~2 KB, car pages only). No jQuery on the front end.
- Convert car JPGs to WebP/AVIF on upload (host plugin or `wp media regenerate`
  with an image-optimizer plugin) for a further LCP win.
- Add a page-cache (host-level or plugin) — every page except quotes is fully cacheable.
  The fleet/car pages vary on `?from&to` query args; exclude those params from cache
  keys or cache them separately.

## 5. Multilingual notes
- 7 locales via URL prefixes; root `/` is English x-default. First visit
  redirects by Accept-Language (302 + `Vary: Accept-Language`); crawlers
  without the header always see stable content — no cloaking risk.
- **Page cache config**: cache per full URL path (prefixes differ, so that's
  automatic), but the ROOT `/` redirect depends on Accept-Language + cookie —
  exclude `/` redirect handling from full-page cache, or cache only the
  x-default variant and let the redirect happen at the origin. Do not cache
  `Set-Cookie` responses.
- Translated layer = UI chrome, headings, booking flow, trust copy, SEO
  descriptions. English-only for now: FAQ answers, terms, travel-info/music
  bodies, place descriptions, car descriptions (phase 2 if desired — the
  string-catalog pattern extends to content via per-locale meta fields).
- `<title>` site tagline is English on all locales (single WP tagline).
