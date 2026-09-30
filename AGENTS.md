# Ecotone Framework — AI Agent Guidelines

This is the guide for anyone, human or agent, writing code in this repository. It is the only one — `CLAUDE.md`,
`.cursorrules` and `.augment-guidelines` all point here rather than restating anything, so there is one copy of
each rule and nothing to drift. Read it before the first edit, not after review.

## Read before writing code

1. **[docs/coding-conventions.md](./docs/coding-conventions.md)** — 21 rules, each one a contributor got wrong at
   least once, each with the file or commit that evidences it. This is the source of truth for *how* to write here.
2. **This file** — what the project is, where things are, and how to run and ship the work.
3. **[docs/dev-workflow.md](./docs/dev-workflow.md)** — the exact commands: containers, per-package test runs,
   database DSNs, the licence and style tooling, what CI does.

The five that cost the most when they are wrong, because each one means throwing work away rather than adjusting it:

- **Tests validate at the userland level only.** No SQL against Ecotone's own tables, no reflection, no statement
  counting, no internal service references. Fetch a gateway and assert on what the application observes. A whole
  worktree — 22 commits — did nothing but pull one feature's tests back to the public surface
  ([rule 10](./docs/coding-conventions.md#10-tests-validate-at-the-userland-level-only)), and a guarantee has to be
  proved on every path that reaches it, not only the one you changed
  ([rule 10a](./docs/coding-conventions.md#10a-a-guarantee-is-proved-on-every-path-that-has-to-hold-it))
- **Exceptions drive the solution.** Name what was wrong, why it cannot work, and *every* way out — the exact API
  call, attribute, option or command. The message is the deliverable; assert it in a test
  ([rule 1](./docs/coding-conventions.md#1-exceptions-drive-the-solution))
- **Never take a nullable service dependency.** Register unconditionally, decide at runtime. A `?Service = null` is
  a mode switch in disguise ([rule 2](./docs/coding-conventions.md#2-never-take-a-nullable-service-dependency))
- **Write the test first**, with `EcotoneLite::bootstrapFlowTesting()`: RED, then GREEN, then refactor, one
  increment per commit ([rule 11](./docs/coding-conventions.md#11-test-shape))
- **Verify every name against the tree before you write it.** The API moved in 2.0 —
  `Ecotone\Api\Attribute\CommandHandler`, not the old flat namespace. Three invented facts reached the first draft
  of the 2.0 docs this way (`upgrade/namespace-map-2.0.csv` has the full mapping)

## Quick Start

```bash
# Bootstrap a fresh checkout — two commands, no .env to create, no flags to remember
docker compose up -d
docker compose exec app composer install

# Run one package's full test suite — inside the container, one package at a time, never in parallel
docker compose exec app bash -lc "cd packages/PackageName && composer install && composer tests:ci"

# Run one test — --no-coverage, or PHPUnit 12's coverage requirement aborts the run
docker compose exec -T app vendor/bin/phpunit --no-coverage --filter test_method_name

# php-cs-fixer runs ON THE HOST, never in the container: git is unavailable there, so the
# fixer loses its file filter and rewrites the whole repository
vendor/bin/php-cs-fixer fix
```

Do not pass `-u root` to `composer` or the test scripts — it leaves `vendor/` root-owned on the host.
[docs/dev-workflow.md](./docs/dev-workflow.md) has the rest;
`docs/dev-environment-cold-start-findings.md` lists the gaps a fresh checkout used to hit.

## Project Overview

Ecotone is the enterprise architecture layer for Laravel and Symfony.
One Composer package adds CQRS, Event Sourcing, Sagas, Projections, Workflows, and Outbox messaging via declarative
PHP 8 attributes.
Works with Symfony, Laravel, Tempest, or standalone via Ecotone Lite (any PSR-11 container).

## Monorepo Structure

Every directory under `packages/` is a separate Composer package with its own `composer.json`, `phpstan.neon`,
`phpunit.xml.dist` and `vendor/`, split to a read-only repository on release:

| | |
|---|---|
| `Ecotone` | the core package — every other package depends on it |
| `Dbal`, `PdoEventSourcing` | database abstraction; event sourcing and the event store |
| `Amqp`, `Sqs`, `Redis`, `Kafka`, `Enqueue` | broker and queue integrations |
| `Laravel`, `Symfony`, `Tempest` | framework integrations |
| `JmsConverter`, `OpenTelemetry`, `DataProtection` | serialization, tracing, encryption |

- **A change to `packages/Ecotone` can affect every package**, so run the downstream suites too, and a
  cross-package change needs tests in both packages
- Splits are managed by `symplify/monorepo-builder` (`monorepo-builder.php`)
- Template for a new package: `_PackageTemplate/`. Registering it also means adding its name to
  `ModulePackageList` and its module class to the right `ModuleClassList` constant

## Code Conventions

**Read [docs/coding-conventions.md](./docs/coding-conventions.md) before writing code.** It is the single source
for the rules below, each with the reasoning, a wrong/right pair where the rule is easy to misread, and the file or
commit that evidences it. The summary:

| # | Rule | Why |
|---|---|---|
| 1 | **Exceptions drive the solution** — name what was wrong, why it cannot work, and *every* way out (the exact API call, attribute, option or command). Assert the message in a test | The message is what the agent or developer reacts to; it is the deliverable |
| 1a | A configuration mistake is **refused at bootstrap**, never at runtime, and never read as an empty result — the guard and the message test ship in the commit that states the rule | One worktree of 18 fix commits paid for the rules that shipped without one |
| 2 | **Never take a nullable service dependency.** Register unconditionally, decide at runtime. Split the class, add a null-object factory, make the parameter required, or pick between two services in the container | A `?Service = null` is a mode switch in disguise, and the two modes drift |
| 3 | **Constructor-injected services are stateless** — no accumulating properties, no static caches, no per-message collectors. Mutable state goes in a function-scoped immutable value object carried on a message header | A container service is a singleton; anything it remembers leaks into the next message, transaction and retry |
| 4 | **Do not mix queries with writes.** A read never runs DDL, a mutator returns `void`, and the name says which it is | `getRecordedEvents()` cleared its buffer and became `popRecordedEvents()` for exactly this reason |
| 5 | **No interface with a single implementation.** An interface marks a real seam: open core vs Enterprise, or in-memory vs storage-backed | Otherwise it is a dispatch hop with no choice behind it |
| 6 | **Names carry the meaning. No comments, no descriptive docblocks.** Array shapes, generics and `@link https://docs.ecotone.tech/...` are the only allowed docblocks. One word per concept, through the whole path | A comment drifts from the code beside it; a name and an exception message cannot |
| 7 | **Enterprise features live in separate files** with `licence Enterprise`, chosen in the container via `LicenceDecider`. Open-source files carry `licence Apache-2.0`. Open-core behaviour stays byte-for-byte unchanged | No `if ($hasLicence)` branches to keep in step |
| 8 | **Always use optimistic locking** — a version check on the write, never a pessimistic lock or a bespoke tracker | Pessimistic locking and snapshot tracking were both tried and removed |
| 9 | **A feature needing external storage ships an in-memory implementation**, named to mirror the storage one, and one shared suite proves the two behave identically | Otherwise the feature cannot be used in an `EcotoneLite` flow test — and `InMemoryEventStore` silently diverged from DBAL three times |
| 10 | **Tests validate at the userland level only.** No SQL on Ecotone's tables, no internal service references, no reflection, no statement counting. Fetch a **gateway** and assert on observable behaviour | A whole worktree — 22 commits — did nothing but pull one feature's tests back to the public surface |
| 10a | **A guarantee is proved on every path that has to hold it** — direct call, class-routed bus send, routed send, gateway, console command, and the reconstruction path beside the live one | Five times in one feature, a fix covered one entry point and looked finished |
| 11 | **`EcotoneLite`, `snake_case`, no comments, fixtures in the test file.** Test-first: RED, GREEN, refactor | |
| 12 | **The public surface is `Api/`, a sibling of `src/`** — never `src/Api/`, which breaks Tempest discovery. Everything outside `Api` is `@internal` | |
| 13 | **Configuration is attributes plus `#[ServiceContext]`**, compiled into a container via `DefinedObject`/`Definition`. No YAML, no XML | |
| 14 | **A console option's name is its PHP parameter name verbatim** — camelCase, never kebab-case | |
| 15 | **Orchestrating methods read as step lists**; SQL belongs to the collaborator that owns the table | |
| 16 | **DDL never runs inside a message transaction** | MySQL/MariaDB implicitly commit on DDL |
| 17 | New files are `final` and `declare(strict_types=1)` | |
| 18 | PHP 8.1+ features where they say something; named arguments once past two parameters | |

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

- **Write the test first**: RED, then GREEN, then refactor, one increment per commit, and prove the test is red
  against the base commit before making it pass. A pure refactor with no behaviour change rides on the tests that
  already cover it
- Tests use **`EcotoneLite::bootstrapFlowTesting()`**, or `bootstrapFlowTestingWithEventStore()` when the test
  needs a real event store
- **Assert only on what the application observes.** No SQL against Ecotone's own tables, no reflection, no
  statement counting, no internal service references — fetch a gateway (`$ecotone->getGateway(EventStore::class)`)
  and drive the public API. This is the most-corrected rule in the repository; see conventions rule 10
- **Fixtures live in the test file**: an anonymous class when it only holds handler methods, named classes below
  the `TestCase` when the class name is part of what is under test (aggregates, events, commands) — the dominant
  form in 2.0, in 92 of the 141 test files added since 1.x. Not a shared `Fixture/` directory
- `snake_case` method names, named after the behaviour rather than the class that implements it
- No comments, no docblocks, no assertion messages. The method name is the description
- **One test per path the guarantee has to hold on**, not one per change: a class-routed `CommandBus::send()`, a
  routed send, a gateway, a console command and an aggregate save all reach the same behaviour differently, and the
  backfill path of a concept has to be proved alongside its live path (conventions rule 10a)
- **A seam with two implementations is asserted in one suite both of them run** — an in-memory store that nobody
  proves equal to the DBAL one makes every flow test using it suspect (conventions rule 9)
- Run the suite of the package you modified, **one package at a time, never in parallel** — the packages share the
  compose services and a parallel run produces false failures

Conventions [rule 11](./docs/coding-conventions.md#11-test-shape) has a full worked example.
[docs/dev-workflow.md](./docs/dev-workflow.md) has every command, the database DSNs, and what each package's
`composer tests:ci` actually runs.

Static analysis is **phpstan level 1 over `src` only** — not `Api/`, not `tests/`, and not every package. It will
not catch a wrong class name in an attribute argument, because attribute arguments resolve lazily via reflection.
**A green phpstan is not evidence.**

## Common Patterns

Every user-facing class lives under `Ecotone\Api` — attributes in `Ecotone\Api\Attribute`, buses in
`Ecotone\Api\Gateway` (`CommandBus`, `EventBus`, `QueryBus`, `DistributedBus`), projections in
`Ecotone\Api\Projecting`. The imports are part of the pattern: the namespaces moved in 2.0, so check them against
the tree rather than from memory (`upgrade/namespace-map-2.0.csv` has the full mapping).

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

## Design, briefs and review

Three independent friction reports over the DCB effort measured what a unit of work costs when the process around
it is loose. [docs/dev-workflow.md § Design, briefs and review](./docs/dev-workflow.md#design-briefs-and-review) has
the evidence; the four rules:

- **A design justifies new storage against the read paths that already exist.** Name the index or read path you
  checked, and why it is not enough, before proposing a new one. A proposal for a new tag index plus a daily
  backfill was replaced by a query against an index the event stream already had — "smaller by an order of
  magnitude", and caught only because a human read the design first
- **A design document's claims are the unit's acceptance criteria.** A claim ships with a test named after it, in
  the same commit. All three reports found this independently: a promise of one read per handler shipped as one read
  per model, five review commits added tests for behaviour that was already correct, and 313 lines of test proved a
  capability that had worked since the day it shipped
- **A brief states its boundaries up front** — whether an exclusion covers tests as well as production code, whether
  "handle X here" reaches the paths X shares with other features, which database engines the change must be verified
  on, and which conventions rules the unit will be checked against. Three of seven units in one group needed a
  blocking round-trip for want of one sentence
- **A review that numbers its findings commits the numbered list.** DCB commits cite `(M1)`, `(N9)`, `(B1)`; the
  document assigning those IDs was never committed, so no fix can be read against what was asked

[Practices worth repeating](./docs/dev-workflow.md#practices-worth-repeating) records the other half — the
survey-first sweep, the review read in a newcomer's order and ranked by size, and performance work that states its
noise floor before it states a result.

## Committing

- Conventional subjects, scoped: `feat(dcb):`, `fix(event-sourcing):`, `refactor(modelling):`, `test(dbal):`,
  `docs(2.0):`, `style(dcb):`. One increment per commit
- Every commit carries an 08:00 timestamp:
  ```bash
  GIT_AUTHOR_DATE="$(date +%Y-%m-%d) 08:00:00" GIT_COMMITTER_DATE="$(date +%Y-%m-%d) 08:00:00" git commit ...
  ```
- Never commit `Monorepo/*/Symfony/config/reference.php` - they drift on their own

## Opening a Pull Request

For a contribution targeting `main`. Work on 2.0 is merged through its own branches instead.

Run the checks in
[docs/dev-workflow.md § Before opening a pull request](./docs/dev-workflow.md#before-opening-a-pull-request)
first — CI is `fail-fast`, so the first failure hides everything after it.

**Title and branch** use a conventional prefix, matching the commit subjects above: `feat:`, `fix:`, `refactor:`,
`docs:`, `test:`.

**Body** starts from `.github/PULL_REQUEST_TEMPLATE.md`, which has three sections — *Why is this change proposed?*,
*Description of Changes*, and the contribution terms. Fill all three:

1. **Why** — the problem, in the reporter's terms. What was not possible before
2. **Description of Changes** — what changed, and what an application observes differently
3. **Contribution terms** — tick the CLA checkbox. `.github/workflows/contribution-check.yml` fails the PR without
   it, on open and on every edit

Add, beyond the template, when the change warrants it:

- **Usage examples** for a new or changed feature — the PHP an application would write: handler registration,
  the attribute, the `#[ServiceContext]` configuration
- **A Mermaid diagram** for anything that changes a message flow — handler chains, async processing, sagas,
  interceptor pipelines:
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

**Draft the body and get it agreed before opening the PR.** Propose it, let the maintainer correct it, and do not
publish a description they have not seen.

`CONTRIBUTING.md` covers the fork-and-clone side for outside contributors.

## Documentation Resources

In this repository:

- [docs/coding-conventions.md](./docs/coding-conventions.md) - the conventions summarized above, in full
- [docs/dev-workflow.md](./docs/dev-workflow.md) - containers, test commands, database DSNs, tooling, CI
- `upgrade-2.0.md` - every 1.x → 2.0 behaviour change, with Before / Now / How to adapt
- `upgrade/namespace-map-2.0.csv` - the full old → new FQCN mapping for the 2.0 `Api` move
- `docs/superpowers/specs/` - design decisions, the alternatives considered, and why. Tracked, but
  `docs/superpowers/` is in `.gitignore`, so a new one needs `git add -f`
- `.claude/skills/ecotone-*` - per-area guides for the user-facing API: `ecotone-testing` for test patterns,
  `ecotone-module-creator` for new modules and packages, and one per feature area

Published:

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

# Enter a dev container — app is PHP 8.5.3, app8_2 is the PHP 8.2 floor
docker compose exec app /bin/bash
docker compose exec app8_2 /bin/bash
```

`docker compose up -d` builds a local image with `ext-sockets` baked in for the `app` service the
first time it runs — the published `simplycodedsoftware/php:8.5.3` image doesn't have it — and
blocks until every database/broker dependency reports healthy, so there is nothing to wait for
manually afterwards. `.env` is optional: it is only read if present (`env_file: required: false`),
so nothing needs to be created for a fresh checkout; copy `.env.dist` to `.env` only if you want to
override a default (e.g. turn Xdebug on).

The root `vendor/` is separate from each package's own `vendor/`, but it is never unused: Ecotone's
annotation finder always loads the monorepo root `vendor/autoload.php`, so keep the root install in sync
even when you only intend to run one package's tests.
