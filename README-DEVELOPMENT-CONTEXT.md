# Ecotone Development Context

This document provides essential information about the Ecotone monorepo project structure, development environment, and testing procedures.

## Project Structure

Ecotone is organized as a monorepo containing multiple packages:

- The monorepo
- Each package is available under `packages/*` directory
- Core package is `packages/Ecotone`, which is the foundation for all other packages
- Each package is a separately delivered composer package with its own `composer.json`
- During release, each package is pushed to a separate read-only repository
- End-users can download specific packages directly from packagist.com

## Development Environment

The project uses Docker for development:

- A `docker-compose.yml` file in the root directory sets up all required containers
- To start the environment: `docker-compose up -d`
- After starting, all packages are available to run and use
- To enter the development container: `docker exec -it ecotone_development /bin/bash`
- From inside the container, you can run tests that require access to other services

## Running Tests

There are two main approaches to running tests:

### 1. From the Docker Container

```bash
# Enter the container
docker exec -it ecotone_development /bin/bash

# Run tests from inside the container
vendor/bin/phpunit
```

### 2. Directly from the Host Machine using Container context

```bash
# Run tests directly without entering the container
docker exec -it ecotone_development vendor/bin/phpunit
```

This method is faster and preferred for most testing scenarios.

## Testing Contexts

Tests can be run in two different contexts:

### Monorepo Context

When running tests from the root of the project, they execute in the context of the entire monorepo:
- Uses shared dependencies
- Runs on vendor packages compatible with all Ecotone packages
- Command: `vendor/bin/phpunit` (from project root)

### Package Context

Tests can also be run in the context of a specific package:
```bash
cd ./packages/Dbal
composer update
vendor/bin/phpunit
```

This approach uses the package's own dependencies and is useful for isolated testing.

## How to write tests

The preferred way to write tests is writing high level tests, which tests from end-user perspective.
The need for that is, that those tests are more reliable in long-term, and allows for refactoring without being broken.  
This is done using Ecotone Lite, which bootstrap small Ecotone Application which can be run in isolation for specific set of classes.  

You can read more about testing approaches under [Testing Support page](https://docs.ecotone.tech/modelling/testing-support).

## Types of Tests

The project uses several testing tools:

### 1. PHPUnit Tests

Used for unit and integration testing:
- Run with: `vendor/bin/phpunit` or `composer tests:phpunit`
- Configuration: Each package has its own `phpunit.xml.dist` file
- Can run specific tests: `vendor/bin/phpunit --filter testMethodName tests/path/to/TestFile.php`

### 2. Behat Tests

Used for behavior-driven development (BDD) and feature testing:
- Run with: `vendor/bin/behat` or `composer tests:behat`
- Configuration: Each package that uses Behat has a `behat.yml` file
- Behat tests are organized in feature files with human-readable scenarios

### 3. PHPStan Static Analysis

Used for static code analysis to detect potential errors:
- Run with: `vendor/bin/phpstan` or `composer tests:phpstan`
- Helps identify type-related issues and other potential bugs without executing the code

### 4. CI Tests

Combined test suites for continuous integration:
- Run with: `composer tests:ci`
- Typically runs PHPStan, PHPUnit, and Behat tests in sequence
- Some packages may include additional checks in their CI process

## Package-Specific Testing

## Common Testing Commands

```bash
# Run all PHPUnit tests in a package
cd packages/PackageName
composer tests:phpunit

# Run a specific test file
vendor/bin/phpunit tests/path/to/TestFile.php

# Run a specific test method
vendor/bin/phpunit --filter testMethodName tests/path/to/TestFile.php

# Run static analysis
composer tests:phpstan

# Run Behat tests (if available for the package)
composer tests:behat

# Run all tests for CI
composer tests:ci
```

## Testing Utilities

The framework provides several testing utilities:

- `EcotoneLite::bootstrapForTesting()` - Creates a test instance of Ecotone
- `FlowTestSupport` - Helps test message flows
- `MessagingTestSupport` - Provides support for testing messaging components

## Some packages may support multiple versions of 3rd party libraries

For example Dbal may support version 3 and 4 of Doctrine DBAL.  
To see which version is supported check related composer.json file in the package directory.  

It's easy to verify lower and highest dependency versions by running from the package context

```bash
composer update --prefer-lowest
vendor/bin/phpunit
composer update --prefer-stable
vendor/bin/phpunit
```

Tests within the package will always run faster than in the monorepo context, as they are limited to specific context.  
However it's still worth to run specific failure test with `--filter testMethodName` to have the quickest feedback loop.

## Important Notes

1. Some tests require specific environment variables to be set
2. Database tests may require the Docker environment to be running
3. Each package may have specific testing requirements defined in its README or phpunit.xml.dist file
4. The Dbal package tests interact with actual databases, so they require the database containers to be running
