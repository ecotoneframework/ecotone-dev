#!/bin/sh
# Usage: interleave.sh <port>
# Request A opens a transaction, pauses 0.5 s outside the database (as an outbound HTTP call would), then fails.
# Request B arrives 0.1 s later on the same worker, carries no tenant header, and succeeds.
# Isolated requests leave: A absent, B stored with tenant null.
port="$1"
run="$(date +%s%N)"
curl -s -X POST "localhost:$port/record" -d "{\"id\":\"A-$run\",\"pause\":0.5,\"fail\":true,\"metadata\":{\"tenant\":\"tenant-of-A\"}}" > /tmp/interleave-a &
sleep 0.1
echo "B: $(curl -s -X POST "localhost:$port/record" -d "{\"id\":\"B-$run\"}")"
wait
echo "A: $(cat /tmp/interleave-a)"
echo "stored A: $(curl -s -X POST "localhost:$port/ledger" -d "{\"id\":\"A-$run\"}")"
echo "stored B: $(curl -s -X POST "localhost:$port/ledger" -d "{\"id\":\"B-$run\"}")"
echo "state:    $(curl -s -X POST "localhost:$port/state" -d '{}')"
