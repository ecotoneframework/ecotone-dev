---
name: ecotone-contributor
description: >-
  Guides Ecotone framework contributions: dev environment setup, monorepo
  navigation, running tests, PR workflow, and package split mechanics.
  Use when setting up development environment, preparing PRs, validating
  changes, running tests across packages, or understanding the monorepo
  structure.
argument-hint: "[package-name]"
---

# Ecotone Contributor Guide

Tests use inline anonymous classes with PHP 8.1+ attributes, snake_case method names, and high-level behavioral assertions. Use this skill when writing or debugging any Ecotone test.

## 1. Dev Environment Setup

Start the Docker Compose stack:

```bash
docker-compose up -d
```

Enter the main container:

```bash
docker exec -it ecotone_development /bin/bash
```

PHP 8.2 container (for compatibility testing):

```bash
docker exec -it ecotone_development_8_2 /bin/bash
```

## 2. Monorepo Structure

```
packages/
├── Ecotone/           # Core package -- foundation for all others
├── Amqp/              # RabbitMQ integration
├── Dbal/              # Database abstraction (DBAL)
├── PdoEventSourcing/  # Event sourcing with PDO
├── Laravel/           # Laravel framework integration
├── Symfony/           # Symfony framework integration
├── Sqs/               # AWS SQS integration
├── Redis/             # Redis integration
├── Kafka/             # Kafka integration
├── OpenTelemetry/     # Tracing / OpenTelemetry
└── ...
```

- Each `packages/<PackageName>` is a separate Composer package, split to read-only repos on release
- The Core package is the dependency for all other packages
- Changes to Core can propagate to all downstream packages

## 3. PR Validation Workflow

Run these steps **in order** before submitting a PR:

### Step 1: Run changed tests first (fastest feedback)

```bash
docker compose up -d
```

```bash
docker exec -t ecotone_development vendor/bin/phpunit --filter test_method_name
```

### Step 2: Run full test suite for affected package

```bash
cd packages/<PackageName> && composer tests:ci
```

### Step 3: Verify licence headers on all new PHP files

Every PHP file must have a licence comment:

```php
/**
 * licence Apache-2.0
 */
```

Enterprise files use:

```php
/**
 * licence Enterprise
 */
```

### Step 4: Fix code style

```bash
vendor/bin/php-cs-fixer fix
```

### Step 5: Verify PHPStan

```bash
vendor/bin/phpstan analyse
```

### Step 6: Check conventions

- `snake_case` test method names (enforced by PHP-CS-Fixer)
- No comments in production code -- use descriptive method names
- PHPDoc `@param`/`@return` on public API methods
- Single quotes, trailing commas in multiline arrays
- `! $var` spacing (not `!$var`)

### Step 7: PR description

- **Why**: What problem does this solve?
- **What**: What changes were made?
- CLA checkbox signed

## 4. Code Conventions

| Rule | Example |
|------|---------|
| No comments | Use meaningful private method names instead |
| PHP 8.1+ features | Attributes, enums, named arguments, readonly |
| snake_case tests | `public function test_it_handles_command()` |
| Single quotes | `'string'` not `"string"` |
| Trailing commas | In multiline arrays, parameters |
| Not operator spacing | `! $var` not `!$var` |
| PHPDoc on public APIs | `@param`/`@return` with types |
| Licence headers | On every PHP file |

## 5. Package Split and Dependencies

- The monorepo uses `symplify/monorepo-builder` for managing splits
- Each package has its own `composer.json` with real dependencies
- Changes to the Core package can affect ALL downstream packages -- run their tests too
- Cross-package changes need tests in both packages

## Key Rules

- Always run tests inside the Docker container
- Never skip licence headers on new files
- Run `php-cs-fixer fix` before committing
- Test methods MUST use `snake_case`
- No comments -- code should be self-documenting via method names
- Use inline anonymous classes, and snake_case methods. Covers handler testing,

## Additional resources

- [CI checklist](references/ci-checklist.md) -- Full CI command reference including per-package Composer test scripts, Docker container commands, running individual tests by method/class/directory, PHPStan configuration, PHP-CS-Fixer rules, Behat test commands, database DSNs for all supported databases inside Docker, dependency testing (lowest/highest), and the complete pre-PR checklist with all validation steps. Load when preparing a PR, running the full test suite, or need exact test commands and database connection strings.
- [Licence format](references/licence-format.md) -- Licence header template and formatting requirements for new PHP files, covering both Apache-2.0 (open source) and Enterprise licence formats with real codebase examples and placement rules. Load when creating new PHP source files that need the licence header.


## Test Structure Rules

- **`snake_case`** method names (enforced by PHP-CS-Fixer)
- **High-level tests** from end-user perspective -- never test internals
- **Inline anonymous classes** with PHP 8.1+ attributes -- not separate fixture files
- **No comments** -- descriptive method names only
- **Licence header** on every test file

```php
/**
 * licence Apache-2.0
 */
final class OrderTest extends TestCase
{
    public function test_placing_order_records_event(): void
    {
        // test body
    }
}
```