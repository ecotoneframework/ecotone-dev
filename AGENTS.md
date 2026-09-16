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

- **No comments** - prefer meaningful private methods that describe intent
- Use PHP 8.1+ features (attributes, enums, named arguments)
- All public APIs need `@param`/`@return` PHPDoc
- Follow existing patterns in the codebase

## Architecture Patterns

- **Messages first** - Commands, Events, Queries are first-class citizens
- **Declarative configuration** - use PHP attributes, not YAML/XML
- **ServiceActivatorBuilder** - for registering message handlers
- **InterfaceToCall** - for reflection and method metadata
- **MessageHeaders** - for message metadata propagation
- **Modules** - self-register via `ModulePackageList`

## Testing Guidelines

### General Approach
- Write high-level tests from end-user perspective
- Tests use **`EcotoneLite::bootstrapFlowTesting`** to bootstrap isolated Ecotone instances
- Prefer **inline anonymous classes** in tests over separate fixture files
- Run tests for the specific package you modified

### Running Tests
```bash
# Enter development container
docker compose exec app /bin/bash

# Run package tests — each package has its own vendor/, install it first
cd packages/PackageName
composer install
composer tests:ci

# Run specific test
vendor/bin/phpunit --filter testMethodName tests/Path/To/TestFile.php
```

Only use `-u root` for things that genuinely need elevated OS-level access. Running
`composer install`/`composer tests:*` as root leaves the files it writes (`vendor/`, phpunit's
cache) owned by root on the host, which then blocks the default non-root user (and your editor)
from writing to them.

The root `vendor/` (installed by the `composer install` above) is separate from each package's own `vendor/`.
Ecotone's annotation finder always loads the monorepo root `vendor/autoload.php`, so keep the
root install in sync even when you only intend to run one package's tests.

### Database-Specific Tests
```bash
# MySQL for PdoEventSourcing
DATABASE_DSN=mysql://ecotone:secret@database-mysql:3306/ecotone?serverVersion=8.0 \
  vendor/bin/phpunit packages/PdoEventSourcing/tests/

# PostgreSQL (default in container)
vendor/bin/phpunit packages/PdoEventSourcing/tests/
```

### Test Types
- `composer tests:phpunit` - Unit/integration tests
- `composer tests:behat` - BDD feature tests
- `composer tests:phpstan` - Static analysis
- `composer tests:ci` - All tests for CI

## Common Patterns

### Command Handler
```php
#[CommandHandler]
public function handle(PlaceOrder $command): void
{
    // Business logic
}
```

### Event Handler
```php
#[EventHandler]
public function when(OrderPlaced $event): void
{
    // React to event
}
```

### Async Handler
```php
#[Asynchronous('orders')]
#[EventHandler]
public function whenAsync(OrderPlaced $event): void
{
    // Processed asynchronously
}
```

### Aggregate
```php
#[Aggregate]
class Order
{
    #[Identifier]
    private string $orderId;
    
    #[CommandHandler]
    public static function place(PlaceOrder $command): self
    {
        return new self($command->orderId);
    }
}
```

## Documentation Resources

- [Full Documentation](https://docs.ecotone.tech)
- [Testing Support](https://docs.ecotone.tech/modelling/testing-support)
- [Contributing Guide](https://docs.ecotone.tech/messaging/contributing-to-ecotone)
- [Blog & Examples](https://blog.ecotone.tech)

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

