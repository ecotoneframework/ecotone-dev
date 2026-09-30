# Ecotone Framework - AI Agent Guidelines

> Guidelines for AI agents contributing to or working with the Ecotone framework codebase.

## Quick Start

```bash
# Bootstrap a fresh checkout — two commands, no .env to create, no flags to remember
docker compose up -d
docker compose exec app composer install

# Run one package's full test suite
docker compose exec app bash -lc "cd packages/PackageName && composer install && composer tests:ci"
```

See [Development Environment](#development-environment) and [Running Tests](#running-tests) below for details, and `docs/dev-environment-cold-start-findings.md` for the full list of gaps a fresh checkout used to hit.

**Before writing code, read [docs/coding-conventions.md](./docs/coding-conventions.md)** — the rules are summarized
under [Code Conventions](#code-conventions) below and stated in full there.

## Project Overview

Ecotone is the enterprise architecture layer for Laravel and Symfony.
One Composer package adds CQRS, Event Sourcing, Sagas, Projections, Workflows, and Outbox messaging via declarative PHP 8 attributes.
Works with Symfony, Laravel, or standalone via Ecotone Lite (any PSR-11 container).

## Monorepo Structure

- Core package: `packages/Ecotone` - foundation for all other packages
- Each package under `packages/*` is a separate Composer package
- Packages are split to read-only repos during release
- Template for new packages: `_PackageTemplate/`

## Code Conventions

**Read [docs/coding-conventions.md](./docs/coding-conventions.md) before writing code.** It is the single source
for the rules below, each with the reasoning, a wrong/right pair where the rule is easy to misread, and the file or
commit that evidences it. The summary:

| # | Rule | Why |
|---|---|---|
| 1 | **Exceptions drive the solution** — name what was wrong, why it cannot work, and *every* way out (the exact API call, attribute, option or command). Assert the message in a test | The message is what the agent or developer reacts to; it is the deliverable |
| 1a | A configuration mistake is **refused at bootstrap**, never at runtime, and never read as an empty result | Fails on the first test run instead of on a production message |
| 2 | **Never take a nullable service dependency.** Register unconditionally, decide at runtime. Split the class, add a null-object factory, make the parameter required, or pick between two services in the container | A `?Service = null` is a mode switch in disguise, and the two modes drift |
| 3 | **Constructor-injected services are stateless** — no accumulating properties, no static caches, no per-message collectors. Mutable state goes in a function-scoped immutable value object carried on a message header | A container service is a singleton; anything it remembers leaks into the next message, transaction and retry |
| 4 | **Do not mix queries with writes.** A read never runs DDL, a mutator returns `void`, and the name says which it is | `getRecordedEvents()` cleared its buffer and became `popRecordedEvents()` for exactly this reason |
| 5 | **No interface with a single implementation.** An interface marks a real seam: open core vs Enterprise, or in-memory vs storage-backed | Otherwise it is a dispatch hop with no choice behind it |
| 6 | **Names carry the meaning. No comments, no descriptive docblocks.** Array shapes, generics and `@link https://docs.ecotone.tech/...` are the only allowed docblocks. One word per concept, through the whole path | A comment drifts from the code beside it; a name and an exception message cannot |
| 7 | **Enterprise features live in separate files** with `licence Enterprise`, chosen in the container via `LicenceDecider`. Open-source files carry `licence Apache-2.0`. Open-core behaviour stays byte-for-byte unchanged | No `if ($hasLicence)` branches to keep in step |
| 8 | **Always use optimistic locking** — a version check on the write, never a pessimistic lock or a bespoke tracker | Pessimistic locking and snapshot tracking were both tried and removed |
| 9 | **A feature needing external storage ships an in-memory implementation**, named to mirror the storage one | Otherwise the feature cannot be used in an `EcotoneLite` flow test |
| 10 | **Tests validate at the userland level only.** No SQL on Ecotone's tables, no internal service references, no reflection, no statement counting. Fetch a **gateway** and assert on observable behaviour | Fifteen commits in one feature did nothing but pull tests back to the public surface |
| 11 | **`EcotoneLite`, `snake_case`, no comments, fixtures in the test file.** Test-first: RED, GREEN, refactor | |
| 12 | **The public surface is `Api/`, a sibling of `src/`** — never `src/Api/`, which breaks Tempest discovery. Everything outside `Api` is `@internal` | |
| 13 | **Configuration is attributes plus `#[ServiceContext]`**, compiled into a container via `DefinedObject`/`Definition`. No YAML, no XML | |
| 14 | **A console option's name is its PHP parameter name verbatim** — camelCase, never kebab-case | |
| 15 | **Orchestrating methods read as step lists**; SQL belongs to the collaborator that owns the table | |
| 16 | **DDL never runs inside a message transaction** | MySQL/MariaDB implicitly commit on DDL |
| 17 | New files are `final` and `declare(strict_types=1)` | |

**Verify every attribute, parameter, method and console option against the current tree before writing it.** The
API moved in 2.0 (`Ecotone\Api\Attribute\CommandHandler`, not the old flat namespace), so recall is unreliable.

## Architecture Patterns

- **Messages first** - Commands, Events, Queries are first-class citizens
- **Declarative configuration** - PHP attributes and `#[ServiceContext]`, never YAML/XML
- **`#[InternalHandler]`** - for a framework-internal message handler (`#[ServiceActivator]` was removed in 2.0)
- **`InterfaceToCall`** - for reflection and method metadata
- **`MessageHeaders`** - for message metadata propagation
- **`Definition` / `DefinedObject`** - configuration is compiled into a container, so what a module registers must
  be expressible as a `Definition`
- **Modules** - implement `AnnotationModule`, carry `#[ModuleAnnotation]`, and are registered **explicitly**: the
  module class in the right `ModuleClassList` constant, and a new package's name in `ModulePackageList`

## Testing Guidelines

### General Approach
- Write the test first: RED, then GREEN, then refactor, one increment per commit
- Tests use **`EcotoneLite::bootstrapFlowTesting()`** to bootstrap isolated Ecotone instances
- **Assert only on what the application observes.** No SQL against Ecotone's own tables, no reflection, no
  statement counting, no internal service references — fetch a gateway (`$ecotone->getGateway(EventStore::class)`)
  and drive the public API. This is the most-corrected rule in the repository; see conventions rule 10
- **Fixtures live in the test file**: an anonymous class when it only holds handler methods, named classes below
  the `TestCase` when the class name is part of what is under test (aggregates, events, commands). Not a shared
  `Fixture/` directory
- `snake_case` method names, named after the behaviour rather than the class that implements it
- Run tests for the specific package you modified, **one package at a time, never in parallel** — the packages
  share the compose services and a parallel run produces false failures

### Running Tests
```bash
# Enter development container
docker compose exec app /bin/bash

# Run package tests — each package has its own vendor/, install it first
cd packages/PackageName
composer install
composer tests:ci

# Run specific test — --no-coverage, or PHPUnit 12's coverage requirement trips the run
vendor/bin/phpunit --no-coverage --filter test_method_name tests/Path/To/TestFile.php
```

Only use `-u root` for things that genuinely need elevated OS-level access. Running
`composer install`/`composer tests:*` as root leaves the files it writes (`vendor/`, phpunit's
cache) owned by root on the host, which then blocks the default non-root user (and your editor)
from writing to them.

The root `vendor/` (installed by the `composer install` above) is separate from each package's own `vendor/`.
Ecotone's annotation finder always loads the monorepo root `vendor/autoload.php`, so keep the
root install in sync even when you only intend to run one package's tests.

### Database-Specific Tests

The container exports a DSN per engine; override `DATABASE_DSN` to run a suite against another one. A change to
event sourcing, the event store or DBAL needs all three, because their DDL and locking behaviour differ.

```bash
# PostgreSQL (the default DATABASE_DSN in the container)
vendor/bin/phpunit packages/PdoEventSourcing/tests/

# MySQL
DATABASE_DSN="$DATABASE_MYSQL" vendor/bin/phpunit packages/PdoEventSourcing/tests/

# MariaDB
DATABASE_DSN="$DATABASE_MARIADB" vendor/bin/phpunit packages/PdoEventSourcing/tests/
```

Also available: `SQLITE_DATABASE_DSN`, `SECONDARY_DATABASE_DSN` (a genuinely separate database, for
two-connection tests), `SQS_DSN`, `REDIS_DSN`, `KAFKA_DSN`.

### Test Types
- `composer tests:phpunit` - Unit/integration tests
- `composer tests:behat` - BDD feature tests
- `composer tests:phpstan` - Static analysis. **Level 1, over `packages/*/src` only** — not `Api/`, not `tests/`,
  and not the `Tempest`, `Redis`, `Sqs` or `DataProtection` packages. It will not catch a wrong class name in an
  attribute argument, because attribute arguments resolve lazily via reflection. A green phpstan is not evidence
- `composer tests:ci` - phpstan, then `packages/DataProtection/tests/before-tests.sh` (it generates a 200 MB
  fixture), then phpunit, then the quickstart examples

### Licence headers and code style
```bash
# Every PHP file under packages/*/src and packages/*/Api needs a licence docblock.
# CI enforces it on every push and pull request (.github/workflows/file-licence.yml).
php bin/check-licence.php
php bin/add-apache-licence.php      # open source
php bin/add-enterprise-licence.php  # Enterprise modules

# Run php-cs-fixer ON THE HOST, never inside the container: git is unavailable
# there, so the fixer loses its file filter and rewrites the whole repository.
vendor/bin/php-cs-fixer fix
```

## Common Patterns

Every user-facing class lives under `Ecotone\Api`. The imports are part of the pattern — the namespaces moved in
2.0, so check them against the tree rather than from memory (`upgrade/namespace-map-2.0.csv` has the full mapping).

### Handlers
```php
use Ecotone\Api\Attribute\Asynchronous;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\EventHandler;
use Ecotone\Api\Attribute\QueryHandler;

final class OrderService
{
    #[CommandHandler]
    public function place(PlaceOrder $command): void
    {
    }

    #[EventHandler]
    public function whenPlaced(OrderPlaced $event): void
    {
    }

    #[Asynchronous('orders')]
    #[EventHandler]
    public function notifyCustomer(OrderPlaced $event): void
    {
    }

    #[QueryHandler('order.status')]
    public function status(string $orderId): string
    {
        return $this->statuses[$orderId];
    }
}
```

### Aggregate
```php
use Ecotone\Api\Attribute\Aggregate;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\Identifier;

#[Aggregate]
final class Order
{
    private function __construct(#[Identifier] private string $orderId)
    {
    }

    #[CommandHandler]
    public static function place(PlaceOrder $command): self
    {
        return new self($command->orderId);
    }
}
```

### Programmatic configuration
```php
use Ecotone\Api\Attribute\ServiceContext;
use Ecotone\Api\Dbal\ExtensionObject\DbalConfiguration;

final class EcotoneConfiguration
{
    #[ServiceContext]
    public function dbal(): DbalConfiguration
    {
        return DbalConfiguration::createWithDefaults()->withTransactionOnCommandBus(true);
    }
}
```

## Documentation Resources

In this repository:

- [docs/coding-conventions.md](./docs/coding-conventions.md) - the conventions summarized above, in full
- `upgrade-2.0.md` - every 1.x → 2.0 behaviour change, with Before / Now / How to adapt
- `upgrade/namespace-map-2.0.csv` - the full old → new FQCN mapping for the 2.0 `Api` move
- `docs/superpowers/specs/` - design decisions, the alternatives considered, and why. Tracked, but
  `docs/superpowers/` is in `.gitignore`, so a new one needs `git add -f`
- `.claude/skills/ecotone-*` - per-area guides; `ecotone-contributor` for the dev loop, `ecotone-testing` for test
  patterns, `ecotone-module-creator` for new modules and packages

Published:

- [Full Documentation](https://docs.ecotone.tech)
- [Testing Support](https://docs.ecotone.tech/modelling/testing-support)
- [Contributing Guide](https://docs.ecotone.tech/messaging/contributing-to-ecotone)
- [Blog & Examples](https://blog.ecotone.tech)

## Committing

- Conventional subjects, scoped: `feat(dcb):`, `fix(event-sourcing):`, `refactor(modelling):`, `test(dbal):`,
  `docs(2.0):`, `style(dcb):`. One increment per commit
- Every commit carries an 08:00 timestamp:
  ```bash
  GIT_AUTHOR_DATE="$(date +%Y-%m-%d) 08:00:00" GIT_COMMITTER_DATE="$(date +%Y-%m-%d) 08:00:00" git commit ...
  ```
- Never commit `Monorepo/*/Symfony/config/reference.php` - they drift on their own

## Development Environment

```bash
# Start all containers — waits for every dependency's healthcheck before app/app8_2 start
docker compose up -d

# Install root dependencies
docker compose exec app composer install

# Enter dev container
docker compose exec app /bin/bash

# Verify lowest/highest dependencies
composer update --prefer-lowest && vendor/bin/phpunit
composer update --prefer-stable && vendor/bin/phpunit
```

`docker compose up -d` builds a local image with `ext-sockets` baked in for the `app` service the
first time it runs — the published `simplycodedsoftware/php:8.5.3` image doesn't have it — and
blocks until every database/broker dependency reports healthy, so there is nothing to wait for
manually afterwards. `.env` is optional: it is only read if present (`env_file: required: false`),
so nothing needs to be created for a fresh checkout; copy `.env.dist` to `.env` only if you want to
override a default (e.g. turn Xdebug on).

