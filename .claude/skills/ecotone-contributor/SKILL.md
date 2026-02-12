---
name: ecotone-contributor
description: >-
  Guides Ecotone framework contributions: dev environment setup, monorepo
  navigation, running tests, PR workflow, and package split mechanics.
  Use when setting up development, preparing PRs, validating changes,
  or understanding the monorepo structure.
disable-model-invocation: true
argument-hint: "[package-name]"
---

# Ecotone Contributor Guide

## Current State

- Branch: !`git branch --show-current`
- Modified files: !`git diff --name-only`
- Staged: !`git diff --cached --name-only`

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

### Database DSNs (inside container)

| Database   | DSN                                                              |
|------------|------------------------------------------------------------------|
| PostgreSQL | `pgsql://ecotone:secret@database:5432/ecotone?serverVersion=16` |
| MySQL      | `mysql://ecotone:secret@database-mysql:3306/ecotone?serverVersion=8.0` |
| SQLite     | `sqlite:////tmp/ecotone_test.db`                                 |
| RabbitMQ   | `amqp://rabbitmq:5672`                                           |
| Redis      | `redis://redis:6379`                                             |
| SQS        | `sqs:?key=key&secret=secret&region=us-east-1&endpoint=http://localstack:4566&version=latest` |

## 2. Monorepo Structure

```
packages/
├── Ecotone/           # Core package — foundation for all others
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
_PackageTemplate/      # Template for new packages
```

- Each `packages/*` is a separate Composer package, split to read-only repos on release
- Core (`packages/Ecotone`) is the dependency for all other packages
- Changes to core propagate to all downstream packages

## 3. PR Validation Workflow

Run these steps **in order** before submitting a PR:

### Step 1: Run changed tests first (fastest feedback)

```bash
vendor/bin/phpunit --filter test_method_name
```

### Step 2: Run full test suite for affected package

```bash
cd packages/PackageName && composer tests:ci
```

This runs PHPStan + PHPUnit + Behat in sequence. Per-package scripts:

```json
{
  "tests:phpstan": "vendor/bin/phpstan",
  "tests:phpunit": "vendor/bin/phpunit --no-coverage",
  "tests:behat": "vendor/bin/behat -vvv",
  "tests:ci": ["@tests:phpstan", "@tests:phpunit", "@tests:behat"]
}
```

### Step 3: Verify licence headers on all new PHP files

Every PHP file must have a licence comment after the class/interface docblock:

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

See `references/licence-format.md` for full details.

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
- No comments in production code — use descriptive method names
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

### PHP-CS-Fixer Rules (from `.php-cs-fixer.dist.php`)

- `@PSR12` + `@PSR12:risky`
- `@PHP80Migration`
- `php_unit_method_casing` → `snake_case`
- `not_operator_with_successor_space` → `! $x`
- `single_quote`, `trailing_comma_in_multiline`
- `no_unused_imports`, `ordered_imports`
- `fully_qualified_strict_types`, `global_namespace_import`

## 5. Package Split and Dependencies

- Monorepo uses `symplify/monorepo-builder` for managing splits
- Each package has its own `composer.json` with real dependencies
- Test both lowest and highest dependencies:

```bash
composer update --prefer-lowest
composer tests:ci

composer update
composer tests:ci
```

- Changes to `packages/Ecotone/` can affect ALL downstream packages — run their tests too
- Cross-package changes need tests in both packages

## Key Rules

- Always run tests inside the Docker container
- Never skip licence headers on new files
- Run `php-cs-fixer fix` before committing
- Test methods MUST use `snake_case`
- No comments — code should be self-documenting via method names
- Check `references/ci-checklist.md` for the full CI command reference
