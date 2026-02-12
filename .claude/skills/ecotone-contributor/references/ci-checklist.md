# CI Checklist Reference

## Per-Package CI Commands

Every package has these Composer scripts:

```json
{
  "tests:phpstan": "vendor/bin/phpstan",
  "tests:phpunit": "vendor/bin/phpunit --no-coverage",
  "tests:behat": "vendor/bin/behat -vvv",
  "tests:ci": ["@tests:phpstan", "@tests:phpunit", "@tests:behat"]
}
```

### Running tests for a specific package

```bash
# Enter container
docker exec -it ecotone_development /bin/bash

# Run full CI for a package (replace <PackageName> with the actual package)
cd packages/<PackageName> && composer tests:ci

# Examples:
# cd packages/Ecotone && composer tests:ci
# cd packages/Dbal && composer tests:ci
```

### Running individual test methods

```bash
# Single test method (fastest feedback)
vendor/bin/phpunit --filter test_method_name

# Single test class
vendor/bin/phpunit --filter ClassName

# Tests in a specific directory
vendor/bin/phpunit packages/<PackageName>/tests/<Directory>
```

## PHPStan Configuration

PHPStan runs at level 1 across all packages. Config in `phpstan.neon`:

```bash
# Run from project root
vendor/bin/phpstan analyse

# Run for specific package
cd packages/<PackageName> && vendor/bin/phpstan
```

## PHP-CS-Fixer

```bash
# Fix all files
vendor/bin/php-cs-fixer fix

# Dry run (check only)
vendor/bin/php-cs-fixer fix --dry-run --diff
```

Key rules enforced:
- `@PSR12` coding standard
- `snake_case` test method names
- Single quotes for strings
- Trailing commas in multiline constructs
- `! $var` spacing (not operator with successor space)
- No unused imports
- Ordered imports
- Fully qualified strict types with global imports

## Behat Tests

Some packages have Behat integration tests:

```bash
cd packages/<PackageName> && vendor/bin/behat -vvv
```

## Database DSNs (Inside Docker Container)

| Variable | Value |
|----------|-------|
| `DATABASE_DSN` | `pgsql://ecotone:secret@database:5432/ecotone?serverVersion=16` |
| `SECONDARY_DATABASE_DSN` | `mysql://ecotone:secret@database-mysql:3306/ecotone?serverVersion=8.0` |
| `DATABASE_MYSQL` | `mysql://ecotone:secret@database-mysql:3306/ecotone?serverVersion=8.0` |
| `SQLITE_DATABASE_DSN` | `sqlite:////tmp/ecotone_test.db` |
| `RABBIT_HOST` | `amqp://rabbitmq:5672` |
| `SQS_DSN` | `sqs:?key=key&secret=secret&region=us-east-1&endpoint=http://localstack:4566&version=latest` |
| `REDIS_DSN` | `redis://redis:6379` |
| `KAFKA_DSN` | `kafka:9092` |

## Dependency Testing

```bash
# Test with lowest dependencies
composer update --prefer-lowest
composer tests:ci

# Test with highest dependencies
composer update
composer tests:ci
```

## Pre-PR Checklist

1. [ ] New/changed tests pass: `vendor/bin/phpunit --filter testName`
2. [ ] Full package CI passes: `cd packages/<PackageName> && composer tests:ci`
3. [ ] Licence headers on all new PHP files
4. [ ] Code style fixed: `vendor/bin/php-cs-fixer fix`
5. [ ] PHPStan passes: `vendor/bin/phpstan analyse`
6. [ ] Test methods use `snake_case`
7. [ ] No comments in production code
8. [ ] PHPDoc on new public API methods
9. [ ] PR description with Why/What/CLA
