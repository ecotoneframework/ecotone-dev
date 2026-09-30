# Ecotone coding conventions

Read this before writing code in this repository, not after review.

Every rule below is one the codebase already follows and that a contributor got wrong at least once. Each carries
a *why* and a citation — a file, or a commit you can `git show`. Where a rule is easy to misread, a wrong/right
pair sits next to it; the wrong side is real code that was corrected.

Rules 1-11 are the maintainer's. Rules 12-19 are conventions the code holds to consistently. Section 20 lists the
mechanics a tool enforces for you, and section 21 the gates and landmines. Lettered sub-rules (1a, 1b, 1c, 6a, 10a,
13a) continue the rule they hang off; they are numbered that way so nothing above them ever renumbers.

> **Verify names before you write them.** Every attribute, parameter, method and console option in code you write
> or documentation you edit must be checked against the current tree first. The 2.0 API moved
> (`Ecotone\Api\Attribute\CommandHandler`, not the old flat namespace), so recall is unreliable. Three invented
> facts reached the first draft of the 2.0 docs this way. A wrong name in a guide is worse than no guide.

---

## 1. Exceptions drive the solution

This is the most-corrected rule in the repository. An exception is read by the agent or developer who has to fix
the problem, so it must contain enough to fix it. Three parts, in order:

1. **What was wrong**, named concretely — the class, method, tag, table, attribute or option, never "invalid input".
2. **Why it cannot work**, one clause.
3. **Every way out**, each an exact API call, attribute, option or console command.

`packages/Ecotone/src/Modelling/EventSourcingExecutor/OpenCoreAggregateMethodInvoker.php` is the shape in one line:

```php
throw InvalidArgumentException::create(
    "Using multiple parameters for Event Sourcing Handler: {$eventSourcingHandler} is part of Enterprise features. "
    . 'Without Enterprise, keep a single event parameter and carry the value you need (for example the time of the '
    . 'change) in the event itself. To read metadata here, obtain Enterprise: https://docs.ecotone.tech/enterprise'
);
```

`packages/Dbal/src/Database/MissingTableInstructions.php` is the fuller model: it names the feature, the table, the
connection when it is not the default, and then the exact command for the framework the application is running —
`bin/console`, `php artisan`, `./tempest`, or the PHP to call when there is no console at all.

Enumerate *all* the ways out, not one. `TagTransactionRequirement` lists four, and gained the fourth
(`7cf42b477`) precisely because a user could be blocked by `#[WithoutDatabaseTransaction]` and the message did not
say so.

**Test the message, not just the class.** The message is the deliverable, so it is what the test asserts. See
`packages/Dbal/tests/Unit/Database/MissingTableInstructionsTest.php`: one test per framework, each asserting the
command string it must contain.

```php
$this->expectException(ConfigurationException::class);
$this->expectExceptionMessage('#[WithoutDatabaseTransaction]');
```

Evidence: `7cf42b477`, `5fa0024b2`, `c9b54d3f9`, `048f614d8`, `d8f81c26b`, `f85f057a8`, `d3a25ac3c`,
`84623fa0e`, and the whole `implement-expression-failure-context` worktree.

### 1a. A configuration mistake is refused at bootstrap, never at runtime

If a combination cannot work, detect it while the messaging system is being built, so it fails on the first test
run rather than on a production message. Twelve commits in the DCB feature alone did nothing but move a failure
earlier: `823dd41c3`, `c48e1b7c7`, `383de545a`, `9984fae25`, `d90ae125f`, `083b9edbd`, `39d420535`, `6ef6e48df`,
`d92883148`, `d3a25ac3c`, `b984c8d41`, `24edcf663`.

**The validation ships in the same commit as the rule it enforces.** This is the most expensive omission the DCB
retrospective measured, and two of its three independent friction reports found it. `implement-dcb-edge-cases` is a
whole worktree of 20 commits, 18 of them `fix(dcb):`, and every one has the same shape: a rule the design spec or
the upgrade guide already stated had no enforcing code, so the misconfiguration booted silently. A model whose
handled events share no `#[EventTag]` name "folded nothing and guarded nothing" (`083b9edbd`); a class carrying both
`#[DecisionModel]` and `#[Aggregate]` "booted silently with two contradictory roles" (`9984fae25`); a command
property holding an array "silently scoped the model by its first element" (`6ef6e48df`). The remaining six did
raise an error, but named the wrong table, the wrong cause, or a resume position that did not exist (`7cf42b477`,
`f85f057a8`, `d8f81c26b`, `c9b54d3f9`, `d2a0f5171`, `d3a25ac3c`) — which is rule 1, and is why the test asserts the
message and not the exception class. A spec sentence with neither the guard nor the message test is an edge case
waiting for someone to spend a worktree finding it.

`packages/Ecotone/src/EventSourcing/Tagging/AggregateCounterTagGuard.php` is the pattern: static assertions the
module calls at compile time, each throwing `ConfigurationException` that names the class and what to do instead.

Never let a wrong configuration read as an empty result. `048f614d8` fixed exactly that: a missing event-stream
table used to return no events, so a decision was taken on an empty aggregate. It now raises the setup
instructions.

### 1b. Failure context is a value object built at container-build time

Do not assemble location strings at the throw site. `packages/Ecotone/src/Messaging/Handler/ExpressionLocation.php`
is compiled into the container with the attribute name, target and owner already in it, and every expression
failure in the framework goes through it, producing
`#[Fetch] on $activity in Handler::count failed. Expression: ... . <cause>` (`c45cf02bd`).

### 1c. A check that can run at compile time runs at compile time

Rule 1a says *when* a wrong configuration fails. This is *where the check lives*: in the module, during
configuration building, not in a service on the message path.

`Module::prepare()` is handed the `InterfaceToCallRegistry` as its fourth parameter for exactly this — it can see
every handler, its parameters and its attributes before a single message exists:

```php
public function prepare(
    Configuration $messagingConfiguration,
    array $extensionObjects,
    ModuleReferenceSearchService $moduleReferenceSearchService,
    InterfaceToCallRegistry $interfaceToCallRegistry,
): void
```

The shape is a **guard**: a class of static assertions the module calls, each throwing `ConfigurationException`.
`packages/Ecotone/src/Modelling/DecisionModel/Config/DecisionModelModule.php:113` calls two of them from
`prepare()` — `CrossConnectionDecisionModelGuard::assertNoCrossConnectionInjection()` (:126) and
`DecisionModelTagResolvabilityGuard::assertEveryModelTagResolvableFromItsMessage()` (:133), the second taking the
annotation finder and the registry so it can answer the question over every model in the application.
`packages/Ecotone/src/EventSourcing/Tagging/Config/EventTaggingModule.php:71` does the same with
`AggregateCounterTagGuard` (:82-83).

**The same knowledge answers both questions, so ask it early.** `MessageTagValueResolver` has two entry points:
`canResolve($tagName, $messageClass)`, which the resolvability guard calls at compile time, and
`resolve($tagName, $payload)`, which `DecisionModelParameterLoader` calls per message. Because the guard refuses an
unresolvable tag at bootstrap, the runtime path has no unresolvable case to handle — no branch, no fallback, and no
silently empty result (rule 1a).

**What is left to runtime is message content, and only that.** The shape of the configuration is knowable while
compiling; the value carried by a particular message is not. A tag value's length in characters and its UTF-8
validity are runtime checks for that reason (`d92883148`) — everything about *which* tag, on *which* model, from
*which* property is compile-time.

So, before adding a check: ask whether it could have been answered from the class, the method, the parameter types
and the attributes alone. If it could, it belongs in a guard the module calls, and the test that proves it boots
Ecotone and expects a `ConfigurationException`.

---

## 2. Never take a nullable service dependency

A `?SomeService $service = null` constructor parameter always becomes a null check that is really a mode switch,
and the two modes then drift. Register services unconditionally and decide at runtime.

```php
// wrong
public function __construct(private ?AttributeExpressionExecutor $executor = null) {}
// ... later
if ($this->executor !== null) { /* expression mode */ } else { /* payload mode */ }
```

There are four ways to remove one. Pick by what the null actually meant:

| The null meant | Do this | Precedent |
|---|---|---|
| Two genuinely different behaviours | **Split the class in two** | `65ba2a852` — `InMemoryEventSourcedRepository` keeps array storage, `EventStoreEventSourcedRepository` requires a store; shared `canHandle()` moves to a trait |
| "There is no expression / no value here" | **A null-object factory + a `has*()` question** | `7f7d24bc0` — `AttributeExpressionExecutor::withoutExpression()`, then `hasExpression()` instead of `!== null` |
| Nothing — every real call site passes one | **Make the parameter required** | `3e9e92d56` — `InMemoryEventStore` and `DbalEventStore` dropped `?AppendStrategy`/`?TagCollaborator` defaults |
| Open core versus Enterprise | **Two services, one chosen in the container** | `LicenceDecider::prepareDefinition()` (rule 7) |

Services are registered unconditionally, always:
`packages/Ecotone/src/Messaging/Config/Container/Compiler/RegisterSingletonMessagingServices.php` registers every
core service with no conditions at all, `LicenceDecider` included — the licence is a constructor argument to it,
not a reason to skip the registration.

Evidence: the `implement-no-nullable-services` worktree (`fb35cf316`), and `eb859ceb8` in `upgrade-2.0.md`.

The rule predates the code it had to correct, and correcting it cost a worktree of its own: 6 commits over four
classes — `InMemoryEventStore`, `DbalEventStore`, `DecisionModelParameterLoader`, `InMemoryEventSourcedRepository` —
deleting defaults that, in the commits' own words, "every real construction site already passed concrete instances"
for (`3e9e92d56`, `7f7d24bc0`, `65ba2a852`, `84e70e8c4`, `e15bd042f`, `eb859ceb8`). There is nothing here to
discover later, only work to redo, so check it while you are writing the constructor.

---

## 3. Constructor-injected services are stateless

A service reached through the container is a singleton for the life of the process. Anything it remembers between
calls leaks into the next message, the next transaction, and the retry of a message that already failed.

Forbidden in a constructor-injected service: instance properties that accumulate, static caches, and
`$somethingByMessageId` collectors.

**Where mutable state goes instead: a function-scoped, immutable value object.**
`packages/Ecotone/src/Modelling/DecisionModel/DecisionModelLoadedState.php` is the worked example — a `final`
class of `readonly` properties, built once per handler invocation by a before-interceptor and carried on a message
header, read by the converter, the append interceptor and `SaveAggregateService`. It replaced two singleton
collectors keyed by message id (`6b42c7a9b`).

The maintainer's own record of why, in `docs/superpowers/specs/2026-09-20-dcb-design.md` (2026-09-28 row):

> Services injected through constructors must be stateless: the snapshot tracking leaked into the next transaction
> on the same connection, and the collectors leaked conditions/instances across query handlers, converter failures
> and retries with the same message id.

Static caches count. `a98c23fa8` removed `DecisionModelReflection`'s static caches; reflection facts are read on
demand. `ca8a1b0fc` removed per-connection snapshot tracking from `DbalTagVersionRegister`.

Statelessness is a testable property: see
`packages/Ecotone/tests/Modelling/DecisionModel/DecisionModelStatelessExecutionTest.php` (`6b42c7a9b`), which drives
the same handler repeatedly and asserts the later executions are unaffected by the earlier ones.

**A mechanism that remembers anything between calls ships its leak test as the RED commit**, before the mechanism
is designed rather than after a later unit trips over it. Two of the three DCB friction reports name this
independently as the most-repeated corrective pattern in their scope. `424220979` ("InnoDB own-bump tracking must
not leak into the next transaction") is exactly the test that should have gated the tracking `ca8a1b0fc` deleted
one commit later — and it landed two units downstream of the unit that built it, so the cost fell on somebody
else's schedule. Some of these bugs are only provable under real contention on a real connection pool, which is
the reason to write that test first, not a reason to skip it.

---

## 4. Do not mix queries with writes

A method that returns a value must not change state, and a method that changes state returns `void`. The name has
to say which it is.

Three shapes this went wrong in, all fixed in `4bb23eb51`:

- **A read ran DDL.** `loadByCriteria()` used to create the tag tables it was about to read. Now a missing table
  raises the setup `ConfigurationException` (rule 1a) and reads never issue DDL.
- **A mutator returned a value.** Tag-version bumps, the backfill and the in-memory append now return `void`.
- **A resolver mutated a static cache.** `MessageTagValueResolver` no longer does.

**When a name hides a write, rename it.** `getRecordedEvents()` cleared the buffer it returned, and agents wrote
`assertCount(2, ...)` after a second command and were wrong. It is now `popRecordedEvents()`, and every destructive
reader on `packages/Ecotone/src/Lite/Test/FlowTestSupport.php` is `pop*`: `popRecordedCommands()`,
`popRecordedEventHeaders()`, `popRecordedMessagesFrom()`. This was decided as D2 in
`docs/superpowers/specs/2026-09-15-agent-detours-2-0-design.md`, with no aliases kept.

---

## 5. Do not create an interface with a single implementation

An interface in this codebase marks a real seam, and there are only two kinds: **open core versus Enterprise**
(`AggregateMethodInvoker`, `DbalTagCollaborator`), and **in-memory versus storage-backed** (`EventStore`,
`StreamSource`). If there is one implementation, the class is the type.

The technique for removing one without reintroducing the construction cycle that motivated it: **pass the concrete
class as a method argument, not a constructor argument.**

```
// wrong: an interface that exists only so a collaborator can call back into its caller
interface InMemoryStreamAccess { ... }          // one implementation: InMemoryEventStore

// right
public function append(InMemoryEventStore $store, ...): void   // per call, never stored
```

Evidence: `84e70e8c4` (drops `InMemoryStreamAccess`), `e15bd042f` (drops `DbalEventRowAccess`), and
`docs/superpowers/specs/2026-09-28-dcb-readability-review.md` §4, which flags `AppendableStore` as "an interface
that exists only to let the strategy call back into its caller".

---

## 6. Names carry the meaning. No comments, no descriptive docblocks

The rule is not "explain less". It is that the explanation belongs in a name, an exception message, or a document —
never in a comment that will drift from the code beside it.

**Allowed docblocks, and nothing else:**

- array shapes and generics: `@param array<class-string, ?string> $aggregateTypesByClass`, `@return class-string[]`,
  `@template`
- `@link https://docs.ecotone.tech/...` on an `Ecotone\Api` class

**Not allowed:** prose describing what a class or method does, inline `//` comments, and `@param string $routingKey
The event routing key` on a parameter whose name already says it.

Decided as D4 in `docs/superpowers/specs/2026-09-15-agent-detours-2-0-design.md`:

> No descriptive docblocks in the API. Semantics come from names and exception messages; rename where a name does
> not convey meaning.

The docblocks removed under this rule were accurate and useful — that is the point. `5c3a9ed2c`
("docblocks carry array shapes only"), `9ca15e6ea` and `75e943982` ("drop explanatory docblocks — names carry
it"), `8f065ed98` ("the expression classes speak through their names alone"). `764d53c49` names where the
explanation went instead: *"`DdlOutsideActiveTransaction` — drop the explanatory docblock, the name and upgrade
guide §8 carry it."* (That class has since been removed along with runtime DDL — see rule 16 — but the commit is
still the clearest statement of where a deleted explanation is supposed to land.)

So when you delete an explanation, put it in one of these: a better class or method name, the text of the
exception the situation raises, `upgrade-2.0.md`, or a design spec under `docs/superpowers/specs/`.

Legacy prose docblocks remain in older files. Leave them where you are not otherwise touching the file; do not add
new ones.

### 6a. One word per concept, through the whole path

Two names for one value forces every reader to re-prove they are the same thing. `a8f23212c` settled DCB's two
numbers: **`version`** is only ever the optimistic-lock counter, **`sequence`** only ever the order stamp. Before
it, the same two values travelled as `version`, `expectedVersion`, `capturedVersion`, `tagVersion`, `sequence`,
`sequencedBy`, `seq` — and `EventsTags::sequencedBy(array $versionsByTagKey)` took versions and emitted sequences.
The cost is spelled out in `docs/superpowers/specs/2026-09-28-dcb-readability-review.md` §2.

When you introduce a term, grep for the concept first and reuse the word already in use. When you rename, rename
the whole path in one commit. `c3b1cfa8e` and `40eacaa22` are renames of this kind.

---

## 7. Enterprise features live in separate files

Never `if ($this->licence->hasEnterpriseLicence())` inside one class. Two classes behind one interface, chosen once
in the container.

The canonical set, all in `packages/Ecotone/src/Modelling/EventSourcingExecutor/`:

- `AggregateMethodInvoker` — the interface, the seam
- `OpenCoreAggregateMethodInvoker` — `licence Apache-2.0`; throws the message in rule 1 when asked for the
  Enterprise behaviour
- `EnterpriseAggregateMethodInvoker` — `licence Enterprise`; the real implementation
- wired in `EventSourcingHandlerExecutorBuilder.php:72` with
  `LicenceDecider::prepareDefinition(AggregateMethodInvoker::class, ...)`

### The licence header

Every PHP file under `packages/*/src` and `packages/*/Api` carries a licence docblock. There are two of them, and
the only difference is the word:

```php
/**
 * licence Apache-2.0
 */
```

```php
/**
 * licence Enterprise
 */
```

It goes immediately above the `class`/`interface`/`trait` line — so, in an attribute class, *after* the
`#[Attribute(...)]`:

```php
#[Attribute(Attribute::TARGET_METHOD)]
/**
 * licence Apache-2.0
 */
class CommandHandler extends InputOutputEndpointAnnotation
```

That placement is not a preference: `bin/add-apache-licence.php` inserts the block directly before the first line
matching `abstract class|final class|class|interface|trait`, and `bin/add-enterprise-licence.php` is the same script
with the other word. Run them rather than typing the header, and `bin/check-licence.php` to check
(silent, exit 0 when every file has one — it is the CI gate, rule 21).

Some older files carry the same text as a `/* ... */` block at the top of the file, before
`declare(strict_types=1)` — `packages/Ecotone/Api/Projecting/PartitionProvider.php` is one.
`bin/check-licence.php` accepts that form, so do not sweep them, but write the docblock form in new files.

1009 files carry `licence Apache-2.0` and 227 carry `licence Enterprise`. **When in doubt, `Apache-2.0`** — a
feature is Enterprise by decision, not by which namespace it landed in, and the maintainer will say so.

Test files carry `/** licence Apache-2.0 @internal */` too (php-cs-fixer adds the `@internal`, not the licence),
but they are a convention rather than a gate: `bin/check-licence.php` only walks `packages/*/src`.

**Write it in the Enterprise file from the first commit; extracting it later is the expensive path.** `a7f08f6a7`
("extract `DbalEventStore`'s DCB tag logic into Enterprise classes"), `7bf1082a2` and `3a6134d18` are what that
costs. `DbalEventStore` and `InMemoryEventStore`, both `licence Apache-2.0`, had grown the entire DCB tag protocol
inline — `appendEventsWithTagCondition`, `loadByCriteria`'s capture and read, `backfillTagsForStream`, the
snapshot-hazard bookkeeping, the SQL-state mapping — and a review pass put it plainly: *"That is Enterprise logic
living in an Apache-2.0 file."* Unpicking it took five to six new classes per store family
(`DbalTagVersionRegister`, `DbalTagConditionalAppender`, `DbalTaggedEventReader`, `DbalTagBackfiller`,
`EnterpriseDbalTagCollaborator`/`OpenCoreDbalTagCollaborator`, and the in-memory mirrors) wired through
`LicenceDecider` — the pattern `EnterpriseAppendStrategy` was already using in this same codebase while the tag
protocol was being written inline. Before writing behaviour a licence gates, read the licence header of the file
you are in.

**Split the implementation, never the interface.** Licence is not an axis to split a type on: it was tried once,
for the event store, and reversed. `TaggedEventStore` and `AggregateEventStore` were folded back into a single
`EventStore` with an `AppendStrategy` chosen by `LicenceDecider` (`983db0c5a`, `5a5f08572`, `ade73e6c5`,
`1be829b95`, with the maintainer call recorded in `bd255a13a`) — after the split shape had already propagated into
every repository, the gateway registration, the integration tests and `DcbSmokeTest`. One interface, two
implementations, chosen in the container: rule 5's seam.

**Open-core behaviour stays byte-for-byte unchanged.** An Enterprise feature may not alter what an application
without a licence observes. This was a standing check in every DCB brief, and is why the licence seam is a
separate class rather than a branch.

---

## 8. Always use optimistic locking

Locking is a version check on a write, never a pessimistic lock and never a bespoke tracker.

- `c064ca8b5` removed `WriteLockStrategy` entirely: "concurrency is the unique index alone".
- `ca8a1b0fc` removed InnoDB own-bump snapshot tracking, which had been tried: "the guarded `UPDATE` is the sole
  conflict mechanism". It was removed both because it was state on a service (rule 3) and because it duplicated the
  lock.
- A conflict surfaces as `ConcurrencyException` (`packages/Ecotone/src/Messaging/Support/ConcurrencyException.php`)
  or `DecisionModelConcurrencyException`, with a message that names what was being decided (`c9b54d3f9`).
- Because the check and the write must commit together, tagged appends require an active transaction and say so
  when there is none (`78bb0d244`, and `TagTransactionRequirement` in rule 1).

Switching a lock off is guarded, not free. `withoutOptimisticLockFor()` exists for state-stored aggregates only,
and `AggregateCounterTagGuard` refuses it for an `#[EventSourcingAggregate]` ("its stream's own version check is
its lock and cannot be switched off") or for anything that is not an aggregate. `33d927ac9` went further: a
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

---

## 10. Tests validate at the userland level only

**The single most-corrected rule in this repository** — a whole worktree, 22 of `implement-blackbox-tests`'s 22
own commits, did nothing but pull tests back to the public surface, with no new coverage in any of them, and two
of the 22 (`f2ef0469a`, `f383622f4`) walk back the rewrite's own over-correction. Whatever the test needs to know,
it asks the public API.

Never, in a test:

- read Ecotone's own tables with SQL
- reach a service by its internal reference
- use reflection
- count SQL statements
- name an internal collaborator class in the test's own name

```php
// wrong — bypasses the gateway to reach the implementation
$store = $ecotone->getServiceFromContainer(EventStore::RAW_REFERENCE);

// right
$store = $ecotone->getGateway(EventStore::class);
```

```php
// wrong — asserts on Ecotone's internal tables
$indexRows = (int) $connection->executeQuery(
    "SELECT COUNT(*) FROM ecotone_tagged_events WHERE tag_name = 'coupon' AND tag_value = 'SUMMER24'"
)->fetchOne();
self::assertSame(2, $indexRows);

// right — the behaviour already proves it
$this->expectExceptionMessage('Coupon SUMMER24 is exhausted');
$commandBus->send(new RedeemCoupon('SUMMER24'));
```

The *why*, from `3c5bdf306`'s own message: "The exhaustion exception on the third redeem already proves both prior
events were correctly tagged and folded into the decision model — the raw counts were a redundant internal check
on top of that." If the observable behaviour is right, the internals are right; asserting both only guarantees the
test breaks the next time the internals are refactored.

**What to do instead**, in order of preference:

1. Assert on what the application observes: a query handler's answer, a published event, an exception and its
   message.
2. If you need the store, the projection manager or the dead letter, fetch it as a **gateway**:
   `$ecotone->getGateway(EventStore::class)`, and read through its public methods
   (`loadByCriteria()`, `popRecordedEvents()`).
3. For a console command, assert on its console output (`9e1d89d43`, `70dad03b8`).
4. Name the test after the behaviour, not the class that implements it (`294c0a3d4`).

Evidence: `12d960553` (the `implement-blackbox-tests` worktree), `3c5bdf306`, `d15d2627d`, `8065c0b19`,
`50354a085`, `d3920e4f2`, `f000f86ef`, `191178d36`, `e7e2c11a8`, `cb1ca48be`, `4a2acf01d`, `9e1d89d43`,
`70dad03b8`, `c11b863dc`, `2f54403fe`, `8b7fec7f7`, `04e831b55`, `294c0a3d4`.

A performance or statement-count claim is **review-protected, not test-protected** — `04e831b55` deleted a
statement-counting test rather than keep it. State the claim in the spec and let review hold it. The DCB effort
proved this twice over one claim: `6b30eb5cd` added a `QueryCountingDbalConnection` to assert one `loadByCriteria()`
per handler however many decision models it injects, and `04e831b55` deleted it again — "one-load-per-handler is
review-protected". What a test *can* hold is the behaviour: the N>1 case has to work, and that is rule 10a.

### 10a. A guarantee is proved on every path that has to hold it

Ecotone reaches the same behaviour several ways — a direct call, `CommandBus::send()` resolving by payload class,
`CommandBus::sendWithRouting()`, a gateway, a console command — and a change that updates one of them looks
finished. Two of the three DCB friction reports found this shape five times between them:

| The guarantee | The path that was missed | Commit |
|---|---|---|
| A tagged append is guarded by the tag versions captured inside the transaction | aggregate saves through `SaveAggregateService` bumped tag counters unconditionally, so a commit competing with a loaded aggregate was not caught | `c09bac1e0` |
| Filter-only tags are indexed | the live append path indexed them, the backfill that reconstructs the index did not, so a backfilled index silently disagreed with a live one — fixed by sharing one `EventsTags::sequencedBy` | `0548c4130` |
| `#[WithoutDatabaseTransaction]` is honoured | fixed for the `CommandBus` gateway wrapper; still broken for a class-routed `CommandBus::send()`, which resolves routing through `BusRoutingKeyResolver` instead | `57c076084`, then `9ae4e3fd3` |
| `loadByCriteria()` is on the unified `EventStore` | never registered as a gateway action in `EventSourcingModule`, so an `EventStore` from the container or the gateway did not expose it | `ade73e6c5`, then `99daa69ba` |

Before the commit that adds a method to an interface, an attribute to a handler, or behaviour to a write path:
enumerate the entry points it must work through, and write one test per entry point. The live path and the
reconstruction path of one concept count as two of them — and they should share one method rather than be
maintained as two.

---

## 11. Test shape

Every test bootstraps Ecotone and drives it as a user would.

```php
<?php

declare(strict_types=1);

namespace Test\Ecotone\Modelling\AggregateBoundary;

use Ecotone\Api\Attribute\Aggregate;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\Identifier;
use Ecotone\Api\Attribute\QueryHandler;
use Ecotone\Lite\EcotoneLite;
use PHPUnit\Framework\TestCase;

/**
 * licence Apache-2.0
 * @internal
 */
final class BasketTest extends TestCase
{
    public function test_adding_an_item_is_visible_to_the_query_side(): void
    {
        $basket = new #[Aggregate] class () {
            #[Identifier]
            private string $basketId = '';

            private int $items = 0;

            #[CommandHandler('basket.open')]
            public static function open(string $basketId): self
            {
                $basket = new self();
                $basket->basketId = $basketId;

                return $basket;
            }

            #[CommandHandler('basket.add')]
            public function add(): void
            {
                $this->items++;
            }

            #[QueryHandler('basket.items')]
            public function items(): int
            {
                return $this->items;
            }
        };

        $ecotone = EcotoneLite::bootstrapFlowTesting([$basket::class]);
        $ecotone->sendCommandWithRouting('basket.open', 'b-1');

        $ecotone->sendCommandWithRouting('basket.add', metadata: ['aggregate.id' => 'b-1']);

        $this->assertSame(1, $ecotone->sendQueryWithRouting('basket.items', metadata: ['aggregate.id' => 'b-1']));
    }
}
```

- **`EcotoneLite::bootstrapFlowTesting()`** for everything;
  `bootstrapFlowTestingWithEventStore()` when the test needs a real event store.
- **`snake_case` method names**, enforced by php-cs-fixer. Name after the behaviour, not the implementation:
  `test_adding_an_item_is_visible_to_the_query_side`, not `test_basket_repository_saves`.
- **`final class`**, with `/** licence Apache-2.0 @internal */`. php-cs-fixer adds `@internal`.
- **No comments, no docblocks, no assertion messages.** The method name is the description.
- **Fixtures live in the test file, as inline anonymous classes.** Never a shared `Fixture/` directory — and not a
  named class below the `TestCase` where an anonymous one does the job. Aggregates included: the example above is
  one, and an anonymous class is registered by the generated name its own instance reports, which is all
  `classesToResolve` needs. `packages/Ecotone/tests/Modelling/Unit/MessageBusTest.php:452` and
  `packages/Ecotone/tests/Modelling/Unit/Causation/AggregateNotFoundCausationTest.php:25` are the committed
  precedents: anonymous aggregates with `#[Identifier]` properties, static `#[CommandHandler]` factories, query
  handlers, and the service handlers around them, all registered through `$fixture::class`.

  **A large share of the existing tests do not follow this.** 92 of the 141 test files added since 1.x declare
  named fixtures below the `TestCase`. That is drift to be reduced, not a convention to copy: a new test uses an
  anonymous class unless one of the two exceptions below applies to that particular class.

  **The two exceptions.** Both are hard PHP limits rather than preferences, and nothing outside them qualifies:

  1. **A class used as a type declaration** — every command, event and query, and any object a handler,
     `#[EventSourcingHandler]` or converter names in a parameter or return type. PHP has no syntax for naming an
     anonymous class in a type. `AggregateNotFoundCausationTest` is the shape to copy: `OrderCreated` is a named
     class below the `TestCase`, while both aggregates and the event handler that pass it around are anonymous.
  2. **A class named inside another fixture's attribute argument** — an attribute argument is a constant
     expression, so `$fixture::class` cannot appear in one and the referenced class needs a name written in
     source: `#[FromAggregateStream(EventSourcedBasket::class)]`
     (`packages/JmsConverter/tests/Integration/InterfaceTypedPayloadTest.php:68`, where the projection carrying the
     attribute is itself anonymous) and `#[DecisionModel(aggregate: WalletForAggregateBackedTest::class)]`
     (`packages/Ecotone/tests/Modelling/DecisionModel/AggregateBackedDecisionModelTest.php:370`).

  Three things that look like exceptions and are not:

  - **`#[AggregateType]`** takes a string name, not a class-string
    (`packages/Ecotone/Api/Attribute/AggregateType.php`), so it forces nothing — an anonymous aggregate carries it.
  - **`EventCriteria`** takes its class-strings at runtime — `EventCriteria::aggregate(string $aggregateClass, ...)`,
    `ofTypes(string ...$eventTypes)` — where `$basket::class` works. The event types it names are already named
    under exception 1, never for `EventCriteria`'s sake.
  - **An exception message containing the class name.** The test holds the instance, so interpolate it:
    `$this->expectExceptionMessage($service::class . '::handle')`. Three test files do exactly that, in five places —
    `packages/Dbal/tests/Integration/MultiTenant/WithTenantResolverPlacementValidationTest.php:47`,
    `WithTenantResolverLicensingTest.php:38`, and `AggregateNotFoundCausationTest.php:60`, which builds an entire
    expected message out of `$wallet::class` and `$walletCharging::class`. Better still, assert the part of the
    message that is the instruction and leave the class name out of it.

  Where an exception does apply, suffix the named fixture per test file (`UnlockedBasketForWithoutLock`) so two
  test files in one namespace cannot collide.
  `packages/Ecotone/tests/Modelling/AggregateBoundary/WithoutOptimisticLockTest.php` is a full example of the named
  form — including, at line 81, an exception-message assertion that could have interpolated `::class` instead.
- **No static properties or static methods** in a test class, for the same reason as rule 3.
- A private `bootstrap()` helper on the test class is fine and common when several tests need the same wiring.

**Write the test first.** RED, then GREEN, then refactor, one increment per commit, and prove the test is red
against the base commit before making it pass. This was in every DCB task brief. `424220979` is a RED commit
landed on its own.

---

## 12. The public surface is `Api/`, a sibling of `src/`

Everything an application is meant to reference — attributes, `#[ServiceContext]` extension objects, gateways —
lives under `Ecotone\Api`. **Everything outside `Api` is `@internal` and may change in a minor version**
(`upgrade-2.0.md` §13).

`Api/` is a directory at the package root, **never** `src/Api/`. Nesting the `Ecotone\Api\*` PSR-4 root inside the
`Ecotone\<Package>\*` root broke Tempest's package auto-discovery: `AutoloadDiscoveryLocations` derives one scan
location per declared PSR-4 prefix, so a nested prefix walks the same files twice and PHP fatals with "Cannot
redeclare class". Fixed at the layout level for every package in `52f582967`; the regression test is
`packages/Tempest/tests/Application/AppNamespaceAutoDiscoveryTest.php`.

Every package declares exactly:

```json
"autoload": { "psr-4": { "Ecotone\\Api\\<Package>\\": "Api/", "Ecotone\\<Package>\\": "src" } }
```

Note the namespace is `Ecotone\Api\<Package>`, not `Ecotone\<Package>\Api`.

**Where a new class goes:**

| It is | Namespace |
|---|---|
| A cross-cutting messaging or modelling attribute | `Ecotone\Api\Attribute\*` |
| A configuration or extension-object value object | `Ecotone\Api\ExtensionObject\*` |
| A gateway interface | `Ecotone\Api\Gateway\*` |
| Anything a user only meets after opting into a feature | `Ecotone\Api\<Module>\*`, **flat** |
| Anything in another Composer package | `Ecotone\Api\<Package>\*` |

Two rules resolve the overlaps:

- **The sub-module rule beats the kind rule.** A module-scoped class stays flat inside its module even when it is
  an attribute or a gateway: `Ecotone\Api\Projecting\Projection`, `Ecotone\Api\Projecting\ProjectionStateGateway`
  — never `Ecotone\Api\Projecting\Attribute\Projection`.
- **Only a package whose `Api/` mixes kinds *and* is large enough gets kind sub-namespaces.** Today that is core
  and `Dbal` only. `Amqp`, `Kafka`, `Redis`, `Sqs`, `Symfony`, `Laravel`, `Tempest`, `DataProtection`,
  `JmsConverter` and `PdoEventSourcing` are flat — adding `Attribute\` for one class adds noise.

Full reasoning and the old→new mapping: `docs/superpowers/specs/2026-09-16-api-namespace-layout-mapping.md` and
`upgrade/namespace-map-2.0.csv`.

**Always `use`-import a sibling `Api` class, even from the same namespace tree.** PHP resolves a bare name against
the current namespace, so an attribute referencing a sibling without an import works until the namespace is split,
then fails at reflection time — which `phpstan` at level 1 does not catch, because attribute arguments resolve
lazily. This broke fifteen classes during the namespace split; see §5 of the mapping spec.

---

## 13. Configuration is attributes plus `#[ServiceContext]`

No YAML and no XML for Ecotone configuration — the only YAML in `packages/` is the Symfony bundle's own framework
wiring. Users configure declaratively with attributes, and programmatically with a `#[ServiceContext]` method
returning an extension object:

```php
final class EcotoneConfiguration
{
    #[ServiceContext]
    public function dbal(): DbalConfiguration
    {
        return DbalConfiguration::createWithDefaults()->withTransactionOnCommandBus(true);
    }
}
```

A module reads them with `ExtensionObjectResolver::resolve(MyConfig::class, $extensionObjects)`.

Configuration is **compiled into a container**, so anything a module registers has to be expressible as a
`Definition` rather than a constructed object. A value object that needs to survive compilation implements
`DefinedObject` (`packages/Ecotone/src/Messaging/Config/Container/DefinedObject.php`) and returns a `Definition`
naming its class, constructor arguments and optional factory. `LicenceDecider::prepareDefinition()` and
`ExpressionLocation::definitionFor*()` are the patterns to copy.

**Module registration is explicit, not discovered.** A new module class must be added to the right
`ModuleClassList` constant, and a new package's name to `ModulePackageList` (constant, `allPackages()`, and the
`getModuleClassesForPackage()` match arm). Modules implement `AnnotationModule`, carry `#[ModuleAnnotation]`, and
are `final`. The `ecotone-module-creator` skill has the full scaffold — including the `NoExternalConfigurationModule`
base class and the `AnnotationFinder` API — and is the place to look rather than this file.

### 13a. Class metadata comes from the registry, not from fresh reflection

`InterfaceToCall` and `ClassDefinition` are the metadata layer. They exist so nothing else has to build a
`ReflectionClass`, and `InterfaceToCallRegistry` memoizes both —
`packages/Ecotone/src/Messaging/Handler/InterfaceToCallRegistry.php` keys `getFor($class, $method)` and
`getClassDefinitionFor($type)` by name and returns the same instance on every later ask. A module or builder that
reflects for itself pays the cost again and gets an object the rest of the framework cannot reuse.

So during configuration building, ask the registry `prepare()` already gave you:

```php
// wrong — the same facts the registry already holds, re-derived
$attributes = (new ReflectionClass($className))->getAttributes(EventTag::class);

// right
$interfaceToCall = $interfaceToCallRegistry->getFor($className, $methodName);
$interfaceToCall->getMethodAnnotationsOf(EventTag::class);
$interfaceToCallRegistry->getClassDefinitionFor(Type::object($className));
```

`packages/Ecotone/src/Modelling/AggregateFlow/SaveAggregate/AggregateResolver/AggregateDefinitionResolver.php:29`
and `packages/Ecotone/src/Modelling/Config/Routing/BusRoutingMapBuilder.php:94` are the shape for the second one.

**And on the message path, take the metadata as a constructor argument rather than reading it.** A `Definition`
that needs an `InterfaceToCall`, one of its parameters, or an attribute instance names it as a reference —
`InterfaceToCallReference`, `InterfaceParameterReference`, `AttributeReference` — and the
`RegisterInterfaceToCallReferences` compiler pass
(`packages/Ecotone/src/Messaging/Config/Container/Compiler/RegisterInterfaceToCallReferences.php`) walks every
definition's arguments and method calls and registers each one as its own container definition. The reflection runs
once, while compiling; the compiled container holds the result, and the runtime service receives it already built.
`packages/Ecotone/src/Modelling/AggregateFlow/LoadAggregate/LoadAggregateServiceBuilder.php:77` and
`packages/Ecotone/src/Projecting/Config/ProjectingAttributeModule.php:184` are the pattern; 36 places in
`packages/*/src` build an `InterfaceToCallReference` this way.

Raw reflection has not disappeared — 31 files under `packages/*/src` still build a `ReflectionClass` — but look at
which ones: the layer that *produces* this metadata (`TypeResolver`, `ClassDefinition`, `InterfaceToCall`,
`AttributeResolver`, the annotation finders), and compile-phase modules, builders and guards asking something the
metadata layer does not expose, such as `class_exists()` or `isAbstract()`. The line is not "never reflect". It is
**nothing on the message path reflects, and nothing re-derives what the registry already holds.**

If a value genuinely is not knowable until a message arrives, read it on demand and **do not cache it** — a cache
on a container service is rule 3, and both attempts at one were deleted rather than kept: `a98c23fa8` removed
`DecisionModelReflection`'s static caches, and `4bb23eb51` stopped `MessageTagValueResolver` mutating one. That is
the reason to move the lookup to compile time rather than to memoize it at runtime: the memo is not available to
you.

---

## 14. A console option's name is its PHP parameter name, verbatim

`#[ConsoleParameterOption]` takes no name argument. The CLI option is the parameter name exactly as written, so it
is camelCase, never kebab-case.

```php
#[ConsoleCommand('ecotone:event-store:backfill-tags')]
public function backfill(
    #[ConsoleParameterOption] int $batchSize = 500,
    #[ConsoleParameterOption] bool|string $skipUndeserializable = false,
): ConsoleCommandResultSet
```

gives `--batchSize` and `--skipUndeserializable`. Writing `--skip-undeserializable` in an exception message or a
document is a bug: `5fa0024b2` fixed exactly that, in a hint that told users to run an option that did not exist.
See also `packages/Dbal/src/Database/DatabaseSetupCommand.php` (`--onlyUsed`, `--initialize`, `--connection`).

---

## 15. Orchestrating methods, with the SQL one level down

A method that reads as a list of steps stays readable; the same method with SQL inlined into it does not. SQL
belongs to the collaborator that owns the table.

`4bb23eb51` is the worked example: tag knowledge went to `TagResolver`/`EventsTags`/`AppendedTags`/`TagKey`; the
DBAL side split into `DbalTagVersionRegister` (capture and bump), `DbalTagIndex` (the
`ecotone_tagged_events` SQL), `DbalTagTables` (readiness) and `TagConcurrencyGuard` — "the six main functions read
as step lists with no inline SQL".

Related: do not pass the store into its own collaborators as a stored dependency
(`docs/superpowers/specs/2026-09-28-dcb-readability-review.md` §5); pass it per call (rule 5).

---

## 16. Never issue DDL while handling a message

MySQL and MariaDB implicitly commit the surrounding transaction on any DDL statement, so a `CREATE TABLE` in the
middle of a message breaks the commit or rollback that message's transaction expects. 1.x carried a workaround that
string-matched the driver's error message and swallowed the failure.

2.0's answer is not a better workaround — it is that **Ecotone never issues DDL on the message path at all**. Tables
are created through the CLI, through the `DatabaseSetupManager` gateway, or by the application's own migration tool,
and a missing table raises the setup instructions instead (rule 1a). `AutoCreateLevel::None` is the default outside
test bootstraps; `AutoCreateLevel::CreateOnly` is what `EcotoneLite::bootstrapFlowTesting()` uses, and is refused on
MySQL/MariaDB for the reason above. The whole story is `upgrade-2.0.md` §8.

So: do not add a code path that creates, alters or drops a table while a message is in flight, and do not add a
workaround for a transaction a DDL statement committed — remove the DDL. `MissingTableInstructions::buildForUnsupportedAutomaticInitialization()`
says the same to the user: "creating a table implicitly commits the surrounding transaction there, and Ecotone will
not split your message transaction to do that."

History: `230bcea5d` and `511b31428` first moved this DDL out of the transaction; `048f614d8` and the rest of rule
1a then removed the runtime creation entirely, and the helper those two commits introduced is gone with it.

---

## 17. `final` and `declare(strict_types=1)` on new files

113 of the 121 classes added since 1.x are `final`; 132 of 133 new `src` files declare strict types. Older files
predate both — do not sweep them, but do not add a new file without them.

Interfaces are the exception to `final`, and are only created under rule 5.

---

## 18. PHP 8.1+ features, used where they say something

Attributes, enums, named arguments, `readonly` properties, constructor property promotion, first-class callables.
`DecisionModelLoadedState` and `ExpressionLocation` (both above) are the house style: promoted `readonly`
constructor parameters, static named factories, no setters.

Named arguments are the norm at call sites with more than two parameters —
`EcotoneLite::bootstrapFlowTesting(classesToResolve: [...], licenceKey: ...)` — because the signature has ten
parameters and positional calls are unreadable.

---

## 19. Keep the two audiences' documents current

A behaviour change that a 1.x application would notice belongs in `upgrade-2.0.md`, with **Before / Now / How to
adapt**. A design decision and its rejected alternatives belong in a spec under `docs/superpowers/specs/`. Both
are where rule 6 sends the prose you did not put in a comment.

**A rename updates the spec in the same commit.** The spec is part of the unit, not a follow-up, and when it is
treated as one somebody pays for an archaeology pass instead. `eec9625d5` is a dedicated readability review that
found the designated first-read design document still describing `ecotone_event_tags` and `tag_version` — a table
and a column the shipped code never had (`ecotone_tagged_events`, `tag_sequence`) — alongside a documented
guarantee, that a decision model scoped only by a filter-only tag name is a bootstrap `ConfigurationException`,
implemented nowhere. That one review produced seven commits and left four ranked items open.

`docs/superpowers/` is in `.gitignore` but the specs are tracked, so adding one needs `git add -f`.

---

## 20. Mechanics php-cs-fixer enforces

Run `vendor/bin/php-cs-fixer fix` **on the host, never inside the container** — `git` is not available there, so
the fixer loses its file filter and rewrites the whole repository. Config: `.php-cs-fixer.dist.php`.

| Rule | Shape |
|---|---|
| `php_unit_method_casing` | `public function test_it_does_the_thing(): void` |
| `not_operator_with_successor_space` | `! $var`, never `!$var` |
| `single_quote` | `'string'` unless interpolating |
| `trailing_comma_in_multiline` | last element of a multiline array or argument list |
| `global_namespace_import` | `use function sprintf;` at the top, then bare `sprintf(...)` — not `\sprintf(...)` |
| `ordered_imports`, `no_unused_imports` | alphabetical, none spare |
| `php_unit_internal_class` | `@internal` on test classes |
| `array_indentation`, `no_empty_phpdoc`, `no_useless_return` | |

It does not add licence headers; `bin/add-apache-licence.php` does.

---

## 21. Gates and landmines

**Gates.**

- `bin/check-licence.php` runs on every push and pull request (`.github/workflows/file-licence.yml`) over
  `packages/*/src`. `Api/` files all carry headers too, though the script does not yet check them.
- `phpstan` is **level 1** and covers `src` plus `Monorepo` — not `Api/`, not `tests/`, and not the `Tempest`,
  `Redis`, `Sqs` or `DataProtection` packages. It will not catch a wrong class in an attribute argument. Do not
  treat a green phpstan as evidence of anything beyond syntax.
  [dev-workflow.md](./dev-workflow.md#static-analysis-licence-headers-code-style) has the exact path list.
- `composer tests:ci` **at the root** = phpstan, then `packages/DataProtection/tests/before-tests.sh` (it generates
  a 200 MB fixture), then phpunit, then the quickstart examples. Four packages have their own additions to it;
  [dev-workflow.md](./dev-workflow.md#what-composer-testsci-runs) lists them.

**Landmines.**

- Run tests **inside the container, one package at a time, never in parallel** — the packages share the compose
  services and a parallel run produces false failures.
- Do not `composer install` or run tests with `-u root`: it leaves `vendor/` root-owned on the host.
- The monorepo **root** `vendor/` is always loaded, even for a single package's run, because Ecotone's annotation
  finder requires the root `vendor/autoload.php`. Keep it installed and in sync.
- `vendor/bin/phpunit --no-coverage` for a single file; a plain run trips PHPUnit 12's coverage requirement.
- Every commit carries an 08:00 timestamp:
  `GIT_AUTHOR_DATE="$(date +%Y-%m-%d) 08:00:00" GIT_COMMITTER_DATE="$(date +%Y-%m-%d) 08:00:00" git commit ...`
- Never commit `Monorepo/*/Symfony/config/reference.php` — they drift on their own.
- Conventional commit subjects: `feat(scope):`, `fix(scope):`, `refactor(scope):`, `test(scope):`, `docs(scope):`,
  `style(scope):`. One increment per commit.

---

## Where the rest lives

These state their areas in more detail. Where something is covered there, read it rather than re-deriving it —
two copies of a rule drift apart.

| Where | Covers |
|---|---|
| [AGENTS.md](../AGENTS.md) | what the project is, the monorepo layout, the test-first loop, committing, opening a PR |
| [docs/dev-workflow.md](./dev-workflow.md) | containers, per-package test commands, database DSNs, licence and style tooling, what CI runs |
| `ecotone-testing` | `EcotoneLite` patterns, async-tested-synchronously, projection tests, diagnosing failures |
| `ecotone-module-creator` | `AnnotationModule` scaffold, `AnnotationFinder`, `ExtensionObjectResolver`, new packages |
| `ecotone-enterprise` | which features are Enterprise and why |
| `ecotone-handler`, `ecotone-aggregate`, `ecotone-event-sourcing`, `ecotone-workflow`, `ecotone-interceptors`, `ecotone-asynchronous`, `ecotone-resiliency`, `ecotone-metadata`, `ecotone-identifier-mapping`, `ecotone-business-interface`, `ecotone-distribution` | the user-facing API of each area |

- [Full documentation](https://docs.ecotone.tech)
- `upgrade-2.0.md` — every 1.x → 2.0 behaviour change
- `docs/superpowers/specs/` — design decisions, alternatives considered, and why
