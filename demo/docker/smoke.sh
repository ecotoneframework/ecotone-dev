#!/bin/bash
# Smoke test of the whole demo, from a clean stack to a rush whose orders get their tickets:
#   demo/docker/smoke.sh   (from the repository root)
# It honours COMPOSE_PROJECT_NAME and the DEMO_*_PORT variables of demo/docker-compose.yml, and takes the stack down
# with its volumes when it ends; set SMOKE_KEEP_STACK=1 to leave it running.
set -u

compose=(docker compose -f demo/docker-compose.yml)
storefront="http://127.0.0.1:${DEMO_STOREFRONT_PORT:-8000}"
pulse="http://127.0.0.1:${DEMO_PULSE_PORT:-8080}"
jaeger="http://127.0.0.1:${DEMO_JAEGER_PORT:-16686}"
started="$(date +%s)"

cleanup() {
    if [ "${SMOKE_KEEP_STACK:-}" != "1" ]; then
        "${compose[@]}" down -v > /dev/null 2>&1
    fi
}
fail() {
    echo "DEMO SMOKE FAILED: $1" >&2
    "${compose[@]}" ps >&2
    "${compose[@]}" logs --no-color --tail 40 storefront storefront-worker ticketing ticketing-worker pulse 2>&1 | cut -c1-300 >&2
    exit 1
}
step() {
    echo "[demo smoke] $1 after $(( $(date +%s) - started )) s"
}
wait_until() {
    local seconds="$1"
    shift
    local deadline=$(( $(date +%s) + seconds ))
    until "$@"; do
        [ "$(date +%s)" -ge "$deadline" ] && return 1
        sleep 2
    done
}
trap cleanup EXIT

"${compose[@]}" up -d --build --wait --wait-timeout 600 || fail "the stack did not turn healthy within 600 seconds"
step "every container is healthy"

service_connected() {
    curl -s "$pulse/services" | awk -v service="$1" '
        $0 ~ "<tr data-service=\"" service "\"" { row = 1 }
        row && /data-status="connected"/ { found = 1 }
        row && /<\/tr>/ { row = 0 }
        END { exit found ? 0 : 1 }'
}
wait_until 180 service_connected ticketing || fail "ticketing is not Connected in Pulse ($pulse/services)"
wait_until 180 service_connected storefront || fail "storefront is not Connected in Pulse ($pulse/services)"
step "ticketing and storefront are Connected in Pulse"

concert_id=
concert_on_sale_in_storefront() {
    concert_id="$("${compose[@]}" exec -T postgres psql -U ecotone -d storefront -tAc "SELECT concert_id FROM concerts WHERE artist = 'The Midnight Owls' AND status = 'on_sale'" 2>/dev/null | tr -d '[:space:]')"
    [ -n "$concert_id" ]
}
wait_until 120 concert_on_sale_in_storefront || fail "the seeded concert The Midnight Owls never reached the storefront's catalogue on sale"
step "the seed is present: The Midnight Owls ($concert_id) is on sale in the storefront"

rush_output="$("${compose[@]}" exec -T storefront php artisan demo:rush "$concert_id" --buyers=20 2>&1)" || fail "demo:rush failed: $rush_output"
rush_id="$(grep -oE 'Started rush [0-9a-f-]{36}' <<<"$rush_output" | cut -d' ' -f3)"
[ -n "$rush_id" ] || fail "demo:rush did not name the rush it started: $rush_output"
step "rush $rush_id started"

rush_state=
rush_has() {
    rush_state="$(curl -s "$storefront/rushes/$rush_id/state.json")"
    jq -e "$1" <<<"$rush_state" > /dev/null 2>&1
}
wait_until 660 rush_has '.finishedAt != null' || fail "rush $rush_id did not finish: $rush_state"
step "rush finished: $(jq -c '{created, held, paying, paid, issued, failed, gaveUp}' <<<"$rush_state")"
wait_until 180 rush_has '.issued >= 1' || fail "no order of rush $rush_id got its tickets issued: $rush_state"
step "$(jq -r '.issued' <<<"$rush_state") orders of the rush have their tickets issued"

dead_letters=0
pulse_has_dead_letters() {
    dead_letters="$(curl -s "$pulse/dead-letters" | grep -c 'data-dead-letter ')"
    [ "$dead_letters" -ge 1 ]
}
wait_until 120 pulse_has_dead_letters || fail "Pulse lists no dead letter after the rush ($pulse/dead-letters)"
step "Pulse lists $dead_letters dead letters"

traces_across_both_services=0
jaeger_has_a_trace_across_both_services() {
    traces_across_both_services="$(curl -s "$jaeger/api/traces?service=storefront&lookback=1h&limit=1000" | jq '[.data[] | select(([.processes[].serviceName] | index("storefront")) and ([.processes[].serviceName] | index("ticketing")))] | length' 2>/dev/null || echo 0)"
    [ "${traces_across_both_services:-0}" -ge 1 ]
}
wait_until 60 jaeger_has_a_trace_across_both_services || fail "Jaeger has no trace that spans storefront and ticketing ($jaeger)"
step "Jaeger has $traces_across_both_services traces that span storefront and ticketing"

echo "[demo smoke] OK"
