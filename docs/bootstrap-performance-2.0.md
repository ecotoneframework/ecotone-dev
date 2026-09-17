# Ecotone 2.0 bootstrap performance

Base `19d6d755`, branch `dgafka/ecotone-2-0-bootstrap-perf`. Four optimisations committed; see "Final numbers".

## Final numbers

`BootingEcotoneBenchmark`, base `19d6d755` against the four optimisation commits, CPU 2, Xdebug unloaded, 1 GB memory
limit, 10 iterations, 20 warm-up revolutions. Revolutions are chosen per subject so that an iteration lasts long enough
to average out host interference: Symfony 30,000 (50,000 with opcache), Laravel 2,500, cached Lite 3,000, uncached
Lite 800. Before and after were taken in the same session on the same busy host; the Lite pairs are adjacent, the
Symfony/Laravel "after" samples with opcache disabled were taken about 25 minutes before their "before" samples.
Every sample with its iterations is in [final-bootstrap.csv](benchmarks/bootstrap-2.0/final-bootstrap.csv).

| subject | opcache disabled: before → after | change | opcache enabled: before → after | change |
|---|---|---|---|---|
| `bench_symfony_prod` (compiled) | 86.18 µs ±2.30% → 85.84 µs ±2.73% | none | 49.32 µs ±1.44% → 48.98 µs ±1.95% | none |
| `bench_symfony_dev` (compiled, debug) | 97.72 µs ±2.32% → 100.10 µs ±1.85% | none (within noise) | 59.87 µs ±2.18% → 60.97 µs ±1.71% | none |
| `bench_laravel_prod` (compiled) | 1.985 ms ±1.95% → 1.970 ms ±1.29% | none | 1.169 ms ±1.45% → 1.183 ms ±1.98% | none |
| `bench_laravel_dev` | 4.026 ms ±0.71% → 3.342 ms ±0.87% | **−17.0%** | 3.045 ms ±1.05% → 2.293 ms ±1.61% | **−24.7%** |
| `bench_lite_prod` (cached Lite) | 2.992 ms ±2.82% → 1.383 ms ±1.77% | **−53.8%** | 2.424 ms ±1.77% → 0.975 ms ±3.01% ¹ | **−59.8%** |
| `bench_lite_dev` (uncached Lite) | 16.391 ms ±1.04% → 9.962 ms ±1.76% | **−39.2%** | 15.647 ms ±0.87% → 9.317 ms ±0.97% | **−40.5%** |

¹ 0.01 points over the bar; the isolated measurement of optimisation 3 (1.548 ms ±1.60%) and the gain of 60% leave no doubt.

### Compiled versus Lite split

- **Compiled containers (Symfony prod/dev, Laravel prod): no change, by design.** They load a dumped container and never
  construct the annotation finder, so none of the four changes is on their path. The numbers confirm it to within noise.
- **Cached, discovery-backed boots (cached Lite, Laravel dev, and Tempest which shares `ContainerCacheLayout`):**
  the gain comes from optimisation 1 (about −14 to −17% of cached Lite) and optimisation 3 (about −46 to −51% of what
  was left). These paths build the finder and fingerprint the code on every boot to decide whether the dumped container
  is still valid. This is also what an **end user's** `EcotoneLite::bootstrapFlowTesting()` pays per test, because Lite
  switches the automatic cache on whenever the root `composer.json` is not an `ecotone/*` package.
- **Uncached Lite (every flow test inside the Ecotone repositories):** optimisation 2 (−19 to −26%), optimisation 4
  (−7 to −15%) and optimisation 3 (the fingerprint is still computed) compound to −39 to −41%.

The full 65-subject suite on the final code, default per-subject settings, both profiles, is in
[final-full.csv](benchmarks/bootstrap-2.0/final-full.csv) next to the [baseline](benchmarks/bootstrap-2.0/baseline-full.csv).
Its short subjects run 10 revolutions and are as noisy as the baseline's; it shows that every subject runs,
not how fast.

### Verification

Full suite on the final code: `Tests: 3859, Assertions: 216959, Skipped: 39, Risky: 35`, zero errors, zero failures
(DataProtection fixture generated first). phpstan: no errors. php-cs-fixer: no changes on the touched files.
`bin/check-licence.php`: passes; no new PHP files were added.

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
- **Laravel at 1,000 revolutions, settled:** peak memory is 298.6 MB (`bench_laravel_prod`) and 312.1 MB
  (`bench_laravel_dev`) because every revolution creates a new Laravel `Application` in the same process. That exceeds
  the image's 256 MB `memory_limit`, hence exit code 255. With the 1 GB limit from the private ini directory both
  subjects complete (3.033 ms ±2.22%, 4.694 ms ±2.57%). The 1 GB limit is part of the measurement conditions for all runs.

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

### 3. `xxh128` fingerprint for class files and `composer.lock` — `f8ad80b2`

Xdebug-free `hrtime` probes (temporary, never committed; 2,000 cached bootstraps, CPU 2, opcache on) showed where
cached Lite really goes after optimisation 1:

| share of cached Lite bootstrap | phase |
|---|---|
| 36.0% (1.19 ms) | `sha1_file(composer.lock)` — a 617 KB lock file |
| 32.5% (1.08 ms) | `sha1_file` over the 84 registered class files |
| 19.7% (0.65 ms) | class discovery (`scandir`, `realpath`, read + namespace regex) |
| 4.5% | class attributes, autoload and the `#[Environment]` scan |
| < 1% each | `gc_collect_cycles`, config serialisation, fetching `ConfiguredMessagingSystem` |

Micro-benchmark of the digest alone: `composer.lock` sha1 1,182 µs → xxh128 101 µs; small class file 14.9 µs → 6.0 µs.
The fingerprint detects change, it is not a security boundary, and it stays content-based.

| cached Lite (`bench_lite_prod`), 3,000 revs | before | after | change |
|---|---|---|---|
| opcache disabled, round 1 | 3.895 ms ±2.23% | 2.115 ms ±1.99% | −45.7% |
| opcache enabled, round 2 | 3.147 ms ±1.43% | 1.548 ms ±1.60% | −50.8% |
| one side over the bar | 3.710 ms ±3.51% / 3.261 ms ±2.53% | 2.192 ms ±2.34% / 1.629 ms ±3.35% | −40.9% / −50.0% |

### 4. Use statements parsed once per `InterfaceToCallRegistry` — `454eedb2`

The Xdebug profile pointed at the recursive definition walkers (`RegisterInterfaceToCallReferences`,
`ValidityCheckPass`, `SymfonyContainerImplementation::resolveArgument`) as the largest self times after optimisation 2.
**That was an Xdebug artefact**: per-call overhead inflates call-heavy code. The `hrtime` probes on 200 uncached
bootstraps gave the real split:

| share of uncached Lite bootstrap | phase |
|---|---|
| 39.4% | `MessagingSystemConfiguration::process` (modules' compiler pass 16%, interceptor matching 9–10%) |
| 22.9% | building module configurations (`AnnotationModuleRetrievingService`) |
| 18.0% → 10–12% after opt. 3 | annotation finder construction + fingerprint |
| 7.2% | `SymfonyContainerImplementation::process` |
| 6.1% | `gc_collect_cycles()` |
| 2.5% / 1.6% | `RegisterInterfaceToCallReferences` / `ValidityCheckPass` — *not* the hot spots Xdebug suggested |
| **26.5%, cutting across the phases above** | `InterfaceToCall::createWithAnnotationFinder`, of which **18.1% is `TypeResolver::getTypeContext`** |

`getTypeContext` read the class file and regex-parsed its `use` statements twice per method with a new `TypeResolver`
per `InterfaceToCall`. One resolver per registry with a per-file memo fixes it without static state.

| uncached Lite (`bench_lite_dev`), 800 revs | before | after | change |
|---|---|---|---|
| opcache disabled | 19.621 ms ±2.09% | 18.220 ms ±2.37% | −7.1% |
| opcache enabled | 18.728 ms ±2.75% | 15.991 ms ±1.54% | −14.6% |
| opcache disabled, both sides just over the bar | 19.394 ms ±3.15% | 17.402 ms ±3.19% | −10.3% |

## Dead ends (measured, not committed)

- **Cheaper directory walk + first-match namespace regex in class discovery.** Skipping `realpath('.')`/`realpath('..')`
  and using `preg_match` instead of `preg_match_all`: discovery 0.517 → 0.46 ms, about 3.5% of cached Lite. That is at
  the noise floor of this host, so it cannot be demonstrated and was dropped.
- **Hoisting `getInterfaceToCall()` out of the interceptor-matching loop** (9,000 → 1,500 registry lookups per
  bootstrap): interceptor matching 1.71 → 1.51 ms, 1.2% of uncached bootstrap. Not demonstrable; dropped.
- **Optimising the recursive definition walkers.** They hold the top self times under Xdebug but are 2.5% and 1.6%
  of real time. Not pursued.
- **Optimisation 1 on the uncached path.** It does nothing there (22.26 → 22.30 ms); the attribute instantiation simply
  moves to the first method query. It is a cached-path change only.

## Judged too risky

- **Process-wide (static) caches** of attributes, use statements, `InterfaceToCall`s or module configurations. They would
  be the biggest win for test suites that bootstrap thousands of times, and they are exactly where one bootstrap could
  observe another's state (attribute instances are mutable objects handed to modules; `#[Environment]` filtering and
  `classesToResolve` differ per bootstrap). Every memo added here lives on an object that is created per bootstrap.
- **Removing `gc_collect_cycles()` from `EcotoneLite`** (6–7% of uncached bootstrap, < 1% of cached). It was added
  deliberately together with "always use cache for Lite" to keep memory bounded in suites that bootstrap repeatedly.
  Removing it trades time for peak memory in users' suites; no evidence here can justify that.
- **`filemtime`/`filesize` instead of content digests** for the fingerprint. Would remove most of the remaining 0.6 ms,
  but reproducible builds normalise mtimes and a patch-version bump in `composer.lock` keeps the size identical,
  so a stale container could be served. Content-based invalidation is kept.
- **Skipping Ecotone's own module classes (about 60 of the 84 files) in the fingerprint** because `composer.lock` already
  covers vendor code. False for path repositories, patched vendors and this monorepo.
- **Deriving class names from PSR-4 paths instead of reading each file's namespace.** Changes discovery semantics for
  files whose namespace does not match their path.

## Observation outside this task

phpstan reported `EventSourcingHandlerExecutor constructor invoked with 4 parameters, 3 required` — in a gitignored
dumped container under `Monorepo/ExampleAppEventSourcing/var/cache` left behind by a benchmark run, not in source.
After deleting that generated directory phpstan is clean. It does mean some definition passes a superfluous
constructor argument, which PHP ignores at runtime; worth a separate look.

## Opcache anomaly

Not reproduced in any clean run by either worker. Cached Lite is consistently *faster* with opcache enabled
(3.65 → 3.17 ms before any change, 3.13 → 2.63 ms after). The original observation compared opcache-disabled samples
collected alone against opcache-enabled samples from an aborted full-suite run with Xdebug loaded; it was an artefact.
