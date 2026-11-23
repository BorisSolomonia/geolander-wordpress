# Runtime security and memory audit — 2026-08-29

## Executive finding

The current repository did **not** reproduce a PHP memory leak or a cryptocurrency miner. A 2,000-request load test completed without failures or cgroup OOM events. The exact reason for Railway's most recent enforcement action cannot be proven from this machine: the Railway CLI account no longer has access to the affected project, so its process list, metrics, and incident logs were unavailable.

There is nevertheless evidence in the earlier incident record that the old deployment was genuinely compromised: WordPress core `index.php` contained an unauthorized recursive-copy payload. That payload is absent from this source tree, and current WordPress core checksums pass. The safest conclusion is that the writable old runtime/volume was compromised; it is not evidence that the reviewed project code intentionally mined cryptocurrency.

Do not attach any old WordPress web-root or database volume to the replacement deployment. Start from the reviewed image, a new database, and a new uploads volume; migrate only inspected media and required business records.

## What was measured

| Check | Baseline | Hardened image |
|---|---:|---:|
| WordPress | cached floating image contained 7.0 | pinned 7.0.4; checksums pass |
| Apache PHP workers | up to 8, recycled after 250 requests | up to 4, recycled after 250 requests |
| 2,000 homepage requests, concurrency 8 | 2,000/2,000; 122.15 req/s | 2,000/2,000; 30.01 req/s on the final lean image |
| Peak cgroup memory in hardened run | not comparable after rebuild | 219,070,464 bytes (~208.9 MiB) |
| Post-load cgroup memory | — | 179,625,984 bytes; 123,408,384 bytes was file cache |
| OOM / OOM-kill events | 0 | 0 |
| Fixable HIGH/CRITICAL OS findings | 259 total, including 3 critical | 0 after pinning the current base and applying Debian upgrades |
| WordPress code writable by PHP user | yes in stock image initialization | no; only uploads remain writable |
| Docker migration layer | 949 MB | 89 MB |

The lower hardened throughput is intentional. Four bounded PHP workers queue excess requests instead of allowing a small service to create a large set of memory-bearing processes. The post-load process list contained Apache only; no unexplained CPU consumer or long-running worker was present.

## Confirmed application issue

The public checkout limiter previously stored two WordPress transient rows for every new client IP. Because proxy headers can be rotated or spoofed, 64 invalid requests produced 128 new `wp_options` rows. That is attacker-controlled persistent database growth and can contribute to volume exhaustion, although it is not a PHP heap leak.

The limiter now:

- validates the request before writing rate state;
- uses one fixed, non-autoloaded option with a bounded counter;
- removes legacy `glc_rl_*` transient rows once;
- leaves per-client abuse control to Cloudflare, where the real client identity is established.

The same 64 invalid requests now return 404 and create zero rate-limit rows.

## Runtime changes

- Pinned `wordpress:7.0.4-php8.3-apache` and upgraded installed Debian packages during the build.
- Pinned WP-CLI 2.12.0 and verified its published SHA-512 digest during the build.
- Removed bundled inactive plugins and themes from the production image.
- Reduced Apache prefork concurrency to four workers and retained child recycling every 250 requests.
- Added slow-client timeouts, short keep-alives, request-size ceilings, and early oversized-body rejection.
- Blocked `xmlrpc.php`, `wp-load.php`, and web-triggered `wp-cron.php` before WordPress runs.
- Disabled WordPress code editing, plugin/theme installation and updates, automatic updates, and request-driven cron in production.
- Made core, theme, plugin, and configuration files read-only to `www-data`; kept only uploads writable.
- Denied PHP-family execution from uploads.
- Replaced the PHP health probe with static `/healthz`.
- Bounded PHP memory and execution/input time, reduced OPcache memory, and disabled unused process-execution functions.
- Expanded the diagnostic snapshot to show all CPU consumers, cgroup composition/OOM events, writable code, upload payloads, and the Apache scoreboard.
- Added server-side and browser-side input length limits to booking and contact paths.
- Re-encoded 113 deployment fleet sources to bounded 1920px JPEGs: 875.6 MiB became 55.9 MiB, while all 15 sidecars were preserved. The 949 MB Docker migration layer is now 89 MB and the final local image is 372,602,306 bytes.

## Verification performed

- All changed PHP files: syntax valid.
- Apache configuration: syntax valid.
- WordPress 7.0.4: official core checksums pass (the image's expected `wp-config-docker.php` produces a harmless extra-file warning).
- Runtime constants: file edits/modifications, automatic updater, and request-driven cron disabled; PHP limits are 96 MiB normal / 128 MiB maximum.
- Runtime ownership: code is `root:root` and not writable by `www-data`; uploads are writable by `www-data`; `wp-config.php` is `root:www-data` mode 0640.
- Public probes: `/healthz` 200; homepage 200; `xmlrpc.php`, `wp-load.php`, and `wp-cron.php` 403; declared API bodies over 64 KiB 413.
- Installed executable WordPress extensions: only `geolander-core` and the `geolander` theme.
- Repository contract suite: 15/15 PowerShell test files passed.
- Trivy image scan: zero fixable HIGH or CRITICAL OS-package findings at audit time.
- Gitleaks: 66 commits scanned with redaction enabled; no credential leaks found.
- Git object connectivity: valid. The existing `.git` directory is unusually large (~845 MiB of loose objects plus temporary garbage), so do not copy it into the replacement repository.

## Required replacement-deployment controls

1. Create a new Railway project, new database service, and new uploads volume. Never reconnect the old `/var/www/html` volume.
2. Rotate every credential that touched the old environment: database password, WordPress administrator sessions/passwords, Railway tokens, SMTP credentials, Cloudflare API/Access credentials, payment credentials, and WordPress salts.
3. Import only reviewed database content and non-executable media. Do not copy an old WordPress core, plugin, theme, cache, temporary, or session directory.
4. Run due cron events from a scheduled trusted command (`wp cron event run --due-now`); HTTP access to `wp-cron.php` is intentionally denied.
5. Put Cloudflare rate limits in front of login and public write endpoints. Origin limiting is a final storage ceiling, not a substitute for edge enforcement.
6. Configure MySQL for the selected memory plan and verify its actual resident memory before opening traffic.
7. After first boot, run core checksums and the supplied memory snapshot. Treat any writable PHP outside uploads, PHP inside uploads, unexplained process, checksum change, or OOM event as an incident.
8. Keep the WordPress base tag pinned and rebuild regularly. A successful build is not a permanent vulnerability waiver.
9. Create the replacement Git repository from a clean export of the working tree (excluding `.git`), then make a new initial commit. This avoids carrying the oversized loose-object store and any unreachable historical blobs to the new remote.

The original full-resolution fleet sources were preserved locally at ignored path `_migration/fleet-import-raw/`. Back that directory up separately if those originals are needed; it is intentionally excluded from both Git and the Docker build.

## Useful production commands

```text
wp core verify-checksums
wp plugin list
wp theme list
wp cron event run --due-now
geolander-memory-snapshot
```

For an appeal or forensic conclusion, obtain Railway's enforcement timestamp, process command line, container/image digest, outbound destination, CPU graph, OOM events, and affected volume identifiers. Without those artifacts, it would be speculation to label the latest event either a miner or only a memory problem.
