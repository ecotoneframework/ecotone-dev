# Testing Both AMQP Implementations

This document explains how the AMQP package is configured to test both AMQP Extension and AMQP Lib implementations.

## Overview

The AMQP package supports two implementations:
- **AMQP Extension** (`enqueue/amqp-ext` + `ext-amqp`) - Default, recommended for production
- **AMQP Lib** (`enqueue/amqp-lib`) - Pure PHP, required for RabbitMQ Streams

To ensure compatibility with both, the test suite runs against both implementations automatically.

## Composer Scripts Configuration

The `composer.json` file defines the following test scripts:

```json
{
  "scripts": {
    "tests:phpstan": "vendor/bin/phpstan",
    "tests:phpunit": "vendor/bin/phpunit --no-coverage",
    "tests:phpunit:amqp-ext": "AMQP_IMPLEMENTATION=ext vendor/bin/phpunit --no-coverage",
    "tests:phpunit:amqp-lib": "AMQP_IMPLEMENTATION=lib vendor/bin/phpunit --no-coverage",
    "tests:ci": [
      "@tests:phpstan",
      "@tests:phpunit:amqp-ext",
      "@tests:phpunit:amqp-lib"
    ]
  }
}
```

## Running Tests

### Run All Tests (Recommended)

```bash
cd packages/Amqp
composer tests:ci
```

This will:
1. Run PHPStan static analysis
2. Run PHPUnit tests with AMQP Extension
3. Run PHPUnit tests with AMQP Lib

### Run Tests for Specific Implementation

```bash
# AMQP Extension only
composer tests:phpunit:amqp-ext

# AMQP Lib only
composer tests:phpunit:amqp-lib
```

### Manual Testing with Environment Variable

```bash
# AMQP Extension
AMQP_IMPLEMENTATION=ext vendor/bin/phpunit

# AMQP Lib
AMQP_IMPLEMENTATION=lib vendor/bin/phpunit
```

## How It Works

### Test Case Configuration

The `AmqpMessagingTestCase` class checks the `AMQP_IMPLEMENTATION` environment variable:

```php
public static function getRabbitConnectionFactory(array $config = []): AmqpConnectionFactory
{
    $dsn = ['dsn' => getenv('RABBIT_HOST') ?: 'amqp://guest:guest@localhost:5672/%2f'];
    $config = array_merge($dsn, $config);
    
    // Use AMQP_IMPLEMENTATION env var to choose between ext and lib
    // Default to ext for backward compatibility
    $implementation = getenv('AMQP_IMPLEMENTATION') ?: 'ext';
    
    if ($implementation === 'lib') {
        return new AmqpLibConnection($config);
    }
    
    return new AmqpExtConnection($config);
}
```

### CI/CD Integration

The GitHub workflows automatically run `composer tests:ci` for the AMQP package, which ensures both implementations are tested on every pull request.

**Monorepo Workflow** (`.github/workflows/test-monorepo.yml`):
- Runs `vendor/bin/phpunit` which includes all packages
- The AMQP package's `tests:ci` script is triggered via the monorepo's test suite

**Split Testing Workflow** (`.github/workflows/split-testing.yml`):
- Tests each package individually
- Runs `composer tests:ci` for each package
- AMQP package automatically tests both implementations

## Benefits

1. **Automatic Coverage**: Both implementations are tested automatically
2. **No Workflow Changes**: GitHub workflows don't need special AMQP-specific logic
3. **Local Development**: Developers can easily test both implementations locally
4. **CI/CD Simplicity**: The complexity is in the package's composer.json, not in CI/CD configs
5. **Explicit Scripts**: Clear, named scripts for testing each implementation

## Requirements

Both packages must be installed in `require-dev` for tests to run:

```json
{
  "require-dev": {
    "ext-amqp": "*",
    "enqueue/amqp-ext": "^0.10.18",
    "enqueue/amqp-lib": "^0.10.25"
  }
}
```

## Troubleshooting

### Tests fail with "Class not found"

Make sure both packages are installed:

```bash
cd packages/Amqp
composer install
```

### Only one implementation is tested

Check that the `AMQP_IMPLEMENTATION` environment variable is being set correctly in the composer script.

### Tests pass locally but fail in CI

Ensure both `enqueue/amqp-ext` and `enqueue/amqp-lib` are in `require-dev` and that the AMQP extension is installed in the CI environment.

## Example Output

When running `composer tests:ci`, you should see output like:

```
> @tests:phpstan
> vendor/bin/phpstan
[OK] No errors

> @tests:phpunit:amqp-ext
> AMQP_IMPLEMENTATION=ext vendor/bin/phpunit --no-coverage
PHPUnit 10.5.0 by Sebastian Bergmann and contributors.
...............................................................  63 / 100 ( 63%)
.......................................                        100 / 100 (100%)
Time: 00:05.123, Memory: 50.00 MB
OK (100 tests, 250 assertions)

> @tests:phpunit:amqp-lib
> AMQP_IMPLEMENTATION=lib vendor/bin/phpunit --no-coverage
PHPUnit 10.5.0 by Sebastian Bergmann and contributors.
...............................................................  63 / 100 ( 63%)
.......................................                        100 / 100 (100%)
Time: 00:06.456, Memory: 52.00 MB
OK (100 tests, 250 assertions)
```

## Future Enhancements

Potential improvements:
- Add test groups to run only stream-specific tests with AMQP Lib
- Add performance benchmarks comparing both implementations
- Add code coverage reports for each implementation separately

