#!/usr/bin/env bash
# Deploy the BOR-111 news section to the Oracle VM.
#
#   ./deploy-news.sh  [SSH_TARGET]  [SSH_KEY]
#   ./deploy-news.sh  ubuntu@89.168.113.238  ~/.ssh/geolander-oracle
#
# Ships only the files this feature touches, restarts the container, runs the
# migration, flushes rewrites, and verifies the result over HTTP. Idempotent:
# safe to run twice.
#
# THE GOTCHA THIS SCRIPT EXISTS TO HANDLE: /var/www/html is a Docker VOLUME in
# the official WordPress image, and opcache runs with validate_timestamps=0. A
# rebuild alone does not replace the served code, and edited PHP stays invisible
# until the container restarts. Both are handled below.
set -euo pipefail

# WHOLE DIRECTORIES, not a hand-picked list.
#
# 2026-10-01 outage: this script shipped only "the files this feature touches".
# One of them was geolander-core.php, which by then required
# class-glc-trip-planner.php — added to the repo on 21 September and therefore
# not in the feature's file list. PHP fatals on a missing require_once, so every
# page on geo-lander.com returned HTTP 500 until that one class was sent.
#
# A plugin is only consistent as a whole: its loader and its classes must travel
# together or not at all. Shipping the directories removes the entire class of
# bug, at the cost of a bigger tarball, which is a trade worth making for a live
# site. Uploads and node_modules are excluded; they do not belong in a deploy.
PATHS=(
  wp-content/plugins/geolander-core
  wp-content/themes/geolander
  _migration
  docs/seo/SEO-AGENT-MANUAL.md
)
TAR_EXCLUDES=(--exclude=node_modules --exclude=.git --exclude='*.map')

if [ "${1:-}" = "--list" ]; then printf '%s\n' "${PATHS[@]}"; exit 0; fi


# Listing what would ship must never touch the network or need a key.
TARGET="${1:-ubuntu@89.168.113.238}"

# The key path in the runbook (~/.ssh/geolander-oracle) does not exist on every
# machine this is run from, and a wrong path fails with a confusing
# "Permission denied (publickey)" rather than "no such file". So: take an
# explicit second argument, else try the agent, else try the usual names.
KEY="${2:-}"
if [ -z "$KEY" ]; then
  echo "==> No key given, auto-discovering"
  if ssh-add -l >/dev/null 2>&1; then
    echo "    using ssh-agent"
  else
    # /mnt/c first: the authorised key is at C:\Users\Admin\.ssh\geolander-oracle
    # (named in docs/ORACLE-DEPLOYMENT-HANDOFF.md), not under the Boris profile.
    for candidate in /mnt/c/Users/Admin/.ssh/geolander-oracle "$HOME/.ssh/geolander-oracle" "$HOME/.ssh/oracle" "$HOME/.ssh/id_ed25519" "$HOME/.ssh/id_rsa"; do
      [ -f "$candidate" ] && { KEY="$candidate"; echo "    found $candidate"; break; }
    done
    if [ -z "$KEY" ]; then
      echo "ERROR: no SSH key found and no ssh-agent running."
      echo "Pass the key explicitly:  ./deploy-news.sh $TARGET /path/to/your/key"
      exit 1
    fi
  fi
fi
REMOTE_DIR="/opt/geolander"
DC="docker compose --env-file .env.host -f compose.host.yml"


echo "==> Shipping ${#PATHS[@]} path(s)"
for p in "${PATHS[@]}"; do [ -e "$p" ] || { echo "MISSING: $p"; exit 1; }; done

# Catch the outage's cause before sending: every class the loader requires must
# exist locally, or the VM will fatal exactly as it did on 2026-10-01.
missing=0
while read -r rel; do
  [ -f "wp-content/plugins/geolander-core/$rel" ] || { echo "LOADER REQUIRES A MISSING FILE: $rel"; missing=1; }
done < <(grep -o "includes/class-glc-[a-z-]*\.php" wp-content/plugins/geolander-core/geolander-core.php | sort -u)
[ "$missing" = 0 ] || { echo "Refusing to deploy: the plugin would fatal on the server."; exit 1; }

case "$KEY" in
  /mnt/[a-z]/*)
    # Translate /mnt/c/... to C:\... for ssh.exe.
    WINKEY=$(printf '%s' "$KEY" | sed -E 's|^/mnt/([a-z])/|\U\1:/|' | tr '/' '\\\\')
    echo "==> Key is on the Windows mount; using ssh.exe with $WINKEY"
    SSH=(ssh.exe -i "$WINKEY" -o StrictHostKeyChecking=accept-new "$TARGET")
    ;;
  "") SSH=(ssh -o StrictHostKeyChecking=accept-new "$TARGET") ;;
  *)  SSH=(ssh -i "$KEY" -o StrictHostKeyChecking=accept-new "$TARGET") ;;
esac

echo "==> Checking the connection before shipping anything"
if ! "${SSH[@]}" -o BatchMode=yes -o ConnectTimeout=10 'echo connected' >/dev/null 2>&1; then
  echo "ERROR: cannot reach $TARGET with that key. Nothing was sent."
  echo "Check: is the key the one authorised on the VM, and is your IP allowed?"
  exit 1
fi

echo "==> Streaming to $TARGET:$REMOTE_DIR"
tar -czf - "${TAR_EXCLUDES[@]}" "${PATHS[@]}" | "${SSH[@]}" "cat > /tmp/bor111.tgz && cd $REMOTE_DIR && tar -xzf /tmp/bor111.tgz && rm -f /tmp/bor111.tgz && echo extracted"

echo "==> Rebuilding image and restarting (volume + opcache)"
"${SSH[@]}" "cd $REMOTE_DIR && $DC up -d --build wordpress && sleep 8 && $DC ps"

echo "==> Running the migrations (dry run first)"
"${SSH[@]}" "cd $REMOTE_DIR && $DC run --rm cli eval-file /migration/setup-news.php dry-run --allow-root"
"${SSH[@]}" "cd $REMOTE_DIR && $DC run --rm cli eval-file /migration/set-car-whatsapp.php dry-run --allow-root"

read -r -p "Apply the migration? [y/N] " yn
[ "$yn" = "y" ] || { echo "Stopped before writing. Nothing changed in the database."; exit 0; }

"${SSH[@]}" "cd $REMOTE_DIR && $DC run --rm cli eval-file /migration/setup-news.php --allow-root"
"${SSH[@]}" "cd $REMOTE_DIR && $DC run --rm cli eval-file /migration/set-car-whatsapp.php --allow-root"
"${SSH[@]}" "cd $REMOTE_DIR && $DC run --rm cli rewrite flush --allow-root"

echo "==> Verifying over HTTPS"
fail=0
code=$(curl -s -o /tmp/v.html -w '%{http_code}' https://geo-lander.com/blog/)
echo "  /blog/                      HTTP $code"; [ "$code" = 200 ] || fail=1
grep -q 'Georgia events and travel news' /tmp/v.html && echo "  archive heading             OK" || { echo "  archive heading             MISSING"; fail=1; }
grep -qi 'noindex' /tmp/v.html && echo "  archive noindex             yes (correct while no article is published)" || echo "  archive noindex             no (an article is published)"
code=$(curl -s -o /dev/null -w '%{http_code}' https://geo-lander.com/blog/taemin-liminal-tbilisi-december-2026/)
echo "  draft article               HTTP $code"; [ "$code" = 404 ] || { echo "    ^ a DRAFT must not be public"; fail=1; }
for p in / /fleet/ /car-rental-tbilisi/ /guides/; do
  c=$(curl -s -o /dev/null -w '%{http_code}' "https://geo-lander.com$p")
  echo "  regression $p  HTTP $c"; [ "$c" = 200 ] || fail=1
done
curl -s https://geo-lander.com/wp-sitemap-archives-1.xml | grep -q '/blog/' && echo "  /blog/ in sitemap           OK" || { echo "  /blog/ in sitemap           MISSING"; fail=1; }
# Marked-up prices must appear in visible text, and the five partner cars must
# answer on their own number. Both are what this release exists to fix.
node _migration/validate-schema.mjs https://geo-lander.com 2>&1 | grep -q 'nowhere in the visible text' && { echo "  price visibility            STILL FAILING"; fail=1; } || echo "  price visibility            OK"
# The number is resolved server-side at checkout, never printed in the page, so
# ask the resolver rather than grepping HTML (the first version of this check
# reported a false failure for exactly that reason).
"${SSH[@]}" "cd $REMOTE_DIR && $DC exec -T wordpress wp eval 'echo GLC_Gateway_WhatsApp::number_for(get_page_by_path(\"subaru-crosstrek-2017-rz117zr\", OBJECT, \"car\")->ID);' --allow-root" | grep -q 995511286224 \
  && echo "  partner wa number           OK" || { echo "  partner wa number           NOT SET"; fail=1; }

[ "$fail" = 0 ] && echo "==> DEPLOY OK" || { echo "==> DEPLOY HAS FAILURES — see above"; exit 1; }
