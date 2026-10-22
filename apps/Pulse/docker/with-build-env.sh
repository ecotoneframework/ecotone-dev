#!/bin/sh
set -e

ECOTONE_LICENCE_KEY="$(php -r 'require "vendor/autoload.php"; echo Ecotone\Test\LicenceTesting::VALID_LICENCE;')"
export ECOTONE_LICENCE_KEY
export PULSE_TRANSPORT_DSN="sqlite:////tmp/pulse-build.db"
export PULSE_SERVICES="build:build_channel"
export APP_SECRET="build"
export PULSE_VERSION="${PULSE_VERSION:-build}"

exec "$@"
