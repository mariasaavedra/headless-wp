#!/usr/bin/env bash
set -euo pipefail

WP="wp --path=/var/www/html --allow-root"

# The base image's entrypoint only extracts WP core / generates wp-config.php
# when invoked as apache2*/php-fpm/docker-ensure-installed.sh. Trigger that
# now so WP-CLI has a working install to talk to below.
docker-ensure-installed.sh

echo "bootstrap: waiting for database at ${WORDPRESS_DB_HOST:-db}..."
until $WP db check --quiet --skip-ssl 2>/dev/null; do
	sleep 2
done
echo "bootstrap: database is up."

FRESH_INSTALL=false
if ! $WP core is-installed --quiet 2>/dev/null; then
	echo "bootstrap: installing WordPress..."
	$WP core install \
		--url="${WORDPRESS_SITE_URL}" \
		--title="${WORDPRESS_SITE_TITLE}" \
		--admin_user="${WORDPRESS_ADMIN_USER}" \
		--admin_password="${WORDPRESS_ADMIN_PASSWORD}" \
		--admin_email="${WORDPRESS_ADMIN_EMAIL}" \
		--skip-email
	FRESH_INSTALL=true
else
	echo "bootstrap: WordPress already installed, skipping install."
fi

echo "bootstrap: applying site settings..."
$WP option update blogname "${WORDPRESS_SITE_TITLE}"
$WP option update blogdescription "${WORDPRESS_TAGLINE}"
$WP option update siteurl "${WORDPRESS_SITE_URL}"
$WP option update home "${WORDPRESS_SITE_URL}"
if [ "${WORDPRESS_LOCALE}" != "en_US" ]; then
	$WP language core install "${WORDPRESS_LOCALE}"
fi
$WP site switch-language "${WORDPRESS_LOCALE}"

echo "bootstrap: configuring permalinks..."
$WP rewrite structure '/%postname%/' --hard
$WP rewrite flush --hard

echo "bootstrap: activating theme and plugin..."
$WP theme activate habeas-cle
$WP plugin activate habeas-cle

if [ "$FRESH_INSTALL" = true ]; then
	echo "bootstrap: seeding demo data..."
	php /var/www/html/wp-content/plugins/habeas-cle/bin/seed-demo.php
else
	echo "bootstrap: existing install, skipping demo data seed."
fi

echo "bootstrap: done. Starting Apache."
exec apache2-foreground
