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
    rapid=0
    delay=1
    minimum_uptime="${PULSE_RESTART_MIN_UPTIME_SECONDS:-10}"
    maximum_rapid="${PULSE_RESTART_MAX_RAPID:-5}"
    trap 'stopping=1; [ -n "$child" ] && kill -TERM "$child" 2>/dev/null' TERM INT
    while true; do
        started=$(date +%s)
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
        if [ $(( $(date +%s) - started )) -ge "$minimum_uptime" ]; then
            rapid=0
            delay=1
            continue
        fi
        rapid=$(( rapid + 1 ))
        if [ "$rapid" -ge "$maximum_rapid" ]; then
            echo "Pulse is stopping: \"$*\" exited cleanly $rapid times in a row, each within ${minimum_uptime}s of starting, so restarting it again would not help. Check its limits (PULSE_WORKER_TIME_LIMIT_MS, PULSE_WORKER_MEMORY_LIMIT) and its log above." >&2
            exit 1
        fi
        echo "Pulse restarts \"$*\" in ${delay}s ($rapid of $maximum_rapid quick clean exits)." >&2
        sleep "$delay" &
        child=$!
        wait "$child" || true
        if [ "$stopping" = 1 ]; then
            exit 0
        fi
        delay=$(( delay * 2 > 30 ? 30 : delay * 2 ))
    done
}

run_pulse() {
    stopping=0
    web=
    consumer=
    refresher=
    trap 'stopping=1; kill -TERM $web $consumer $refresher 2>/dev/null' TERM INT
    prepare
    if [ "$stopping" = 1 ]; then
        exit 0
    fi
    frankenphp run --config /etc/caddy/Caddyfile &
    web=$!
    keep_running php bin/console ecotone:run "${PULSE_CHANNEL:-ecotone_pulse}" \
        --memoryLimit="${PULSE_WORKER_MEMORY_LIMIT:-256}" \
        --executionTimeLimit="${PULSE_WORKER_TIME_LIMIT_MS:-3600000}" &
    consumer=$!
    keep_running php bin/console pulse:refresh &
    refresher=$!
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
