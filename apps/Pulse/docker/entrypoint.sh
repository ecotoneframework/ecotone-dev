#!/bin/sh
set -e

role="${1:-web}"

case "$role" in
    web)
        php bin/console cache:warmup
        exec frankenphp run --config /etc/caddy/Caddyfile
        ;;
    worker)
        php bin/console cache:warmup
        exec php bin/console ecotone:run "${PULSE_CHANNEL:-ecotone_pulse}" \
            --memoryLimit="${PULSE_WORKER_MEMORY_LIMIT:-256}" \
            --executionTimeLimit="${PULSE_WORKER_TIME_LIMIT_MS:-3600000}"
        ;;
    *)
        exec "$@"
        ;;
esac
