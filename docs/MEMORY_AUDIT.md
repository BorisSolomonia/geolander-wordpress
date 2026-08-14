# BOR-90 — Railway memory audit

Date: 2026-08-14
Service: `geolander-wordpress` / Railway production

## Conclusion

The application did not exhibit a continuously retained PHP heap or an OOM
failure. Railway's container metric was dominated at times by reclaimable
filesystem cache, while the only exceptional 30-day spike coincided with
one-time fleet/photo import deployments.

There was still a verified resource-exhaustion risk: Apache prefork could grow
from 5 to 150 mod_php children and never recycle them while the public service
was receiving sustained hostile requests. The previous container was also
compromised. The production image now bounds and recycles the worker pool and
rejects the observed abusive entry points before PHP runs.

## Evidence before the change

| Measurement | Result |
|---|---:|
| Railway memory, 7-day average | 254.24 MiB |
| Railway memory, 7-day maximum | 424.80 MiB |
| Railway memory limit | 8 GiB |
| Cgroup OOM / OOM-kill events | 0 / 0 |
| Live cgroup sample | 330.75 MB |
| Anonymous/process portion of that sample | 67.33 MB |
| Reclaimable file-cache portion | 243.12 MB |
| Apache children observed | 8–9 |
| Apache worker ceiling before fix | 150 |
| Worker recycling before fix | Never (`0`) |

The 30-day Railway maximum was 3.40 GiB at 2026-07-21 12:00 UTC. The
next-largest sample was 1.38 GiB at 08:00 UTC that day. Both align with the
fleet/photo import deployments. Normal serving samples repeatedly returned
toward baseline and were generally below about 0.52 GiB, which is inconsistent
with a monotonic leak.

The live cgroup's `memory.stat` distinguished the growing page cache from
anonymous process memory. PHP runs under request-isolated mod_php; the codebase
has no custom long-running PHP worker, queue, or recurring background job, and
the application audit found no unbounded in-process cache or collection.

Railway logs did reveal sustained automated POST traffic to `/xmlrpc.php` and
directly to `/wp-load.php`. The prior container's core `index.php` contained
unauthorized recursive-copy code. After a clean redeploy, `wp core
verify-checksums` passed. The persistent uploads volume contained no executable
payload: its only `.php` file was a two-byte `<?php` index marker and no
`.phtml` files existed.

## Changes

- Prefork starts with 2 workers, keeps 2–4 idle, and permits at most 8
  concurrent PHP requests.
- Each child is recycled after 250 connections, bounding allocator/process
  high-water retention.
- Keep-alive and request timeouts are constrained so slow clients cannot pin a
  PHP worker indefinitely.
- Unused XML-RPC and direct `wp-load.php` requests are denied by Apache before
  WordPress boots.
- PHP-family files are denied in the persistent uploads volume.
- Apache logs now include worker PID and request duration.
- `/_internal-apache-status?auto` exposes the worker scoreboard to localhost
  only. `geolander-memory-snapshot` reports cgroup composition, OOM events,
  Apache RSS, and the scoreboard over `railway ssh`.
- Railway once again checks `/health.php` during deploys.
- The MPM cleanup wrapper now delegates into the official WordPress entrypoint
  with `apache2-foreground`, so a clean image start initializes WordPress and
  the health endpoint without relying on Railway's dashboard start override.

The eight-worker cap is intentional: even if all eight requests reached PHP's
256 MiB per-script limit simultaneously, the theoretical script allocation is
2 GiB plus the measured baseline/cache, well within the current 8 GiB service
limit. Normal observed traffic did not need that concurrency.

## Verification and operating procedure

The final local image was built from the refreshed official
`wordpress:php8.3-apache` base and produced `Syntax OK` from `apache2ctl -t`.
Runtime verification produced:

| Check | Result |
|---|---:|
| Sustained PHP requests | 4,000 |
| Concurrency | 8 |
| Successful / failed | 4,000 / 0 |
| Throughput | 554.4 requests/second |
| Cgroup memory before | 133.77 MB |
| Cgroup memory immediately after | 135.79 MB |
| Cgroup memory after 30 seconds idle | 135.70 MB |
| Cgroup peak during test | 149.19 MB |
| OOM / OOM-kill events | 0 / 0 |

The initial Apache child PIDs were 24 and 25. After the workload they were
60–63, demonstrating that the 250-connection recycle policy operated. The
idle pool settled at four and memory stayed within about 2 MB of its pre-test
level. Health returned 200; XML-RPC, direct `wp-load.php`, a PHP probe in
uploads, and external server-status requests returned 403; localhost
server-status returned the prefork scoreboard.

Run the local contract check:

```powershell
& .\tests\runtime-memory-config.test.ps1
```

Validate the built image:

```powershell
docker build -t geolander-wordpress:bor-90 .
docker run --rm geolander-wordpress:bor-90 apache2ctl -t
```

After deployment, take comparable snapshots before, immediately after, and
after an idle/reclamation period around a sustained workload:

```sh
railway ssh -s geolander-wordpress geolander-memory-snapshot
railway metrics -s geolander-wordpress --since 6h --memory --cpu --http
```

Interpret `memory.current` together with `memory.stat`: growth in `file` that
falls under pressure is normal cache; continuously growing `anon`, worker
counts above the configured ceiling, or non-zero `oom_kill` requires further
investigation. Use Railway's 30-day graph and its deployment markers to avoid
mistaking image-import peaks for the web service's steady-state footprint.

## Remaining operational risks

- Rotate all WordPress administrator, database, payment, Railway, and backup
  credentials that were valid while the old container was compromised.
- Review WordPress users and database content for unauthorized changes. A
  clean immutable container cannot prove that the database was untouched.
- The uploads volume is 496.7 MB of a 500 MB allocation. This is a separate,
  immediate availability risk; increase or clean it through a reviewed backup
  process before it fills.
- The database retains active-plugin and cron entries for plugins absent from
  the immutable image. They are not loaded and did not cause retained memory,
  but should be cleaned in a separate, backed-up maintenance task.
