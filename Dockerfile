# Geolander WordPress — production image for Railway.
# Code (theme + plugin) is baked into the image and deploys via git push.
# Media uploads persist on a Railway volume mounted at
# /var/www/html/wp-content/uploads. Plugins added via wp-admin do NOT
# survive redeploys — add them to this repo instead.

FROM wordpress:7.0.4-php8.3-apache

# The official image is rebuilt periodically, but a cached Docker layer can
# retain Debian packages after security fixes are published. Patch all
# installed packages in this build so a new deploy never inherits that lag.
RUN apt-get update \
	&& apt-get upgrade -y --no-install-recommends \
	&& rm -rf /var/lib/apt/lists/*

# mod_php requires exactly ONE Apache MPM (prefork). This base image re-enables
# the event/worker MPMs at CONTAINER START (via the entrypoint, after all image
# layers), which trips "AH00534: apache2: Configuration error: More than one MPM
# loaded" and crash-loops the container. Removing them at build time doesn't
# help — the runtime puts them back. So we strip them at startup instead: this
# wrapper runs first, strips the conflicting modules, then delegates to the
# official WordPress entrypoint so its web-root initialization still runs.
RUN printf '#!/bin/sh\nrm -f /etc/apache2/mods-enabled/mpm_event.* /etc/apache2/mods-enabled/mpm_worker.*\nexec docker-entrypoint.sh "$@"\n' \
		> /usr/local/bin/geolander-entrypoint.sh \
	&& chmod +x /usr/local/bin/geolander-entrypoint.sh

# Only project-owned executable code ships in wp-content. Removing bundled,
# inactive plugins/themes reduces the code an attacker can probe or activate.
RUN rm -rf \
	/usr/src/wordpress/wp-content/plugins/akismet \
	/usr/src/wordpress/wp-content/plugins/hello.php \
	/usr/src/wordpress/wp-content/themes/twentytwentythree \
	/usr/src/wordpress/wp-content/themes/twentytwentyfour \
	/usr/src/wordpress/wp-content/themes/twentytwentyfive

# Bake our code into the WordPress source tree; the entrypoint copies it
# to the web root on container start.
COPY wp-content/themes/geolander /usr/src/wordpress/wp-content/themes/geolander
COPY wp-content/plugins/geolander-core /usr/src/wordpress/wp-content/plugins/geolander-core

# Migration data + importers, for one-time content seeding via wp-cli.
COPY _migration /migration

# Behind Railway's TLS-terminating proxy: mark requests as HTTPS when the
# proxy says so, or WordPress redirect-loops. Apache stays on port 80 —
# set the Railway service's target port to 80.
RUN { \
		echo "SetEnvIf X-Forwarded-Proto https HTTPS=on"; \
	} > /etc/apache2/conf-available/railway.conf \
	&& a2enconf railway

# Bound mod_php/prefork memory, reject verified abusive bootstrap endpoints,
# prevent PHP execution from the persistent uploads volume, and provide
# localhost-only worker/cgroup diagnostics for `railway ssh` investigations.
COPY docker/apache-production.conf /etc/apache2/conf-available/zz-geolander-production.conf
COPY docker/memory-snapshot.sh /usr/local/bin/geolander-memory-snapshot
COPY docker/wordpress-hardening.php /usr/local/etc/wordpress/wordpress-hardening.php
COPY docker/hardened-apache-start.sh /usr/local/bin/apache2-geolander-foreground
RUN chmod +x /usr/local/bin/geolander-memory-snapshot /usr/local/bin/apache2-geolander-foreground \
	&& a2enmod status \
	&& a2enconf zz-geolander-production

# Static healthcheck: confirms Apache serves without consuming a PHP worker.
COPY docker/healthz /usr/src/wordpress/healthz
COPY docker/apache-status /usr/src/wordpress/_internal-apache-status

# Static Google Search Console ownership verification. Keep the filename and
# file contents unchanged so Google can validate the domain over HTTPS.
COPY googlecaf9dc315ab07aac.html /usr/src/wordpress/googlecaf9dc315ab07aac.html

# Version- and checksum-pinned WP-CLI for one-time maintenance via SSH.
ARG WP_CLI_VERSION=2.12.0
ARG WP_CLI_SHA512=be928f6b8ca1e8dfb9d2f4b75a13aa4aee0896f8a9a0a1c45cd5d2c98605e6172e6d014dda2e27f88c98befc16c040cbb2bd1bfa121510ea5cdf5f6a30fe8832
RUN curl -fsSL "https://github.com/wp-cli/wp-cli/releases/download/v${WP_CLI_VERSION}/wp-cli-${WP_CLI_VERSION}.phar" -o /usr/local/bin/wp \
	&& echo "${WP_CLI_SHA512}  /usr/local/bin/wp" | sha512sum -c - \
	&& chmod 0755 /usr/local/bin/wp

# Reasonable PHP defaults for a small production site.
RUN { \
		echo 'upload_max_filesize = 32M'; \
		echo 'post_max_size = 34M'; \
		echo 'memory_limit = 128M'; \
		echo 'max_execution_time = 30'; \
		echo 'max_input_time = 30'; \
		echo 'max_input_vars = 1000'; \
		echo 'expose_php = Off'; \
		echo 'allow_url_include = Off'; \
		echo 'disable_functions = exec,passthru,shell_exec,system,proc_open,popen,pcntl_exec'; \
		echo 'opcache.enable = 1'; \
		echo 'opcache.memory_consumption = 64'; \
		echo 'opcache.validate_timestamps = 0'; \
		echo 'auto_prepend_file = /usr/local/etc/wordpress/wordpress-hardening.php'; \
	} > /usr/local/etc/php/conf.d/geolander.ini

# Strip runtime-re-enabled MPMs before the stock initializer and Apache start.
ENTRYPOINT ["geolander-entrypoint.sh"]
CMD ["apache2-geolander-foreground"]
