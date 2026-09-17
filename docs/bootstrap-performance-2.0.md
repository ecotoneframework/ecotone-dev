# Ecotone 2.0 bootstrap performance

Investigation in progress on `19d6d755`, branch `dgafka/ecotone-2-0-bootstrap-perf`.

## Measurement conditions

PHP 8.5.3 and phpbench 1.7.0 run in the Docker `app` service as its default non-root user.
All runs use the tracked `phpbench.json`. The former temporary phpbench config was removed.

Timing runs unload Xdebug completely. A private ini scan directory contains symlinks to the normal
configuration files except `docker-php-ext-xdebug.ini`; `PHP_INI_SCAN_DIR` is inherited by phpbench's
child processes. Both `extension_loaded('xdebug')` and phpbench's environment banner confirm it is absent.
This is stronger than `XDEBUG_MODE=off`, which leaves the extension loaded.

The host has an Intel Core Ultra 9 275HX with 24 heterogeneous cores. Measurement trials are pinned
to CPU 2 with `taskset -c 2` to prevent migration between core types. Other host services remain running.
This worker does not run tests or profiles concurrently with timing samples. An independent PHPUnit
process was observed during later calibration; those overlapping samples are not accepted as decisive comparisons.

The acceptance threshold for an optimisation comparison is at most 3% relative standard deviation
for the affected subject, with repeated before/after runs and a gain materially larger than the noise.
No improvement is claimed from a noisy subject. Diagnostic Xdebug profiles are kept separate from timing results.

`bench_lite_prod` is cached bootstrap; `bench_lite_dev` is uncached bootstrap. These are the subjects
from `FullAppBenchmarkCaseTrait`, not the `executeFor*` callbacks.

## Calibration and rejected measurements

- The first clean trial used 100 revolutions, 10 iterations, and 10 warmups without CPU pinning.
  Relative standard deviations ranged from 5.20% to 17.49%; these are not an accepted baseline.
- The next trial used CPU 2, 1,000 revolutions, 10 iterations, and 20 warmups.
  Uncached Lite reached 0.67% relative standard deviation and cached Lite reached 2.54%.
  Both Laravel subjects exited with code 255 under the default 256 MB memory limit.
  A separate 1 GB ini limit is being evaluated for the higher-revolution runs.
- Short Symfony samples remain noisy even at 1,000 revolutions. They do not support small comparative claims.

## Earlier blocker, now fixed upstream

The original attempt stopped on `57d2e463` before changing framework code.
The AMQP publishing benchmark registered `Enqueue\AmqpExt\AmqpConnectionFactory`, while container
resolution requested `Ecotone\Amqp\Connection\AmqpExtConnectionFactory`. The resulting exception was:

```text
Reference Ecotone\Amqp\Connection\AmqpExtConnectionFactory was not found in definitions
/data/app/packages/Ecotone/src/SymfonyContainer/ExternalReferenceResolver.php:30
```

All six AMQP publishing subjects failed. Separately, phpbench's Git environment provider could not
resolve host worktree metadata inside Docker. Both defects were fixed upstream in the merged base:
benchmark and cross-module connection registrations now use Ecotone factories, and the tracked
phpbench configuration excludes the Git provider.

Every timing from that attempt is discarded. Xdebug was loaded; relative deviations were 11–24%;
opcache-disabled bootstrap measurements were collected alone while enabled measurements came from
an aborted full-suite run. The apparent cached-Lite opcache slowdown therefore requires fresh evidence.
The original diagnostic report was `/tmp/ecotone-bootstrap-blocked-report.md`; its confirmed findings
are preserved here so they do not depend on temporary files.

## Full-suite baseline

Both complete suites on the merged base passed: **65 subjects, zero errors and zero failures per profile**.
All six AMQP publishing subjects ran in both profiles. These full suites use the repository's default
per-subject revolutions, iterations and warmups, CPU 2, Xdebug unloaded and the same 1 GB memory limit.
They establish the working baseline; their noisy short samples are not used to claim an optimisation.

[Full-suite baseline data](benchmarks/bootstrap-2.0/baseline-full.csv) preserves each subject's prod/dev
name, opcache profile, settings, mode, mean, relative deviation and individual iteration averages in microseconds.

## Diagnostic profile findings

The separate Xdebug profile repeats each Lite subject ten times. Profiling overhead changes absolute
times substantially, so profile times are not benchmark results. Uncached Lite calls annotated-method
discovery 35 times per bootstrap, making approximately 12,851 cached-method lookups.
`FileSystemAnnotationFinder::findAnnotatedMethods` has the largest self-time in this profile.
A candidate experiment is to reuse method metadata within one finder, while keeping every bootstrap isolated.

Cached Lite still constructs its annotation finder and hashes class files and `composer.lock`.
File hashing and attribute discovery therefore remain costs even when the container itself is cached.
The clean trials have not reproduced the previous large opcache-enabled slowdown.

## Handover and host conditions (second worker)

A second worker took over on `1a244e04`. At takeover the first worker's process was still alive and one queued
script of it (`/tmp/ecotone-red-bootstrap.sh`) ran a pinned phpbench sample on CPU 2 in the same container.
No timing sample was taken until that run had finished and the process was confirmed idle. Every timing run since
is gated: it refuses to start when any other PHP process exists in the `app` container.

The host is shared and busy throughout (load average 14-26 on 24 cores, every core 50-60% utilised by other
worktrees' test suites, container health checks and desktop applications). That cannot be changed from this worktree.
Consequences for method:

- Absolute numbers drift with host load (uncached Lite baseline read 22.3 ms in a quieter window and 25-27 ms later).
  Only **before/after pairs adjacent in time** are compared; numbers from different windows are never compared.
- Short subjects need more revolutions: cached Lite at 1,000 revolutions gave 3.2-7.3% rstdev, at 3,000 revolutions 1.0-2.1%.
- Samples above 3% rstdev are reported but never used as the deciding evidence.

## Optimisations

### 1. `#[Environment]` bans without instantiating every method attribute — `b081c5a7`

Profile evidence (cached Lite, 10 bootstraps, Xdebug, diagnostic only): `ContainerCacheLayout::resolve` is 186.6 of
199.1 ms inclusive; the finder constructor alone is 140.1 ms, of which 68.4 ms is `getCachedMethodAnnotations`
(3,120 calls) instantiating every attribute of every public method just to look for `#[Environment]`.
The constructor now asks reflection for `Environment` attributes only. Other method attributes are resolved lazily.

| cached Lite (`bench_lite_prod`), 3,000 revs | before | after | change |
|---|---|---|---|
| opcache disabled, round 1 | 3.650 ms ±1.49% | 3.127 ms ±1.02% | −14.3% |
| opcache disabled, round 2 | 3.651 ms ±1.28% | 3.174 ms ±1.60% | −13.1% |
| opcache enabled, round 1 | 3.173 ms ±1.08% | 2.628 ms ±1.83% | −17.2% |
| opcache enabled, round 2 | 3.206 ms ±2.09% | 2.676 ms ±3.11% (over the bar) | −16.5% |

Uncached Lite is unchanged by this commit (22.26 → 22.30 ms): the work moves to the first method query there.

### 2. Annotated-method index, once per finder — `4e350128`

Profile evidence (uncached Lite): `findAnnotatedMethods` 350 calls / 10 bootstraps, largest self time (614.7 ms of
4,551 ms under Xdebug), 128,510 `getCachedMethodAnnotations` lookups. The finder now builds one ordered list of
methods that carry at least one attribute and answers all 35 queries from it. The list is an instance property;
each bootstrap creates its own finder, so nothing crosses bootstraps.

| uncached Lite (`bench_lite_dev`) | before | after | change |
|---|---|---|---|
| opcache disabled, 1,000 revs | 27.064 ms ±1.44% | 21.991 ms ±2.75% | −18.7% |
| opcache enabled, 600 revs | 26.354 ms ±2.93% | 19.430 ms ±2.28% | −26.3% |
| rejected as evidence (rstdev > 3% on one side) | 26.356 / 25.196 / 23.731 ms | 21.383 / 20.736 / 17.899 ms | same direction |

## Opcache anomaly

Not reproduced in any clean run by either worker. Cached Lite is consistently *faster* with opcache enabled
(3.65 → 3.17 ms before any change, 3.13 → 2.63 ms after). The original observation compared opcache-disabled samples
collected alone against opcache-enabled samples from an aborted full-suite run with Xdebug loaded; it was an artefact.
