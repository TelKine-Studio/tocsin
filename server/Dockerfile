# syntax=docker/dockerfile:1.7

# ---------------------------------------------------------------------------
# Tocsin — single container image
#   nginx (port 80) ─┬─ PHP-FPM (web UI + API)
#                    └─ /app → Laravel Reverb (WebSocket, 127.0.0.1:8080)
#   + queue worker and scheduler, all supervised by supervisord.
# Persistent data lives in /data (mount a volume there).
# ---------------------------------------------------------------------------

ARG PHP_VERSION=8.4

# --- PHP dependencies --------------------------------------------------------
FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction --no-progress --ignore-platform-reqs
COPY . .
RUN composer dump-autoload --optimize --no-dev --no-scripts

# --- Front-end assets --------------------------------------------------------
FROM node:22-alpine AS assets
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci --no-audit --no-fund
COPY vite.config.js ./
COPY resources ./resources
COPY public ./public
COPY --from=vendor /app/vendor/laravel/framework/src/Illuminate/Pagination/resources/views ./vendor/laravel/framework/src/Illuminate/Pagination/resources/views
RUN npm run build

# --- Runtime -----------------------------------------------------------------
FROM php:${PHP_VERSION}-fpm-alpine AS runtime

ARG TOCSIN_VERSION=0.1.0
LABEL org.opencontainers.image.title="Tocsin" \
      org.opencontainers.image.description="Self-hosted push notification server" \
      org.opencontainers.image.licenses="MIT" \
      org.opencontainers.image.version="${TOCSIN_VERSION}"

ADD --chmod=0755 https://github.com/mlocati/docker-php-extension-installer/releases/latest/download/install-php-extensions /usr/local/bin/

RUN apk add --no-cache nginx supervisor su-exec curl tini \
    && install-php-extensions bcmath gmp intl opcache pcntl pdo_mysql pdo_pgsql zip \
    && cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

COPY docker/php.ini $PHP_INI_DIR/conf.d/zz-tocsin.ini
COPY docker/php-fpm.conf /usr/local/etc/php-fpm.d/zz-tocsin.conf
COPY docker/nginx.conf /etc/nginx/nginx.conf
COPY docker/supervisord.conf /etc/supervisord.conf
COPY --chmod=0755 docker/entrypoint.sh /usr/local/bin/tocsin-entrypoint

WORKDIR /var/www/html

COPY --chown=www-data:www-data . .
COPY --from=vendor --chown=www-data:www-data /app/vendor ./vendor
COPY --from=assets --chown=www-data:www-data /app/public/build ./public/build

RUN rm -rf .env tests node_modules storage/logs/* database/database.sqlite \
    && php artisan package:discover --ansi \
    && chown -R www-data:www-data bootstrap/cache

ENV APP_ENV=production \
    APP_DEBUG=false \
    TOCSIN_VERSION=${TOCSIN_VERSION} \
    TOCSIN_DATA_DIR=/data \
    TOCSIN_STORAGE_PATH=/data/storage \
    LOG_CHANNEL=stderr \
    LOG_LEVEL=warning \
    DB_CONNECTION=sqlite \
    DB_DATABASE=/data/database.sqlite \
    SESSION_DRIVER=database \
    CACHE_STORE=database \
    QUEUE_CONNECTION=database \
    BROADCAST_CONNECTION=reverb \
    REVERB_HOST=127.0.0.1 \
    REVERB_PORT=8080 \
    REVERB_SCHEME=http

VOLUME /data
EXPOSE 80

HEALTHCHECK --interval=30s --timeout=5s --start-period=30s --retries=3 \
    CMD curl -fsS http://127.0.0.1/up > /dev/null || exit 1

ENTRYPOINT ["/sbin/tini", "--", "tocsin-entrypoint"]
CMD ["supervisord", "-c", "/etc/supervisord.conf"]
