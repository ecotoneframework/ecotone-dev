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

The cache prototype also passes a forced-digest-collision run (3 tests, 8 assertions):
exact source comparison prevents alias reuse when two different sources have the same key.
A filename-only negative control fails both source-change regressions, including the test
that preserves size and mtime. The benchmark tooling and patch live in
[the diagnostic artifact directory](benchmarks/lite-test-2.0/README.md).

First memory-only evidence, 10,000 fresh flow-testing bootstraps with three synchronous commands,
three queued events, `run()`, queries and assertions per bootstrap: both the unchanged policy and
suppressed bootstrap collection peak at **16 MiB allocated**. The unchanged policy explicitly collects
10,000 times at bootstrap and 30,000 times after async messages; suppressing bootstrap collection
moves reclamation to the async path. These runs overlap the coordinator gate, so their elapsed
and collector times are diagnostic only. Bootstrap-only memory comparisons are still running.

The content-cache prototype passes the full core correctness run: **1,370 tests, 2,302 assertions,
one skip**, with 64.50 MB peak reported by PHPUnit. That run overlaps the coordinator gate;
its runtime is excluded from every speed comparison.

All four 10,000-iteration bootstrap-only memory probes completed. Allocated peaks are 14 MiB
(always), 14 MiB (never), 16 MiB (every 32 calls), and 14 MiB (at least 1,000 GC roots).
Used-memory peaks are respectively 13,106,408; 13,809,104; 14,601,512; and 13,106,456 bytes.
The root-threshold variant collected on every bootstrap in this workload, so it skipped no work.
Automatic PHP GC stayed enabled in all variants. These are workload-specific bounds, not a promise
about applications holding much larger object graphs; actual core-suite memory checks follow.

Actual core-suite memory checks pass with all four bootstrap GC policies: 1,370 tests,
2,302 assertions, one skip, **62.50 MiB allocated peak** in each run. Each process reaches
746 bootstrap and 447 async collection call sites. Total PHP GC runs are 1,575 (always),
1,143 (never), 1,161 (every32), and 1,565 (roots1000). All elapsed times remain excluded
because the coordinator's full-suite gate is running concurrently.

Execution memory checks repeat 100,000 command/event/async/query cycles after one bootstrap.
All four async GC policies peak at **14 MiB allocated**. The always policy calls explicit async
collection 100,000 times but reclaims only 171 cycles; every32 also reclaims 171 cycles with
3,125 async collections, while roots1000 collects once on the async path. This fixed-handler
workload creates few cycles, so it cannot establish a universal memory bound for user handlers.
Execution timing comparisons are still pending a quiet host.

A separate execution stress case creates one unreachable 64 KiB cyclic payload per command,
then publishes and consumes an async event. At 10,000 cycles and the suite's 384 MiB limit,
always-collect peaks at **14 MiB**, every32 at **16 MiB**, and roots1000 at **60 MiB**.
Never-collect exhausts **384 MiB after 5,545 completed async cycles**, before automatic GC's
root threshold is reached. This deliberately adversarial workload demonstrates why the earlier
low-cycle memory result cannot justify removing per-message collection. Retain the execution
GC default; any opt-in policy would need an explicit memory/throughput contract outside this task.

## Clean merged-head baseline

After the coordinator gate exited, the host had no PHP test processes. Three core-suite samples
with CPU 2, Xdebug unloaded, opcache CLI disabled, and the configured 384 MiB limit all passed:
**21.958 s, 22.110 s, 22.042 s** wall time; mean **22.037 s**, relative standard deviation **0.28%**.
Every sample reports **62.50 MiB**, 1,370 tests, 2,302 assertions and one skip. The overlap monitor
recorded no external test/benchmark processes. Raw logs and command receipts are `merged-core-off-r*`.
The full merged baseline is running and discovers **3,888 tests** across the complete configuration.

## Accepted bootstrap comparison and full-suite wall time

The exact `bench_bootstrap_send_assert` comparison used adjacent CPU-2 runs, Xdebug unloaded,
300 revolutions, three iterations, five warmups, and no overlap. Opcache disabled measured
**21.037 ms ±0.78%** before versus **20.838 ms ±0.54%** with the content-addressed prototype
(−0.95%). Opcache enabled measured **20.769 ms ±1.26%** versus **20.494 ms ±0.82%** (−1.32%).
Both changes are below the evidence threshold and do not justify shipping the prototype.

The merged full suite passed **3,888 tests, 216,354 assertions, 39 skipped and 35 risky** in
**1,953.634 s** wall time, with no overlap detected. The old 3,876-test, 12-error run remains
rejected historical data. The current full run changed generated Symfony reference files again;
they are restored before committing this report. The coordinator's older full-suite timing is
not a valid before/after comparator because it predates the merged database setup and had an
untouched-base fixture failure.

The steady-state execution subject was too noisy at 3,000 revolutions (19.38% rstdev for always
collection), so no execution speed claim is made. Its memory stress result is the accepted decision:
per-message collection remains enabled because never-collect exhausted 384 MiB at 5,545 cycles,
while always-collect stayed at 14 MiB.
