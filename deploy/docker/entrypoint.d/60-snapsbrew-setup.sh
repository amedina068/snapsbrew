#!/bin/sh
# Prepares Snapsbrew when the web container starts.
#
# The serversideup/php entrypoint sources each script in /etc/entrypoint.d in
# version order. This script runs after 50-laravel-automations.sh, so the
# migrations are complete. Only the web container sets SNAPSBREW_PREPARE_ON_START.

if [ "${SNAPSBREW_PREPARE_ON_START:-false}" != "true" ]; then
    exit 0
fi

# Create the Passport keys and the storage link when they are missing.
php /var/www/html/artisan snapsbrew:install --prepare --no-interaction

# While the instance has no account, show the setup URL in the container log.
setup_url="$(php /var/www/html/artisan snapsbrew:setup-url --no-ansi)" || setup_url=""

case "$setup_url" in
    http://* | https://*)
        echo ""
        echo "=================================================================="
        echo " Snapsbrew has no account yet."
        echo " Open this URL to create the owner account:"
        echo ""
        echo "   $setup_url"
        echo ""
        echo " To show this URL again, run:"
        echo "   docker compose exec web php artisan snapsbrew:setup-url"
        echo "=================================================================="
        echo ""
        ;;
esac
