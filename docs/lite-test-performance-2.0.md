# Lite test performance follow-up

Base: `d7402683`, branch `dgafka/ecotone-2-0-lite-test-speed`.
Investigation in progress; no optimization or performance improvement is claimed yet.

## Measurement contract

The previous [bootstrap report](bootstrap-performance-2.0.md) and its three CSV files remain the reference.
Use the tracked `phpbench.json`, CPU 2, and a private `PHP_INI_SCAN_DIR` containing every normal ini except
Xdebug, with a 1 GB benchmark memory limit. Both opcache profiles require adjacent repeated comparisons,
at most 3% relative standard deviation, and no concurrent tests or profiles on the host.
Suite measurements retain the PHPUnit configuration, including its 384 MB memory limit.

Docker bootstrap and root Composer installation succeeded. The DataProtection fixture was generated.
`extension_loaded('xdebug')` returns false with `/tmp/lite-speed-ini` inside the app container.
An existing PHPUnit run in another worktree (observed host PID 758521) prevents uncontaminated baseline
measurements at this checkpoint; no samples have been accepted.

## Candidate boundaries to investigate

Use-statement maps contain only strings and arrays, unlike mutable attribute objects and module configurations.
Their existing cache is per resolver; sharing across instances would also need to address changes to the source
file between bootstraps, bounded retention, and copies that cannot expose mutable configuration.
A filename-only process cache would change current cross-bootstrap file-reading semantics, so it is not yet justified.
No handler, channel, aggregate, attribute instance, or configuration object is proposed for static reuse.

`gc_collect_cycles()` currently runs at the end of `EcotoneLite::bootstrap`, affecting normal bootstrap as well
as flow testing. Removing or amortizing it requires both suite wall time and peak-memory evidence; none is yet
available, so the existing collection strategy remains unchanged.

## Outstanding work

Establish core/full suite baselines; profile repeated bootstrap-and-message workloads; measure bootstrap and
execution benchmarks with both opcache profiles; investigate safe sharing and GC memory/time tradeoffs;
accept only demonstrated improvements with isolation tests; rerun full verification and document upgrade impact.

## First suite baseline

CPU 2, Xdebug unloaded, opcache CLI disabled: `vendor/bin/phpunit --no-coverage --testsuite "Core tests"`
passed with 1,368 tests, 2,295 assertions and one skip. Bash wall clock: **24.713 s**; PHPUnit-reported
runtime: 24.492 s; peak allocated memory: **62.50 MB**. Raw output is in
[baseline-core-off.log](benchmarks/lite-test-2.0/baseline-core-off.log).
This is one baseline sample, not a comparative performance claim.

The full baseline discovers **3,876 tests**, rather than the historical report's 3,859, at the supplied base.
It is still running. An unrelated Composer installation briefly appeared on the host during this run;
retain this caveat when interpreting its eventual wall time.

The first full-suite wall-time sample is **rejected**: external PHPUnit processes (observed PIDs 1957825 and
1975073) started while it was running. It remains useful as a correctness run only. The coordinator was notified
and a quiet measurement window requested; no speed or GC-memory conclusions can be drawn from this run.
Code inspection also found explicit collection after each consumed message in `PollToGatewayTaskExecutor::execute`;
this is an execution-path candidate requiring its own memory evidence, separate from Lite bootstrap collection.

The run completed in 1,900.852 seconds wall time but failed on the untouched base: 3,876 tests, 12 errors, 39 skipped and 35 risky. The errors were concentrated in cross-module/example integration tests, including a missing `Monorepo/ExampleAppEventSourcing/Laravel/.env` warning and generated Symfony reference changes. Because the failure occurred before framework edits and concurrent PHPUnit processes were present, this run is not a valid before/after suite baseline. The focused resolver isolation regression test passes (2 tests, 4 assertions), but no process-wide cache was introduced.

## Resumed investigation on the merged head

Merged `dgafka/ecotone-2-0-work` at `e91c711d` into this branch as `1630d730`.
The old 24.713-second core sample predates the database setup changes and is historical only.
Restored both generated Symfony `config/reference.php` files. No package-local `vendor/`
directories were present; refreshed the root autoloader and regenerated the DataProtection fixture.
The current root PHPUnit configuration actually lists 15 suites; the final full run will use all of them.

The coordinator's PHPUnit gate was still active when work resumed, so no new timing samples have
been accepted yet. The prepared measurement runner refuses external PHPUnit/phpbench processes
and monitors for overlap during a run. Xdebug is confirmed absent from the private ini environment.
Long-run memory-only probes omit elapsed time while the gate is active.

A diagnostic resolver prototype shares only parsed string arrays, keyed by `xxh128` of the bytes
already read for each new resolver. Exact source comparison protects against digest collisions.
It retains at most 256 entries and 1 MiB of source text, clearing before exceeding either bound;
array overhead is additional but bounded by those entries and their source-derived contents.
The original per-resolver filename memo remains, preserving its existing within-resolver semantics.
The prototype passes the existing changed-source isolation regression (2 tests, 4 assertions overall).
This establishes a plausible narrow subset, not a performance result or permission to share attributes.
Neither the cache prototype nor a GC policy change is in framework source.
