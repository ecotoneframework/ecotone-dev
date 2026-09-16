# Database Setup CLI — no implicit commit, no on-the-fly DDL — 2.0 Design (Group F2)

Status: draft — awaiting maintainer approval
Date: 2026-08-28
Release group: F2 (remove implicit commit + runtime table creation; explicit CLI and programmatic setup API)
Depends on: Group D (`docs/superpowers/specs/2026-08-22-dcb-event-store-design.md`) — for the final step only, see §Implementation plan
Research: `docs/superpowers/research/database-setup-cli/report.md` (Revision 3, re-verified 2026-08-28)
Verified against: `dgafka/ecotone-2-0-work` @ `bcf4efc0`

## Problem

Ecotone creates database tables during message handling. On MySQL that DDL commits the surrounding
transaction, so the framework carries a workaround that swallows the resulting commit failure, and a second
workaround that disables a concurrency guarantee on every platform except PostgreSQL. Both are marked
`@TODO Ecotone 2.0` in the source.

### The implicit-commit workaround

`packages/Dbal/src/DbalTransaction/DbalTransactionInterceptor.php:101-126`, the commit half of
`transactional()`:

```php
foreach ($connections as $connection) {
    try {
        $connection->commit();
        $this->logger->info('Database Transaction committed', $message);
    } catch (Exception $exception) {
        // Handle the case where a database did an implicit commit or the transaction is no longer active
        /** @TODO Ecotone 2.0 remove implicit commit and tables creation on fly, and provide CLI command instead */
        if (ImplicitCommit::isImplicitCommitException($exception, $connection)) {
            // ... log, swallow, rollBack() defensively, continue
        }
        throw $exception;
    }
}
```

`ImplicitCommit::isImplicitCommitException()` (`packages/Dbal/src/DbalTransaction/ImplicitCommit.php:16-36`)
detects this by **string-matching the DBAL exception message** against four phrases (`'No active
transaction'`, `'There is no active transaction'`, `'Transaction not active'`, `'not in a transaction'`),
scoped to `AbstractMySQLPlatform` (`:18-20`). A genuine connection loss whose driver message happens to
contain one of those phrases is silently treated as a successful commit.

The consequence being worked around: MySQL and MariaDB commit the open transaction implicitly on any DDL
statement. When Ecotone creates a table during the first message, every write the handler already performed
is committed at that instant, outside Ecotone's control. A later handler exception rolls back nothing.
The workaround makes the *commit call* succeed; it does not restore atomicity.

### The Postgres special case

`packages/Dbal/src/Deduplication/DeduplicationInterceptor.php:99-109`:

```php
/** @TODO Ecotone 2.0 remove postgres check - when getting rid of implicit commit for MySQL */
$isTransactionActive = $connection->isTransactionActive() && $connection->getDatabasePlatform() instanceof PostgreSQLPlatform;
// ensure that concurrent message handling will fail before proceeding
if ($isTransactionActive) {
    $this->insertHandledMessage($connectionFactory, $messageId, $consumerEndpointId, $routingSlip);
}
$result = $methodInvocation->proceed();
if (! $isTransactionActive) {
    $this->insertHandledMessage($connectionFactory, $messageId, $consumerEndpointId, $routingSlip);
}
```

Inserting the deduplication row *before* `proceed()` is what makes two concurrent consumers of the same
message collide on the primary key instead of both running the handler. That guarantee is available only on
PostgreSQL today, because on MySQL the row insert would be at risk of being committed by an implicit commit
from table creation later in the same handler.

### Check-then-create, on every table manager

Four of the six live `DbalTableManager` implementations create tables like this
(`packages/Dbal/src/Database/DeduplicationTableManager.php:52-59`, identical in
`EnqueueTableManager.php:52-59`, `DeadLetterTableManager.php:56-63`, `DocumentStoreTableManager.php:51-58`):

```php
public function createTable(Connection $connection): void
{
    if (self::isInitialized($connection)) {
        return;
    }
    SchemaManagerCompatibility::getSchemaManager($connection)->createTable($this->buildTableSchema());
}
```

`getCreateTableSql()` on all four returns `$connection->getDatabasePlatform()->getCreateTableSQL($table)` — a
plain `CREATE TABLE`. Doctrine's platform generator does not add `IF NOT EXISTS`. Two processes racing can
both pass `isInitialized()` before either `CREATE TABLE` lands; the loser gets `TableAlreadyExistsException`.
The interface docblock says "Should use CREATE TABLE IF NOT EXISTS"
(`packages/Dbal/src/Database/DbalTableManager.php:33`) — an unenforced contract these four violate. Only
`ProjectionStateTableManager` (`packages/PdoEventSourcing/src/Database/ProjectionStateTableManager.php:100,114`)
and `EventStreamTableManager` emit literally idempotent DDL.

`DatabaseSetupManager` adds a second layer of the same pattern: `initializeAll()` skips managers that report
initialised (`packages/Dbal/Api/DatabaseSetupManager.php:85-91`) before calling `createTable()`, which checks
again. Two nested check-then-create windows, neither of which closes the race.

### Prooph creates a table per stream, ungated, inside the transaction

The largest remaining source of DDL-in-transaction is not in the DBAL package at all.
`LazyProophEventStore::appendTo()` (`packages/PdoEventSourcing/src/Prooph/LazyProophEventStore.php:164-175`):

```php
if (! isset($this->ensuredExistingStreams[$this->getContextName()][$streamName->toString()]) && ! $this->hasStream($streamName)) {
    $this->create(new Stream($streamName, new ArrayIterator([]), []));
}
```

`create()` (`:155-162`) hands off to Prooph, which issues `CREATE TABLE` for the physical stream table
(`vendor/prooph/pdo-event-store/src/PostgresEventStore.php:205` → `createSchemaFor()` `:848-862`). Table names
are `'_' . sha1($streamName)`
(`packages/PdoEventSourcing/src/Prooph/PersistenceStrategy/InterlopMysqlSimpleStreamStrategy.php:85`), and the
strategies emit a bare `CREATE TABLE` with no `IF NOT EXISTS` (`:40`).

This path consults no `shouldBeInitializedAutomatically()`. It is unaffected by
`DbalConfiguration::withAutomaticTableInitialization(false)`. And no CLI can pre-create these tables: under
the aggregate persistence strategy the stream name — and therefore the table name — is derived per aggregate
instance at runtime. **Removing `ImplicitCommit` is unsafe while Prooph is the event store.** Group D's
single global `event_log` removes the mechanism; until it lands, this design removes everything else and
leaves `ImplicitCommit` in place.

### Single-connection setup, and a runtime counterpart

`DatabaseSetupModule::prepare()` builds one `DatabaseSetupManager` against one connection:

```php
// packages/Dbal/src/Database/DatabaseSetupModule.php:48
$connectionReference = $dbalConfiguration->getDefaultConnectionReferenceNames()[0] ?? DbalConnectionReference::DEFAULT;
```

This is not "the wrong index of a relevant list" — `DbalTableManagerReference`
(`packages/Dbal/src/Database/DbalTableManagerReference.php:14-25`) carries only a service reference name and
no connection at all, so there is nothing to index. Every registered manager is run against that single
connection regardless of what its owning feature resolved to, and features genuinely do resolve
independently: `getDeduplicationConnectionReference()` / `getDeadLetterConnectionReference()`
(`packages/Dbal/Api/DbalConfiguration.php:79-87`) route through `getMainConnectionOrDefault()` (`:89-104`)
where an explicit `withDeduplication(connectionReference: 'reporting')` (`:210`) wins outright, and
`EventSourcingConfiguration::create('eventstore')`
(`packages/PdoEventSourcing/Api/EventSourcingConfiguration.php:51`) is a separate connection entirely.

The runtime side has the mirror bug: `DbalProjectionStateStorage` is constructed with a hardwired
`new Reference(DbalConnectionReference::DEFAULT)`
(`packages/PdoEventSourcing/src/Config/ProophProjectingModule.php:274-280`), ignoring
`EventSourcingConfiguration::getConnectionReferenceName()`. Fixing only the CLI would leave setup and runtime
disagreeing.

### Table names

| Feature | Runtime table name comes from | Setup CLI creates | Agree? |
|---|---|---|---|
| Message queue / outbox | `DbalContext::getTableName()` → connection config `table_name`, default `'enqueue'` (`packages/Dbal/src/Connection/DbalConnectionFactory.php:37,65`) | `EnqueueTableManager::DEFAULT_TABLE_NAME`, hardcoded at registration (`DbalPublisherModule.php:62`) | **No** — a non-default `table_name` makes the CLI create the wrong table |
| Deduplication | `DeduplicationInterceptor::DEFAULT_DEDUPLICATION_TABLE`, no override | same constant (`DeduplicationModule.php:72`) | Yes, trivially — neither is configurable |
| Dead letter | `DbalDeadLetterHandler::DEFAULT_DEAD_LETTER_TABLE`, no override | same constant (`DbalDeadLetterModule.php:62`) | Yes, trivially |
| Document store | `DbalDocumentStore::ECOTONE_DOCUMENT_STORE`, no override | same constant (`DbalDocumentStoreModule.php:54`) | Yes, trivially |
| Event streams | `EventSourcingConfiguration::withEventStreamTableName()` (`Api/EventSourcingConfiguration.php:170`) | same value (`EventSourcingModule.php:277`) | Yes |
| Projection state | `ProjectionStateTableManager::DEFAULT_TABLE_NAME`, no override | same constant (`ProophProjectingModule.php:241`) | Yes, trivially. `withProjectionsTableName()` (`:177`) is misleadingly named — it sets the *legacy v1* table |

Every `*TableManager` already takes `$tableName` as its first constructor argument. The gap is that nothing
feeds a user-supplied value into four of them, and that for `enqueue` a user-supplied value already exists in
a place the manager cannot see.

### Two independent auto-init switches

`DbalConfiguration::withAutomaticTableInitialization(bool)`
(`packages/Dbal/Api/DbalConfiguration.php:369-375`, default `true` at `:54`) reaches every
`DbalTableManager` via its `$shouldAutoInitialize` constructor argument. But the event store has a second,
older switch: `EventSourcingConfiguration::withInitializeEventStoreOnStart(bool)`
(`packages/PdoEventSourcing/Api/EventSourcingConfiguration.php:149`) feeds `LazyProophEventStore::$canBeInitialized`
(`:96`), which is checked *first* in `prepareEventStore()` (`:186`), before the per-manager checks at
`:191-192`. Two knobs, overlapping scope, different defaults.

### Deduplication cleanup runs inside the handler transaction

`DeduplicationInterceptor::removeExpiredMessages()` (`:124-137`) creates the table on every invocation
(`:127`) and then loops a batched `DELETE`. It is registered as a service activator plus a console command
(`packages/Dbal/src/Deduplication/DeduplicationModule.php:102-113`,
`ecotone:deduplication:remove-expired-messages`) but nothing schedules it, and nothing prevents it running
inside whatever transaction is already open.

[ecotoneframework/ecotone-dev#438](https://github.com/ecotoneframework/ecotone-dev/issues/438), point 6, is
first-party production evidence:

> "The issue has occurred again, and it got stuck once more with the last log message: `Executing Command
> Handler *\Domain\Entity\Card::close`... There are pending queries to the database: `DELETE FROM
> ecotone_deduplication WHERE handled_at <= $1`"

and the explicit ask:

> "...whether the deletion from `ecotone_deduplication` can be done periodically with a low frequency and
> definitely not within the main transaction."

## Goals / Non-goals

**Goals**
1. No DDL executes inside a message transaction, on any driver, in production configuration.
2. Delete the string-matching implicit-commit workaround and the Postgres-only deduplication special case.
3. One explicit, discoverable way to create Ecotone's tables: a CLI, a programmatic gateway, or SQL dumped
   into the host application's own migration tool.
4. Correct behaviour with more than one connection and with multi-tenancy — setup and runtime targeting the
   same database for the same feature.
5. `EcotoneLite` test bootstraps keep working with zero configuration.
6. Deduplication cleanup outside the handler transaction.

**Non-goals**
- `AutoCreateLevel::CreateOrUpdate` (patching columns on an existing table). No `DbalTableManager` can diff
  today, and two of them have no `Table` object to diff. Deferred; adding an enum case later is
  backward-compatible.
- A schema-version marker table. Its only justification was caching `CreateOrUpdate` diffs.
- Replacing Doctrine Migrations / Laravel migrations. Ecotone offers schema; the host application decides
  when to apply it.
- Unifying `ChannelSetupManager` (`packages/Ecotone/src/Messaging/Channel/Manager/`) with
  `DatabaseSetupManager`. Structurally identical, but a separate refactor.
- Fixing the shared `#[ConsoleParameterOption]` boolean-coercion wart (`normalizeBoolean()`, duplicated in
  four commands). Framework-wide console concern, not database setup.

## Prior art

Researched in full in the report's §3; the load-bearing conclusions only.

| Source | What we take | What we reject |
|---|---|---|
| **Marten** — `AutoCreate` levels (`None`/`CreateOnly`/`CreateOrUpdate`/`All`) + `db-patch`/`db-apply`/`db-assert` | The graduated enum instead of a boolean; the assert verb as a deploy gate; "apply on startup" as a named explicit opt-in | `All` (drop-and-recreate). Ecotone's dead-letter, dedup and document-store tables hold data |
| **Symfony Messenger** `messenger:setup-transports` | "Iterate every configured resource, call `setup()` only on those that opt in via an interface" — maps onto iterating `DbalTableManager[]` | `auto_setup=1` as a DSN default. Also: **no `--dry-run` flag exists** in Symfony 7.4, contrary to a common assumption |
| **Laravel** `make:queue-table` + `vendor:publish --tag=migrations` | Two-step separation: produce the artifact, then apply it separately. This is what `--format=laravel-migration` is | Shipping a framework-chosen migration into the app skeleton |
| **Axon / Spring Boot** `spring.sql.init.mode: embedded` (default) | Auto-init convenient only for ephemeral/dev databases, never for anything that looks like a real connection; `createSchema(tableFactory)` as an explicit method | One global switch over the whole application's `schema.sql` |
| **Doctrine Migrations** `SchemaProviderInterface` | The pluggable "give me your target `Schema`" contract — each feature module already is one | ORM-mapping-as-schema-source; the `schema_filter` regex hack |
| **Prooph `pdo-event-store`** — versioned `.sql` files, no CLI, no runtime auto-create | Validation that zero runtime DDL is production-proven specifically in event sourcing | Static files with no idempotency and no cross-DB abstraction |
| **Flyway / Liquibase** | `baseline` as the upgrade path for a pre-existing 1.x database | The checksum history table — no consumer in 2.0 scope |

## Decision

**Two-level `AutoCreateLevel`, defaulting to `None`, layered on the existing
`DatabaseSetupManager`/`DatabaseSetupModule`/`DatabaseSetupCommand` rather than replacing them.**

```php
namespace Ecotone\Api\Dbal;

enum AutoCreateLevel
{
    case None;       // never issue DDL at runtime; a missing table throws, naming the fix command
    case CreateOnly; // create missing tables; never alter or drop an existing one
}
```

`CreateOnly` is the honest name for what every `createTable()` has always done. No manager has ever patched
columns, so this is a rename of the status quo, not a behaviour change. `None` becomes the default.

### The level is not derived from the environment

Ecotone already has an environment concept — `ServiceConfiguration::withEnvironment()`
(`packages/Ecotone/Api/ServiceConfiguration.php:132`) and `isProductionConfiguration()` (`:381-383`, true for
`'prod'` and `'production'`) — and deriving the default level from it is the obvious-looking move: `None` in
production, `CreateOnly` everywhere else. **This design rejects that**, for one decisive reason: the default
environment is `'dev'` (`ServiceConfiguration::DEFAULT_ENVIRONMENT`, `:20`), and nothing forces an
application to set it. `isProductionConfiguration()` is consulted in exactly two places today
(`MessagingSystemConfiguration.php:691`, `ContainerCacheLayout.php:47`), both about cache layout, so an
application can be running in production with the environment left at `'dev'` and never notice. Binding
schema safety to that string would give the least-configured deployments the most permissive behaviour —
precisely the failure mode this design exists to remove.

The level is therefore explicit: `None` unless the application or the test bootstrap says otherwise. An
application that wants environment-derived behaviour writes it in one line, visibly:

```php
#[ServiceContext]
public function databaseSetup(): DatabaseSetupConfiguration
{
    return DatabaseSetupConfiguration::createWithDefaults()
        ->withAutoCreateLevel($this->isLocalDev ? AutoCreateLevel::CreateOnly : AutoCreateLevel::None);
}
```

Rejected alternatives:

- **Flip the boolean, change nothing else.** Cheapest, but leaves the connection mistargeting, the
  `enqueue` table-name disagreement, the in-transaction cleanup and the missing status/dump verbs.
- **Build a migration engine inside Ecotone** (own files, own history table, `up`/`down`). Duplicates a tool
  every user already has. Integrate instead (§CLI, `--format=`, `EcotoneSchemaProvider`).
- **Dump SQL only; no `--initialize` verb.** Breaks the zero-config promise for tests and quickstarts.

## Public API

### `DatabaseSetupConfiguration` extension object

Lives at `packages/Dbal/Api/DatabaseSetupConfiguration.php`, namespace `Ecotone\Api\Dbal` — new class, so it
goes to its final home immediately rather than moving in a later pass.

```php
final class DatabaseSetupConfiguration
{
    public static function createWithDefaults(): self;              // AutoCreateLevel::None
    public static function createForTesting(): self;                // AutoCreateLevel::CreateOnly

    public function withAutoCreateLevel(AutoCreateLevel $level): self;
    public function withAutoCreateLevelForConnection(string $connectionReferenceName, AutoCreateLevel $level): self;

    public function getAutoCreateLevelFor(string $connectionReferenceName): AutoCreateLevel;
}
```

Application wiring follows the existing `#[ServiceContext]` convention:

```php
use Ecotone\Api\Dbal\AutoCreateLevel;
use Ecotone\Api\Dbal\DatabaseSetupConfiguration;
use Ecotone\Api\Attribute\ServiceContext;

final class EcotoneConfiguration
{
    #[ServiceContext]
    public function databaseSetup(): DatabaseSetupConfiguration
    {
        return DatabaseSetupConfiguration::createWithDefaults()
            ->withAutoCreateLevelForConnection('reporting', AutoCreateLevel::CreateOnly);
    }
}
```

**It subsumes both existing switches.** `DbalConfiguration::withAutomaticTableInitialization(bool)`
(`packages/Dbal/Api/DbalConfiguration.php:369-375`) and
`EventSourcingConfiguration::withInitializeEventStoreOnStart(bool)`
(`packages/PdoEventSourcing/Api/EventSourcingConfiguration.php:149`) both stay as deprecated shims for one
minor version, each mapping `true → CreateOnly`, `false → None` on the connection they govern. When both a
shim and an explicit `DatabaseSetupConfiguration` are present, the explicit object wins and the shim call
raises a deprecation.

### Table-name overrides

```php
DbalConfiguration::createWithDefaults()
    ->withDeduplicationTable('app_deduplication')
    ->withDeadLetterTable('app_dead_letter')
    ->withDocumentStoreTable('app_document_store');
```

Each threads through to the corresponding manager's existing first constructor argument at
`DeduplicationModule.php:72`, `DbalDeadLetterModule.php:62`, `DbalDocumentStoreModule.php:54` — plumbing, no
new mechanism. `EventSourcingConfiguration::withProjectionStateTableName()` is added alongside (the existing
`withProjectionsTableName()` at `:177` keeps its current, legacy-v1 meaning and gains a docblock saying so).

`enqueue` is the exception and is fixed the other way round: rather than adding a
`DbalConfiguration::withEnqueueTable()` that would compete with the connection's `table_name`,
`DbalPublisherModule` reads the resolved connection's `table_name` config and passes **that** into
`EnqueueTableManager` (`DbalPublisherModule.php:62`). One source of truth, and the existing override starts
working with the setup CLI instead of silently disagreeing with it.

### `DatabaseSetupRegistry` — one manager per connection

`DbalTableManagerReference` gains the connection it belongs to:

```php
final class DbalTableManagerReference
{
    public function __construct(
        private string $referenceName,
        private string $connectionReferenceName,
    ) {}

    public function getReferenceName(): string;
    public function getConnectionReferenceName(): string;
}
```

Every registering module already resolves its own connection for runtime wiring — `DeduplicationModule.php:59`
calls `getDeduplicationConnectionReference()` before building the interceptor — so passing the same value
into the reference costs nothing. `EventSourcingModule` passes
`EventSourcingConfiguration::getConnectionReferenceName()`; `ProophProjectingModule` passes the same, which
also fixes the hardwired `DbalConnectionReference::DEFAULT` at `ProophProjectingModule.php:277` — setup and
runtime then agree by construction.

`DatabaseSetupModule::prepare()` groups references by connection and registers one `DatabaseSetupManager` per
distinct connection, behind a registry:

```php
namespace Ecotone\Api\Dbal;

final class DatabaseSetupRegistry
{
    public function forConnection(string $connectionReferenceName): DatabaseSetupManager;
    public function forTenant(string $tenant): DatabaseSetupManager;

    /** @return string[] */
    public function connectionNames(): array;

    /** @return string[] */
    public function tenantNames(): array;
}
```

Tenant connections come from `MultiTenantConfiguration::getTenantToConnectionMapping()`
(`packages/Dbal/Api/MultiTenantConfiguration.php:55-58`) when that extension object is present. That mapping
is a plain constructor array, never mutated, with no setter and no provider abstraction anywhere in
`packages/Dbal/src/MultiTenant/` — so enumerating it at build time is correct for the codebase as it stands.
Its values are `string|ConnectionReference`, so the registry resolves both forms.

`DatabaseSetupManager` keeps its current public surface unchanged (`getFeatureNames()`,
`getCreateSqlStatements()`, `getDropSqlStatements()`, `initializeAll()`, `dropAll()`, `initialize()`,
`drop()`, `getCreateSqlStatementsForFeatures()`, `getDropSqlStatementsForFeatures()`,
`getInitializationStatus()`, `getUsageStatus()` — `packages/Dbal/Api/DatabaseSetupManager.php`), so code that
injects it today is unaffected. The registry is additive.

### Programmatic use

```php
final class DeploymentHealthCheck
{
    public function __construct(private DatabaseSetupManager $databaseSetupManager) {}

    #[QueryHandler('database.checkSetup')]
    public function check(): array
    {
        return $this->databaseSetupManager->getInitializationStatus();
    }
}
```

`DatabaseSetupManager` and `DatabaseSetupRegistry` are registered unconditionally — no nullable service
dependencies. Callers that don't need them don't inject them.

## CLI

The two existing commands keep their names and every current flag. Nothing in a deploy script breaks.

| Command | Status | Purpose | Options |
|---|---|---|---|
| `ecotone:migration:database:setup` | exists, extended | Create tables, or show status, or print SQL | `--feature=` `--initialize` `--sql` `--onlyUsed` (all present) + `--connection=` `--tenant=` (new) |
| `ecotone:migration:database:delete` | exists, extended | Drop tables | `--feature=` `--force` `--onlyUsed` (present) + `--connection=` `--tenant=` (new) |
| `ecotone:migration:database:status` | new | Answer "what is missing?" with no side effects | `--feature=` `--connection=` `--tenant=` `--strict` |
| `ecotone:migration:database:dump-sql` | new | Emit SQL shaped for the host app's migration tool | `--format=raw\|doctrine-migration\|laravel-migration` `--feature=` `--connection=` `--tenant=` `--out=` |

`--connection=` defaults to all connections in the registry; `--tenant=` to all tenants. `--connection=` and
`--tenant=` are mutually exclusive; passing both is a `ConfigurationException`.

**`--strict` throws; it does not return an exit code.** No framework bridge can carry one today — Symfony's
`MessagingEntrypointCommand::execute()` returns a literal `0`
(`packages/Symfony/DependencyInjection/MessagingEntrypointCommand.php:80`), Laravel's generated closure
returns `0` (`packages/Laravel/src/EcotoneProvider.php:165`), and Tempest's generated proxy returns
`ExitCode::SUCCESS` (`packages/Tempest/src/ConsoleCommandProxyGenerator.php:163`). An uncaught exception
produces a non-zero exit in all three, and is a catchable assertion under `EcotoneLite`. So:

```
ecotone:migration:database:status --strict
→ throws ConfigurationException:
  "Database setup incomplete on connection 'Ecotone\Dbal\Connection\DbalConnectionFactory':
   missing tables for features: deduplication (ecotone_deduplication), dead_letter (ecotone_error_messages).
   Run `ecotone:migration:database:setup --initialize` or generate a migration with
   `ecotone:migration:database:dump-sql --format=doctrine-migration`."
```

Without `--strict` it prints the same information as a table and exits normally.

Usage is identical across frameworks — command registration is fully generic. A module only needs a
`#[ConsoleCommand]` method (`Ecotone\Api\Attribute\ConsoleCommand`); Laravel iterates every registered
`ConsoleCommandConfiguration` and builds an Artisan command (`EcotoneProvider.php:126-170`), Symfony does the
equivalent (`EcotoneExtension.php:152`), Tempest generates proxies (`MessagingSystemInitializer.php:76`,
`ConsoleCommandProxyGenerator.php:115-170`). No framework-specific code is written for any command in this
design.

```bash
# Symfony
bin/console ecotone:migration:database:status --strict
bin/console ecotone:migration:database:setup --feature=deduplication,dead_letter --initialize
bin/console ecotone:migration:database:dump-sql --format=doctrine-migration --out=migrations/Version20260101000000.php

# Laravel — same names
php artisan ecotone:migration:database:status --strict
php artisan ecotone:migration:database:dump-sql --format=laravel-migration --out=database/migrations/2026_01_01_000000_ecotone_setup.php

# Ecotone Lite / tests
$ecotone->runConsoleCommand('ecotone:migration:database:setup', ['initialize' => true]);
```

`--format=doctrine-migration` and `--format=laravel-migration` are templating over data
`DatabaseSetupManager::getCreateSqlStatementsForFeatures()` / `getDropSqlStatementsForFeatures()` already
produce — `up()`/`down()` bodies of `$this->addSql(...)`, or a Laravel migration class wrapping
`DB::statement(...)`. No new schema introspection.

`EcotoneSchemaProvider` (a `Doctrine\Migrations\Provider\SchemaProviderInterface`) is added for
`doctrine:migrations:diff` users, built from the same managers. **Documented limitation:** it can contribute a
real `Doctrine\DBAL\Schema\Table` only for the four Schema-API managers. `ProjectionStateTableManager` and
`EventStreamTableManager` hand-write per-platform SQL strings with no `Table` to offer, so
`doctrine:migrations:diff` will not see drift on the event-stream or projection-state tables; `--sql` and
`dump-sql` still cover them. Stated rather than silently omitted.

## Internals

### Runtime behaviour by level

The `$shouldAutoInitialize` boolean each manager carries becomes an `AutoCreateLevel`, resolved per
connection at container-build time. `shouldBeInitializedAutomatically()` stays on the interface and returns
`$level === AutoCreateLevel::CreateOnly`, so every existing call site
(`DbalInboundChannelAdapter.php:35-39`, `DbalOutboundChannelAdapter.php:50-54`,
`DbalDeadLetterHandler.php:207-214`, `DbalDocumentStore.php:195-202`, `DeduplicationInterceptor.php:166-175`,
`DbalProjectionStateStorage.php:199-213`, `LazyProophEventStore.php:186,191-192`) keeps working unmodified.

### Detecting a missing table under `None` — catch and rethrow

No pre-check runs on the happy path. Instead, the call sites that used to auto-create catch DBAL's
cross-driver `TableNotFoundException` and rethrow with an actionable message:

```php
try {
    $select = $connection->createQueryBuilder()
        ->select('message_id')
        ->from($this->getTableName())
        // ...
        ->executeQuery()
        ->fetchAssociative();
} catch (TableNotFoundException $exception) {
    throw ConfigurationException::create(sprintf(
        "Deduplication table '%s' does not exist. Run `ecotone:migration:database:setup --feature=deduplication --initialize`,"
        . ' or generate a migration with `ecotone:migration:database:dump-sql --feature=deduplication`.',
        $this->getTableName()
    ), previous: $exception);
}
```

Rejected alternative — an existence pre-check per connection per process, cached like
`DeduplicationInterceptor::$initialized` (`:37`): costs a round-trip, and the cache masks a table dropped
after the check until the process restarts. Catch-and-rethrow costs nothing when the table exists (the
overwhelming majority of calls) and has no TOCTOU window.

This is not speculative about the current failure mode:
`packages/PdoEventSourcing/tests/Projecting/ProjectionStateTableInitializationTest.php:49-68` already asserts
that a raw `Doctrine\DBAL\Exception\TableNotFoundException` reaches user code with auto-init disabled.
`TableNotFoundException` is converted from platform SQLSTATE codes by DBAL's `ExceptionConverter` for every
supported driver, so one `catch` is correct on MySQL, MariaDB, PostgreSQL and SQLite alike — unlike the
platform-scoped string-matching `ImplicitCommit` it replaces.

### Idempotent DDL

Two changes, both needed:

1. **The emitted SQL becomes re-runnable.** For the four Schema-API managers, `getCreateTableSql()` prefixes
   `CREATE TABLE IF NOT EXISTS` — built by hand, since `AbstractPlatform::getCreateTableSQL()` takes no such
   flag, mirroring what `ProjectionStateTableManager` already does. This matters most for `--sql` /
   `dump-sql`: SQL a user pastes into a migration must be safe to re-run.
2. **`createTable()` catches `TableAlreadyExistsException` and treats it as success.** Cheap, covers the
   four managers without depending on platform support for the prefix, and closes the check-then-create race
   for the in-process path.

For the CLI's own `--initialize`, additionally take an advisory lock (`pg_advisory_lock` / MySQL `GET_LOCK`)
around the per-connection `initializeAll()`, so two concurrent invocations in a deploy pipeline serialise
rather than race. This does not help the SQL-dump case — hence (1) as well.

### Transaction semantics after the change

With `AutoCreateLevel::None` in production and no DDL reachable inside a message:

1. `DbalTransactionInterceptor::transactional()` drops the `ImplicitCommit` branch (`:105-125`). A failing
   `commit()` becomes a hard error again. **Gated on Group D** — see §Implementation plan step 12.
2. `ImplicitCommit.php` is deleted.
3. `DeduplicationInterceptor::deduplicate()` drops **only the platform half** of the condition at `:101`:

   ```php
   $insertBeforeProceeding = $connection->isTransactionActive();
   ```

   The `isTransactionActive()` check must stay. It is false for two independent reasons: the platform is not
   Postgres, *or* there is no transaction at all — deduplication used without `DbalTransaction`, or an
   endpoint carrying `#[WithoutDatabaseTransaction]`. Removing only the first is correct. Removing both would
   mean inserting the deduplication row with nothing to roll it back: a handler that then throws leaves the
   message permanently marked handled and the retry silently swallowed. That is message loss, not a missed
   optimisation.

   `Connection::isTransactionActive()` returns `$this->transactionNestingLevel > 0` — an in-memory counter
   local to that `Connection` instance, incremented only by `beginTransaction()` on that same object. So if
   an external transaction manager (Doctrine ORM's `EntityManager`) uses a *different* connection reference,
   `isTransactionActive()` on deduplication's connection is false even though a business transaction is open
   elsewhere, and the row commits independently of a later external rollback. Narrower than the
   no-transaction case — the message is still processed correctly once, it just cannot be retried — but real,
   and documented rather than silently accepted.

   `TransactionStatusTracker` (`packages/Ecotone/src/Modelling/Config/DatabaseTransaction/TransactionStatusTracker.php:10-31`),
   now injected into `DbalTransactionInterceptor` (`:38`) and marked around `proceed()` (`:93-96`,
   `:144-148`), answers "did Ecotone start a transaction at all" but is connection-agnostic, so it cannot
   replace the per-connection check. It is the right thing to consult for diagnostics and for the deprecation
   warning when deduplication runs with no Ecotone-managed transaction.

4. `DbalContext::createDataBaseTable()` (`packages/Dbal/src/Connection/DbalContext.php:228-260`) — a public,
   ungated duplicate of `EnqueueTableManager::buildTableSchema()`, on no runtime path, called only by
   `packages/Dbal/tests/Integration/ReconnectTest.php:37,64` — is deleted and the test switched to the
   manager. One schema definition per table.

Net result: one transaction per message on every driver, opened by `DbalTransactionInterceptor` and closed by
it, with no framework-issued DDL inside it.

### Deduplication cleanup outside the handler transaction

The mechanism already exists and is CLI-triggerable. What is missing is a scheduled trigger and transaction
isolation.

`DeduplicationModule::prepare()` registers a poller-driven inbound channel adapter per deduplication-enabled
connection, reusing the existing `removeExpiredMessages` service activator and the
`ecotone.deduplication.removeExpiredMessages` channel (`DeduplicationModule.php:102-113`). The in-repo
precedent is exact — `DbalBatchForwardingModule.php:85-94`:

```php
$messagingConfiguration->registerConsumer(
    InboundChannelAdapterBuilder::create(
        NullableMessageChannel::CHANNEL_NAME,
        DeduplicationInterceptor::class,
        $interfaceToCallRegistry->getFor(DeduplicationInterceptor::class, 'removeExpiredMessages'),
    )
        ->withEndpointId('ecotone.deduplication.cleanup')
        ->withEndpointAnnotations([new AttributeDefinition(WithoutDatabaseTransaction::class)]),
);
```

`WithoutDatabaseTransaction` is what takes the `DELETE` out of any handler transaction —
`DbalTransactionInterceptor::transactional()` returns `$methodInvocation->proceed()` immediately when it is
present (`:44-46`). The endpoint is opt-in operationally: it runs when an operator starts
`ecotone:run ecotone.deduplication.cleanup`, exactly like any other consumer. `removeExpiredMessages()` also
drops its `createDataBaseTable()` call at `:127` — the cleanup job has no business issuing DDL.

Operators who prefer their own scheduler keep `ecotone:deduplication:remove-expired-messages` unchanged;
both paths call the same service activator. This is what #438 asked for.

Rejected: probabilistic in-handler cleanup with `SKIP LOCKED`. It still runs inside the business
transaction, so it shrinks the blast radius without addressing the coupling #438 identifies — and
`SKIP LOCKED` semantics differ across MySQL 8, MariaDB and PostgreSQL.

### Tests and `EcotoneLite`

`EcotoneLite::bootstrapFlowTestingWithEventStore()` injects `DbalConfiguration::createForTesting()` when no
`DbalConfiguration` extension object is present (`packages/Ecotone/src/Lite/EcotoneLite.php:127-130`). Plain
`bootstrapFlowTesting()` (`:73-93`) injects nothing, so a DBAL-backed test that supplies no configuration
gets `createWithDefaults()`. Both currently end at auto-init `true`, by different routes.

Both routes get `DatabaseSetupConfiguration::createForTesting()` (`AutoCreateLevel::CreateOnly`) when no
`DatabaseSetupConfiguration` is supplied. Observed test behaviour is unchanged.

**How "test connection" is detected: by which bootstrap was called, not by inspecting the connection.** This
keeps the existing mental model — test bootstrap means auto, `#[ServiceContext]` means explicit — and it is
honest about a real gap: pointing a test bootstrap at a live PostgreSQL instance today still auto-creates,
and will continue to. The alternative, branching on `SqlitePlatform`, is worse: it would silently change
behaviour for anyone testing against a real database, and SQLite is a perfectly legitimate production
target for four of the features. The gap is documented rather than papered over, and the escape hatch is
one line:

```php
protected function setUp(): void
{
    $this->ecotone = EcotoneLite::bootstrapFlowTestingWithEventStore(
        [OrderHandler::class],
        runForProductionEventStore: true,
        configuration: ServiceConfiguration::createWithDefaults()
            ->withExtensionObjects([DatabaseSetupConfiguration::createWithDefaults()]),  // None
    );
    $this->ecotone->getGateway(DatabaseSetupManager::class)->initializeAll();
}
```

## Table inventory

Every table Ecotone can create, as of `bcf4efc0`.

| Table (default name) | Feature name | `DbalTableManager` | Runtime creation call site | Gated today |
|---|---|---|---|---|
| `enqueue` | `message_queue` (also backs the outbox — one manager, one table, discriminated by a `queue` column) | `EnqueueTableManager` — `packages/Dbal/src/Database/EnqueueTableManager.php` | `DbalOutboundChannelAdapter::initialize()` (`:45-56`), `DbalInboundChannelAdapter::initialize()` (`:30-40`) — first send / first poll | Yes |
| `ecotone_error_messages` | `dead_letter` | `DeadLetterTableManager` — `packages/Dbal/src/Database/DeadLetterTableManager.php` | `DbalDeadLetterHandler::createDataBaseTable()` (`:207-214`), via `initialize()` (`:237-243`) | Yes |
| `ecotone_deduplication` | `deduplication` | `DeduplicationTableManager` — `packages/Dbal/src/Database/DeduplicationTableManager.php` | `DeduplicationInterceptor::deduplicate()` (`:63-66`) and `removeExpiredMessages()` (`:127`) | Yes |
| `ecotone_document_store` | `document_store` (also backs consumer-position tracking, collection `ecotone_consumer_positions`, `packages/Dbal/src/Consumer/DbalConsumerPositionTracker.php:16`) | `DocumentStoreTableManager` — `packages/Dbal/src/Database/DocumentStoreTableManager.php` | `DbalDocumentStore::createDataBaseTable()` (`:195-202`), from `:53`, `:86`, `:97` | Yes |
| `event_streams` (`LazyProophEventStore::DEFAULT_STREAM_TABLE`, overridable) | `event_streams` | `EventStreamTableManager` — `packages/PdoEventSourcing/src/Database/EventStreamTableManager.php` (docblock `licence Enterprise`, `:17`) | `LazyProophEventStore::prepareEventStore()` (`:199-201`) | Yes, plus `withInitializeEventStoreOnStart()` (`:186`) |
| `projections` (`DEFAULT_PROJECTIONS_TABLE`, overridable) | `projections_v1` | `LegacyProjectionsTableManager` — same directory | `LazyProophEventStore::prepareEventStore()` (`:202-204`) | Yes. Deleted with Group B |
| `ecotone_projection_state` | `projection_state` | `ProjectionStateTableManager` — `packages/PdoEventSourcing/src/Database/ProjectionStateTableManager.php` | `DbalProjectionStateStorage::createSchema()` (`:199-213`) | Yes |
| `_<sha1(streamName)>`, one per stream | **none** | **none, and none is possible** — names are runtime-derived | `LazyProophEventStore::appendTo()` (`:164-175`) → Prooph `createSchemaFor()` | **No** |

### Driver support matrix

| Feature | PostgreSQL | MySQL 8 | MariaDB 11.4 | SQLite | DDL source |
|---|---|---|---|---|---|
| `message_queue` / outbox | Yes | Yes | Yes | Yes | Doctrine Schema API (`EnqueueTableManager::buildTableSchema()` `:71-95`) |
| `dead_letter` | Yes | Yes | Yes | Yes | Doctrine Schema API (`:94-106`) |
| `deduplication` | Yes | Yes | Yes | Yes | Doctrine Schema API (`:90-103`) |
| `document_store` / consumer positions | Yes | Yes | Yes | Yes | Doctrine Schema API (`:70-83`) |
| `projection_state` | Yes | Yes | Yes (MySQL branch) | **No** | Hand-written; branches `AbstractMySQLPlatform` vs. *else Postgres* (`:47-54`) — SQLite receives PostgreSQL `JSON` DDL |
| `event_streams` | Yes | Yes | Yes | **No** | Hand-written; branches Postgres → MariaDB → *else MySQL* (`:45-56`) — SQLite receives backticked `ENGINE=InnoDB` DDL |
| `projections_v1` | Yes | Yes | Yes | **No** | Same shape (`LegacyProjectionsTableManager.php:45-56`) |
| per-stream tables | Yes | Yes | Yes | Untested | Prooph persistence strategies |

SQLite is a genuinely supported and exercised connection for the four DBAL-native features
(`docker-compose.yml:17,41` set `SQLITE_DATABASE_DSN`; `DbalConnectionFactory` maps `sqlite`/`sqlite3`/
`sqlite+pdo` to `pdo_sqlite` at `:140-142`; `DbalMessagingTestCase::isUsingSqlite()` `:63-66`). It is **not**
supported for event streams or projection state: zero SQLite references exist in `packages/PdoEventSourcing`,
and both managers actively mistarget it. Stated explicitly rather than left to be discovered via a
mistargeted `CREATE TABLE`. Adding a SQLite branch to the *old* `EventStreamTableManager` is not in scope —
Group D rewrites that layer.

## Edge cases

| Case | Behaviour |
|---|---|
| **Concurrent first deploy** — two instances boot against a fresh database at `CreateOnly` | `CREATE TABLE IF NOT EXISTS` makes the DDL itself safe on every platform that supports the prefix; `TableAlreadyExistsException` is caught and treated as success everywhere else. Both nested check-then-create layers (`DatabaseSetupManager::initializeAll()` `:85-91` and each `createTable()`) remain, but neither is load-bearing once the DDL is idempotent |
| **Two concurrent `--initialize` in a pipeline** | An advisory lock (`pg_advisory_lock` / `GET_LOCK`) around the per-connection `initializeAll()` serialises them. Does not help dumped SQL, which is why the DDL is fixed too |
| **Failed mid-migration** — `--initialize` creates 3 of 7 tables, process dies | Re-running is safe once the DDL is idempotent. No transaction wraps the loop and none should — DDL is not transactional on MySQL. "Each call is independent" and "each call is safely re-runnable" are different claims; only the fixed DDL gives the second |
| **Ordering between features** | None needed. Ecotone's tables have no foreign keys between them |
| **Missing table under `None`** | `TableNotFoundException` is caught at the call site and rethrown as a `ConfigurationException` naming the table and the exact command. Zero overhead when the table exists |
| **SQLite** | Supported for `message_queue`, `dead_letter`, `deduplication`, `document_store`, consumer positions. Not supported for `event_streams`, `projection_state`, `projections_v1` — `status` reports those features as unsupported on a SQLite connection rather than emitting DDL that cannot execute |
| **Read-only connection** | `status` and `dump-sql` work — both are pure reads (`isInitialized()` is a schema-manager introspection, `getCreateTableSql()` needs only the platform). `--initialize` and `--force` fail with the driver's permission error, wrapped in a `ConfigurationException` naming the connection |
| **Table-name overrides** | Deduplication / dead letter / document store / projection state gain `with*Table()` methods threading into the existing constructor argument. `enqueue` is fixed by having `EnqueueTableManager` read the connection's own `table_name` config instead of the hardcoded constant — closing an existing disagreement between setup and runtime rather than adding a competing knob |
| **Multiple connections** | One `DatabaseSetupManager` per connection actually in use, from the connection-aware `DbalTableManagerReference`. Not per entry in `defaultConnectionReferenceNames` — that list is a fallback and need not contain every connection a feature resolved to (`DbalConfiguration.php:89-104`) |
| **Multi-tenancy** | One manager per tenant connection from `getTenantToConnectionMapping()`. Static by construction today; if a future release adds dynamic tenant resolution, `--tenant=all` must become an explicit list or gain a `TenantProvider`. Flagged, not currently a bug |
| **Enterprise vs OSS** | The setup CLI is open-core — operational tooling, not a monetizable feature, matching `DatabaseSetupModule`'s `licence Apache-2.0`. `--tenant=` *enumeration* for `status`/`dump-sql` stays ungated (reading a configuration array invokes no gated runtime behaviour, and "visible but disabled" beats hiding); `--initialize`/`--force` against a tenant connection is gated, consistent with multi-tenancy being Enterprise. Unresolved: `EventStreamTableManager` is `licence Enterprise` (`:17`) while the sibling `ProjectionStateTableManager` is `licence Apache-2.0` (`:18`) — see Open Questions |
| **Deduplication without an active transaction** | `#[WithoutDatabaseTransaction]` endpoints, or a connection the current `DbalTransaction` doesn't cover: the row is inserted *after* `proceed()`, exactly as today. Deduplication offers no atomicity with the handler's side effects there — unchanged from 1.x, but now stated |
| **Non-DBAL transaction manager on a different connection** | Doctrine ORM demarcating its own transaction on a different connection reference: `isTransactionActive()` on deduplication's connection is false, so the row commits independently of a later ORM rollback. Documented limitation |
| **Prooph per-stream tables** | Not creatable by any CLI and not gated by any level. They remain the one runtime DDL path in 2.0 until Group D lands; `ImplicitCommit` stays until then |
| **Existing 1.x production database** | Tables already exist, so `None` is a no-op at deploy. Run `ecotone:migration:database:status` once after upgrading to confirm before flipping |
| **Very large tables** | `CreateOnly` never touches an existing table, so no `ALTER` and no lock. The risk arrives with `CreateOrUpdate`, which is deferred |
| **Replay & rebuild** | Group B/D territory. This design's only contact is ensuring the event-stream and projection-state tables exist before a rebuild — a `status --strict` pre-flight step |

## Migration / upgrade notes

Replaces `upgrade-2.0.md` §8 (currently lines 222-238, marked "Planned — not implemented yet"). Note that
§8's current text names a `dump-sql` command and a `DatabaseSetupManager::setup()` method — the command name
is adopted below, the method does not exist and the correct call is `initializeAll()`. The status line at the
top of the guide (`upgrade-2.0.md:9-11`) must drop §8 from the "planned" list when this ships.

> ## 8. Database tables are no longer created on the fly
>
> **Before:** `DbalTransactionInterceptor` and `DeduplicationInterceptor` created `ecotone_deduplication`,
> `ecotone_error_messages`, `enqueue` and the rest during the first message. On MySQL that DDL committed the
> surrounding transaction implicitly, and Ecotone swallowed the resulting commit failure by string-matching
> the driver's error message. PostgreSQL got a special-case branch so deduplication could still guarantee
> that concurrent handling of the same message fails fast.
>
> **Now:** Tables are created through the CLI, through the `DatabaseSetupManager` gateway, or by your own
> migration tool — never during message handling. One transaction wraps the whole message on every driver.
> Deduplication's concurrency guarantee now works on MySQL and MariaDB too. Deduplication cleanup runs on its
> own endpoint, outside your handler's transaction, so it no longer holds row locks while your handler runs.
>
> Behaviour is controlled by `AutoCreateLevel`:
>
> - `AutoCreateLevel::None` — the new default. Ecotone never issues DDL; a missing table raises a
>   `ConfigurationException` naming the table and the exact command to run.
> - `AutoCreateLevel::CreateOnly` — create missing tables, never alter an existing one. This is what 1.x
>   always did, under a new name. `EcotoneLite` test bootstraps use it, so in-memory and SQLite tests are
>   unaffected.
>
> `AutoCreateLevel::CreateOrUpdate` (patching columns on an existing table) is not part of 2.0.
>
> **How to adapt:**
>
> ```php
> // 1.x — nothing to configure; tables appeared on first use.
>
> // 2.0 — None is the default. Opt back into auto-create only where you want it:
> use Ecotone\Api\Dbal\AutoCreateLevel;
> use Ecotone\Api\Dbal\DatabaseSetupConfiguration;
> use Ecotone\Api\Attribute\ServiceContext;
>
> final class EcotoneConfiguration
> {
>     #[ServiceContext]
>     public function databaseSetup(): DatabaseSetupConfiguration
>     {
>         return DatabaseSetupConfiguration::createWithDefaults()
>             ->withAutoCreateLevelForConnection('local', AutoCreateLevel::CreateOnly);
>     }
> }
> ```
>
> - Add `ecotone:migration:database:setup --initialize` to your deploy pipeline (or `--feature=deduplication,dead_letter`
>   for a subset). Use `--connection=` per connection and `--tenant=` per tenant if you use multi-tenancy;
>   with neither flag it covers all of them.
> - Or generate a migration for your existing tool:
>   `ecotone:migration:database:dump-sql --format=doctrine-migration` / `--format=laravel-migration`,
>   optionally with `--out=<path>`.
> - Gate your deploy on `ecotone:migration:database:status --strict`, which throws (and therefore exits
>   non-zero) if anything used is missing.
> - For integration tests against a real database, call
>   `$ecotone->getGateway(DatabaseSetupManager::class)->initializeAll();` once in `setUp()`. `EcotoneLite`
>   in-memory and SQLite bootstraps do not need this.
> - Deduplication cleanup: run the endpoint with `ecotone:run ecotone.deduplication.cleanup`, or keep using
>   `ecotone:deduplication:remove-expired-messages` from your own cron / Kubernetes CronJob. Both call the
>   same code; neither runs inside your handler's transaction any more.
> - `DbalConfiguration::withAutomaticTableInitialization()` and
>   `EventSourcingConfiguration::withInitializeEventStoreOnStart()` still work for one minor version and log a
>   deprecation. `true` maps to `CreateOnly`, `false` to `None`.
> - Table names that were hardcoded are now configurable: `DbalConfiguration::withDeduplicationTable()`,
>   `withDeadLetterTable()`, `withDocumentStoreTable()`, and
>   `EventSourcingConfiguration::withProjectionStateTableName()`. If you already set `table_name` on your DBAL
>   connection, the setup CLI now honours it — in 1.x it created `enqueue` regardless.
> - If you use more than one connection, or event sourcing on a dedicated connection: setup now targets the
>   connection each feature is actually configured for. In 1.x every table was created against the DBAL
>   default connection, so check `ecotone:migration:database:status` before and after upgrading.
> - Remove any application code that relied on the implicit commit (for example, DDL issued from inside a
>   handler on MySQL).
> - **Event sourcing on Prooph:** per-stream tables are still created on first append and cannot be
>   pre-created — their names depend on runtime stream names. On MySQL/MariaDB that DDL still commits the
>   surrounding transaction. This disappears with the new event store (§4), which uses a single event log.
>
> **Existing 1.x databases:** your tables already exist, so `None` is a no-op at deploy time. Run
> `ecotone:migration:database:status` once after upgrading to confirm nothing is missing.

Mechanical hints for the upgrade tooling:

- `DbalConfiguration::withAutomaticTableInitialization(false)` →
  `DatabaseSetupConfiguration::createWithDefaults()->withAutoCreateLevel(AutoCreateLevel::None)`;
  `(true)` → `AutoCreateLevel::CreateOnly`. Deprecated shim, one minor version.
- No Rector rule for command names — `ecotone:migration:database:setup` / `:delete` are unchanged; only new
  flags and two new command names are added.
- `DatabaseSetupConfiguration` and `AutoCreateLevel` are new classes placed directly in `Ecotone\Api\Dbal`,
  so they never need the Group H rename.

## Implementation plan

Ordered for single focused sessions; each step depends only on earlier ones.

**Tests run inside the docker container and must be run sequentially, never in parallel across packages** —
the compose services are shared, and concurrent package runs produce random cross-package failures. Two
existing suites already cover this surface and every step below extends them rather than starting fresh:
`packages/Dbal/tests/Integration/DatabaseInitializationTest.php` (14 tests over the CLI, including
`test_tables_are_auto_created_when_auto_initialization_enabled` `:126-140` and
`test_tables_are_not_auto_created_when_auto_initialization_disabled` `:142-159`) and
`packages/PdoEventSourcing/tests/Projecting/ProjectionStateTableInitializationTest.php` (3 tests).

1. **Make every `createTable()` idempotent.** Prefix `CREATE TABLE IF NOT EXISTS` in the four Schema-API
   managers' `getCreateTableSql()`, and catch `TableAlreadyExistsException` around each `createTable()`.
   A pure bug fix, independent of everything else, and a prerequisite for idempotent dumps, safe concurrent
   `--initialize` and safe crash-and-retry.
   *Tests:* per-manager unit tests asserting the emitted SQL contains `IF NOT EXISTS`; an integration test
   calling `createTable()` twice in a row on each of Postgres, MySQL and SQLite; a test that pre-creating the
   table by hand and then calling `createTable()` does not throw.
2. **Collapse the duplicate enqueue schema.** Delete `DbalContext::createDataBaseTable()`
   (`packages/Dbal/src/Connection/DbalContext.php:228-260`) and point
   `packages/Dbal/tests/Integration/ReconnectTest.php:37,64` at `EnqueueTableManager` instead.
   *Tests:* `ReconnectTest` continues to pass unchanged in behaviour.
3. **Add `AutoCreateLevel` and `DatabaseSetupConfiguration`** in `packages/Dbal/Api/`. Additive classes, no
   behaviour change yet.
   *Tests:* unit tests for per-connection resolution and the default.
4. **Wire the level through every `shouldBeInitializedAutomatically()` site**, keeping the method signature.
   Turn `DbalConfiguration::withAutomaticTableInitialization()` and
   `EventSourcingConfiguration::withInitializeEventStoreOnStart()` into deprecating shims that set the new
   configuration. Default is still `CreateOnly` at this step — the flip is step 11.
   *Tests:* extend `DatabaseInitializationTest` with level-based equivalents of its two auto-init tests,
   asserting the shims produce identical behaviour to the explicit configuration.
5. **Catch-and-rethrow for missing tables.** Wrap the first query at each site that used to auto-create, so
   `None` produces a `ConfigurationException` naming the table and the fix command.
   *Tests:* one per feature asserting the message text under `None`; rewrite
   `ProjectionStateTableInitializationTest:49-68` to expect `ConfigurationException` (with
   `TableNotFoundException` as `previous`) instead of the raw DBAL exception.
6. **Table-name overrides.** Add `withDeduplicationTable()`, `withDeadLetterTable()`,
   `withDocumentStoreTable()` and `EventSourcingConfiguration::withProjectionStateTableName()`; make
   `EnqueueTableManager` receive the resolved connection's `table_name` instead of the hardcoded constant;
   docblock `withProjectionsTableName()` as legacy-v1-only.
   *Tests:* per-feature integration test that a custom name is the table actually created *and* the table
   actually queried; a regression test that a connection configured with a non-default `table_name` has its
   `enqueue` table created under that name.
7. **Add `connectionReferenceName` to `DbalTableManagerReference`** and update all six constructing modules
   (`DeduplicationModule.php:122`, `DbalDeadLetterModule.php:92`, `DbalPublisherModule.php:135`,
   `DbalDocumentStoreModule.php:221`, `EventSourcingModule.php:339-340`, `ProophProjectingModule.php:387`) to
   pass the connection each already resolves. Fix `DbalProjectionStateStorage`'s hardwired
   `DbalConnectionReference::DEFAULT` (`ProophProjectingModule.php:277`) in the same pass so setup and runtime
   agree.
   *Tests:* a two-connection integration test asserting each feature's table lands on its configured
   connection, and that projection state is written to the event-sourcing connection.
8. **`DatabaseSetupRegistry`.** Replace the single-manager registration in `DatabaseSetupModule` with one
   manager per distinct connection plus one per tenant connection. Add `--connection=` and `--tenant=` to the
   two existing commands, resolving against the registry.
   *Tests:* extend `DatabaseInitializationTest` with `--connection=` scoping; a multi-tenant test over the two
   tenant connections `DbalMessagingTestCase` already provides (`:87`).
9. **`ecotone:migration:database:status`.** Thin command over `getInitializationStatus()` / `getUsageStatus()`,
   with `--strict` throwing a `ConfigurationException` listing every used-and-uninitialised feature.
   *Tests:* status output shape; `--strict` throws when a table is missing and does not when all are present;
   one test per framework bridge asserting the thrown exception produces a non-zero exit.
10. **`ecotone:migration:database:dump-sql`** with `--format=raw|doctrine-migration|laravel-migration` and
    `--out=`. Templating over statements the manager already produces.
    *Tests:* generated Doctrine and Laravel files are syntactically valid PHP and, when executed against a
    clean database, produce the same schema as `--initialize`.
11. **Flip the default to `AutoCreateLevel::None`** and update the affected test suites.
    `DbalMessagingTestCase` and the DBAL/PdoEventSourcing integration suites call `initializeAll()` explicitly
    instead of relying on auto-create inside the test body; `EcotoneLite::bootstrapFlowTesting()` and
    `bootstrapFlowTestingWithEventStore()` inject `DatabaseSetupConfiguration::createForTesting()`. This is the
    widest-reaching step; do it once the API it exercises is stable.
    *Tests:* the whole DBAL and PdoEventSourcing suites, run sequentially in docker.
12. **Remove the implicit-commit workaround. Blocked on Group D.** Delete `ImplicitCommit.php` and the branch
    at `DbalTransactionInterceptor.php:105-125`; drop **only** `&& $connection->getDatabasePlatform() instanceof
    PostgreSQLPlatform` from `DeduplicationInterceptor.php:101`, keeping `isTransactionActive()`.
    Doing this before Group D's event store lands reintroduces the original MySQL crash for every
    event-sourcing user, because Prooph's per-stream `CREATE TABLE` still runs inside the message
    transaction, ungated by any level.
    *Tests:* a MySQL integration test asserting that a handler which throws after a write rolls that write
    back (impossible today); a MySQL deduplication test asserting two concurrent consumers of the same
    message collide on the primary key — the guarantee Postgres has had all along.
13. **Scheduled deduplication cleanup.** Register the poller-driven endpoint in `DeduplicationModule` with
    `#[WithoutDatabaseTransaction]`; drop `createDataBaseTable()` from `removeExpiredMessages()` (`:127`).
    Keep `ecotone:deduplication:remove-expired-messages` as an alternative trigger.
    *Tests:* the cleanup deletes expired rows and leaves fresh ones; it runs with no transaction open; a
    handler running concurrently with cleanup is not blocked (the #438 scenario).
14. **`EcotoneSchemaProvider`** for `doctrine:migrations:diff`, built from the four Schema-API managers, with
    the event-stream / projection-state limitation documented rather than silently omitted.
    *Tests:* `diff` against a database missing an Ecotone table produces a migration containing it.
15. **Docs.** Replace `upgrade-2.0.md` §8 with the text above and remove §8 from the "planned" list at
    `upgrade-2.0.md:9-11`; update the `ecotone-testing` skill for the explicit-setup call in integration
    tests; document the "Manager + Command + `#[ConsoleCommand]`" shape in `AGENTS.md` so the next feature
    module follows `DatabaseSetupCommand`/`ChannelSetupCommand` rather than inventing a third variant.

Deliberately out of this plan: `AutoCreateLevel::CreateOrUpdate` and its `Comparator`-based diffing; the
`ecotone_schema_version` marker table; unifying `ChannelSetupManager` with `DatabaseSetupManager`; fixing the
shared `#[ConsoleParameterOption]` boolean coercion.

## Open questions for the maintainer

1. **Is `AutoCreateLevel::None` the right default for *every* non-test bootstrap, including
   `ServiceConfiguration::createWithDefaults()` built by hand outside a framework?** Recommendation: yes —
   "safe by default" should be literal, and the test bootstraps override it explicitly. The cost is that a
   quickstart-style script constructing Ecotone by hand now needs one extra call or one CLI invocation. The
   alternative — deriving the default from `ServiceConfiguration::isProductionConfiguration()` — is argued
   against in §Decision, but it is your call and it is a real ergonomic trade: `dev` is the default
   environment string, so anyone who has never set an environment would get `CreateOnly` in production.
2. **`EventStreamTableManager` is `licence Enterprise` (`:17`) while `ProjectionStateTableManager` next to it
   is `licence Apache-2.0` (`:18`).** This decides whether
   `ecotone:migration:database:setup --feature=event_streams` is licence-gated. Flagging rather than
   guessing — inventing the answer risks shipping the wrong gate on a licensing-sensitive path. The same
   question is open in the Group D spec.
3. **Should `EventSourcingConfiguration::withInitializeEventStoreOnStart()` be deprecated into
   `AutoCreateLevel`, or kept as a genuinely separate concern?** It predates the table-manager abstraction and
   gates `prepareEventStore()` as a whole, not just table creation. Recommendation: deprecate into
   `AutoCreateLevel` for the table-creation half and keep the method as a shim — but if it is load-bearing for
   something else in the Prooph lifecycle, say so before step 4.
4. **Should `--strict` be the default for `status`,** so `ecotone:migration:database:status` fails a
   deploy unless you explicitly ask for the report? Recommendation: no — a status command that throws by
   default is surprising, and `--strict` is one flag in a pipeline. Confirm.
5. **How far should Group F2 go before Group D lands?** Steps 1-11 and 13-15 are independent; step 12 is not.
   Recommendation: ship 1-11 and 13-15 as F2, and move step 12 into Group D's PR, where the MySQL rollback
   test can be written against the new store. That makes `ImplicitCommit`'s deletion Group D's responsibility
   and needs both specs to agree.
6. **Is the deduplication cleanup endpoint acceptable as an opt-in consumer** (`ecotone:run
   ecotone.deduplication.cleanup`), or should the framework try harder — for example, warning at bootstrap
   when deduplication is enabled and no scheduler is configured? Recommendation: opt-in with a documented
   default schedule, no warning. Deployments already run consumers; a bootstrap warning that cannot verify an
   external cron would fire on correctly-configured systems.
