# Geolander WordPress — production image for Railway.
# Code (theme + plugin) is baked into the image and deploys via git push.
# Media uploads persist on a Railway volume mounted at
# /var/www/html/wp-content/uploads. Plugins added via wp-admin do NOT
# survive redeploys — add them to this repo instead.

FROM wordpress:php8.3-apache

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

# Reasonable PHP defaults for a small production site.
RUN { \
		echo 'upload_max_filesize = 32M'; \
		echo 'post_max_size = 34M'; \
		echo 'memory_limit = 256M'; \
		echo 'opcache.enable = 1'; \
		echo 'opcache.validate_timestamps = 0'; \
	} > /usr/local/etc/php/conf.d/geolander.ini
