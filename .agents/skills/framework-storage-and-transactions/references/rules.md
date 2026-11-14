## 8. Always use optimistic locking

Locking is a version check on a write, never a pessimistic lock and never a bespoke tracker.

- `c064ca8b5` removed `WriteLockStrategy` entirely: "concurrency is the unique index alone".
- `ca8a1b0fc` removed InnoDB own-bump snapshot tracking, which had been tried: "the guarded `UPDATE` is the sole
  conflict mechanism". It was removed both because it was state on a service (rule 3) and because it duplicated the
  lock.
- A conflict surfaces as `ConcurrencyException` (`packages/Ecotone/Api/Messaging/ConcurrencyException.php`)
  or `DecisionModelConcurrencyException`, with a message that names what was being decided (`c9b54d3f9`).
- This holds for a row that serialises work too, not only for a record two writers decide on. Projection state kept
  `SELECT ... FOR UPDATE` as a "work lease" until the save was guarded on the partition's `version`: the run that loses
  rolls back and is retried from the position the winner stored (`ProjectionOptimisticLockingTest`).
- A conflict is resolved by running the work again, so the instant retry is on by default wherever a database
  transaction wraps the work — the command bus and asynchronous endpoints under `DbalConfiguration`, and an
  asynchronous projection, which opens its own (`InstantRetryByDefaultTest`). Without a transaction nothing retries by
  default: the failed attempt's writes were never rolled back.
- Because the check and the write must commit together, tagged appends require an active transaction and say so
  when there is none (`78bb0d244`, and `TagTransactionRequirement` in rule 1).

Switching a lock off is guarded, not free. `withoutCounterTagFor()` exists for state-stored aggregates only,
and `AggregateCounterTagGuard` refuses it for an `#[EventSourcingAggregate]` ("its stream's own version check
guards it, and that cannot be switched off") or for anything that is not an aggregate. It drops the DCB counter
tag, not every lock: a `#[Version]` on the aggregate is still checked by its repository. `33d927ac9` went further: a
`#[DecisionBoundary]` scoped only by opted-out aggregates guards nothing, so it is refused.

---

## 9. Every feature needing external storage ships an in-memory implementation

Otherwise the feature cannot be used in an `EcotoneLite` flow test, and every test of it needs a database. There
are 34 `InMemory*` classes in `packages/Ecotone/src` for this reason, among them `InMemoryEventStore`,
`InMemoryDocumentStore`, `InMemoryProjectionStateStorage`, `InMemoryStateStoredRepository`.

**Mirror the storage implementation name for name**, so the correspondence is checkable by reading:

| Role | DBAL (`packages/PdoEventSourcing/src/Dbal/Tag/`) | In-memory (`packages/Ecotone/src/EventSourcing/EventStore/Tag/`) |
|---|---|---|
| The seam | `DbalTagCollaborator` | `InMemoryTagCollaborator` |
| Enterprise implementation | `EnterpriseDbalTagCollaborator` | `EnterpriseInMemoryTagCollaborator` |
| Open-core implementation | `OpenCoreDbalTagCollaborator` | `OpenCoreInMemoryTagCollaborator` |
| Counters | `DbalTagVersionRegister` | `InMemoryTagVersionRegister` |
| Index | `DbalTagIndex` | `InMemoryTagIndex` |

`40eacaa22` ("name the in-memory tag classes after their DBAL mirrors") did this; the reasoning is
`docs/superpowers/specs/2026-09-28-dcb-readability-review.md` §10. Where the two genuinely differ — the in-memory
side holds its own tag state, and has no backfill — the naming makes the asymmetry visible instead of hiding it.

Build them through their constructors, not through setters or a builder (`2ccb8bf9f`).

**One guarantee the shared suite cannot prove on the in-memory side: a race decided by a database constraint.**
`DbalHandledMessages`-style storage refuses a concurrent duplicate because a unique index rejects the second
insert inside the transaction; an in-memory store has no constraint and no second connection, so it can only
enforce the same rule in PHP. State the contract as **behaviour** the suite can assert on both sides — "recording
the same key twice is refused", "appending a version that already exists raises `ConcurrencyException`" — and
leave the mechanism to each implementation. A conformance case that needs two real connections racing belongs in
the storage-backed suite alone, named so the gap is visible rather than assumed covered.

**An in-memory implementation nobody proves equal to the storage one is a place bugs hide.** The whole point of
rule 9 is that a flow test can stand in for the database; when the two disagree, the test is validating something
the application will never do. `InMemoryEventStore` diverged from the DBAL store three times, on three different
branches, and each time it was found by a unit doing something else entirely:

| The divergence | Found by | Commit |
|---|---|---|
| Appending zero events is a no-op on DBAL; in-memory raised a conflict for a stale condition — "a flow test disagreed with production" | the edge-case audit | `d59bd5989` |
| DBAL enforced the aggregate optimistic lock through its unique index; in-memory had never enforced it at all | the store unification | `ade73e6c5` |
| The in-memory flow-testing repository held the `EventStore` *gateway* where every other caller uses `EventStore::RAW_REFERENCE`, so an in-memory conditional append produced no tracing span | a tracing feature | `503d70bc7` |

So: a behaviour of a seam with two implementations is asserted once, in a suite both implementations run — never in
a DBAL-only or an in-memory-only test. New behaviour goes into that shared suite, and which fixture a test picks
stops being somewhere a bug can hide.

The suites are `packages/PdoEventSourcing/tests/Conformance/` (event store, tagged and untagged) and
`packages/Dbal/tests/Conformance/` (document store); copy their shape for a new seam. A data provider yields one row
per way an application gets the seam, each case fetches the gateway through `EcotoneLite`, and a divergence nobody has
decided yet goes into the suite's `KNOWN_DIVERGENCES`: the case is skipped with the divergence named, and fails the
suite once it stops diverging. Never make a case assert the divergent behaviour — that locks the bug in.

---

## 16. No table is created on the message path

MySQL and MariaDB implicitly commit the surrounding transaction on any DDL statement, so a `CREATE TABLE` in the
middle of a message breaks the commit or rollback that message's transaction expects. 1.x carried a workaround that
string-matched the driver's error message and swallowed the failure. Wave 8 gated runtime creation per engine; that
left whether a table appeared mid-message depending on the database underneath (conformance divergence E11), so the
maintainer removed the concept instead of documenting the split.

A table is created in exactly two places:

- **`ecotone:migration:database:setup`**, or its programmatic twin `DatabaseSetupManager` /
  `DatabaseSetupManagerRegistry`, or SQL from `--sql` fed to the application's own migration tool;
- **the test setup action**, `FlowTestSupport::setupDatabaseTables()`, which the `EcotoneLite` flow-test bootstrap
  calls itself for every connection the test registers. A test turns that off with
  `TestConfiguration::createWithDefaults()->withDatabaseTablesSetUpOnBootstrap(false)` and calls the action when it
  wants the tables. Bootstrap is not the message path.

Everywhere else - every handler, channel, store, projection and console action that is not the setup command - a
missing table raises the owning `*TableManager`'s missing-table `ConfigurationException` (rule 1a), the same on every
engine. There is no `AutoCreateLevel` and no gate to consult, and the DDL text lives only in the `*TableManager` and
`*Schema` classes.

The refusal comes from the statement that needed the table, not from a question asked before it. The store runs its
SQL and passes the failure through `MissingTableFailure::explained()`, which finds Doctrine's `TableNotFoundException`
in the cause chain - Doctrine maps every engine's error code onto it, so there is nothing per engine to maintain - and
returns the owning table manager's instructions. Do not ask the schema first (`tableExists()`, `isInitialized()`): that
is a round trip on every operation, memoising the answer is a cache on a container singleton (rule 3), and wave 10b
removed it from the DBAL channels, the dead letter, the document store, deduplication cleanup and projection state. Nor
read a missing table as an empty result: a `list()` that answers `[]` hides the same configuration mistake rule 1a
refuses. A schema check belongs to the setup command and its status listing, to a projection's own `init()`, and to a
decision that is not about this table's existence (a feature that is not used at all is known at compile time, from the
table manager's `isUsed()`).

Catching `TableNotFoundException` to *ignore* it is a different thing from translating it. On PostgreSQL a failed
statement aborts the surrounding transaction, so a swallowed failure resurfaces later as "current transaction is
aborted". Translate and rethrow, so the transaction is rolled back. Where a missing table really is a no-op, keep a
guard and say why: `DbalTagIndex::deleteForStream()` checks for the tag table because deleting a stream runs inside a
projection reset's transaction.

So: do not create, alter or drop a table on the message path, and do not add a workaround for a transaction a DDL
statement committed - remove the DDL. `EventStore::delete()` is the one deliberate exception, and it refuses to drop
a table inside an open transaction on MySQL and MariaDB. The whole story for users is `upgrade-2.0.md` §8.

History: `230bcea5d` and `511b31428` first moved this DDL out of the transaction; `048f614d8` and the rest of rule
1a replaced that with the refusal and the setup instructions; wave 10 removed runtime creation with
`AutomaticTableInitializationSupport`, `AutoCreateLevel` and `EventSourcingConfiguration::withInitializeEventStoreOnStart()`.

---

## 16a. No database write after the transaction

A message's unit of work owns a transaction: the transaction interceptor begins it, the handler and everything it
calls write inside it, and it commits or rolls back as one. Anything a feature records about that message - stored
traces, bookkeeping, a completion flag - is written inside the same transaction, or not at all.

Never write after the commit, after a rollback, or in an interceptor that wraps the transaction from outside. At that
point the work is already decided. If the database is down, the write throws out of a consumer whose message was
already acknowledged, or out of a command whose data already committed: the caller sees a failure for work that
succeeded, and the message is not retried. The same outage inside the transaction rolls back and leaves the message
to the retry and dead-letter strategy, which is what they exist for.

Wrong - the root span is inserted when it ends, after the commit:

```php
public function record(Span $span): void
{
    if ($this->scopeActive) { $this->buffer[] = $span; return; }
    $this->traceStore->append([$span]);   // after the commit: fails a consumer whose work succeeded
}
```

Right - the span that wraps the unit of work is written as it stands at the flush, inside the transaction, and
nothing touches the database once the transaction has returned:

```php
$result = $methodInvocation->proceed();
$this->recorder->flushWithOpenSpans();   // inside the transaction
```

Work that commits by itself - an asynchronous projection commits once per batch, with Ecotone turning the endpoint's
transaction off - is recorded inside each of those commits, never once the work has returned: flow tracking joins
the projection batch's transaction. A path the application chose to run with no transaction at all (the command bus
with `withTransactionOnCommandBus(false)`, an event bus or gateway call from a controller) has no transaction to be
inside, so what it stores is written when its work ends; that is the application's decision, and the default runs
every command and consumer in a transaction.

What may happen after the commit is work that does not depend on the database: publishing to subscribers from
memory, and sending telemetry to an external collector such as OpenTelemetry, which is allowed to fail and never
fails the message. A test proves the rule by making the database unavailable right after the commit, with an
`#[Around]` interceptor just outside the transaction, and asserting that the command or consumer still succeeds.

One database write is allowed outside the message's transaction: the failure of a consumed message, stored once
the error channel (a retry, a dead letter, a custom error handler) has taken it. The failed attempt's transaction
rolled back, so its steps cannot be inside it; the message is already handled by the error channel, so a failing
write cannot cause a retry, and the error channel's own write - the dead letter - runs the same way. Flow tracking
stores the failed attempt in its own short transaction on the connection (and tenant) the attempt ran on, and a
failure of that write is logged, never thrown, because the message itself is already taken care of. A failure that
propagates to the consumer still stores nothing.

Evidence: stored traces wrote the root span and claimed completion after the commit, and an outage at that moment
threw the missing-table refusal out of the consumer (`FlowTrackingConformanceTest`, the outage tests); an asynchronous
projection wrote its spans after its own batch committed (`FlowTrackingProjectionTest`).

---

## 16b. No DDL in a command, event or asynchronous flow

Rule 16 keeps Ecotone from creating its own tables on the message path. The same holds for everything a flow runs:
a command handler, an event handler, an asynchronous consumer, and anything a remote request triggers, whether a
Distributed Bus message or a Pulse action. None of them creates, alters or drops a table, and that includes a
projection's own tables.

Every table exists before Ecotone handles its first message. Ecotone's tables come from
`ecotone:migration:database:setup` or the SQL it prints for the application's migrations. A projection is set up
with `ecotone:projection:init` (its `#[ProjectionInitialization]` runs there, outside any transaction) or by the
application's migrations. Code on the message path assumes the tables are there, and a missing one is refused with
the setup instructions (rule 16), never created.

So a remote surface such as Pulse offers operations that run on existing tables (rebuild, backfill, dead letter
replay) and shows setup and initialization status read-only, with the console command that fixes it. It never offers
to initialize a projection or set up a table.

Evidence: a Pulse Initialize ran a projection's `CREATE TABLE` inside the request's transaction. On MySQL the implicit
commit lost the reply, committed the tracking row outside the transaction, and without the consumer transaction the
request ran twice (round-4 review of the Pulse application). Core already kept `initProjection`, `backfillProjection`
and `deleteProjection` console commands out of the DBAL transaction for the same reason (`DbalTransactionModule`).

---


## 16d. Framework code opens no transaction; the boundaries do

The transaction belongs to the place a message enters Ecotone: the command bus (and a gateway marked
`#[DbalTransaction]`), the asynchronous endpoint (polling consumer, inbound channel adapter) and the console
command. The event bus, query bus and other gateways open none: framework code reached from them directly runs
without a transaction, which is the user's choice of entry point. `DbalTransactionModule`
wraps each of them by default and `DbalConfiguration::withTransactionOnCommandBus()`,
`withTransactionOnAsynchronousEndpoints()` and `withTransactionOnConsoleCommands()` switch them off. Everything the
framework does on the message path - a store's read-check-write, closing a group, recording a part, calling the
application's handler - runs inside that one transaction, so it commits or rolls back with the message.

A store that wraps its own writes in `transactional()` adds nothing while the boundary transaction is open (DBAL
nests it as a savepoint). When the user switched the boundary transaction off, it opens one anyway, and if a user
callback runs inside it, the framework holds a transaction around arbitrary application code (HTTP calls, slow
work) on exactly the path the user asked to run without one.

Wrong - the store opens a transaction and runs the application's handler inside it:

```php
return $connection->transactional(function () use ($groupId, $whenComplete): bool {
    $this->closeGroup($groupId);
    $whenComplete($parts);            // the user's #[Gathered] handler, inside a framework transaction
    return true;
});
```

Right - the store relies on the consumer's transaction; with the default configuration the close and the handler
still commit or roll back together:

```php
$this->closeGroup($groupId);
$whenComplete($parts);
return true;
```

Idempotency that used to lean on a savepoint (swallowing a duplicate-key error inside a nested transaction) becomes a
plain existence check before the insert, with the unique key left as the final guard: a concurrent duplicate fails
the delivery and its retry sees the row.

A console command a module registers (`ConsoleCommandModule::prepareConsoleCommandForReference()`, or a handler plus
`ConsoleCommandConfiguration`) carries the `ConsoleCommand` endpoint annotation automatically
(`MessagingSystemConfiguration::markConsoleCommandHandlers()`), so the console transaction and every other
console-command interceptor apply to it like to an application `#[ConsoleCommand]`.

The exceptions keep a transaction short where the boundary's would be long-running or wrong:

- projections commit per batch (`ProjectingManager`, projection state storage), and their init, delete and backfill
  commands run without the console transaction;
- `ecotone:deduplication:remove-expired-messages` and `ecotone:tracing:remove-expired-traces` delete in short
  batches, so their handlers carry `#[WithoutDatabaseTransaction]`: they still run as console commands (tenant
  activation, application interceptors), only without the one long transaction;
- `ecotone:run` runs a consumer loop in which each message gets its own transaction, and `ecotone:list` only reads
  configuration, so both are registered with `ConsoleCommandConfiguration::withoutConsoleCommandInterception()` and
  no console-command interceptor wraps them;
- the outbox forwarder claims a batch in its own consumer loop; traces and Pulse tracking are written outside the
  message transaction they describe; a time-boxed schema read scopes `SET LOCAL` and always rolls back; an Eloquent
  aggregate save keeps its version claim and write as one unit (its own short transaction, or a savepoint inside the
  boundary's); driver adapters implement the connection's
  transaction API.

`bin/check-framework-transactions.php` lists each of them with its reason and refuses any other `transactional()`,
`transaction()`, `beginTransaction()` or `wrapInTransaction()` call under `packages/*/src` and `packages/*/Api`.


## 8a. Failed operations use the framework's retries

A store or handler reports a lost optimistic write with `Ecotone\Api\Messaging\ConcurrencyException`, with the
ways out named in its message. Ecotone already retries the command bus and asynchronous endpoints running in a
database transaction, each attempt in a new transaction. Once instant attempts run out, the configured error
channel, delayed retries and dead letter own recovery. Tests prove both automatic retry under the defaults and
exhaustion followed by replay. Framework code adds no retry loop, re-send of the consumed message, feature-specific
retry configuration or its own backoff. A maintainer-approved exception belongs in the feature spec with its reason.

Evidence: gathering in PR #765 re-sent a conflicting report as a replacement message with a new id. Collecting then
needed a separate original-message identity header, and the replacement handling caused duplicate collection
(review finding C1). On 2026-11-14 the maintainer replaced both features' re-send with `ConcurrencyException` so every
optimistic lock uses one recovery mechanism.

A deterministic check for re-sending a consumed message from a handler or store would need to establish message
identity across builders, converters, collaborators and channel sends. A syntactic ban on `send()` or message
builders also rejects legitimate forwarding, timeout scheduling and publication of new messages. No such check is
added: the eval grades whether the proposed recovery duplicates the framework's retries, while real-database tests
prove that a conflict rolls back and a retry reads fresh state.
