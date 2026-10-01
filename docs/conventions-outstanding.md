# Conventions audit — outstanding findings

What four audits of `docs/coding-conventions.md` found and has **not** been actioned yet, with each item's
disposition. Actioned findings are not repeated here: they are in the git history (`4e7b8d830` merged the mechanical
set — rules 1, 2 partial, 6/6a, 10 partial, 14, 17).

Sources, all committed:

- `docs/superpowers/research/conventions-audit-a/report.md` — rules 2, 3, 5, 12, 17
- `docs/superpowers/research/conventions-audit-b/report.md` — rules 1, 4, 8, 9, 16
- `docs/superpowers/research/conventions-audit-c/report.md` — rules 6/6a, 7, 10/10a, 11, 14
- `docs/superpowers/research/guideline-conformance-audit.md` — a fourth, independent sweep over all 21 rules

**Read the audits as claims to verify, not as a work queue.** They have been wrong six times so far, and each error
would have cost real work:

| Audit claim | Reality |
|---|---|
| None of rule 17's ten files are extended, so `final` is safe on all | `MariaDbEventStreamSchema extends MySqlEventStreamSchema`; `final` there does not compile |
| `MessageHeadersPropagatorInterceptor` has an unbalanced push/pop | Both pops are in a `finally`; the real gap is the missing leak test |
| `DbalTagTables` and `DbalEventStore` both never invalidate their memo | This document first "corrected" it to an asymmetry on `DbalEventStore::delete()`, and that was wrong too. `delete()` never drops the tag tables — they are shared, and it only deletes the stream's index rows — so delete-then-append was never affected. The table that goes stale is dropped by `ecotone:migration:database:delete`, and that left **both** memos stale. See the rule-3 rows under *Shipped since the audits* |
| `TracingChannelInterceptor` desynchronises every later span once a `preSend` misses its `afterSendCompletion` | Not reachable, and backwards: an unpopped push sits at the bottom of the stack and later pairs stay balanced. The proposed per-message keying breaks an existing test. See the rule-3 rows under *Shipped since the audits* |
| Rule 7's mixed-licence counts | Derived from a grep approximation; `bin/check-licence.php` was never executed (no PHP on host, no containers in that worktree) |
| A gateway named-argument bug: `EventStore::loadAggregateEvents()` fails through the gateway when a named argument skips defaulted parameters | A fully positional call failed identically — PHP resolves named arguments against the generated proxy's own signature before Ecotone sees them. The defect was `GenericType::accepts()`, and it broke every parameter documented as a list, not one gateway (see the row under *Open, no owner*) |

The two rule-3 audits also **contradict each other** on `PendingDeliveryRegistry`, and each missed a class the other
found. Where this document and an audit disagree, this document was verified against the code.

## Deferred by the maintainer

### Rule 3 — services carrying mutable state

Out of scope for now, recorded here in full so the verification is not repeated. Four items; 3b and 3c are
resolved, under *Shipped since the audits*:

| # | Site | Finding | Proposal |
|---|---|---|---|
| 3a | `packages/Ecotone/src/Messaging/Scheduling/Clock.php:16`, `:20` | `private static ?EcotoneClockInterface $globalClock`, set in the constructor, **never read anywhere** — a static global duplicating the container's singleton | Delete the property and the assignment. Two lines, no behaviour change. Separately: this file imports `Ecotone\Test\StaticPsrClock` into production code |
| 3d | `packages/Ecotone/src/Modelling/MessageHandling/MetadataPropagator/MessageHeadersPropagatorInterceptor.php:24`, `:26` | `currentlyPropagatedHeaders` and `causationChain` stacks on a container singleton. The balance is **correct** (both pops in a `finally`, `:64-65`); what is missing is a test gating the leak | Ship the leak test: two messages through one instance, asserting the second is unaffected, in the style of `DecisionModelStatelessExecutionTest`. The header-carried rewrite (the `DecisionModelLoadedState` pattern, precedent `6b42c7a9b`) is a **separate unit**: `getLastHeaders()` is exposed as an `#[InternalHandler]` on `GET_CURRENTLY_PROPAGATED_HEADERS_CHANNEL`, so components read the propagated headers *without holding the message*, and that out-of-band read is what forces state onto the service. `isPollingConsumer` is deliberately cross-message |
| 3e | `packages/Ecotone/src/Messaging/Channel/DeliveryConfirmation/PendingDeliveryRegistry.php` | **The audits contradict each other.** Audit a: fails rule 3 on every axis (accumulating array, static `WeakMap`, three mutable counters). Fourth audit: "by-design collector" | Leave the behaviour. Its `openScope()`/`closeScope()` semantics mean the state *is* the feature — the exemption rule 3 already grants `InMemory*` stores. Resolve by **documenting the exemption in rule 3's text**. It is `licence Enterprise`, so a wrong call costs licensed users |
| 3f | `ScalarToEnumConverter`, `EventSourcedRepositoryAdapter.php:180` | Filed under rule 3 by the fourth audit; they are **rule 13a** — a `ReflectionEnum` per conversion and a `ClassDefinition` per load instead of the memoizing registry | Real, and on the message path, but a different rule and a performance concern. Measure before changing |

Excluded from rule 3 by every audit, and correctly: the Tempest static caches (architecture-constrained by the
shared-container setup) and the `Type`/`MediaType` caches (value objects, never container-resolved).

## Open, no owner

| Rule | Finding | Disposition |
|---|---|---|
| 12a | **What the nine-type move deliberately left.** `EcotoneLite` returns `FlowTestSupport` and `ConfiguredMessagingSystem`, which every test calls and which are still `@internal`; `ErrorMessage` returns `ErrorContext`, used in 9 test files. `MessageHeaders` is in `Api` but still carries about ten framework-only statics (`unsetFrameworkKeys`, `unsetAsyncKeys`, `unsetTransportMessageKeys` and the like), and `ModulePackageList::getModuleClassesForPackage()` and `allPackages()` are module-registration plumbing now living in `Api` | Each is its own unit. **`EcotoneLite` + `FlowTestSupport` + `ConfiguredMessagingSystem` move together or not at all** — moving the factory alone would hand every test a public entry point that returns an internal type, which is worse than today because it looks resolved; that unit should also decide whether phpstan ought to analyse `Api/` at all, since `EcotoneLite` is 289 lines of container-factory logic and adding to `Api/` never fails a build. **`ErrorMessage` + `ErrorContext` likewise, both or neither.** The `MessageHeaders` statics and the `ModulePackageList` match arm move to internal collaborators — untidiness, not a leak, since only the framework calls them |
| 12a | **Inward leaks found during the nine-type move**, each an `Api` method the application calls that names an internal type: `EventStore::load()` takes `MetadataMatcher`; `ConversionService::convert()`/`canConvert()` take `Handler\Type` and `MediaType::getTypeParameter()` returns it; `EcotoneClockInterface::now()` returns `Scheduling\DatePoint` and the interface extends `SleepInterface`; `MessagePublisher::publishDeferred()` returns `Messaging\Future`; Dbal's `DeadLetterGateway::show()` returns `Message` and `list()` returns `ErrorContext[]`; `DynamicMessageChannelBuilder::createWithSendOnlyStrategy()` and `withInternalChannels()` take `MessageChannelBuilder`. Two are outward: `#[LogError]`'s level is a `Logger\LoggingLevel` constant and `#[DbalQuery]`'s `fetchMode` a `DbaBusinessMethod\FetchMode` one | Reported, not fixed. Found by reading the imports of every `packages/*/Api` file and asking who calls the method that exposes each; implementation-only imports (`Assert`, `Definition`, channel adapters built in `compile()`) were discarded as rule 12a permits. Each needs its own placement decision — `Type` and `Message` in particular are named across the whole framework |
| 7 | 2 mixed-licence-branch files — `FetchAggregateConverter.php:37` and `EcotoneProjectorExecutor.php:42`, `:129` — carry `licence Apache-2.0` with an `if hasEnterpriseLicence()` inside | Needs a design decision: two classes chosen via `LicenceDecider::prepareDefinition()`, or a compile-time `LicensingException` in the module (rule 1c). **Re-run `bin/check-licence.php` for real first** — these counts rest on an approximation |

## Decided, sequenced

| Rule | Decision |
|---|---|
| 9 | **Conformance suite shipped; its findings are the queue.** `EventStoreConformanceTest`, `TaggedEventStoreConformanceTest` (`packages/PdoEventSourcing/tests/Conformance/`) and `DocumentStoreConformanceTest` (`packages/Dbal/tests/Conformance/`) run every case against each in-memory wiring and DBAL, on PostgreSQL, MySQL, MariaDB and SQLite. Each suite's `KNOWN_DIVERGENCES` ratchet skips a recorded case and fails once it stops diverging. What the suite found, what each side does, and the two seams below are in `docs/superpowers/specs/2026-10-01-conformance-suite-report.md`. **T1 is closed** — `bootstrapFlowTestingWithEventStore()` indexes `#[EventTag]` again; the report says how. **D5 is closed** — a DBAL array document naming no class is read back as a plain array, so mixed value types survive; the report says how. **D4 is decided as a documented engine limitation** — the id inherits the database's default collation, `upgrade-2.0.md` states it with the opt-in `ALTER`, and the entry stays with the engines named; the report weighs the options. **Next:** a maintainer decision per remaining divergence (E1–E9, E11, E13, D1, D3). **Deduplication and dead letter are not seams yet:** `DeduplicationInterceptor` and `DbalDeadLetterHandler` are concrete DBAL classes, so an in-memory implementation first needs an interface extracted and the policy moved to core — a design change sketched in the report, not built |
| 8 | **Shipped where a version can be observed; four limits of the rule and one misnamed opt-out found.** See *Rule 8* below |

### Rule 8 — what shipped, and four limits of the rule as written

**The finding: "always use optimistic locking" is achievable today on two of five storage paths** — the DBAL event
store and the document store. On the other three it describes an aspiration, not a convention the tree follows:
projection state is a work lease, not a record; three of the four state-stored backends have no version to compare
for an aggregate without `#[Version]`; Eloquent never persists `#[Version]`; and Tempest cannot observe whether a
guarded update matched. Each limit below carries its evidence, so none of them is re-filed as a violation.

**Shipped** (`0f47c62f6`, `b403ebfb8`, `4036d4c20`, `8c2d96d8b`):

- **E10 closed.** `DbalEventStore` reads the aggregate's current version before appending under
  `AppendCondition::forAggregate()`, on the plain path and on the tagged (DCB) path — the tagged path had the same
  hole and was recorded nowhere; `TaggedEventStoreConformanceTest::test_appending_a_tagged_event_under_a_stale_aggregate_version_is_rejected`
  proves it. `dbal` is removed from E10, which is now gone from `KNOWN_DIVERGENCES`; the case runs on PostgreSQL,
  MySQL, MariaDB and SQLite. Both stores raise `ConcurrencyException::forStaleAggregate()`
- **`DocumentStore` break.** `updateDocument()` / `upsertDocument()` take a required `int $expectedVersion`;
  `DocumentStore::LAST_WRITE_WINS` is the named opt-out, `getDocumentVersion()` the reload read, a `version` column the
  DBAL storage (a 1.x table is refused with the `ALTER TABLE`). Nine cases in `DocumentStoreConformanceTest`
- **Document-store and in-memory aggregate repositories** check `$versionBeforeHandling`, proved by
  `StateStoredRepositoryConformanceTest` (new; in-memory, document store over in-memory and over DBAL). It exposed a
  fake version: `StateStoredRepositoryAdapter::save()` reported `0` after a creation and for every aggregate without
  `#[Version]`, which `outputChannelName` chains read back as `TARGET_VERSION` — fixed. It also records **R1**: a
  refused save leaves the handler's change in the stored instance on both in-memory rows (D3's root cause)

**Limit 1 — projection state is a work lease, not a record write. `DbalProjectionStateStorage` keeps `FOR UPDATE`.**
The second run of a partition must *wait and continue from where the first got to*, not fail; a version predicate
turns that benign catch-up into a failed command for a synchronous projection, whose transaction is the command's.
On MySQL and MariaDB the lock also makes the read current: under REPEATABLE READ a plain `SELECT` returns the
transaction's old snapshot. Proved by a prototype (not committed): `FOR UPDATE` removed and the save guarded on the
position read, `ProjectingConcurrencyTest::test_interleaved_commands_sees_gaps` went **red on MySQL and MariaDB** —
TX1's `PlaceOrder` failed with the prototype's `ConcurrencyException` where it succeeds today — and stayed green on
PostgreSQL (READ COMMITTED re-reads). The brief's "pessimistic locking was tried here and removed" did not hold:
`c064ca8b5` and `ca8a1b0fc` are event-store and tag locking, not projection state.

**Limit 2 — an aggregate without `#[Version]` has no version to check, on every backend.** Its
`$versionBeforeHandling` is `null` after a load. The document-store repository passes `LAST_WRITE_WINS` for it, named
at the call site rather than laundered through a fetched version; in-memory skips the check; Eloquent and Tempest
have no column. Refusing such aggregates at bootstrap was weighed and rejected: all 11 aggregates and sagas the 7
quickstarts store in the document store lack `#[Version]`, and refusing in one backend while the in-memory one every
flow test uses does not would pass tests and fail at boot. Creation is still checked where the repository runs
before the row exists (document store, in-memory).

**Limit 3 — `#[Version]` on an Eloquent aggregate is a silent no-op.** Measured with a throwaway test (not
committed, it would pin the no-op): a model hydrated by `EloquentRepository::findBy()` reads its `#[Version]` as
**`0` on every load** — the declared property's default, never hydrated from the column — and
`EloquentRepository::save()` with the property at `7` leaves the `version` column at **`0`**: Ecotone's enrichment
writes the declared PHP property, which Eloquent never persists. So `$versionBeforeHandling` is `0` on every load
and a check has nothing to compare. **Decided by the storage-defects unit: a documented limitation**, after the two
shapes ahead of it were measured and ruled out:

- *Making it work* needs a persistence convention an application has to know. Ecotone reads and writes `#[Version]`
  through `getVersion()` / `setVersion()` or else the declared property (`PropertyReaderAccessor`,
  `PropertyEditorAccessor`), and the attribute only targets a property, so the repository would have to copy a column
  named after that property into it on `findBy()` — a declared property shadowing an Eloquent attribute of the same
  name. And the guarded write cannot go through `Model::save()`: `performUpdate()` discards the affected-row count, so
  `UPDATE … AND version = ?` matching nothing is indistinguishable from success, and bypassing `save()` drops the
  model's `saving` / `updating` / `saved` events and its timestamps
- *A bootstrap refusal* cannot tell when it applies. The repository serving an aggregate is chosen at runtime, by the
  first `canHandle()` in `AllAggregateRepository` — an application's own `#[Repository]` first — so compile time cannot
  know whether Eloquent will store a given model, and the refusal would fire on the very configuration it should point
  to. Blast radius, measured anyway: the quickstarts hold 2 Eloquent aggregates
  (`Laravel/Projection/EloquentReadModel` `UserReadModel`, `MultiTenant/Laravel/Aggregate` `Customer`) and `#[Version]`
  appears nowhere in `quickstart-examples`, so it would have broken none of them

`upgrade-2.0.md` states the no-op and the two ways out: a plain class in the document-store repository, or the
application's own `#[Repository]` for the model, running the guarded update itself.
`EloquentRepositoryTest::test_an_application_repository_for_an_eloquent_model_is_chosen_over_eloquent_and_given_the_version_it_loaded`
pins what that second way out relies on — the application's repository wins over Eloquent's, and `save()` receives the
version `findBy()` loaded with the property moved past it; with the application's `canHandle()` returning `false`, the
same test reaches `EloquentRepository` and fails on the table.

**Limit 4 — Tempest cannot observe a guarded update.** `UpdateQueryBuilder::execute()` returns `?PrimaryKey`,
`Database::execute()` returns `void`, `GenericDatabase` keeps the `PDOStatement` in a private property, and
`grep -rn "rowCount\|affected" vendor/tempest/framework/packages/database/src` has **0 hits** — so
`UPDATE … WHERE version = ?` matching nothing is indistinguishable from success, and a stale write is lost silently.
`RETURNING` works on PostgreSQL, SQLite and MariaDB but not MySQL; driving Tempest's raw `Connection::prepare()`
means re-implementing its private, serializer-aware `resolveBindings()` and losing `onDatabase()` tags; read-then-write
leaves the race rule 8 forbids. `ecotone/dbal` is only a composer *suggest* of `ecotone/tempest`, so DBAL is no
fallback. **Ready to send upstream** (the maintainer's call, not filed):

> **Expose the affected-row count of an update.** `UpdateQueryBuilder::execute()` returns `?PrimaryKey`,
> `Database::execute()` returns `void`, and `GenericDatabase` keeps the executed `PDOStatement` private, so a caller
> cannot learn how many rows an `UPDATE` matched. Optimistic locking needs exactly that: `UPDATE … WHERE id = ? AND
> version = ?` matching zero rows is the conflict signal. Ecotone wants to version-check aggregates stored as Tempest
> models and cannot without it. A `Database::execute()` returning the affected-row count, or an
> `UpdateQueryBuilder::executeAndCount(): int`, would be enough.

**Defect 5 — `withoutOptimisticLockFor()` is misnamed. Found by the full-suite gate, not by reading the code** — the
other four were visible to inspection, this one needed the suite. It opts an aggregate out of the DCB **counter tag**,
not out of optimistic locking: its own refusals say so (`AggregateCounterTagGuard`: "only a state-stored aggregate
has a counter tag to opt out of"), while its name and
`StateStoredAggregateCounterDbalTest::test_an_aggregate_excluded_from_the_optimistic_lock_keeps_last_write_wins_across_two_connections`
promised last-write-wins. Once the document-store repository checked `#[Version]`, that test failed: the excluded
aggregate declares `#[Version]`, and the repository refused the stale save. Decided: `#[Version]`, declared on the
class, wins over an exclusion listed on a distant Enterprise configuration — the alternative would make open-core
`StateStoredRepositoryAdapter` read an Enterprise setting (rule 7). The test is renamed to assert the refusal
(`test_a_versioned_aggregate_excluded_from_the_counter_tag_is_still_version_checked_across_two_connections`) and
`test_an_aggregate_without_a_version_excluded_from_the_counter_tag_keeps_last_write_wins_across_two_connections` pins
the other half. **Recommended rename** (breaking Enterprise API, the maintainer's call, not done):
`DynamicConsistencyBoundaryConfiguration::withoutCounterTagFor()`, with the guard messages and `upgrade-2.0.md`
following it.

**Draft sentences for rule 8** (for the maintainer; the rule is not edited here):

> Optimistic locking governs **writes to a record that two writers can decide on**. It does not govern a **work
> lease** — a row that serialises one worker over a queue, such as a projection partition's position — where the
> second worker must wait and continue rather than fail; there, `SELECT … FOR UPDATE` is the correct tool, and on
> MySQL/MariaDB also what makes the read current.

> A version check needs a version to check. An aggregate without `#[Version]` is last-write-wins on every backend, and
> a call that deliberately writes unchecked says so by name (`DocumentStore::LAST_WRITE_WINS`) rather than by
> fetching the current version and passing it back. A backend that cannot report whether a guarded write matched —
> Tempest today — cannot hold the rule, and a check it cannot observe is worse than none.

**Noticed on the way, left alone:**

- The Laravel test application (`packages/Laravel/tests/Application`) saved the measurement's Eloquent aggregate
  through the **in-memory** repository, not `EloquentRepository` — with `'test' => true` and still with it switched to
  `false` — and the renamed title never reached the table. `EloquentIntegrationTest::test_executing_command_action`
  runs in the same application, so its cancel round trip most likely proves `InMemoryStateStoredRepository` rather
  than Eloquent persistence. Not verified on that test, and why the switch did not change the repository was not
  chased
- `upsertDocument()` under `expectedVersion: 0` that loses a race to a concurrent insert reports "at version 1 now";
  the duplicate key aborts a PostgreSQL transaction, so the current version cannot be re-read there

## Deliberately last — judgement-heavy

- **Rule 5** — 31 raw candidates, 24 zero-implementation (dynamic-proxy gateways, constant holders: outside the
  rule), leaving ~7 for case-by-case triage.
- **Rule 2** — 6 of 9 nullable service dependencies remain, 5 classified needs-judgement
  (`ManagerRegistryEmulator`, `DbalParameterConfig`, `MessagingContainerBuilder` ×2, `DirectChannel`,
  `BusRoutingMapBuilder`).
- **Rule 11** — 100 of 141 test files added since 1.x declare a named fixture, **up from the rule's own 92/141
  baseline**: it grew while the rule was being written. Drift reduction, not correctness.

Rules 5, 7 and 2 are **coupled** and should move together: splitting Enterprise behaviour behind an interface
*creates* the two-implementation seam rule 5 permits, so triaging interfaces before deciding the Enterprise splits
means doing it twice.

## Shipped since the audits

Recorded here because a finding that stays under *Open* after it ships sends the next reader chasing closed
work, and invites a second fix.

| Rule | Finding | Outcome |
|---|---|---|
| 7 | `EcotoneProjectorExecutor` (`licence Apache-2.0`) branched on `hasEnterpriseLicence()` at `:42` and `:129` to decide whether projection handlers receive the projection name | **Shipped.** The decision is a `ProjectionNameHeader` collaborator: `OpenCoreProjectionNameHeader` (`licence Apache-2.0`) leaves the headers as they are, `EnterpriseProjectionNameHeader` (`licence Enterprise`) adds `projection.name`. `EcotoneProjectionExecutorBuilder` picks one with `LicenceDecider::prepareDefinition()`, the call shape of `DynamicConsistencyBoundary.php:108`, and `ProjectingModule` registers both unconditionally (rule 2). The executor itself stays one class: splitting it whole would have copied 130 lines to vary two. Open core is unchanged on every path that sends a message — event handler, flush, initialization, delete, and reset during a rebuild — proved by `ProjectionNameHeaderTest` without and with a licence; it passes on the base commit, and forcing the old branch either way fails exactly the five cases of the other mode. `bin/check-licence.php` exits 0 before and after; across `src` and `Api` the tally moves from Apache-2.0 1012 / Enterprise 227 to 1014 / 228, the three new files and nothing else. `ProjectionNameHeader` is a two-implementation seam that rule 5 permits — **for the rule 5 unit to note, not to triage away.** Adjacent, **not** changed: the canonical example rule 7 cites, `EventSourcingHandlerExecutorBuilder.php:72`, passes `Reference` objects to `prepareDefinition(string, string, string)`, which works only because that file lacks `declare(strict_types=1)`; and `AggregrateModule.php:344` registers only the implementation the licence selects, behind an `if`, where `DynamicConsistencyBoundary` registers both |
| 12a | The 2.0 `Api/` sweep missed two public types, `EventStore` and `ConversionService` | **Shipped** in `eb870e30f`: `Ecotone\Api\EventSourcing\EventStore` and `Ecotone\Api\Conversion\ConversionService`, with the namespace-map rows and `upgrade-2.0.md` §13b. `EventStore::RAW_REFERENCE` and `EventStoreReference::EVENT_STORE_INSTANCE` are unchanged; `ConversionService::REFERENCE_NAME` is `self::class`, so its container id moved with it, which §13b documents. The wider gap was closed for nine more types afterwards — see the next row |
| 12a | Eleven more application-facing types were still in `src` | **Shipped** for nine: `WithEvents` and `WithAggregateVersioning` → `Ecotone\Api\Modelling`, `Event` → `Ecotone\Api\EventSourcing`, `MethodInvocation` and `Precedence` → `Ecotone\Api\Interceptor`, `TimeSpan` → `Ecotone\Api\Scheduling`, `MessageHeaders` → `Ecotone\Api\Messaging`, `ModulePackageList` and `DynamicMessageChannelBuilder` → `Ecotone\Api\ExtensionObject`, with namespace-map rows and `upgrade-2.0.md` §13c. `Event`, `Precedence`, `TimeSpan` and `MessageHeaders` were already named by `Api` signatures, so the move closed inward leaks too. None of the nine declares a container id or is registered under its class name, so no service id moved. All nine are now outside phpstan (`src` only) and `bin/check-licence.php` (`packages/*/src` only); `DynamicMessageChannelBuilder` is `licence Enterprise` and is the 38th Enterprise file under `Api/`, so the licence gate's blind spot predates it. **Deliberately not moved:** `EcotoneLite` and `ErrorMessage` — see the rule-12a rows under *Open, no owner* |
| — | **Documented list parameters did not resolve** — filed as a "gateway named-argument bug", which it was not. `GenericType::accepts()` read every generic as `[key, value]`, so a single-generic collection such as `string[]` compared each key against the element type and then called `accepts()` on a missing value type. Any non-empty array reaching a parameter documented `@param string[]` — payload or `#[Header]`, through a bus, a `#[BusinessMethod]`, an asynchronous channel or a gateway — failed with `Call to a member function accepts() on null`, and a header documented `@param Foo[]` already holding `Foo`s was refused with `Can't convert … from array<Foo> to array<Foo>`. Present since `deecf49f7` (#523). `EventStore::loadAggregateEvents()` documents `@param string[] $eventNames`, which is how the audit met it; its `fromVersion:` sibling passed only because `eventNames` stayed `[]` and never entered the loop. The named-argument framing was wrong: a fully positional gateway call failed identically. Reproducer: `packages/PdoEventSourcing/tests/Integration/EventStreamAggregateQueryTest.php:59` | **Fixed.** Proved on each path in `packages/Ecotone/tests/Messaging/Unit/Handler/DocumentedListParameterTest.php`, and the reproducer now runs through `getGateway(EventStore::class)`. Adjacent and **not** fixed: a class named by its short name in an anonymous class's docblock resolves against the global namespace, because an anonymous class reports none |
| 10 | Two raw-SQL assertions in tests | **Shipped.** `DeduplicationCleanupMultiTenantTest` now asserts whether a resent message is handled again per tenant (`e87212d14`); the snapshot-envelope assertion was deleted (`e8d8dabce`) after a mutation proved its sibling covers the same guarantee. The 26 internal-container references closed in `4e7b8d830` and `2e27015a6`. **Zero raw-SQL assertions remain** |
| 3b | `TracingChannelInterceptor` pushes a span in `preSend()` and pops it in `afterSendCompletion()`, with no `try/finally` between them | **Closed, no production change — not reachable.** The only caller of either method is `SendingInterceptorAdapter::send()`, and it calls `afterSendCompletion()` for every interceptor whose `preSend()` returned, on all five outcomes: success, a failing channel send, a failing later `preSend()`, a dropped message, and a failing cleanup or `postSend()` of another interceptor. `TracingScopeCleanupTest`'s eight tests cover the failure outcomes, each asserting no OpenTelemetry scope-detach notice. Tracing's own `preSend()` cannot throw after the push. And the finding was backwards: an unpopped push sits at the bottom of the stack, so later pairs stay balanced; desynchronising them needs more pops than pushes. **The proposed fix is wrong**: keying the open span on the message — by its `TRACING_CARRIER_HEADER` or a `WeakMap` — was built and turned `test_no_scope_detach_notices_when_interceptor_replaces_message_dropping_framework_headers` red with two scope-detach notices and a leaked scope, because `afterSendCompletion()` receives the *final* message, not the one tracing returned, and a later interceptor may have rebuilt it without the header. The experiment was reverted. Still in the file and untouched: the `// @TODO test` on `afterReceiveCompletion()` |
| 3c | `DbalTagTables` memoizes that the tag tables exist and never invalidates it | **Shipped**, with the premise corrected. `DbalEventStore::delete()` never drops the tag tables, and `test_deleting_the_stream_in_the_same_process_keeps_the_tag_index_working_for_the_next_append` records that. The real drop is `ecotone:migration:database:delete --feature=event_tags`, after which a tagged append or a tag backfill in the same process failed with a raw `TableNotFoundException`. `DbalEventStore`'s stream-table memo had the same hole under `--feature=event_stream`, for an append, a tagged append and an event-sourced aggregate creation. **No in-process invalidation can cover a drop made by another process or by an external migration tool, so a memo plus invalidation cannot hold on every path that drops the table; a memo plus conversion does.** The memos stay, and the first statement against each table converts DBAL's typed `TableNotFoundException` — PostgreSQL 42P01, MySQL/MariaDB 1146, SQLite "no such table", mapped by DBAL, not matched on message — into the missing-table instruction the load paths already raised: `d2dc6d8fb` for the tag tables, `bc129766b` for the stream table. No query added per append. A state-stored aggregate save reaches the tag tables through `loadByCriteria()` before it bumps them, so it already raised the instruction; a test records that path. Not changed, and a choice: even under automatic initialization on PostgreSQL or SQLite, a table dropped this way is named, not recreated at runtime, until the setup command runs |
| 16 | The audit reported 0 unguarded DDL violations | **The audit was wrong, and the gap is now closed.** `DbalEventStore::delete()` dropped a stream table with no engine or transaction check; on MySQL/MariaDB that committed the message's writes and then failed at commit with `DriverException: There is no active transaction`, reachable through a `#[ProjectionReset]` handler. Guarded in `ccfd69384` on the intersection of an open transaction and an engine where DDL implicitly commits; rule 16 itself restated in `0843cf77f` and §8 aligned in `a6d3b29d5` |

## Closed — no action

Rule 4 (0 confirmed; 11 mechanically flagged, all false positives or exempt on triage), rule 12's structural checks
(0 violations, full walk), rule 14 in production code (`#[ConsoleParameterOption]` has no name override, so the rule
is structurally unviolable — the only violations were in `upgrade-2.0.md`, fixed), and rule 6's 271 legacy inline
comments plus 789 legacy prose docblocks (grandfathered by the rule's own text; only the fresh ones were actioned).
