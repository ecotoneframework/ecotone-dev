# Build from the repository root, because the Ecotone packages are resolved from ./packages:
#   docker build -f apps/Pulse/Dockerfile -t ecotone-pulse .

FROM dunglas/frankenphp:1-php8.5-bookworm AS base

RUN install-php-extensions amqp rdkafka redis pdo_pgsql pdo_mysql sockets intl opcache zip

ENV APP_ENV=prod \
    APP_DEBUG=0 \
    PULSE_CACHE_DIR=/app/var/pulse-cache \
    PULSE_HTTP_PORT=8080 \
    XDG_DATA_HOME=/app/var/xdg/data \
    XDG_CONFIG_HOME=/app/var/xdg/config

FROM base AS build

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /build
COPY packages ./packages
COPY apps/Pulse ./apps/Pulse

WORKDIR /build/apps/Pulse

ENV COMPOSER=composer.pinned.json

RUN php docker/pin-path-package-versions.php --copy \
    && COMPOSER_ALLOW_SUPERUSER=1 composer update --no-dev --no-interaction --no-scripts --prefer-dist --no-autoloader \
    && COMPOSER_ALLOW_SUPERUSER=1 composer dump-autoload --no-dev --classmap-authoritative --no-scripts

RUN sh docker/with-build-env.sh php bin/console importmap:install \
    && sh docker/with-build-env.sh php bin/console asset-map:compile \
    && rm -rf var

FROM base AS runtime

WORKDIR /app

COPY --from=build /build/apps/Pulse /app
COPY apps/Pulse/docker/Caddyfile /etc/caddy/Caddyfile
COPY apps/Pulse/docker/entrypoint.sh /usr/local/bin/pulse-entrypoint

RUN mkdir -p var/cache var/log var/pulse-cache var/xdg/data var/xdg/config \
    && chown -R www-data:www-data var \
    && chmod +x /usr/local/bin/pulse-entrypoint \
    && cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini" \
    && printf 'opcache.enable_cli=1\nopcache.validate_timestamps=0\n' > "$PHP_INI_DIR/conf.d/zz-pulse.ini"

USER www-data

EXPOSE 8080

ENTRYPOINT ["pulse-entrypoint"]
CMD ["web"]
