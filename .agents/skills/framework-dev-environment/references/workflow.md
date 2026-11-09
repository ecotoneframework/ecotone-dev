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
```

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

## What CI actually runs

| Workflow | Trigger | What it does |
|---|---|---|
| `test-monorepo.yml` | every branch push **and** pull request | A `shards` job runs `bin/check-monorepo-shards.php`, which checks that `.github/monorepo-shards.json` names every test suite of `phpunit.xml.dist` exactly once and emits the matrix; then one `Monorepo (<shard>)` job per shard: `bin/check-suite-parity.php` first, then PHP 8.5, `--prefer-stable`, `composer validate --strict`, root phpstan, then `phpunit --testsuite <the shard's suites>` against PostgreSQL with MySQL as the secondary connection. A new suite in `phpunit.xml.dist` must be added to a shard or the run fails |
| `split-testing.yml` | every branch push **and** pull request | Per-package `composer tests:ci` for every package with a `phpunit.xml.dist`, on PHP 8.2, PHP 8.5, and PHP 8.2 `--prefer-lowest`. Tempest is excluded — it needs PHP 8.5 and a monorepo-tied boot harness, so a Monorepo shard covers it |
| `file-licence.yml` | every push **and** pull request | `php bin/check-licence.php` over `packages/*/src` |
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

