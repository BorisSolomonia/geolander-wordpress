#!/bin/sh
set -eu

WEB_ROOT=/var/www/html
UPLOADS="$WEB_ROOT/wp-content/uploads"

# The official entrypoint initializes the web root as www-data so WordPress can
# self-update. This deployment is image-driven instead: make all executable
# code root-owned and writable only through a new build. Keep media writable.
# Local bind-mount development opts out to avoid changing host ownership.
if [ "${GLC_SKIP_FILE_HARDENING:-0}" != '1' ]; then
	mkdir -p "$UPLOADS"
	# The stock entrypoint can restore bundled plugins after image build layers
	# have removed them. Remove the known inactive packages after initialization.
	rm -rf "$WEB_ROOT/wp-content/plugins/akismet" "$WEB_ROOT/wp-content/plugins/hello.php"
	find "$WEB_ROOT" -path "$UPLOADS" -prune -o -type d -exec chown root:root {} +
	find "$WEB_ROOT" -path "$UPLOADS" -prune -o -type f -exec chown root:root {} +
	find "$WEB_ROOT" -path "$UPLOADS" -prune -o -type d -exec chmod 0755 {} +
	find "$WEB_ROOT" -path "$UPLOADS" -prune -o -type f -exec chmod 0644 {} +
	chown -R www-data:www-data "$UPLOADS"
	chmod 0755 "$UPLOADS"
	if [ -f "$WEB_ROOT/wp-config.php" ]; then
		chown root:www-data "$WEB_ROOT/wp-config.php"
		chmod 0640 "$WEB_ROOT/wp-config.php"
	fi
fi

exec apache2-foreground
