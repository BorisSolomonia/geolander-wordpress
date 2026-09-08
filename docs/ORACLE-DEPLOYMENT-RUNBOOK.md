# Geolander Oracle Cloud deployment runbook

This is the reproducible deployment procedure for `mrbokoko1qa-dot/geolander-wordpress`.
It deploys a clean WordPress/MariaDB stack on an Oracle Cloud Always Free ARM VM and
publishes it through an outbound-only Cloudflare Tunnel. The database and WordPress
HTTP port are never exposed to the Internet.

The procedure is intentionally written so an operator or another LLM can execute it
without relying on the previous host. Do not copy a WordPress web root, database
volume, cache, plugin, theme, or secret from a previously compromised host.

## 1. Values and access required before starting

Have these ready:

- Oracle Cloud account with permission to create a compute instance, VCN, subnet,
  public IPv4 address, and boot volume in the selected region.
- GitHub read access to `https://github.com/mrbokoko1qa-dot/geolander-wordpress`.
- Cloudflare access to the `geo-lander.com` zone and Zero Trust Tunnels.
- A domain email address for WordPress administration (for example,
  `info@geo-lander.com`). SMTP credentials are optional during the first deploy.
- A workstation with Git, SSH, and an SSH key. Keep the private key outside the repo.

Never put any of the following in Git, a ticket, or chat: database passwords, the
Cloudflare tunnel token, WordPress admin passwords, SMTP passwords, API tokens, or
the Web Bot Auth private key.

## 2. Create the Oracle VM

In Oracle Cloud Console, choose **Compute → Instances → Create instance**:

1. Name it `geolander-prod`.
2. Image: Ubuntu 24.04 LTS, ARM64.
3. Shape: `VM.Standard.A1.Flex`, 2 OCPUs and 12 GB memory (1 OCPU/6 GB is the
   practical minimum for this stack).
4. Boot volume: at least 50 GB.
5. Networking: create a VCN and a **public subnet**, automatically assign a public
   IPv4 address, and leave IPv6 disabled unless your network requires it.
6. SSH: upload or paste the public key. Download the private key immediately if
   Oracle generated the pair; it cannot be downloaded again.

Record the VM public IP. The only inbound port needed is TCP 22 for administration;
HTTP and HTTPS will arrive through Cloudflare Tunnel, not through the VM firewall.

Connect and install the base packages:

```bash
ssh -i ~/.ssh/geolander-oracle ubuntu@VM_PUBLIC_IP
sudo apt-get update
sudo apt-get install -y ca-certificates curl git ufw openssl
curl -fsSL https://get.docker.com | sudo sh
sudo usermod -aG docker "$USER"
sudo systemctl enable --now docker
exit
```

Reconnect so the Docker group applies, then configure the firewall:

```bash
ssh -i ~/.ssh/geolander-oracle ubuntu@VM_PUBLIC_IP
sudo ufw default deny incoming
sudo ufw default allow outgoing
sudo ufw allow 22/tcp
sudo ufw --force enable
sudo ufw status verbose
docker version
docker compose version
```

Do not open ports 80, 443, 3306, or 8080. The Compose file binds WordPress to
`127.0.0.1:8080` only, and Cloudflare Tunnel makes the outbound connection.

## 3. Create a clean deployment directory and secrets

Clone the exact production branch and create persistent directories:

```bash
sudo mkdir -p /opt/geolander
sudo chown -R "$USER":"$USER" /opt/geolander
git clone --branch main --depth 1 \
  https://github.com/mrbokoko1qa-dot/geolander-wordpress.git /opt/geolander
cd /opt/geolander
mkdir -p data/mysql data/uploads
cp .env.host.example .env.host
chmod 600 .env.host
```

Generate independent high-entropy database credentials. Run this locally or on the
VM; do not paste the output into a shell command that will be saved in history:

```bash
openssl rand -base64 32       # MARIADB_PASSWORD
openssl rand -base64 32       # MARIADB_ROOT_PASSWORD
```

Generate the Web Bot Auth Ed25519 secret in the format used by the plugin (the
base64-encoded 64-byte Sodium secret key):

```bash
php -r 'echo base64_encode(sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair())), PHP_EOL;'
```

Edit `.env.host` and replace every `CHANGE_ME` value:

```dotenv
GLC_SITE_URL=https://geo-lander.com
GLC_HOST_LOOPBACK_PORT=8080
MARIADB_DATABASE=geolander
MARIADB_USER=geolander
MARIADB_PASSWORD=<unique database password>
MARIADB_ROOT_PASSWORD=<different unique root password>
CLOUDFLARE_TUNNEL_TOKEN=<token created in section 4>
GLC_CF_ACCESS_TEAM_DOMAIN=<your Cloudflare Access team domain>
GLC_CF_ACCESS_AUD=<your Cloudflare Access application audience tag>
GLC_WEB_BOT_AUTH_PRIVATE_KEY=<base64 Sodium secret key>
GLC_SMTP_HOST=
GLC_SMTP_PORT=587
GLC_SMTP_USERNAME=
GLC_SMTP_PASSWORD=
GLC_SMTP_ENCRYPTION=tls
GLC_SMTP_FROM=info@geo-lander.com
```

`GLC_CF_ACCESS_TEAM_DOMAIN` and `GLC_CF_ACCESS_AUD` are public identifiers, but
must match the Cloudflare Access application protecting the agent API. They are not
Cloudflare API credentials. If Access is not being used yet, leave them empty and
do not advertise protected agent calls as ready.

Validate the Compose interpolation before starting anything:

```bash
docker compose --env-file .env.host -f compose.host.yml config --quiet
```

## 4. Create the Cloudflare Tunnel

In Cloudflare Dashboard:

1. Select the `geo-lander.com` account/zone.
2. Go to **Zero Trust → Networks → Tunnels → Create a tunnel**.
3. Choose **Cloudflared**, name it `geolander-oracle`, and create it.
4. Copy the Linux Docker connector token. This is a tunnel token, not an API token.
5. Put the token only in `.env.host` as `CLOUDFLARE_TUNNEL_TOKEN`.
6. Add public hostnames:
   - `geo-lander.com` → `http://wordpress:80`
   - `www.geo-lander.com` → `http://wordpress:80`

Use **HTTP**, not HTTPS, for the origin service: TLS terminates at Cloudflare and
the tunnel reaches the private Docker service by its Compose name. Do not expose the
token in terminal output or screenshots.

## 5. Build and start the stack

```bash
cd /opt/geolander
docker compose --env-file .env.host -f compose.host.yml up -d --build
docker compose --env-file .env.host -f compose.host.yml ps
curl --fail http://127.0.0.1:8080/healthz
```

Expected services are `db` (healthy), `wordpress` (healthy), and `tunnel` (running).
If WordPress restarts, inspect logs before proceeding:

```bash
docker compose --env-file .env.host -f compose.host.yml logs --tail=200 wordpress db tunnel
```

## 6. Fresh WordPress installation and migrations

Run this only against a new empty database. The admin password prompt is interactive
so it does not enter shell history:

```bash
cd /opt/geolander
docker compose --env-file .env.host -f compose.host.yml exec wordpress sh
wp core install --allow-root --url=https://geo-lander.com --title=Geolander \
  --admin_user=geolander-admin --admin_email=info@geo-lander.com \
  --prompt=admin_password
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

The importer is safe to rerun for updates, but `wp core install` is not a migration
step for an already-installed database. Never run it against a database you intend
to preserve.

## 7. Pre-cutover security checks

Run these before changing DNS:

```bash
cd /opt/geolander
docker compose --env-file .env.host -f compose.host.yml exec wordpress \
  wp core verify-checksums --allow-root
docker compose --env-file .env.host -f compose.host.yml exec wordpress \
  /usr/local/bin/geolander-memory-snapshot
curl --fail http://127.0.0.1:8080/healthz
```

The memory snapshot should show only expected Apache processes, `oom=0`, no PHP or
executable files in uploads, and no unexplained CPU process. Treat any other result
as a release blocker.

## 8. DNS cutover

Wait until the tunnel connector is **Healthy** and its prechecks pass. In Cloudflare
DNS, remove only the old apex and `www` records that point to the previous host:

- old Railway apex record
- old Netlify `www` record

Do not delete MX, SPF, DKIM, verification TXT, DNS-AID, or unrelated records. The
tunnel hostname setup will create the Cloudflare-managed CNAME targets. Confirm that
no apex/`www` record still points to Railway or Netlify.

## 9. Public verification and agent checks

Run from a Linux host (this avoids Windows Schannel/TLS false negatives):

```bash
curl -fsSI https://geo-lander.com/
curl -fsSI https://www.geo-lander.com/
curl -fsS -o /dev/null -w '%{http_code}\n' \
  https://geo-lander.com/a-path-that-does-not-exist
curl -fsSI https://geo-lander.com/.well-known/api-catalog
curl -fsSI https://geo-lander.com/.well-known/agent-card.json
curl -fsSI https://geo-lander.com/.well-known/http-message-signatures-directory
curl -fsSI https://geo-lander.com/auth.md
curl -fsS -D - -o /dev/null -H 'Accept: text/markdown' https://geo-lander.com/ \
  | grep -Ei '^(HTTP/|content-type:|vary:|link:)'
```

The expected results are 200 for the homepage and machine-readable resources, 404
for the nonexistent path, and `Vary: Accept,Accept-Encoding` for markdown
negotiation. Confirm the homepage `Link` headers include `api-catalog`,
`service-desc`, `service-doc`, and `describedby`.

From a checkout containing Node.js, run the full schema guard:

```bash
node _migration/validate-schema.mjs https://geo-lander.com
```

It must report zero errors. Warnings require review before announcing the deployment.

## 10. Operations and updates

Pull and redeploy only reviewed commits:

```bash
cd /opt/geolander
git fetch origin main
git checkout --detach origin/main
docker compose --env-file .env.host -f compose.host.yml up -d --build
docker compose --env-file .env.host -f compose.host.yml ps
curl --fail http://127.0.0.1:8080/healthz
docker compose --env-file .env.host -f compose.host.yml exec wordpress \
  wp plugin list --allow-root --fields=name,version | grep geolander-core   # must show the new version
```

The web root `/var/www/html` is a Docker volume, so a rebuild alone does not replace
served code: `docker/hardened-apache-start.sh` re-syncs `geolander-core` and the
theme from the image on every container start (added 2026-09-08 after a rebuild left
the web root on the previous plugin version). If the version check above still shows
the old number, the running container predates that script — restart it once.

If `/opt/geolander` is not a git checkout (the first deployment was made from an
exported tree), replace the `git` lines with a stream from a workstation:
`git archive --format=tar HEAD | ssh ubuntu@VM 'cd /opt/geolander && tar xf -'`
and never extract over `data/` or `.env.host` (the archive contains neither).

After a release that adds migrations, run them explicitly, for example:

```bash
docker compose --env-file .env.host -f compose.host.yml exec wordpress sh -c \
  'wp eval-file /migration/<script>.php --allow-root && wp rewrite flush --allow-root'
```

Never run `git clean -fdx` in `/opt/geolander`; it can remove persistent deployment
data if the directory layout changes. Keep `.env.host`, `data/mysql`, and
`data/uploads` outside Git tracking.

Useful diagnostics:

```bash
docker compose --env-file .env.host -f compose.host.yml logs --tail=200
docker stats --no-stream
docker compose --env-file .env.host -f compose.host.yml exec wordpress \
  /usr/local/bin/geolander-memory-snapshot
```

## 11. Backups and restore

Create encrypted, off-host backups. A database directory copy alone is not a safe
logical backup while MariaDB is running:

```bash
mkdir -p /opt/geolander/backups
set -a; . /opt/geolander/.env.host; set +a
docker compose --env-file .env.host -f compose.host.yml exec -T db \
  mariadb-dump --single-transaction --routines --events -u root \
  -p"$MARIADB_ROOT_PASSWORD" geolander \
  | gzip > /opt/geolander/backups/geolander-$(date +%F).sql.gz
tar -C /opt/geolander -czf /opt/geolander/backups/uploads-$(date +%F).tar.gz data/uploads
```

The production password is in `.env.host`; load it into a protected shell rather
than committing it. Copy backups to a different account/provider and periodically
test restoration on a disposable VM.

## 12. Rollback and incident response

If a release fails health or schema checks, keep DNS unchanged if possible and roll
back the image to the last known-good Git commit:

```bash
cd /opt/geolander
git checkout --detach LAST_GOOD_COMMIT
docker compose --env-file .env.host -f compose.host.yml up -d --build
docker compose --env-file .env.host -f compose.host.yml ps
```

If compromise is suspected, do not investigate by copying the live web root into a
new host. Isolate the VM, preserve logs, rotate WordPress/DB/Cloudflare/SMTP/payment
credentials, create a new VM, and redeploy from a clean Git commit and inspected
media only. Then request a Google Search Console security review after all sample
URLs return clean responses.

## 13. Email and booking confirmations

Cloudflare DNS and the tunnel do not send email. To enable automatic booking
receipts, set the SMTP variables in `.env.host`, publish the provider's SPF/DKIM
records in Cloudflare, redeploy, and test delivery. Until SMTP is configured, the
site can still generate a prefilled customer-confirmation email draft from the
booking request admin screen.
