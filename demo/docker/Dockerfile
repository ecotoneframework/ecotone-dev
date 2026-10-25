# Build from the repository root, because the demo applications resolve the Ecotone packages from ./packages:
#   docker compose -f demo/docker-compose.yml build

FROM dunglas/frankenphp:1-php8.5-bookworm

RUN install-php-extensions amqp pdo_pgsql pdo_sqlite sockets intl zip pcntl opcache rdkafka redis

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

ENV COMPOSER_HOME=/tmp/composer \
    XDG_CONFIG_HOME=/tmp/xdg/config \
    XDG_DATA_HOME=/tmp/xdg/data \
    DEMO_HTTP_PORT=8080

RUN printf 'opcache.enable_cli=0\nopcache.validate_timestamps=1\nopcache.revalidate_freq=0\nmemory_limit=512M\n' > "$PHP_INI_DIR/conf.d/zz-demo.ini"

COPY demo/docker/Caddyfile /etc/caddy/Caddyfile
COPY apps/Pulse/docker/entrypoint.sh /usr/local/bin/pulse-entrypoint

EXPOSE 8080

ENTRYPOINT ["/repo/demo/docker/entrypoint.sh"]
