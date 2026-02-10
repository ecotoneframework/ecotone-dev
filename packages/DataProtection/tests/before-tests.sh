#!/bin/sh
set -e

BIG_GENERATED_FILE=./tests/Fixture/files/big-generated-file
if [ ! -e $BIG_GENERATED_FILE ] || [ $(wc -c < $BIG_GENERATED_FILE) -ne "209715200" ]; then
    echo "Please wait while I create a large random test plaintext file..."
    dd if=/dev/urandom "of=$BIG_GENERATED_FILE" bs=1M count=200
fi
