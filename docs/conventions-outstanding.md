# Conventions audit — outstanding findings

What four audits of `docs/coding-conventions.md` found and has **not** been actioned yet, with each item's
disposition. Actioned findings are not repeated here: they are in the git history (`4e7b8d830` merged the mechanical
set — rules 1, 2 partial, 6/6a, 10 partial, 14, 17).

Sources, all committed:

- `docs/superpowers/research/conventions-audit-a/report.md` — rules 2, 3, 5, 12, 17
- `docs/superpowers/research/conventions-audit-b/report.md` — rules 1, 4, 8, 9, 16
- `docs/superpowers/research/conventions-audit-c/report.md` — rules 6/6a, 7, 10/10a, 11, 14
- `docs/superpowers/research/guideline-conformance-audit.md` — a fourth, independent sweep over all 21 rules

**Read the audits as claims to verify, not as a work queue.** They have been wrong four times so far, and each error
would have cost real work:

| Audit claim | Reality |
|---|---|
| None of rule 17's ten files are extended, so `final` is safe on all | `MariaDbEventStreamSchema extends MySqlEventStreamSchema`; `final` there does not compile |
| `MessageHeadersPropagatorInterceptor` has an unbalanced push/pop | Both pops are in a `finally`; the real gap is the missing leak test |
| `DbalTagTables` and `DbalEventStore` both never invalidate their memo | `DbalEventStore::deleteStream()` does invalidate (`:159`); only `DbalTagTables` does not — and that asymmetry is the bug |
| Rule 7's mixed-licence counts | Derived from a grep approximation; `bin/check-licence.php` was never executed (no PHP on host, no containers in that worktree) |

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
| 12a | The 2.0 `Api/` sweep **missed two userland gateways**: `EventStore` (`packages/Ecotone/src/EventSourcing/EventStore.php`, **156 `getGateway()` call sites**, the most-used gateway in the codebase) and `ConversionService` (`src/Messaging/Conversion/`). `MessagePublisher`, `DocumentStore`, `DeadLetterGateway`, `ProjectionRegistry` and `CommandBus` all moved correctly. `AGENTS.md`'s own rule-10 guidance tells contributors to `getGateway(EventStore::class)` — a class rule 12 declares `@internal` | Move both to `Api/`, update `upgrade/namespace-map-2.0.csv` and `upgrade-2.0.md`. **Note the audits could not have found this**: their 12a check sampled 24 of 170 `Api/` files looking for internal types leaking *in*, never for public types living *out*, so their 12a coverage is one-directional |
| 10 | One raw-SQL assertion left: `packages/PdoEventSourcing/tests/Integration/Tagging/AggregateBackedDecisionModelSnapshotDbalTest.php:100-107`, which asserts the snapshot envelope's stored field names. Plus the 26th container reference, blocked by the gateway bug below. Audit c's other one, `DeduplicationCleanupMultiTenantTest`'s `SELECT COUNT(*) FROM ecotone_deduplication`, now asserts whether a resent message is handled again. **`FetchedAggregateReadOnlyTest.php:49` was never one of them**: audit c cited it as a container-reference example, `389d814a7` swapped it to `getGateway()`, and it runs no SQL | A design call: is the envelope's storage format a guarantee we hold? `test_the_snapshot_survives_a_restart_of_the_application` beside it already proves from userland that the covered position survives outside the user's serialization (the fixture converter writes only `balance`), so the envelope test adds only the field names. Recommendation: delete it |
| — | **Gateway named-argument bug.** `EventStore::loadAggregateEvents()` called with a named argument that skips defaulted parameters fails through the gateway proxy (`Cannot resolve parameter 'eventNames'`) but works through the raw service. Reproducer: `packages/PdoEventSourcing/tests/Integration/EventStreamAggregateQueryTest.php:59` | The only **user-facing defect** the audits surfaced; everything else is about how we write code. Prove across several gateways and argument shapes, not just this one — the mechanism is not `EventStore`-specific |
| 16 | **0 unguarded violations** — all DDL sits in `*TableManager` classes gated by `shouldBeInitializedAutomatically()`, which refuses on MySQL/MariaDB. The audit's conclusion is that the **rule text claims more than the code delivers** | The code is right and the rule is wrong. Rewrite the rule and its `AGENTS.md` summary; change no production code |
| 7 | 2 mixed-licence-branch files — `FetchAggregateConverter.php:37` and `EcotoneProjectorExecutor.php:42`, `:129` — carry `licence Apache-2.0` with an `if hasEnterpriseLicence()` inside | Needs a design decision: two classes chosen via `LicenceDecider::prepareDefinition()`, or a compile-time `LicensingException` in the module (rule 1c). **Re-run `bin/check-licence.php` for real first** — these counts rest on an approximation |

## Decided, sequenced

| Rule | Decision |
|---|---|
| 9 | **Conformance suite first.** One shared suite every seam's two implementations must pass, starting from the existing in-memory/DBAL event store pair — the drift that hit `InMemoryEventStore` three times. Then the missing in-memory deduplication and dead-letter implementations, proved equivalent by that suite on arrival |
| 8 | **Fix all, accept the break.** Version checks in `EloquentRepository`, `TempestRepository`, `DocumentStoreAggregateRepository` and `InMemoryStateStoredRepository`; replace `DbalProjectionStateStorage`'s `SELECT … FOR UPDATE`. `DocumentStore` gains a version parameter — a breaking `Api/` change, which 2.0 is the right moment for. Sequenced after rule 9, so the conformance suite proves the check holds in **every** implementation rather than only the one edited |

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
