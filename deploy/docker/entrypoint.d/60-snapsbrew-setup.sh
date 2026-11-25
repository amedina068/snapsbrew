#!/bin/sh
# Prepares Snapsbrew after the migrations.
#
# The serversideup/php entrypoint sources each script in /etc/entrypoint.d in
# version order. This script runs after 50-laravel-automations.sh, so the
# migrations are complete. Set SNAPSBREW_PREPARE_ON_START=false to skip it.
#
# The s6 service snapsbrew-ready prints the setup URL when the web server and
# the renderer answer. That line is the last line of the boot log.

if [ "${SNAPSBREW_PREPARE_ON_START:-true}" != "true" ]; then
    exit 0
fi

# Create the Passport keys and the storage link when they are missing.
php /var/www/html/artisan snapsbrew:install --prepare --no-interaction
