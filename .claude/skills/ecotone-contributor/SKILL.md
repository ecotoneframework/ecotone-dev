---
name: ecotone-contributor
description: >-
  Guides Ecotone framework contributions: dev environment setup, monorepo
  navigation, running tests, PR workflow, and package split mechanics.
  TRIGGER whenever any code change is made to the Ecotone codebase —
  new features, bug fixes, refactors, or any modification to source files.
  Also use when setting up development environment, preparing PRs, validating
  changes, running tests across packages, or understanding the monorepo
  structure.
argument-hint: "[package-name]"
---

# Ecotone Contributor Guide

Tests use inline anonymous classes with PHP 8.1+ attributes, snake_case method names, and high-level behavioral assertions. Use this skill when writing or debugging any Ecotone test.

## 0. Test-First Rule (MANDATORY)

Every new feature or bug fix **MUST** start with a test written using **Ecotone Lite** (`EcotoneLite::bootstrapFlowTesting()` or `EcotoneLite::bootstrapForTesting()`). This is a blocking requirement — do not write production code before the test exists.

**Tests must follow the userland perspective.** Each test should read like an example of how an Ecotone end-user would use the feature in their application. The test sets up real handler classes (inline anonymous classes with attributes), sends commands/events/queries through the bus, and asserts on the outcome — exactly as a user would interact with the framework. Never test framework internals directly; instead, demonstrate the feature through its public API as seen by the user.

**Workflow:**
1. **Write the test first** using Ecotone Lite — model it as a real-world usage example from the user's perspective
2. Run the test — confirm it fails (red)
3. Write the minimal production code to make it pass (green)
4. Refactor if needed

**Example test-first skeleton (userland perspective with inline classes):**

```php
public function test_placing_order_publishes_event(): void
{
    $ecotoneLite = EcotoneLite::bootstrapFlowTesting(
        [new class {
            private array $products = [];

            #[CommandHandler]
            public function placeOrder(#[Header('orderId')] string $orderId, #[Header('product')] string $product): void
            {
                $this->products[$orderId] = $product;
            }

            #[QueryHandler('order.getProduct')]
            public function getProduct(string $orderId): string
            {
                return $this->products[$orderId];
            }
        }],
    );

    $ecotoneLite->sendCommandWithRoutingKey('placeOrder', metadata: ['orderId' => '123', 'product' => 'Book']);

    $this->assertSame(
        'Book',
        $ecotoneLite->sendQueryWithRouting('order.getProduct', '123'),
    );
}
```

Tests use **inline anonymous classes** defined directly inside the test method — never create separate fixture files. This keeps each test self-contained and readable as a complete usage example. **Never use static properties or static methods** in test classes — use instance properties and instance methods only. The test demonstrates **how a user would use the feature** — registering handlers, sending commands, and querying results. It does not test internal message routing, channel resolution, or framework wiring directly.

This applies to **all** code changes that add or modify behavior — features, bug fixes, refactors that change behavior. Pure refactors with no behavior change may skip this if existing tests already cover the behavior.

## 1. Dev Environment Setup

Start the Docker Compose stack:

```bash
docker compose up -d
```

Enter the main container:

```bash
docker compose exec app /bin/bash
```

PHP 8.2 container (for compatibility testing):

```bash
docker compose exec app8_2 /bin/bash
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
docker compose exec -T app vendor/bin/phpunit --filter test_method_name
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

### Step 7: Create Pull Request

Use the repository's PR template at `.github/PULL_REQUEST_TEMPLATE.md`.

**Before creating the PR**, generate a suggested description based on the changes made, focusing on **why** the change is needed and **what benefits it provides to end users**. Present this suggestion to the user and let them agree or modify it. Do not use the description without user confirmation.

**Branch and PR title naming**: Use conventional commit prefixes:
- `feat:` for new features (e.g. `feat: add support for delayed message publishing`)
- `fix:` for bug fixes (e.g. `fix: resolve race condition in saga handler`)
- `refactor:` for refactoring (e.g. `refactor: simplify interceptor resolution`)
- `docs:` for documentation changes
- `test:` for test-only changes

**PR body must include**:
1. **Why is this change proposed?** — Use the user's description of the problem
2. **Description of Changes** — Summarize what was changed
3. **Usage examples** — If the PR adds or changes a feature, include PHP code examples showing how to use it (command/event/query handler registration, configuration, etc.)
4. **Mermaid flow diagrams** — For changes involving message flows, handler chains, async processing, sagas, or interceptor pipelines, include a Mermaid diagram illustrating the flow:
   ````markdown
   ```mermaid
   sequenceDiagram
       participant User
       participant CommandBus
       participant Handler
       User->>CommandBus: PlaceOrder
       CommandBus->>Handler: #[CommandHandler]
       Handler-->>User: OrderPlaced event
   ```
   ````
5. **Pull Request Contribution Terms** — CLA checkbox signed

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