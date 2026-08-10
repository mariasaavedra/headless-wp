FROM wordpress:latest

# WP-CLI, used by bin/bootstrap.sh to install and configure WordPress.
RUN curl -o /usr/local/bin/wp -fsSL https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar \
    && chmod +x /usr/local/bin/wp \
    && apt-get update && apt-get install -y --no-install-recommends default-mysql-client \
    && rm -rf /var/lib/apt/lists/*

COPY --chown=www-data:www-data apps/wordpress/plugins/habeas-cle/plugin /var/www/html/wp-content/plugins/habeas-cle
COPY --chown=www-data:www-data apps/wordpress/plugins/habeas-cle/theme /var/www/html/wp-content/themes/habeas-cle
COPY apps/wordpress/bin/bootstrap.sh /usr/local/bin/bootstrap.sh

RUN chmod +x /usr/local/bin/bootstrap.sh

# Keep the base image's entrypoint (extracts WP core, generates wp-config.php),
# then hand off to our bootstrap script, which installs/configures WordPress
# via WP-CLI before finally starting Apache.
CMD ["bootstrap.sh"]