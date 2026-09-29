FROM node:22-slim AS node

FROM serversideup/php:8.4-fpm-nginx

USER root
RUN install-php-extensions pdo_pgsql intl

# marcos:export-legacy / marcos:rehearse-restore need pg_dump/pg_restore, which
# this image does not ship. The client must match the production server's major
# version (postgres:16-alpine): a v17 pg_dump writes `SET transaction_timeout`,
# which a v16 server rejects on restore. Debian trixie only ships v17, so the
# v16 client comes from the official PGDG repository.
RUN apt-get update \
    && apt-get install -y --no-install-recommends curl ca-certificates \
    && install -d /usr/share/postgresql-common/pgdg \
    && curl -fsSL -o /usr/share/postgresql-common/pgdg/apt.postgresql.org.asc https://www.postgresql.org/media/keys/ACCC4CF8.asc \
    && . /etc/os-release \
    && echo "deb [signed-by=/usr/share/postgresql-common/pgdg/apt.postgresql.org.asc] https://apt.postgresql.org/pub/repos/apt ${VERSION_CODENAME}-pgdg main" > /etc/apt/sources.list.d/pgdg.list \
    && apt-get update \
    && apt-get install -y --no-install-recommends postgresql-client-16 \
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
