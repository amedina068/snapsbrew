# syntax=docker/dockerfile:1

# One image runs every app container: the web server, Horizon and the
# scheduler. The base is serversideup/php (PHP-FPM and NGINX on Debian).
ARG PHP_IMAGE=serversideup/php:8.4-fpm-nginx-trixie-v5.1.2

# Stage 1: the PHP dependencies.
FROM ${PHP_IMAGE} AS vendor
USER root
RUN install-php-extensions gd intl exif bcmath
USER www-data
WORKDIR /var/www/html
COPY --chown=www-data:www-data composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --no-progress --no-scripts --no-autoloader --prefer-dist

# Stage 2: the front-end assets. Tailwind reads the pagination views in vendor/.
# The built files are the same on each platform, so this stage runs on the
# platform of the builder. A multi-platform build then needs no emulation here.
FROM --platform=$BUILDPLATFORM node:22-trixie-slim AS assets
WORKDIR /app
ENV PUPPETEER_SKIP_DOWNLOAD=true
COPY package.json package-lock.json ./
RUN npm ci --no-audit --no-fund
COPY vite.config.mjs ./
COPY resources ./resources
COPY --from=vendor /var/www/html/vendor/laravel/framework/src/Illuminate/Pagination/resources/views ./vendor/laravel/framework/src/Illuminate/Pagination/resources/views
RUN npm run build

# Stage 3: the runtime image.
FROM ${PHP_IMAGE}
USER root
RUN install-php-extensions gd intl exif bcmath
# The image entrypoint runs this script at start. It acts only in the web container.
COPY --chmod=755 deploy/docker/entrypoint.d/60-snapsbrew-setup.sh /etc/entrypoint.d/60-snapsbrew-setup.sh
USER www-data
WORKDIR /var/www/html

ENV PHP_OPCACHE_ENABLE=1 \
    HEALTHCHECK_PATH=/up \
    LOG_CHANNEL=stderr

COPY --chown=www-data:www-data --from=vendor /var/www/html/vendor ./vendor
COPY --chown=www-data:www-data . .
COPY --chown=www-data:www-data --from=assets /app/public/build ./public/build

RUN composer dump-autoload --no-dev --optimize --no-interaction
