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

- [references/rules.md](references/rules.md) — rules 8, 9, 16, 16a, 16b in full.
