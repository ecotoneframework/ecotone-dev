# Conventions audit — outstanding findings

What four audits of `docs/coding-conventions.md` found and has **not** been actioned yet, with each item's
disposition. Actioned findings are not repeated here: they are in the git history (`4e7b8d830` merged the mechanical
set — rules 1, 2 partial, 6/6a, 10 partial, 14, 17).

Sources, all committed:

- `docs/superpowers/research/conventions-audit-a/report.md` — rules 2, 3, 5, 12, 17
- `docs/superpowers/research/conventions-audit-b/report.md` — rules 1, 4, 8, 9, 16
- `docs/superpowers/research/conventions-audit-c/report.md` — rules 6/6a, 7, 10/10a, 11, 14
- `docs/superpowers/research/guideline-conformance-audit.md` — a fourth, independent sweep over all 21 rules

**Read the audits as claims to verify, not as a work queue.** They have been wrong five times so far, and each error
would have cost real work:

| Audit claim | Reality |
|---|---|
| None of rule 17's ten files are extended, so `final` is safe on all | `MariaDbEventStreamSchema extends MySqlEventStreamSchema`; `final` there does not compile |
| `MessageHeadersPropagatorInterceptor` has an unbalanced push/pop | Both pops are in a `finally`; the real gap is the missing leak test |
| `DbalTagTables` and `DbalEventStore` both never invalidate their memo | `DbalEventStore::deleteStream()` does invalidate (`:159`); only `DbalTagTables` does not — and that asymmetry is the bug |
| Rule 7's mixed-licence counts | Derived from a grep approximation; `bin/check-licence.php` was never executed (no PHP on host, no containers in that worktree) |
| A gateway named-argument bug: `EventStore::loadAggregateEvents()` fails through the gateway when a named argument skips defaulted parameters | A fully positional call failed identically — PHP resolves named arguments against the generated proxy's own signature before Ecotone sees them. The defect was `GenericType::accepts()`, and it broke every parameter documented as a list, not one gateway (see the row under *Open, no owner*) |

The two rule-3 audits also **contradict each other** on `PendingDeliveryRegistry`, and each missed a class the other
found. Where this document and an audit disagree, this document was verified against the code.

## Deferred by the maintainer

### Rule 3 — services carrying mutable state

Out of scope for now, recorded here in full so the verification is not repeated. Six items:

| # | Site | Finding | Proposal |
|---|---|---|---|
| 3a | `packages/Ecotone/src/Messaging/Scheduling/Clock.php:16`, `:20` | `private static ?EcotoneClockInterface $globalClock`, set in the constructor, **never read anywhere** — a static global duplicating the container's singleton | Delete the property and the assignment. Two lines, no behaviour change. Separately: this file imports `Ecotone\Test\StaticPsrClock` into production code |
| 3b | `packages/OpenTelemetry/src/TracingChannelInterceptor.php:30`, `:42`, `:59` | `$openSpans` is pushed in `preSend()` and popped in `afterSendCompletion()` — **different methods, no `try/finally`**. A `preSend` not followed by its completion makes every later pop take the wrong span, so traces on that channel are silently wrong from then on, and the orphaned `$span->activate()` scope leaks OpenTelemetry context | Correlate each span to **its own message** — the class already writes a `TRACING_CARRIER_HEADER`, or use a `WeakMap` keyed on the message. A missed completion then loses one span instead of desynchronising all of them. Establish first whether the path is reachable |
| 3c | `packages/PdoEventSourcing/src/Dbal/Tag/DbalTagTables.php:19`, `:34` | `$ensuredTables` has **no invalidation at all**, and `DbalEventStore::deleteStream()` does not clear it (it clears only its own, `:159`). Delete a stream then append in the same process: `ensureExist()` returns early, never re-checks `isInitialized()`, the tag table is never recreated, and the append hits a raw driver error instead of §8's "table missing, run this command" instruction. Delete-then-append is ordinary — tests and `ecotone:migration:database:*` both do it | Invalidate the tag memo on the delete path, or drop the memo. Reproduce the sequence as a failing test first; assert §8's instruction, not a driver error |
| 3d | `packages/Ecotone/src/Modelling/MessageHandling/MetadataPropagator/MessageHeadersPropagatorInterceptor.php:24`, `:26` | `currentlyPropagatedHeaders` and `causationChain` stacks on a container singleton. The balance is **correct** (both pops in a `finally`, `:64-65`); what is missing is a test gating the leak | Ship the leak test: two messages through one instance, asserting the second is unaffected, in the style of `DecisionModelStatelessExecutionTest`. The header-carried rewrite (the `DecisionModelLoadedState` pattern, precedent `6b42c7a9b`) is a **separate unit**: `getLastHeaders()` is exposed as an `#[InternalHandler]` on `GET_CURRENTLY_PROPAGATED_HEADERS_CHANNEL`, so components read the propagated headers *without holding the message*, and that out-of-band read is what forces state onto the service. `isPollingConsumer` is deliberately cross-message |
| 3e | `packages/Ecotone/src/Messaging/Channel/DeliveryConfirmation/PendingDeliveryRegistry.php` | **The audits contradict each other.** Audit a: fails rule 3 on every axis (accumulating array, static `WeakMap`, three mutable counters). Fourth audit: "by-design collector" | Leave the behaviour. Its `openScope()`/`closeScope()` semantics mean the state *is* the feature — the exemption rule 3 already grants `InMemory*` stores. Resolve by **documenting the exemption in rule 3's text**. It is `licence Enterprise`, so a wrong call costs licensed users |
| 3f | `ScalarToEnumConverter`, `EventSourcedRepositoryAdapter.php:180` | Filed under rule 3 by the fourth audit; they are **rule 13a** — a `ReflectionEnum` per conversion and a `ClassDefinition` per load instead of the memoizing registry | Real, and on the message path, but a different rule and a performance concern. Measure before changing |

Excluded from rule 3 by every audit, and correctly: the Tempest static caches (architecture-constrained by the
shared-container setup) and the `Type`/`MediaType` caches (value objects, never container-resolved).

## Open, no owner

| Rule | Finding | Disposition |
|---|---|---|
| 12a | The 2.0 `Api/` sweep **missed two public types**: `EventStore` (`packages/Ecotone/src/EventSourcing/EventStore.php`, **176 `getGateway()` call sites** across 65 files today, the most-used gateway in the codebase) and `ConversionService` (`src/Messaging/Conversion/`), which is not a gateway at all — 38 files take it as a typed parameter or property, one test fetches it — but is public surface an application receives. `MessagePublisher`, `DocumentStore`, `DeadLetterGateway`, `ProjectionRegistry` and `CommandBus` all moved correctly. `AGENTS.md`'s own rule-10 guidance told contributors to `getGateway(EventStore::class)` — a class rule 12 declared `@internal`. **The audits could not have found this**: their 12a check sampled 24 of 170 `Api/` files looking for internal types leaking *in*, never for public types living *out*, so their 12a coverage was one-directional | **Done.** `Ecotone\Api\EventSourcing\EventStore`, beside the `AppendCondition`/`EventCriteria`/`LoadedEvents` it names (the `Api/Projecting/ProjectionRegistry` precedent; `Api/Gateway/` was considered and rejected), and `Ecotone\Api\Conversion\ConversionService`, its own area namespace because it is injected rather than fetched. Two rows in `upgrade/namespace-map-2.0.csv`, and `upgrade-2.0.md` §13b. `EventStore::RAW_REFERENCE` and `EventStoreReference::EVENT_STORE_INSTANCE` are unchanged; `ConversionService::REFERENCE_NAME` is `self::class`, so its value moved with the class, which §13b records. Both files now sit outside `src`, so outside phpstan and `bin/check-licence.php` too. Left open, as leaks in the other direction: `EventStore::load()` takes `Ecotone\EventSourcing\EventStore\MetadataMatcher` and `ConversionService` takes `Ecotone\Messaging\Handler\Type`, both of which an application constructs |
| 10 | One raw-SQL assertion left: `packages/PdoEventSourcing/tests/Integration/Tagging/AggregateBackedDecisionModelSnapshotDbalTest.php:100-107`, which asserts the snapshot envelope's stored field names. Audit c's other one, `DeduplicationCleanupMultiTenantTest`'s `SELECT COUNT(*) FROM ecotone_deduplication`, now asserts whether a resent message is handled again per tenant. The 26th container reference is closed: `EventStreamAggregateQueryTest` runs through `getGateway(EventStore::class)` since the documented-list fix below. **`FetchedAggregateReadOnlyTest.php:49` was never one of them**: audit c cited it as a container-reference example, `389d814a7` swapped it to `getGateway()`, and it runs no SQL | **The maintainer has approved deleting the envelope assertion.** `test_the_snapshot_survives_a_restart_of_the_application` beside it already proves from userland that the covered position survives outside the user’s serialization (the fixture converter writes only `balance`), so the envelope test adds only the internal field names — which is what rule 10 forbids asserting |
| — | **Documented list parameters did not resolve** — filed as a "gateway named-argument bug", which it was not. `GenericType::accepts()` read every generic as `[key, value]`, so a single-generic collection such as `string[]` compared each key against the element type and then called `accepts()` on a missing value type. Any non-empty array reaching a parameter documented `@param string[]` — payload or `#[Header]`, through a bus, a `#[BusinessMethod]`, an asynchronous channel or a gateway — failed with `Call to a member function accepts() on null`, and a header documented `@param Foo[]` already holding `Foo`s was refused with `Can't convert … from array<Foo> to array<Foo>`. Present since `deecf49f7` (#523). `EventStore::loadAggregateEvents()` documents `@param string[] $eventNames`, which is how the audit met it; its `fromVersion:` sibling passed only because `eventNames` stayed `[]` and never entered the loop. The named-argument framing was wrong: a fully positional gateway call failed identically. Reproducer: `packages/PdoEventSourcing/tests/Integration/EventStreamAggregateQueryTest.php:59` | **Fixed.** Proved on each path in `packages/Ecotone/tests/Messaging/Unit/Handler/DocumentedListParameterTest.php`, and the reproducer now runs through `getGateway(EventStore::class)`. Adjacent and **not** fixed: a class named by its short name in an anonymous class's docblock resolves against the global namespace, because an anonymous class reports none |
| 16 | **0 unguarded violations** — all DDL sits in `*TableManager` classes gated by `shouldBeInitializedAutomatically()`, which refuses on MySQL/MariaDB. The audit's conclusion is that the **rule text claims more than the code delivers** | The code is right and the rule is wrong. Rewrite the rule and its `AGENTS.md` summary; change no production code |
| 7 | 2 mixed-licence-branch files — `FetchAggregateConverter.php:37` and `EcotoneProjectorExecutor.php:42`, `:129` — carry `licence Apache-2.0` with an `if hasEnterpriseLicence()` inside | Needs a design decision: two classes chosen via `LicenceDecider::prepareDefinition()`, or a compile-time `LicensingException` in the module (rule 1c). **Re-run `bin/check-licence.php` for real first** — these counts rest on an approximation |

## Decided, sequenced

| Rule | Decision |
|---|---|
| 9 | **Conformance suite shipped; its findings are the queue.** `EventStoreConformanceTest`, `TaggedEventStoreConformanceTest` (`packages/PdoEventSourcing/tests/Conformance/`) and `DocumentStoreConformanceTest` (`packages/Dbal/tests/Conformance/`) run every case against each in-memory wiring and DBAL, on PostgreSQL, MySQL, MariaDB and SQLite. Each suite's `KNOWN_DIVERGENCES` ratchet skips a recorded case and fails once it stops diverging. What the suite found, what each side does, and the two seams below are in `docs/superpowers/specs/2026-10-01-conformance-suite-report.md`. **Next, in order:** (1) **T1, its own unit, no design question** — `bootstrapFlowTestingWithEventStore()` indexes no `#[EventTag]`, because `SerializingEventStore` hands `InMemoryEventStore` array payloads, so every DCB flow test on it is vacuous; (2) the user-facing bugs **D5** (a mixed-type array document cannot be read back through JMS) and **D4** (MySQL/MariaDB document ids collide on case); (3) a maintainer decision per remaining divergence (E1–E9, E11, E13, D1, D3). **Deduplication and dead letter are not seams yet:** `DeduplicationInterceptor` and `DbalDeadLetterHandler` are concrete DBAL classes, so an in-memory implementation first needs an interface extracted and the policy moved to core — a design change sketched in the report, not built |
| 8 | **Fix all, accept the break.** Version checks in `EloquentRepository`, `TempestRepository`, `DocumentStoreAggregateRepository` and `InMemoryStateStoredRepository`; replace `DbalProjectionStateStorage`'s `SELECT … FOR UPDATE`. `DocumentStore` gains a version parameter — a breaking `Api/` change, which 2.0 is the right moment for. Sequenced after rule 9, so the conformance suite proves the check holds in **every** implementation rather than only the one edited. **Inherits a failing case:** E10, `EventStoreConformanceTest::test_appending_under_a_stale_aggregate_version_is_rejected` — `DbalEventStore` appends under a stale `AppendCondition::forAggregate()` because it checks only its unique index; the fix removes `dbal` from E10 |

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

## Closed — no action

Rule 4 (0 confirmed; 11 mechanically flagged, all false positives or exempt on triage), rule 12's structural checks
(0 violations, full walk), rule 14 in production code (`#[ConsoleParameterOption]` has no name override, so the rule
is structurally unviolable — the only violations were in `upgrade-2.0.md`, fixed), and rule 6's 271 legacy inline
comments plus 789 legacy prose docblocks (grandfathered by the rule's own text; only the fresh ones were actioned).
