# Geolander Oracle deployment handoff for an autonomous operator

Last reviewed: 17 September 2026. This document is the execution guide for an
operator or another LLM deploying Geolander. The detailed clean-room provisioning
reference remains [ORACLE-DEPLOYMENT-RUNBOOK.md](ORACLE-DEPLOYMENT-RUNBOOK.md).

## 1. Deployment contract

Deploy only reviewed, committed code from:

- Repository: `https://github.com/mrbokoko1qa-dot/geolander-wordpress`
- Production site: `https://geo-lander.com`
- Production directory: `/opt/geolander`
- Oracle SSH user: `ubuntu`
- Current documented VM address: `89.168.113.238`
- Compose file: `/opt/geolander/compose.host.yml`
- Secret file: `/opt/geolander/.env.host`
- Persistent data: `/opt/geolander/data/mysql` and `/opt/geolander/data/uploads`
- Public path: Cloudflare Tunnel to the private Compose service `http://wordpress:80`

The workstation currently uses the private key
`C:\Users\Admin\.ssh\geolander-oracle`. A different operator must use its own
authorized private-key path. Never copy, print, upload or commit a private key.

Hard rules:

1. Do not deploy to Railway. Oracle plus Cloudflare Tunnel is the active architecture.
2. Do not deploy a dirty worktree. Do not silently discard somebody else's changes.
3. Do not print or read `.env.host` into logs. It contains production secrets.
4. Do not copy a web root, database directory, plugin or cache from the compromised
   historical Railway runtime.
5. Do not delete or replace `data/`, `.env.host`, backups, MX/SPF/DKIM records, or
   unrelated Cloudflare records.
6. Do not run the complete migration/import list during an ordinary update. Run only
   the migrations named in the reviewed release manifest.
7. Do not use `git reset --hard`, `git clean -fdx`, or broad recursive deletion.
8. Do not announce success until origin health, public health and both validators pass.
9. If an operation fails, preserve the failure output and keep or restore the last
   healthy container. Never hide a failed release check.

## 2. Decide which procedure applies

Use **Procedure A** for the existing production VM. It preserves the current database,
uploads, WordPress settings and Cloudflare Tunnel.

Use **Procedure B** only for a new/replacement VM with a new empty database. It creates
a clean WordPress installation and runs the complete bootstrap sequence.

The current `/opt/geolander` may be an exported source tree rather than a Git checkout.
Test it; do not assume:

```powershell
ssh -i C:\Users\Admin\.ssh\geolander-oracle -o BatchMode=yes `
  ubuntu@89.168.113.238 "test -d /opt/geolander/.git && echo git-checkout || echo exported-tree"
```

## 3. Release inputs an autonomous tool must establish

**Current handoff warning (17 September 2026):** the workstation checkout contains
uncommitted application/news work. It is not a deployable release as-is. The next
operator must review and resolve that work with the owner; it must not commit or deploy
it merely because it is present.

Before writing to production, report these values:

```powershell
Set-Location C:\Users\Boris\Dell\Projects\APPS\Geolander\Geolander_WordPress
git status --short
git branch --show-current
git log --oneline -5
git remote -v
git rev-parse HEAD
```

Stop if `git status --short` is non-empty. Ask the owner whether the changes should be
reviewed and committed; do not infer that every local file belongs in a release.

The release must have:

- an exact commit SHA;
- a clean worktree;
- a successful local test result appropriate to the changes;
- an explicit list of new migrations, if any;
- an explicit rollback commit or code archive;
- GitHub synchronization, unless the owner explicitly approves an emergency
  archive-only release.

Push the exact reviewed branch before production deployment:

```powershell
git push -u origin HEAD
```

If authentication fails or hangs, stop the push and report it. A direct archive
deployment can still work, but it creates a source-of-truth gap and must be disclosed.

## 4. Procedure A — update the existing Oracle deployment

### A1. Preflight the VM

```powershell
$KeyPath = 'C:\Users\Admin\.ssh\geolander-oracle'
$Vm = 'ubuntu@89.168.113.238'

ssh -i $KeyPath -o BatchMode=yes -o ConnectTimeout=10 $Vm `
  "cd /opt/geolander && test -f .env.host && docker compose --env-file .env.host -f compose.host.yml config --quiet && docker compose --env-file .env.host -f compose.host.yml ps && curl --fail --silent --show-error http://127.0.0.1:8080/healthz"
```

Required result: `.env.host` exists without being displayed, Compose configuration is
valid, `db` and `wordpress` are healthy, `tunnel` is running, and health returns `ok`.
If this fails, diagnose before changing code.

### A2. Create production backups

The following creates a logical database dump, uploads archive and code/config-layout
archive. It excludes secrets and persistent data from the code archive. It does not
stop the running site.

```powershell
ssh -i $KeyPath -o BatchMode=yes $Vm @'
set -eu
cd /opt/geolander
release_id=$(date -u +%Y%m%dT%H%M%SZ)
mkdir -p backups
chmod 700 backups
set -a
. ./.env.host
set +a
docker compose --env-file .env.host -f compose.host.yml exec -T \
  -e MYSQL_PWD="$MARIADB_ROOT_PASSWORD" db \
  mariadb-dump --single-transaction --routines --events -u root "$MARIADB_DATABASE" \
  | gzip > "backups/database-$release_id.sql.gz"
tar --exclude='./data' --exclude='./.env.host' --exclude='./backups' \
  -czf "backups/code-$release_id.tar.gz" .
tar -czf "backups/uploads-$release_id.tar.gz" data/uploads
sha256sum "backups/database-$release_id.sql.gz" \
  "backups/code-$release_id.tar.gz" "backups/uploads-$release_id.tar.gz"
printf '%s\n' "$release_id" > backups/LAST_PREDEPLOY_BACKUP
'@
```

These on-VM backups protect the immediate release. The owner should also copy encrypted
backups off-host; an on-host backup does not protect against account or disk loss.

### A3. Build a binary-safe release archive on Windows

Do **not** use `git archive | ssh` in Windows PowerShell. Its pipeline can reinterpret
binary tar bytes and create malformed headers.

```powershell
$Commit = (git rev-parse HEAD).Trim()
$Archive = Join-Path $env:TEMP "geolander-$Commit.tar"
git archive --format=tar -o $Archive $Commit
$LocalHash = (Get-FileHash -Algorithm SHA256 -LiteralPath $Archive).Hash.ToLowerInvariant()
Get-Item -LiteralPath $Archive | Select-Object FullName,Length
Write-Output "SHA256 $LocalHash"
```

Transfer the archive without transforming it:

```powershell
scp -i $KeyPath -o BatchMode=yes $Archive "${Vm}:/tmp/geolander-$Commit.tar"
```

### A4. Verify and extract only committed application files

```powershell
ssh -i $KeyPath -o BatchMode=yes $Vm "sha256sum /tmp/geolander-$Commit.tar"
```

The remote hash must exactly match `$LocalHash`. Then validate the archive and extract:

```powershell
ssh -i $KeyPath -o BatchMode=yes $Vm @"
set -eu
archive=/tmp/geolander-$Commit.tar
tar -tf \"`$archive\" | grep -Fx 'Dockerfile'
tar -tf \"`$archive\" | grep -Fx 'compose.host.yml'
if tar -tf \"`$archive\" | grep -Eq '^(\.env\.host|data/|backups/)'; then
  echo 'Unsafe archive contains protected production paths' >&2
  exit 1
fi
cd /opt/geolander
tar -xf \"`$archive\"
rm -f \"`$archive\"
docker compose --env-file .env.host -f compose.host.yml config --quiet
"@
```

This updates tracked application files and leaves `.env.host`, `data/` and `backups/`
untouched. Remove the exact local temporary archive after a successful transfer:

```powershell
Remove-Item -LiteralPath $Archive -Force
```

### A5. Build and restart the application

```powershell
ssh -i $KeyPath -o BatchMode=yes $Vm @'
set -eu
cd /opt/geolander
docker compose --env-file .env.host -f compose.host.yml up -d --build
docker compose --env-file .env.host -f compose.host.yml ps
curl --fail --silent --show-error http://127.0.0.1:8080/healthz
docker compose --env-file .env.host -f compose.host.yml exec -T wordpress \
  wp plugin list --allow-root --fields=name,status,version | grep geolander-core
'@
```

The Docker start script resynchronizes the Geolander plugin and theme from the image
into WordPress's served web root. Checking the plugin version is therefore mandatory.

### A6. Run only release-specific migrations

Every migration must be named in the reviewed change. For the 9 September evidence
SEO release, the migration was:

```powershell
ssh -i $KeyPath -o BatchMode=yes $Vm @'
set -eu
cd /opt/geolander
docker compose --env-file .env.host -f compose.host.yml exec -T wordpress \
  wp eval-file /migration/setup-evidence-seo.php --allow-root
docker compose --env-file .env.host -f compose.host.yml exec -T wordpress \
  wp rewrite flush --allow-root
'@
```

Do not rerun `import.php`, `import-fleet.php`, `setup-pages.php` or all historical
migrations during a routine release. Some migration scripts intentionally create or
update content and therefore require release-by-release review.

### A7. Origin checks

```powershell
ssh -i $KeyPath -o BatchMode=yes $Vm @'
set -eu
cd /opt/geolander
curl --fail --silent --show-error http://127.0.0.1:8080/healthz
docker compose --env-file .env.host -f compose.host.yml exec -T wordpress \
  wp core verify-checksums --allow-root
docker compose --env-file .env.host -f compose.host.yml exec -T wordpress \
  /usr/local/bin/geolander-memory-snapshot
docker compose --env-file .env.host -f compose.host.yml logs --tail=150 wordpress db tunnel
'@
```

Block the release if the checksum check fails, the memory snapshot reports unexpected
executables/processes or OOM activity, or containers are restarting.

### A8. Public release gates

Run from the reviewed workstation checkout:

```powershell
curl.exe -I https://geo-lander.com/healthz
curl.exe -I https://geo-lander.com/
curl.exe -I https://www.geo-lander.com/
curl.exe -I https://geo-lander.com/.well-known/api-catalog
curl.exe -I https://geo-lander.com/.well-known/agent-card.json
curl.exe -I https://geo-lander.com/auth.md
node _migration/validate-schema.mjs https://geo-lander.com
node _migration/audit-seo-http.mjs https://geo-lander.com --strict --tools
```

Acceptance criteria:

- public health is HTTP 200;
- apex homepage is HTTP 200 and `www` resolves to the intended canonical site;
- a random nonexistent path and `/font` return HTTP 404;
- machine-readable endpoints return their required HTTP status and content type;
- schema guard reports **zero errors**; every warning is reviewed and disclosed;
- strict HTTP audit passes every check;
- homepage, fleet, one vehicle, booking form and any newly migrated page render on a
  phone-size viewport;
- no real customer message, payment or email is created by smoke testing.

Purge only affected URLs in Cloudflare if stale content is observed. Avoid a global
purge unless the release genuinely affects every cached page. Cloudflare cache success
does not replace origin or public validation.

After all gates pass, record the deployment SHA without exposing secrets:

```powershell
ssh -i $KeyPath -o BatchMode=yes $Vm "printf '%s\n' '$Commit' > /opt/geolander/DEPLOYED_COMMIT"
```

## 5. Rollback Procedure A

If build or origin health fails before the new container is healthy, Compose usually
leaves the old container/image available. Inspect `docker compose ps` and logs first.

For a code rollback on the exported-tree host:

```powershell
ssh -i $KeyPath -o BatchMode=yes $Vm @'
set -eu
cd /opt/geolander
backup_id=$(cat backups/LAST_PREDEPLOY_BACKUP)
tar -xzf "backups/code-$backup_id.tar.gz" -C /opt/geolander
docker compose --env-file .env.host -f compose.host.yml up -d --build
docker compose --env-file .env.host -f compose.host.yml ps
curl --fail http://127.0.0.1:8080/healthz
'@
```

Do not restore the database merely to reverse code. Restore the logical database dump
only if a release migration changed data incompatibly and the owner approves the data
rollback. Database restoration can erase legitimate bookings created after the backup.

After rollback, rerun the public health and validator checks and report both the failed
release SHA and restored state.

## 6. Procedure B — provision a clean replacement VM

### B1. Oracle instance

Create an Ubuntu 24.04 ARM64 instance:

- name: `geolander-prod`;
- shape: `VM.Standard.A1.Flex`;
- recommended allocation: 2 OCPUs, 12 GB RAM;
- boot volume: at least 50 GB;
- new VCN and public subnet;
- automatically assigned public IPv4;
- only TCP 22 inbound;
- upload the operator's SSH public key.

Do not open ports 80, 443, 3306 or 8080. Public traffic will use an outbound Cloudflare
Tunnel, and WordPress binds only to `127.0.0.1:8080` on the VM.

### B2. Install Docker and firewall rules

```bash
sudo apt-get update
sudo apt-get install -y ca-certificates curl git ufw openssl
curl -fsSL https://get.docker.com | sudo sh
sudo usermod -aG docker "$USER"
sudo systemctl enable --now docker
sudo ufw default deny incoming
sudo ufw default allow outgoing
sudo ufw allow 22/tcp
sudo ufw --force enable
```

Log out and reconnect so Docker group membership applies. Verify `docker version`,
`docker compose version`, and `sudo ufw status verbose`.

### B3. Clone and create private configuration

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

Create separate random MariaDB user/root passwords and a new Web Bot Auth Ed25519
secret. If PHP is not installed on the VM, generate the latter with a disposable
container:

```bash
openssl rand -base64 32
openssl rand -base64 32
docker run --rm php:8.3-cli php -r \
  'echo base64_encode(sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair())), PHP_EOL;'
```

Put values only in `.env.host`. Required variables are documented in
`.env.host.example`: site URL, both DB passwords, Cloudflare Tunnel token, Cloudflare
Access identifiers, Web Bot Auth private key, and optional SMTP settings. Never put
the actual values in Git or chat.

### B4. Cloudflare Tunnel

In Cloudflare Zero Trust create a Cloudflared tunnel named `geolander-oracle` and add:

- `geo-lander.com` → `http://wordpress:80`
- `www.geo-lander.com` → `http://wordpress:80`

Store the connector token only in `.env.host`. This is a Tunnel token, not a general
Cloudflare API token. Do not remove old DNS until the new connector and origin checks
are healthy. Preserve mail, verification and DNS-AID records.

### B5. Start the clean stack

```bash
cd /opt/geolander
docker compose --env-file .env.host -f compose.host.yml config --quiet
docker compose --env-file .env.host -f compose.host.yml up -d --build
docker compose --env-file .env.host -f compose.host.yml ps
curl --fail http://127.0.0.1:8080/healthz
```

Expected services: healthy `db`, healthy `wordpress`, running `tunnel`.

### B6. Bootstrap a new empty WordPress database

This entire sequence is for a new empty database only. The password prompt prevents
the WordPress administrator password entering shell history.

```bash
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
wp eval-file /migration/setup-evidence-seo.php --allow-root
wp rewrite flush --allow-root
wp eval-file /migration/audit-fleet.php --allow-root
exit
```

Do not run `wp core install` on an existing database. Do not import unreviewed data from
the old Railway volume.

### B7. Verify, then cut over

Run the origin, public and validator gates in A7 and A8. Only after they pass should
Cloudflare's apex and `www` hostnames be moved from the old origin to the healthy new
tunnel. DNS cutover and Google Safe Browsing review are separate tasks.

## 7. SMTP and booking confirmations

Cloudflare Tunnel does not send email. Automatic booking confirmation requires a real
transactional email provider:

1. publish provider SPF and DKIM records in Cloudflare;
2. set `GLC_SMTP_HOST`, port, username, password, encryption and from-address in
   `.env.host`;
3. recreate WordPress with `docker compose ... up -d`;
4. send a labelled test only to an owner-controlled mailbox;
5. verify provider delivery logs and avoid using a real customer for testing.

Without SMTP, WordPress can still generate a prefilled confirmation draft for staff.

## 8. Required completion report

An autonomous tool must finish with:

- deployed commit SHA and branch;
- whether that SHA was pushed to GitHub;
- backup identifiers and whether an off-host copy exists (never passwords/contents);
- Compose service health;
- plugin version;
- migrations executed, each by filename;
- origin health result;
- public health and canonical-domain result;
- schema validator counts;
- strict HTTP audit count;
- warnings and unresolved decisions;
- rollback point;
- confirmation that `.env.host`, private keys and persistent data were not printed,
  committed or replaced.

“Container started” is not a completion condition. The release is complete only when
the public site serves the reviewed code and all release gates pass.

## 9. Prompt to give another deployment tool

Use this with a tool that has terminal access to this workspace and authorized SSH
access to the Oracle VM:

> Read `docs/ORACLE-DEPLOYMENT-HANDOFF.md` and
> `docs/ORACLE-DEPLOYMENT-RUNBOOK.md` completely. Deploy Geolander to the existing
> Oracle production VM using Procedure A. Do not use Railway. Start with the release
> inputs and stop if the worktree is dirty; preserve all existing changes and ask me
> what should be included. Deploy only an exact reviewed commit, push it to the correct
> GitHub repository when authentication is available, create pre-deployment backups,
> use the binary-safe `git archive -o` plus `scp` method on Windows, preserve
> `/opt/geolander/.env.host` and `/opt/geolander/data`, run only migrations named by
> the release, and complete every origin/public validation gate. If a gate fails,
> diagnose or roll back; do not announce success. Never reveal credentials. Finish
> with the completion report required in section 8.
