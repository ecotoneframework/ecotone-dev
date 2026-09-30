# Conventions audit — group b: behaviour and correctness rules

Inventory only. No production code, test, or documentation file other than this report was changed. HEAD audited:
`b255fd5e9` on `dgafka/ecotone-2-0-work` (this worktree was already at that commit; verified with `git rev-parse
HEAD` before starting and `git status` clean throughout).

Scope: `packages/*/src` and `packages/*/Api` across all 14 packages (Amqp, DataProtection, Dbal, Ecotone, Enqueue,
JmsConverter, Kafka, Laravel, OpenTelemetry, PdoEventSourcing, Redis, Sqs, Symfony, Tempest) for production rules;
`packages/*/tests`, `Monorepo/`, `quickstart-examples/` sampled where a rule's own text reaches test/example code.
Confirmed no `packages/*/vendor` build artifacts exist (`find packages -maxdepth 2 -name vendor -type d` → empty),
so nothing needed excluding on that front.

Work was split into five independent audits, one per rule, run in parallel by separate agents against the same
tree and the same rule text (`docs/coding-conventions.md`). This report synthesizes their findings; methods and
counts below are re-runnable as stated.

## Summary

| Rule | Violations found | Exempt (not debt) | Fix cost | Recommendation |
|---|---|---|---|---|
| 1 — exceptions drive the solution | **39/42** `LicensingException` sites missing a way-out link (systemic, all packages); a handful of generic messages (`"No subscribers"`, `"Requeue loop was detected"`) with no identifying context, sampled ~20% of ~612 throw sites | `DataProtection/src/Encryption/*` (57 sites) — ported third-party crypto library text, not original authorship | `LicensingException` gap: **mechanical** (append the standard link, one string edit per site). Generic-message class: **needs judgement** (context must be threaded to the throw site) | **Enforce and fix** the `LicensingException` link gap — cheap, uniform, high leverage. Fix the generic messages opportunistically, not as a sweep |
| 1a/1c — compile-time refusal | Not mechanically measurable at scale; spot-checked ~10 non-`Module`/non-`Guard` `ConfigurationException` sites, none read as a clear 1c violation | — | — | No sweep justified from this sample; re-audit narrowly if a specific feature is suspected |
| 4 — no query/write mixing | **0** confirmed in production code (11 mechanically flagged candidates, all false positives or exempt on manual triage) | Connection-pooling lazy-init patterns (`DbalContext::getDbalConnection()`, etc.) — idempotent return value, not query/write mixing; `DiscardsHalfBuiltServices::get()` — framework interface, cleanup is failure-path only | N/A | **Already followed — no backlog.** Rule 4's original fix (`4bb23eb51`) generalized cleanly across all 14 packages |
| 8 — always use optimistic locking | Pessimistic lock: `PdoEventSourcing/src/Projecting/PartitionState/DbalProjectionStateStorage.php` (`SELECT ... FOR UPDATE`, no version predicate on write). Missing version check: `Laravel/src/EloquentRepository.php`, `Tempest/src/TempestRepository.php`, `Dbal/src/DocumentStore/DocumentStoreAggregateRepository.php`, `Ecotone/src/Modelling/InMemoryStateStoredRepository.php` — all ignore the `$versionBeforeHandling`/`$expectedVersion` parameter they're handed | `Dbal/src/BatchForwarding/DbalOutboxPublisher.php` (`FOR UPDATE SKIP LOCKED`) — queue-claim semantics, not aggregate consistency; `Dbal/src/ObjectManager/ManagerRegistryRepository.php` — delegates to Doctrine's own native optimistic locking | Pessimistic-lock removal + state-stored version enforcement: **needs design** — `DocumentStore` gateway interface has no version parameter at all, so plumbing this through is a public-interface change | **Needs a design decision.** The state-stored-aggregate path (every framework repository) has no real optimistic lock outside Doctrine ORM — this is a substantive gap, not cosmetic. Flag as possibly breaking for `DocumentStore`/`StateStoredRepository` implementers |
| 9 — in-memory implementation for storage seams | **Deduplication** (`Dbal/src/Deduplication/DeduplicationInterceptor.php`) and **dead-letter handling** (`Dbal/src/Recoverability/DbalDeadLetterHandler.php`) have no in-memory counterpart — cannot be exercised in an `EcotoneLite` flow test at all. No shared abstract test suite found proving any dual-implementation seam (EventStore, DocumentStore, ProjectionStateStorage) behaves identically | Broker/transport adapters (Amqp, Sqs, Redis, Kafka, Enqueue) — channel adapters, not storage seams, already covered by the generic in-memory channel; `Dbal/src/ObjectManager/*` (Doctrine ORM) — alternate implementation of the same seam `InMemoryStateStoredRepository` already mirrors, not a seam of its own | Deduplication/dead-letter in-memory implementations: **needs design** (no seam/interface exists yet to implement against — rule 5 territory first). Shared test suite: **needs judgement** (test restructuring only, no production change) | **Enforce and fix** for deduplication and dead-letter — both are commonly used features currently unusable in a pure flow test. Introduce a shared abstract suite per dual-implementation seam going forward |
| 16 — no DDL on the message path | **0** unguarded violations. All DDL lives in `Dbal`/`PdoEventSourcing` `*TableManager` classes, gated by `shouldBeInitializedAutomatically()`, which refuses on MySQL/MariaDB — but **does run inline on the message path on Postgres/SQLite** when `AutoCreateLevel::CreateOnly` is active (not just inside test bootstraps) | Console/CLI, `Module::prepare()`, `DatabaseSetupManager` gateway calls, DDL text embedded in exception messages (rule 1a's own pattern) | Doc wording: **mechanical** (the rule's summary sentence overstates the guarantee) | **Revise the rule's wording**, not the code. The actual guarantee is "no DDL that would break a MySQL/MariaDB transaction," not "no DDL on the message path, full stop" — the current code is a deliberate, correctly-gated design, already narrower than the rule's own summary claims |

## Rule 1 — Exceptions drive the solution (incl. 1a, 1c)

**Detection method**: mechanical count of throw sites (`throw new [A-Za-z]` and `*Exception::(create|because|for|with)(`)
across `packages/*/src packages/*/Api`, per package. Re-run:
```
grep -rn "throw new \|Exception::create(\|Exception::because(\|Exception::for(\|Exception::with(" packages/*/src packages/*/Api --include=*.php | wc -l
```
Then a sample: every throw site in the 9 smallest packages read verbatim (~95 sites), plus a stratified sample of
29 sites (every 15th) from Ecotone's 443. Total sample ≈124/612 (~20%).

**Per-package throw-site counts**:

| Package | Sites |
|---|---|
| Ecotone | 443 |
| DataProtection | 57 |
| Dbal | 52 |
| PdoEventSourcing | 22 |
| Amqp | 10 |
| Kafka | 8 |
| Redis | 6 |
| Sqs | 5 |
| JmsConverter | 3 |
| Laravel | 3 |
| Enqueue | 2 |
| Tempest | 1 |
| OpenTelemetry | 0 |
| Symfony | 0 |

**The clearest, fully-mechanical finding**: `LicensingException::create(...)` — 42 sites total across the whole
codebase, and only **3 of them** include the way-out link the rule's own canonical example
(`OpenCoreAggregateMethodInvoker`) uses. **39/42 (93%)** name the Enterprise-only feature and say it isn't
available, but give no way out at all — no `https://docs.ecotone.tech/enterprise` link, no upgrade path. This spans
every package that has a licence gate: Ecotone (20), Amqp (3), Dbal (3), and one each in DataProtection, Kafka,
Laravel, PdoEventSourcing, Redis, Sqs. Detect via:
```
grep -c "LicensingException::create" packages/*/src/**/*.php packages/*/Api/**/*.php 2>/dev/null
grep -rn "LicensingException::create" packages/*/src packages/*/Api --include=*.php | grep -vc "docs.ecotone.tech"
```

Worst examples:
- `packages/Kafka/src/Configuration/KafkaModule.php:76` — "Kafka module is available only with Ecotone Enterprise licence." No link.
- `packages/DataProtection/src/Configuration/DataProtectionModule.php:197` — same shape, no link.
- `packages/Ecotone/src/Messaging/Channel/QueueChannel.php:46` and `DelayableQueueChannel.php:50` — duplicated
  "Sending BatchMessage is available only with Ecotone Enterprise licence." message, no link.
- `packages/Ecotone/src/Messaging/Channel/PollableChannel/InMemory/InMemoryAcknowledgeCallback.php:81,98,115` and
  `InMemoryStreamingAcknowledgeCallback.php:108,128` — "Requeue loop was detected" ×5, no cause named, no way out.
- `packages/Dbal/src/Connection/DbalSubscriptionConsumer.php:93` — "No subscribers" — doesn't name which channel/consumer.
- `packages/Dbal/src/Connection/DbalConsumerHelperTrait.php:34` — "Queues must not be empty." — same shortfall.

**Exemption**: `packages/DataProtection/src/Encryption/*` (57 throw sites) is a ported third-party crypto library
(the `defuse/php-encryption` shape — `File.php`, `Core.php`, `Crypto.php`, `Encoding.php`, exception classes like
`EnvironmentIsBrokenException`, `WrongKeyOrModifiedCiphertextException`). Its terse messages ("Bad secret type.",
"Integrity check failed.") are upstream library text, not Ecotone's own authorship. Recommend excluding this
subtree from rule 1 explicitly rather than counting it as debt — rewriting it would fork a security-sensitive
library for cosmetic reasons.

**1a/1c** (compile-time refusal): not mechanically measurable — "should this check happen in `Module::prepare()`
instead of at runtime" requires reading each check's context. Spot-checked ~10 `ConfigurationException` sites
outside `*Module.php`/`*Guard.php`/`Config/` directories; none read as a clear violation in the sample (all
legitimately depend on live DB state or per-message content). This dimension is **unmeasured beyond the sample**,
not "clean" — a targeted follow-up on a specific feature would be needed to say more.

One serious-looking smell worth a maintainer's eyes, though not confirmed as a rule 1a violation: `packages/Tempest/src/MessagingSystemInitializer.php:96-99`
catches `Throwable` around Composer namespace resolution and returns `[]` silently — a misconfiguration reading as
an empty result is exactly the pattern rule 1a's `048f614d8` citation fixed once already. It may be an intentional
"Composer classmap not present" fallback rather than true misconfiguration; needs a maintainer call, not a fix.

**Fix cost**: `LicensingException` gap — mechanical, append the standard link to 39 messages, one string edit each,
zero behaviour change. Generic-message class (`Requeue loop`, `No subscribers`, `Queues must not be empty`) — needs
judgement, the identifying context (channel/consumer name) has to be threaded to the throw site. Tempest silent
catch — needs judgement to confirm intent before touching.

**Recommendation**: enforce and fix the `LicensingException` gap — it is cheap, uniform, and touches every package
with a licence gate, so it's the single highest-leverage fix in this whole audit. Fix the generic-message class
opportunistically as those call sites are touched for other reasons; a dedicated sweep isn't obviously worth a
worktree on its own. No package stood out as meaningfully worse than another — the gap is uniform, including in
Ecotone itself, which argues this is a genuine adoption gap in the rule rather than legacy-package debt.

## Rule 4 — Do not mix queries with writes

**Detection method**: grepped for public getter-shaped method names (`get*`, `find*`, `load*`, `fetch*`, `read*`,
`list*`, `all*`) across `packages/*/src`, `packages/*/Api`, `packages/*/tests`, then extracted each method body with
a brace-matching pass and flagged bodies containing mutation markers (`$this->x =`, `unset($this->...)`,
`array_shift/splice/pop($this->...)`, DDL/DML execution, `->persist/save/flush/insert/update/delete(`). Re-run
shape:
```
grep -rn "public function get\|public function find\|public function load\|public function fetch\|public function read\|public function list" packages/*/src packages/*/Api --include=*.php
```

**Candidate counts (production, src+Api)**: Ecotone 843, Dbal 170, PdoEventSourcing 50, Amqp 46, Kafka 46,
Enqueue 32, Laravel 18, Sqs 14, Symfony 3, Tempest 9, JmsConverter 7, Redis 7, DataProtection 4, OpenTelemetry 4.
The mutation-body heuristic flagged 11 of these (Dbal 6, Ecotone 3, PdoEventSourcing 1, Amqp 1); all 11 were then
read in full.

**Manual triage result: 0 confirmed production violations.**
- `Dbal/src/Database/{Deduplication,DocumentStore,DeadLetter,Enqueue}TableManager.php` `getDropTableSql()`,
  `PdoEventSourcing/.../ProjectionStateTableManager.php:61` — false positives: these return a SQL string literal
  containing the words "DROP TABLE", they don't execute anything.
- `Dbal/src/Connection/DbalContext.php:214 getDbalConnection()`, `Amqp/src/AmqpReconnectableConnectionFactory.php:205`,
  `Enqueue/src/CachedConnectionFactory.php:69` — lazy-init-and-cache-a-connection pattern. Idempotent return value
  (same connection every call), so not a rule 4 violation by the rule's own "must not change observable result"
  test — flagged as adjacent to rule 3 (stateless-service caching) instead, not counted here.
- `Ecotone/src/SymfonyContainer/DiscardsHalfBuiltServices.php:23 get()` — implements `ContainerInterface::get()`;
  mutation is failure-path cleanup only. Exempt, framework interface, can't rename.

**Test-scope observation** (not counted, but worth flagging): `Amqp/tests/Fixture/*/OrderService.php` (e.g.
`packages/Amqp/tests/Fixture/SuccessTransaction/OrderService.php:29`) and `Ecotone/tests/.../Collector/BetService.php:78`,
`Dbal/tests/Fixture/Betting/BetService.php:85` are `#[QueryHandler]`-annotated test fixtures whose `get*()` methods
drain their buffer on read — the exact shape rule 4's own citation (`getRecordedEvents`) was written to eliminate.
These are inline test doubles modelling async mailbox semantics, likely intentional rather than debt, but they're a
live counter-example if this rule is ever taught by pointing at the test suite.

**Fix cost**: N/A, no confirmed production violations. If the test-fixture drain pattern is judged undesirable:
mechanical rename (`getOrder`→`popOrder`, `getBetHeaders`→`popBetHeaders`) in ~4 fixture files, no public-surface
impact (inline test doubles).

**Recommendation**: already followed — no backlog. The original fix (`4bb23eb51`, `getRecordedEvents` →
`popRecordedEvents`) generalized cleanly across all 14 packages; the DDL-string-builder naming convention
(`getCreateTableSql`/`getDropTableSql` returning text vs. `createTable()`/`dropTable()` executing) is consistently
applied everywhere it appears. Low violation count here is the finding — enforce as-is, no rule revision needed.

## Rule 8 — Always use optimistic locking

**Detection method**: exhaustive grep across all 14 packages' `src`+`Api` for pessimistic-locking primitives —
total hits were low enough (2) that every hit was read, no sampling needed:
```
grep -rniE "for update|lock_mode|lockmode|pessimistic|flock\(|get_lock\(|advisory_lock" packages/*/src packages/*/Api --include=*.php
```
Then every class implementing `StateStoredRepository` (7 implementations — small population, all read in full) was
checked for whether it honours the `$versionBeforeHandling`/`$expectedVersion` parameter it's handed.

**Per-package pessimistic-lock hits**: Dbal 1, PdoEventSourcing 1, all other 12 packages 0.

**Confirmed violations**:
- `packages/PdoEventSourcing/src/Projecting/PartitionState/DbalProjectionStateStorage.php:84-95` — `loadPartition($lock=true)`
  issues `SELECT ... FOR UPDATE` to serialize concurrent projection writers; `savePartition()` (:152-180) does a
  blind `INSERT ... ON DUPLICATE KEY UPDATE` / `ON CONFLICT DO UPDATE` with no version predicate — concurrency is
  handled purely by holding a row lock across the transaction. This is the exact pattern rule 8 says was tried and
  removed elsewhere (`ca8a1b0fc`).
- Four `StateStoredRepository` implementations accept `$versionBeforeHandling`/`$expectedVersion` (per
  `packages/Ecotone/src/Modelling/StateStoredRepository.php:33`, whose own docblock explains the parameter exists
  for a version-guarded write) and then **ignore it**:
  - `packages/Laravel/src/EloquentRepository.php:23-26` — blind `$aggregate->save()`; Eloquent has no built-in
    optimistic locking.
  - `packages/Tempest/src/TempestRepository.php:25-28` — identical pattern.
  - `packages/Dbal/src/DocumentStore/DocumentStoreAggregateRepository.php:33-37` — blind `upsertDocument()`; the
    underlying `DocumentStore` gateway interface (`packages/Ecotone/Api/Gateway/DocumentStore.php:20,27`) has no
    version parameter on `updateDocument`/`upsertDocument` at all, so there is currently no path to enforce this
    even if the repository wanted to.
  - `packages/Ecotone/src/Modelling/InMemoryStateStoredRepository.php:71-76` — also ignores `$expectedVersion`,
    meaning a state-stored-aggregate flow test can never catch a concurrency bug the storage-backed repositories
    should catch. This mirrors, unfixed, the exact `InMemoryEventStore`/DBAL divergence rule 9 already documents
    being caught and fixed for event-sourced aggregates — the same class of gap exists here for state-stored ones.

**Exempt**: `packages/Dbal/src/BatchForwarding/DbalOutboxPublisher.php:163` (`SELECT ... FOR UPDATE SKIP LOCKED`) —
multi-consumer queue-claim semantics (picking one of N available rows for delivery), not aggregate/model state
consistency; optimistic locking doesn't fit a claim-a-row dequeue. `packages/Dbal/src/ObjectManager/ManagerRegistryRepository.php:39-45`
also ignores the parameter but delegates to Doctrine ORM's own native `#[Version]`-mapped optimistic locking, which
throws `OptimisticLockException` on `flush()` — not a gap, a different (valid) mechanism.

**Guard confirmed still enforced**: `packages/Ecotone/src/EventSourcing/Tagging/AggregateCounterTagGuard.php:36,43`
refuses `withoutOptimisticLockFor()` for non-state-stored and event-sourced aggregates;
`packages/Ecotone/src/Modelling/DecisionModel/DecisionBoundaryEvaluator.php:104` and
`Config/DecisionModelModule.php:336-337` refuse an unguarded decision boundary — unchanged from the doc.

**Fix cost**: needs a design decision in both cases, not mechanical. `DbalProjectionStateStorage`'s `FOR UPDATE`
needs either a `state_version` column + conditional UPDATE, or an explicit decision that per-partition
single-writer serialization is a legitimate exception the rule didn't anticipate (its own examples are all
aggregate/decision-model examples, not projection state). The state-stored-repository gap needs a version parameter
added to the `DocumentStore` gateway interface — this is a **public API surface change**, since `StateStoredRepository`
and `DocumentStore` are implemented/called by application code too, so flag it explicitly as a potential breaking
change for anyone with a custom `StateStoredRepository`.

**Recommendation**: this rule is **not** near-fully complied with — the pessimistic-lock primitive itself is nearly
absent (good), but the substance of the rule (an actual version check on every write) is systematically missing
across the entire state-stored-aggregate path outside Doctrine ORM. Needs a design decision before a fix: recommend
scoping a dedicated design doc for "optimistic locking on `DocumentStore`/`StateStoredRepository`" rather than
treating this as several independent one-line fixes, since the interface change is shared across all four sites.

## Rule 9 — Every feature needing external storage ships an in-memory implementation

**Detection method**:
```
grep -rl "^final class InMemory\|^class InMemory" packages/*/src packages/*/Api --include=*.php
```
for the existing-mirror inventory, cross-referenced against storage-backed "seam" classes (`Dbal*`, `Doctrine*`,
role-named classes matching `EventStore`, `DocumentStore`, `TagCollaborator`, `ProjectionStateStorage`,
`StateStoredRepository`, deduplication/dead-letter/tracking stores) found via
`grep -rln "class .*Dbal\|class .*Doctrine" packages/*/src packages/*/Api --include=*.php`. Shared-test-suite check
sampled via `grep -rl "abstract class" packages/*/tests | xargs grep -l "EventStore\|DocumentStore\|ProjectionStateStorage"`.

**Inventory**: all 31 `InMemory*` production classes live exclusively in `packages/Ecotone/src` (event sourcing:
`InMemoryEventStore`, `InMemoryTagIndex`, `InMemoryTagVersionRegister`, `InMemoryTagCollaborator` +
Enterprise/OpenCore variants; projecting: `InMemoryProjectionStateStorage`, `InMemoryProjectionRegistry`,
`InMemoryStreamSource`, `InMemoryEventStoreStreamSource`; modelling: `InMemoryStateStoredRepository`,
`InMemoryEventSourcedRepository`; document store: `InMemoryDocumentStore`; consumer:
`InMemoryConsumerPositionTracker`). Zero `InMemory*` classes exist in any other package. Every mirror named in the
rule's own table (Tag collaborator, EventStore, ProjectionStateStorage) is confirmed present and correctly
name-mirrored against its `Dbal`/`PdoEventSourcing` counterpart.

**Confirmed violations — storage seams with no in-memory counterpart**:
- **Deduplication** (`#[Deduplicated]`): `packages/Dbal/src/Deduplication/DeduplicationInterceptor.php`, backed by
  `DeduplicationTableManager` — no in-memory tracker anywhere. Every deduplication test lives under
  `packages/Dbal/tests/Integration/Deduplication/*`, none in Ecotone's pure flow-testing suite. This feature
  **cannot be exercised in an `EcotoneLite::bootstrapFlowTesting()` test at all** — precisely what rule 9 exists to
  prevent.
- **Dead letter queue**: `packages/Dbal/src/Recoverability/DbalDeadLetterHandler.php` — no
  `InMemoryDeadLetterHandler`. Ecotone's own error-handler tests exercise the generic `ErrorChannel`/retry
  mechanism, not dead-letter storage itself; actual dead-letter storage behaviour is only proven against Dbal
  (`packages/Dbal/tests/Integration/Recoverability/DbalDeadLetterTest.php`).

Both are concrete classes with no interface behind them yet — this is rule 5 territory too (no seam exists to
implement against), so adding an in-memory counterpart is a design-level decision, not a mechanical rename.

**Exempt**: broker/transport packages (Amqp, Sqs, Redis, Kafka, Enqueue) — channel/transport adapters, not the
storage/state seam this rule targets; the generic `InMemoryMessageChannelHolder`/`InMemoryStreamingChannel` already
gives flow tests an in-memory channel substitute. `Dbal/src/ObjectManager/*` (Doctrine ORM) — an alternate
storage-backed implementation of the same seam `InMemoryStateStoredRepository` already mirrors, not a distinct
seam.

**Shared-suite requirement**: sampled, not exhaustive. No abstract base test class was found that both a
Dbal-backed and an InMemory-backed EventStore/DocumentStore/ProjectionStateStorage test extend — the grep for
shared abstracts only turned up `EventSourcingMessagingTestCase`/`DbalMessagingTestCase`, neither shared with an
InMemory-only suite. This is consistent with rule 9's own citation that `InMemoryEventStore` diverged from the DBAL
store three separate times before unrelated work caught it — the structural gap the rule warns against appears
**still open today**; no evidence a shared suite now closes it.

**Fix cost**: deduplication/dead-letter in-memory implementations — needs design (extract an interface per rule 5
first; purely additive, not a breaking change for users). Shared cross-implementation test suite for
EventStore/DocumentStore/ProjectionStateStorage — needs judgement (test restructuring only, no production code
change).

**Recommendation**: core event-sourcing/tag/document-store seams are fully compliant, no action needed there.
Deduplication and dead-letter are real, actionable gaps — recommend enforce and fix, since both are commonly used
production features currently forcing a database dependency into what should be flow-testable behaviour. Separately,
recommend introducing an explicit shared abstract test contract per dual-implementation seam going forward — the
rule's own history shows the ad-hoc alternative already cost three separate divergence bugs, and nothing found in
this audit indicates that risk has been retired.

## Rule 16 — Never issue DDL while handling a message

**Detection method**: exhaustive grep across all 14 packages' `src`+`Api` for DDL statement strings and DBAL
schema-manipulation calls:
```
grep -rniE "CREATE TABLE|ALTER TABLE|DROP TABLE|CREATE INDEX|DROP INDEX|TRUNCATE TABLE" packages/*/src packages/*/Api --include=*.php
grep -rn "createSchema\|dropSchema\|->createTable(\|->dropTable(\|SchemaManager\|AbstractSchemaManager\|->addSql(" packages/*/src packages/*/Api --include=*.php
```
Every hit was read and classified by call context (message path vs. CLI/console vs. `Module::prepare()` vs. test
bootstrap).

**Result**: all DDL-pattern hits are confined to `Dbal` and `PdoEventSourcing`. The other 12 packages contain
**zero** DDL statements or schema-manipulation calls of any kind. Within Dbal/PdoEventSourcing, every DDL string
lives inside a `*TableManager`/`*Schema` class (`DocumentStoreTableManager`, `EnqueueTableManager`,
`DeadLetterTableManager`, `DeduplicationTableManager`, `MySqlEventStreamSchema`, `PostgresEventStreamSchema`,
`SqliteEventStreamSchema`, `*TaggedEventSchema`, `TagSchemaVerifier`) — the intended single-owner location for
schema SQL — called from `DatabaseSetupManager` (the CLI/gateway path, exempt) and from a
`createTable()`/`createDataBaseTable()` method gated behind `shouldBeInitializedAutomatically($connection)` at
every call site.

**Key finding — a wording gap in the rule, not a code violation**:
`shouldBeInitializedAutomatically()` = `$shouldAutoInitialize && !($connection->getDatabasePlatform() instanceof AbstractMySQLPlatform)`
(`packages/Dbal/src/Database/AutomaticTableInitializationTrait.php:16`,
`AutomaticTableInitializationSupport.php:15-18`). So when `AutoCreateLevel::CreateOnly` is active — the default
under `EcotoneLite::bootstrapFlowTesting()`, but also something an application can opt into in production via
`DbalConfiguration::withAutoCreateLevel()` — **and** the connection is Postgres or SQLite (not MySQL/MariaDB), DDL
genuinely runs inline while a message is being handled:
- `packages/Dbal/src/DocumentStore/DbalDocumentStore.php:54,87,98,208` — `addDocument()` and other gateway calls
  run `createDataBaseTable()` on the message path.
- `packages/Dbal/src/Deduplication/DeduplicationInterceptor.php:65,180` — a `#[Before]`-style interceptor wrapping
  every deduplicated message creates its table inline.
- `packages/Dbal/src/Recoverability/DbalDeadLetterHandler.php:60,221,248` — dead-letter table created on first
  failed message.
- `packages/Dbal/src/DbalOutboundChannelAdapter.php:69-73` — sending to a DBAL channel creates its table inline.
- `packages/PdoEventSourcing/src/Projecting/PartitionState/DbalProjectionStateStorage.php:201-219` (called from
  `:86,113,154,186,191`) — projection state save/load during event handling.
- `packages/Dbal/src/DbalInboundChannelAdapter.php:44-58` — table created in `initialize()` (consumer/poller
  startup, not per-message — lower severity, closer to a startup hook than message-handling proper).

This is a deliberate, consistently-applied, single-gated design — not scattered violations — and it is safe on
Postgres/SQLite, where DDL inside a transaction does not implicitly commit (the specific hazard rule 16 exists to
avoid is MySQL/MariaDB-only). But it contradicts the rule doc's own blanket sentence, "Ecotone never issues DDL on
the message path at all" (`docs/coding-conventions.md:849`). The actual guarantee the code implements is narrower:
*no DDL that would break a MySQL/MariaDB transaction*, not *no DDL on the message path, full stop*.

**Violations found: 0.** No unconditional/unguarded auto-create exists anywhere; every `createTable()` call site
uses the same `shouldBeInitializedAutomatically()` gate, which correctly refuses (raising `ConfigurationException`)
on MySQL/MariaDB. `AutoCreateLevel::CreateOnly` usage in the tree is effectively confined to
`createForTesting()`/`createDefaultFor()`'s test-configuration branch — though nothing in the API stops an
application from calling `withAutoCreateLevel(CreateOnly)` itself in production, which is what makes the
Postgres/SQLite in-flight-DDL behaviour above reachable outside tests too, not just inside them.

**Fix cost**: no code fix needed (0 violations). Wording fix — mechanical: reword line 849's summary sentence to
state the MySQL/MariaDB-specific guarantee the code actually implements.

**Recommendation**: revise the rule's wording, not the code. This rule is essentially fully complied with — a
genuinely clean result across the whole codebase, not an artifact of narrow search. The current design (one gate,
refused specifically on the platform where DDL implicitly commits) is correct and doesn't need code changes; only
the rule's own summary sentence overstates the guarantee and should be tightened so a future reader doesn't infer
a broader promise than the code makes.

## Rules the audit could not measure exhaustively

- **1a/1c** (which checks belong at compile time): no mechanical signal distinguishes "this runtime check could
  have run in `Module::prepare()`" from "this runtime check genuinely depends on live data." A ~10-site sample
  found no clear violation but is far too small to extrapolate a rate; this needs a feature-by-feature audit, not a
  codebase-wide sweep, if the maintainer wants a number here.
- Nothing else in this group required sampling beyond what's stated per rule above — rules 4, 8, and 16 were either
  exhaustively read (small populations) or exhaustively grepped with a manageable hit count; rule 1's throw-site
  population was large enough (612) that a ~20% stratified sample was used and is reported as such, not as an
  exact total.
