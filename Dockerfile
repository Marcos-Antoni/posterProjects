FROM node:22-slim AS node

FROM serversideup/php:8.4-fpm-nginx

USER root
RUN install-php-extensions pdo_pgsql intl

# marcos:export-legacy / marcos:rehearse-restore need pg_dump/pg_restore, which
# this image does not ship. Debian trixie's own repo (already configured, no
# PGDG needed) carries postgresql-client 17, whose major version is >= the
# production server's (postgres:16-alpine) — the minimum config/legacy_backup.php
# documents for a working dump/restore.
RUN apt-get update \
    && apt-get install -y --no-install-recommends postgresql-client \
    && rm -rf /var/lib/apt/lists/*

# Node is needed at build time: the Wayfinder Vite plugin shells out to
# `php artisan wayfinder:generate`, so assets must build inside the PHP image.
COPY --from=node /usr/local/bin/node /usr/local/bin/node
COPY --from=node /usr/local/lib/node_modules /usr/local/lib/node_modules
RUN ln -sf /usr/local/lib/node_modules/npm/bin/npm-cli.js /usr/local/bin/npm \
    && ln -sf /usr/local/lib/node_modules/npm/bin/npx-cli.js /usr/local/bin/npx

WORKDIR /var/www/html
COPY --chown=www-data:www-data . .

USER www-data
RUN composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader

# Dummy APP_KEY only boots artisan for wayfinder:generate; never used at runtime.
RUN npm ci \
    && APP_KEY=base64:tmpbuildkeytmpbuildkeytmpbuildkey00000000000= npm run build \
    && rm -rf node_modules

ENV AUTORUN_ENABLED=true \
    PHP_OPCACHE_ENABLE=1
