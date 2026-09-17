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
