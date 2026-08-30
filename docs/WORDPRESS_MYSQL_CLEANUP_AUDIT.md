# WordPress and MySQL production cleanup audit

Date: 2026-08-14  
Environment: Railway `production`

## Outcome

The WordPress uploads volume and MySQL volume were both close enough to their
500 MB Railway quotas to require cleanup. The main causes were stale generated
image cache files, WordPress revision history, abandoned plugin data, table
fragmentation, and an oversized MySQL memory configuration.

No active business content, bookings, users, published pages, attachment
records, or referenced media were deleted.

| Resource | Before | After |
| --- | ---: | ---: |
| WordPress uploads filesystem | 424 MB / 434 MB (100%) | 217 MB / 434 MB (52%) |
| MySQL filesystem | 287 MB / 434 MB (68%) | 190 MB / 434 MB (45%) |
| MySQL application schema directory | 81 MB | 17 MB |
| WordPress container memory | 190 MB current / 313 MB peak | 193 MB current / 313 MB peak |
| MySQL container memory | 808 MB current | 170 MB current / 229 MB peak after restart |
| OOM kills | 0 | 0 |

Railway's dashboard volume figure can lag the filesystem. `df` inside each
running container is the authoritative immediate post-cleanup measurement.

## WordPress uploads audit

- 274 attachment records referenced 1,518 files totaling 213.4 MB.
- 1,557 stale Autoptimize/Airlift cache files under `al_opt_content` consumed
  about 207 MB. The related plugin was absent and live pages contained no
  references to the cache path, so the cache was removed.
- 41 unreferenced dated upload files were zero-byte remnants and were removed.
- All referenced dated media was retained.
- No PHP-family executable files were found in uploads.
- Apache denies PHP-family execution from the uploads volume as a separate
  defense-in-depth control.

## WordPress database audit and cleanup

The database contained legacy data from Elementor, Hello Elementor, Rank Math,
LiteSpeed Cache, Autoptimize/Airlift, BlogVault, WPvivid, FileBird, WP-Optimize,
WP File Manager, and Action Scheduler. Only `geolander-core` is active in the
deployed application.

Removed:

- 492 revisions, including 16.676 MB of Elementor revision metadata.
- 125 trashed cars and 5 trashed pages.
- 1 auto-draft.
- 69 transient rows; four normal transients regenerated during verification.
- 331 options belonging to absent plugins, totaling about 1.14 MB.
- 23 abandoned plugin tables, including a 4 MB BlogVault/Airlift config table.
- Empty orphan metadata/relationship rows: none existed.

Retained deliberately:

- 18 published pages, 19 published cars, 36 places, 13 FAQs, 4 cities,
  3 bookings, testimonials, and navigation/global-style records.
- 274 attachment records and all referenced media.
- Published legacy Elementor page metadata and nine Elementor library records.
  They are not loaded by the active theme, but were retained because they are
  historical content rather than objectively regenerable cache.

After cleanup, only the 12 standard WordPress tables remain. `wp_posts`,
`wp_postmeta`, and `wp_options` were rebuilt and analyzed to reclaim allocated
space. Autoloaded option payload is approximately 0.06 MB.

## MySQL memory and disk audit

Before tuning:

- `innodb_buffer_pool_size`: 1 GB.
- InnoDB data resident in the pool: about 85 MB.
- `max_connections`: 151; observed maximum: 36.
- `table_open_cache`: 4,000 for a final 12-table schema.
- `innodb_log_buffer_size`: 64 MB.
- `innodb_redo_log_capacity`: 100 MB.
- Temporary tables written to disk: 0.
- InnoDB buffer-pool read hit rate: greater than 99.999%.

Persisted production settings:

| Setting | Value |
| --- | ---: |
| `innodb_buffer_pool_size` | 256 MB |
| `innodb_buffer_pool_instances` | 1 |
| `innodb_log_buffer_size` | 16 MB |
| `innodb_redo_log_capacity` | 64 MB |
| `max_connections` | 64 |
| `table_open_cache` | 256 |

The settings are stored through MySQL `SET PERSIST`/`SET PERSIST_ONLY` in the
private data volume and survive container restarts. The database restarted
successfully and accepted connections with these values.

## Backup and recovery

A transactionally consistent pre-cleanup dump is stored privately at:

`/var/lib/mysql/.geolander-before-cleanup-20260814.sql.gz`

- Size: 3.5 MB.
- SHA-256: `46f933a002b6ec954632d9879dff789e620d1d6dbff2b41cc1866aba10ef0f10`.
- Gzip integrity was verified before and after the MySQL restart.
- The file is not web-accessible and is mode `0600` on the MySQL volume.

Retain it through 2026-08-21, then remove it after confirming no historical
content rollback is required.

## Preventive controls

- Apache PHP workers are capped at eight and recycled every 250 connections.
- WordPress revisions are capped at five per post by `GLC_Perf`.
- Generated caches are not treated as durable media.
- The uploads and database volumes should be alerted at 75% and reviewed at
  least monthly.
- Do not manually delete MySQL redo, undo, `ibdata`, or system files. Their
  sizes are managed by MySQL and direct deletion can corrupt the database.

## Verification

- Homepage: HTTP 200 with approximately 65 KB HTML.
- Vehicle detail page: HTTP 200 with approximately 47 KB HTML.
- Login and health endpoints: HTTP 200.
- WordPress cgroup OOM events: 0.
- MySQL cgroup OOM events: 0.
- MySQL 9.4 startup completed without recovery errors after tuning.
