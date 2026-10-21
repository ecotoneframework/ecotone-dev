#!/bin/bash
set -e

PULSE_RUN_DIR="${PULSE_RUN_DIR:-/tmp/pulse-run}"

prepare_application_secret() {
    if [ -z "${APP_SECRET:-}" ] && [ -n "${APP_SECRET_FILE:-}" ]; then
        if [ ! -r "$APP_SECRET_FILE" ]; then
            echo "APP_SECRET_FILE points to $APP_SECRET_FILE, which cannot be read. Mount the secret at that path, or unset APP_SECRET_FILE." >&2
            exit 1
        fi
        APP_SECRET="$(cat "$APP_SECRET_FILE")"
    fi
    if [ -z "${APP_SECRET:-}" ]; then
        APP_SECRET="$(head -c 32 /dev/urandom | od -An -tx1 | tr -d ' \n')"
        echo "Pulse: APP_SECRET is not set, so one was generated for the lifetime of this container (Pulse signs nothing with it)." >&2
    fi
    export APP_SECRET
}

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
    label="$1"
    shift
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
        echo "$child" > "$PULSE_RUN_DIR/$label.pid"
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

process_is_running() {
    [ -r "/proc/$1/stat" ] || return 1
    state="$(sed -e 's/^.*) //' -e 's/ .*$//' "/proc/$1/stat")"
    [ "$state" != "T" ] && [ "$state" != "Z" ] && [ "$state" != "t" ]
}

report_heartbeats() {
    while true; do
        running=()
        for entry in "${reply_channels[@]}"; do
            label="${entry%%=*}"
            channel="${entry#*=}"
            pid="$(cat "$PULSE_RUN_DIR/$label.pid" 2>/dev/null || true)"
            if [ -n "$pid" ] && process_is_running "$pid"; then
                running+=("consumer:$channel")
            fi
        done
        if [ "${#running[@]}" -gt 0 ]; then
            php bin/console pulse:heartbeat "${running[@]}" > /dev/null 2>&1 || true
        fi
        sleep 5
    done
}

run_pulse() {
    stopping=0
    pids=()
    trap 'stopping=1; kill -TERM "${pids[@]}" 2>/dev/null' TERM INT
    mkdir -p "$PULSE_RUN_DIR"
    rm -f "$PULSE_RUN_DIR"/*.pid
    prepare
    if [ "$stopping" = 1 ]; then
        exit 0
    fi
    php bin/console pulse:banner >&2
    frankenphp run --config /etc/caddy/Caddyfile &
    pids+=($!)
    reply_channels=()
    index=0
    while IFS= read -r channel; do
        [ -z "$channel" ] && continue
        label="consumer.$index"
        reply_channels+=("$label=$channel")
        keep_running "$label" php bin/console ecotone:run "$channel" \
            --memoryLimit="${PULSE_WORKER_MEMORY_LIMIT:-256}" \
            --executionTimeLimit="${PULSE_WORKER_TIME_LIMIT_MS:-3600000}" &
        pids+=($!)
        index=$(( index + 1 ))
    done < <(php bin/console pulse:reply-channels)
    keep_running refresh php bin/console pulse:refresh &
    pids+=($!)
    report_heartbeats &
    heartbeats=$!
    php bin/console pulse:doctor >&2 || echo "Pulse: pulse:doctor found problems, see above. Pulse keeps running, because a service that is down is what it exists to show." >&2
    status=0
    wait -n "${pids[@]}" || status=$?
    kill -TERM "${pids[@]}" "$heartbeats" 2>/dev/null || true
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
        prepare_application_secret
        run_pulse
        ;;
    *)
        exec "$@"
        ;;
esac
