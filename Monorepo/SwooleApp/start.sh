#!/bin/sh
# Usage: start.sh symfony|laravel
# Starts the application on OpenSwoole in the background, after stopping whatever this container already serves.
# Symfony listens on 9501 and reads SWOOLE_WORKERS, SWOOLE_COROUTINES, SWOOLE_MAX_REQUEST and SWOOLE_BOOT (see Symfony/server.php).
# Laravel runs on Octane on 9502 and reads SWOOLE_WORKERS and SWOOLE_MAX_REQUEST.
directory="$(dirname "$0")"
sh "$directory/stop.sh" 2>/dev/null

case "$1" in
    symfony)
        nohup php "$directory/Symfony/server.php" > /tmp/symfony-server.log 2>&1 &
        port=9501
        ;;
    laravel)
        mkdir -p "$directory/Laravel/bootstrap/cache" "$directory/Laravel/storage/logs" "$directory/Laravel/storage/framework/cache" \
            "$directory/Laravel/storage/framework/views" "$directory/Laravel/storage/framework/sessions"
        rm -f "$directory/Laravel/storage/logs/octane-server-state.json"
        export APP_KEY="${APP_KEY:-base64:ZWNvdG9uZS1kZW1vLXN0b3JlZnJvbnQta2V5LTMyYnk=}"
        export COMPOSER_VENDOR_DIR="$(cd "$directory/../.." && pwd)/vendor"
        nohup php "$directory/Laravel/artisan" octane:start --server=swoole --host=0.0.0.0 --port=9502 \
            --workers="${SWOOLE_WORKERS:-2}" --max-requests="${SWOOLE_MAX_REQUEST:-500}" > /tmp/laravel-server.log 2>&1 &
        port=9502
        ;;
    *)
        echo "Usage: start.sh symfony|laravel" >&2
        exit 1
        ;;
esac

for attempt in $(seq 1 50); do
    if curl -s -o /dev/null "localhost:$port/state" -X POST -d '{}'; then
        echo "$1 is serving on port $port"
        exit 0
    fi
    sleep 0.2
done
echo "$1 did not start serving on port $port within 10 seconds; see /tmp/$1-server.log" >&2
exit 1
