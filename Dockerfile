# syntax=docker/dockerfile:1.7
#
# PromptEdit × Pixelforge: one image for the web app, the queue worker and the scheduler.
# Pick the role at runtime with CONTAINER_ROLE=web|worker|scheduler (see docker/entrypoint.sh).

ARG PHP_IMAGE=dunglas/frankenphp:1-php8.5
ARG NODE_IMAGE=node:24-bookworm-slim

# ---------------------------------------------------------------------------------------------
# base: PHP 8.5 (FrankenPHP) with the extensions the app needs.
# ---------------------------------------------------------------------------------------------
FROM ${PHP_IMAGE} AS base

RUN install-php-extensions \
        pdo_pgsql pgsql \
        redis \
        bcmath intl zip \
        pcntl \
        opcache

WORKDIR /app

# ---------------------------------------------------------------------------------------------
# build: Composer dependencies and the Vite bundle. Vite's Wayfinder plugin runs
# `php artisan wayfinder:generate`, so this stage needs PHP and the app as well as Node.
# ---------------------------------------------------------------------------------------------
FROM ${NODE_IMAGE} AS node

FROM base AS build

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY --from=node /usr/local/bin/node /usr/local/bin/node
COPY --from=node /usr/local/lib/node_modules /usr/local/lib/node_modules
RUN ln -s /usr/local/lib/node_modules/corepack/dist/corepack.js /usr/local/bin/corepack \
    && corepack enable pnpm

# Boot Laravel without real services while building (no database, cache or queue needed).
ENV APP_ENV=production \
    APP_DEBUG=false \
    CACHE_STORE=array \
    SESSION_DRIVER=array \
    QUEUE_CONNECTION=sync \
    LOG_CHANNEL=stderr \
    COMPOSER_ALLOW_SUPERUSER=1

COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --no-progress --prefer-dist --no-autoloader --no-scripts

COPY package.json pnpm-lock.yaml pnpm-workspace.yaml ./
RUN pnpm install --frozen-lockfile

COPY . .
RUN composer dump-autoload --optimize --classmap-authoritative --no-dev \
    && php artisan package:discover --ansi \
    && pnpm run build \
    && rm -rf node_modules

# ---------------------------------------------------------------------------------------------
# runtime
# ---------------------------------------------------------------------------------------------
FROM base AS runtime

ENV APP_ENV=production \
    APP_DEBUG=false \
    LOG_CHANNEL=stderr \
    PORT=8000 \
    XDG_CONFIG_HOME=/config \
    XDG_DATA_HOME=/data

RUN cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini" \
    && { \
        echo 'memory_limit=256M'; \
        echo 'upload_max_filesize=8M'; \
        echo 'post_max_size=8M'; \
        echo 'expose_php=Off'; \
        echo 'opcache.enable=1'; \
        echo 'opcache.enable_cli=1'; \
        echo 'opcache.validate_timestamps=0'; \
        echo 'opcache.memory_consumption=192'; \
        echo 'opcache.max_accelerated_files=20000'; \
    } > "$PHP_INI_DIR/conf.d/zz-app.ini"

COPY --from=build --chown=www-data:www-data /app /app
COPY docker/Caddyfile /etc/frankenphp/Caddyfile
COPY --chmod=755 docker/entrypoint.sh /usr/local/bin/entrypoint
COPY --chmod=755 docker/healthcheck.sh /usr/local/bin/healthcheck

# Run as www-data. Writable: storage (logs, cache, sessions, stored images), bootstrap/cache, Caddy's dirs.
RUN mkdir -p /config/caddy /data/caddy \
        storage/app/private storage/app/public \
        storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs \
    && chown -R www-data:www-data /config /data storage bootstrap/cache

USER www-data

EXPOSE 8000

HEALTHCHECK --interval=30s --timeout=5s --start-period=40s --retries=3 CMD ["healthcheck"]

ENTRYPOINT ["entrypoint"]
