# Lite diagnostic workloads

Use the repository root, its Docker `app` service, and root Composer installation.
The private `/tmp/lite-speed-ini` directory must include the normal ini files except Xdebug
and set a 1 GB memory limit. PHPUnit still applies its own 384 MB limit.

`measure.py LABEL COMMAND...` refuses active PHP test/benchmark workloads before starting,
checks other containers for overlapping workloads every second, and records a log and JSON receipt.
A receipt is valid only when `accepted` is true; inspect benchmark iteration deviation separately.
The runner's wall clock includes Docker startup and the subprocess wait polling overhead (at most approximately 50 ms).

For example:

```sh
python3 docs/benchmarks/lite-test-2.0/measure.py merged-core-off \
  docker compose exec -T -e PHP_INI_SCAN_DIR=/tmp/lite-speed-ini \
  app taskset -c 2 php -d opcache.enable_cli=0 vendor/bin/phpunit \
  --no-coverage --testsuite 'Core tests'
```

`LiteFlowTestingBenchmark` checks synchronous commands and queries, queued events and `run()`,
and an asynchronous projection. `bench_bus_execution` boots outside the measured body;
the other subjects include a new isolated bootstrap per revolution.

Use the tracked `phpbench.json` with `--profile=opcache_disabled` or `--profile=opcache_enabled`,
`--bootstrap=docs/benchmarks/lite-test-2.0/benchmark-bootstrap.php`, and a recorded XML dump.
The benchmark bootstrap loads diagnostic GC wrappers, never framework source changes.

`run-workload.php SUBJECT COUNT` repeats a subject in one PHP process for peak-memory checks.
Set `LITE_MEMORY_ONLY=1` to omit elapsed time when the host is busy. `GC_REPORT_PATH` selects
the append-only JSONL destination. `LITE_GC_POLICY` and `ASYNC_GC_POLICY` independently accept
`always` (default), `never`, `every32`, or `roots1000`; automatic PHP GC remains enabled.
The wrapper's `gc_status` data also contains diagnostic elapsed times; discard those on busy runs.

`content-cache-prototype.patch` is an experimental alternative resolver, not an installed optimisation.
Generate a temporary copy with `patch -o /tmp/TypeResolver-content-probe.php
packages/Ecotone/src/Messaging/Handler/TypeResolver.php < docs/benchmarks/lite-test-2.0/content-cache-prototype.patch`,
copy that file to the app container, and set `LITE_RESOLVER_PROBE=/tmp/TypeResolver-content-probe.php`
for the benchmark bootstrap or workload runner. The source file remains unchanged.
For PHPUnit isolation checks, load the copy using `php -d auto_prepend_file=/tmp/TypeResolver-content-probe.php`.

Set `LITE_CYCLIC_PAYLOAD=1` with `bench_bus_execution` to create an unreachable 64 KiB
self-referential object after each command. This is a stress case for handlers retaining cyclic
payload graphs, not the ordinary benchmark workload. Use `-d memory_limit=384M` and 10,000 cycles.
