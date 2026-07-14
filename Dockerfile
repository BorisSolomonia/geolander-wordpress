# Geolander WordPress — production image for Railway.
# Code (theme + plugin) is baked into the image and deploys via git push.
# Media uploads persist on a Railway volume mounted at
# /var/www/html/wp-content/uploads. Plugins added via wp-admin do NOT
# survive redeploys — add them to this repo instead.

FROM wordpress:php8.3-apache

# mod_php requires exactly ONE Apache MPM (prefork). This base image re-enables
# the event/worker MPMs at CONTAINER START (via the entrypoint, after all image
# layers), which trips "AH00534: apache2: Configuration error: More than one MPM
# loaded" and crash-loops the container. Removing them at build time doesn't
# help — the runtime puts them back. So we strip them at startup instead: this
# wrapper runs after the entrypoint, immediately before Apache, and is wired in
# as CMD at the end of this file. (Verified against Railway runtime logs.)
RUN printf '#!/bin/sh\nrm -f /etc/apache2/mods-enabled/mpm_event.* /etc/apache2/mods-enabled/mpm_worker.*\nexec apache2-foreground "$@"\n' \
		> /usr/local/bin/geolander-start.sh \
	&& chmod +x /usr/local/bin/geolander-start.sh

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

# Healthcheck endpoint that always returns 200. WordPress itself answers
# "/" with a 302 (install screen or canonical redirect), which Railway's
# healthcheck treats as failure. This confirms Apache + PHP are serving;
# the entrypoint copies it to the web root alongside WordPress core.
RUN echo '<?php http_response_code(200); header("Content-Type: text/plain"); echo "ok";' \
	> /usr/src/wordpress/health.php

# WP-CLI for one-time content import and maintenance via `railway ssh`.
RUN curl -fsSL https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar -o /usr/local/bin/wp \
	&& chmod +x /usr/local/bin/wp

# Reasonable PHP defaults for a small production site.
RUN { \
		echo 'upload_max_filesize = 32M'; \
		echo 'post_max_size = 34M'; \
		echo 'memory_limit = 256M'; \
		echo 'opcache.enable = 1'; \
		echo 'opcache.validate_timestamps = 0'; \
	} > /usr/local/etc/php/conf.d/geolander.ini

# Strip the runtime-re-enabled extra MPMs, then start Apache. Replaces the base
# image's `CMD ["apache2-foreground"]`. The stock entrypoint still runs first.
CMD ["geolander-start.sh"]
