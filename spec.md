# Ecotone Claude Code Skills - Implementation Spec

## Design Philosophy

Make contributions to Ecotone as simple and straightforward as possible. Contributors should not need to know every convention — Claude applies them by default through **model-invocable skills** that load automatically when relevant.

Three principles:
1. **Conventions by default** — Claude auto-applies the right patterns without being asked
2. **Fast feedback** — Skills guide Claude to verify early and often
3. **No CI surprises** — `ecotone-contributor` catches everything before PR submission

## Skill Architecture

### Progressive Disclosure (3 Levels)

| Level | What | When Loaded | Token Cost |
|-------|------|-------------|------------|
| 1. YAML frontmatter `description` | Tells Claude *when* to use the skill | Always in system prompt | ~50-100 tokens per skill |
| 2. SKILL.md body | Tells Claude *how* to do it | When skill is invoked | Main instructions |
| 3. `references/` files | Deep reference material | When Claude reads them | On demand |

### YAML Frontmatter Fields

```yaml
---
name: skill-name                    # kebab-case, matches folder name
description: >-                     # WHAT + WHEN, third person, under 1024 chars
  Creates message handlers following Ecotone conventions.
  Use when writing command, event, or query handlers.
disable-model-invocation: false     # true = user-only (for side-effect skills)
user-invocable: true                # false = model-only (background knowledge)
allowed-tools: Read, Grep, Glob     # restrict tools if needed
context: fork                       # isolate in subagent context
agent: Explore                      # subagent type (with context: fork)
argument-hint: "[feature-name]"     # autocomplete hint
---
```

### Invocation Strategy

| Configuration | User invokes | Claude auto-invokes | Use for |
|---------------|-------------|--------------------|---------|
| Default (both) | Yes | Yes | Domain knowledge skills (testing, handlers, aggregates, etc.) |
| `disable-model-invocation: true` | Yes | No | Side-effect workflows (contributor PR checks) |
| `user-invocable: false` | No | Yes | Background knowledge skills |

**Key insight**: Most skills should be model-invocable so Claude automatically applies Ecotone patterns when a contributor asks to write code, without them needing to know about specific slash commands.

### Dynamic Context Injection

The `` !`command` `` syntax runs shell commands and injects output into the skill prompt:

```markdown
## Current changes
- Modified files: !`git diff --name-only`
- Staged diff: !`git diff --cached`
```

Use this for workflow skills that need runtime context (git state, test output, etc.).

### File Structure

```
.claude/skills/
├── ecotone-contributor/
│   ├── SKILL.md
│   └── references/
│       ├── ci-checklist.md           # Full CI checklist with exact commands
│       └── licence-format.md         # Licence header formats
├── ecotone-testing/
│   ├── SKILL.md
│   └── references/
│       ├── test-patterns.md          # Real test examples from codebase
│       └── ecotone-lite-api.md       # EcotoneLite/FlowTestSupport API
├── ecotone-handler/
│   ├── SKILL.md
│   └── references/
│       └── handler-patterns.md       # All handler types with attribute reference
├── ecotone-aggregate/
│   ├── SKILL.md
│   └── references/
│       └── aggregate-patterns.md     # State-stored and event-sourced examples
├── ecotone-interceptors/
│   ├── SKILL.md
│   └── references/
│       ├── interceptor-patterns.md   # Before/After/Around/Presend examples
│       └── pointcut-reference.md     # Pointcut expression syntax and targeting
├── ecotone-asynchronous/
│   ├── SKILL.md
│   └── references/
│       ├── channel-patterns.md       # Channel types and configuration
│       └── error-handling.md         # Retry, dead letter, error channels
├── ecotone-event-sourcing/
│   ├── SKILL.md
│   └── references/
│       ├── projection-patterns.md    # ProjectionV2, lifecycle, partitioning
│       └── versioning-patterns.md    # Event versioning, upcasting, and DCB
├── ecotone-business-interface/
│   ├── SKILL.md
│   └── references/
│       └── interface-patterns.md     # DBAL, repository, converter examples
└── ecotone-module-creator/
    ├── SKILL.md
    └── references/
        └── module-anatomy.md         # Module lifecycle and registration
```

---

## Skills

### Skill 1: `ecotone-contributor`

**Priority:** 1 — Every contributor needs this

```yaml
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
```

**What the SKILL.md body covers:**

1. **Dev environment setup:**
   - Docker Compose stack (`docker-compose up -d`)
   - Enter container: `docker exec -it -u root ecotone_development /bin/bash`
   - Database DSNs for MySQL/PostgreSQL/MariaDB

2. **Monorepo structure:**
   - Core package: `packages/Ecotone` — foundation for all others
   - Each `packages/*` is a separate Composer package split to read-only repos on release
   - Template for new packages: `_PackageTemplate/`
   - How `MonorepoBuilder` and package splits work

3. **PR validation workflow (order matters):**
   1. Run new/changed tests first — `vendor/bin/phpunit --filter testMethodName` for fastest feedback
   2. Run full test suite for affected package — `cd packages/PackageName && composer tests:ci` (PHPStan + PHPUnit + Behat)
   3. Verify licence headers on all new PHP files
   4. Fix code style — `vendor/bin/php-cs-fixer fix`
   5. Verify PHPStan — `composer tests:phpstan`
   6. Check conventions: `snake_case` test methods, no comments, PHPDoc on public APIs
   7. PR description: Why / What / CLA checkbox

4. **Code conventions quick reference:**
   - No comments — use meaningful private method names
   - PHP 8.1+ features (attributes, enums, named arguments)
   - Public APIs need `@param`/`@return` PHPDoc
   - Single quotes, trailing commas, `! $var` spacing

5. **Package split and dependency rules:**
   - How changes to `packages/Ecotone` propagate to downstream packages
   - How to verify lowest/highest dependency compatibility

**References:**
- `references/ci-checklist.md` — Full CI checklist with exact commands per package
- `references/licence-format.md` — Apache-2.0 and Enterprise licence header formats

---

### Skill 2: `ecotone-testing`

**Priority:** 2 — Every contribution needs tests

```yaml
---
name: ecotone-testing
description: >-
  Writes and debugs tests for Ecotone using EcotoneLite::bootstrapFlowTesting,
  inline anonymous classes, and snake_case methods. Covers handler testing,
  aggregate testing, async-tested-synchronously patterns, projections, and
  common failure diagnosis. Use when writing tests, debugging test failures,
  or adding test coverage.
---
```

**What the SKILL.md body covers:**

1. **Bootstrap selection:**
   - `EcotoneLite::bootstrapFlowTesting()` — standard handler/aggregate tests
   - `EcotoneLite::bootstrapFlowTestingWithEventStore()` — event-sourced aggregate tests

2. **Test structure rules:**
   - `snake_case` method names (enforced by PHP-CS-Fixer)
   - High-level tests from end-user perspective, never test internals
   - Inline anonymous classes with PHP 8.1+ attributes (not separate fixture files)
   - No comments — descriptive method names only
   - Licence header on all test files

3. **Testing patterns** (code examples in body, detail in references):
   - Simple handler testing (command/event/query)
   - Aggregate testing with commands and events
   - Event-sourced aggregate testing with `withEventsFor()`
   - **Async-tested-synchronously**: `enableAsynchronousProcessing` + `run()` / `releaseAwaitingMessagesAndRunConsumer()` — tests async handlers without real broker
   - Service stubs via second argument to `bootstrapFlowTesting`
   - `ServiceConfiguration` with `ModulePackageList::allPackagesExcept()`
   - Projection testing with `triggerProjection()`

4. **Debugging test failures:**
   - `ModulePackageList` misconfiguration
   - Missing service in container (second arg to `bootstrapFlowTesting`)
   - Channel not configured for async tests
   - Database DSN not set for integration tests
   - Lowest vs highest dependency issues
   - How to run single tests: `vendor/bin/phpunit --filter testName`

5. **Common mistakes to avoid:**
   - Using raw PHPUnit mocking instead of EcotoneLite
   - Creating separate fixture classes for test-only handlers
   - Testing implementation details instead of behavior

**References:**
- `references/test-patterns.md` — Real code examples of each pattern from the codebase
- `references/ecotone-lite-api.md` — EcotoneLite and FlowTestSupport API methods

---

### Skill 3: `ecotone-handler`

**Priority:** 3 — Most common code pattern

```yaml
---
name: ecotone-handler
description: >-
  Creates Ecotone message handlers with PHP attributes, proper
  endpointId configuration, and routing patterns. Covers CommandHandler,
  EventHandler, QueryHandler, and message metadata.
  Use when creating or modifying message handlers.
---
```

**What the SKILL.md body covers:**

1. **Handler types and attributes:**
   - `#[CommandHandler]` — handles commands, returns void or identifier
   - `#[EventHandler]` — reacts to events
   - `#[QueryHandler]` — handles queries, returns data
   - `#[ServiceActivator]` — low-level message endpoint

2. **EndpointId rules:**
   - Every handler needs a unique `endpointId` when registered programmatically
   - How `endpointId` relates to channel configuration and monitoring
   - Naming conventions for endpoint IDs

3. **Method signatures:**
   - Type-hinted message object as first parameter
   - Optional `#[Header('headerName')]` parameters for metadata
   - Return types matching the query/command contract
   - Aggregate method handlers (static factory vs instance)

4. **Routing patterns:**
   - Class-based resolution (default) — message class maps to handler
   - Routing key: `#[CommandHandler('order.place')]` for string-based routing
   - When to use which approach

5. **Conventions:**
   - PHPDoc on public APIs (`@param`/`@return`)
   - No comments — meaningful method names
   - Licence header
   - Follow existing patterns in the codebase

**References:**
- `references/handler-patterns.md` — All handler types with full attribute reference and examples

---

### Skill 4: `ecotone-aggregate`

**Priority:** 4 — Core DDD pattern

```yaml
---
name: ecotone-aggregate
description: >-
  Creates DDD aggregates following Ecotone patterns: state-stored and
  event-sourced variants with proper identifier mapping, factory patterns,
  and command handler wiring. Use when creating aggregates, entities with
  command handlers, or domain models.
---
```

**What the SKILL.md body covers:**

1. **State-stored aggregate:**
   - `#[Aggregate]` on the class
   - `#[Identifier]` on the identity field
   - Static factory method with `#[CommandHandler]` for creation (returns `self`)
   - Instance methods with `#[CommandHandler]` for state changes
   - Multiple identifiers with `#[Identifier]` on each field

2. **Event-sourced aggregate:**
   - `#[EventSourcingAggregate]` on the class
   - `#[EventSourcingHandler]` for applying events (rebuilds state from stream)
   - Recording events — return from handler vs `recordThat()`
   - Aggregate versioning with `#[Version]`

3. **Identifier mapping:**
   - `#[IdentifierMapping('commandField')]` — maps command property to aggregate ID
   - `#[TargetIdentifier]` on command properties
   - Multi-field identifiers

4. **Factory patterns:**
   - Static `#[CommandHandler]` returning `self` — creates new aggregate
   - Why factory is static (no existing instance yet)
   - Returning events from factory (event-sourced)

5. **Testing guidance:**
   - State-stored: send command, query state, assert
   - Event-sourced: `withEventsFor()` to set up state, send command, assert recorded events

**References:**
- `references/aggregate-patterns.md` — State-stored and event-sourced examples from codebase

---

### Skill 5: `ecotone-interceptors`

**Priority:** 5 — Cross-cutting concerns, middleware, hooking into handler execution

```yaml
---
name: ecotone-interceptors
description: >-
  Implements Ecotone interceptors and middleware: #[Before], #[After],
  #[Around], #[Presend] attributes with pointcut targeting, precedence
  ordering, header modification, and MethodInvocation flow control.
  Use when adding interceptors, middleware, cross-cutting concerns,
  hooking into handler execution, or implementing transactions/logging/auth.
---
```

**What the SKILL.md body covers:**

1. **Interceptor types:**
   - `#[Before]` — executes before the target handler. Parameters: `precedence`, `pointcut`, `changeHeaders`
   - `#[After]` — executes after handler completes. Parameters: `precedence`, `pointcut`, `changeHeaders`
   - `#[Around]` — wraps handler execution with full flow control via `MethodInvocation::proceed()`. Parameters: `precedence`, `pointcut`
   - `#[Presend]` — executes before message is sent to channel (message-level). Parameters: `precedence`, `pointcut`, `changeHeaders`

2. **Pointcut system** (how interceptors target handlers):
   - **By class/interface**: `pointcut: MyHandler::class` — targets any handler in that class
   - **By attribute**: `pointcut: CommandHandler::class` — targets methods with that attribute
   - **By method**: `pointcut: 'MyHandler::handleCommand'` — targets specific method
   - **Logical operators**: `&&` (AND), `||` (OR), `not()` (NOT)
   - **Automatic inference**: when no explicit pointcut, inferred from interceptor method parameter type-hints (attribute parameters)
   - Expression classes: `PointcutAttributeExpression`, `PointcutInterfaceExpression`, `PointcutMethodExpression`, `PointcutOrExpression`, `PointcutAndExpression`, `PointcutNotExpression`

3. **Precedence ordering** (lower value = earlier execution):
   - `Precedence::ENDPOINT_HEADERS_PRECEDENCE` (-3000) — headers setup
   - `Precedence::DATABASE_TRANSACTION_PRECEDENCE` (-2000) — transactions
   - `Precedence::LAZY_EVENT_PUBLICATION_PRECEDENCE` (-1900) — event publishing
   - `Precedence::DEFAULT_PRECEDENCE` (1) — default for custom interceptors
   - Execution order within phases: Presend → Before → Around → handler → Around end → After

4. **Header modification** (`changeHeaders: true`):
   - Interceptor receives `#[Headers] array $headers` parameter
   - Returns modified headers array
   - Framework merges returned headers into the message via `HeaderResultMessageConverter`
   - Only available on `#[Before]`, `#[After]`, `#[Presend]` (not `#[Around]`)

5. **MethodInvocation** (for `#[Around]`):
   - `proceed(): mixed` — continue to next interceptor or target handler
   - `getArguments(): array` — inspect method arguments
   - `replaceArgument(string $name, $value)` — modify arguments before proceeding
   - `getObjectToInvokeOn()` — get the handler instance
   - Must call `proceed()` or the handler chain stops

6. **Channel interceptors** (message-level, separate from method interceptors):
   - `ChannelInterceptor` interface with `preSend()`, `postSend()`, `preReceive()`, `postReceive()`
   - Applied at message channel level, not handler level
   - Broader scope than method interceptors

7. **Real-world examples:**
   - Transaction interceptor: `#[Around]` wrapping handler in begin/commit/rollback
   - Logging: `#[Before]` with `LogBefore` attribute pointcut
   - `#[InstantRetry(retryTimes: 3, exceptions: [...])]` — retry on specific exceptions

8. **Testing interceptors:**
   - Register interceptor class alongside handlers in `EcotoneLite::bootstrapFlowTesting()`
   - Pass interceptor instance for DI
   - Use call stack tracking to verify execution order
   - Test header modifications with `getRecordedMessages()`

**References:**
- `references/interceptor-patterns.md` — Before/After/Around/Presend examples from codebase with testing
- `references/pointcut-reference.md` — Pointcut expression syntax, operator combinations, and auto-inference rules

---

### Skill 6: `ecotone-asynchronous`

**Priority:** 6 — Essential for real applications

```yaml
---
name: ecotone-asynchronous
description: >-
  Implements asynchronous message processing in Ecotone: message channels,
  #[Asynchronous] attribute, polling consumers, Sagas, delayed messages,
  error handling with retry and dead letter queues, and the outbox pattern.
  Use when working with async processing, message channels, Sagas,
  delayed delivery, retries, or the outbox pattern.
---
```

**What the SKILL.md body covers:**

1. **`#[Asynchronous]` attribute:**
   - Applied to `#[CommandHandler]`, `#[EventHandler]`, or at class level
   - Accepts single channel name or array: `#[Asynchronous('orders')]` or `#[Asynchronous(['db', 'broker'])]`
   - Routes handler execution through specified message channels
   - Requires a corresponding channel to be configured

2. **Message channels:**
   - `SimpleMessageChannelBuilder::createQueueChannel('name')` — in-memory (testing, dev)
   - `DbalBackedMessageChannelBuilder::create('name')` — database-backed (outbox, durability)
   - `AmqpBackedMessageChannelBuilder` — RabbitMQ
   - `SqsBackedMessageChannelBuilder` — AWS SQS
   - `RedisBackedMessageChannelBuilder` — Redis
   - `CombinedMessageChannel` — routes through multiple channels in sequence (outbox → broker)
   - Channels registered via `#[ServiceContext]` methods

3. **Polling consumers and configuration:**
   - `PollingMetadata::create('endpointId')` — configure consumer behavior
   - Settings: `handledMessageLimit`, `executionTimeLimitInMilliseconds`, `memoryLimitInMegabytes`, `fixedRateInMilliseconds`, `stopOnError`, `finishWhenNoMessages`
   - `#[Poller]` attribute for inline configuration
   - Cron scheduling: `cron: '*/5 * * * *'`
   - Running consumers: `$messagingSystem->run('channel-name')`

4. **Sagas (Process Managers):**
   - `#[Saga]` attribute on the class (extends aggregate concept)
   - `#[Identifier]` for saga correlation
   - Event handlers drive saga state transitions
   - Static factory `#[CommandHandler]` starts new saga instances
   - Timeout/deadline handling with `#[Delayed]`
   - Completing and dropping sagas

5. **Delayed messages:**
   - `#[Delayed(5000)]` — delay in milliseconds
   - `#[Delayed(TimeSpan::withSeconds(5))]` — using TimeSpan
   - `#[Delayed(expression: 'header("delay")')]` — runtime expression
   - Testing: `->run('channel', $metadata, TimeSpan::withSeconds(60))` releases delayed messages

6. **Error handling and retry:**
   - `ErrorHandlerConfiguration::createWithDeadLetterChannel('errorChannel', $retryTemplate, 'dead_letter')`
   - `RetryTemplateBuilder::fixedBackOff(1000)` — fixed delay between retries
   - `RetryTemplateBuilder::exponentialBackoff(1000, 10)` — exponential backoff
   - `RetryTemplateBuilder::exponentialBackoffWithMaxDelay(1000, 10, 60000)` — capped exponential
   - `->maxRetryAttempts(3)` — limit retry count
   - `#[InstantRetry(retryTimes: 3, exceptions: [ConnectionException::class])]` — handler-level retry
   - Dead letter queues for unrecoverable failures
   - Error channel routing via `PollingMetadata::setErrorChannelName()`

7. **Outbox pattern:**
   - Use `DbalBackedMessageChannelBuilder` — events stored in DB transaction with business data
   - Consumer reads from DB table and forwards to external broker
   - `CombinedMessageChannel` chains DB → external broker
   - Guarantees: atomic with business data, no lost messages, eventual consistency

8. **Testing async:**
   - `enableAsynchronousProcessing: [SimpleMessageChannelBuilder::createQueueChannel('orders')]`
   - `->run('orders', ExecutionPollingMetadata::createWithTestingSetup())` — consume messages
   - `->sendDirectToChannel('channel', $payload)` — inject messages directly
   - `->getRecordedMessages()` / `->getRecordedCommands()` / `->getRecordedEvents()` — capture output
   - `ExecutionPollingMetadata::createWithTestingSetup(amountOfMessagesToHandle: 1, maxExecutionTimeInMilliseconds: 100)`

**References:**
- `references/channel-patterns.md` — Channel types, configuration, and `#[ServiceContext]` registration examples
- `references/error-handling.md` — Retry strategies, dead letter queues, saga error patterns

---

### Skill 7: `ecotone-event-sourcing`

**Priority:** 7 — Advanced but increasingly common

```yaml
---
name: ecotone-event-sourcing
description: >-
  Implements event sourcing in Ecotone: ProjectionV2 with partitioning
  and streaming, event store configuration, event versioning/upcasting,
  and Dynamic Consistency Boundary (DCB) patterns. Use when working with
  projections, event store, event versioning, or DCB.
---
```

**What the SKILL.md body covers:**

1. **ProjectionV2** (current projection system):
   - `#[ProjectionV2('projection-name')]` — main projection marker (requires only name)
   - `#[EventHandler]` methods for handling events in projections
   - **Lifecycle attributes:**
     - `#[ProjectionInitialization]` — called on init (create tables, setup state)
     - `#[ProjectionDelete]` — called on deletion (drop tables, cleanup)
     - `#[ProjectionFlush]` — custom flush operations
   - **Configuration attributes (composable, one per projection):**
     - `#[Partitioned]` — enables partitioned projections with partition key header (default: `EVENT_AGGREGATE_ID`)
     - `#[Polling]` — polling-based projection (requires `endpointId`)
     - `#[Streaming]` — event streaming from channel (requires `channelName`)
     - `#[FromStream('stream_name', Aggregate::class)]` — stream source configuration
     - `#[ProjectionExecution(batchSize: 1000)]` — batch size for event loading
     - `#[ProjectionBackfill]` — backfill settings (partition batch size, async channel)
     - `#[ProjectionDeployment]` — blue/green deployment (`manualKickOff`, `live`)
   - **Validation rules:** Cannot mix `#[Polling]` + `#[Streaming]`, `#[Polling]` + `#[Partitioned]`, or `#[Partitioned]` + `#[Streaming]`
   - **State management:**
     - `#[ProjectionState]` method parameter — reads/writes partition state
     - `ProjectionStateStorage` interface for custom backends (DBAL, in-memory)
   - **API:** `initializeProjection()`, `deleteProjection()`, `resetProjection()`, `triggerProjection()`, `rebuildProjection()`

2. **Legacy Projection (V1)** — `#[Projection('name', fromStreams: [...])]` in `PdoEventSourcing` package. V2 is preferred for new code.

3. **Event store:**
   - Event store configuration and backends (DBAL, in-memory)
   - Loading event streams for replay
   - Appending events manually
   - Multi-stream projections

4. **Event versioning and upcasting:**
   - Why events need versioning (schema evolution)
   - `#[EventRevision]` attribute for version tracking
   - Upcaster pattern — transforming old event shapes to new
   - Registering upcasters in the module system

5. **Dynamic Consistency Boundary (DCB):**
   - What DCB is and when to use it
   - How Ecotone supports DCB patterns
   - Multi-aggregate consistency without distributed transactions

6. **Testing event sourcing:**
   - `bootstrapFlowTestingWithEventStore()` setup
   - `withEventsFor(aggregateId, [...events])` for state setup
   - Asserting recorded events
   - Testing ProjectionV2: `initializeProjection()` → send events → `triggerProjection()` → assert read model
   - `LicenceTesting::VALID_LICENCE` for enterprise features in tests

**References:**
- `references/projection-patterns.md` — ProjectionV2 examples: partitioned, polling, streaming, lifecycle, state management
- `references/versioning-patterns.md` — Event versioning, upcasting, and DCB patterns

---

### Skill 8: `ecotone-business-interface`

**Priority:** 8 — Common for persistence and conversion layers

```yaml
---
name: ecotone-business-interface
description: >-
  Creates Ecotone business interfaces: DBAL query interfaces, repository
  abstractions, expression language usage, and media type converters.
  Use when creating database queries, custom repositories, data
  converters, or business method interfaces.
---
```

**What the SKILL.md body covers:**

1. **DBAL query interfaces:**
   - `#[DbalQueryBusinessMethod]` for SQL queries as interface methods
   - Parameter binding with `#[Parameter]`
   - Return type mapping (single object, collection, scalar)
   - Write operations with `#[DbalWriteBusinessMethod]`

2. **Repository interfaces:**
   - `#[Repository]` for custom aggregate repositories
   - Standard repository patterns (find, save, delete)
   - How Ecotone auto-implements repository interfaces

3. **Expression language:**
   - Ecotone's expression language in attributes
   - Using expressions for routing, filtering, transforming
   - Available variables in expression context (`payload`, `headers`)

4. **Media type converters:**
   - `#[Converter]` attribute for type conversion
   - `#[MediaTypeConverter]` for format conversion (JSON, XML, etc.)
   - `MediaType` class and content negotiation
   - Registering converters in the module system

5. **Business method interfaces:**
   - How Ecotone generates implementations from interfaces
   - `#[BusinessMethod]` for custom interface proxying
   - Combining business interfaces with message bus

**References:**
- `references/interface-patterns.md` — DBAL, repository, converter examples from codebase

---

### Skill 9: `ecotone-module-creator`

**Priority:** 9 — Least frequent but complex

```yaml
---
name: ecotone-module-creator
description: >-
  Scaffolds new Ecotone packages and modules: AnnotationModule pattern,
  module registration, Configuration building, and package template
  usage. Use when creating new framework modules, extending the module
  system, or scaffolding new packages.
disable-model-invocation: true
argument-hint: "[module-name]"
---
```

**Why `disable-model-invocation: true`:** Creates new files and directories. Should only run when explicitly requested.

**What the SKILL.md body covers:**

1. **Module class structure:**
   - `#[ModuleAnnotation]` attribute
   - Implements `AnnotationModule`
   - Extends `NoExternalConfigurationModule` when no external config needed

2. **Required methods:**
   - `create()` — static factory, receives `AnnotationFinder` and `InterfaceToCallRegistry`
   - `prepare()` — registers handlers, converters, service definitions on `Configuration`
   - `canHandle()` — declares supported extension objects
   - `getModulePackageName()` — returns module identifier from `ModulePackageList`

3. **Using `AnnotationFinder`:**
   - Scanning for custom attributes on classes/methods
   - Filtering by attribute type
   - Building handler registrations from scan results

4. **Using `ExtensionObjectResolver`:**
   - How modules accept configuration from users
   - Defining extension object contracts
   - Merging multiple configuration sources

5. **Package scaffolding:**
   - Start from `_PackageTemplate/`
   - Required files: `composer.json`, module class, test class
   - Registering in `ModulePackageList`
   - Adding to monorepo `composer.json`

6. **Testing modules:**
   - Verifying module registration with `EcotoneLite`
   - Testing that `prepare()` registers expected handlers
   - Integration testing the full module lifecycle

**References:**
- `references/module-anatomy.md` — Full module lifecycle, registration, `Configuration` API, and real examples

---

## Implementation Plan

### Phase 1: Foundation (Skills 1–3)
1. `ecotone-contributor` — Every contributor needs dev setup + PR workflow
2. `ecotone-testing` — Every contribution needs tests
3. `ecotone-handler` — Most common code pattern

### Phase 2: Domain Patterns (Skills 4–6)
4. `ecotone-aggregate` — Core DDD aggregate patterns
5. `ecotone-interceptors` — Cross-cutting concerns, middleware, hooking
6. `ecotone-asynchronous` — Channels, consumers, sagas, retry, outbox

### Phase 3: Advanced (Skills 7–9)
7. `ecotone-event-sourcing` — ProjectionV2, versioning, DCB
8. `ecotone-business-interface` — DBAL, repositories, converters
9. `ecotone-module-creator` — Package scaffolding

### Reference File Guidelines

Reference files provide the "third level" of progressive disclosure:
- **One topic per file** — focused and scannable
- **Real code from the codebase** — not abstract examples
- **Under 500 lines** — keep context cost manageable
- **One level deep** — SKILL.md references files, but files should not chain-reference other files
- **Update when patterns change** — stale references cause wrong code

### SKILL.md Template

```markdown
---
name: skill-name
description: >-
  Does X following Ecotone conventions. Use when [trigger phrases].
---

## Steps

1. **First action**
   Specific instructions with code example.

2. **Second action**
   ...

## Key Rules
- Rule 1
- Rule 2

## Examples

### Simple case
[code example]

### Advanced case
[code example]
```

Keep SKILL.md under 500 lines. Move detailed examples to `references/`.

### Evaluation Strategy

For each skill, validate with three scenarios:
1. **Happy path** — Does Claude produce correct output for a standard request?
2. **Edge case** — Does Claude handle unusual patterns (e.g., event-sourced aggregate with saga)?
3. **Convention enforcement** — Does Claude follow Ecotone conventions without being reminded?

Iterate: run scenario without skill (baseline) → add skill → compare → refine.

## Sources

- [Extend Claude with skills](https://code.claude.com/docs/en/skills) — Official Claude Code documentation
- [Skill authoring best practices](https://platform.claude.com/docs/en/agents-and-tools/agent-skills/best-practices) — Anthropic platform docs
- [Anthropic Skills GitHub](https://github.com/anthropics/skills) — Official examples
- [Equipping agents with Agent Skills](https://claude.com/blog/equipping-agents-for-the-real-world-with-agent-skills) — Anthropic blog
