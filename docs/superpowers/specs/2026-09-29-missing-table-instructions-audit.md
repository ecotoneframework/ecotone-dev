# Missing-table instructions — audit and implementation report

Unit `implement-dcb-read-path-hygiene`, 2026-09-29. Base `dgafka/ecotone-2-0-dcb-design` @ `13858fe7`.

Covers the three items the maintainer took from the single-read research (rev 2 §13.6 steps 1–2, §2.3(d) / OQ1–OQ2):
delete the `tableExists` probes from the decision read path, make every missing-table failure name the feature, the
table and the two setup commands, and refuse a `#[DecisionBoundary]` scoped only by filter-only tags.

## 1. Probes off the decision path

`DbalEventStore::load()` and `loadAggregateEvents()` ask `information_schema` whether the stream table exists and
return `[]` when it does not. For an aggregate `#[CommandHandler]` that is the right answer — an aggregate that has
never been saved has no events. On the decision path the same answer is a **silently wrong decision**: an
aggregate-backed `#[DecisionModel]` folds zero events and the handler decides on an empty aggregate. The black-box
test pins exactly that: before the change the handler observed a screening with capacity `0` and happily reserved a
seat against it.

**How the two paths are separated: a dedicated store method, not a flag.** `EventStore` gains

```php
public function loadDecisionModelAggregateEvents(
    string $streamName,
    ?string $aggregateType,
    string $aggregateId,
    array $eventNames = [],
): iterable;
```

implemented by `DbalEventStore`, `InMemoryEventStore` and `SerializingEventStore`. The Dbal implementation issues the
read with **no probe** and maps the driver's `TableNotFoundException` onto
`ConfigurationException::create(MissingTableInstructions::build('event_stream', $table, $prefix))` — the same message
`DbalTagTables::missingTablesException` produces for the tag tables, and the same one `ensureTableExists` produces on
append (that site was refactored onto the shared `missingStreamTableException()` rather than repeating the builder
call). `load()` and `loadAggregateEvents()` are untouched: the open-core "missing stream reads as nothing" contract
that `EventSourcingRepository` and the projection stream sources depend on is unchanged. There is no boolean
parameter and no mode on the store; the caller's choice of method *is* the contract it asked for.

`DecisionModelBatchLoader::readEventsOfEachAggregateInstance()` is the only caller — it is also the only decision-path
caller of `loadAggregateEvents()` that is not the shared aggregate repository.

**Scope decision (coordinator, 2026-09-29).** The brief also named "the `#[Fetch]` capture/load in DCB handlers".
The **capture** already raises the unified exception: it rides statement 1 (`loadByCriteria`), which catches
`TableNotFoundException` and calls `DbalTagTables::missingTablesException`. The **load** does not, and cannot be
separated here: `FetchAggregateConverter` → `AllAggregateRepository::findBy()` → `EventSourcedRepositoryAdapter` →
`EventSourcingRepository` → `EventStore::loadAggregateEvents()` is byte-for-byte the path an ordinary aggregate
`#[CommandHandler]` takes, so telling the two apart needs either a boolean flag (excluded by the brief) or a
DCB-specific repository adapter (owned by the parallel `implement-decision-model-snapshots` unit). The coordinator
confirmed: scope the probe removal to the aggregate-backed model read and record the `#[Fetch]` repository read
below.

Measurement was not repeated; the research already has it (≈0.91 ms per probe on PostgreSQL, ≈0.51 ms on MySQL).

## 2. The audit — every place a missing table can surface

`✓` = already names the feature, the table and both commands. `→` = changed by this unit. `~` = silently continues;
listed with a recommendation, not changed.

### packages/Dbal

| Feature | Site | Missing table today |
|---|---|---|
| `dead_letter` | `DbalDeadLetterHandler::store/show/replay/replayAll/delete/deleteAll` → `initialize()` | ✓ |
| `dead_letter` | `DbalDeadLetterHandler::list()` | ~ returns `[]` when auto-create is off |
| `dead_letter` | `DbalDeadLetterHandler::count()` | ~ returns `0` |
| `deduplication` | `DeduplicationInterceptor::handle()` → `createDataBaseTable()` | ✓ |
| `deduplication` | `DeduplicationInterceptor::removeExpiredMessages()` | guarded by `isInitialized()`; correct — cleanup with nothing to clean |
| `document_store` | `addDocument` / `updateDocument` / `upsertDocument` | ✓ |
| `document_store` | `findDocument` / `getAllDocuments` / `countDocuments` / `deleteDocument` / `dropCollection` | ~ empty; `getDocument()` then reports "document not found" |
| `message_queue` | both channel adapters' `initialize()`, auto-declare on | ✓ |
| `message_queue` | receive with `withAutoDeclare(false)` | → was a raw `TableNotFoundException` |
| `message_queue` | send with `withAutoDeclare(false)`, and a send after the table is dropped | → was Interop's "The transport fails to send the message due to some internal error" |

### packages/PdoEventSourcing

| Feature | Site | Missing table today |
|---|---|---|
| `event_stream` | `DbalEventStore::appendTo()` → `ensureTableExists()` | ✓ |
| `event_stream` | `DbalEventStore::loadDecisionModelAggregateEvents()` | → item 1 |
| `event_stream` | `DbalEventStore::load()` / `loadAggregateEvents()` | ~ empty — open-core contract, kept deliberately |
| `event_stream` | `DbalEventStore::hasStream()` | `false`; correct — that is the question asked |
| `event_stream` | `DbalTagBackfiller::backfillTagsForStream()` | → returned a report saying nothing was scanned |
| `event_stream` | `EventStoreGlobalStreamSource::loadFromSingleTable()` | ~ empty `StreamPage` |
| `event_tags` | `DbalTaggedEventReader::loadByCriteria()`, `DbalTagTables::ensureExist()` | ✓ |
| `event_tags` | `DbalTagIndex::deleteForStream()` | returns; correct — a delete with nothing to delete |
| `projection_state` | `DbalProjectionStateStorage` | ✓ |

No path was found that raises a raw driver exception other than the two enqueue ones. The unification the brief asks
for was therefore already in place for every *failing* path; what this unit adds is the two enqueue translations, the
backfill error, the decision-path error, and end-to-end proof of the console prefix.

### Console prefix

`MissingTableInstructions::build()` already takes the prefix `ConsoleInvocationResolver` resolves per integration
(`bin/console`, `php artisan`, `./tempest`, or the programmatic `DatabaseSetupManager` form with no console). That was
only covered by a unit test on the builder. The Symfony and Laravel `DcbSmokeTest`s now each assert that a command
sent against a database with the Ecotone tables dropped reports
`<prefix> ecotone:migration:database:setup --initialize --feature=`, so the resolution is proven inside a real
application of each framework.

### Silent paths — recommendations, not changed

All four are open-core contracts, so per the brief they are listed rather than changed.

1. **`DeadLetterGateway::list()` / `count()`** — the strongest candidate. A user running
   `ecotone:deadletter:list` on an un-migrated database is told there are no failed messages, which is
   indistinguishable from "everything is fine". *Recommendation: raise the instruction.* Nothing legitimately reads
   the dead letter before it has been set up, and both are operator-facing commands where a setup error is the more
   useful answer. Lowest-risk variant if that is felt too strong: keep the empty result and have the console command
   print a one-line note naming the feature.
2. **Document store reads** — `findDocument()` returning `null` on a missing table is defensible (the document really
   is not there), but `getDocument()` then throws "document not found", which sends the reader hunting for a data bug.
   *Recommendation: leave `findDocument()`/`countDocuments()` alone and raise the instruction from `getDocument()`*,
   whose contract is already "this must exist".
3. **`EventStore::load()` / `loadAggregateEvents()`** — keep. This is the contract aggregate loading is built on, and
   the decision path no longer depends on it.
4. **`EventStoreGlobalStreamSource`** — a projection against a missing stream table makes no progress and reports
   nothing. *Recommendation: raise the instruction.* A projection is configured against a named stream; if that
   stream's table is absent, the projection can never do anything, so silence only delays the diagnosis. The reason
   to hesitate is that a projection may legitimately be deployed before the first event is ever appended — worth the
   maintainer's call rather than mine.
5. **The `#[Fetch]` aggregate load inside a DCB handler** (item 1's carve-out) — a DCB handler that `#[Fetch]`es an
   aggregate on a database with the tag tables but no stream table gets `AggregateNotFoundException`, not a setup
   error. *Recommendation: leave it until the parallel snapshots unit lands.* Once `EventSourcedRepositoryAdapter`
   grows a DCB-aware path there (research rev 2 §10.4 / OQ5 hands the fetched aggregate to the batch loader), the
   decision-path read of a fetched aggregate stops going through the shared repository and can use
   `loadDecisionModelAggregateEvents()` for free. Doing it before then would mean either a flag or a second adapter.

## 3. Filter-only `#[DecisionBoundary]`

Research §2.3(d) is the only genuine correctness gap it found: `TagResolver::tagsOfCriteria()` captures every tag of a
boundary's criteria, filter-only ones included, but `EventsTags::counted()` excludes them, so no unconditional append
ever moves them. A boundary scoped only by filter-only tags guards nothing, silently. Models are already refused at
bootstrap (`DecisionModelModule::assertNoModelScopedOnlyByFilterOnlyTags`); boundaries were not.

**Why the check is not at bootstrap.** A boundary is a static method that builds its criteria *from the command*, so
its tag names are not statically knowable — a value-dependent or conditional branch only exists once the method has
run. The three options were: require the names in the attribute, check at runtime, or declare-and-assert.

**Chosen (coordinator-confirmed): check at runtime, on every evaluation,** in `DecisionBoundaryEvaluator::criteriaFor()`
— where the criteria exist and where the class and method names are already fields. It takes `EventTagRegistry`
(which already answers `isFilterOnly()`), walks the branches of the criteria it just built, and raises a
`ConfigurationException` naming the method and the tags when not one tag is counted. Mixed criteria are accepted:
one counted tag anywhere guards the append.

`#[DecisionBoundary(tags: [...])]` was rejected because it makes the user restate names the method already computes,
cannot express value-dependent branches, and would *still* need the runtime assertion to be sound — strictly more API
surface for a strictly weaker check. The runtime walk is over a handful of already-materialised tags and is
negligible against the SQL that follows it; it holds no state and caches nothing, per the stateless-services rule.

## 4. Commits

| Step | Commit |
|---|---|
| 1 — probes off the decision path | `ca58c3f3` |
| 2 — instructions everywhere | `dddd46d8` |
| 3 — filter-only boundary guard | `bdaa6ca0` |
| 4 — docs and this report | see branch tip |
