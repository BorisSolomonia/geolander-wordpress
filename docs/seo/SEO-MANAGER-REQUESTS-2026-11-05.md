# The SEO manager's three requests — built 2026-11-05

Three things were asked for: landing pages the SEO manager can make himself, an
editing account for Nika, and smaller images with a speed check that keeps a
record. All three are built, with evidence below. Nothing is committed, nothing
is deployed, and no WordPress user was created.

**Read this first if you read nothing else.** The three live things to know:

1. A landing page is now a normal WordPress page with a path typed into a box.
   The two existing live URLs — `/car-rental-kazbegi/` and `/fleet/4x4-suv/` —
   were moved into that mechanism and still answer `HTTP 200` with their own
   titles. They are no longer written in code.
2. Pictures now get a WebP twin automatically. Measured here: **38% fewer bytes
   across 1,131 files, 46.7 MB saved**, originals untouched.
3. The speed check is built but has never measured anything, because it needs a
   free Google API key that nobody has given it. It says so on screen instead of
   inventing a number.

---

## Task A — landing pages the SEO manager can make himself

### 1. QUESTION — what was actually asked, and what I had to decide myself

| # | Requirement | Status | Source |
|---|---|---|---|
| A1 | "Separate Google Landing Pages – Create dedicated landing pages optimized for Google, using a reusable template." | **CONFIRMED** | SEO manager, quoted verbatim in the brief |
| A2 | The SEO manager does it from wp-admin: no developer, no deploy | **CONFIRMED** | brief, Task A |
| A3 | He picks a layout, sets the URL path, fills the SEO fields | **CONFIRMED** | brief, Task A |
| A4 | Rewrite rules come from stored data, never literals | **CONFIRMED** | brief, Task A + the NOTHING IS HARDCODED rule |
| A5 | Adding a page must not need a hand-run `wp rewrite flush` | **CONFIRMED** | brief, Task A |
| A6 | Per-locale title, description and body, through the existing i18n layer | **CONFIRMED** | brief, Task A |
| A7 | The existing schema.org output wired up | **CONFIRMED** | brief, Task A |
| A8 | Both existing live URLs keep resolving exactly as now | **CONFIRMED** | brief, rule 5 |
| A9 | "2-3 real layouts, registered as data so more can be added" | **CONFIRMED** | brief, Task A |
| A10 | Three layouts: a place/airport, a kind of vehicle, a route/region | **ASSUMED** | nobody named the three. These are the three shapes the site's existing SEO pages already take, so they are the three a keyword page will need. Changing them is editing a pattern in wp-admin, so a wrong guess costs nothing |
| A11 | How deep a path may be (`/a/b/c/`) | **ASSUMED → made a setting** | nobody said. Default 3 segments, `landing_path_depth` |
| A12 | Which words a landing page may not take over | **ASSUMED → made a setting** | derived by reading the site's own top-level paths; `landing_reserved_paths` |
| A13 | Whether the SEO manager may also edit the layouts themselves | **UNCLEAR** | Not asked either way. Built as an off-by-default switch, `editor_can_edit_layouts`. See "What is still open" |

### 2. DELETE — what I did not build

- **A new `landing` custom post type.** Cut. See the options below: it would have
  cost a migration of the two live URLs for nothing.
- **An SEO field editor with its own screen.** Cut. WordPress's own page editor
  already does titles, bodies, revisions, scheduling and translations.
- **A path-history / old-URL redirect table.** Cut, because
  `class-glc-redirects.php` already exists and already does redirects. Building a
  second one next to it is the "trust the framework" rule broken twice over.
- **A preview of the generated rewrite rule.** Cut. The person typing a path
  wants to know the page works, which the permalink link under the field already
  shows him.

### 3. SIMPLIFY — what the mechanism actually is

A landing page is an ordinary WordPress **page** with one extra field: the path
it answers on. Everything else — the layout, the per-locale words, the schema —
is a thing this site already had, now reachable from that page.

The old file hardcoded two rules:

```php
add_rewrite_rule( '^car-rental-kazbegi/?$', 'index.php?pagename=car-rental-kazbegi', 'top' );
add_rewrite_rule( '^fleet/4x4-suv/?$',      'index.php?pagename=4x4-suv',           'top' );
```

Those two lines are gone. In their place, every page that has a path in its
`glc_custom_path` meta becomes a rule, and the list of rules is generated:

```php
public static function rules( $rules ) {
    $mine = [];
    foreach ( self::paths() as $path => $slug ) {
        $mine[ '^' . $path . '/?$' ] = 'index.php?pagename=' . $slug;
    }
    return $mine + (array) $rules;
}
```

The two live URLs are now **data**: they were copied into page meta by a
one-time upgrade step, seeded from the `landing_paths_seed` setting. That is why
they survive — they are the first two rows of the new mechanism, not a special
case beside it.

### 4. ACCELERATE — one measured bottleneck, nothing else

The path list is read from the database to build the rules, and WordPress builds
its rules on requests where the stored set is missing. One query per such request
is not free, so the generated list is kept in an autoloaded option
(`glc_landing_rules`) and only rebuilt when a page is saved, trashed, untrashed
or deleted. No other speed work was done here, because nothing else was measured
to be slow.

### 5. AUTOMATE — the flush, and only the flush

| | |
|---|---|
| **Trigger** | saving, trashing, untrashing or deleting any page |
| **What happens** | the generated list is thrown away and rebuilt; its fingerprint (an md5) is compared with the stored one; if they differ, WordPress is told to rebuild its rules |
| **Owner** | `GLC_Landings::flush_if_changed()`, on `wp_loaded` |
| **How it fails** | if the rebuild does not happen, the fingerprint is not stored either, so the next request tries again. It cannot get stuck believing it has done work it has not done |
| **Stop condition** | the fingerprint matches, so nothing is flushed on an ordinary request |
| **Manual fallback** | Settings → Permalinks → Save, WordPress's own flush, still works and always did |

**The bug this cost me, written down because it is the kind that comes back.**
My first version flushed on `init`. The tracer then showed the new rule sitting
in the registry while the URL still returned 404. The reason is in WordPress
itself: `WP_Rewrite::flush_rules()` begins *"Prevent this action from running
before everyone has registered their rewrites"*, and when `wp_loaded` has not
fired it re-registers itself for later **and returns as if it had worked**. So
my code stored the fingerprint for a rebuild that had not happened, and the next
request saw a matching fingerprint and did nothing. Moving the flush to
`wp_loaded` fixed it.

**The second bug, same tracer.** Still 404. The stored positions showed why:

```
position  0   ^car-rental-kazbegi/?$
position  1   ^fleet/4x4-suv/?$
position 34   ^car-rental-([^/]+)/?$          <- the city pages
position 39   ^car-rental-tbilisi-airport/?$  <- my new landing, too late
```

`add_rewrite_rule( …, 'top' )` puts each new rule *in front of the previous one*,
so the winner is decided by which class happened to run its `init` last. The
city rule matched `car-rental-tbilisi-airport` first and looked for a city of
that name, which does not exist. The fix was to stop using `add_rewrite_rule`
and filter the finished list instead (`rewrite_rules_array`, priority 99,
`$mine + $rules`), which puts the landing rules first by construction rather
than by luck. After the fix the three landing rules sit at positions 0, 1 and 2.

### The options I considered for Task A, and why I chose this one

| Option | What it would have meant | Verdict |
|---|---|---|
| **A new `landing` custom post type** | A clean, obviously-named thing in the admin menu. But the two live URLs are `page` posts today, so keeping them would mean either migrating two ranking pages to a new post type, or keeping the old code beside the new — the exact "two mechanisms" problem. Pages already have translations, revisions, schema and SEO wired in; a new type starts with none of that | **rejected** |
| **Generalise the existing `glc_custom_path` meta on `page`** | The field the two live pages would already have had, if the old code had stored anything. Nothing migrates, both URLs are preserved *by construction* rather than by care, and everything pages already do keeps working | **chosen** |
| **A path field on every post type** | Cars, cities and news already have their own URL shapes and their own classes. Adding a second way to set their URLs invites two rules for one page | **rejected** |
| **A template-name field plus PHP template files** | Would make a new layout a developer job with a deploy — the one thing the request explicitly rules out | **rejected** |

Layouts are **patterns**, not code: the three starting ones are `wp_block`
records created by `_migration/setup-landing-layouts.php`, which means the SEO
manager can copy them, rewrite them and add more under Appearance → Patterns,
with no deploy. That is also why the selector offers the site's own patterns and
not every pattern that exists — measured on this install, offering everything
put eleven of WordPress's own patterns in the list (six ways to list blog posts,
five navigation overlays), none of which is a landing page. Typing a category
name into `landing_layout_category` opts coded patterns back in; proven below.

---

## Task B — the editing role for Nika

### 1. QUESTION

| # | Requirement | Status | Source |
|---|---|---|---|
| B1 | "Editing Access for Nika – Grant Nika permission to edit website content." | **CONFIRMED** | SEO manager, quoted verbatim |
| B2 | Build the role only. Create no user | **CONFIRMED** | brief, Task B + hard prohibition |
| B3 | May manage cars, cities, landing pages, news — "content only" | **CONFIRMED** | **Boris, 2026-11-05**, relayed by the team lead; matches the brief exactly |
| B4 | May **not**: install plugins, edit theme files, manage users, change plugin settings, or see payment or booking data | **CONFIRMED** | **Boris, 2026-11-05.** The booking exclusion is a **privacy requirement, not a preference**: a booking request carries the customer's phone number |
| B5 | Registered idempotently, with a documented way to assign it | **CONFIRMED** | brief, Task B |
| B6 | Align with `class-glc-partner-panel.php` / `class-glc-access.php` rather than duplicate | **CONFIRMED** | brief, Task B |
| B7 | Whether Nika may upload pictures | **CONFIRMED** (within "content only") | a content editor who cannot add a photo cannot write a page about a car. `upload_files` is granted |
| B8 | Whether Nika may edit *other people's* pages | **CONFIRMED** (within "content only") | he is the content person for a site whose content was written by someone else; without this he could not touch a single existing page |
| B9 | Whether Nika may delete pages | **CONFIRMED** (within "content only") | WordPress "delete" means move to the trash, which is reversible, and the alternative is him asking Boris to empty every draft |
| B11 | He will also create and edit **car model** pages | **CONFIRMED, not buildable yet** | Boris, 2026-11-05. The `car_model` post type is still being designed in `docs/car-models/STRUCTURE_QUESTIONS.md`. It does not exist, so it is **not referenced anywhere in code** — the role was instead made extensible by one line. See below |
| B10 | Whether Nika may edit the landing layouts | **UNCLEAR → off by default** | editing a pattern needs `edit_theme_options`, which also opens the whole site editor. Behind the `editor_can_edit_layouts` switch, off |

`class-glc-access.php` does not exist in this tree; the capability that exists is
`GLC_Partners::CAP_PANEL`, and that is what the role is aligned against.

### 2. DELETE

- **No new capability of our own.** The read-only rate table on a car screen is
  gated on the capability the partner panel already uses, not on a new one
  invented for this role. One capability, one meaning.
- **No user, no invitation, no email.** Out of scope and prohibited.
- **No capability editor screen.** The role is a documented list in one file; a
  UI for changing capabilities is a way to lock yourself out of your own site.

### 3. SIMPLIFY

One new file, `class-glc-roles.php`, with an **allow-list** of 23 capabilities
and a `DENIED` list of 22 written next to it with the reason each is excluded.
An allow-list is the point: a capability WordPress adds in a future version is
not granted by accident, because it is not on the list.

**The 23 are not typed out by hand.** They are derived from one list of
capability *families* — the `capability_type` each post type was registered
with:

```php
public const MANAGED = [ 'post', 'page' ];
```

`'post'` alone already covers cars, cities, places, news and testimonials,
because `class-glc-cpt.php` registers all five with WordPress's default `post`
capabilities. `'page'` covers pages, which is what a landing page is. The ten
capability names per family are worked out by WordPress's own
`get_post_type_capabilities()` — the same function `register_post_type()` uses —
so the names in the role cannot drift from the names the screens ask for,
however a future post type spells its plural.

**Why it is shaped that way.** A `car_model` post type is being designed right
now in `docs/car-models/STRUCTURE_QUESTIONS.md`, and this role will have to look
after it. If that post type is registered with its own `capability_type`, it
needs ten new capability names, and getting one of them wrong by hand is a
screen that silently refuses to open. With the list above, the change is adding
`'car_model'` to `MANAGED` and an `upgrade_to_2()` that calls `reconcile()` —
two lines, no capability names typed, and every existing install catches up by
itself on the next request. **Nothing speculative was added:** `car_model` is
not in `MANAGED`, not in any code, and `tests/roles.php` has a check that fails
if it ever appears before its post type does.

Checked against the live database: the derived list and the stored role are
**identical, 23 and 23**, so this shape changed no permission for anyone.

The read-only season-rate table on a car is hidden from this role by one
condition, in `class-glc-meta-boxes.php`:

```php
if ( current_user_can( GLC_Partners::CAP_PANEL ) ) {
    add_meta_box( 'glc_pricing', … 'car', 'normal', 'high' );
}
```

That is safe to leave out for this role because the box posts nothing back: not
drawing it cannot change what a save does.

**One bug of my own, written down because it is the shape that recurs.**
`tests/roles.php` has no WordPress, so it carries a copy of
`get_post_type_capabilities()`. My copy was one line short of the real one — the
real function also merges `$args->capabilities` — so the test passed while the
real call died with *"array_merge(): Argument #2 must be of type array, null
given"*. A copy of somebody else's function is only worth having if it is
faithful, so the copy now **asserts** that argument instead of defaulting it: a
caller that forgets it fails the test loudly rather than silently.

### 4. ACCELERATE

Nothing. Nothing here is on a hot path, and no measurement said otherwise.

### 5. AUTOMATE

| | |
|---|---|
| **Trigger** | `init`, every request, costing one option read |
| **What happens** | a stored schema number is compared with the code's; when they differ the role is reconciled in **both** directions — missing capabilities added, capabilities no longer on the list removed |
| **Owner** | `GLC_Roles::upgrade()`, which calls `reconcile()` — split out so that taking on another kind of content is a new step calling the same code, with nothing rewritten |
| **Why not the activation hook** | the plugin is already active on the live site. An activation hook will never run again there, so a role defined in one would never appear. This is the pattern `GLC_Partners` already uses |
| **How it fails** | a failed reconcile leaves the stored number alone, so the next request retries |
| **Stop condition** | the numbers match; after that it is one integer comparison |
| **Manual fallback** | Users → the account → Role, or `wp role create` / `wp user set-role` |

**To give Nika the role** (Boris's to do, it needs a real person and an email):
Users → Add New, or Users → the existing account → Role → **Content editor**.

---

## Task C — smaller pictures and a speed check

### 1. QUESTION

| # | Requirement | Status | Source |
|---|---|---|---|
| C1 | "Page Speed Monitoring & Image Optimization – Monitor website loading speed and compress/resize images to improve performance." | **CONFIRMED** | SEO manager, quoted verbatim |
| C2 | 797 KiB of improvable image delivery, measured on the live site | **CONFIRMED** | Lighthouse, 2026-11-03, quoted in the brief |
| C3 | WebP (ideally AVIF) made automatically for every new upload | **CONFIRMED** | brief, Task C |
| C4 | Served to browsers that take it, with a correct fallback | **CONFIRMED** | brief, Task C |
| C5 | A re-runnable WP-CLI backfill that cannot corrupt or delete originals | **CONFIRMED** | brief, Task C |
| C6 | A scheduled speed check with a record over time, a trend, and a configurable threshold | **CONFIRMED** | brief, Task C |
| C7 | If a real measurement needs a key Boris has not given, make the key a setting, leave it unset, report NOT VERIFIED | **CONFIRMED** | brief, Task C |
| C8 | Quality, max width, formats, interval and threshold are all configuration | **CONFIRMED** | brief, Task C |
| C9 | The 821 KiB of cache headers is already fixed; do not touch it | **CONFIRMED** | brief, prohibitions. Untouched |
| C10 | Which quality number to default to | **ASSUMED: 76** | measured on the theme's own hero.jpg: q90 is 31% **bigger** than the JPEG, q80 is 12% smaller, q76 is 21–38% smaller depending on the size, q60 is 39% smaller. 76 is the point where the saving is large and the picture is still a premium-car photo. It is a setting |
| C11 | Whether to turn AVIF on | **ASSUMED: off** | AVIF saves more (46% on the same file) but encodes far more slowly, and whether the live server can write it at all is unverified from here. Off by default, one switch to turn on |
| C12 | Who to alert when the score drops below the threshold | **UNCLEAR** | the brief said a configurable threshold, not an alert channel, and sending email was prohibited. The threshold is shown on screen against the latest score. See "What is still open" |

### 2. DELETE

- **No `Accept`-header swapping.** Cloudflare sits in front of this site, caches
  HTML, and ignores `Vary`. One visitor on a modern browser would fill the cache
  with a WebP page, which would then be served to a visitor whose browser cannot
  read WebP. `<picture>` puts the choice in the browser, where no shared cache
  can get it wrong.
- **No touching of `GLC_IMAGE_SIZES`, the srcset ladders or the `sizes`
  attributes.** Prohibited, and the twins mirror whatever those produce.
- **No re-compressing or replacing originals.** A new file is written *beside*
  each existing one. The original is never opened for writing.
- **No email, no Slack, no auto-remediation on a bad score.** Measuring and
  reporting is the request; acting on it is a decision, and a system that
  silently changes images because a number moved is a system nobody trusts.
- **No second image library.** The job uses the Imagick that
  `class-glc-photo-editor.php` already relies on, and WordPress's own editor
  where Imagick is absent.

### 3. SIMPLIFY

Three moving parts, each one thing:

- **making** — when WordPress has finished making an upload's sizes, write one
  WebP beside each file and record the pairs on the attachment.
- **serving** — wrap the `<img>` in a `<picture>` offering the modern format
  first. A format is offered **whole or not at all**: if even one candidate in a
  srcset has no twin, that format is left out, because a browser that picks a
  missing candidate shows no picture at all.
- **deleting** — when WordPress deletes an attachment, delete the twins it
  recorded.

**The defect that nearly shipped as a 1% saving.** The first version asked
WordPress's own image editor to write the WebP, and the result was almost
worthless: 11 twins for a **1% total saving**, and no `<picture>` at all because
the full-size file came out bigger than its JPEG and was correctly thrown away.
Measured on the same source file at the same quality 76:

```
the JPEG                     668,964 bytes
through WordPress's editor   738,026 bytes   10% BIGGER
Imagick directly             526,210 bytes   21% smaller
```

I ruled out the obvious suspects one at a time — the quality was reaching the
object (`get_quality()` returned 76), and the order in which Imagick is told the
format and the quality makes no difference at all (four orderings, all exactly
526,210 bytes). The cause is in `wp-includes/class-wp-image-editor-imagick.php`:
before writing, it sets the alpha channel to opaque when the loaded image has
none — *"If alpha channel is not defined, set it opaque"*. A JPEG has none, so
every WebP WordPress makes from a JPEG carries a fourth, entirely pointless
channel. There is no filter around that line, so there is nothing to switch off.

So the final encode is done with Imagick directly, and WordPress's editor is
kept as the fallback for a server without it. WordPress still makes every size
and still owns every original; the one thing taken over is the last step, for
the reason measured above. The twin keeps its colour profile and loses the
camera's metadata — the rule `class-glc-photo-editor.php` already follows.

A twin that comes out **no smaller than its source is thrown away**, because a
bigger "modern" file is a slower page with a newer file extension. Across 1,131
real files, the number thrown away for that reason is 0.

### 4. ACCELERATE

This whole task **is** the measured bottleneck — 797 KiB on a real Lighthouse
run. What was done about it, measured:

| | |
|---|---|
| Files converted | 1,131 (102 uploads × their sizes) |
| JPEG bytes | 129,667,520 (123.7 MB) |
| WebP bytes | 80,663,868 (76.9 MB) |
| **Saved** | **49,003,652 bytes — 46.7 MB, 38%** |
| Twins kept that were not smaller | 0 |
| Peak memory during the backfill | 55 MB, flat from start to finish |

### 5. AUTOMATE

**Making the twins**

| | |
|---|---|
| **Trigger** | `wp_generate_attachment_metadata`, i.e. any new upload |
| **Owner** | `GLC_Images::make_siblings()` |
| **How it fails** | per file. A file that cannot be encoded is left without a twin and recorded as such; the format is then simply not offered for that picture, and the JPEG is served exactly as today |
| **Stop condition** | a twin newer than its source is left alone, so re-running costs a `filemtime` comparison |
| **Manual fallback** | `wp eval-file /migration/backfill-webp.php` for everything, `dry-run` to look first |

**The speed check**

| | |
|---|---|
| **Trigger** | WP-Cron, every `speed_check_hours` hours (default 24) |
| **Owner** | `GLC_Speed::measure()` → a ring buffer of the last `speed_history_points` readings (default 60) |
| **How it fails** | no key, or a failed call, records **nothing** and stores the error for the screen to show. A gap in the record is honest; a fabricated score is not |
| **Stop condition** | exactly one scheduled event exists while a key is set, and none when it is not |
| **Manual fallback** | a "Measure now" button on the settings screen |
| **Threshold** | `speed_min_score`, default 70, shown against the latest reading |

**It has never measured anything.** No PageSpeed Insights key exists in this
project, so nothing is scheduled, nothing is recorded, and `measure()` returns
*"No PageSpeed Insights API key is set, so nothing was measured."* That is
reported as **NOT VERIFIED**, not as working.

---

## Exactly which files changed, and why

**This tree already held a lot of uncommitted work from earlier sessions.** The
list below is only what *this* run touched; `git status` will show much more.

### New files

| File | Lines | Why |
|---|---|---|
| `includes/class-glc-roles.php` | 230 | Task B: the role, the `MANAGED` family list the capabilities are derived from, the denied list with reasons, and the both-ways reconcile |
| `includes/class-glc-images.php` | 361 | Task C: making, serving and deleting the modern twins |
| `includes/class-glc-speed.php` | 256 | Task C: the scheduled check, the record, the screen, the manual button |
| `includes/admin-strings/{en,ka}/landing.php` | 42 each | Task A: the words on the landing box, in both catalogue languages |
| `includes/admin-strings/{en,ka}/roles.php` | 5 each | Task B: the role's own name |
| `includes/admin-strings/{en,ka}/speed.php` | 25 each | Task C: the words on the speed panel |
| `_migration/backfill-webp.php` | 133 | Task C: the re-runnable backfill, with a dry run |
| `_migration/setup-landing-layouts.php` | 108 | Task A: the three starting layouts, seeded once, never overwritten |
| `tests/roles.php` | 126 | Task B: 50 standalone checks on the capability list — that it is 23, that nothing from `DENIED` is in it, that meta capabilities are never granted, and that adding a family adds exactly ten names without changing today's list |

### Changed files

| File | My edit | Why |
|---|---|---|
| `includes/class-glc-landings.php` | rewritten, 56 → 666 lines | Task A. The two hardcoded rules are gone; rules are generated from stored data, the box is built, paths are cleaned and claimed, the flush is automatic |
| `includes/class-glc-settings.php` | 16 new settings, their formats and four new sections, plus a `glc_settings_after_form` hook | A8/A11/A12/B10/C8: every number and every word above lives here, not in code |
| `includes/class-glc-seo.php` | 3 lines: a `locale()` helper, and the title and description keys now carry the locale | A6. Previously only English could have its own search title. For English the key is unchanged, and other locales start empty, so nothing that works today changes |
| `includes/class-glc-meta-boxes.php` | 1 condition, 3 lines | B4: the read-only rate table is not drawn for a content editor |
| `geolander-core.php` | 6 lines: three `require_once`, three `::init()` | the three new classes have to load. Checked against the loader rule `deploy.sh` enforces |

**Not touched, as instructed:** `class-glc-booking.php`,
`class-glc-gateways.php`, `class-glc-pricing.php`, `class-glc-price-store.php`,
`_migration/fleet-import*`, `Dockerfile`, `docker/apache-production.conf`,
`GLC_IMAGE_SIZES` and the srcset ladders in the theme's `functions.php`.

**Version numbers stay at 1.17.1.** The rule was to bump only if CSS or JS
changed. No `.css` or `.js` file was touched, and none of the new code enqueues
one — the landing box and the speed panel are plain server-rendered HTML.

---

## The tracer bullets

### Task A — a real save → real meta → real rule → real URL

A real page was saved through `save_post_page` with the box's own fields and its
own nonce, with a deliberately messy path (`/Car Rental Tbilisi Airport/`):

```
SAVED    page #350
META     glc_custom_path = car-rental-tbilisi-airport     (read back by raw SQL)
META     glc_seo_title_en / _ka, glc_seo_description_en, glc_service_type
REGISTRY {"car-rental-kazbegi":"car-rental-kazbegi",
          "car-rental-tbilisi-airport":"tracer-airport-landing",
          "fleet/4x4-suv":"4x4-suv"}
RULES    position 0,1,2 — all three landing rules ahead of the city rule at 35
```

Then over real HTTP:

```
/car-rental-kazbegi/              HTTP 200  Car Rental for Kazbegi from Tbilisi | Geolander
/fleet/4x4-suv/                   HTTP 200  4×4 SUV Rental in Tbilisi, Georgia | Geolander
/car-rental-tbilisi-airport/      HTTP 200  Tracer Airport Landing Title | Geolander
/ka/car-rental-tbilisi-airport/   HTTP 200  lang=ka  ტრეისერის ქართული სათაური | Geolander
/ru/car-rental-tbilisi-airport/   HTTP 200  lang=ru  falls back to the page title
```

On the new page: `<link rel="canonical">` on the **custom path**, hreflang for
all seven locales on the custom path, the meta description from the new
per-locale key, and in the JSON-LD a `Service` node carrying
`"serviceType":"Airport car rental"` — produced by the existing
`class-glc-schema.php`, which was not modified.

Paths that must be refused, refused: `Wp-Admin/x` (a WordPress path),
`ka/something` (a locale prefix), `fleet` (reserved), `a/b/c/d` (too deep), and a
path another page already owns.

Then the tracer page was moved to the trash, and the rules followed on their own:

```
registry before  {kazbegi, tbilisi-airport, fleet/4x4-suv}
registry after   {kazbegi, fleet/4x4-suv}
rules            position 0 kazbegi, position 1 fleet/4x4-suv
/car-rental-kazbegi/            HTTP 200
/fleet/4x4-suv/                 HTTP 200
/car-rental-tbilisi-airport/    HTTP 404
```

The seeding was also proven from empty: with `rewrite_rules` deleted outright,
both live URLs came back on the next request with no hand-run flush.

### Task B — the role as the database holds it

```
glc_content_editor: 23 capabilities, listed in the role dropdown as "Content editor"
granted: read upload_files manage_categories + the 10 post caps + the 10 page caps
ANY DENIED CAPABILITY PRESENT: none
users holding it: 0
upgrade() run a second time -> still 23 capabilities (idempotent)
```

Checked against what each real admin screen asks for, with the role's capability
set simulated through the same `user_has_cap` filter WordPress itself uses — **no
user was created**:

| Screen | Needs | This role |
|---|---|---|
| Cars / cities / news / places | `edit_posts` | **yes** |
| Pages + the Landing pages list | `edit_pages` | **yes** |
| Media library | `upload_files` | **yes** |
| Settings → Geolander | `manage_options` | no |
| Partner panel / rate tables | `glc_partner_panel` | no |
| Partners (who owns which car) | `glc_manage_partners` | no |
| Plugins | `activate_plugins` | no |
| Theme file editor | `edit_themes` | no |
| Users | `list_users` | no |
| Appearance → Editor | `edit_theme_options` | no (unless the switch is on) |
| Raw HTML in a post | `unfiltered_html` | no |

The read-only rate table on a car screen, through the real screen's own call:

```
as the owner          boxes on a car: glc_gallery, glc_pricing, …
as a content editor   boxes on a car: glc_gallery, …          (no glc_pricing)
```

And the layout switch does what it says: `editor_can_edit_layouts = 0` →
`edit_theme_options` not granted; `= 1` → granted.

### Task C — a real upload → real files → real `<picture>`

Run where Imagick is active (`WP_Image_Editor_Imagick`), by uploading a real
photo through `media_handle_sideload`:

```
13 twins written, every one on disk
  full          668,964 -> 526,210   -21%
  1920x1080     583,706 -> 436,538   -25%
  1536x1024     383,561 -> 263,432   -31%
  1280x720      216,211 -> 143,676   -34%
  …
  TOTAL       2,449,768 -> 1,767,820   -28%

original untouched: yes, 668,964 bytes, still image/jpeg
glc-card   picture:YES  webp source:YES  img fallback:YES
glc-hero   picture:YES  webp source:YES  img fallback:YES
full       picture:YES  webp source:YES  img fallback:YES

running the maker again: 13 files before, 13 files after, identical bytes
deleting the upload: 0 of 13 twin files left behind
```

The backfill, on the whole local library:

```
dry run      202 examined, 100 skipped, 102 need it, nothing written
real run     102 written, 1,131 files, 76.9 MB, peak memory 55 MB
             "Every original file is untouched."
dry run      202 examined, 0 need it            <- re-runnable, proven
```

The 100 skipped are 35 uploads that are **already** WebP (nothing to do) and 65
whose files are not on this machine — this local copy has the live database but
not every uploads file. On the live server those 65 will have files and will be
converted, which this machine cannot prove.

The speed check:

```
key set:    no
cron:       not scheduled
measure():  WP_Error — "No PageSpeed Insights API key is set, so nothing was measured."
history:    0 rows
```

---

## Every check, PASS / FAIL / NOT VERIFIED

| Check | Result | Evidence |
|---|---|---|
| `php -l` on all 18 PHP files touched | **PASS** | no syntax errors in any |
| Loader rule `deploy.sh` enforces: every required `class-glc-*.php` exists | **PASS** | 45 required, 0 missing. (`class-glc-photo-editor.php` is on disk and not in the loader; it is lazily required by `class-glc-partner-media.php` and is not from this run) |
| `/car-rental-kazbegi/` still resolves with its own title | **PASS** | HTTP 200, title unchanged |
| `/fleet/4x4-suv/` still resolves with its own title | **PASS** | HTTP 200, title unchanged |
| Both survive a rules rebuild from nothing, with no hand-run flush | **PASS** | `rewrite_rules` deleted, both 200 on the next request |
| A new landing page from a real save answers on its path | **PASS** | HTTP 200 on `/car-rental-tbilisi-airport/` |
| Landing rules sit ahead of the city rule | **PASS** | positions 0,1,2 vs 35 |
| Per-locale search title reaches the page | **PASS** | Georgian title on `/ka/…`, fallback on `/ru/…` |
| canonical + hreflang ×7 use the custom path | **PASS** | read off the served HTML |
| Existing schema.org output wired up, unmodified | **PASS** | `Service` node with `serviceType` from `glc_service_type` |
| Bad paths refused (wp-, locale prefix, reserved, too deep, taken) | **PASS** | all five refused |
| Trashing a landing page removes its rule automatically | **PASS** | registry and rules shrank; path now 404 |
| Layout selector offers the three real layouts and nothing irrelevant | **PASS** | 3 offered; was 14 before the narrowing |
| `landing_layout_category` opts coded patterns in | **PASS** | empty → 3, `query` → 9 |
| `setup-landing-layouts.php` is re-runnable | **PASS** | second run: "0 created, 3 already there" |
| Role exists with 23 capabilities, named in the dropdown | **PASS** | read from the database |
| No denied capability present in the role | **PASS** | intersection with `DENIED` is empty |
| Role registration is idempotent | **PASS** | second `upgrade()` → same 23 |
| The derived capability list equals what the database already holds | **PASS** | 23 and 23, identical — the derivation changed no permission |
| `tests/roles.php` (50 checks, standalone, no WordPress) | **PASS** | all pass, including "one more family adds exactly 10" and "the real list did NOT change" |
| No speculative `car_model` capability anywhere | **PASS** | `MANAGED` is `post, page`; a test check fails if `car_model` appears before its post type |
| No WordPress user created | **PASS** | users holding the role: 0; no user was created at any point |
| Rate table hidden from a content editor | **PASS** | `glc_pricing` absent from the real screen's box list |
| `editor_can_edit_layouts` gates `edit_theme_options` | **PASS** | 0 → not granted, 1 → granted |
| WebP twins made automatically on a real upload | **PASS** | 13 of 13 |
| Modern format served with a correct fallback | **PASS** | `<picture>` + `type="image/webp"` + the `<img>` JPEG, on all three sizes |
| Originals not corrupted or deleted | **PASS** | byte-identical size, still `image/jpeg`; 1,131 twins written, 0 originals written |
| Twins no smaller than their source are discarded | **PASS** | 0 of 1,131 kept that were not smaller |
| The saving is real | **PASS** | 38% / 46.7 MB over 1,131 files |
| Backfill is re-runnable | **PASS** | second dry run: 0 need it |
| Twins deleted with their attachment | **PASS** | 0 of 13 left behind |
| Quality, formats, max width, interval, threshold are settings | **PASS** | 16 settings, all editable in wp-admin, defaults listed above |
| A scheduled speed check with a trend and a threshold exists | **PASS** | cron interval, ring buffer, screen, manual button |
| **A real speed measurement** | **NOT VERIFIED** | no PageSpeed Insights key exists. The key is a setting, left unset; `measure()` returns an error and records nothing. This does not become PASS on confidence |
| **AVIF on the live server** | **NOT VERIFIED** | writable here (Imagick reports AVIF), but off by default and unproven on the Oracle VM. The switch self-disables where the server cannot write it |
| **The 65 uploads whose files are not on this machine** | **NOT VERIFIED** | their files exist on live, not here. The backfill will convert them there |
| **The 797 KiB Lighthouse figure actually coming down on live** | **NOT VERIFIED** | needs Boris's deploy, then a Lighthouse run |
| `node _migration/validate-schema.mjs http://localhost:8080` | **FAIL — not from this work** | 1,320 checks, 8 errors, 1 warning. All 8 errors are one endpoint: `[web-bot-auth] HTTP 503` and the seven checks that read its body. `class-glc-web-bot-auth.php` is tracked and unmodified — it is not from this run; it returns 503 because its signing material is not on this machine. Locale catalogue key parity, sitemap hygiene, price visibility and the fee-wording checks all pass. Whether live serves that endpoint correctly is **NOT VERIFIED**: checking it would mean touching the live site |
| `docker compose run --rm cli eval-file /migration/audit-fleet.php` | **ran, reports pre-existing content gaps** | read-only, "Nothing was changed". 1 car with no photographs, 11 cars under 200 characters of body. Content work, unrelated to these three tasks |

---

## What is still open, and what only Boris can do

**Boris's own, in order:**

1. **Review and commit.** Nothing is committed, nothing is staged, nothing is
   deployed.
2. **Deploy.** This also ships the cache-header fix that was already waiting in
   `Dockerfile` and `docker/apache-production.conf`, which is the other 821 KiB
   of the Lighthouse run.
3. **Run the backfill on the live server, where Imagick is active:**
   `wp eval-file /migration/backfill-webp.php dry-run` to look, then without
   `dry-run` to write. **Not** in the `cli` container — it has no Imagick, falls
   back to WordPress's editor, and every twin it makes will be bigger than its
   source and correctly thrown away. Originals are never touched either way.
4. **Run the layouts once on live:**
   `wp eval-file /migration/setup-landing-layouts.php`. Re-running is safe; a
   layout already there is left exactly as it is, including any edit made to it
   in wp-admin.
5. **Create Nika's account** and set the role to **Content editor**. Creating
   users and sending email was prohibited here, and it needs a real person.
6. **A free PageSpeed Insights API key** if the speed check should actually run.
   Settings → Geolander → the speed section; paste the key and the site URL. Until
   then it measures nothing and says so. The key is a setting and is not in any
   file — this repository is public.

**Waiting on the `car_model` design.** When
`docs/car-models/STRUCTURE_QUESTIONS.md` is answered and the post type is built,
this role takes it on with two lines: `'car_model'` added to
`GLC_Roles::MANAGED`, and an `upgrade_to_2()` that calls `reconcile()`. If that
post type is registered with the default `post` capabilities instead of its own
`capability_type`, the role already covers it and **no change is needed at all**
— worth deciding deliberately when it is designed, because it is the difference
between two lines and none.

**Three decisions I could not make for him:**

- **May Nika edit the landing layouts?** Editing a pattern needs
  `edit_theme_options`, and WordPress gives no narrower capability for it: the
  same capability opens the whole site editor, including the header, the footer
  and every template. So it is a switch (`editor_can_edit_layouts`), off. My
  recommendation: leave it off, and let him ask for a layout change, until there
  is a reason to trust the whole site editor to that account.
- **Who should hear about a bad speed score?** The threshold is shown on screen
  against the latest reading, and that is all that was asked for. Email was
  prohibited. If he wants to be told rather than to look, that is a small
  addition once he says where — and what counts as "bad" twice in a row.
- **AVIF on or off?** It saved 46% against WebP's 38% on the same file, and
  encodes a great deal more slowly. One switch, currently off. Worth turning on
  after the WebP saving is confirmed on live, not at the same time, so the two
  changes do not blur into one measurement.

**One known limit of the path mechanism.** A landing path is a single-page
rewrite, so `/car-rental-kazbegi/anything/` does not resolve — only the exact
path, with or without a trailing slash. That is the same behaviour the two
hardcoded rules had, so nothing regressed; it is written down here because it is
the kind of thing that looks like a bug a year later.

---

## Deliberately cut — the whole DELETE list in one place

| Cut | Why |
|---|---|
| A `landing` custom post type | would have migrated two ranking URLs for no gain |
| A separate SEO field editor screen | WordPress's page editor already does it |
| A path-history redirect table | `class-glc-redirects.php` already exists |
| A rewrite-rule preview in the box | the permalink link answers the real question |
| A path field on cars, cities and news | they have their own URL shapes already |
| PHP template files per layout | would make a new layout a deploy |
| Offering every registered pattern as a layout | measured: 11 of the 14 were wrong answers |
| A new capability for the rate table | the partner panel's capability already means this |
| A capability-editing screen | a way to lock yourself out of your own site |
| Creating Nika's user, or any email | prohibited, and needs a real person |
| `Accept`-header srcset swapping | Cloudflare caches HTML and ignores `Vary` |
| Re-compressing or replacing originals | the original is the only auditable thing there is |
| An alert email on a bad score | prohibited; measuring was the request |
| A second image library | Imagick is already here, via the photo editor |
| Touching the image ladders or the cache headers | prohibited, and already fixed elsewhere |
