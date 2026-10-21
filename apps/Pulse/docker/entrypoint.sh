#!/bin/sh
set -e

role="${1:-web}"

rebuild_container() {
    rm -rf var/cache/*
    php bin/console cache:warmup
}

case "$role" in
    web)
        rebuild_container
        exec frankenphp run --config /etc/caddy/Caddyfile
        ;;
    worker)
        rebuild_container
        exec php bin/console ecotone:run "${PULSE_CHANNEL:-ecotone_pulse}" \
            --memoryLimit="${PULSE_WORKER_MEMORY_LIMIT:-256}" \
            --executionTimeLimit="${PULSE_WORKER_TIME_LIMIT_MS:-3600000}"
        ;;
    *)
        exec "$@"
        ;;
esac
