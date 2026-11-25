# syntax=docker/dockerfile:1

# One image runs all of Snapsbrew. s6-overlay keeps these processes running:
#   - NGINX and PHP-FPM (the web server), from serversideup/php
#   - the queue worker (queue:work, or Horizon when QUEUE_CONNECTION=redis)
#   - the scheduler (schedule:work)
#   - a headless Chromium that stays warm for the renders
#   - Caddy with automatic HTTPS, only when APP_DOMAIN is set
#
# The volume /data holds the SQLite database, the app key, the uploads and the
# rendered images. With no variables, the image runs SQLite on port 8080:
#
#   docker run -d -p 8080:8080 -v snapsbrew:/data ghcr.io/amedina068/snapsbrew
#
# docker-compose.yml also runs this image with MySQL, Redis, Horizon and
# Browserless (the "mysql" profile).
ARG PHP_IMAGE=serversideup/php:8.4-fpm-nginx-trixie-v5.1.2
ARG NODE_IMAGE=node:22-trixie-slim
ARG CADDY_IMAGE=caddy:2.10-alpine

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
FROM --platform=$BUILDPLATFORM ${NODE_IMAGE} AS assets
WORKDIR /app
ENV PUPPETEER_SKIP_DOWNLOAD=true
COPY package.json package-lock.json ./
RUN npm ci --no-audit --no-fund
COPY vite.config.mjs ./
COPY resources ./resources
COPY --from=vendor /var/www/html/vendor/laravel/framework/src/Illuminate/Pagination/resources/views ./vendor/laravel/framework/src/Illuminate/Pagination/resources/views
RUN npm run build

# Stage 3: Node and puppeteer-core for Browsershot, on the target platform.
# Browsershot requires the module "puppeteer" from the node_modules folder of
# the app. The full package downloads a browser, and the image uses the
# Chromium of Debian. A small "puppeteer" module loads puppeteer-core instead.
# The version comes from package-lock.json.
FROM ${NODE_IMAGE} AS node
WORKDIR /opt/puppeteer
COPY package-lock.json /tmp/package-lock.json
RUN version="$(node -p "require('/tmp/package-lock.json').packages['node_modules/puppeteer-core'].version")" \
    && npm install --omit=dev --no-audit --no-fund --no-package-lock "puppeteer-core@${version}" \
    && mkdir -p node_modules/puppeteer \
    && echo "module.exports = require('puppeteer-core');" > node_modules/puppeteer/index.js \
    && printf '{"name":"puppeteer","version":"%s","main":"index.js"}\n' "$version" > node_modules/puppeteer/package.json \
    && rm -rf /root/.npm

# Stage 4: the Caddy binary for automatic HTTPS.
FROM ${CADDY_IMAGE} AS caddy

# Stage 5: the runtime image.
FROM ${PHP_IMAGE}
USER root
RUN install-php-extensions gd intl exif bcmath

# Chromium (the headless shell), the fonts of the renderer, and the database
# clients that load a schema dump (sqlite3, and mysql from mariadb-client-core).
#
# The headless shell renders in software. It links libgbm, but it never loads
# the Mesa drivers. Mesa and LLVM are 200 MB, so the build removes them.
# dpkg then reports missing dependencies. Do not install packages with apt in
# an image built on this one.
RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        chromium-headless-shell \
        fontconfig \
        fonts-dejavu-core \
        fonts-liberation \
        fonts-noto-color-emoji \
        fonts-noto-core \
        mariadb-client-core \
        sqlite3 \
    && dpkg --purge --force-depends libgl1-mesa-dri libglx-mesa0 mesa-libgallium libllvm19 libz3-4 \
    && rm -rf /var/lib/apt/lists/* /var/cache/apt/archives/*

COPY deploy/docker/fonts.conf /etc/fonts/local.conf
RUN fc-cache -f
COPY deploy/docker/mysql-client.cnf /etc/mysql/conf.d/snapsbrew-client.cnf

COPY --from=node /usr/local/bin/node /usr/local/bin/node
COPY --from=caddy /usr/bin/caddy /usr/local/bin/caddy
COPY deploy/docker/Caddyfile /etc/caddy/Caddyfile

# The image entrypoint runs these scripts at start, before s6 starts the
# services: 05 prepares /data, 50 (serversideup) runs the migrations, and 60
# creates the Passport keys.
COPY --chmod=755 deploy/docker/entrypoint.d/ /etc/entrypoint.d/

# The s6 services. Each one depends on "base". The ready service prints the
# last lines of the boot log, so it starts after the other services.
COPY --chmod=755 deploy/docker/s6-rc.d/ /etc/s6-overlay/s6-rc.d/
RUN for service in snapsbrew-queue snapsbrew-scheduler snapsbrew-chromium snapsbrew-caddy snapsbrew-ready; do \
        mkdir -p "/etc/s6-overlay/s6-rc.d/${service}/dependencies.d" \
        && touch "/etc/s6-overlay/s6-rc.d/${service}/dependencies.d/base" \
        && touch "/etc/s6-overlay/user-bundles.d/user/contents.d/${service}"; \
    done \
    && for service in nginx php-fpm snapsbrew-queue snapsbrew-scheduler snapsbrew-chromium; do \
        touch "/etc/s6-overlay/s6-rc.d/snapsbrew-ready/dependencies.d/${service}"; \
    done \
    && mkdir -p /data \
    && chown www-data:www-data /data

USER www-data
WORKDIR /var/www/html

# The defaults for one container with SQLite. Each value can change with -e.
# APP_URL has no default here: config/app.php derives it from APP_DOMAIN, or
# uses http://localhost:8080.
ENV APP_NAME=Snapsbrew \
    APP_ENV=production \
    APP_DEBUG=false \
    APP_KEY_FILE=/data/.secrets/app_key \
    LARAVEL_STORAGE_PATH=/data/storage \
    LOG_CHANNEL=stderr \
    DB_CONNECTION=sqlite \
    DB_DATABASE=/data/database.sqlite \
    QUEUE_CONNECTION=database \
    CACHE_DRIVER=file \
    SESSION_DRIVER=file \
    MAIL_MAILER=log \
    MEDIA_DISK=public \
    RENDER_DRIVER=browsershot \
    BROWSERSHOT_INSTANCE=http://127.0.0.1:9222 \
    BROWSERSHOT_CHROME_PATH=/usr/lib/chromium/chromium-headless-shell \
    RENDER_ASSET_URL=http://127.0.0.1:8080 \
    AUTORUN_ENABLED=true \
    AUTORUN_LARAVEL_MIGRATION_ISOLATION=true \
    AUTORUN_LARAVEL_MIGRATION_TIMEOUT=300 \
    SNAPSBREW_PREPARE_ON_START=true \
    PHP_OPCACHE_ENABLE=1 \
    HEALTHCHECK_PATH=/up \
    SHOW_WELCOME_MESSAGE=false

COPY --chown=www-data:www-data --from=vendor /var/www/html/vendor ./vendor
COPY --chown=www-data:www-data . .
COPY --chown=www-data:www-data --from=assets /app/public/build ./public/build
COPY --chown=www-data:www-data --from=node /opt/puppeteer/node_modules ./node_modules

RUN composer dump-autoload --no-dev --optimize --no-interaction

VOLUME ["/data"]
EXPOSE 8080 80 443

# A new MySQL database loads database/schema/mysql-schema.sql, so the first
# start takes less than a minute. The start period allows for a slow disk.
HEALTHCHECK --interval=10s --timeout=3s --start-period=180s --start-interval=2s --retries=3 \
    CMD ["sh", "-c", "curl --silent --fail --output /dev/null http://127.0.0.1:${NGINX_HTTP_PORT}${HEALTHCHECK_PATH} || exit 1"]
