#!/bin/bash
# Smoke test of the built image against one transport: the container turns healthy, a fixture service reaches Connected,
# and /readyz is ready.
#   usage: SMOKE_NETWORK=host apps/Pulse/docker/smoke.sh <name> <dsn> [<image>]
# SMOKE_NETWORK is the Docker network that reaches the broker (host in CI, the compose network locally).
set -u

name="$1"
dsn="$2"
image="${3:-ecotone-pulse:ci}"
network="${SMOKE_NETWORK:-host}"
container="pulse-smoke-$name"
service="smoke-service-$name"
port="${SMOKE_PORT:-18080}"
args=(--network "$network")
url="http://127.0.0.1:8080"
if [ "$network" != "host" ]; then
    args+=(-p "127.0.0.1:$port:8080")
    url="http://127.0.0.1:$port"
fi

licence="$(docker run --rm --entrypoint php "$image" -r 'require "vendor/autoload.php"; echo Ecotone\Test\LicenceTesting::VALID_LICENCE;')"

cleanup() {
    docker rm -f "$container" "$service" > /dev/null 2>&1
}
fail() {
    echo "SMOKE FAILED ($name): $1" >&2
    docker logs "$container" 2>&1 | grep -v "^{" | cut -c1-300 | tail -40 >&2
    docker logs "$service" 2>&1 | cut -c1-300 | tail -15 >&2
    cleanup
    exit 1
}
trap cleanup EXIT
cleanup

settings=(-e ECOTONE_LICENCE_KEY="$licence" -e PULSE_TRANSPORT_DSN="$dsn")
if [ "${SMOKE_SECRET_FILES:-}" = "1" ]; then
    secrets="$(mktemp -d)"
    printf '%s' "$licence" > "$secrets/ecotone_licence_key"
    printf '%s' "$dsn" > "$secrets/dsn"
    chmod 644 "$secrets"/* && chmod 755 "$secrets"
    settings=(-v "$secrets/ecotone_licence_key:/run/secrets/ecotone_licence_key:ro" -v "$secrets/dsn:/run/secrets/dsn:ro" -e PULSE_TRANSPORT_DSN_FILE=/run/secrets/dsn)
fi

started="$(date +%s)"
docker run -d --name "$container" "${args[@]}" "${settings[@]}" \
    -e PULSE_SERVICES=orders \
    -e PULSE_REFRESH_INTERVAL=2 \
    "$image" > /dev/null || fail "the container did not start"

for attempt in $(seq 1 30); do
    health="$(docker inspect --format '{{.State.Health.Status}}' "$container" 2>/dev/null)"
    [ "$health" = "healthy" ] && break
    sleep 1
done
[ "$health" = "healthy" ] || fail "the container is $health, not healthy, after 30 seconds"
docker logs "$container" 2>&1 | grep -q 'APP_SECRET is not set, so one was generated' || fail "the log does not say that APP_SECRET was generated"
echo "[$name] healthy after $(( $(date +%s) - started )) s"

docker run -d --name "$service" --network "$network" \
    -e ECOTONE_LICENCE_KEY="$licence" \
    -e PULSE_TRANSPORT_DSN="$dsn" \
    --entrypoint php "$image" docker/smoke-service.php > /dev/null || fail "the fixture service did not start"

connected=0
for attempt in $(seq 1 90); do
    if curl -s "$url/services" | grep -q 'data-status="connected"'; then
        echo "[$name] Connected after $(( $(date +%s) - started )) s"
        connected=1
        break
    fi
    sleep 1
done
[ "$connected" = "1" ] || fail "the service never reached Connected"

for attempt in $(seq 1 30); do
    [ "$(curl -s -o /dev/null -w '%{http_code}' "$url/readyz")" = "200" ] && break
    sleep 1
done
[ "$(curl -s -o /dev/null -w '%{http_code}' "$url/readyz")" = "200" ] || fail "/readyz is not ready: $(curl -s "$url/readyz")"
if docker logs "$container" 2>&1 | grep -qi 'Deprecated\|was not found in definitions'; then
    fail "the log contains deprecation or missing-definition lines"
fi
locked="$(docker logs "$container" 2>&1 | grep -c 'database is locked')"
[ "$locked" = "0" ] || fail "$locked 'database is locked' lines in the Pulse log"


if [ "${SMOKE_STOP_CONSUMER:-}" = "1" ]; then
    docker exec "$container" sh -c 'kill -STOP "$(cat /tmp/pulse-run/consumer.0.pid)"'
    for attempt in $(seq 1 60); do
        [ "$(curl -s -o /dev/null -w '%{http_code}' "$url/readyz")" = "503" ] && break
        sleep 1
    done
    [ "$(curl -s -o /dev/null -w '%{http_code}' "$url/readyz")" = "503" ] || fail "/readyz stayed ready with the reply consumer stopped"
    curl -s "$url/readyz" | grep -q 'reply consumer' || fail "/readyz does not name the reply consumer"
    echo "[$name] /readyz fails when the reply consumer stops"
fi
echo "[$name] OK"
