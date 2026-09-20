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
