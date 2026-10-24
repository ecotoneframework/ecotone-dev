#!/bin/bash
# Proves a production Pulse image verifies licences against the release public key: the image carries that key, and
# Pulse started with Ecotone's testing licence refuses to run.
#   usage: apps/Pulse/docker/verify-production-licence.sh <image> <path to the release public key>
set -u

image="$1"
expected_key="$2"
key_in_image=/app/vendor/ecotone/ecotone/src/Messaging/Config/Licence/key.pem

fail() {
    echo "PRODUCTION LICENCE CHECK FAILED: $1" >&2
    exit 1
}

[ -s "$expected_key" ] || fail "the release public key at $expected_key is empty or missing"

actual="$(docker run --rm --entrypoint cat "$image" "$key_in_image" | sha256sum | cut -d' ' -f1)"
expected="$(sha256sum < "$expected_key" | cut -d' ' -f1)"
[ "$actual" = "$expected" ] || fail "$image carries a public key ($actual) other than the release one ($expected). Build it with --secret id=ecotone_enterprise_public_key,src=<release key.pem>"
echo "release public key in the image: ok"

testing_licence="$(docker run --rm --entrypoint php "$image" -r 'require "vendor/autoload.php"; echo Ecotone\Test\LicenceTesting::VALID_LICENCE;')"
[ -n "$testing_licence" ] || fail "could not read Ecotone's testing licence from $image"

output="$(timeout 120 docker run --rm \
    -e APP_SECRET=licence-check \
    -e ECOTONE_LICENCE_KEY="$testing_licence" \
    -e PULSE_TRANSPORT_DSN=sqlite:////tmp/transport.db \
    -e PULSE_SERVICES=orders:orders_channel \
    "$image" 2>&1)"
status=$?
[ "$status" -ne 0 ] && [ "$status" -ne 124 ] || fail "$image started with Ecotone's testing licence (exit $status), so it does not verify against the release public key. Output: $output"
echo "$output" | grep -q "Invalid licence key provided" || fail "$image stopped, but not because it refused the testing licence. Output: $output"
echo "testing licence refused: ok"
