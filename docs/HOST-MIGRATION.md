# Geolander host migration

For a complete operator-ready procedure, including Oracle instance creation,
Cloudflare Tunnel setup, secret generation, cutover, backups, and rollback, use
[`docs/ORACLE-DEPLOYMENT-RUNBOOK.md`](ORACLE-DEPLOYMENT-RUNBOOK.md).

This deployment replaces Railway with one Linux VM while preserving the existing
containerized WordPress architecture. WordPress and MariaDB remain private. The
only public path is an outbound-only Cloudflare Tunnel to `wordpress:80`.

## Host choice

Oracle Cloud Always Free is the preferred host: one Ampere A1 VM with 2 OCPUs,
12 GB RAM, and a 50 GB boot disk is comfortably above the measured Geolander
working set. Google Cloud's Free Tier `e2-micro` is a fallback, but its 1 GB RAM
is tight and Google bills nearly every hour of a public IPv4 address. Render and
Koyeb free services are not suitable because their free filesystems are not
persistent.

Do not point `geo-lander.com` at the replacement until all local health and
schema checks pass. Do not copy WordPress core, plugins, themes, caches, sessions,
or executables from a previously compromised runtime.

## VM prerequisites

- Ubuntu 24.04 LTS (ARM64 on Oracle A1; AMD64 on Google)
- Docker Engine with the Compose plugin
- Git deploy access to `mrbokoko1qa-dot/geolander-wordpress`
- A new Cloudflare Tunnel token scoped to this deployment
- At least 2 GB RAM recommended; add swap when using a 1 GB VM

## Deploy

```sh
git clone https://github.com/mrbokoko1qa-dot/geolander-wordpress.git
cd geolander-wordpress
cp .env.host.example .env.host
chmod 600 .env.host
# Fill every CHANGE_ME value with a newly generated credential.
mkdir -p data/mysql data/uploads
docker compose --env-file .env.host -f compose.host.yml config --quiet
docker compose --env-file .env.host -f compose.host.yml up -d --build
docker compose --env-file .env.host -f compose.host.yml ps
curl --fail http://127.0.0.1:8080/healthz
```

In Cloudflare Tunnel, set the public hostname `geo-lander.com` to the service
URL `http://wordpress:80`. Add `www.geo-lander.com` to the same tunnel and make
the application redirect it to the canonical apex URL. Delete the obsolete
Railway and Netlify DNS routes only after the tunnel reports healthy.

## Fresh WordPress bootstrap

Generate a new administrator password locally and do not put it in Git or shell
history. Run the installation command interactively in the WordPress container,
then apply the deterministic migration scripts:

```sh
docker compose --env-file .env.host -f compose.host.yml exec wordpress sh
wp core install --allow-root --url=https://geo-lander.com --title=Geolander \
  --admin_user=geolander-admin --admin_email=info@geo-lander.com --prompt=admin_password
wp plugin activate geolander-core --allow-root
wp theme activate geolander --allow-root
wp rewrite structure '/%postname%/' --allow-root
wp eval-file /migration/import.php --allow-root
wp eval-file /migration/convert-place-images-webp.php --allow-root
wp eval-file /migration/import-fleet.php --allow-root
wp eval-file /migration/consolidate-fleet.php --allow-root
wp eval-file /migration/sync-owner-facts.php --allow-root
wp eval-file /migration/sync-booking-facts.php --allow-root
wp eval-file /migration/setup-pages.php --allow-root
wp eval-file /migration/setup-seo.php --allow-root
wp eval-file /migration/setup-cities.php --allow-root
wp eval-file /migration/publish-route-guides.php --allow-root
wp eval-file /migration/setup-seo-pages.php --allow-root
wp eval-file /migration/setup-seo-p2-p3.php --allow-root
wp eval-file /migration/setup-agent-readiness.php --allow-root
wp eval-file /migration/setup-reputation-trust.php --allow-root
wp rewrite flush --allow-root
wp eval-file /migration/audit-fleet.php --allow-root
exit
```

## Verification before DNS cutover

```sh
docker compose --env-file .env.host -f compose.host.yml ps
docker compose --env-file .env.host -f compose.host.yml logs --tail=200
curl --fail http://127.0.0.1:8080/healthz
docker compose --env-file .env.host -f compose.host.yml exec wordpress \
  geolander-memory-snapshot
docker compose --env-file .env.host -f compose.host.yml exec wordpress \
  wp core verify-checksums --allow-root
```

After the Cloudflare cutover, verify the homepage, WordPress sitemap, 404 status,
all `.well-known` discovery resources, HTML/Markdown content negotiation, booking
flow, and admin login. Then run:

```sh
node _migration/validate-schema.mjs https://geo-lander.com
```

If Chrome still shows a dangerous-site interstitial after the clean origin is
live, use the verified Google Search Console property to inspect **Security &
Manual Actions > Security issues** and request a review. Hosting migration and
Safe Browsing review are separate operations.

## Backups

Back up `data/mysql` with `mariadb-dump` and `data/uploads` as separate encrypted
artifacts. Test a restore before relying on the backup schedule. Keep at least
one copy outside the VM and outside the hosting account.
