#!/bin/bash
# Smoke test of the compose demo profile: from "docker compose --profile demo up" to two Connected services holding
# dead letters and traces, with no file edited.
#   usage: ECOTONE_LICENCE_KEY=... apps/Pulse/docker/demo-smoke.sh   (from the repository root)
set -u

project="${DEMO_SMOKE_PROJECT:-pulsedemo}"
url="http://127.0.0.1:${PULSE_DEMO_PORT:-8080}"
compose() {
    docker compose -p "$project" -f apps/Pulse/docker-compose.example.yml --profile demo "$@"
}
fail() {
    echo "DEMO SMOKE FAILED: $1" >&2
    compose logs --no-color 2>&1 | cut -c1-300 | tail -40 >&2
    compose down -v > /dev/null 2>&1
    exit 1
}
trap 'compose down -v > /dev/null 2>&1' EXIT

licence="$ECOTONE_LICENCE_KEY"
unset ECOTONE_LICENCE_KEY
compose down -v > /dev/null 2>&1 || fail "docker compose down needs ECOTONE_LICENCE_KEY"
echo "down without the licence key: ok"
export ECOTONE_LICENCE_KEY="$licence"

started="$(date +%s)"
compose up -d --build > /dev/null 2>&1 || fail "docker compose up failed"

for attempt in $(seq 1 120); do
    [ "$(curl -s "$url/" | grep -c 'data-status="connected"')" = "2" ] && break
    sleep 2
done
[ "$(curl -s "$url/" | grep -c 'data-status="connected"')" = "2" ] || fail "orders and billing never both reached Connected"
echo "Connected after $(( $(date +%s) - started )) s"

for attempt in $(seq 1 60); do
    total="$(curl -s "$url/" | grep -o 'data-summary="dead-letters"><b>[0-9]*' | grep -o '[0-9]*$')"
    [ "${total:-0}" -ge 10 ] && break
    sleep 2
done
[ "${total:-0}" -ge 10 ] || fail "the demo services hold ${total:-0} dead letters, expected at least 10"
echo "Dead letters: $total"

traces="$(curl -s "$url/traces" | grep -c 'data-flow-type=')"
[ "$traces" -ge 2 ] || fail "the traces page lists $traces flow types, expected at least 2"
echo "Flow types with traces: $traces"
curl -s "$url/services/orders" | grep -q 'data-hub-status="connected"' || fail "the orders hub page is not Connected"
compose up -d > /dev/null 2>&1 || fail "the second docker compose up failed"
for attempt in $(seq 1 30); do
    compose logs demo-seed 2>&1 | grep -q 'already seeded' && break
    sleep 2
done
compose logs demo-seed 2>&1 | grep -q 'already seeded' || fail "a second up seeded the demo again"
echo "second up does not seed again: ok"
echo "DEMO SMOKE OK"
