#!/bin/sh
# Stops every OpenSwoole server started from Monorepo/SwooleApp inside this container.
for process in /proc/[0-9]*; do
    pid="${process#/proc/}"
    if [ "$pid" != "$$" ] && tr '\0' ' ' < "$process/cmdline" 2>/dev/null | grep -q "server.php\|octane\|swoole"; then
        kill -9 "$pid" 2>/dev/null
    fi
done
sleep 1
