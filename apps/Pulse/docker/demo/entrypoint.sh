#!/bin/bash
# The compose demo profile only: starts Pulse on Ecotone's testing licence when no ECOTONE_LICENCE_KEY is given.
# The image's own entrypoint never does this; a production image refuses the testing licence.
set -e

if [ -z "${ECOTONE_LICENCE_KEY:-}" ]; then
    ECOTONE_LICENCE_KEY="$(php -r 'require "vendor/autoload.php"; echo Ecotone\Test\LicenceTesting::VALID_LICENCE;')"
    export ECOTONE_LICENCE_KEY
    echo "Pulse demo: running on Ecotone's testing licence. Your own services need your Ecotone Enterprise key." >&2
fi

exec pulse-entrypoint "$@"
