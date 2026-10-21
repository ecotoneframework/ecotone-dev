#!/bin/bash
set -e

setup_tables() {
    command="php bin/console ecotone:migration:database:setup --initialize"
    deadline=$(( $(date +%s) + 30 ))
    delay=1
    while ! $command; do
        if [ "$(date +%s)" -ge "$deadline" ]; then
            host="$(printf '%s' "$PULSE_TRANSPORT_DSN" | sed -e 's#^[^:]*://##' -e 's#[/?].*$##' -e 's#^.*@##' -e 's#:.*$##')"
            echo "Pulse could not create its tables on the internal SQLite database or on the database \"${host:-the sqlite file}\" from PULSE_TRANSPORT_DSN. Check that the database is reachable and the user may create tables, or run manually: $command" >&2
            exit 1
        fi
        sleep "$delay"
        delay=$(( delay < 4 ? delay * 2 : 4 ))
    done
}

prepare() {
    rm -rf var/cache/*
    php bin/console cache:warmup
    php bin/console pulse:setup
    setup_tables
}

keep_running() {
    stopping=0
    child=
    trap 'stopping=1; [ -n "$child" ] && kill -TERM "$child" 2>/dev/null' TERM INT
    while true; do
        "$@" &
        child=$!
        status=0
        wait "$child" || status=$?
        if [ "$stopping" = 1 ]; then
            wait "$child" 2>/dev/null || true
            exit 0
        fi
        if [ "$status" -ne 0 ]; then
            exit "$status"
        fi
    done
}

run_pulse() {
    prepare
    frankenphp run --config /etc/caddy/Caddyfile &
    web=$!
    keep_running php bin/console ecotone:run "${PULSE_CHANNEL:-ecotone_pulse}" \
        --memoryLimit="${PULSE_WORKER_MEMORY_LIMIT:-256}" \
        --executionTimeLimit="${PULSE_WORKER_TIME_LIMIT_MS:-3600000}" &
    consumer=$!
    keep_running php bin/console pulse:refresh &
    refresher=$!
    stopping=0
    trap 'stopping=1; kill -TERM "$web" "$consumer" "$refresher" 2>/dev/null' TERM INT
    status=0
    wait -n || status=$?
    kill -TERM "$web" "$consumer" "$refresher" 2>/dev/null || true
    wait || true
    if [ "$stopping" = 1 ]; then
        exit 0
    fi
    if [ "$status" = 0 ]; then
        status=1
    fi
    exit "$status"
}

case "${1:-run}" in
    run)
        run_pulse
        ;;
    *)
        exec "$@"
        ;;
esac
