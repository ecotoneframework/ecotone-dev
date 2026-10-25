#!/bin/bash
# Usage: entrypoint.sh web | worker <channel>... | pulse
# DEMO_APP names the application directory: ticketing or storefront under demo/, pulse for apps/Pulse.
set -euo pipefail

role="${1:-web}"
shift || true

case "${DEMO_APP:-}" in
    ticketing | storefront)
        cd "/repo/demo/$DEMO_APP"
        php /repo/demo/docker/pin-path-package-versions.php
        ;;
    pulse)
        cd /repo/apps/Pulse
        php docker/pin-path-package-versions.php
        ;;
    *)
        echo "DEMO_APP is \"${DEMO_APP:-}\", but the demo runs ticketing, storefront or pulse. Set DEMO_APP to one of them in demo/docker-compose.yml." >&2
        exit 1
        ;;
esac

export COMPOSER=composer.pinned.json
export SYMFONY_LOCKFILE=symfony.lock

if [ ! -f vendor/autoload.php ]; then
    echo "Demo: installing $DEMO_APP's dependencies, with ecotone/* linked from /repo/packages."
    composer install --no-interaction --prefer-dist --no-scripts
fi

if [ -z "${ECOTONE_LICENCE_KEY:-}" ]; then
    ECOTONE_LICENCE_KEY="$(php -r 'require "vendor/autoload.php"; echo Ecotone\Test\LicenceTesting::VALID_LICENCE;')"
    export ECOTONE_LICENCE_KEY
    echo "Demo: $DEMO_APP runs on Ecotone's testing licence. Set ECOTONE_LICENCE_KEY to use your own."
    case "$DEMO_APP" in
        ticketing) local_env=.env.local ;;
        storefront) local_env=.env ;;
        *) local_env= ;;
    esac
    if [ -n "$local_env" ]; then
        touch "$local_env"
        sed -i '/^ECOTONE_LICENCE_KEY=/d' "$local_env"
        echo "ECOTONE_LICENCE_KEY=$ECOTONE_LICENCE_KEY" >> "$local_env"
    fi
fi

if [ "$DEMO_APP" = pulse ]; then
    php bin/console importmap:install
    exec pulse-entrypoint run
fi

if [ "$DEMO_APP" = ticketing ]; then
    console=(php bin/console)
    prepare() {
        "${console[@]}" importmap:install
        "${console[@]}" doctrine:migrations:migrate --no-interaction --allow-no-migration
        "${console[@]}" messenger:setup-transports
        "${console[@]}" ecotone:migration:database:setup --initialize
    }
else
    console=(php artisan)
    mkdir -p storage/app storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache
    prepare() {
        "${console[@]}" package:discover
        "${console[@]}" migrate --force
        "${console[@]}" ecotone:migration:database:setup --initialize
    }
fi

case "$role" in
    web)
        deadline=$(( $(date +%s) + 60 ))
        until prepare; do
            if [ "$(date +%s)" -ge "$deadline" ]; then
                echo "Demo: $DEMO_APP could not set up its database tables within 60 seconds. Check that postgres is healthy (docker compose -f demo/docker-compose.yml ps postgres) and the connection settings in demo/docker-compose.yml, then restart the container." >&2
                exit 1
            fi
            sleep 2
        done
        export DEMO_PUBLIC_DIR="$PWD/public"
        exec frankenphp run --config /etc/caddy/Caddyfile
        ;;
    worker)
        if [ "$#" -eq 0 ]; then
            echo "Demo: the worker role needs the channels to consume, for example: entrypoint.sh worker ticketing ticketing_async." >&2
            exit 1
        fi
        run_dir=/tmp/demo-workers
        mkdir -p "$run_dir"
        rm -f "$run_dir"/*.pid
        pids=()
        stopping=0
        trap 'stopping=1; kill -TERM "${pids[@]}" 2>/dev/null || true' TERM INT
        for channel in "$@"; do
            (
                child=
                trap '[ -n "$child" ] && kill -TERM "$child" 2>/dev/null; exit 0' TERM INT
                while true; do
                    "${console[@]}" ecotone:run "$channel" --executionTimeLimit=3600000 --memoryLimit=256 &
                    child=$!
                    echo "$child" > "$run_dir/$channel.pid"
                    wait "$child" || echo "Demo: the consumer of $channel stopped, restarting it in 2 seconds." >&2
                    sleep 2
                done
            ) &
            pids+=("$!")
        done
        status=0
        wait -n "${pids[@]}" || status=$?
        kill -TERM "${pids[@]}" 2>/dev/null || true
        wait || true
        if [ "$stopping" = 1 ]; then
            exit 0
        fi
        exit "$(( status == 0 ? 1 : status ))"
        ;;
    *)
        exec "$role" "$@"
        ;;
esac
