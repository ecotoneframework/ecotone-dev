# Ecotone Framework - AI Agent Guidelines

> Guidelines for AI agents contributing to or working with the Ecotone framework codebase.

## Project Overview

Ecotone is a PHP framework for message-driven architecture with DDD, CQRS, and Event Sourcing.
Works with Symfony, Laravel, or standalone (Ecotone Lite).

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
- Tests use **EcotoneLite** to bootstrap isolated Ecotone instances
- Prefer **inline anonymous classes** in tests over separate fixture files
- Run tests for the specific package you modified

### Running Tests
```bash
# Enter development container
docker exec -it -u root ecotone_development /bin/bash

# Run package tests
cd packages/PackageName
composer tests:ci

# Run specific test
vendor/bin/phpunit --filter testMethodName tests/Path/To/TestFile.php
```

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
# Start all containers
docker-compose up -d

# Enter dev container (use root for full access)
docker exec -it -u root ecotone_development /bin/bash

# Verify lowest/highest dependencies
composer update --prefer-lowest && vendor/bin/phpunit
composer update --prefer-stable && vendor/bin/phpunit
```

