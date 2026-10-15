#!/bin/sh
set -e

cd "$(dirname "$0")/.."

BIG_GENERATED_FILE=./tests/Fixture/files/big-generated-file
if [ ! -e "$BIG_GENERATED_FILE" ] || [ "$(wc -c < "$BIG_GENERATED_FILE")" -ne "209715200" ]; then
    echo "Please wait while I create a large random test plaintext file..."
    if ! dd if=/dev/urandom "of=$BIG_GENERATED_FILE" bs=1M count=200; then
        echo "Failed to create $(pwd)/$BIG_GENERATED_FILE" >&2
        exit 1
    fi
fi
