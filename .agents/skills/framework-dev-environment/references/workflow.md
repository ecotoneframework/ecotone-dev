# Ecotone dev workflow

The mechanics of a unit of work: which container, which command, which environment variable. The rules for the code
itself are in the other framework skills; [framework-orientation](../../framework-orientation/SKILL.md) maps them.

Every command below was run against this tree unless it is marked otherwise.

---

## Containers

```bash
docker compose up -d                          # builds app's image on first run, waits for every healthcheck
docker compose exec app composer install      # root dependencies
docker compose exec app /bin/bash             # PHP 8.5.3 — the default container
docker compose exec app8_2 /bin/bash          # PHP 8.2.29 — the supported floor
```

`app` is PHP 8.5.3, `app8_2` is PHP 8.2.29, and both mount the working tree at `/data/app`. They are not
interchangeable for database work: **`app8_2` points `DATABASE_DSN` at MySQL, not PostgreSQL**, so the same suite
run in `app8_2` also changes which engine it exercises. Reach for it when a change has to hold on the 8.2 floor —
a language feature, a dependency constraint, or a signature phpstan will not catch.

`openswoole` is PHP 8.5.3 with OpenSwoole and `swoole` is PHP 8.5.3 with Swoole compiled with
`--enable-swoole-pgsql` and `--enable-swoole-sqlite`; both are without Xdebug, which coroutines cannot run with. The tests that run messages in
real coroutines are written against the `Swoole\...` class names, which both extensions provide, and carry
`#[RequiresMethod(Coroutine::class, 'create')]`, so they are skipped in `app`. Run them in both containers, one after
the other:

```bash
docker compose exec -T openswoole vendor/bin/phpunit --no-coverage --exclude-group swoole packages/Dbal/tests/Integration/CoroutineExecutionsTest.php
docker compose exec -T swoole vendor/bin/phpunit --no-coverage --exclude-group openswoole packages/Dbal/tests/Integration/CoroutineExecutionsTest.php
```

A test that holds on one extension only (PostgreSQL waits on Swoole and is refused on OpenSwoole) carries
`#[RequiresPhpExtension('swoole')]` with `#[Group('swoole')]`, or the same pair for `openswoole`. CI runs both
extensions with `--fail-on-skipped` and excludes the other one's group, so a test without the group fails the job
where it is skipped.

Run phpstan in `app`, not in these two: with an extension loaded phpstan knows its classes and reports nothing
where CI, which has no extension, fails.

`.env` is optional (`env_file: required: false` in `docker-compose.yml`), so a fresh checkout needs nothing
created. Copy `.env.dist` to `.env` only to override a default, such as turning Xdebug on.

**`git` is not available inside either container.** Anything that shells out to git — most importantly
`php-cs-fixer` — has to run on the host.

---

## Running tests

One package at a time, inside the container, **never in parallel**: the packages share the compose services and a
parallel run produces false failures.

```bash
# A package's full CI — each package has its own vendor/, so install it first
docker compose exec app bash -lc "cd packages/JmsConverter && composer install && composer tests:ci"

# Amqp's lib-mode run takes ~7 minutes, past composer's 300 s script timeout; CI sets COMPOSER_PROCESS_TIMEOUT=3600
docker compose exec app bash -lc "cd packages/Amqp && composer install && COMPOSER_PROCESS_TIMEOUT=0 composer tests:phpunit:amqp-lib"

# One file
docker compose exec -T app vendor/bin/phpunit --no-coverage packages/Ecotone/tests/Modelling/AggregateBoundary/WithoutCounterTagTest.php

# One method — fastest feedback
docker compose exec -T app vendor/bin/phpunit --no-coverage --filter test_an_excluded_state_stored_aggregate_keeps_last_write_wins

# One directory
docker compose exec -T app vendor/bin/phpunit --no-coverage packages/Ecotone/tests/Modelling
```

**`--no-coverage` is not optional.** Without it PHPUnit 12 aborts before running anything:

```
1) XDEBUG_MODE=coverage (environment variable) or xdebug.mode=coverage (PHP configuration setting) has to be set
No tests executed!
```

The monorepo **root** `vendor/` is loaded even for a single package's run, because Ecotone's annotation finder
requires the root `vendor/autoload.php`. Keep the root install in sync with the container you are testing in.

Do not pass `-u root` to `composer` or to a test script: it leaves `vendor/` and phpunit's cache root-owned on the
host, which then blocks your own user and your editor from writing to them.

A **new** Laravel test fixture directory needs `storage/logs/.gitignore` and `bootstrap/cache/.gitkeep` copied from
a sibling under `packages/Laravel/tests/` before anything runs against it, or the first run commits its own
`laravel.log`. Two of the three DCB friction reports found this independently: `f575d7c60` and `ff8dab584` do
nothing else, and `ff8dab584` strips a tracked 177-line log out of three fixture directories.

### What `composer tests:ci` runs

It is not the same script in every package. Four of them do something extra:

| Package | `tests:ci` |
|---|---|
| Amqp | phpstan, then phpunit **twice** — `AMQP_IMPLEMENTATION=ext`, then `AMQP_IMPLEMENTATION=lib` |
| DataProtection | phpstan, then `tests/before-tests.sh` (generates a 200 MB fixture), then phpunit |
| Symfony | phpstan, phpunit, then `bin/console ecotone:list` as a smoke test |
| all others | phpstan, then phpunit |
| **monorepo root** | `bin/check-suite-parity.php`, phpstan, `packages/DataProtection/tests/before-tests.sh`, phpunit, then `quickstart-examples` |

`composer tests:local` at the root is the same as the root `tests:ci` without the quickstart examples.

### The root and per-package runs must agree

A package's tests run twice: in the package's own `composer tests:ci`, which is also what its split repository runs,
and in the root full suite. A green result from one runner is no evidence for the other — three defects existed
only in the gap between them, among them `c47252bd2`, a test-app namespace registered in the package's
`composer.json` and not in the root one. `php bin/check-suite-parity.php` (`composer tests:suite-parity`) fails
on any divergence and names the file, the expected entry and the fix:

| Check (`bin/suite-parity/`) | What must agree |
|---|---|
| `psr4-autoload` | every package `psr-4` entry is in the root `composer.json` under `packages/<Package>/`, and every root entry into a package resolves in that package |
| `testsuite-directories` | the root testsuite for a package lists exactly the package's own testsuite directories |
| `php-environment` | each package's `<php>` block and `backupGlobals`/`backupStaticProperties`/`processIsolation` equal the root's. A deliberate difference goes in its `$acceptedDivergences` with the reason; an accepted entry that stops diverging fails too |
| `stray-package-vendor` | no `packages/*/vendor` exists |
| `phpunit-configuration-name` | every package has a `phpunit.xml.dist`, the name `split-testing.yml` selects packages by |

A per-package `composer install` leaves `packages/<Package>/vendor` behind. A test that hands EcotoneLite its
package directory then requires that autoloader inside the root run, replacing the root's dependency versions —
an uncatchable `Premature end of PHP process` in an unrelated test — and its autoload map, hiding a missing root
namespace. The root `phpunit.xml.dist` therefore bootstraps through `bin/phpunit-root-bootstrap.php`, which refuses
to start while one exists. Remove them before a root run:

```bash
find packages -maxdepth 2 -name vendor -type d -prune -exec rm -rf {} +
```

There is **no `tests:behat` script and no behat suite** — behat was dropped over `074f37209`, `a3a52b970` and
finally `3a9618280`. Only an empty `packages/Symfony/tests/Behat/features/.gitkeep` survives.

### Databases

The container exports a DSN per engine. Override `DATABASE_DSN` to point a suite at another one. A change to event
sourcing, the event store or DBAL needs PostgreSQL, MySQL and MariaDB, because their DDL and locking behaviour
differ.

```bash
vendor/bin/phpunit --no-coverage packages/PdoEventSourcing/tests/                                   # PostgreSQL, the default
DATABASE_DSN="$DATABASE_MYSQL"   vendor/bin/phpunit --no-coverage packages/PdoEventSourcing/tests/  # MySQL
DATABASE_DSN="$DATABASE_MARIADB" vendor/bin/phpunit --no-coverage packages/PdoEventSourcing/tests/  # MariaDB
```

Set in the `app` container (`docker-compose.yml`), reachable by service name only from inside it:

| Variable | Value |
|---|---|
| `DATABASE_DSN` | `pgsql://ecotone:secret@database:5432/ecotone?serverVersion=16` |
| `DATABASE_MYSQL` | `mysql://ecotone:secret@database-mysql:3306/ecotone?serverVersion=8.0` |
| `DATABASE_MARIADB` | `mysql://ecotone:secret@database-mariadb:3306/ecotone?serverVersion=11.4.5-MariaDB` |
| `SECONDARY_DATABASE_DSN` | `mysql://ecotone:secret@database-mysql:3306/ecotone?serverVersion=8.0` — a genuinely separate database, for two-connection tests |
| `RABBIT_HOST` | `amqp://rabbitmq:5672` |
| `SQS_DSN` | `sqs:?key=key&secret=secret&region=us-east-1&endpoint=http://localstack:4566&version=latest` |
| `REDIS_DSN` | `redis://redis:6379` |
| `KAFKA_DSN` | `kafka:9092` |

In `app8_2`, `DATABASE_DSN` and `SECONDARY_DATABASE_DSN` are swapped: MySQL primary, PostgreSQL secondary.

There is no SQLite variable: a suite runs against SQLite by being given a `sqlite:///` **`DATABASE_DSN`**, which is
what `split-testing.yml` does for its third pass. Tests branch on the DSN's scheme, never on a separate variable.

The host ports are ephemeral by default (`${POSTGRES_PORT:-0}`), so connect from the host only after setting the
matching `*_PORT` variable.

### Dependency ranges

CI resolves each package three ways (`.github/workflows/split-testing.yml`): PHP 8.2, PHP 8.5, and PHP 8.2 with
`--prefer-lowest`. The lowest-dependency run is the one that catches a constraint that is too loose.

```bash
# in the package you changed
docker compose exec app8_2 bash -lc "cd packages/Dbal && composer update --prefer-lowest && composer tests:ci"
docker compose exec app    bash -lc "cd packages/Dbal && composer update && composer tests:ci"
```

Both forms are the shape CI uses; they were not executed while writing this file, because they rewrite the
package's lock resolution.

---

## Static analysis, licence headers, code style

```bash
# phpstan — level 3, over a baseline. The bare form is what composer tests:phpstan runs; `analyse` is its default subcommand
docker compose exec -T app vendor/bin/phpstan
docker compose exec -T app bash -lc "cd packages/Dbal && vendor/bin/phpstan"

# Licence headers. check-licence.php is silent and exits 0 when every file has one
docker compose exec -T app php bin/check-licence.php
docker compose exec -T app php bin/add-apache-licence.php      # open source
docker compose exec -T app php bin/add-enterprise-licence.php  # Enterprise modules

# Build flow snapshots: what modules register, the chain of each handler, the wiring and the traced steps
# of every flow catalogue scenario, committed under packages/Ecotone/tests/BuildFlow/baseline by main's CI
# after each merge. A pull request leaves the baseline alone; its comment shows what the change does to the flow
docker compose exec -T app php bin/build-flow.php compare --presentation=build    # what changed against the baseline, as the pull request comment shows it
docker compose exec -T app php bin/check-build-flow.php      # whether the baseline is what the code produces
docker compose exec -T app php bin/build-flow.php snapshot   # writes var/build-flow-snapshot; only an explicit --location writes the baseline
docker compose exec -T app php bin/test-build-flow-comment.php  # the tests of the pull request comment builder
```

`compare` prints GitHub Markdown by default. `--presentation` picks the view:

| Presentation | What it shows |
|---|---|
| `before-after` (default) | the changed wiring before and after, the traced steps before and after, and what ran, process by process |
| `marked` | one diagram with the changes marked, and one list of the traced steps |
| `path` | the message's path as a sequence diagram, with what changed in sentences |
| `build` | what the pull request comment shows: the build tree, then per scenario the Before/Now summary, what ran process by process with the step tree of each changed process, the message's path, and the marked wiring and traced spans collapsed |
| `json` | the raw comparison, for tooling |

A scenario of the catalogue is a `FlowScenario` returned by a private static method of one of the
`packages/Ecotone/src/Test/Flow/*FlowScenarios.php` classes and listed in that class's `all()`; the classes are
listed in `FlowScenarioCatalogue::all()`. A scenario names itself, the classes and services to boot, the extension
objects, and one `FlowStage::when(...)` per action with the spans it expects. Add one there; the pull request comment
shows it as a new scenario, and main's CI commits its snapshot under `packages/Ecotone/tests/BuildFlow/baseline` after
the merge. Contributors leave the baseline alone. The
`ecotone:build-flow:*` commands and the snapshot tooling behind them are test code in `packages/Ecotone/tests/BuildFlow`
(`Test\Ecotone\BuildFlow`): the package does not ship them, and only `bin/build-flow.php` and the framework's own
tests register them. The catalogue stays testing support in `src/Test/Flow`, because other packages' conformance tests
run it.

**What ran** (`execution` in each snapshot, and the "What ran" section of `compare`) is recorded at the
framework tracing level, `TracingConfiguration::withLevel(TracingLevel::Framework)`; the default level,
`TracingLevel::Application`, traces messages and handlers without the recording. In a running application the
framework level records whenever a span recorder (`TracingConfiguration::withSpanRecorder()`) or a tracer such as
OpenTelemetry is configured: every step Ecotone runs is a span started and ended through the configured tracer and
sent to the span recorders, so an OpenTelemetry exporter receives the steps too. That is about eight to ten
times as many spans per message. An application span
keeps its name, kind, status and attributes; its parent may now be a step. A step the recording leaves out (a call
that ran nothing, an argument conversion with nothing inside) is not exported either. The default level exports what
it did before. Each process is one entry
into the framework: a gateway call, a console command or one consumed message. Each step has a label, what ran, and
hints. A hint is a key and a value; a hint shown in lines (`shownInLines`) decides where the message goes next or
whether it goes anywhere, or says it failed, and is printed under its step as `↳ key: value`; the other hints are
stored and printed only on request. Nesting means "ran inside", with two loops drawn as nesting:

- A send through channel interceptors is a loop (`SendingInterceptorAdapter`), not a chain: each `before send` step
  is drawn inside the one before it in the order the loop calls them, the put on the channel innermost, and the
  `after send` phases follow below it in the order the loop runs them.
- The receive phases (`before receive`, `take from channel`, `after receive`) are drawn when the message has been
  taken, from what the interceptors declare, not observed one by one.

A process whose message was sent by another recorded process is a sub-flow of it: `sentBy` on the process holds the
sending process's `processNumber` and the `stepNumber` of its step that sent the message, both counted from 1. The
link is read from the trace context the message carries: a span of the consumer run whose trace parent is a span of
another process. A message whose sender was not recorded, or that arrives without its trace context, starts a flow
of its own, and so does a message another flow-testing application sent: each application's recording holds only its
own processes, so a distributed hop between two applications in one test has no `sentBy`. The link across
applications is a hint instead: the receiving process's first step carries `sent by: application user_service, the
command ticket.create` (the sending service's name from the distributed message, or `another application` when the
message names none). The catalogue runs its distributed scenarios in one application, where the hop is a sub-flow;
a message delivered again after a failure keeps the context
it was sent with, so each delivery is a sub-flow of the same send. A consumer a handler runs stays inside that
handler's process. The text prints flows in run order and each sub-flow as `sub-flow process N: …` under its sender,
in the order the sender sent them; process numbers stay the run order. `compare` states a new, gone or re-attached
sub-flow and shows each one right after its sender.

The framework wiring (`ecotone:flow:dump --framework`, and the wiring and build of a snapshot) is read only at the
framework tracing level, and needs no span recorder. `MessageFlowModule` adds the step that reads it before the
recording step, so it is the wiring the application declared, never the recording's wrappers. At the application
level, `--framework` and `ecotone:build-flow:record` are refused; `ecotone:flow:dump` without `--framework` is the
same at both levels. In the dump the part Ecotone registers itself is under the JSON key `framework`, its nodes and
edges carry the attribute `framework: true`, and Mermaid draws them with `classDef framework`; the repository's own
build-flow snapshots keep the word `build` for their sections.

A test reads the recording with `$ecotone->popExecutionRecording()` on its `FlowTestSupport`, which returns what ran
since the last pop. In flow testing the framework level needs one line,
`TracingConfiguration::createWithDefaults()->withLevel(TracingLevel::Framework)`: `EcotoneLite::bootstrapFlowTesting()`
(and only it) registers an `InMemorySpanRecorder` of its own beside the application's span recorders, and the reader
reads and clears only that one, so an application's own recorders keep their spans. The recording is an
`ExecutionRecording` (`Ecotone\Api\Tracing`) holding `processes` in run order, each a `RecordedProcess` with its
`title`, `steps` (`RecordedStep`: `depth`, `label`, `hints` of `RecordedHint` `key`, `value`, `shownInLines`) and
`sentBy` (a `RecordedSender` with `processNumber` and `stepNumber`, null for a flow), plus `lines` (the default text)
and `linesWithEveryHint`. The snapshots are built from the same data, so the two never disagree.

`phpstan.neon` is level 3 over `src` for Ecotone, Enqueue, Dbal, Amqp, JmsConverter, PdoEventSourcing, Laravel,
OpenTelemetry, Kafka, plus `packages/Symfony/DependencyInjection`, `Monorepo`, and the `Api/` of all twelve packages
that have one. Each package's own `phpstan.neon` is level 3 too and lists its `Api` beside its source directories.
The root does **not** cover `tests/`, or the `src` of the Tempest, Redis, Sqs and DataProtection packages — only their
own configs do.

Every config includes a `phpstan-baseline.neon` beside it: the errors already in the tree when the level rose, which
is the list to work down. Fix a new error rather than regenerating the baseline over it. The root baseline fails on
an entry that no longer matches, so fixing an error means deleting its entry. The per-package configs set
`reportUnmatchedIgnoredErrors: false`, because CI analyses each package against its own `vendor/` on PHP 8.2,
8.5 and `--prefer-lowest`, and an entry one of those does not reproduce must not fail the others.

Shrinking a baseline after a fix is a regeneration, done in place, because phpstan ignores only the baseline file
it is overwriting — generating to any other path applies the included baseline and reports nothing:

```bash
docker compose exec -T app vendor/bin/phpstan analyse --allow-empty-baseline --generate-baseline=phpstan-baseline.neon
docker compose exec -T app bash -lc "cd packages/Ecotone && vendor/bin/phpstan analyse --allow-empty-baseline --generate-baseline=phpstan-baseline.neon"
```

A per-package run needs that package's own `vendor/`, and the root phpunit bootstrap refuses to start while one
exists, so move them aside before running the root suites. **The Ecotone and Symfony baselines end in entries only
PHP 8.2 with `--prefer-lowest` reproduces** (phpstan 2.1.0: `DatePoint::createFromInterface()` and
`CronExpression::getRunDate()` returning `DateTimeInterface|false`, and Symfony's `NodeDefinition::children()`).
A regeneration on PHP 8.5 drops them, so re-append them and confirm the package in `app8_2` with
`composer update --prefer-lowest`; every other package regenerates identically on 8.5.

phpstan cannot catch a wrong class name in an attribute argument, because attribute arguments resolve lazily through
reflection, so a green run does not prove a rename or a move is complete.

```bash
# php-cs-fixer — ON THE HOST, never in the container. git is unavailable there, so the
# fixer loses its file filter and rewrites the whole repository.
vendor/bin/php-cs-fixer fix
vendor/bin/php-cs-fixer fix --dry-run --diff
```

Config is `.php-cs-fixer.dist.php`: `@PSR12`, `@PSR12:risky` and `@PHP80Migration` over `packages/` only, plus the
house rules listed in [conventions rule 20](tooling-and-landmines.md#20-mechanics-php-cs-fixer-enforces). It does
not add licence headers.

---

## Reading the flow comparison

The pull request comment and `php bin/build-flow.php compare --presentation=build` print each changed scenario of the flow scenario
catalogue: the Before and Now summary, what ran, process by process (every process in run order with its step tree),
the message's path as a sequence diagram (Before and After), and the marked wiring and the traced spans collapsed
under it. The terms they use:

| Term | Meaning |
|---|---|
| `(build, no span)`, a dashed arrow | a step taken from what Ecotone registers (the build or the wiring), because no span is recorded for it; a solid arrow is a traced step |
| `+` / `-` | new in the pull request / gone in it |
| process, sub-flow | a process is one entry into Ecotone: a gateway call, a console command or one consumed message. A sub-flow is a process started by a message another process sent; `sent by:` / `taken by:` name the two sides |
| `Flow · process 2 of 6` / `Sub-flow · process 3 of 6 · sent by process 2` | the heading of one process of what ran, numbered in run order after the change; a sub-flow names the process that sent its message. The heading goes on with the status, the step count and the process title |
| `unchanged`, `2 new steps`, `1 step gone`, `runs only now`, `no longer runs` | the status of a process: it runs the same steps; it runs that many steps it did not run before, or no longer runs that many; it runs only after the change; it ran only before (its heading then says `process N of M before`, its number before the change). `N steps with other hints` / `N steps moved` stand alone when nothing is new or gone |
| `46 → 48 steps` | the number of steps the process ran before and now; one number when it is the same |
| `Steps of process N` | the whole step tree of process N, from its first step to its last, open when the process changed and closed when it is unchanged (`Steps of process N before` for a process that no longer runs, all its lines `-`). Each step is a line of a `diff` block: `+` new, `-` gone, two spaces per level, its hints `↳ key: value` one level under it. Its status is in the process heading above it, so its summary carries no dot |
| `📨 send X · N steps of channel interceptors` | a fold holding what ran while the message was sent to `X` (the channel interceptors and the put on the channel); the step's label is in code font, as in every fold summary |
| `🪝 N interceptors around ⚙️ handler X` / `🪝 N interceptors around the next M steps, through X` | a fold listing interceptors that wrap one another; what the last one wraps follows the fold at the level where the first one stood: one step, named, or the next M steps, up to and including the step `X` |
| `🚪 gateway X · N steps inside` | a fold holding what ran inside a gateway call, `entrypoint sends to`, `forward to`, an aggregate save, an inline delivery, or any step with six or more steps under it among siblings |
| `… N unchanged steps` | a fold holding four or more steps in a row that did not change; three or fewer are printed as they are |
| `, in the run's artifact` after a summary | a fold left empty to keep the comment within GitHub's 65,536 character limit; the steps are in the `build-flow-comparison` artifact of the workflow run |
| `🟢 new ·` / `🔴 gone ·` before a summary's label, a plain 🟢 / 🔴 | the folded step itself is new / gone; a plain 🟢: the step ran before too, and something inside it is new or changed; a plain 🔴: a fold holding only gone steps. Such a fold is open, every other fold is closed. The comment repeats this in its legend line above the first process |
| the bar on the left | a quote block: the lines beside it are inside the fold or under the step printed just above it; the indentation of the lines restarts at that step. At most six bars deep: past that no bar is added, and the lines keep their indentation from the last bar, one level deeper per step |
| the icons | 📨 `release collected message to`, `send`; 🚪 `entrypoint sends to`, `gateway`; 🪝 `after successful send`, `before send`, `after send`, `after receive`, `before receive`, `around`, `before`, `after`; 📬 `take from channel`, `receive`; 📥 `put on channel`; 🧩 `part report to`, `record part`, `read part`, `release group`, `dispatch`, `open`; 📣 `publish-subscribe`; ➡️ `direct channel`; ↩️ `no hand-over`, `reply to`; 🔀 `forward to`, `route to`, `router`; ✅ `acknowledge`; ⚙️ `handler`; 🔎 `span`; 💾 `load`, `save`; 🔒 `begin`, `commit`; 📦 `deliver`; 📊 `project`. A step of another kind has no icon |
| hand-over, `no hand-over` | what a handler's return value does next: handed to the output channel or the reply, or `no hand-over` when nothing is returned or nothing takes it |
| `chosen by: licence, over X` | with an Enterprise licence Ecotone runs this implementation in place of the open-core `X` |
| `chosen by: …` (other values) | what picked the route or the handler: a routing key, a routing slip, the service map |
| `{generated#1}` | an id Ecotone generates while it builds (an endpoint id, a channel name), numbered in order of appearance so two builds compare equal |
| `moved, now run under the X step` / `no longer run under` | a step that still runs, but inside another step now (or no longer), for example under an interceptor added around it |
| `↳ key: value` | a hint of the step above it: what decided where the message went, or that it failed |
| Marked wiring | the part of the message flow graph the change touches, drawn once with what is new and what is gone marked; collapsed under the message's path |
| Traced spans, and What ran, process by process | Traced spans lists the application's own spans (buses, handlers, channels) the change adds or removes, as at the default tracing level; What ran lists every step Ecotone ran, process by process, as the framework level records it |
| `New: X in place of Y, and Z.`, `Gone: X.`, `2 × X (28 steps with what runs inside them) now run under it.` | the sentence under a changed process's heading: its shallowest new steps (a new step in the place of a gone one says so), its shallowest gone steps, and the steps that now run under a step they did not run under before, with how many steps that is counting what runs inside them; `under it` when that step is the one `New:` names, its label otherwise. `Runs other steps now` is printed only when none of these applies |
| `Also new: a gathering group of 2 parts opens, and 2 gatherer runs take the part reports in.` | the end of a scenario's Now sentence: what the change adds that the message's path does not show |
| `- X: 2 of 6 processes changed` / `wiring only` / `traced spans only` / `· left out of this comment` | the list of changed scenarios at the top of the comment: how many of a scenario's processes changed, or that only its wiring or its traced spans did; a scenario left out to stay within the comment limit is marked there |
| `like X except:`, `… N steps as in X` | in the other presentations (`before-after`, `marked`, `path`): a process that runs what the earlier process `X` runs, with only the lines listed below it different / steps left out because they are the same as in `X` |
| `(1 of 2)` after a process title | the first of two processes with the same title, numbered in run order |
| `(part 1 of 2)` after a process title | a gatherer run that took in part 1 of a gathering group of 2 parts |
| `chosen by: routing slip, its last entry` / `chosen by: routing slip, next: Y, Z` | the step was forwarded to the next entry on the message's routing slip: `its last entry`, nothing is left on the slip after it; `next: Y, Z`, those are still on the slip, in that order. The bare `chosen by: routing slip` does not occur: a forward by the slip always takes its first entry |
| `routing slip: adds X` / `routing slip: drops X` / `routing slip: replaces X with Y` | what the step did to the routing slip it received: put `X` ahead of what it held (a gateway or entrypoint sending with a routing slip, the `RoutingSlipPrepender` of a bus, an orchestrator or orchestrator gateway returning its steps); took `X` off its front; or replaced its entries `X` with `Y` (both done by an interceptor changing the `routingSlip` header of a handler's reply, for example `#[After(changeHeaders: true)]`) |
| `kept: routing slip X` | the step passed the message on with the routing slip it received |
| `started in: a coroutine started by process N` / `starts: process M, in a coroutine` | a process that runs in a coroutine a handler started (OpenSwoole or Swoole): its first step says which process started the coroutine, and the step it is linked to says which process runs in it. That is the innermost step that was open when the coroutine started; when that step has ended by the time the coroutine first records (the handler returned before it), the nearest of its ancestors still open. A coroutine that first records after its parent's entry ended is not linked: its process has no sender |
| `runs in:` | where the step's work runs when that is not obvious from the tree: `this consumer run, gatherer called back`, `this consumer run, no queue` |
| `collected:` | the message is held by the message collector until the handler ends (`held until the handler ends`) and sent then (`released after the handler ended`), so a failing handler sends nothing |
| gathering group, part report, gatherer, Enterprise part completion | a gathering group is the set of parts one dispatch splits its work into (`open gathering group of 2 parts`); each part's run ends with a part report sent to the group's completion channel; the gatherer (`GatheringMessageHandler`) records each part as it arrives and releases the group once every part is in; the Enterprise part completion, `GatheringPartCompletion`, is what sends the part report in place of the open-core `no hand-over` |
| step words | `around X` an around interceptor; `before send X` / `after send X` / `after successful send X` a channel interceptor before a message is sent, after it is sent whatever the result, and only after it was sent; `span X` a span Ecotone opens; `direct channel X` a synchronous channel the message passes through; `forward to X` the handler's result or the message handed to the next channel; `put on channel X` the message stored on a queue channel, taken later by a consumer run; `entrypoint sends to X` a message Ecotone sends itself; `handler X` the method that handles the message; `bridge X` the bridge Ecotone puts between a consumed channel and the endpoint it calls; `orchestrator X` an orchestrator returning the steps it puts on the routing slip; `splitter X` a splitter sending each element on; `repository finds` / `repository saves` an aggregate loaded / stored; `recover X` an interceptor recovering from a failed send; `chained X` a step of a chain of handlers run one after the other |
| `@@ N steps unchanged @@` | in the traced spans of the other presentations, N spans left out because they did not change |

## What CI actually runs

| Workflow | Trigger | What it does |
|---|---|---|
| `test-monorepo.yml` | every branch push **and** pull request | A `shards` job runs `bin/check-monorepo-shards.php`, which checks that `.github/monorepo-shards.json` names every test suite of `phpunit.xml.dist` exactly once and emits the matrix; then one `Monorepo (<shard>)` job per shard: `bin/check-suite-parity.php` first, then PHP 8.5, `--prefer-stable`, `composer validate --strict`, root phpstan, then `phpunit --testsuite <the shard's suites>` against PostgreSQL with MySQL as the secondary connection. A new suite in `phpunit.xml.dist` must be added to a shard or the run fails. A `Monorepo (<OpenSwoole|Swoole> coroutines)` job is a matrix over both extensions (OpenSwoole from setup-php, Swoole compiled from source with `--enable-swoole-pgsql` and `--enable-swoole-sqlite`). Each leg runs the files listed in its `COROUTINE_TESTS` with `--fail-on-skipped --exclude-group <the other extension>`, once with ext-amqp and once with php-amqplib, and `CoroutineTracingTest` in a step of its own: add a new coroutine test file to that list |
| `split-testing.yml` | every branch push **and** pull request | Per-package `composer tests:ci` for every package with a `phpunit.xml.dist`, on PHP 8.2, PHP 8.5, and PHP 8.2 `--prefer-lowest`. Tempest is excluded — it needs PHP 8.5 and a monorepo-tied boot harness, so a Monorepo shard covers it |
| `file-licence.yml` | every push **and** pull request | `php bin/check-licence.php` over `packages/*/src`, then `php bin/check-framework-transactions.php` and `php bin/check-framework-connections.php` over the framework `src` and `Api`, and `php bin/check-flow-catalogue.php` with its tests over the flow scenario catalogue |
| `build-flow-baseline.yml` | push to `main`, except a push that changes only the baseline | `php bin/build-flow.php snapshot --location=packages/Ecotone/tests/BuildFlow/baseline` from the latest `main`; when `packages/Ecotone/tests/BuildFlow/baseline` changed, commits it as `chore(flow): the build-flow baseline after <sha>` and pushes, regenerating and retrying when `main` moved. Runs one at a time, never cancelled. It commits and pushes as the Ecotone Framework Bot with the bot token `release.yml` uses, and with its git identity. A push with that token starts the other `push` workflows on the baseline commit (`test-monorepo.yml`, `split-testing.yml`, `file-licence.yml`, `agent-skills.yml`, `quickstart-examples.yml`, and `pulse.yml` and `demo.yml`, whose `packages/**` paths include the baseline); `paths-ignore` on the baseline keeps this workflow from starting itself again. A push refused although `main` did not move means the bot's token may not push to `main` past its protection. It runs only in `ecotoneframework/ecotone-dev` (a fork has no bot token), and it waits while a `release.yml` run is in progress, before snapshotting and again before pushing, so it never pushes to `main` between a release's checkout and its `Release <tag>` push or while the release splits the packages (at most 30 minutes, then it fails and the next push to `main` regenerates) |
| `build-flow-comparison.yml` | pull request opened, pushed to or reopened | `php bin/build-flow.php compare --from=<snapshot of the base commit> --presentation=build`: the base commit is checked out beside the merge ref and snapshotted in the job, so neither a lagging baseline on `main` nor a baseline the pull request edited changes the comment (what ran, process by process, first, then the message's path as a sequence diagram, the marked wiring and traced spans collapsed under it), `php bin/build-flow-comment.php` to build the comment. Snapshotting the base commit adds a second checkout, a second `composer update` (about as long as the first, a minute or two) and one snapshot (about 8 seconds locally) to the job (over GitHub's limit it keeps whole scenarios from the top and leaves out the rest; a first scenario too large alone has the folds of its unchanged processes emptied, then its folds of unchanged steps, each summary then ending `, in the run's artifact`, and is only then cut between whole sections; never inside a fold, a quote, a fence or a diagram); uploads both as artifacts. Read-only; fails only when the tooling breaks |
| `build-flow-comment.yml` | `workflow_run` of Build Flow Comparison | posts that comment on the pull request, or updates the one it posted before (found by its marker line), also for forks; runs `main`'s file and never the pull request's code |
| `contribution-check.yml` | PR opened or edited | Fails unless the PR body has the CLA checkbox ticked |
| `quickstart-examples.yml` | every branch push **and** pull request | The quickstart applications, as their own package |
| `benchmark-pr.yml` | pull request | phpbench on PHP 8.5, for Ecotone, Laravel and Symfony |

Every one of them is `fail-fast: true`, so the first failure cancels the rest.

The four push-triggered workflows share one trigger — `push` on `branches: ['**']` with `tags-ignore: ['*.*']`,
beside `pull_request` — so a branch pushed and then opened as a pull request runs each of them twice. Each
concurrency group falls back from the PR number to `github.ref_name`, so a newer push still cancels the older run
for the same event. If the minutes bite, narrow `branches` to `['2.0', 'dgafka/ecotone-2-0-*']`.

### When a check is red

**A failed check is never rerun, and there is no list of known flaky tests.** A test that passes on a second run
has a timing assumption, an order dependency or a shared resource in it; the rerun hides it until it fires on
someone else's pull request. Read the failure, reproduce it the way the job ran it, fix the cause with a RED commit
then a GREEN one, and push:

```bash
gh pr checks <pr>                     # which job failed
gh run view <run-id> --log-failed     # its output; Split Testing prints the failing pass under "::error::KO"
```

Reproduce with the job's shape, not the default container's. The install recipes rewrite lock resolution, so like
[§ Dependency ranges](#dependency-ranges) they were not executed while writing this file:

| The job ran | Reproduce with |
|---|---|
| PHP 8.2, or PHP 8.2 `--prefer-lowest` | the isolated install below, run in `app8_2` with `composer update [--prefer-lowest]` — and mind that `app8_2` makes MySQL the primary database. Updating only the package's `vendor/` is not enough: the root `vendor/`, resolved for PHP 8.5, is still the one the annotation finder loads |
| Split Testing's MySQL or SQLite pass | the same package run with both connections swapped as the job swaps them: `DATABASE_DSN="$DATABASE_MYSQL" SECONDARY_DATABASE_DSN="$DATABASE_DSN"` (MySQL primary, PostgreSQL secondary), or `DATABASE_DSN='sqlite:////tmp/ecotone_test.db' SECONDARY_DATABASE_DSN='sqlite:////tmp/ecotone_test_b.db'` (`split-testing.yml` `_run_pass`). Changing only `DATABASE_DSN` in `app` leaves both connections on MySQL |
| Split Testing's isolated install | the job has no root `vendor/`, so the annotation finder walks up from the symlinked `packages/Ecotone` to the first `vendor/autoload.php` it finds, `packages/Ecotone/vendor`. Locally: move the root `vendor/` aside, `composer update` in `packages/Ecotone` and in the package, run its `composer tests:ci`, then delete both package `vendor/`s and move the root one back. For the package's own `vendor/` alone, set its path repositories to `"symlink": false` instead, reinstall, and `git checkout` its `composer.json` afterwards |
| a timing-sensitive test | the deadline forced down — `ExecutionPollingMetadata::createWithTestingSetup(executionTimeLimitInMilliseconds: 1)` in the test — and the suite in reverse, `vendor/bin/phpunit --no-coverage --order-by=reverse` |

An install or infrastructure failure — a dependency download, a service that was not ready, a step that raced
another — is fixed the same way, in `.github/workflows/*.yml`: the workflow is code in this repository.


---

