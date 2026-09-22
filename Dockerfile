# syntax=docker/dockerfile:1

# ---------------------------------------------------------------------------
# Base: PHP 8.5 CLI on Alpine with the extensions the app needs.
# Built in: ctype, iconv, mbstring, pdo_sqlite, sqlite3, zlib, posix, opcache.
# Added:    bcmath (php-standard-library), intl (mike42/escpos-php), pcntl (graceful SIGTERM for messenger:consume).
# ---------------------------------------------------------------------------
FROM php:8.5-cli-alpine AS base

RUN set -eux; \
    apk add --no-cache icu-libs; \
    apk add --no-cache --virtual .build-deps $PHPIZE_DEPS icu-dev; \
    docker-php-ext-install -j"$(nproc)" bcmath intl pcntl; \
    apk del .build-deps; \
    cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

ENV APP_ENV=prod \
    APP_DEBUG=0

WORKDIR /app

# ---------------------------------------------------------------------------
# Vendor: composer install on the real runtime PHP, so platform requirements are checked.
# ---------------------------------------------------------------------------
FROM base AS vendor

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
COPY composer.json composer.lock symfony.lock ./
RUN --mount=type=cache,target=/root/.composer/cache \
    composer install --no-dev --no-scripts --no-progress --no-interaction --prefer-dist --no-autoloader

# ---------------------------------------------------------------------------
# Runtime
# ---------------------------------------------------------------------------
FROM base AS runtime

RUN set -eux; \
    apk add --no-cache supervisor su-exec; \
    addgroup -g 1000 app; \
    adduser -D -u 1000 -G app app

COPY --from=vendor /app/vendor ./vendor
COPY bin bin
COPY config config
COPY public public
COPY src src
COPY composer.json composer.lock symfony.lock ./
# Not the repository's .env: Dokploy overwrites it with the service environment before the build.
COPY docker/app.env .env
COPY docker/supervisord.conf /etc/supervisord.conf
COPY docker/entrypoint.sh /usr/local/bin/entrypoint

# The autoloader is dumped here, after src/ is present, so the authoritative classmap covers the app.
RUN --mount=from=composer:2,source=/usr/bin/composer,target=/usr/local/bin/composer \
    set -eux; \
    composer dump-autoload --no-dev --optimize --classmap-authoritative --no-interaction; \
    chmod +x /usr/local/bin/entrypoint bin/console; \
    mkdir -p data/receipts var; \
    php bin/console cache:warmup --no-interaction; \
    chown -R app:app data var

VOLUME ["/app/data"]

# Reachability of the configured printer, see bin/console printer:check.
HEALTHCHECK --interval=60s --timeout=10s --start-period=30s --retries=3 \
    CMD su-exec app php bin/console printer:check || exit 1

ENTRYPOINT ["entrypoint"]
CMD ["supervisord", "-n", "-c", "/etc/supervisord.conf"]
