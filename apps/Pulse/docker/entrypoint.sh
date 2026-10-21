#!/bin/bash
set -e

role="${1:-web}"

setup_message_table() {
    command="php bin/console ecotone:migration:database:setup --feature=message_queue --initialize --missing"
    deadline=$(( $(date +%s) + 30 ))
    delay=1
    while ! $command; do
        if [ "$(date +%s)" -ge "$deadline" ]; then
            host="$(printf '%s' "$PULSE_TRANSPORT_DSN" | sed -e 's#^[^:]*://##' -e 's#[/?].*$##' -e 's#^.*@##' -e 's#:.*$##')"
            echo "Pulse could not create its message table on database \"${host:-the sqlite file}\" from PULSE_TRANSPORT_DSN. Check that the database is reachable and the user may create tables, or run manually: $command" >&2
            exit 1
        fi
        sleep "$delay"
        delay=$(( delay < 4 ? delay * 2 : 4 ))
    done
}

rebuild_container() {
    rm -rf var/cache/*
    php bin/console cache:warmup
    case "$(printf '%s' "${PULSE_TRANSPORT_DSN:-}" | tr '[:upper:]' '[:lower:]')" in
        pgsql://*|mysql://*|sqlite://*)
            setup_message_table
            ;;
    esac
}

case "$role" in
    web)
        rebuild_container
        exec frankenphp run --config /etc/caddy/Caddyfile
        ;;
    worker)
        rebuild_container
        php bin/console ecotone:run "${PULSE_CHANNEL:-ecotone_pulse}" \
            --memoryLimit="${PULSE_WORKER_MEMORY_LIMIT:-256}" \
            --executionTimeLimit="${PULSE_WORKER_TIME_LIMIT_MS:-3600000}" &
        consumer=$!
        php bin/console pulse:refresh &
        refresher=$!
        trap 'kill -TERM "$consumer" "$refresher" 2>/dev/null' TERM INT
        status=0
        wait -n || status=$?
        kill -TERM "$consumer" "$refresher" 2>/dev/null || true
        wait || true
        exit "$status"
        ;;
    *)
        exec "$@"
        ;;
esac
