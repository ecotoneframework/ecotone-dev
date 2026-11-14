---
name: framework-storage-and-transactions
description: >-
  Ecotone framework rules for storage: optimistic locking, an in-memory twin proved equal
  to the storage implementation by one shared suite, no table creation or DDL on the
  message path, missing-table errors, and no database write after the transaction. Use when
  a feature reads or writes a database table, adds storage, touches transactions, locking,
  migrations or the event store in the ecotone-dev repository.
---

# Storage, tables and transactions

[references/rules.md](references/rules.md) has the reasoning and evidence for each rule.

## Applies when

- A feature needs a table, a document store, a queue or any external storage
- Changing transaction wrapping, locking, concurrency, the event store or DBAL code
- Handling a missing table or a database outage

## Rules

### 8. Always use optimistic locking
A version check on the write, never a pessimistic lock or a bespoke tracker.
- **Expected:** concurrent writes are guarded by a version/expected-version check on the write; no
  `SELECT ... FOR UPDATE`, no lock table.
- **Verified by:** `eval:store-feature-state`

### 8a. A lost optimistic write uses the framework's retries
A lost optimistic write throws `Ecotone\Api\Messaging\ConcurrencyException`; the existing command-bus and
asynchronous-endpoint instant retries under a database transaction handle it, followed by the configured error
channel, delayed retries and dead letter. No handwritten retry loop, consumed-message re-send from a handler or
store, feature-specific retry configuration or backoff. Applications may tune the existing
`InstantRetryConfiguration` and error-channel configuration; a feature introduces no configuration of its own. An
exception requires the maintainer's explicit decision, recorded with its reason in the feature spec. Load
[references/rules.md](references/rules.md) for the failure that established this rule and why a syntax-only check cannot
decide it.
- **Expected:** a store or handler losing a version check throws `ConcurrencyException` naming the ways out;
  tests prove automatic retry with the default configuration and dead-letter handling when attempts run out.
- **Verified by:** `eval:handle-version-conflict`

### 9. A feature needing external storage ships an in-memory implementation
Named to mirror the storage one, and one shared suite proves the two behave identically by booting the
application once per storage. A race decided by a database constraint is stated as behaviour both sides assert;
a connection-racing case stays in the storage suite, named so the gap is visible.
- **Expected:** a new storage-backed feature has an `InMemory*` twin and one test suite run against both storages
  through the same gateway.
- **Verified by:** `eval:store-feature-state`

### 16. No table is created on the message path
Tables come from `ecotone:migration:database:setup` (or the application's migrations) and, in tests, from the
`EcotoneLite` bootstrap. A missing table raises its table manager's missing-table `ConfigurationException`, found
by the failing statement (`MissingTableFailure`), never by asking the schema first.
- **Expected:** no `CREATE TABLE`/`tableExists` on the message path; a missing table surfaces as the table
  manager's `ConfigurationException` naming the setup command.
- **Verified by:** `eval:store-feature-state`

### 16a. No database write after the transaction
Whatever a feature stores about a message is written inside that message's transaction, or not at all. Telemetry
to an external collector is exempt, and so is the failure of a consumed message stored once the error channel took
it, in its own short transaction like the dead letter.
- **Expected:** the feature's write happens inside the message's transaction, never after commit or rollback; the
  one database write outside it records a failure the error channel already took, in its own transaction.
- **Verified by:** `eval:store-feature-state`

### 16d. Framework code opens no transaction; the boundaries do
A database transaction is opened only where a message enters: the command bus (or a gateway marked
`#[DbalTransaction]`), the asynchronous endpoint (polling consumer or inbound channel adapter) and the console
command. A store, a handler processor or a module's service never calls `transactional()`, `transaction()` or
`beginTransaction()`; it runs in the caller's transaction, so a user who switches a boundary's transaction off
(`DbalConfiguration::withTransactionOn*`) gets none, also around their own handler. A console command a module
registers carries `ConsoleCommand` automatically and so gets the console transaction. Work that must not sit
inside one long boundary transaction keeps it short instead: a batched console command runs with
`#[WithoutDatabaseTransaction]`, a consumer loop (`ecotone:run`) is registered
`withoutConsoleCommandInterception()`, and code that opens its own, like projections (per batch), is listed with
its reason in `bin/check-framework-transactions.php`.
- **Expected:** new framework storage code relies on the boundary transaction instead of wrapping its writes or a
  user callback in `transactional()`/`beginTransaction()`; a module's console command keeps the console
  transaction unless its work is batched or long-running, and then opts out with `#[WithoutDatabaseTransaction]`.
- **Verified by:** `check:framework-transactions`, `eval:framework-store-transaction`

### 16b. No DDL in a command, event or asynchronous flow
Every table — Ecotone's and a projection's — is migrated before the first message, through console commands or
the application's migrations. Nothing a remote request triggers creates, alters or drops one.
- **Expected:** no handler, Distributed Bus message or dashboard action runs DDL.
- **Verified by:** `eval:store-feature-state`

### 16c. Engine-sensitive changes run on MySQL and MariaDB too
MySQL and MariaDB implicitly commit on DDL, so a PostgreSQL or SQLite run cannot show the failure.
- **Expected:** a change touching event sourcing, DBAL, transactions or DDL lists MySQL and MariaDB runs in its
  verification.
- **Verified by:** `eval:store-feature-state`

## References

- [references/rules.md](references/rules.md) — rules 8, 9, 16, 16a, 16b, 16d in full.
