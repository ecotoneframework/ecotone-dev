# Ecotone dev workflow

The exact commands. [AGENTS.md](../AGENTS.md) is the guide and summarizes what is here;
[docs/coding-conventions.md](./coding-conventions.md) holds the rules. This file holds only the mechanics: which
container, which command, which environment variable.

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

# One file
docker compose exec -T app vendor/bin/phpunit --no-coverage packages/Ecotone/tests/Modelling/AggregateBoundary/WithoutOptimisticLockTest.php

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

### What `composer tests:ci` runs

It is not the same script in every package. Four of them do something extra:

| Package | `tests:ci` |
|---|---|
| Amqp | phpstan, then phpunit **twice** — `AMQP_IMPLEMENTATION=ext`, then `AMQP_IMPLEMENTATION=lib` |
| DataProtection | phpstan, then `tests/before-tests.sh` (generates a 200 MB fixture), then phpunit |
| Symfony | phpstan, phpunit, then `bin/console ecotone:list` as a smoke test |
| all others | phpstan, then phpunit |
| **monorepo root** | phpstan, `packages/DataProtection/tests/before-tests.sh`, phpunit, then `quickstart-examples` |

`composer tests:local` at the root is the same as the root `tests:ci` without the quickstart examples.

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
| `SQLITE_DATABASE_DSN` | `sqlite:////tmp/ecotone_test.db` |
| `RABBIT_HOST` | `amqp://rabbitmq:5672` |
| `SQS_DSN` | `sqs:?key=key&secret=secret&region=us-east-1&endpoint=http://localstack:4566&version=latest` |
| `REDIS_DSN` | `redis://redis:6379` |
| `KAFKA_DSN` | `kafka:9092` |

In `app8_2`, `DATABASE_DSN` and `SECONDARY_DATABASE_DSN` are swapped: MySQL primary, PostgreSQL secondary.

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
# phpstan — level 1. The bare form is what composer tests:phpstan runs; `analyse` is its default subcommand
docker compose exec -T app vendor/bin/phpstan
docker compose exec -T app bash -lc "cd packages/Dbal && vendor/bin/phpstan"

# Licence headers. check-licence.php is silent and exits 0 when every file has one
docker compose exec -T app php bin/check-licence.php
docker compose exec -T app php bin/add-apache-licence.php      # open source
docker compose exec -T app php bin/add-enterprise-licence.php  # Enterprise modules
```

`phpstan.neon` is level 1 over `src` for Ecotone, Enqueue, Dbal, Amqp, JmsConverter, PdoEventSourcing, Laravel,
OpenTelemetry, Kafka, plus `packages/Symfony/DependencyInjection` and `Monorepo`. It does **not** cover `Api/`,
`tests/`, or the Tempest, Redis, Sqs and DataProtection packages, and it cannot catch a wrong class name in an
attribute argument, because attribute arguments resolve lazily through reflection. A green phpstan is not evidence
that anything works.

```bash
# php-cs-fixer — ON THE HOST, never in the container. git is unavailable there, so the
# fixer loses its file filter and rewrites the whole repository.
vendor/bin/php-cs-fixer fix
vendor/bin/php-cs-fixer fix --dry-run --diff
```

Config is `.php-cs-fixer.dist.php`: `@PSR12`, `@PSR12:risky` and `@PHP80Migration` over `packages/` only, plus the
house rules listed in [conventions rule 20](./coding-conventions.md#20-mechanics-php-cs-fixer-enforces). It does
not add licence headers.

---

## What CI actually runs

| Workflow | Trigger | What it does |
|---|---|---|
| `test-monorepo.yml` | pull request | One unified job: PHP 8.5, `--prefer-stable`, `composer validate --strict`, root phpstan, then phpunit against PostgreSQL with MySQL as the secondary connection |
| `split-testing.yml` | pull request | Per-package `composer tests:ci` for every package with a `phpunit.xml.dist`, on PHP 8.2, PHP 8.5, and PHP 8.2 `--prefer-lowest`. Tempest is excluded — it needs PHP 8.5 and a monorepo-tied boot harness, so the unified job covers it |
| `file-licence.yml` | every push **and** pull request | `php bin/check-licence.php` over `packages/*/src` |
| `contribution-check.yml` | PR opened or edited | Fails unless the PR body has the CLA checkbox ticked |
| `quickstart-examples.yml` | pull request | The quickstart applications, as their own package |
| `benchmark-pr.yml` | pull request | phpbench on PHP 8.5, for Ecotone, Laravel and Symfony |

Every one of them is `fail-fast: true`, so the first failure cancels the rest.

---

## Before opening a pull request

1. The new or changed test passes: `vendor/bin/phpunit --no-coverage --filter test_name`
2. The changed package's full CI passes: `cd packages/<PackageName> && composer install && composer tests:ci`
3. A Core change also runs the packages downstream of it — everything depends on `packages/Ecotone`
4. `php bin/check-licence.php` is clean
5. `vendor/bin/php-cs-fixer fix` on the host, with nothing outside your change rewritten
6. `vendor/bin/phpstan` is clean
7. Anything touching event sourcing, the event store or DBAL ran on MySQL and MariaDB too
8. The 8.2 floor still holds, if the change could care: `docker compose exec app8_2 ...`
9. `Monorepo/*/Symfony/config/reference.php` is not in the diff — those drift on their own
