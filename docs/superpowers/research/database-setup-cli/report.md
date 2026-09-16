# Topic 3 — `database-setup-cli` (Release Group F2)

Design for removing implicit commits and on-the-fly table creation from Ecotone's DBAL runtime, and for
finishing the CLI + programmatic setup API that replaces it.

**Headline finding, stated up front because it changes the shape of every section below:** most of the
target infrastructure already exists on this branch. `DatabaseSetupManager`, `DatabaseSetupModule`, the
`DbalTableManager` interface, and two working console commands (`ecotone:migration:database:setup`,
`ecotone:migration:database:delete`) are already implemented, already collect every feature's table
manager as an extension object, and are already driven by a single `DbalConfiguration::withAutomaticTableInitialization(bool)`
flag that every runtime call site consults before creating a table. This is **not** a "build a CLI from
scratch" task. It is: (1) flip the default of that flag, (2) remove the implicit-commit workaround code that
only exists because of on-the-fly DDL, (3) close real gaps (per-connection/per-tenant setup — which needs a
manager→connection mapping that doesn't exist yet, table-name overrides for 4 of 7 features, DDL that isn't
actually idempotent for 4 of 6 table managers, a dump-SQL-for-migrations UX, a scheduled deduplication
cleanup), and (4) decide product-level questions (a 2-level `AutoCreateLevel` policy for 2.0 with
column-patching deferred, licence gating of event-store setup).

**Revision note (Round 1 challenge, addressed in place below):** eight factual/design corrections were made
after a code-level challenge round: table DDL is not uniformly idempotent (§6.1, §7), SQLite is not a
supported target for event streams/projection state (§7's new SQLite row), the deduplication
insert-before-`proceed()` fix must keep the `isTransactionActive()` check and only drop the platform check to
avoid message loss (§6.2), `AutoCreateLevel::CreateOrUpdate` needs new per-manager capability that isn't cheap
and is deferred out of 2.0 scope (§6.4, with the schema-version marker table deferred alongside it, §6.5), the
single-connection limitation is a missing manager→connection mapping rather than a wrong array index (§5.5),
multi-tenant enumeration was re-verified as genuinely static (§5.6), missing-table detection under
`AutoCreateLevel::None` is now designed explicitly via catch-and-rethrow (§6.2a), and several smaller
corrections (CLI command names are not renamed, §5.3; `DatabaseSetupManager`'s connection factory does have
basic reconnect via `DbalReconnectableConnectionFactory`, §5.5; the `normalizeBoolean()` wart is flagged as a
shared-framework issue, §5.3).

## Revision 3 (2026-08-28) — re-verified against `dgafka/ecotone-2-0-work` @ `bcf4efc0`

Everything below Revision 2 was written against the tree as it stood on 2026-08-22. Three large merges have
landed since: the public API moved to `Ecotone\Api\*` / `Ecotone\Api\<Package>\*` with the files living in
`packages/<Pkg>/Api/` (`upgrade/namespace-map-2.0.csv`), the DBAL queue transport was internalised into
`Ecotone\Dbal\Connection\*` with `Ecotone\Api\Dbal\ExtensionObject\DbalConnectionReference::DEFAULT`, and
`ServiceConfiguration::withModulePackages()` replaced the skip lists. **Every path, class name and line
number in this report has been re-checked against the current tree and corrected in place.** The headline
conclusion is unchanged — the infrastructure exists, this is a default-flip plus gap-closing — but the
re-verification turned up **thirteen substantive findings the earlier rounds missed**, three of which change
the design.

### Corrections to citations (mechanical, applied in place)

| # | What moved | Old citation | Current citation |
|---|---|---|---|
| R-1 | `DatabaseSetupManager` | `packages/Dbal/src/Database/DatabaseSetupManager.php`, `Ecotone\Dbal\Database` | `packages/Dbal/Api/DatabaseSetupManager.php`, `Ecotone\Api\Dbal\ExtensionObject\DatabaseSetupManager`; ctor `:26-30`, `initializeAll()` `:81-92`, `getConnection()` `:235-241` |
| R-2 | `DbalConfiguration` | `packages/Dbal/src/Configuration/DbalConfiguration.php` | `packages/Dbal/Api/DbalConfiguration.php`, `Ecotone\Api\Dbal\ExtensionObject\DbalConfiguration`; `$initializeDatabaseTables` `:54`, `withAutomaticTableInitialization()` `:369-375`, `isAutomaticTableInitializationEnabled()` `:377-380`, `createForTesting()` `:65-77`, `getMainConnectionOrDefault()` `:89-104`, `withDeduplication()` `:210`, `withDeadLetter()` `:221`, `withConsumerPositionTracking()` `:230`, `withDocumentStore()` `:241` |
| R-3 | `MultiTenantConfiguration` | `packages/Dbal/src/MultiTenant/MultiTenantConfiguration.php` | `packages/Dbal/Api/MultiTenantConfiguration.php`, `Ecotone\Api\Dbal`; ctor `:18-24`, factories `:29-40`, `getTenantToConnectionMapping()` `:55-58`. Mapping values are now `string\|ConnectionReference`, no longer plain strings — a `--tenant=` implementation must resolve both |
| R-4 | `EventSourcingConfiguration` | `packages/PdoEventSourcing/src/EventSourcingConfiguration.php` | `packages/PdoEventSourcing/Api/EventSourcingConfiguration.php`, `Ecotone\Api\EventSourcing`; `withEventStreamTableName()` `:170`, `withProjectionsTableName()` `:177`, `create()` `:51` |
| R-5 | `DbalConnectionReference` | `Ecotone\Dbal\DbalConnectionReference` | `Ecotone\Api\Dbal\ExtensionObject\DbalConnectionReference` (`packages/Dbal/Api/DbalConnectionReference.php:15`), `DEFAULT = Ecotone\Dbal\Connection\DbalConnectionFactory::class` |
| R-6 | `#[ConsoleCommand]` / `#[ConsoleParameterOption]` | `Ecotone\Messaging\Attribute\*` | `Ecotone\Api\Attribute\ConsoleCommand` / `Ecotone\Api\Attribute\ConsoleParameterOption` (`packages/Ecotone/Api/`) |
| R-7 | `#[WithTenantResolver]` | `packages/Dbal/src/Attribute/WithTenantResolver.php` | `packages/Dbal/Api/WithTenantResolver.php`, `Ecotone\Api\Dbal` |
| R-8 | SQLite DSN parsing | `DbalConnectionFactory.php:11-13,22-23,73` | `packages/Dbal/src/Connection/DbalConnectionFactory.php:140-142` (scheme map), `:151`, `:280` |
| R-9 | `DatabaseSetupModule` | `:47` (`[0]`), `:45`, `:78-94`, `:107-127`, `:56-59` | `:48`, `:46`, `:79-95`, `:108-128`, `:58-60` |
| R-10 | `DatabaseSetupCommand::setup()` | `:25-106` / `:25-31` | `:26-107` / `:26-32` |
| R-11 | Table managers | `EnqueueTableManager::createTable()` `:52-58`, `DeadLetterTableManager` `:56-62`, `DocumentStoreTableManager` `:51-57` | `:52-59`, `:56-63`, `:51-58`. `DeduplicationTableManager::createTable()` `:52-59` and `isInitialized()` `:72-75` are unchanged |
| R-12 | `EnqueueTableManager` registration | `DbalPublisherModule.php:54-61` | `DbalPublisherModule.php:59-66` (service), `:135` (`DbalTableManagerReference`) |
| R-13 | Runtime create sites | `DbalDocumentStore.php:195-201`, `DbalProjectionStateStorage.php:198-212`, `LazyProophEventStore.php:184-204` | `:195-202`, `:199-213`, `:183-205` |
| R-14 | Framework console bridges | Laravel `:123-166`, Symfony `:149`, Tempest (no line) | Laravel `EcotoneProvider.php:126-170`, Symfony `EcotoneExtension.php:152`, Tempest `MessagingSystemInitializer.php:76` + `ConsoleCommandProxyGenerator.php:115-170` |
| R-15 | `DbalMessagingTestCase` | `:68-92` (setUp), `:63-65` (`isUsingSqlite`) | `cleanUpDbalTables()` `:68-77`, `setUp()` `:84-92`, `isUsingSqlite()` `:63-66` |

Unchanged and re-confirmed verbatim: `DbalTransactionInterceptor.php:98-126` with the `@TODO` at `:107`;
`ImplicitCommit.php:16-20`; `DeduplicationInterceptor.php` `:37`, `:60-66`, `:99-113` (`@TODO` at `:100`),
`:127`, `:166-175`; `DbalInboundChannelAdapter.php:39`; `DbalOutboundChannelAdapter.php:54`;
`DbalDeadLetterHandler.php:207-214`; `DbalTableManager.php:33` ("Should use CREATE TABLE IF NOT EXISTS");
`ProjectionStateTableManager.php:47-54,100,114`; `EventStreamTableManager.php:45-56`;
`EcotoneLite.php:127-130`; `docker-compose.yml:17,41`; `DbalConsumerPositionTracker.php:16`.

### New findings (substantive — these were not in Revisions 1–2)

**R-16. Prooph creates a table per stream, at runtime, inside the message transaction, with no auto-init
gate at all.** This is the single largest omission in the earlier table inventory (§2.2), which listed only
the `event_streams` *metadata* table. `LazyProophEventStore::appendTo()`
(`packages/PdoEventSourcing/src/Prooph/LazyProophEventStore.php:164-175`) does:

```php
if (! isset($this->ensuredExistingStreams[$this->getContextName()][$streamName->toString()]) && ! $this->hasStream($streamName)) {
    $this->create(new Stream($streamName, new ArrayIterator([]), []));
}
```

`create()` (`:155-162`) routes into Prooph, which issues `CREATE TABLE` for the physical stream table
(`vendor/prooph/pdo-event-store/src/PostgresEventStore.php:205` → `createSchemaFor()` `:848-862`,
`$this->persistenceStrategy->createSchema($tableName)`). Table names are
`'_' . sha1($streamName)` (`packages/PdoEventSourcing/src/Prooph/PersistenceStrategy/InterlopMysqlSimpleStreamStrategy.php:85`,
`InterlopMariaDbSimpleStreamStrategy.php:87`), and the strategies emit a bare `CREATE TABLE` — no
`IF NOT EXISTS` (`InterlopMysqlSimpleStreamStrategy.php:40`). Three consequences:

1. This path is **not** gated by `shouldBeInitializedAutomatically()`. Setting
   `withAutomaticTableInitialization(false)` today does **not** stop it — contradicting §1.2's claim that the
   flag "already disables every on-the-fly `CREATE TABLE` call". That claim is correct for the DBAL package
   and for the two `PdoEventSourcing` table *managers*, but not for per-stream tables.
2. `DatabaseSetupManager` **cannot** pre-create these tables: their names depend on stream names that only
   exist at runtime (per-aggregate-instance under the aggregate persistence strategy). No CLI verb can
   enumerate them ahead of time.
3. Therefore, for anyone using event sourcing on MySQL/MariaDB, DDL-inside-the-handler-transaction survives
   every change this report proposes. **Removing `ImplicitCommit` is not safe while Prooph is the event
   store.** This makes Group F2 depend on Group D (the DCB store, which replaces per-stream tables with a
   single `event_log`) — a dependency neither report recorded. §6.2 and the implementation plan are updated
   accordingly.

**R-17. A second, independent auto-init switch exists for the event store.**
`EventSourcingConfiguration::withInitializeEventStoreOnStart(bool)`
(`packages/PdoEventSourcing/Api/EventSourcingConfiguration.php:149`) feeds
`LazyProophEventStore::$canBeInitialized` (`:96`), checked first in `prepareEventStore()` (`:186`) —
before the per-manager `shouldBeInitializedAutomatically()` checks at `:191-192`. Any `AutoCreateLevel`
design has to subsume or explicitly reconcile it; §5.1 previously assumed
`DbalConfiguration::withAutomaticTableInitialization()` was the only knob.

**R-18. `--strict` cannot signal failure through an exit code — no framework bridge supports one.** All
three hardcode success: Symfony `MessagingEntrypointCommand::execute()` returns `0`
(`packages/Symfony/DependencyInjection/MessagingEntrypointCommand.php:80`), Laravel's generated closure
returns `0` (`packages/Laravel/src/EcotoneProvider.php:165`), Tempest's generated proxy returns
`ExitCode::SUCCESS` (`packages/Tempest/src/ConsoleCommandProxyGenerator.php:163`). §5.3's proposed
"`--strict` exits non-zero" is not implementable as written without changing all three bridges. The
alternative — have `--strict` **throw** — works today unchanged, because an uncaught exception produces a
non-zero exit in every console framework, and is a catchable assertion in `EcotoneLite`. §5.3 and the
implementation plan now specify throwing.

**R-19. The `enqueue` table name *is* already overridable per connection — and the setup CLI ignores the
override.** `table_name` is a documented `DbalConnectionFactory` config key defaulting to `'enqueue'`
(`packages/Dbal/src/Connection/DbalConnectionFactory.php:37,65`), read at runtime by
`DbalContext::getTableName()` (`:204-207`). But `EnqueueTableManager` is always constructed with the
hardcoded `EnqueueTableManager::DEFAULT_TABLE_NAME` (`packages/Dbal/src/Configuration/DbalPublisherModule.php:62`).
So an application that sets `table_name` gets a setup CLI (and an auto-create path) that creates `enqueue`
while the runtime reads and writes the custom table. This is a live bug, not a missing feature, and it
sharpens §2.3: the gap is not "no override exists" but "the override exists on the connection and the table
manager doesn't see it."

**R-20. Per-channel enqueue tables do not exist.** §2.2/§2.3 said "per-channel table names come from the
channel's own configuration". They don't. `DbalBackedMessageChannelBuilder::create(string $channelName, string
$connectionReferenceName)` (`packages/Dbal/Api/DbalBackedMessageChannelBuilder.php:30`) takes no table name;
every DBAL channel shares one `enqueue` table discriminated by a `queue` column
(`packages/Dbal/src/Connection/DbalContext.php:195-201`). One table per connection, not per channel.

**R-21. The outbox does not register a second `EnqueueTableManager`.** §2.2 said `DbalPublisherModule`
registers the manager a second time for the outbox. There is exactly one `EnqueueTableManager` service
(`DbalPublisherModule.php:59-66`) and one `DbalTableManagerReference` for it (`:135`). Outbox and message
channels share the same manager and the same `enqueue` table — which is why `isUsed` is a single
`$hasMessageQueues` flag (`:63`).

**R-22. `DbalProjectionStateStorage` is hardwired to the default connection.**
`packages/PdoEventSourcing/src/Config/ProophProjectingModule.php:274-280` builds it with
`new Reference(DbalConnectionReference::DEFAULT)`, ignoring `EventSourcingConfiguration::getConnectionReferenceName()`.
So an application running event sourcing on a dedicated connection already writes `ecotone_projection_state`
to the *default* connection at runtime. This is the same class of mistargeting §5.5 describes for
`DatabaseSetupManager`, but on the runtime side and independent of it — the CLI fix alone will not make the
two agree.

**R-23. `DatabaseSetupManager` now pre-checks `isInitialized()` itself.** `initializeAll()` `:85-91` and
`initialize()` `:114-118` skip managers that report initialised, before calling `createTable()` — which
does the same check again. The TOCTOU window §6.1/§7 describe is therefore *two* nested check-then-create
layers, not one. The recommendation (make the DDL idempotent, plus catch `TableAlreadyExistsException`) is
unchanged; the description in §7's concurrency row is.

**R-24. The missing-table failure surface is already proven by an existing test, not merely inferred.**
`packages/PdoEventSourcing/tests/Projecting/ProjectionStateTableInitializationTest.php:49-68`
(`test_projection_fails_when_auto_initialization_disabled_and_table_not_created`) asserts
`$this->expectException(TableNotFoundException::class)` — a raw
`Doctrine\DBAL\Exception\TableNotFoundException` reaching user code today under auto-init-off. §6.2a's
catch-and-rethrow recommendation now rests on a first-party test, not on reasoning about driver behaviour.

**R-25. There is substantial existing test coverage §2.6 never mentioned.**
`packages/Dbal/tests/Integration/DatabaseInitializationTest.php` has 14 tests over the CLI surface,
including `test_tables_are_auto_created_when_auto_initialization_enabled` (`:126-140`),
`test_tables_are_not_auto_created_when_auto_initialization_disabled` (`:142-159`) and three
`normalizeBoolean` regression tests (`:298-330`); it drives commands through
`ConsoleCommandRunner` (`:249-255`) against `EcotoneLite::bootstrapFlowTesting()` with
`->withEnvironment('prod')->withModulePackages([ModulePackageList::DBAL_PACKAGE])` (`:267-283`).
`ProjectionStateTableInitializationTest` adds 3 more. The implementation plan's "tests to add" must be
framed as *extending these*, not as greenfield.

**R-26. `DbalContext` carries a duplicate, ungated copy of the enqueue schema.**
`packages/Dbal/src/Connection/DbalContext.php:228-260` defines `createDataBaseTable()`, which rebuilds the
`enqueue` `Table` column-for-column and index-for-index identically to
`EnqueueTableManager::buildTableSchema()` (`packages/Dbal/src/Database/EnqueueTableManager.php:71-95`) and
consults no `shouldBeInitializedAutomatically()`. It is on no runtime path — the only callers in the repo
are `packages/Dbal/tests/Integration/ReconnectTest.php:37,64` — but it is a second source of truth for one
table's schema, arrived at when the transport was internalised. It must be collapsed onto
`EnqueueTableManager` or deleted.

**R-27. `bootstrapFlowTesting()` never injects `DbalConfiguration::createForTesting()`.** §2.6 read as if
the injection at `EcotoneLite.php:127-130` covers `bootstrapFlowTesting*`. It is inside
`bootstrapFlowTestingWithEventStore()` only (`:104-134`). Plain `bootstrapFlowTesting()` (`:73-93`) adds no
`DbalConfiguration` at all, so a DBAL-backed test that doesn't pass one gets
`DbalConfiguration::createWithDefaults()` — auto-init `true`. Both bootstraps end up auto-creating, but by
different routes, and a design that changes the test default has to touch both.

**R-28. `DbalTransactionInterceptor` gained a `TransactionStatusTracker`.** The constructor now takes one
(`packages/Dbal/src/DbalTransaction/DbalTransactionInterceptor.php:38`) and marks in/out of transaction
around `proceed()` (`:93-96`, `:144-148`). `Ecotone\Modelling\Config\DatabaseTransaction\TransactionStatusTracker`
(`packages/Ecotone/src/Modelling/Config/DatabaseTransaction/TransactionStatusTracker.php:10-31`) is a
framework-level "am I inside an Ecotone-managed DBAL transaction" signal that did not exist when §6.2 was
written. It is *not* a drop-in replacement for `Connection::isTransactionActive()` in
`DeduplicationInterceptor` (it is connection-agnostic, so it cannot tell whether *this* connection is in a
transaction), but it is the right thing to consult for the "was a transaction started by Ecotone at all"
half of that condition.

### What these change in the design

- §1.2's "the flag already disables every on-the-fly `CREATE TABLE`" is scoped down: true for the DBAL
  package, false for Prooph per-stream tables (R-16).
- Removing `ImplicitCommit` is now **blocked on Group D**, not merely sequenced after the default flip
  (R-16). The implementation plan's step ordering changes.
- `--strict` throws instead of returning an exit code (R-18).
- `AutoCreateLevel` must subsume `withInitializeEventStoreOnStart()` (R-17).
- Table-name work is larger than "add three `with*Table()` methods": it also has to reconcile the connection
  -level `table_name` override that already exists (R-19) and the hardwired projection-state connection
  (R-22).

The final design that acts on all of this is
`docs/superpowers/specs/2026-08-28-database-setup-cli-design.md`.

---

## 1. Problem

### 1.1 Implicit commit — `DbalTransactionInterceptor`

`packages/Dbal/src/DbalTransaction/DbalTransactionInterceptor.php:98-126`, the commit half of `transactional()`:

```php
// packages/Dbal/src/DbalTransaction/DbalTransactionInterceptor.php:98-126
try {
    $result = $methodInvocation->proceed();

    foreach ($connections as $connection) {
        try {
            $connection->commit();
            $this->logger->info('Database Transaction committed', $message);
        } catch (Exception $exception) {
            // Handle the case where a database did an implicit commit or the transaction is no longer active
            /** @TODO Ecotone 2.0 remove implicit commit and tables creation on fly, and provide CLI command instead */
            if (ImplicitCommit::isImplicitCommitException($exception, $connection)) {
                $this->logger->info(
                    sprintf('Implicit Commit was detected, skipping manual one.'),
                    $message,
                    ['exception' => $exception],
                );

                try {
                    $connection->rollBack();
                } catch (Exception) {
                    // Ignore rollback errors after implicit commit
                };

                continue;
            }

            throw $exception;
        }
    }
}
```

The `@TODO Ecotone 2.0` comment at line 107 is the literal source of this topic. `ImplicitCommit::isImplicitCommitException`
(`packages/Dbal/src/DbalTransaction/ImplicitCommit.php:16-36`) detects the failure by **string-matching the
DBAL exception message** (`'No active transaction'`, `'There is no active transaction'`, `'Transaction not
active'`, `'not in a transaction'`) and is scoped to `AbstractMySQLPlatform` only:

```php
// packages/Dbal/src/DbalTransaction/ImplicitCommit.php:16-20
public static function isImplicitCommitException(Throwable $exception, Connection $connection): bool
{
    if (! ($connection->getDriver()->getDatabasePlatform($connection) instanceof AbstractMySQLPlatform)) {
        return false;
    }
```

### 1.2 On-the-fly table creation — `DeduplicationInterceptor`

`packages/Dbal/src/Deduplication/DeduplicationInterceptor.php:54-66`, inside the intercepted handler call,
*before* the wrapped business logic runs:

```php
// packages/Dbal/src/Deduplication/DeduplicationInterceptor.php:60-66
$connectionFactory = CachedConnectionFactory::createFor(new DbalReconnectableConnectionFactory($this->connection));
$contextId = spl_object_id($connectionFactory->createContext());

if (! isset($this->initialized[$contextId])) {
    $this->createDataBaseTable($connectionFactory);
    $this->initialized[$contextId] = true;
}
```

and at line 100, a second workaround directly downstream of the implicit-commit problem:

```php
// packages/Dbal/src/Deduplication/DeduplicationInterceptor.php:99-105
try {
    /** @TODO Ecotone 2.0 remove postgres check - when getting rid of implicit commit for MySQL */
    $isTransactionActive = $connection->isTransactionActive() && $connection->getDatabasePlatform() instanceof PostgreSQLPlatform;
    // ensure that concurrent message handling will fail before proceeding
    if ($isTransactionActive) {
        $this->insertHandledMessage($connectionFactory, $messageId, $consumerEndpointId, $routingSlip);
    }
```

`removeExpiredMessages()` (the periodic cleanup) does the same thing at line 127: `$this->createDataBaseTable($connectionFactory);`
runs on **every invocation**, inside whatever transaction is active for that call.

**Important correction to the task's framing**: both of these calls are already gated, not unconditional.
`createDataBaseTable()` (`DeduplicationInterceptor.php:166-175`) is:

```php
// packages/Dbal/src/Deduplication/DeduplicationInterceptor.php:166-175
private function createDataBaseTable(ConnectionFactory $connectionFactory): void
{
    if (! $this->tableManager->shouldBeInitializedAutomatically()) {
        return;
    }

    $connection = $this->getConnection($connectionFactory);
    $this->tableManager->createTable($connection);
    $this->logger->info('Deduplication table was created');
}
```

`shouldBeInitializedAutomatically()` traces back to `DbalConfiguration::isAutomaticTableInitializationEnabled()`
(`packages/Dbal/Api/DbalConfiguration.php:377-380`), which defaults to `true`
(`DbalConfiguration.php:54`, `private bool $initializeDatabaseTables = true;`). **So the actual bug is a
default, not missing plumbing**: setting `DbalConfiguration::createWithDefaults()->withAutomaticTableInitialization(false)`
today already disables every on-the-fly `CREATE TABLE` call in the DBAL package. **Scoped down in Revision 3
(R-16): it does *not* disable Prooph's per-stream table creation**, which runs ungated inside the message
transaction (`LazyProophEventStore.php:164-175`) — so for event-sourcing users on MySQL the implicit-commit
trigger survives the flag entirely. The remaining problem is
that (a) nobody does this by default, (b) the implicit-commit workaround code stays load-bearing as long as
the default is `true`, and (c) even with the flag off, the deduplication *cleanup* still needs a home outside
the handler transaction (§6).

### 1.3 What exists today — `DatabaseSetupManager` / `DatabaseSetupModule`

`packages/Dbal/Api/DatabaseSetupManager.php` (260 lines, `Ecotone\Api\Dbal\ExtensionObject\DatabaseSetupManager`) is a working aggregator over
`DbalTableManager[]`:

```php
// packages/Dbal/Api/DatabaseSetupManager.php:21-30
class DatabaseSetupManager implements DefinedObject
{
    public function __construct(
        private ConnectionFactory $connectionFactory,
        private array $tableManagers = [],
    ) {
    }
```

It exposes, per connection: `getFeatureNames(bool $onlyUsed = true)`, `getCreateSqlStatements(bool $onlyUsed = true)`,
`getDropSqlStatements(...)`, `initializeAll(bool $onlyUsed = true)`, `dropAll(bool $onlyUsed = true)`,
`initialize(string $featureName)`, `drop(string $featureName)`, `getCreateSqlStatementsForFeatures(array $featureNames)`,
`getDropSqlStatementsForFeatures(...)`, `getInitializationStatus(bool $onlyUsed = true): array<string,bool>`,
`getUsageStatus(): array<string,bool>`.

`packages/Dbal/src/Database/DatabaseSetupModule.php:38-96` wires this up: it resolves `DbalTableManagerReference`
extension objects contributed by each feature module (`ExtensionObjectResolver::resolve(DbalTableManagerReference::class, $extensionObjects)`,
line 46), builds one `DatabaseSetupManager` against **a single connection** —

```php
// packages/Dbal/src/Database/DatabaseSetupModule.php:48
$connectionReference = $dbalConfiguration->getDefaultConnectionReferenceNames()[0] ?? DbalConnectionReference::DEFAULT;
```

— (this `[0]` is a real gap: see §7) and registers two console commands:

```php
// packages/Dbal/src/Database/DatabaseSetupModule.php:79-95
$this->registerConsoleCommand('setup', 'ecotone:migration:database:setup', DatabaseSetupCommand::class, ..., 'Creates database tables required by Ecotone');
$this->registerConsoleCommand('delete', 'ecotone:migration:database:delete', DatabaseDeleteCommand::class, ..., 'Drops database tables created by Ecotone');
```

`DatabaseSetupCommand::setup()` (`packages/Dbal/src/Database/DatabaseSetupCommand.php:26-107`) is a real,
already-functional CLI surface:

```php
// packages/Dbal/src/Database/DatabaseSetupCommand.php:26-32
#[ConsoleCommand('ecotone:migration:database:setup')]
public function setup(
    #[ConsoleParameterOption] array $feature = [],
    #[ConsoleParameterOption] bool|string $initialize = false,
    #[ConsoleParameterOption] bool|string $sql = false,
    #[ConsoleParameterOption] bool|string $onlyUsed = true,
): ?ConsoleCommandResultSet {
```

With no flags it prints a status table (feature / used / initialized). `--sql` dumps `CREATE TABLE` statements
instead of running them. `--initialize` actually creates. `--feature name1,name2` scopes to specific features.
`DatabaseDeleteCommand::delete()` mirrors it with a `--force` confirmation guard (refuses to drop without it).

**A near-identical, independently-built sibling exists for message channels**: `ChannelSetupManager` /
`ChannelSetupCommand` (`packages/Ecotone/src/Messaging/Channel/Manager/ChannelSetupCommand.php`,
`#[ConsoleCommand('ecotone:migration:channel:setup')]`, `--channel`, `--initialize`). Whatever shape this
report proposes for the database CLI should either match this existing "Manager + Command" convention
exactly, or the two should be unified — see Open Question 9.

### 1.4 GitHub issue #438 — production pain from in-transaction deduplication cleanup

[ecotoneframework/ecotone-dev#438](https://github.com/ecotoneframework/ecotone-dev/issues/438) ("List of
previously found problems (will be updated)", opened 2025-02-03 by @lifinsky) is a running list; point 6 and
its comment thread are on-topic:

> "There was a case where an AMQP consumer got stuck on an event-sourcing aggregate command, and in the
> queue, it was visible that the message was received but not acknowledged. The last log message was:
> `Executing Command Handler...`"

Follow-up, pinpointing the cause via `pg_stat_activity`:

> "The issue has occurred again, and it got stuck once more with the last log message: `Executing Command
> Handler *\Domain\Entity\Card::close`... There are pending queries to the database: `DELETE FROM
> ecotone_deduplication WHERE handled_at <= $1`"

And the explicit ask:

> "...if you can, please check whether rollbacks are being done correctly everywhere and whether the
> deletion from `ecotone_deduplication` can be done periodically with a low frequency and definitely not
> within the main transaction."

> "The blocking occurred because there was a transaction with deleting messages from the
> `ecotone_deduplication` table... I want to be able to move these garbage collecting to cron and not deal
> with this during the processing of aggregates..."

This is first-party evidence of connections held `idle in transaction` for 30+ minutes on Postgres, caused by
exactly the coupling this topic is meant to remove: `removeExpiredMessages()` and its batched `DELETE`
running inside whatever transaction happens to be active, instead of as an independent, scheduled operation.

---

## 2. Current state inventory

### 2.1 Core setup/CLI classes

| Class | Path | Role |
|---|---|---|
| `DatabaseSetupManager` | `packages/Dbal/Api/DatabaseSetupManager.php` (`Ecotone\Api\Dbal`) | Aggregates `DbalTableManager[]` for one connection; create/drop/status/SQL-dump API |
| `DatabaseSetupModule` | `packages/Dbal/src/Database/DatabaseSetupModule.php` | `#[ModuleAnnotation]`; collects `DbalTableManagerReference` extension objects, registers the manager + two console commands |
| `DatabaseSetupCommand` | `packages/Dbal/src/Database/DatabaseSetupCommand.php` | `#[ConsoleCommand('ecotone:migration:database:setup')]` — status / `--sql` / `--initialize` / `--feature` |
| `DatabaseDeleteCommand` | `packages/Dbal/src/Database/DatabaseDeleteCommand.php` | `#[ConsoleCommand('ecotone:migration:database:delete')]` — status / `--force` / `--feature` |
| `DbalTableManager` (interface) | `packages/Dbal/src/Database/DbalTableManager.php` | `getFeatureName/isUsed/getCreateTableSql/getDropTableSql/createTable/dropTable/isInitialized/shouldBeInitializedAutomatically` |
| `DbalTableManagerReference` | `packages/Dbal/src/Database/DbalTableManagerReference.php` | Extension object a feature module returns from `getModuleExtensions()` to register its table manager |
| `ChannelSetupManager` / `ChannelSetupCommand` | `packages/Ecotone/src/Messaging/Channel/Manager/ChannelSetupCommand.php` | Parallel, independently-built pattern for message channels — same shape, `ecotone:migration:channel:setup` |
| `LicenceDecider` | `packages/Ecotone/src/Messaging/Config/LicenceDecider.php` | Open-core/Enterprise service selection at container-build time (`decide()`, `prepareDefinition()`) |

### 2.2 Table inventory — every table Ecotone can create, and where

| Table (default name) | Feature | `DbalTableManager` : path | Runtime creation call site | Auto-create gated? |
|---|---|---|---|---|
| `enqueue` (`EnqueueTableManager::DEFAULT_TABLE_NAME`) | Message queue channel (also backs the outbox — same table, same manager) | `EnqueueTableManager` : `packages/Dbal/src/Database/EnqueueTableManager.php` | `DbalOutboundChannelAdapter::initialize()` (`packages/Dbal/src/DbalOutboundChannelAdapter.php:45-54`) and `DbalInboundChannelAdapter::initialize()` (`packages/Dbal/src/DbalInboundChannelAdapter.php:30-39`) — first send/first poll | Yes, `shouldBeInitializedAutomatically()` check at both sites |
| `ecotone_error_messages` (`DbalDeadLetterHandler::DEFAULT_DEAD_LETTER_TABLE`) | Dead letter queue | `DeadLetterTableManager` : `packages/Dbal/src/Database/DeadLetterTableManager.php` | `DbalDeadLetterHandler::createDataBaseTable()` (`packages/Dbal/src/Recoverability/DbalDeadLetterHandler.php:207-214`) — first dead-lettered message | Yes |
| `ecotone_deduplication` (`DeduplicationInterceptor::DEFAULT_DEDUPLICATION_TABLE`) | Handler/consumer deduplication | `DeduplicationTableManager` : `packages/Dbal/src/Database/DeduplicationTableManager.php` | `DeduplicationInterceptor::deduplicate()` line 64, and `removeExpiredMessages()` line 127 (both `packages/Dbal/src/Deduplication/DeduplicationInterceptor.php`) | Yes |
| `ecotone_document_store` (`DbalDocumentStore::ECOTONE_DOCUMENT_STORE`) | Document store (also the backing store for consumer-position tracking, see below) | `DocumentStoreTableManager` : `packages/Dbal/src/Database/DocumentStoreTableManager.php` | `DbalDocumentStore::createDataBaseTable()` (`packages/Dbal/src/DocumentStore/DbalDocumentStore.php:195-202`) — first read/write | Yes |
| Event streams table (configurable via `EventSourcingConfiguration::withEventStreamTableName()`) | Event-sourcing stream storage (Prooph-backed today, see Group D) | `EventStreamTableManager` : `packages/PdoEventSourcing/src/Database/EventStreamTableManager.php` (docblock tagged `licence Enterprise` — see Open Question 8) | `LazyProophEventStore::prepareEventStore()` (`packages/PdoEventSourcing/src/Prooph/LazyProophEventStore.php:183-205`) | Yes |
| Legacy `projections` table | ProjectionV1 state (being removed in Group B) | `LegacyProjectionsTableManager` : `packages/PdoEventSourcing/src/Database/LegacyProjectionsTableManager.php` | Same `LazyProophEventStore::prepareEventStore()` call site, line 203 (`:202-204`) | Out of scope for 2.0 — deleted with Group B |
| Per-stream event tables `_<sha1(streamName)>` — **added in Revision 3 (R-16)** | Physical event storage under every Prooph persistence strategy | **none** — no `DbalTableManager` exists and none can, the names are runtime-derived | `LazyProophEventStore::appendTo()` (`packages/PdoEventSourcing/src/Prooph/LazyProophEventStore.php:164-175`) → `create()` (`:155-162`) → Prooph `createSchemaFor()` (`vendor/prooph/pdo-event-store/src/PostgresEventStore.php:205,848-862`); names from `generateTableName()` (`InterlopMysqlSimpleStreamStrategy.php:85`) | **No** — completely ungated, and the DDL is a bare `CREATE TABLE` with no `IF NOT EXISTS` (`InterlopMysqlSimpleStreamStrategy.php:40`) |
| `ecotone_projection_state` (`ProjectionStateTableManager::DEFAULT_TABLE_NAME`) | ProjectionV2 (→ `#[Projection]`) state | `ProjectionStateTableManager` : `packages/PdoEventSourcing/src/Database/ProjectionStateTableManager.php` | `DbalProjectionStateStorage::createSchema()` (`packages/PdoEventSourcing/src/Projecting/PartitionState/DbalProjectionStateStorage.php:199-213`) | Yes |
| Outbox — **no separate table**, reuses `enqueue` | Outbox pattern | `EnqueueTableManager` again, registered **once**, by `packages/Dbal/src/Configuration/DbalPublisherModule.php:59-66` (see Revision 3, correction R-21) | Same channel-adapter `initialize()` sites | Yes |
| Consumer/endpoint position — **no dedicated table** | Consumer offset persistence | `DbalConsumerPositionTracker` (`packages/Dbal/src/Consumer/DbalConsumerPositionTracker.php`) stores into the Document Store under collection `ecotone_consumer_positions` (line 16) | Rides on `DocumentStoreTableManager`'s creation path — no independent DDL | Inherits Document Store's gate |

Nine feature areas map to **7 distinct DBAL table managers** (outbox and consumer-position ride on existing
ones). All 7 are already discoverable by `DatabaseSetupManager` via `DbalTableManagerReference` extension
objects returned from each feature module's `getModuleExtensions()`.

### 2.3 Table-name configurability — real gap

| Feature | Table-name override method | Exists? |
|---|---|---|
| Event streams | `EventSourcingConfiguration::withEventStreamTableName(string): static` (`packages/PdoEventSourcing/Api/EventSourcingConfiguration.php:170`, `Ecotone\Api\EventSourcing`) | Yes |
| ProjectionV2 state | `EventSourcingConfiguration::withProjectionsTableName(string): static` (line 177) — **misleadingly named**, this sets the *legacy* v1 projections table, not the v2 state table (`ecotone_projection_state` has no override at all) | Partial / wrong table |
| Deduplication | none — hardcoded to `DeduplicationInterceptor::DEFAULT_DEDUPLICATION_TABLE = 'ecotone_deduplication'` | **No** |
| Dead letter | none — hardcoded to `DbalDeadLetterHandler::DEFAULT_DEAD_LETTER_TABLE = 'ecotone_error_messages'` | **No** |
| Document store | none — hardcoded to `DbalDocumentStore::ECOTONE_DOCUMENT_STORE = 'ecotone_document_store'` | **No** |
| Message queue / enqueue | none — hardcoded to `EnqueueTableManager::DEFAULT_TABLE_NAME = 'enqueue'` (per-channel table names come from the channel's own configuration, not this default) | Partial |

`DbalConfiguration::withDeduplication(bool, string $connectionReference, int $expirationTime, int $removalBatchSize)`
(line 210), `withDeadLetter(bool, string $connectionReference)` (line 221), and `withDocumentStore(bool, bool,
string $reference, bool $initializeDatabaseTable, bool, string $connectionReference, ?array)` (line 241) all
take a **connection reference** override but no **table name** override. This is a genuine, verified gap
(searched explicitly for `withDeduplicationTable`, `withDeadLetterTable`, `withDocumentStoreTable`,
`withEnqueueTable` — zero hits).

### 2.4 Console command mechanics

Two registration idioms coexist and both work identically end-to-end (`ConsoleCommandRunner` gateway →
framework command):

**Idiom A — attribute-per-method** (used by `DatabaseSetupCommand`, `DatabaseDeleteCommand`,
`ChannelSetupCommand`):

```php
// packages/Ecotone/Api/ConsoleCommand.php  (Ecotone\Api\Attribute\ConsoleCommand)
#[Attribute(Attribute::TARGET_METHOD)]
class ConsoleCommand
{
    public function __construct(string $consoleCommandName, string $description = '') { /* ... */ }
}
```

The module then wraps the method via `ConsoleCommandModule::prepareConsoleCommandForReference(new Reference($className),
new InterfaceToCallReference($className, $methodName), $commandName, true, $interfaceToCallRegistry, $description)`
and calls `$configuration->registerMessageHandler($messageHandlerBuilder)->registerConsoleCommand($oneTimeCommandConfiguration)`
(`packages/Dbal/src/Database/DatabaseSetupModule.php:108-128`).

**Idiom B — explicit `ConsoleCommandConfiguration`** (used by `DeduplicationModule` for the cleanup command,
and by `MessagingCommandsModule` for `ecotone:run`/`ecotone:list`):

```php
// packages/Dbal/src/Deduplication/DeduplicationModule.php:102-113
->registerMessageHandler(
    ServiceActivatorBuilder::create(DeduplicationInterceptor::class, 'removeExpiredMessages')
        ->withInputChannelName($inputChannelName = 'ecotone.deduplication.removeExpiredMessages')
)
->registerConsoleCommand(ConsoleCommandConfiguration::create(
    $inputChannelName,
    'ecotone:deduplication:remove-expired-messages',
    [],
    'Removes expired message deduplication entries'
));
```

**A command for the deduplication cleanup already exists and is already CLI-only-triggerable** —
`ecotone:deduplication:remove-expired-messages`. It is not scheduled by default; nothing calls it
periodically. This is the missing half of §6.

**Framework bridging is fully generic — no new per-command wiring needed in Symfony/Laravel/Tempest.**
Laravel's `EcotoneProvider::boot()` iterates *every* registered `ConsoleCommandConfiguration` and dynamically
registers an Artisan command for each:

```php
// packages/Laravel/src/EcotoneProvider.php:126-170
if ($this->app->runningInConsole()) {
    foreach ($container->getRegisteredConsoleCommands() as $oneTimeCommandConfiguration) {
        $commandName = $oneTimeCommandConfiguration->getName();
        // ...builds the Artisan signature from getParameters()...
        $artisanCommand = Artisan::command($commandName, function (ConfiguredMessagingSystem $configuredMessagingSystem) {
            $consoleCommandRunner = $configuredMessagingSystem->getGatewayByName(ConsoleCommandRunner::class);
            // ...
            $result = $delegatingWriter->executeWith(new SymfonyConsoleWriter($self->getOutput()),
                fn () => $consoleCommandRunner->execute($self->getName(), array_merge($self->arguments(), $self->options())));
            if ($result) { $self->table($result->getColumnHeaders(), $result->getRows()); }
            return 0;
        });
    }
}
```

`packages/Symfony/DependencyInjection/EcotoneExtension.php:152` does the equivalent for Symfony
(`foreach ($ecotoneContainer->getRegisteredConsoleCommands() as $oneTimeCommandConfiguration) { ... }`), and
`packages/Tempest/src/MessagingSystemInitializer.php:76` mirrors it for Tempest via
`ConsoleCommandProxyGenerator`. **Conclusion for §3**: any new
CLI command this design adds only needs a `#[ConsoleCommand(...)]` method (or a `ConsoleCommandConfiguration::create(...)`
registration) inside a `Module::prepare()`. It is automatically `php artisan <name>`, `bin/console <name>`,
and `$ecotone->runConsoleCommand('<name>', [...])` in Ecotone Lite (via `ConsoleCommandRunner`) — no
framework-specific code to write.

### 2.5 Licence gating pattern

`packages/Ecotone/src/Messaging/Config/LicenceDecider.php` — `hasEnterpriseLicence()`,
`isEnabledSpecificallyFor(InterfaceToCall|ClassDefinition)` (checks for `#[Enterprise]`), and the static
factory used at container-build time:

```php
// example usage — packages/Ecotone/src/Modelling/EventSourcingExecutor/EventSourcingHandlerExecutorBuilder.php:72
LicenceDecider::prepareDefinition(
    AggregateMethodInvoker::class,
    Reference::to(OpenCoreAggregateMethodInvoker::class),
    Reference::to(EnterpriseAggregateMethodInvoker::class)
),
```

`DeduplicationModule::verifyEnterpriseFeatures()` (`packages/Dbal/src/Deduplication/DeduplicationModule.php:131-144`)
shows the other half — a hard `LicensingException` thrown at `prepare()` time when a gated attribute is used
without a licence:

```php
if (! empty($deduplicatedClasses)) {
    throw LicensingException::create("Deduplicated attribute on interfaces/gateways ({$classNames}) is available only with Ecotone Enterprise licence...");
}
```

### 2.6 Test behaviour today

`packages/Dbal/tests/DbalMessagingTestCase.php:84-92` — `setUp()` calls `cleanUpDbalTables()` (`:68-77`), which
explicitly `DROP TABLE`s `enqueue`, `OrderService::ORDER_TABLE`, `DbalDeadLetterHandler::DEFAULT_DEAD_LETTER_TABLE`,
`DbalDocumentStore::ECOTONE_DOCUMENT_STORE`, `DeduplicationInterceptor::DEFAULT_DEDUPLICATION_TABLE`,
`persons`, `activities` **before every test**, relying on the still-default-`true` auto-create to recreate
them on first use inside the test body.

`DbalConfiguration::createForTesting()` (`packages/Dbal/Api/DbalConfiguration.php:65-77`)
disables transactions and deduplication/dead-letter but **does not** call `withAutomaticTableInitialization(false)` —
tables still auto-create in test mode. `EcotoneLite::bootstrapFlowTestingWithEventStore()`
(`packages/Ecotone/src/Lite/EcotoneLite.php:127-130`) adds `DbalConfiguration::createForTesting()`
automatically whenever the test bootstrap doesn't already have a `DbalConfiguration` extension object:

```php
// packages/Ecotone/src/Lite/EcotoneLite.php:127-130
if (! $configuration->hasExtensionObject(DbalConfiguration::class)) {
    $configuration = $configuration->addExtensionObject(DbalConfiguration::createForTesting());
}
```

**There is no distinction anywhere in the DBAL package between "in-memory/SQLite test connection" and
"real Postgres/MySQL connection pointed at from a test"** — the decision is made entirely by *which
bootstrap method / extension object is present*, not by inspecting the connection's actual platform. Point
into `EcotoneLite::bootstrapFlowTestingWithEventStore()` with a real Postgres DSN today and tables still
silently auto-create. See §5 and Open Question 4 for the proposed fix.

### 2.7 Package/composer notes

`packages/Dbal/composer.json` — package `ecotone/dbal`, dual-licensed `Apache-2.0` / `proprietary`
(Enterprise), depends on `doctrine/dbal: ^3.9|^4.0` and `ecotone/enqueue: ~1.326.1`. `AGENTS.md` has zero
mentions of "migration", "console command", or "database setup" — the `DatabaseSetupCommand`/`ChannelSetupCommand`
pair is the de facto, undocumented template for how a new setup CLI should be structured; this report
proposes making it a documented convention (Open Question 9).

---

## 3. Prior art / web research

| System | Mechanism | Copy | Don't copy |
|---|---|---|---|
| **Symfony Messenger** `messenger:setup-transports` ([source, 7.4](https://raw.githubusercontent.com/symfony/symfony/7.4/src/Symfony/Component/Messenger/Command/SetupTransportsCommand.php), [docs](https://symfony.com/doc/current/messenger.html)) | One command, optional `transport` argument (all transports if omitted); calls `->setup()` on each transport that implements `SetupableTransportInterface`, skips the rest. Doctrine transport also has a DSN-level `auto_setup=1` default that creates the table implicitly on first send — the exact anti-pattern this topic removes. **No `--dry-run` flag exists** (verified against the actual 7.4 source — the task brief's assumption was wrong). | The "iterate every configured resource, call `setup()` only on ones that opt in via an interface" shape — maps directly onto iterating `DbalTableManager[]`. | The `auto_setup`-on-by-default-in-a-DSN-string pattern; don't cite a `--dry-run` flag that doesn't exist. |
| **Doctrine Migrations** `diff`/`migrate`/`up-to-date`, `SchemaProviderInterface` ([docs](https://www.doctrine-project.org/projects/doctrine-migrations/en/3.9/reference/generating-migrations.html)) | `diff` compares live DB schema vs. a target `Schema` object and generates a migration file; applied migrations tracked in a metadata table. Third-party schema sources register via `Doctrine\Migrations\Provider\SchemaProviderInterface` (`setDefinition(SchemaProvider::class, fn () => $provider)`); without ORM you must wire this yourself. Bundles that own tables outside the ORM use `doctrine.dbal.schema_filter` (a regex) so `doctrine:schema:update`/`diff` don't try to drop them. | The pluggable `SchemaProviderInterface` composition contract — each Ecotone feature module already *is* one via `DbalTableManagerReference`; formalize the "give me your target `Schema`" contract so `getCreateSqlStatements()` can literally be handed to a user's own `SchemaProviderInterface` implementation for `doctrine:migrations:diff` (§8.4). | ORM-mapping-as-default-schema-source (Ecotone has no ORM layer to piggyback on); the regex-based exclusion hack. |
| **Laravel** `make:queue-table`/`make:queue-batches-table`/`make:cache-table`, `vendor:publish --tag=migrations` ([docs](https://laravel.com/docs/12.x/queues)) | These commands **write a migration stub file**, they never touch the DB. `php artisan migrate` applies it separately. Packages ship migrations via `$this->publishes([...], 'migrations')` in a provider; the user must explicitly `vendor:publish` before `migrate`. | The two-step separation — "produce the artifact" (SQL/migration stub) is a distinct, safe, re-runnable step from "apply it." A module only *offers* schema; the host app chooses whether/when to materialize and run it. Maps onto `--sql` already existing on `DatabaseSetupCommand` plus a new file-writing variant (§8.3). | Laravel also ships a default cache-table migration inside the framework skeleton — don't blur "framework decides your schema" with "explicit pull only." |
| **Marten** (.NET doc DB/event store) `AutoCreate` levels + `dotnet marten db-patch`/`db-apply`/`db-assert` ([schema docs](https://martendb.io/schema/migrations), [workflow doc](https://martendb.io/configuration/optimized_artifact_workflow), [Jeremy Miller — vNext resource management](https://jeremydmiller.com/2025/05/11/managing-auto-creation-of-database-or-message-broker-resources-in-the-critter-stack-vnext/)) — **closest prior art** | Four graduated levels: `None` (never touch schema, throw on mismatch — prod), `CreateOnly` (create missing objects, never alter/drop existing), `CreateOrUpdate` (create + patch missing columns, never destroys data), `All` (create/update/drop-and-recreate — dev only, data-loss risk). Programmatic `store.Storage.ApplyAllConfiguredChangesToDatabaseAsync()` / `ApplyAllDatabaseChangesOnStartup()` (explicit opt-in, not silent). CLI verbs (Oakton-hosted through the app's own entrypoint): `db-patch` (emit reviewable SQL + rollback script), `db-apply` (execute it), `db-assert` (verify live schema matches config, fail if not — good as a startup health check). Documented recommendation: `CreateOrUpdate`/`All` in dev only, `None` + explicit `db-patch`/`db-apply` in prod. | The graduated-permission enum instead of a single boolean; the `assert`/`patch`/`apply` three-verb split; making "apply on startup" a named, explicit opt-in. This is the model for §4/§9's `DatabaseSetupConfiguration` and CLI verbs. | The `All` level's destructive drop-and-recreate as anything reachable outside local dev — Ecotone's dead-letter/dedup/document-store tables hold data that must never be silently dropped, so even Ecotone's most permissive level should stop at "create + add columns." |
| **Axon Framework / Spring Boot** `JdbcEventStorageEngine`, `EventSchema`/`EventTableFactory`, `spring.sql.init.mode` ([Axon reference](https://docs.axoniq.io/axon-framework-reference/4.11/events/infrastructure/), [Spring Boot data-init doc](https://github.com/spring-projects/spring-boot/blob/v3.4.5/spring-boot-project/spring-boot-docs/src/docs/antora/modules/how-to/pages/data-initialization.adoc)) | Schema creation is an explicit method call (`storageEngine.createSchema(eventTableFactory)`), never automatic — Spring Boot auto-configures the *engine bean* but does not create tables. `spring.sql.init.mode`: `always` (run `schema.sql` regardless of DB), `embedded` (**default** — only against in-memory DBs like H2), `never`. | The `embedded`-as-default philosophy (auto-init convenient only for ephemeral/dev DBs, never for anything that looks like a real connection); `createSchema(tableFactory)` as an explicit method shape for the programmatic API (§5). | The single global switch across the whole app's `schema.sql`/`data.sql` — Ecotone wants per-feature granularity, not one blanket toggle. |
| **Prooph `pdo-event-store`** ([GitHub](https://github.com/prooph/pdo-event-store), [docs](http://docs.getprooph.org/event-store/implementations/pdo_event_store/variants.html)) | Ships versioned raw `.sql` files (`scripts/{mysql,mariadb,postgres}/01_event_streams_table.sql`, `02_projections_table.sql`) the operator runs by hand before first use. **No console command exists** (the task brief's guessed script names don't exist) and **no runtime auto-create at all**. Directly relevant since `pdo-event-sourcing` currently wraps this. | Validation that "zero implicit runtime table creation" is an established, production-proven pattern specifically in event sourcing, not just a generic ORM idea. | The delivery mechanism — static files with no CLI, no idempotency, no cross-DB abstraction, exactly the friction this design should avoid while keeping the underlying principle. |
| **Flyway / Liquibase** baseline pattern ([Baeldung comparison](https://www.baeldung.com/liquibase-vs-flyway), [Bytebase comparison](https://www.bytebase.com/blog/flyway-vs-liquibase/)) | A dedicated marker table (`flyway_schema_history` / `DATABASECHANGELOG`) records every applied migration by version + **checksum**; on each run, unapplied migrations run in order; a changed already-applied file is rejected on checksum mismatch. `flyway baseline` seeds the table for a pre-existing DB without replaying history. | The tracking-table-with-checksum mechanism as the basis for a schema-version marker table (§9); `baseline` as the upgrade path for existing 1.x production databases so they aren't forced to replay history for tables that already exist. | Nothing to avoid — industry-standard mechanism, directly reusable. |
| **Ruby on Rails** engine mountable migrations, `<engine>:install:migrations` ([Rails guide](https://guides.rubyonrails.org/engines.html), [Solid Queue README](https://github.com/rails/solid_queue)) | A gem/engine ships its own migrations; `bin/rails <engine>:install:migrations` **copies** them into the host app's `db/migrate/` (re-timestamped to run after existing app migrations), idempotent re-run ("only copies migrations not already copied"). Then normal `db:migrate` applies them. Solid Queue: `bin/rails generate solid_queue:install`. | The "copy into host-owned, source-controlled migration dir, then apply with the normal tool" shape, keeping schema diffable in the host app's own VCS — informs the `--dump-to-migration` design in §8.3/§8.4. | The file-copy-as-only-mechanism — PHP/attribute-driven Ecotone doesn't have Rails' generator/timestamp convention; a programmatic diff-and-apply (Doctrine/Marten-style) fits better as the primary path, with file-dump as a secondary convenience. |

**Strongest synthesis**: Marten's four-level `AutoCreate` policy plus its `assert`/`patch`/`apply` CLI split,
combined with Flyway/Liquibase's checksum-tracked history table and Doctrine's pluggable schema-provider
contract, sketch almost exactly what Ecotone should land on — each feature module already contributes a
`DbalTableManager` (Doctrine's `SchemaProviderInterface` equivalent); the CLI needs an assert/status verb
alongside the existing create/drop/dump verbs; and production should default to the strict end of a graduated
policy, never the permissive end.

---

## 4. Alternative approaches compared

| # | Approach | DX | Perf / prod safety | Migration cost (1.x → 2.0) | DB portability | Complexity | Notes |
|---|---|---|---|---|---|---|---|
| A | **Do nothing beyond flipping the default flag off** — keep `withAutomaticTableInitialization(bool)` as the only knob, default `false` in prod, `true` in `createForTesting()` | Low — one boolean, no granularity, no per-feature control, error messages just say "table X doesn't exist" | Good once flipped — no more implicit commits | Very low — no API changes needed | N/A, unaffected | Very low | Fastest to ship but doesn't fix table-name gaps (§2.3), doesn't fix the single-connection limitation (§7), doesn't fix deduplication cleanup coupling (§6), doesn't give an upgrade path for existing prod DBs |
| B | **Marten-inspired graduated `AutoCreate` levels, scoped to `None`/`CreateOnly` for 2.0** (`CreateOrUpdate` designed but deferred — see §6.4) applied per environment via a new `DatabaseSetupConfiguration` extension object, keep existing CLI verbs, add `assert`/status | High — dev stays "just works", prod is strict by construction, not by remembering to set a flag; clear error messages naming the exact command to run | Best — `None` in prod removes all runtime schema I/O from the hot path entirely | Medium — new extension object, `DbalConfiguration::withAutomaticTableInitialization` becomes a thin deprecated shim delegating to it | Mixed today, not yet uniform (§6.1: 4 of 6 managers are Doctrine-Schema-API-based, 2 hand-write per-platform SQL with no real SQLite branch — see §7's SQLite row) | Medium | **Recommended** — see §5 |
| C | **Full Doctrine-Migrations-style version-tracked migration engine inside Ecotone** (own migration files, own history table, own `up`/`down`) | High once built, but duplicates a tool most Ecotone users already have (Doctrine Migrations or Laravel's migrator) | Same as B for runtime; extra: schema-version table adds one more table Ecotone owns | High — biggest new surface area, new file format, new runner, new tests | Same | High | Rejected as primary path — Ecotone should integrate with existing migration tooling (§8.4), not reimplement it; a lightweight internal version marker (part of B) is enough to support idempotent re-apply without becoming a competing migration framework |
| D | **Rely entirely on external tooling** (ship only `--sql` dump, no `--initialize`/`--force apply` verbs at all, force every user through Doctrine Migrations/Laravel migrations) | Low for the common case (in-memory/SQLite tests, solo devs, prototypes) — every setup requires leaving Ecotone's CLI | Good | Low — removes verbs rather than adding them | Same | Low | Rejected — breaks `EcotoneLite` "just works" zero-config promise for tests and quick starts; Ecotone's whole value proposition is not making users hand-wire infrastructure |

**Recommendation: B**, built as an additive layer on top of the already-existing `DatabaseSetupManager`/`DatabaseSetupModule`/`DatabaseSetupCommand`,
not a rewrite. It keeps the working CLI surface, fixes the concrete gaps found in §2 (table-name overrides,
single-connection limitation, no assert/diff verb, no scheduled dedup cleanup), and gives multi-tenant/
multi-connection users and production operators the Marten-style safety story the framework's "reliable by
default" principle already promises elsewhere (§3 of the design principles).

---

## 5. Proposed public API

### 5.1 `DatabaseSetupConfiguration` extension object

Mirrors the shape of `DbalConfiguration`/`EventSourcingConfiguration`: immutable, `create*()` named
constructors, `with*()` builders, returned from a `#[ServiceContext]` method. Supersedes
`DbalConfiguration::withAutomaticTableInitialization()` (kept, deprecated, delegating internally) with a
graduated policy and per-connection/per-feature overrides.

**2.0 scope is two levels, not the three originally drafted — see §6.4 for the full cost analysis.**
`CreateOrUpdate` (column-patching on an existing table) needs a new diffing capability that doesn't exist on
any `DbalTableManager` implementation today and isn't buildable at all for two of the six managers without
first migrating them onto the Doctrine Schema API. Shipping `None`/`CreateOnly` now and adding
`CreateOrUpdate` later is a pure, backward-compatible enum addition:

```php
enum AutoCreateLevel
{
    case None;       // never touch schema at runtime; throw ConfigurationException naming the missing table + fix command
    case CreateOnly; // create missing tables only, never alter existing ones — this is what every DbalTableManager::createTable() already does today (§6.1); no new capability required
    // CreateOrUpdate is deferred — see §6.4. Adding it later is additive, not breaking.
}

final class DatabaseSetupConfiguration
{
    public static function createWithDefaults(): self
    {
        return new self(
            defaultLevel: AutoCreateLevel::CreateOnly,
            perConnectionLevel: [],
        );
    }

    public function withAutoCreateLevel(AutoCreateLevel $level): self { /* clone + set default */ }

    public function withAutoCreateLevelForConnection(string $connectionReferenceName, AutoCreateLevel $level): self { /* clone + set override */ }
}
```

Usage — application wiring, following the existing `#[ServiceContext]` convention (`AGENTS.md` / design
principle 2):

```php
final class EcotoneConfiguration
{
    #[ServiceContext]
    public function databaseSetup(): DatabaseSetupConfiguration
    {
        return DatabaseSetupConfiguration::createWithDefaults()
            ->withAutoCreateLevel(AutoCreateLevel::None)
            ->withAutoCreateLevelForConnection('reporting', AutoCreateLevel::CreateOnly);
    }
}
```

`EcotoneLite::bootstrapFlowTesting*()` continues to inject `AutoCreateLevel::CreateOnly` by default (as
`DbalConfiguration::createForTesting()`'s already-`true` auto-init effectively does today — §6.4 established
that today's runtime behaviour has only ever been "create if missing," never column-patching, so this is a
rename of the status quo, not a behaviour change) — this is the "in-memory/SQLite just works" behaviour and
requires **no change in observed behaviour**. New: an explicit, documented escape hatch for tests that point
at a real database
(integration tests), matching what §8 of `upgrade-2.0.md` already drafts:

```php
protected function setUp(): void
{
    $this->ecotone = EcotoneLite::bootstrapFlowTestingWithEventStore(
        [OrderHandler::class],
        runForProductionEventStore: true,
    );
    $this->ecotone->getGateway(DatabaseSetupManager::class)->initializeAll();
}
```

### 5.2 Table-name overrides — close the gap from §2.3

Add the missing `with*Table()` methods to `DbalConfiguration`, matching the existing signature style of
`withEventStreamTableName()`:

```php
$dbalConfiguration = DbalConfiguration::createWithDefaults()
    ->withDeduplicationTable('app_deduplication')
    ->withDeadLetterTable('app_dead_letter')
    ->withDocumentStoreTable('app_document_store');
```

Each flows into the corresponding `*TableManager` constructor exactly as `eventStreamTableName` already
flows into `EventStreamTableManager` — no new mechanism, just filling in the missing constructor argument at
the three call sites identified in §2.3.

### 5.3 CLI commands

All new/changed commands attach via the existing `#[ConsoleCommand]` idiom inside `DatabaseSetupModule::prepare()`
— no framework-specific wiring required (§2.4). **No existing command is renamed** — `ecotone:migration:database:setup`
and `ecotone:migration:database:delete` keep their current names and current flags (`--feature`, `--initialize`,
`--sql`, `--onlyUsed`, `--force`) unchanged; this design only adds new optional flags (`--connection=`,
`--tenant=`) to those two and two brand-new command names. A deploy script calling today's commands with
today's flags keeps working unmodified. Proposed final command set (keeping the two that already exist,
extending their parameters, adding two new ones):

| Command | Status | Purpose | Key options |
|---|---|---|---|
| `ecotone:migration:database:setup` | **exists**, extend | Create all/subset of tables | `--feature=`, `--connection=`, `--tenant=`, `--sql`, `--initialize`, `--onlyUsed` (all present already except `--connection`/`--tenant`, new) |
| `ecotone:migration:database:delete` | **exists**, extend | Drop all/subset | `--feature=`, `--connection=`, `--tenant=`, `--force` (all present already except `--connection`/`--tenant`) |
| `ecotone:migration:database:status` | **new** | "What is missing?" diff/status without side effects — the `db-assert` verb from Marten | `--feature=`, `--connection=`, `--tenant=`, `--strict` (**throws** if anything is missing — for CI/deploy gates; an exit code is not expressible through any framework bridge, Revision 3 R-18) |
| `ecotone:migration:database:dump-sql` | **new**, or fold into `--sql --format=` on the existing setup command | Dump SQL formatted for pasting into Doctrine Migrations (`up()`/`down()` method bodies) or Laravel migrations (`Schema::create` closure skeleton, or raw `DB::statement($sql)`) | `--format=raw\|doctrine-migration\|laravel-migration`, `--feature=`, `--connection=`, `--out=path` |

Symfony console usage:

```bash
bin/console ecotone:migration:database:status --strict
bin/console ecotone:migration:database:setup --feature=deduplication,dead_letter --initialize
bin/console ecotone:migration:database:dump-sql --format=doctrine-migration --out=migrations/Version20260101000000.php
```

Laravel artisan usage (identical command names — the bridge in §2.4 makes this automatic):

```bash
php artisan ecotone:migration:database:status --strict
php artisan ecotone:migration:database:dump-sql --format=laravel-migration --out=database/migrations/2026_01_01_000000_ecotone_setup.php
```

Ecotone Lite (programmatic, e.g. in a deploy script or a test):

```php
$ecotone->runConsoleCommand('ecotone:migration:database:setup', ['initialize' => true]);
```

`--format=doctrine-migration` implementation note: `DatabaseSetupManager::getCreateSqlStatementsForFeatures()`
already returns raw SQL strings per feature (§2.1) — the new command wraps that output in the boilerplate
Doctrine Migrations expects (`public function up(Schema $schema): void { $this->addSql('...'); }`) and the
mirror for `getDropSqlStatementsForFeatures()` as `down()`. No new schema-introspection code needed; this is
templating around data the manager already produces.

`doctrine:migrations:diff` integration (§8.4): expose a `SchemaProviderInterface` implementation
(`Ecotone\Dbal\Doctrine\EcotoneSchemaProvider`) that builds a `Doctrine\DBAL\Schema\Schema` from every
registered `DbalTableManager::getCreateTableSql()` output, so a user's own `doctrine_migrations.yaml` can
register it:

```php
// config/services.yaml or a compiler pass, per Doctrine Migrations' documented extension point
$dependencyFactory->setDefinition(SchemaProvider::class, fn () => $ecotoneSchemaProvider);
```

then `doctrine:migrations:diff` sees Ecotone's tables as part of "the target schema" and picks up drift the
same way it picks up ORM-mapped drift. This is additive — doesn't require every user to adopt it, but gives
Doctrine-Migrations users a first-class integration instead of copy-pasting `--sql` output.

**Limitation, following from §6.1/§6.4**: `EcotoneSchemaProvider` can only build a real `Doctrine\DBAL\Schema\Schema`
object for the four managers that already construct a `Table` object (Deduplication, Enqueue, DeadLetter,
DocumentStore). `ProjectionStateTableManager` and `EventStreamTableManager` hand-write SQL strings with no
`Table` to contribute — for those two, `EcotoneSchemaProvider` either omits them (so `doctrine:migrations:diff`
won't detect drift on event-stream/projection-state tables, only `--sql`/`--dump` cover them) or requires the
same Schema-API migration flagged as a `CreateOrUpdate` prerequisite in §6.4. Documented as a known gap, not
silently glossed over.

**Small point, out of scope for this design**: every `#[ConsoleCommand]` boolean flag in this proposal
(`--sql`, `--initialize`, `--onlyUsed`, `--force`, the new `--strict`) needs the same
`normalizeBoolean(bool|string $value): bool` dance already duplicated in `DatabaseSetupCommand::normalizeBoolean()`,
`DatabaseDeleteCommand::normalizeBoolean()`, and the pre-existing `ChannelSetupCommand`/`ChannelDeleteCommand`
(`packages/Ecotone/src/Messaging/Channel/Manager/`) — four independent copies of the identical workaround
today, because `#[ConsoleParameterOption]` resolves a declared `bool` parameter as `bool|string` (a CLI
`--flag=false` arrives as the string `"false"`, not `false`). The root cause is in the shared console-command
parameter-resolution layer (`packages/Ecotone/Api/ConsoleParameterOption.php`,
`packages/Ecotone/src/Messaging/Config/ConsoleCommandParameter.php`), not in any individual command — fixing
it there once would remove the wart from all four existing copies plus this design's new commands. **Flagged
as out of scope for this topic (Group F2)**: it's a framework-wide console-command concern shared by every
module that registers a `#[ConsoleCommand]`, not specific to database setup.

### 5.4 Programmatic API

`DatabaseSetupManager` (§2.1) is already a solid business-facing interface — inject it directly:

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

Per design principle 4 ("no nullable service dependencies"), `DatabaseSetupManager` is always registered
(it already is, unconditionally, by `DatabaseSetupModule`) — no `?DatabaseSetupManager $x = null` anywhere;
callers that don't need it simply don't inject it.

### 5.5 Multi-connection / multi-tenant setup — new `DatabaseSetupManager` construction

**Round 1 correction — the bug is deeper than "picks the wrong array index."** The original draft described
`DatabaseSetupModule.php:48`'s `$dbalConfiguration->getDefaultConnectionReferenceNames()[0]` as picking the
wrong entry from an otherwise-relevant list. Re-reading the actual source of truth for "which connection does
each feature use" shows the real problem: **there is no manager→connection mapping in the codebase at all**,
so there is nothing for `DatabaseSetupModule` to have picked the wrong entry from in the first place.

**Where connection references actually live** — verified, not `DbalConfiguration::getDefaultConnectionReferenceNames()`
alone:
- `DbalConfiguration::$defaultConnectionReferenceNames` (`packages/Dbal/Api/DbalConfiguration.php:29`,
  default `[DbalConnectionReference::DEFAULT]`) is a **fallback list**, not the full set of connections in use.
- Each feature has its own independently-settable connection reference that, when set, **overrides** the
  fallback entirely: `getDeduplicationConnectionReference()` (`:79-82`) and `getDeadLetterConnectionReference()`
  (`:84-87`) both route through `getMainConnectionOrDefault()` (`:89-104`):
  ```php
  private function getMainConnectionOrDefault(?string $connectionReferenceName, string $type): string
  {
      if ($connectionReferenceName) {
          return $connectionReferenceName;
      }
      if (empty($this->defaultConnectionReferenceNames)) {
          return DbalConnectionReference::DEFAULT;
      }
      if (count($this->defaultConnectionReferenceNames) !== 1) {
          throw ConfigurationException::create("Specify exact connection for {$type}. Got: " . implode(',', $this->defaultConnectionReferenceNames));
      }
      return $this->defaultConnectionReferenceNames[0];
  }
  ```
  i.e. an explicit `withDeduplication(connectionReference: 'reporting')` (`:210`) or `withDeadLetter(connectionReference: 'reporting')`
  (`:221`) wins outright — the "default connection list" is consulted only as a fallback, and only for
  features that didn't set their own override.
- `getDocumentStoreConnectionReference()` (`:330-333`, backed by `withDocumentStore(..., connectionReference: ...)`, `:241`)
  and `getConsumerPositionTrackingConnectionReference()` (`:360-363`, backed by `withConsumerPositionTracking(..., connectionReference: ...)`,
  `:230`) work the same way but default straight to `DbalConnectionReference::DEFAULT` rather than falling
  back through the default-list logic.
- **Event streams are not part of `DbalConfiguration` at all.** `EventSourcingConfiguration::create(string
  $connectionReferenceName = DbalConnectionReference::DEFAULT, ...)` (`packages/PdoEventSourcing/Api/EventSourcingConfiguration.php:42,51`)
  has its own, completely independent connection reference — a real production app running event streams on a
  dedicated `eventstore` connection while deduplication/dead-letter stay on `default` is exactly the kind of
  configuration this framework already supports today.
- `DbalConnectionModule::prepare()` (`packages/Dbal/src/Configuration/DbalConnectionModule.php`) — the
  module that validates connections are wired up — **only ever requires the raw `defaultConnectionReferenceNames`
  list** (`:44`, `$dbalConfiguration->getDefaultConnectionReferenceNames() ?: [DbalConnectionReference::DEFAULT]`)
  to exist as container references; it never consults any feature's per-feature override, so there is no
  central registry of "every connection any feature actually resolved to" anywhere in the framework today.

**The manager itself carries no connection information.** `DbalTableManagerReference`
(`packages/Dbal/src/Database/DbalTableManagerReference.php`) — the extension object every feature module
returns to register its table manager — has exactly one property, `referenceName` (the service id of the
`DbalTableManager` implementation), and nothing else:
```php
final class DbalTableManagerReference
{
    public function __construct(private string $referenceName) {}
    public function getReferenceName(): string { return $this->referenceName; }
}
```
And `DatabaseSetupManager::initializeAll()` (`packages/Dbal/Api/DatabaseSetupManager.php:81-92`)
resolves **one** `Connection` from its own constructor-injected factory and passes that same `$connection`
to every registered manager's `createTable($connection)`, regardless of which connection that manager's
owning feature actually configured. Concretely: if `EventSourcingConfiguration::create('eventstore_connection')`
is used, `EventStreamTableManager` still gets created against whatever connection `DatabaseSetupModule`
picked for the single package-wide `DatabaseSetupManager` — silently targeting the wrong database, not merely
"index 0 of an array that happened to be right most of the time."

**Fix — add the missing mapping, don't just fix the array index.** `DbalTableManagerReference` needs a
connection reference field, supplied by whichever module registers it (each module already knows its own
resolved connection — `DeduplicationModule::prepare()` already calls
`$dbalConfiguration->getDeduplicationConnectionReference()` at line 59 to wire the interceptor itself, so
passing that same value into the reference costs nothing):

```php
final class DbalTableManagerReference
{
    public function __construct(
        private string $referenceName,
        private string $connectionReferenceName,
    ) {}

    public function getReferenceName(): string { return $this->referenceName; }
    public function getConnectionReferenceName(): string { return $this->connectionReferenceName; }
}
```

`DatabaseSetupModule::prepare()` then groups the resolved `DbalTableManagerReference[]` by
`getConnectionReferenceName()` and builds **one `DatabaseSetupManager` per distinct connection actually in
use** (not per entry in `defaultConnectionReferenceNames` — that list may not even contain every connection a
feature resolved to), plus one per entry in `MultiTenantConfiguration::getTenantToConnectionMapping()` when
present (§5.6):

```php
final class DatabaseSetupRegistry
{
    /** @param array<string, DatabaseSetupManager> $managersByConnection */
    public function __construct(private array $managersByConnection) {}

    public function forConnection(string $connectionReferenceName): DatabaseSetupManager { /* ... */ }

    /** @return string[] */
    public function connectionNames(): array { /* ... */ }
}
```

The CLI's `--connection=` and `--tenant=` options resolve against this registry rather than a single manager.

**Small point on long-running CLI reliability**: `DatabaseSetupManager::getConnection()`
(`packages/Dbal/Api/DatabaseSetupManager.php:235-241`) calls
`$this->connectionFactory->createContext()->getDbalConnection()` — no statement-level retry there, but the
factory it's actually registered with is not a bare connection factory: `DatabaseSetupModule::prepare()`
wraps it in `DbalReconnectableConnectionFactory` (`packages/Dbal/src/Database/DatabaseSetupModule.php:58-60`,
`new Definition(DbalReconnectableConnectionFactory::class, [new Reference($connectionReference)])`), whose
`createContext()` (`packages/Dbal/src/DbalReconnectableConnectionFactory.php:32-41`) detects a disconnected
connection and closes+reconnects automatically before handing it back. So a dropped connection between two
features in a long-running multi-connection `initializeAll()` run self-heals on the next call; what's genuinely
missing is `RetryRunner`/exponential-backoff retry of a statement that fails *mid-flight* (the pattern
`DbalTransactionInterceptor` uses for `beginTransaction()`, §1.1) — worth adding if the new
`DatabaseSetupRegistry` (above) starts driving many-connection/many-tenant runs in one process, but not
present today and not required to fix the connection-mapping bug this section addresses; noted as a
nice-to-have in the implementation plan (§9) rather than a blocker.

### 5.6 Multi-tenant enumeration — verified static, not dynamic

**Verified, not assumed** — the original draft's claim that `MultiTenantConfiguration::getTenantToConnectionMapping()`
is "a static array known at boot" holds up under scrutiny; here is the verification chain rather than a
restated assertion:
- `MultiTenantConfiguration` (`packages/Dbal/Api/MultiTenantConfiguration.php:18-24`, `Ecotone\Api\Dbal`) takes
  `array $tenantToConnectionMapping` as a plain constructor argument via `create()`/`createWithDefaultConnection()`
  (`:29-40`) and exposes it unchanged via `getTenantToConnectionMapping(): array` (`:55-58`) — never mutated
  after construction, no setter exists.
- The actual runtime consumer, `HeaderBasedMultiTenantConnectionFactory` (`packages/Dbal/src/MultiTenant/HeaderBasedMultiTenantConnectionFactory.php`),
  receives that same array as `private array $connectionReferenceMapping` in its constructor (`:40`) and only
  ever **reads** it — `getCurrentConnectionReferenceOrNull()` (`:183-192`) does `isset($this->connectionReferenceMapping[$currentTenant])`,
  a plain array lookup, falling back to `$this->defaultConnectionName` when the tenant isn't a key.
- `#[WithTenantResolver]` (`packages/Dbal/Api/WithTenantResolver.php`, `Ecotone\Api\Dbal`, wired by
  `MultiTenantConnectionFactoryModule::prepare()` at `packages/Dbal/src/MultiTenant/Module/MultiTenantConnectionFactoryModule.php:71`)
  resolves **which tenant a given inbound message belongs to** (an expression evaluated against message
  headers) — it has nothing to do with *defining* the set of tenants, and doesn't add entries to the mapping.
- There is no callable/provider abstraction anywhere in `packages/Dbal/src/MultiTenant/` and no DB-backed
  tenant registry — confirmed by reading every file in that directory
  (`MultiTenantConnectionFactory.php`, `MultiTenantHeaderResolver.php`,
  `HeaderBasedMultiTenantConnectionFactory.php`, `Module/MultiTenantConnectionFactoryModule.php`, plus
  `packages/Dbal/Api/MultiTenantConfiguration.php`).

So `--tenant=` enumeration against `getTenantToConnectionMapping()` (§5.5) is correct for the codebase as it
exists today — `--tenant=all` (or no flag) can safely iterate the whole array with no additional runtime
discovery mechanism needed. **Forward-compatibility note, not a current bug**: if a future version introduces
dynamic tenant resolution (e.g. a DB-backed tenant registry, common in larger multi-tenant SaaS setups), this
static-enumeration contract breaks — `--tenant=` would need to become either a required explicit
`--tenant=x --tenant=y` list (no more "all") or a new `TenantProvider` interface the CLI can call to enumerate
tenants at run time. Not needed today; flagged so nobody is surprised if `MultiTenantConfiguration` grows a
dynamic resolution mode later without this CLI being revisited.

---

## 6. Internals

### 6.1 `DbalTableManager` DDL — portable, but **not idempotent at the SQL level** (correction from Round 1)

**Round 1 correction.** The original draft claimed table creation was "already Doctrine-Schema-API-based,
portable" and (in §7's concurrency row) that `getCreateTableSql()` already used `CREATE TABLE IF NOT EXISTS`
semantics. Re-reading every manager shows this is only half true, and the idempotency half is wrong for most
of them:

- **Check-then-create (TOCTOU), not `IF NOT EXISTS`**, for the four Doctrine-Schema-API managers:
  `DeduplicationTableManager::createTable()` (`packages/Dbal/src/Database/DeduplicationTableManager.php:52-59`):
  ```php
  public function createTable(Connection $connection): void
  {
      if (self::isInitialized($connection)) {
          return;
      }
      SchemaManagerCompatibility::getSchemaManager($connection)->createTable($this->buildTableSchema());
  }
  ```
  Identical shape in `EnqueueTableManager::createTable()` (`packages/Dbal/src/Database/EnqueueTableManager.php:52-59`),
  `DeadLetterTableManager::createTable()` (`:56-63`), `DocumentStoreTableManager::createTable()` (`:51-58`).
  `getCreateTableSql()` on all four calls `$connection->getDatabasePlatform()->getCreateTableSQL($table)`,
  which emits a **plain `CREATE TABLE`** — Doctrine's platform generator does not add `IF NOT EXISTS` unless
  told to, and none of these call sites tell it to. So two processes racing `createTable()` concurrently can
  both pass the `isInitialized()` check before either one's `CREATE TABLE` lands, and the second `CREATE
  TABLE` throws `TableAlreadyExistsException` instead of silently succeeding.
- **Only two managers emit literal `CREATE TABLE IF NOT EXISTS`**: `ProjectionStateTableManager`
  (`packages/PdoEventSourcing/src/Database/ProjectionStateTableManager.php:100`, `:114`, hand-written SQL, see
  next point) and `EventStreamTableManager` (hand-written SQL per platform, see next point). These two *are*
  safe against the TOCTOU race because the DDL itself is idempotent, independent of the `isInitialized()`
  pre-check.
- **`EventStreamTableManager` and `ProjectionStateTableManager` are not Doctrine-Schema-API-based at all** —
  they hand-write SQL strings per platform. `EventStreamTableManager::getCreateTableSql()`
  (`packages/PdoEventSourcing/src/Database/EventStreamTableManager.php:45-56`) branches
  Postgres → MariaDB → **else MySQL** (`getMysqlCreateSql()`, backticked identifiers, `ENGINE=InnoDB`), so any
  platform that is neither Postgres nor MariaDB — including SQLite — silently gets MySQL DDL and would fail
  to execute it. `ProjectionStateTableManager::getCreateTableSql()` (`:47-54`) branches only
  `AbstractMySQLPlatform` vs. **else Postgres SQL** — so SQLite gets Postgres's `JSON`/`JSONB`-flavoured DDL
  instead. Neither manager has a real SQLite branch (see §7's SQLite row and Open Question 4b below).

So the `DbalTableManager` interface docblock ("Should use `CREATE TABLE IF NOT EXISTS`") is an **unenforced
contract that 4 of 6 non-legacy implementations violate**. This directly affects §7's concurrency and
"failure mid-migration" rows (both rewritten below) and adds a concrete, scoped item to the implementation
plan (§9, new step): make every manager's DDL genuinely idempotent, either by
(a) switching `getCreateTableSQL()` calls to pass `['IF NOT EXISTS' => true]`-equivalent generation where the
platform supports it — Doctrine's `AbstractPlatform::getCreateTableSQL()` does not take such a flag, so this
means building the `CREATE TABLE IF NOT EXISTS` prefix manually for the four Schema-API managers, mirroring
what `ProjectionStateTableManager`/`EventStreamTableManager` already do by hand — or
(b) keeping check-then-create but catching the platform's "already exists" exception
(`Doctrine\DBAL\Exception\TableAlreadyExistsException`, cross-driver via DBAL's `ExceptionConverter`) around
the `createTable()` call and treating it as success, or
(c) an advisory lock around the whole `initializeAll()`/`initialize()` call (§7's concurrency row).
**Recommendation**: (a) for the SQL emitted by `--sql`/`--dump` (so the SQL a user pastes into a migration is
itself safely re-runnable, matching Doctrine Migrations/Marten conventions), combined with (b) as a defensive
belt-and-braces catch around the CLI's own `createTable()` invocation (cheap, and covers the four managers
without touching their DDL). (c) is still worth adding for `--initialize` (see §7) but doesn't by itself fix
the SQL-dump use case, where there's no in-process lock to take.

### 6.2 Removing the implicit-commit workaround

Once `AutoCreateLevel::None` is the deploy-time default and every table is guaranteed to already exist:

1. `DbalTransactionInterceptor::transactional()` (§1.1) drops the `ImplicitCommit::isImplicitCommitException`
   branch entirely — `commit()` failing becomes a hard error again, since nothing inside the transaction can
   legitimately issue DDL any more.
2. `DeduplicationInterceptor::deduplicate()` drops **only the platform half** of the condition at line 101 —
   **Round 1 correction, this was wrong in the original draft.** The original text said the
   insert-before-`proceed()` trick "becomes safe to run unconditionally on every platform," which is not
   true and would introduce message loss. Re-reading `DeduplicationInterceptor.php:99-113`:
   ```php
   $isTransactionActive = $connection->isTransactionActive() && $connection->getDatabasePlatform() instanceof PostgreSQLPlatform;
   if ($isTransactionActive) {
       $this->insertHandledMessage($connectionFactory, $messageId, $consumerEndpointId, $routingSlip);
   }
   $result = $methodInvocation->proceed();
   if (! $isTransactionActive) {
       $this->insertHandledMessage($connectionFactory, $messageId, $consumerEndpointId, $routingSlip);
   }
   ```
   the flag is false for **two independent reasons**: (i) the platform isn't Postgres, or (ii) there is no
   active transaction at all — deduplication used without `DbalTransaction`, or an endpoint marked
   `#[WithoutDatabaseTransaction]`. The fix in this design only removes reason (i); reason (ii) must stay,
   because insert-before-`proceed()` with **no transaction** means: if the handler then throws, the
   deduplication row is already committed (nothing to roll it back), the message is permanently marked
   handled, and the retry is silently swallowed — real message loss, not merely a missed optimization. The
   correct 2.0 condition is:
   ```php
   $insertBeforeProceeding = $connection->isTransactionActive();
   ```
   i.e. drop `&& $connection->getDatabasePlatform() instanceof PostgreSQLPlatform` and keep everything else
   identical — insert-before-`proceed()` whenever *any* platform has an active transaction (now safe on MySQL
   too, since no DDL can occur inside it any more once `AutoCreateLevel::None` removes on-the-fly table
   creation), insert-after-`proceed()` when there is no active transaction, exactly as today. See the new
   "deduplication without an active transaction" row in §7.

   **Non-DBAL transaction managers** (e.g. Doctrine ORM's `EntityManager` demarcating its own transaction):
   `Connection::isTransactionActive()` returns `$this->transactionNestingLevel > 0`
   (`vendor/doctrine/dbal/src/Connection.php:348-350`), a **purely in-memory counter local to that specific
   DBAL `Connection` object instance**, incremented only by calls to `beginTransaction()` on that same
   object (`vendor/doctrine/dbal/src/Connection.php:1052-1054`) — it is not a query against the database and
   has no visibility into transactions opened through a different `Connection` instance. So: if
   `DbalConfiguration::withDoctrineORMRepositories(connectionReferenceName: ...)` points the ORM at the
   **same** connection reference/container service as deduplication, `EntityManager::wrapInTransaction()`
   ultimately calls `beginTransaction()` on that same object and `isTransactionActive()` correctly reports
   it. If the ORM (or any other transaction manager) uses a **different** connection reference — a distinct
   DBAL `Connection` object, even against the same physical database — `isTransactionActive()` on
   deduplication's connection returns `false` even though a business-level transaction is open elsewhere,
   and the insert-after-`proceed()` path runs: the deduplication row commits independently of whatever
   external transaction later rolls back. This is a narrower inconsistency than the no-transaction case above
   (the message is still processed correctly once; it just can't be *retried* if the external transaction
   rolls back after the deduplication row was already written) but is real and worth calling out explicitly
   as a documented limitation rather than silently accepting it.
3. `ImplicitCommit.php` is deleted.
4. `createDataBaseTable()` calls throughout the DBAL package (deduplication, dead-letter, document-store,
   channel adapters, event-store) become conditional on `AutoCreateLevel::CreateOnly` only (§6.2a covers what
   `None` does instead of silently creating).

### 6.2a Detecting a missing table under `AutoCreateLevel::None`

**Gap identified in Round 1.** The original draft asserted `None` "throws a `ConfigurationException` naming
the missing table and the exact CLI command" without saying how that detection happens. It doesn't happen
today: none of the runtime call sites (`DbalOutboundChannelAdapter::initialize()`,
`DeduplicationInterceptor::deduplicate()`, `DbalDeadLetterHandler`, `DbalDocumentStore`, ...) check table
existence before running their query — under `None` (auto-create disabled) they would simply run the query
and let the driver fail, surfacing a raw `Doctrine\DBAL\Exception\TableNotFoundException`
(`vendor/doctrine/dbal/src/Exception/TableNotFoundException.php`) with a driver-specific message ("Base table
or view not found", `relation "..." does not exist`, etc.) deep inside DBAL — not the actionable,
Ecotone-branded message the design principle promises.

Two ways to close this gap:

| Option | Mechanism | Cost |
|---|---|---|
| **Existence pre-check** | Before the first query on each connection per process, call `$manager->isInitialized($connection)` (the same check `createTable()` already does) and throw immediately if false; cache the result per `contextId` the same way `DeduplicationInterceptor::$initialized` already caches "table was created" (`DeduplicationInterceptor.php:37,63-66`) | One extra round-trip per connection per process (amortized to ~zero after the first message), but still TOCTOU — the table could be dropped by an operator between the check and a later query, and the cache would then mask that until the process restarts |
| **Catch-and-rethrow** (recommended) | Do nothing extra on the happy path; catch `Doctrine\DBAL\Exception\TableNotFoundException` at the same call sites that used to call `createDataBaseTable()`, and rethrow with an Ecotone-branded message | Zero overhead when the table exists (the overwhelming majority of calls, once setup has run); the cost is paid exactly once, on the failure path, where a slower/clearer error is a feature, not a bug; no TOCTOU window, no cache to keep coherent |

**Recommendation: catch-and-rethrow.** It fits the same shape already used for the current implicit-commit
detection (`ImplicitCommit::isImplicitCommitException` — catch a DBAL exception type, react) and removing the
implicit-commit branch (point 1 above) is a like-for-like swap, not a net-new pattern:

```php
private function createDataBaseTable(ConnectionFactory $connectionFactory): void
{
    if ($this->autoCreateLevel === AutoCreateLevel::None) {
        return;
    }
    // AutoCreateLevel::CreateOnly path — unchanged from today's shouldBeInitializedAutomatically()
    $connection = $this->getConnection($connectionFactory);
    $this->tableManager->createTable($connection);
}
```

and at the actual query site (e.g. inside `deduplicate()`, wrapping the `createQueryBuilder()->executeQuery()` call):

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
        "Deduplication table '%s' does not exist. Run `ecotone:migration:database:setup --feature=deduplication --initialize`" .
        ' or `ecotone:migration:database:dump-sql --feature=deduplication` to generate a migration.',
        $this->getTableName()
    ), previous: $exception);
}
```

`TableNotFoundException` is a stable, cross-driver DBAL abstraction (converted from platform-specific
SQLSTATE codes by DBAL's `ExceptionConverter` for every supported driver — MySQL, MariaDB, PostgreSQL,
SQLite), so this single `catch` clause is correct on every platform Ecotone supports, unlike the
platform-scoped string-matching `ImplicitCommit` class it replaces.

### 6.3 Deduplication cleanup out of the handler transaction

Design comparison for where `removeExpiredMessages()` should run, given #438's evidence that it currently
can hold row locks inside the same transaction as user handler code:

| Option | How it works | Multi-tenant / multi-connection | Trade-off |
|---|---|---|---|
| **A — dedicated scheduled endpoint** (recommended) | A `#[Scheduled]`/poller-backed endpoint per DBAL connection that has deduplication enabled, calling `DeduplicationInterceptor::removeExpiredMessages()` on a fixed interval, running in its own transaction (or `WithoutDatabaseTransaction`), independent of any handler invocation | Register one scheduled endpoint per connection reference that has `isDeduplicatedEnabled()` — natural fit with the existing `DbalConfiguration::withDeduplication(connectionReference: ...)` per-feature connection setting; for multi-tenant, one endpoint per tenant connection | Needs a running consumer process (already true for any `#[Asynchronous]` deployment — Group J makes this the norm in 2.0); simplest to reason about, matches issue #438's own ask ("move to cron") |
| **B — CLI command, externally scheduled (cron/k8s CronJob)** | Keep `ecotone:deduplication:remove-expired-messages` (already exists, §2.4) as the only mechanism; operators wire it into their own scheduler | No Ecotone-side multi-tenant/multi-connection logic — a `--connection=`/`--tenant=` flag lets the operator's cron target each one explicitly | Zero new runtime code; but relies on every deployment remembering to schedule it — silent data growth (unbounded `ecotone_deduplication` table) if forgotten; weaker "reliable by default" story |
| **C — probabilistic in-handler cleanup with `SKIP LOCKED`** | Every N-th deduplication check also runs a small `DELETE ... LIMIT batch SKIP LOCKED`-guarded cleanup, still inside the handler transaction but non-blocking (`SKIP LOCKED` means it never waits on a lock held by a concurrent cleanup) | Complex — `SKIP LOCKED` semantics differ across MySQL 8/MariaDB/Postgres, and multi-tenant would multiply the probability check per tenant, defeating the "spread load" intent | Rejected as primary mechanism — still runs inside the business transaction, so it doesn't fix the root cause #438 identifies (a `DELETE` inside the same transaction as the handler, regardless of `SKIP LOCKED`), it only shrinks the blast radius |

**Recommendation**: A, with B kept as the underlying mechanism (the scheduled endpoint just calls the same
CLI-reachable service activator on an interval) — this satisfies both "Ecotone works out of the box in a
process-based deployment" and "operators who prefer their own scheduler can keep doing so." C is rejected
outright: it doesn't address the transactional-coupling root cause, only shrinks its window.

```php
final class DeduplicationCleanupConfiguration
{
    #[ServiceContext]
    public function scheduledCleanup(): PollableChannelConfiguration
    {
        return SimpleMessageChannelBuilder::createQueueChannel('ecotone.deduplication.cleanup');
    }
}
```

registered by `DeduplicationModule` itself when `isDeduplicatedEnabled()` is true, reusing the existing
`removeExpiredMessages` service-activator method and `ecotone.deduplication.removeExpiredMessages` channel
(`packages/Dbal/src/Deduplication/DeduplicationModule.php:102-113`) — just adding a `#[Poller]`/scheduled
trigger instead of leaving it CLI-only.

### 6.4 `CreateOrUpdate` is not implementable against today's `DbalTableManager` contract — 2.0 scope decision

**Round 1 finding — this changes the recommended scope of §4/§5.1.** `DbalTableManager` (§2.1) exposes
`getCreateTableSql()` / `createTable()` / `isInitialized()`, and `isInitialized()` is only `tableExists()`
(`SchemaManagerCompatibility::tableExists()`, a schema-manager `tablesExist([$name])`/`introspectSchema()->hasTable()`
call — see every manager's `isInitialized()`, e.g. `DeduplicationTableManager.php:72-75`). **None of that can
alter an existing table.** There is no "does this table have the columns I expect" check and no "add the
missing ones" capability anywhere in the interface or any implementation. `AutoCreateLevel::CreateOrUpdate`
as originally drafted (§5.1: "create + add missing columns/indexes, never drop") requires a genuinely new
capability on every manager, not a policy flag on top of what exists.

**What the new capability would have to be, and its real cost:**

- A new interface method, e.g. `getUpdateSql(Connection $connection): array` (or `array<Table>`), returning
  the `ALTER TABLE` statements needed to bring an existing table in line with the manager's target shape.
- For the **four Doctrine-Schema-API managers** (Deduplication, Enqueue, DeadLetter, DocumentStore — §6.1),
  this is buildable with Doctrine's own schema-diffing: introspect the live table
  (`$connection->createSchemaManager()->introspectTable($tableName)`), build the target `Table` via the
  manager's existing `buildTableSchema()`, diff them with `Doctrine\DBAL\Schema\Comparator`
  (`AbstractSchemaManager::createComparator()` in DBAL 4.x / the static `Comparator::compareTables()` in
  3.x — both are already a transitive dependency via `doctrine/dbal: ^3.9|^4.0`, so no new package), and turn
  the resulting `TableDiff` into SQL via `$platform->getAlterTableSQL($diff)`. This is real but bounded work
  — roughly one shared helper trait plus one call site per manager.
- For the **two hand-written-SQL managers** (`ProjectionStateTableManager`, `EventStreamTableManager` — §6.1)
  there is **no `Table` object to diff against at all** — they emit raw heredoc SQL strings per platform.
  `getUpdateSql()` for these would mean either (a) hand-writing per-platform `ALTER TABLE` statements for
  every future column addition (the same maintenance burden Flyway/Liquibase-style versioned migrations
  exist to avoid), or (b) migrating both managers onto the Doctrine Schema API first (a prerequisite refactor
  with its own risk — `EventStreamTableManager` is tagged `licence Enterprise`, see Open Question 8, and its
  raw SQL includes engine-specific tuning like `ENGINE=InnoDB DEFAULT CHARSET=utf8mb4` that a generic
  `Table`/`Comparator` round-trip may not reproduce exactly).

**Recommendation: ship a 2-level policy for 2.0 — `None` and `CreateOnly` only — and defer `CreateOrUpdate`.**
`CreateOnly` ("create missing tables, never alter existing ones") is achievable with **zero new capability**:
it is exactly what every manager's `createTable()` already does today (create if missing, full stop — none
of them have ever patched columns, so `CreateOnly` is actually the *honest* name for 1.x's status quo
behaviour; the original draft's assumption that today's default was somehow "create or update" was itself
wrong, since no manager has ever supported updating). Column-patching (`CreateOrUpdate`) is real, valuable,
scoped work — but it's new work gated on (a) building the `Comparator`-based diff helper for the four
Schema-API managers and (b) a separate decision about whether/how to bring the two raw-SQL managers onto the
same footing — and shipping a 2.0-scoped `AutoCreateLevel::None|CreateOnly` enum today doesn't foreclose
adding `CreateOrUpdate` later as a pure backward-compatible addition (new enum case, no removal). Every
mention of `CreateOrUpdate` elsewhere in this report (§5.1's enum, §6.2 point 4, §7's edge cases, the
`upgrade-2.0.md` draft in §8, the implementation plan in §9) has been updated to reflect the 2-level 2.0
scope, with `CreateOrUpdate` tracked as an explicit deferred follow-up rather than shipped half-built.

### 6.5 Schema-version marker table — deferred alongside `CreateOrUpdate`

The original draft justified `ecotone_schema_version` (one row per feature per connection: `feature_name`,
`schema_version`, `applied_at`, modeled on Flyway/Liquibase, §3) as "a cache for `CreateOrUpdate`, so it
doesn't have to re-diff live schema on every run." **With `CreateOrUpdate` deferred (§6.4), that justification
no longer applies for 2.0** — there is nothing to cache a diff result *for* if the diff itself isn't being
computed. The marker table doesn't earn an independent place in 2.0 scope on its own merits either: `status`/
`--strict` (§5.3) already gets a correct, sufficient answer from `isInitialized(Connection)` (a real
existence check) without needing a separate bookkeeping table, and introducing one now would itself be an
eighth table this design has to define DDL for, gate behind the same `None`/`CreateOnly` policy, and migrate
existing 1.x databases around — cost with no consumer. **Recommendation: drop `ecotone_schema_version` from
2.0 scope entirely**, and revisit it only alongside `CreateOrUpdate` implementation, where it has a genuine
job (short-circuiting repeated `Comparator` diffs). Implementation plan step 10 (§9) has been updated to
reflect this.

---

## 7. Edge cases

| Case | Behaviour |
|---|---|
| **Concurrency**: two app instances boot simultaneously against the same fresh DB, both auto-create at `CreateOnly` | **Corrected in Round 1** — the original row claimed this was already safe via `IF NOT EXISTS`; §6.1 established that's true for only 2 of 6 managers. For the four Doctrine-Schema-API managers, two processes racing `createTable()` (check-then-create) can both pass `isInitialized()` before either `CREATE TABLE` lands; the loser gets `TableAlreadyExistsException`, which is why §6.1 recommends catching that exception around the CLI's own `createTable()` call as a defensive floor, on top of fixing the SQL to use `CREATE TABLE IF NOT EXISTS` where the platform allows it. For the CLI's explicit `--initialize`, additionally take an advisory lock (`pg_advisory_lock`/MySQL `GET_LOCK`) around `initializeAll()` so two concurrent `ecotone:migration:database:setup --initialize` invocations in a deploy pipeline serialize rather than race |
| **Ordering & gaps** in applying schema changes across features | Not applicable to table *creation* (each feature's table is independent, no FK relationships between Ecotone's own tables) — no ordering constraint needed between e.g. deduplication and document-store setup |
| **Multi-tenancy** | Verified in §5.6: `MultiTenantConfiguration::getTenantToConnectionMapping()` is a static array, never mutated after construction, with no dynamic-resolution path anywhere in `packages/Dbal/src/MultiTenant/` — `--tenant=` iterates it directly, no runtime tenant discovery needed (§5.5). Requires Enterprise licence per Group A of the release plan; the setup CLI itself should stay open-core (setup is infrastructure, not a business feature) but multi-tenant *enumeration* only becomes available when the licensed `MultiTenantConfiguration` extension object is present |
| **Multiple DB connections** | **Corrected in Round 1 — deeper than an array-index bug.** §5.5 established there is no manager→connection mapping in the codebase today (`DbalTableManagerReference` carries only a service reference name, no connection), so `DatabaseSetupModule.php:48`'s `[0]` isn't picking the wrong entry from a relevant list — it's the only connection any manager is ever run against, full stop, regardless of what connection its owning feature actually resolved to (e.g. `EventSourcingConfiguration::create('eventstore_connection')` is a real, already-supported way to put event streams on a separate connection, and today's `DatabaseSetupManager` would still try to create that table against the DBAL package's default connection). Fixed by adding a `connectionReferenceName` field to `DbalTableManagerReference` and building one `DatabaseSetupManager` per distinct connection via the new `DatabaseSetupRegistry` (§5.5) — this must be fixed regardless of the rest of this design; it's a latent, silent mistargeting bug today, not merely an ergonomic gap |
| **PostgreSQL vs MySQL vs MariaDB vs SQLite** | Implicit commit: MySQL/MariaDB-only failure mode (`ImplicitCommit` is scoped to `AbstractMySQLPlatform`); Postgres has transactional DDL so it never hit this bug. Table creation portability is **not uniform** (corrected in §6.1) — see the per-feature SQLite matrix below, the row directly beneath this one |
| **SQLite support per feature** (new row, added in Round 1 per challenge C2) | SQLite is a genuinely supported/tested connection in this repo (`docker-compose.yml:17,41` sets `SQLITE_DATABASE_DSN`; `DbalConnectionFactory` parses `sqlite`/`sqlite3`/`sqlite+pdo` schemes, `packages/Dbal/src/Connection/DbalConnectionFactory.php:140-142,151,280`; `DbalMessagingTestCase::isUsingSqlite()`/multi-tenant SQLite DSN handling, `packages/Dbal/tests/DbalMessagingTestCase.php:63-66,151-152`) — but **only for the four DBAL-native, Doctrine-Schema-API-backed features**: message queue/outbox (`enqueue`), dead letter, deduplication, document store (and consumer-position tracking, which rides on document store). **Event streams and projection state (`packages/PdoEventSourcing`) are never exercised against SQLite anywhere in this codebase** — zero SQLite references in `packages/PdoEventSourcing/src` or `packages/PdoEventSourcing/tests` (verified by grep). Worse, their DDL actively mistargets SQLite if attempted: `ProjectionStateTableManager::getCreateTableSql()` (`:47-54`) branches only MySQL vs. *else Postgres*, so SQLite gets Postgres's `JSON`-typed DDL; `EventStreamTableManager::getCreateTableSql()` (`:45-56`) branches Postgres → MariaDB → *else MySQL*, so SQLite gets MySQL's backticked, `ENGINE=InnoDB` DDL. **Conclusion, stated explicitly rather than left ambiguous: SQLite is a supported target for message_queue/outbox, dead_letter, deduplication, document_store, and consumer_position — it is *not* a supported target for event_streams or projection_state today**, and this design does not silently paper over that; see Open Question 4b |
| **Very large streams / tables** | `CreateOrUpdate`-driven `ALTER TABLE ... ADD COLUMN` is deferred out of 2.0 scope entirely (§6.4) — the corresponding risk (locking a large populated table) is deferred with it. What remains in 2.0 scope (`CreateOnly`, i.e. create-if-missing) never touches a table that already exists, so this edge case has no 2.0-scoped mitigation to design; revisit alongside `CreateOrUpdate` implementation |
| **Replay & rebuild** | Out of scope for this topic — event-store replay/rebuild is Group B/D territory; the setup CLI's only interaction is ensuring `ecotone_projection_state`/event-stream tables exist before a rebuild command runs, which `ecotone:migration:database:status --strict` in a pre-flight CI step covers |
| **Failure mid-migration** (e.g. `--initialize` creates 3 of 7 feature tables, then the process dies) | **Corrected in Round 1** — the original row claimed every manager's `createTable()` is "already idempotent (`IF NOT EXISTS`)"; §6.1 established that's true for only 2 of 6. Re-running `--initialize` (or `--feature=` for the remaining ones) after a mid-run crash is still safe **once the §6.1 idempotency fix (or the `TableAlreadyExistsException`-catching fallback) lands** — before that fix, a crash-and-retry on a partially-created table set could throw on the table that was mid-creation when the process died, depending on exactly how far the DDL got. No transaction wraps the whole `initializeAll()` loop (`DatabaseSetupManager.php:81-92`) and it shouldn't, since DDL isn't meaningfully transactional across MySQL anyway — but "each call is independent" and "each call is safely re-runnable" are two different claims, and only the fixed version of §6.1 guarantees the second |
| **Transactional boundaries and rollback** | This is the core problem being fixed (§1) — once `AutoCreateLevel::None` is the production default, no DDL ever executes inside `DbalTransactionInterceptor`'s transaction, so a handler rollback can never leave a "table created but data rolled back" inconsistency |
| **Deduplication without an active transaction** (new row, added in Round 1 per challenge C3) | `#[WithoutDatabaseTransaction]` endpoints, or deduplication configured on a connection the current `DbalTransaction` doesn't cover: `DeduplicationInterceptor::deduplicate()`'s `$connection->isTransactionActive()` check (§6.2 point 2) is false, so the interceptor inserts the deduplication row **after** `$methodInvocation->proceed()` returns, exactly as it does today — unchanged behaviour, not a regression introduced by this design, but worth stating explicitly: deduplication provides no atomicity guarantee with the handler's own side effects on such endpoints, same as 1.x |
| **Missing table under `AutoCreateLevel::None`** (new row, added in Round 1 per challenge C7) | No existence pre-check runs by default (§6.2a) — the query executes, the driver throws `Doctrine\DBAL\Exception\TableNotFoundException`, and the DBAL/Ecotone call site catches that specific exception type and rethrows a `ConfigurationException` naming the missing table and the exact `ecotone:migration:database:setup`/`dump` command to run. Zero overhead on the happy path (the common case, once setup has run); the cost is paid only on the failure path |
| **Tests: `EcotoneLite` in-memory vs real DB** | In-memory/SQLite: `AutoCreateLevel::CreateOnly` stays the default via `DbalConfiguration::createForTesting()` / new `DatabaseSetupConfiguration` test default — unchanged "just works" behaviour (renamed from the originally-drafted `CreateOrUpdate` default per §6.4 — today's actual test behaviour has only ever been create-if-missing, so this is not a behaviour change). Real DB pointed at from a test (`runForProductionEventStore: true` or a custom `DbalConfiguration`): today auto-creates silently regardless (§2.6 finding) — this design proposes making that an explicit call, `$ecotone->getGateway(DatabaseSetupManager::class)->initializeAll()` in `setUp()`, matching the already-drafted text in `upgrade-2.0.md` §8 |
| **Licence gating** | Table setup/CLI itself is open-core (it's operational tooling, not a monetizable feature) — this matches `DatabaseSetupModule`'s current `licence Apache-2.0` tag. The one anomaly found: `EventStreamTableManager` is tagged `licence Enterprise` in its own docblock (`packages/PdoEventSourcing/src/Database/EventStreamTableManager.php:17`) while `ProjectionStateTableManager` next to it is `licence Apache-2.0` — needs a maintainer decision, see Open Question 8 |

---

## 8. Migration impact for users

Draft addition to `upgrade-2.0.md` §8 ("Database tables are no longer created on the fly") — the section
already exists and its framing is accurate; this expands it with the concrete new API surface from §5. (Note:
the block below is quoted content for that document, not a section of *this* report — it is deliberately
**not** a `##`-level heading in this file, to avoid the duplicate-`## 8.` numbering flagged in Round 1.)

```markdown
### Database tables are no longer created on the fly

**Before:** `DbalTransactionInterceptor` and `DeduplicationInterceptor` created `ecotone_deduplication`,
`ecotone_error_messages`, `enqueue`, etc. during the first message, and on MySQL committed the surrounding
transaction implicitly. PostgreSQL received a special-case branch to work around it.

**Now:** Tables are created only through the CLI or your own migrations, controlled by an `AutoCreateLevel`.
The default in production is `AutoCreateLevel::None` — Ecotone throws a `ConfigurationException` naming the
missing table and the exact command to run rather than creating it. `EcotoneLite` test bootstraps keep
`AutoCreateLevel::CreateOnly` (create-if-missing — the same behaviour tests have always had), so
in-memory/SQLite tests are unaffected. `AutoCreateLevel::CreateOrUpdate` (patching columns on an existing
table) is designed but not shipped in 2.0 — see the design report's §6.4 for why.

**How to adapt:**

\`\`\`php
// 1.x — nothing to configure, tables appeared on first use

// 2.0 — explicit, in a #[ServiceContext] method
final class EcotoneConfiguration
{
    #[ServiceContext]
    public function databaseSetup(): DatabaseSetupConfiguration
    {
        return DatabaseSetupConfiguration::createWithDefaults()
            ->withAutoCreateLevel(AutoCreateLevel::None);
    }
}
\`\`\`

- Run `ecotone:migration:database:setup --initialize` (or `--feature=deduplication,dead_letter` for a
  subset) as part of your deploy pipeline, once per connection (`--connection=`) and once per tenant
  (`--tenant=`) if you use multi-tenancy.
- Or dump SQL for your existing migration tool: `ecotone:migration:database:dump-sql --format=doctrine-migration`
  / `--format=laravel-migration`.
- Check `ecotone:migration:database:status --strict` in CI before deploy — exits non-zero if anything is
  missing.
- For integration tests against a real database, call
  `$ecotone->getGateway(DatabaseSetupManager::class)->initializeAll();` once in `setUp()` — `EcotoneLite`
  in-memory/SQLite bootstraps don't need this, they still auto-create.
- Deduplication cleanup (`ecotone_deduplication` row expiry) no longer runs inside your handler's
  transaction. It runs on a scheduled endpoint per connection (enable via
  `DbalConfiguration::withDeduplication(...)`, unchanged call) or via
  `ecotone:deduplication:remove-expired-messages` if you prefer your own cron/k8s CronJob.
- If you overrode table names, the previously-hardcoded deduplication/dead-letter/document-store table names
  are now configurable: `DbalConfiguration::withDeduplicationTable()`, `withDeadLetterTable()`,
  `withDocumentStoreTable()`.
- Existing 1.x production databases: tables already exist, so `AutoCreateLevel::None` is a no-op at deploy
  time — run `ecotone:migration:database:status` once after upgrading to confirm nothing is missing before
  flipping the default.
```

Mechanical hints:
- `sed` migration for `DbalConfiguration::withAutomaticTableInitialization(false)` → `DatabaseSetupConfiguration::createWithDefaults()->withAutoCreateLevel(AutoCreateLevel::None)`; the old method stays as a deprecated shim for one minor version (`withAutomaticTableInitialization(true)` → `AutoCreateLevel::CreateOnly`, `(false)` → `AutoCreateLevel::None`).
- No rector rule needed for the CLI command names — `ecotone:migration:database:setup`/`delete` are unchanged, only new flags are added (backward compatible).

---

## 9. Implementation plan

Ordered for single focused coding sessions; each depends only on prior items in this list.

1. **Make every `DbalTableManager::createTable()` implementation actually idempotent** (§6.1) — either emit
   `CREATE TABLE IF NOT EXISTS` for the four Doctrine-Schema-API managers (Deduplication, Enqueue, DeadLetter,
   DocumentStore) or wrap their `createTable()` calls in a catch for `Doctrine\DBAL\Exception\TableAlreadyExistsException`.
   Do this first — every later step (idempotent `--sql` dumps, safe concurrent `--initialize`, safe
   crash-and-retry) depends on it, and it's a pure bug fix independent of the rest of this plan.
2. **Add `AutoCreateLevel` enum (`None`/`CreateOnly` only — §6.4) and `DatabaseSetupConfiguration` extension
   object** in `packages/Dbal/src/Configuration/` (or `packages/Dbal/src/Database/`), with `createWithDefaults()`
   defaulting to `CreateOnly`, `withAutoCreateLevel()`, `withAutoCreateLevelForConnection()`. No behaviour
   change yet — additive class.
3. **Wire `DatabaseSetupConfiguration` into every `shouldBeInitializedAutomatically()` call site**, replacing
   the `DbalConfiguration::isAutomaticTableInitializationEnabled()` boolean check with a level check (`None` →
   false, `CreateOnly` → true for "create if missing"). Keep `DbalConfiguration::withAutomaticTableInitialization()`
   as a deprecated shim that sets the new configuration under the hood.
4. **Add the `TableNotFoundException`-catch-and-rethrow behaviour** (§6.2a) at every read/write call site that
   used to call `createDataBaseTable()` unconditionally, so `AutoCreateLevel::None` produces an actionable
   `ConfigurationException` instead of a raw driver exception.
5. **Add the four missing table-name override methods** (`withDeduplicationTable`, `withDeadLetterTable`,
   `withDocumentStoreTable`; audit `withProjectionsTableName` naming vs. `ecotone_projection_state` and fix or
   add a correctly-named one) — pure plumbing through existing constructors, no new mechanism.
6. **Add a `connectionReferenceName` field to `DbalTableManagerReference`** (§5.5) and update every module that
   constructs one (`DeduplicationModule`, `DbalDeadLetterModule`, `DbalPublisherModule`, `DbalDocumentStoreModule`,
   `EventSourcingModule`, `ProophProjectingModule`) to pass the same connection reference it already resolves
   for its own runtime wiring — this is the prerequisite for step 7, not optional plumbing.
7. **Replace `DatabaseSetupModule`'s single `DatabaseSetupManager` registration with the `DatabaseSetupRegistry`**
   from §5.5, grouping the now-connection-aware `DbalTableManagerReference[]` by connection (plus tenant
   connections from `MultiTenantConfiguration` when present, §5.6). Update `DatabaseSetupCommand`/`DatabaseDeleteCommand`
   to accept `--connection=`/`--tenant=` and resolve against the registry.
8. **Add `ecotone:migration:database:status --strict`** — thin new command reusing
   `DatabaseSetupManager::getInitializationStatus()`/`getUsageStatus()` (already implemented), exit code 1
   when any used-and-uninitialized feature exists under `--strict`.
9. **Add `ecotone:migration:database:dump-sql --format=`** — templating layer over the already-implemented
   `getCreateSqlStatements()`/`getDropSqlStatementsForFeatures()`, producing Doctrine Migrations / Laravel
   migration file skeletons; `--out=` writes to disk instead of stdout.
10. **Add the `EcotoneSchemaProvider` (`Doctrine\Migrations\Provider\SchemaProviderInterface` implementation)**
    for `doctrine:migrations:diff` integration, built from the same `getCreateTableSql()` outputs; document the
    ProjectionState/EventStream limitation from §5.3 rather than silently omitting those two features.
11. **Add the scheduled deduplication-cleanup endpoint** in `DeduplicationModule`, reusing the existing
    `removeExpiredMessages` service activator and `ecotone.deduplication.removeExpiredMessages` channel, adding
    a `#[Poller]`/scheduled trigger gated by `isDeduplicatedEnabled()`; keep the standalone CLI command as an
    alternative trigger.
12. **Remove the implicit-commit workaround** — **blocked on Group D (Revision 3, R-16): while Prooph is the
    event store, per-stream `CREATE TABLE` still runs inside the message transaction, ungated, so deleting
    `ImplicitCommit` reintroduces the original MySQL crash for every event-sourcing user.** Once the DCB store
    lands: delete `ImplicitCommit.php`, the `@TODO` branch in
    `DbalTransactionInterceptor::transactional()`, and **only the platform half** of the condition in
    `DeduplicationInterceptor::deduplicate()` (line 101 — keep `$connection->isTransactionActive()`, drop
    `&& $connection->getDatabasePlatform() instanceof PostgreSQLPlatform`, per the §6.2 correction) — only after
    step 3 makes `AutoCreateLevel::None` reachable and documented as the production default, otherwise this
    step reintroduces the original crash for anyone still relying on auto-create + MySQL.
13. **Update `DbalMessagingTestCase` and all DBAL integration test suites** to call the explicit
    `initializeAll()` path instead of relying on default auto-create-in-test-body, per §2.6 — this is the one
    step touching the widest number of existing test files, do it last so the API it's exercising is stable.
14. **Docs/skill updates**: `ecotone-testing` skill (explicit setup call in integration tests),
    `upgrade-2.0.md` §8 (already drafted above), and a new documented convention note in `AGENTS.md` for the
    `#[ConsoleCommand]` "Manager + Command" pattern (Open Question 9) so future feature modules follow the
    same shape as `DatabaseSetupCommand`/`ChannelSetupCommand` instead of inventing a third variant.

Deliberately **not** in this plan, deferred per §6.4: `AutoCreateLevel::CreateOrUpdate` (the
`Comparator`-based column-diffing capability and its per-manager cost), and the `ecotone_schema_version`
marker table (§6.5) whose only justification was caching that diff. Also **not** in this plan, flagged
out-of-scope per §5.3: fixing the shared `#[ConsoleParameterOption]` boolean-coercion wart
(`normalizeBoolean()`), since it's a framework-wide console-command concern, not specific to database setup.

---

## 10. Open questions for the maintainer

1. **Should `AutoCreateLevel::None` be the literal default for *all* environments, or only when a non-test
   bootstrap is used?** Recommendation: `None` as the `DatabaseSetupConfiguration::createWithDefaults()`
   default overall, with `EcotoneLite::bootstrapFlowTesting*()` explicitly overriding to `CreateOnly` when no
   `DatabaseSetupConfiguration` extension object is supplied (mirroring today's `DbalConfiguration::createForTesting()`
   injection) — keeps "safe by default" literal for anyone constructing a `ServiceConfiguration` by hand
   outside a test bootstrap.
2. **Is deferring `AutoCreateLevel::CreateOrUpdate` out of 2.0 (§6.4) the right call, given it removes a level
   the original draft assumed users would get on day one?** Recommendation: yes, defer it — §6.4's cost
   analysis shows it needs a genuinely new `Comparator`-based diffing capability that doesn't exist on any
   manager today and isn't buildable at all for `ProjectionStateTableManager`/`EventStreamTableManager`
   without first migrating them onto the Doctrine Schema API. Shipping `None`/`CreateOnly` now doesn't block
   adding `CreateOrUpdate` later (pure enum addition), and `CreateOnly` alone already fixes the actual bug
   this topic exists to fix (implicit commits from *creating* tables mid-transaction) — column-patching was
   never the problem `DbalTransactionInterceptor:107`'s `@TODO` was about. If the maintainer disagrees and
   wants `CreateOrUpdate` in 2.0, budget the `Comparator` helper plus a decision on the two raw-SQL managers
   as real, separately-sized work, not a footnote.
3. **Is `AutoCreateLevel::All` (Marten's most permissive, drop-and-recreate) worth adding at all?**
   Recommendation: no — omit it entirely from the initial design; nothing in Ecotone's current feature set
   needs destructive schema changes, and adding the level only to leave it unused invites accidental misuse.
   Revisit only if a concrete feature (e.g. a future DCB event-store column change) needs it.
4. **Should the "real DB vs in-memory" distinction be made by inspecting the actual `Connection`'s platform
   (e.g. `SqlitePlatform` → always `CreateOnly`) instead of by which bootstrap method was called?**
   Recommendation: keep it bootstrap-method-driven as today (simpler, already-familiar mental model — "test
   bootstrap = auto, `#[ServiceContext]` = explicit"), but document the current gap (§2.6) clearly so users
   who point a test bootstrap at a real Postgres instance understand tables will still auto-create unless
   they explicitly set `AutoCreateLevel::None` in that test's configuration.
4b. **Should `ProjectionStateTableManager`/`EventStreamTableManager` get a real SQLite DDL branch, given §7
   established SQLite is not a supported target for either today (they mistarget to Postgres/MySQL DDL
   respectively)?** Recommendation: no, not as part of this topic — nothing in the current test suite
   exercises event sourcing against SQLite (§7), so there's no evidence anyone needs it, and Group D is
   already rewriting the event store's persistence layer from scratch (DCB, own DBAL store, dropping Prooph);
   adding SQLite support to the *old* `EventStreamTableManager` now would likely be thrown away. Revisit as
   part of Group D's own DBAL event store design instead, where it can be built in from the start rather than
   retrofitted; until then, this report's recommendation is to state the SQLite limitation clearly in
   documentation (§7) rather than silently leave users to discover it via a mistargeted `CREATE TABLE`.
5. **Where should `DatabaseSetupConfiguration` live under the Group H `/Api` namespace reorganization?**
   Recommendation: `Ecotone\Dbal\Api\ExtensionObject\DatabaseSetupConfiguration`, alongside where
   `DbalConfiguration` moves per `upgrade-2.0.md` §13's mapping table — do this rename in the same Group H
   pass, not earlier, to avoid a second breaking rename.
6. **Does the deduplication scheduled-cleanup endpoint need to be `#[Asynchronous]` (queued) or can it be a
   direct `#[Poller]`-driven synchronous call?** Recommendation: `#[Poller]`-driven direct call is enough —
   it's a housekeeping job, not business logic, and doesn't need queue durability; Group J's "asynchronous is
   always real, never inline" rule doesn't need to apply to internal framework maintenance endpoints. Confirm
   this reading is correct before implementation.
7. **Should the CLI's `--tenant=` resolution require an Enterprise licence check even for a no-op `--sql`
   dump (no actual multi-tenant *behaviour*, just reading the static `tenantToConnectionMapping` array)?**
   Recommendation: no — reading configuration to enumerate tenants for a dump/status command doesn't invoke
   any gated *runtime* behaviour (`HeaderBasedMultiTenantConnectionFactory` isn't touched); gate only
   `--initialize`/`--force` against a tenant connection, consistent with "visible but disabled" (design
   principle 5) rather than hiding the tenant list entirely.
8. **`EventStreamTableManager` is tagged `licence Enterprise` while the sibling `ProjectionStateTableManager`
   is `licence Apache-2.0` (§2.6/edge cases) — is this intentional?** This determines whether
   `ecotone:migration:database:setup --feature=event_streams` should be licence-gated. Needs a maintainer
   decision; flagging rather than guessing, since inventing the answer risks shipping the wrong gate on a
   licensing-sensitive path.
9. **Should `ChannelSetupManager`/`ChannelSetupCommand` (message channels) and `DatabaseSetupManager`/`DatabaseSetupCommand`
   (DBAL tables) be unified under one shared `SetupManager`/`SetupCommand` abstraction, given they're
   structurally identical today?** Recommendation: not in this topic's scope — flag as a follow-up
   refactor once both are stable under the new `AutoCreateLevel` model, to avoid scope creep here; document
   the "Manager + Command + `#[ConsoleCommand]`" shape as the convention for both in `AGENTS.md` regardless
   (implementation plan step 14).
