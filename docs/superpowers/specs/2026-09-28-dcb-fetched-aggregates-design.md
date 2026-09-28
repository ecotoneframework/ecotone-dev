# Aggregates inside the Dynamic Consistency Boundary — design

Status: **proposal**, nothing implemented
Date: 2026-09-28
Base: `dgafka/ecotone-2-0-dcb-design` at `40eacaa2`
Builds on: `docs/superpowers/specs/2026-09-20-dcb-design.md` (Parts 4–8 are the shipped design and are taken as
settled here), `docs/superpowers/specs/2026-09-28-dcb-readability-review.md` (items 3–6 are still open; §6.6 below
says where this proposal touches them)

Answers the maintainer's two questions:

1. An **event-sourced** aggregate injected with `#[Fetch]` is outside the boundary. How do we load it "just as we
   load a `DecisionModel`" — pre-invocation, by criteria, with its version captured into the same
   `AppendCondition`?
2. A **state-stored** aggregate has no events and no stream. How do we give it a consistency boundary at all?

The short answer to both is one mechanism: **an aggregate's identity becomes a counted tag.** `ecotone_tag_versions`
already is a general-purpose optimistic-lock register keyed by `(tag_name, tag_value)`, guarded by
`UPDATE ... WHERE version = :captured`. Giving each aggregate instance a row in it puts event-sourced and
state-stored aggregates under exactly the same guard as a decision model, through code paths that already exist.
Everything below is the argument for that, the two things it is *not* (it is not a second way to read an aggregate,
and it is not a replacement for the unique index), and the cost.

---

## Part 1 — Today's mechanics, precisely

Read from the code at `40eacaa2`. Where I am inferring rather than reading, the paragraph says so.

### 1.1 How `#[Fetch]` resolves and loads an aggregate

`#[Fetch]` is one attribute with two destinations, chosen at bootstrap in
`ParameterConverterAnnotationFactory::getConverterFor()` (`packages/Ecotone/src/Messaging/Config/Annotation/ModuleConfiguration/ParameterConverterAnnotationFactory.php:130-148`):

| Parameter type | Converter built |
|---|---|
| a `#[DecisionModel]` class | `DecisionModelConverterBuilder` — the expression supplies **tag values** |
| any other object type | `FetchAggregateConverterBuilder` — the expression supplies **aggregate identifiers** |

A parameter typed with a `#[DecisionModel]` class and *no* `#[Fetch]` also becomes a `DecisionModelConverterBuilder`
(`:152-154`). There is no equivalent bare-type-hint path for aggregates; an aggregate parameter always needs
`#[Fetch]`.

The aggregate path, `FetchAggregateConverter::getArgumentFrom()`:

1. Enterprise licence check (`LicensingException` otherwise).
2. The expression is evaluated against the message (`payload.getUserId()`, `headers['userId']`,
   `"{'a': payload.x, 'b': payload.y}"`).
3. A scalar result is mapped onto the aggregate's single identifier name; more than one identifier without an array
   is an `InvalidArgumentException`.
4. `AllAggregateRepository::findBy($class, $identifiers)` → the first registered `AggregateRepository` that
   `canHandle()` the class.
5. **`return $resolvedAggregate?->getAggregateInstance();`** — `FetchAggregateConverter.php:68`.

Step 5 is the whole of the problem. `findBy()` returns a `ResolvedAggregate`, which carries
`versionBeforeHandling`; the converter throws it away and hands the handler a bare object. Nothing is recorded
anywhere — no header, no interceptor state, no condition.

**Event-sourced aggregate.** `EventSourcedRepositoryAdapter::findBy()` optionally loads a snapshot from the document
store, then `EventSourcingRepository::findBy()` →
`EventStore::loadAggregateEvents($stream, $aggregateType, $aggregateId, $fromVersion)` — a query on the stream
table's own aggregate columns, not on the tag index — folds the events with the aggregate's
`#[EventSourcingHandler]`s, and returns `ResolvedAggregate(..., versionBeforeHandling: $aggregateVersion, ...)`
(`EventSourcedRepositoryAdapter.php:103`). When the class uses `WithAggregateVersioning` /
`#[Version]` with automatic increment, the loaded version is also written back onto the instance's version property
(`:93-101`), so it survives step 5 *on the object* even though the `ResolvedAggregate` does not.

**State-stored aggregate.** `StateStoredRepositoryAdapter::findBy()` calls the user's
`StateStoredRepository::findBy()` and wraps the result:

```php
return new ResolvedAggregate(
    $this->aggregateDefinitionRegistry->getFor($aggregateClassName),
    false,
    $aggregate,
    null,                       // versionBeforeHandling — hard-coded null
    $identifiers,
    [],
);
```

(`StateStoredRepositoryAdapter.php:40-47`.) On this path the version is not even read. If the class has `#[Version]`
the value is on the instance, because the user's repository rehydrated it; the framework does not look.

**A fetched aggregate is never saved.** `AggregateResolver::resolveMultipleAggregates()` resolves exactly two
things: the aggregate named by `AggregateMessage::CALLED_AGGREGATE_INSTANCE` / `CALLED_AGGREGATE_CLASS`, and a new
aggregate instance returned as the handler's payload. `SaveAggregateService::process()` saves only what that
resolver returns. A `#[Fetch]`-injected aggregate appears in neither. So `#[Fetch]` is **read-only injection**, and
it is read-only by construction, not by a check.

One consequence is a live footgun, independent of DCB: a handler that fetches an event-sourced aggregate and calls
a state-changing method on it records events into the `WithEvents` buffer, and **those events are silently
dropped** — nothing reads that buffer for a non-called aggregate. For a state-stored aggregate under Doctrine ORM
the opposite happens: the entity is managed, so a mutation *is* persisted by the next `flush()`
(`ManagerRegistryRepository::save()` flushes when `autoFlushOnCommand` is on, but the entity manager may also flush
for other reasons), entirely outside Ecotone's save flow and its version handling. Both are worth a paragraph in
the docs regardless of what we decide here; §4.4 proposes turning the first one into an exception.

### 1.2 What an aggregate command handler's save guards

`EventSourcingRepository::save()` (`packages/PdoEventSourcing/src/EventSourcingRepository.php:47-61`) and its core
twin `EventStoreEventSourcedRepository::save()` build:

```php
$appendCondition = AppendCondition::forAggregate($aggregateType, (string) $aggregateId, $versionBeforeHandling)
    ->mergeWith(DecisionModelLoadedState::appendConditionIn($metadata));
```

and pass it to `EventStore::appendTo()`. Two halves, enforced by two different mechanisms:

**The aggregate half is not enforced by any statement.** `DbalEventStore::appendEventsWithAggregateCondition()`
(`DbalEventStore.php:133-136`) is literally

```php
public function appendEventsWithAggregateCondition(string $streamName, array $events, AppendCondition $appendCondition): void
{
    $this->appendEventsUnconditionally($streamName, $events);
}
```

The guard is the unique index over `(_aggregate_type, _aggregate_id, _aggregate_version)`, and it fires because
`SaveAggregateServiceTemplate` has already stamped `versionBeforeHandling + n` into each event's metadata. The
`AppendCondition`'s aggregate part is a *declaration* of the expectation the index will enforce, not an instruction
the store executes.

**This is the load-bearing fact for everything below.** An aggregate expectation can only be enforced by *writing an
event of that aggregate at that version*. A handler that reads aggregate `Customer c-1` and writes `OrderPlaced`
cannot be guarded by that index, because it writes no `Customer` event. No amount of plumbing changes that; a
`SELECT`-then-check is a re-check race, which Part 3 of the shipped spec rejects on exactly these grounds.

**The tag half is enforced.** `DbalTagConditionalAppender::appendEventsWithTagCondition()` resolves the tags the
events carry, unions them with the condition's tags (`AppendedTags`), captures the versions of any tag the condition
does not name, and runs the guarded `UPDATE`/`INSERT` per tag in sorted order before inserting the events and their
index rows. Zero affected rows → `DecisionModelConcurrencyException`.

So an `#[EventSourcingAggregate]` command handler's save guards, today:

- its own next version, via the unique index;
- every counted tag its events carry — on the version an injected decision model captured, or, failing that, on a
  version captured inside the transaction (§4.5, "every tagged append is guarded", 2026-09-28).

### 1.3 What a service handler with `#[Fetch]` and returned events guards

Only a handler that is already a *DCB handler* appends its returned events at all. `DecisionModelModule::findAppendEligibleMethods()`
selects non-aggregate `#[CommandHandler]`/`#[EventHandler]` methods that either inject a `#[DecisionModel]`
parameter or have a matching `#[DecisionBoundary]` method. `DecisionModelAppendInterceptor::append()` then builds:

```php
$appendCondition = DecisionModelLoadedState::appendConditionCarriedBy($message)
    ->mergeWith($this->decisionBoundaryEvaluator->conditionFor($methodInvocation));
```

— the union of the injected models' captured tag versions and the boundary's. The returned events are appended
under it, with no aggregate metadata (§4.6), then published.

A `#[Fetch]`-injected aggregate contributes **nothing** to that union. It is not in
`DecisionModelBatchLoader`'s loader list (only `DecisionModelConverterBuilder` parameters are,
`DecisionModelModule::findModelLoaderDefinitionsFor()`), it has no criteria, and its version was discarded at
`FetchAggregateConverter.php:68`.

### 1.4 The gap, with a scenario the current guards do not catch

Take the coupon domain from §4.5b and give the customer a store credit balance held by an ordinary event-sourced
aggregate. `Customer`'s events are untagged — they are aggregate events, and §4.2 is explicit that untagged events
write nothing to either tag table.

```php
#[EventSourcingAggregate]
final class Customer
{
    use WithAggregateVersioning;

    #[Identifier] private string $customerId;
    private int $credit = 0;

    #[EventSourcingHandler] public function granted(CreditGranted $e): void { $this->credit += $e->amount; }
    #[EventSourcingHandler] public function spent(CreditSpent $e): void   { $this->credit -= $e->amount; }

    public function availableCredit(): int { return $this->credit; }
}

final readonly class OrderPlaced
{
    public function __construct(
        public string $orderId,
        #[EventTag('customer')] public string $customerId,
        #[EventTag('coupon')]   public ?string $couponCode,
        public int $amount,
    ) {}
}

final class Orders
{
    #[CommandHandler]
    public function place(
        PlaceOrder $command,
        #[Fetch('payload.customerId')] Customer $customer,
        ?CouponRedemptions $coupon,
    ): array {
        if ($coupon?->isExhausted()) {
            throw new CouponExhausted();
        }
        if ($customer->availableCredit() < $command->amount) {
            throw new NotEnoughCredit();
        }

        return [new OrderPlaced($command->orderId, $command->customerId, $command->couponCode, $command->amount)];
    }
}
```

`Customer c-1` has 100 credit. Anna and Ben each place a 60 order for `c-1`, with **different** coupons —
`SUMMER24` and `WINTER24`.

| | Anna | Ben |
|---|---|---|
| capture | `coupon:SUMMER24` = 3 | `coupon:WINTER24` = 0 |
| fetch `Customer c-1` | credit 100 | credit 100 |
| decide | 100 ≥ 60 → place | 100 ≥ 60 → place |
| guard | `UPDATE … coupon/SUMMER24 … version = 3` → 1 row | `INSERT … (coupon, WINTER24, 1)` → 1 row |
| | `INSERT … (customer, anna-c1…)` → fine | `INSERT … (customer, …)` → fine |
| append `OrderPlaced` | committed | committed |

120 of 100 credit is spent, and **no guard fired**: the two coupon counters are different rows, the `customer` tag
values differ (or, if both orders are for the same customer, that tag *would* catch it — see below), the events
carry no aggregate metadata so the unique index is never consulted, and the fetched `Customer` captured nothing.
The decision was made on state that was correct when read and wrong when written.

Two refinements, because they show the gap is not an artefact of the example:

*The `customer` tag is not a substitute.* Change the scenario so both orders are for `c-1` and the `customer` tag
does catch this pair — by accident. It catches it because both writers happen to *emit* an event tagged
`customer:c-1`. A handler that reads `Customer c-1` and emits something tagged only `coupon` (a `CouponRedeemed`
event, say) is back to no guard. The tag that protects a decision must be the tag the decision *read*, not a tag the
decision happened to write; that is the whole point of `AppendCondition`.

*One writer is enough.* Anna's handler fetches `Customer c-1` (credit 100). Concurrently, an ordinary
`SpendCredit` command runs against the `Customer` aggregate itself and commits, spending 80. The aggregate's unique
index protected *that* save perfectly. Anna wrote no `Customer` event, so nothing collided, and Anna's decision
went through on state that was 80 units stale. This is the plain lost-update, and it is invisible today.

The same scenario with `Customer` **state-stored** is worse, and for a different reason — see Part 3: no shipped
state-stored repository enforces `versionBeforeHandling` at all, so even two concurrent `SpendCredit` commands
against the aggregate itself are unguarded.

---

## Part 2 — The event-sourced aggregate inside the boundary

### 2.1 The two mechanisms that exist, and what each can guard

Under the settled constraints (optimistic only; no `FOR UPDATE`; no raised isolation; no snapshot tracking; the
store holds no state between executions) there are exactly two atomic check-and-write primitives in the system:

| Primitive | Where | Guards |
|---|---|---|
| unique index on `(aggregate_type, aggregate_id, aggregate_version)` | every stream table | the append of *an event of that aggregate at that version* |
| `UPDATE ecotone_tag_versions SET version = version + 1 WHERE tag_name = ? AND tag_value = ? AND version = ?` | one table per connection | **anything**, because the guarded row is independent of what is written |

Any proposal that guards "I read aggregate X, then wrote Y" must use the second one. That is not a preference; the
first primitive is structurally incapable of it (§1.2).

### 2.2 Option A — the aggregate's identity is a tag

Give each aggregate instance a counter row keyed by its identity. Two variants, differing only in whether the
aggregate's *events* also get index rows.

#### A1 — counter only (the aggregate keeps its own load)

- **Identity tag:** `tag_name = '_aggregate'` (reserved), `tag_value = '<aggregateType>:<aggregateId>'`, where
  `<aggregateType>` is what `AggregateTypeMapping` already resolves — `#[AggregateType('Order')]` when declared,
  the FQCN otherwise. The leading underscore matches the stream metadata convention (`_aggregate_id`); declaring
  `#[EventTag('_aggregate')]` in userland is a bootstrap `ConfigurationException`.
- **Never indexed.** No row in `ecotone_tagged_events`. The tag exists only to be counted.
- **Bumped by every save of that aggregate**, guarded on the version captured when it was loaded, or — for a save
  that loaded outside any boundary — on a version captured inside the same transaction, which is the rule §4.5
  already applies to every tagged append.
- **Captured before invocation**, in the decision-model batch, as one more `(tag_name, tag_value)` pair in the
  capture `SELECT` the batch loader already issues.
- **Read unchanged:** `EventSourcingRepository::findBy()` → `loadAggregateEvents()` on the aggregate's own columns,
  snapshots included.

#### A2 — counter *and* index rows ("fold it like a model")

As A1, plus: every aggregate event writes an `ecotone_tagged_events` row under its identity tag, so the aggregate's
events can be read by `loadByCriteria(EventCriteria::tag('_aggregate', 'Order:o-1'))` inside the same batched read
as the decision models. This is literally what the maintainer's question asks for — "through the same pre-invocation
batch, by criteria".

It costs:

| | A1 | A2 |
|---|---|---|
| index rows | none | **one per aggregate event, for every aggregate in the application** |
| storage (§4.2's own estimate, ~220 B/tag/event on PostgreSQL) | 0 | 10 M aggregate events ≈ 2.2 GB, on top of the events |
| backfill before it can be trusted | **none** (see §2.5) | a full `backfill-tags` pass over every stream, ever |
| snapshots | work as today | the batch read cannot start from a snapshot; the aggregate would load its whole history or keep a second load path |
| reads saved | 0 — the aggregate load is still one query either way | 1 round trip per handler, at most |
| order | trivial — one aggregate, one stream, `no` order | `tag_sequence`, exact but unnecessary |
| `#[EventTag]` on aggregate events | not needed | not needed either (the tag is synthetic), but §4.2's "only tagged events are indexed" invariant is gone |

A2 buys one saved round trip and costs a mandatory rewrite of the whole index plus the snapshot path. The
"by criteria" framing is attractive because it makes the aggregate look like a decision model, but an aggregate is
already loaded by an indexed query keyed on exactly the pair the criterion would name; routing it through a generic
tag index makes it slower and larger, not more uniform.

**A1 is the recommendation.** A2 is recorded here because the question asked for it, and because if per-tag
snapshots ever ship (§4.10 lists them as a follow-up) the calculus changes: an aggregate folded from the tag index
with a per-tag snapshot is genuinely the same machinery as a decision model, and A2 becomes a rename rather than a
new mechanism.

#### What the user writes (A1)

Nothing new:

```php
#[CommandHandler]
public function place(
    PlaceOrder $command,
    #[Fetch('payload.customerId')] Customer $customer,   // unchanged
    ?CouponRedemptions $coupon,                          // unchanged
): array
```

The rule is the one the maintainer already set for models — *whatever a handler injects, the framework keeps
consistent* (§4.4, revision 3) — extended one word: **whatever a DCB handler injects, including an aggregate.**
No new attribute, no marker on the aggregate class, no `consistent: true` flag.

"DCB handler" keeps its current meaning exactly (Part 4½ #6): a non-aggregate `#[CommandHandler]`/`#[EventHandler]`
that injects a `#[DecisionModel]` or declares a `#[DecisionBoundary]`. A `#[Fetch]` on any *other* handler behaves
precisely as it does today — same injection, same reply, no append, no capture, no transaction requirement. This is
what keeps the change non-breaking for the existing Enterprise `#[Fetch]` users; see Open Question 1 for the case
this leaves out (a handler whose only boundary participant is a fetched aggregate).

| Question | A1 answer |
|---|---|
| Loaded when | identity captured in the pre-invocation batch; the aggregate itself loaded by its parameter converter, as today — after the capture, which is the order §4.5 requires ("capture first, then read") |
| One load per handler | preserved for the models. The aggregate's own load is the load it already had; nothing is added |
| Guarded on append | `UPDATE ecotone_tag_versions … WHERE tag_name='_aggregate' AND tag_value='Customer:c-1' AND version=:captured`, in the same sorted pass as every other tag |
| On conflict | `DecisionModelConcurrencyException` (message names the aggregate, not `_aggregate:Customer:c-1` — see OQ 4). Nothing written |
| Retry | unchanged: `InstantRetryConfiguration` / `#[InstantRetry]`, outside the transaction (§4.5) |

### 2.3 Option B — merge the aggregate version into `AppendCondition::forAggregate()`

The brief's option B: capture the aggregate version at load and merge it into the existing aggregate expectation,
"with the returned events' target stream".

This does not work for a service handler, for the reason in §1.2: the aggregate expectation is enforced by the
unique index, and the index is only consulted for rows carrying that aggregate's `(type, id, version)`. A service
handler's returned events carry no aggregate metadata at all (§4.6), and giving them the fetched aggregate's
metadata would mean writing `OrderPlaced` **as a `Customer` event at `Customer`'s next version** — it would be
folded into `Customer` on the next load, delivered to `#[Partitioned]` projections of `Customer`, and counted in its
version sequence. That is not a consistency boundary, it is silently reassigning ownership of the event.

Two narrower readings of B are worth separating out, because both are real:

- **B-as-it-already-is.** For an aggregate's *own* command handler, B is shipped. `versionBeforeHandling` →
  `AppendCondition::forAggregate()` → the unique index. Nothing to add.
- **B-as-plumbing.** `AppendCondition` is still the right carrier for the new expectation — it is where a handler's
  boundary is assembled, and §4.9 already splits it into an open-core aggregate half and an Enterprise tag half.
  The recommendation in Part 4 puts the aggregate identity expectation *into* `AppendCondition`, derived from the
  aggregate part. So B's plumbing is adopted; only its enforcement mechanism is replaced by A's.

### 2.4 Option C — both

"Both" is the shipped state plus A1, and that is the recommendation. They are complementary, not redundant:

- the unique index guards **the aggregate's own event sequence** — two commands against `Order o-1` still collide
  on `(Order, o-1, 5)` exactly as today, with no counter involved;
- the identity counter guards **decisions taken on that aggregate by someone else** — a handler that read it and
  wrote elsewhere.

They can both fire on the same append (an aggregate command handler that also injects a model and whose aggregate
was itself read inside a boundary); the first to fail raises, nothing is written, and the retry re-reads.

### 2.5 The questions the brief asks about, answered for A1

**Multiple fetched aggregates in one handler.** Each contributes one `(_aggregate, Type:id)` expectation. They merge
into one `AppendCondition` keyed by `TagKey`, like everything else. The appender already sorts the union of
condition tags and event tags by `(tag_name, tag_value)` before touching any row, so two handlers that fetch the
same two aggregates in opposite parameter order cannot deadlock ABBA (§4.5, "one merged sorted pass").

**A handler that mutates the fetched aggregate.** `#[Fetch]` stays read-only — decided here, and it is the status
quo (§1.1), not a new restriction. It is also the only defensible answer: saving a fetched aggregate would mean the
handler has two write targets (its returned events and the aggregate's), and their atomicity story, version
stamping and event publication order are a separate design. What this proposal *adds* is that the silent case
becomes loud: an event-sourced aggregate injected by `#[Fetch]` whose `WithEvents` buffer is non-empty when the
handler returns is a runtime exception naming the aggregate, the events and the fix ("call a command handler on the
aggregate instead"). The fetched instances are already reachable at that point if they are carried in the
message header beside `DecisionModelLoadedState` (§4's rule: per-execution state is function-scoped, in a header).
The state-stored mutation case cannot be detected — there are no events — and gets a documentation paragraph
instead (§1.1).

**Aggregate events carrying several tags.** Unchanged. `AppendedTags` already unions the events' counted tags with
the condition's; the identity tag is one more member of that union, bumped in the same sorted pass, and it is the
*condition* side, so it never produces an index row.

**Cross-stream folding order (`tag_sequence`).** Not involved. The identity tag writes no index rows, so it has no
sequence and orders nothing. The aggregate's own events are in one stream and ordered by `no`, as today. (Under A2
they would carry an exact `tag_sequence`; another reason A2 is more machinery for no gain.)

**In-memory parity.** `InMemoryTagVersionRegister` already implements capture / `assertUnchanged` / `bump`, and
`EnterpriseInMemoryTagCollaborator::appendEventsWithTagCondition()` already runs the same protocol. The identity tag
is an ordinary key to both. The one thing to check in implementation: `EcotoneLite::bootstrapFlowTesting()` routes
event-sourced aggregates through `EventStoreEventSourcedRepository` over `InMemoryEventStore`
(`InMemoryRepositoryBuilder::compile()`, the `true` branch), so ES saves already reach the store and will bump.
`InMemoryStateStoredRepository` does not go through any store, which is why Part 3's hook point is the repository
adapter rather than the store.

**The aggregate does not exist yet.** `findBy()` returns `null`, the counter is 0, and the guarded path for a
captured 0 is `INSERT … ON CONFLICT DO NOTHING` — which conflicts exactly when someone else created that aggregate
in between. So "decide on the *absence* of an aggregate, then write" becomes safe, which it is not today. A
nullable parameter (`?Customer $customer`) still receives `null` and still contributes its expectation; a
non-nullable one throws `AggregateNotFoundException` as today, before any decision is made.

**Which aggregates pay.** Not all of them — see §4.7. Only aggregate classes that appear as an injected parameter of
a DCB handler somewhere in the application maintain an identity counter. That set is computable at bootstrap from
the same handler scan `DecisionModelModule` already performs.

---

## Part 3 — State-stored aggregates

### 3.1 What exists today, checked

The brief's option B for state-stored aggregates is "rely on the existing `#[Version]`". There is less there than
the name suggests. What I read:

- `StateStoredRepository::save(array $identifiers, object $aggregate, array $metadata, ?int $versionBeforeHandling)`
  passes the version to the repository. **No shipped repository uses the argument.**
  `DocumentStoreAggregateRepository::save()` upserts and ignores it. `ManagerRegistryRepository::save()` persists
  and flushes and ignores it. I checked the same signature in `packages/Laravel/src/EloquentRepository.php`,
  `packages/Tempest/src/TempestRepository.php` and `InMemoryStateStoredRepository` — same picture.
- `AggregateVersionMismatchException` exists in `packages/Ecotone/src/Modelling/` and is **thrown nowhere**
  (`grep` over `packages/`: one definition, zero throws).
- `StateStoredRepositoryAdapter::findBy()` hard-codes `versionBeforeHandling: null` (`:40-47`), so the load side does
  not even read it.
- `AggregateResolver::getVersionBeforeHandling()` returns `null` for a state-stored aggregate with an automatically
  increased version, with the comment *"Version could already been bumped up, we are unaware which version was used
  before handling"*.

So `#[Version]` on a state-stored aggregate is, in the framework, a property the framework increments and hands to
the repository. Enforcement is entirely the user's: a Doctrine `#[ORM\Version]` column, or nothing. In the default
`DocumentStoreAggregateRepository` setup it is nothing — two concurrent commands against the same state-stored
aggregate both read, both decide, both upsert, last write wins.

This matters beyond DCB: it means **option B for state-stored has nothing to build on**, and it means the mechanism
proposed below is not only a DCB feature — it is the first optimistic lock Ecotone would enforce for state-stored
aggregates at all.

### 3.2 Option A — a per-aggregate counter, bumped by the save adapter

Exactly A1 from Part 2, with a different hook point.

- **Same row, same table, same key:** `('_aggregate', '<aggregateType>:<aggregateId>')` in `ecotone_tag_versions`.
- **Captured** in the pre-invocation batch, identically.
- **Bumped** in `StateStoredRepositoryAdapter::save()` — *not* in `StateStoredRepository::save()`. This is the
  reason the repository interfaces do not have to change: the adapter is framework-owned and wraps **every**
  state-stored repository, including user-written ones (`StateStoredRepositoryAdapterBuilder::canHandle()` matches
  anything implementing the interface). The adapter has the `ResolvedAggregate`, so it has the type and the
  identifiers.
- **Ordering inside the adapter:** bump first, then delegate to the user's `save()`. Counters-first, for the three
  reasons §4.5 gives; here the third matters most — a loser must not have written the document or flushed the
  entity.

**Storage and connection.** `ecotone_tag_versions` lives on the event store's connection, in `PdoEventSourcing`,
under the Enterprise licence. A state-stored aggregate may live somewhere else. Three cases:

| Where the aggregate lives | Same connection as the tag tables? | Atomic? |
|---|---|---|
| `DocumentStoreAggregateRepository` (Dbal document store) | yes, the same `DbalConnectionFactory` | yes, under `DbalTransactionInterceptor` |
| `ManagerRegistryRepository` (Doctrine ORM) | yes, when the ORM is built from the same connection — the documented setup (`DbalConnection::createForManagerRegistry()`) | yes, same transaction |
| Eloquent, Tempest, a user repository over an HTTP API | not necessarily | **no** |

The third case is the `CrossConnectionDecisionModelGuard` situation exactly, and gets the same treatment: a
bootstrap `ConfigurationException` naming the aggregate, its repository and both connection references, rather than
a silent half-guard. Where the connection cannot be determined statically (a user repository is an opaque object),
the rule is documented and the runtime `TagTransactionRequirement` assertion still fires if no transaction is
active — which is the honest failure, not a wrong decision.

**What it guards.** Two things, and the second is a bonus:

1. A handler that fetched the aggregate and wrote events elsewhere — the question this document exists to answer.
2. **The aggregate's own command handlers.** Two concurrent `SpendCredit` commands against a state-stored
   `Customer` now capture the same counter, and one loses. That is the optimistic lock `#[Version]` promises and
   does not deliver today. It is available for free and I recommend taking it, but it is a behaviour change for
   existing state-stored users who enable DCB — previously-silent lost updates become `ConcurrencyException`s. See
   OQ 3.

### 3.3 Option B — expose `#[Version]` into `AppendCondition`

Concretely, this would mean: `StateStoredRepositoryAdapter::save()` guards on `#[Version]` instead of on a captured
counter — the counter row holds the aggregate's own version, and the guarded `UPDATE` uses
`WHERE version = :versionBeforeHandling`.

It is tempting because the two numbers move in lockstep (both start at 0, both +1 per save) and it saves the capture
`SELECT`. I recommend against it:

- It only works when the version is automatically increased. `isAggregateVersionAutomaticallyIncreased()` can be
  false, in which case the user owns the number and may not increment it by one, or at all.
- It does not work at all for an aggregate with **no** `#[Version]`, which is legal today — those would need the
  capture anyway, so the design would have two regimes with different failure modes for the same table.
- It makes the counter row mean two different things depending on the aggregate class, which breaks the one
  sentence that currently explains `ecotone_tag_versions` ("one counter per tag value, incremented by every append
  that carries it").
- `versionBeforeHandling` is `null` on exactly the state-stored path where it would be needed
  (`AggregateResolver::getVersionBeforeHandling()`, quoted above).

The capture is one more pair in a `SELECT` that is already being issued. The saving is not worth two regimes.

What *is* worth taking from B: when a state-stored aggregate does carry `#[Version]`, the conflict message should
quote it ("`Customer c-1` was at version 4 when this command read it") — diagnostics, not mechanism.

### 3.4 Option C — declare it out of scope

The honest form of this option is: *state-stored aggregates keep today's behaviour; a decision that depends on one
is documented as unguarded; use an event-sourced aggregate or a decision model if you need a boundary.*

It is defensible — DCB is an event-sourcing feature, and `ecotone_tag_versions` is an event-store table. I do not
recommend it, for three reasons:

1. The user-facing rule would be "a fetched aggregate is inside the boundary, unless it is state-stored, in which
   case it silently is not". Silent asymmetry is the failure mode this design refuses everywhere else.
2. The mechanism costs nothing extra. The register, the guard, the capture, the exception and the retry story are
   all already built; the only new thing is the call site in `StateStoredRepositoryAdapter::save()`.
3. It leaves `#[Version]` on a state-stored aggregate enforcing nothing, which is a standing surprise (§3.1)
   independent of DCB.

If it *is* chosen, the rule must be enforced, not documented: injecting a state-stored aggregate into a DCB handler
should be a bootstrap `ConfigurationException` naming the aggregate.

---

## Part 4 — Recommendation

One design, two hook points. **An aggregate's identity is a counted tag; the boundary is the tag counter; the
aggregate's own load and its unique index are untouched.**

### 4.1 The rule, in one paragraph

When Dynamic Consistency Boundary is enabled, every aggregate class that a DCB handler injects gets one row in
`ecotone_tag_versions`, keyed `('_aggregate', '<aggregateType>:<aggregateId>')`. Every save of such an aggregate —
event-sourced or state-stored, from a command handler or from a repository call — increments that row with the same
guarded `UPDATE` every tag uses. A DCB handler that injects that aggregate captures the row's version before the
aggregate is read, and the captured version joins the handler's `AppendCondition` next to its decision models'. If
anything changed the aggregate in between, the guard affects zero rows and the whole transaction is rolled back with
`DecisionModelConcurrencyException`, which the user's configured retry re-runs. The aggregate is still loaded the
way it is loaded today — its own columns, its own snapshots — and is still protected by its own unique index for its
own events.

### 4.2 Usage — event-sourced

```php
#[EventSourcingAggregate]
final class Customer
{
    use WithAggregateVersioning;

    #[Identifier] private string $customerId;
    private int $credit = 0;

    #[EventSourcingHandler] public function granted(CreditGranted $e): void { $this->credit += $e->amount; }
    #[EventSourcingHandler] public function spent(CreditSpent $e): void     { $this->credit -= $e->amount; }

    public function availableCredit(): int { return $this->credit; }
}

final class Orders
{
    #[CommandHandler]
    public function place(
        PlaceOrder $command,
        #[Fetch('payload.customerId')] Customer $customer,
        ?CouponRedemptions $coupon,
    ): array {
        if ($coupon?->isExhausted())                       { throw new CouponExhausted(); }
        if ($customer->availableCredit() < $command->amount) { throw new NotEnoughCredit(); }

        return [new OrderPlaced($command->orderId, $command->customerId, $command->couponCode, $command->amount)];
    }
}
```

Nothing in this snippet is new syntax. What changes is the guarantee: `Customer c-1`'s state is now part of the
condition under which `OrderPlaced` is appended.

### 4.3 Usage — state-stored

```php
#[Aggregate]
final class Wallet
{
    public function __construct(
        #[Identifier] private string $walletId,
        private int $balance,
    ) {}

    #[Version] private int $version = 0;

    public function balance(): int { return $this->balance; }
}

final class Payouts
{
    #[CommandHandler]
    public function payOut(
        RequestPayout $command,
        #[Fetch('payload.walletId')] Wallet $wallet,
        PayoutsToday $today,                       // #[DecisionModel], tag 'wallet'
    ): array {
        if ($wallet->balance() < $command->amount) { throw new InsufficientFunds(); }
        if ($today->total() + $command->amount > self::DAILY_LIMIT) { throw new DailyLimitExceeded(); }

        return [new PayoutRequested($command->walletId, $command->amount)];
    }
}
```

`Wallet` lives in the document store or the ORM. `PayoutsToday` folds tagged events. Both are in the same
`AppendCondition`; both are captured in the same `SELECT`; the whole thing is one transaction.

### 4.4 The rows, on a walkthrough

`Wallet w-1` has balance 100, and its counter is at 4 (four earlier saves). `PayoutsToday` for `wallet:w-1` is at
counter 7. Anna requests a 60 payout.

**a. capture** — one statement, both pairs:

```sql
SELECT tag_name, tag_value, version FROM ecotone_tag_versions
WHERE (tag_name = '_aggregate' AND tag_value = 'Wallet:w-1')
   OR (tag_name = 'wallet'     AND tag_value = 'w-1');
```

| tag_name | tag_value | version |
|---|---|---|
| _aggregate | Wallet:w-1 | 4 |
| wallet | w-1 | 7 |

**b. read** — the index query for `wallet:w-1` (the `_aggregate` pair matches nothing, by design), then the events;
then the parameter converter loads `Wallet w-1` from the document store.

**c. decide** — balance 100 ≥ 60, today's total under the limit → returns `PayoutRequested('w-1', 60)`, tagged
`#[EventTag('wallet')]`.

**d. guard**, one sorted pass over `{_aggregate:Wallet:w-1, wallet:w-1}`:

```sql
UPDATE ecotone_tag_versions SET version = version + 1
WHERE tag_name = '_aggregate' AND tag_value = 'Wallet:w-1' AND version = 4;   -- 1 row → 5
UPDATE ecotone_tag_versions SET version = version + 1
WHERE tag_name = 'wallet' AND tag_value = 'w-1' AND version = 7;              -- 1 row → 8
```

**e. events** — `INSERT INTO ecotone_event_stream …` for `PayoutRequested`, aggregate columns null.

**f. index** — one row for the `wallet` tag. **No row for `_aggregate`.**

| tag_name | tag_value | stream_name | event_no | tag_sequence |
|---|---|---|---|---|
| wallet | w-1 | ecotone_event_stream | 41 | 8 |

**g. COMMIT.**

`ecotone_tag_versions` afterwards: `_aggregate:Wallet:w-1` = **5**, `wallet:w-1` = **8**.

Now the competing save. A `CreditWallet` command against `Wallet w-1` runs in another transaction. It captures
`_aggregate:Wallet:w-1` = 4 at load, and `StateStoredRepositoryAdapter::save()` runs:

```sql
UPDATE ecotone_tag_versions SET version = version + 1
WHERE tag_name = '_aggregate' AND tag_value = 'Wallet:w-1' AND version = 4;
```

If it runs before Anna commits, it waits on her row lock and then finds 5 ≠ 4 → **0 rows** →
`DecisionModelConcurrencyException`, nothing written, retry. If it ran first, Anna's step d fails instead. Either
way, exactly one of the two decisions is taken on current state.

### 4.5 The concurrency, explained without a database

*Anna and Ben, one wallet.*

Anna asks for a 60 payout from wallet `w-1`. Ben, at the same moment, asks for a 60 payout from the same wallet. The
wallet holds 100.

Both requests read the wallet and see 100. Neither holds anything while it thinks — nothing is locked, and that
matters, because thinking is where the application code runs.

Before either of them writes, each notes one number: *"when I read this wallet, it was at change number 4."* That
number is the wallet's own ticker, and it goes up by one every single time the wallet changes, no matter who changed
it or why.

Now they write. Writing does two things in one indivisible step: *"move the wallet's ticker from 4 to 5 — but only
if it is still 4."*

Anna gets there first. The ticker was 4, so it moves to 5, and her payout is recorded.

Ben arrives a moment later. His step says "move it from 4 to 5, but only if it is still 4". It is 5. The step
changes nothing, and Ben's whole request is undone — no payout recorded, no ticker moved, nothing half-done. Ben's
command is simply run again from the start. This time it reads the wallet, sees 40, and refuses the payout with
*"insufficient funds"* — a business answer, which is what the user deserves, rather than a technical error or a
silent overdraft.

Two things make this safe rather than merely likely-to-work. First, the "check and change" is one step the database
performs; there is no moment between checking and changing for someone to slip through. Second, Ben noted the
wallet's number *before* he read it, not after — so if the wallet changed while he was reading, his number is the
old one and he loses, which is the safe direction to be wrong in.

Two payouts from *different* wallets never wait for each other: different wallets, different tickers.

### 4.6 How it composes with decision models in the same handler

One condition, one capture, one guard pass:

- `DecisionModelBatchLoader` already resolves each model's criteria from the message, ORs them, and calls
  `EventStore::loadByCriteria()`, which captures every criterion tag's version and returns it as an
  `AppendCondition` inside `LoadedEvents`. An injected aggregate adds **one more criterion branch**,
  `EventCriteria::tag('_aggregate', 'Wallet:w-1')`, whose value comes from the same `#[Fetch]` expression the
  aggregate converter will use. It matches no index rows (there are none), so it costs one extra pair in the
  capture `SELECT` and one extra pair in the index `IN` list, and reads nothing.

  *Checked:* `EventCriteria` needs no change for this; a tag with no index rows already yields no events, and
  `TagResolver::eventsMatching()` folds per-model criteria, so an aggregate branch is never folded into anything.
  `EventStore` needs no new method.
- The captured versions land in `DecisionModelLoadedState`'s `AppendCondition`, exactly as models' do, and
  `DecisionModelAppendInterceptor` passes it to `appendTo()`. For an aggregate command handler, they travel via
  `DecisionModelLoadedState::carryInto()` → `EventStoreEventSourcedRepository::save()` /
  `EventSourcingRepository::save()` → `mergeWith()`, which is the path already built.
- `AppendedTags` unions condition tags with event tags, so the identity tag is bumped guarded and produces no index
  row — **no change to `DbalTagConditionalAppender`, `AppendedTags` or `EventsTags` at all.** The one-load-per-handler
  guarantee is unaffected.
- On the save side, the ES repositories already build `AppendCondition::forAggregate(...)`. The proposal is that the
  aggregate part of `AppendCondition` *implies* its identity tag: `AppendCondition` exposes the identity tag derived
  from `aggregateType`/`aggregateId`, and `TagResolver::resolveAppend()` includes it among the involved tags. That
  way every event-sourced save bumps its own counter with no change to either repository, and the open-core path is
  unaffected because `StandardConsistencyBoundaryStrategy` never consults the tag half.

### 4.7 Which aggregates maintain a counter, and why not all of them

**Derived at bootstrap: an aggregate class maintains an identity counter if, anywhere in the application, it is an
injected parameter of a DCB handler.** Per class, not per handler — otherwise a save through a different route would
not bump, and a conditional writer could not see it (the ruby-dcb #42 bug §4.5 already guards against).

Not all aggregates, because the cost is not zero:

- one extra guarded `UPDATE` per save of that aggregate;
- one row per aggregate *instance* in `ecotone_tag_versions` (§4.2's estimate: ~100–200 MB per million);
- and — the one that actually bites — a tagged append **requires an active transaction**
  (`TagTransactionRequirement`). Making every aggregate save in a DCB-enabled application tagged would mean every
  aggregate save needs `withTransactionOnCommandBus(true)`, which is a real behaviour change for aggregates that
  have nothing to do with DCB.

The derived set keeps all three costs on the aggregates that are actually in a boundary. It is computable from the
handler scan `DecisionModelModule` already performs (and which readability-review item 6 wants unified into one
pass anyway).

The residual hazard is the same one §4.7 of the shipped spec already documents for tags: during the rollout of the
release that first injects `Customer` into a DCB handler, nodes running the previous release save `Customer`
without bumping. The operator rule is identical and one line long — *deploy the release that adds the injection to
every node before relying on the boundary* — and, unlike the tag index, **there is nothing to backfill** (§4.9).

Open Question 2 asks whether the maintainer would rather have this declared explicitly on
`DynamicConsistencyBoundaryConfiguration` than derived.

### 4.8 Collaborators that change — names only

Core (`packages/Ecotone`):

| Class | Change |
|---|---|
| `AggregateIdentityTag` *(new, `Ecotone\EventSourcing\Tagging`)* | `_aggregate` name, `'<type>:<id>'` value, and the reserved-name check |
| `AppendCondition` | derives the identity tag from its aggregate part; `expectedAggregateIdentityVersion()` |
| `TagResolver::resolveAppend()` | includes the condition's identity tag among the involved tags |
| `DecisionModelModule` | one more thing found by the handler scan: aggregate parameters of DCB handlers, and the set of aggregate classes that therefore count |
| `DecisionModelBatchLoader` | one criterion branch per injected aggregate; capture only |
| `FetchAggregateConverter` | records the fetched instance into the loaded-state header (for the recorded-events guard, §2.5) |
| `StateStoredRepositoryAdapter` | guarded bump before delegating to the user's `save()` |
| `AggregateConsistencyRegister` *(new interface, core)* | `capture()` / `guardedBump()`; implementations `DbalAggregateConsistencyRegister` (delegates to `DbalTagVersionRegister`), `InMemoryAggregateConsistencyRegister` (delegates to `InMemoryTagVersionRegister`), `OpenCoreAggregateConsistencyRegister` (does nothing, mirroring `OpenCoreDbalTagCollaborator`) |
| `CrossConnectionAggregateBoundaryGuard` *(new)* | bootstrap check for a state-stored aggregate whose repository is on another connection — the `CrossConnectionDecisionModelGuard` pattern |
| `DecisionModelConcurrencyException` | renders an identity-tag conflict as the aggregate, not as `_aggregate:Wallet:w-1` |

`PdoEventSourcing`: `DbalAggregateConsistencyRegister` only. **No schema change** — same two tables, same columns,
same keys, same setup feature. `EventStore`, `AggregateRepository`, `EventSourcedRepository` and
`StateStoredRepository` signatures are unchanged; this is the point of putting the state-stored bump in the adapter.

### 4.9 Licence, gating, migration

**Licence.** Nothing new. The identity tag is a tag, so it is the Enterprise half of `AppendCondition` (§4.9 of the
shipped spec). `AppendCondition::forAggregate()` stays open-core and keeps meaning exactly what it means today: with
DCB off, `StandardConsistencyBoundaryStrategy` never looks at the tag half and the append is byte-for-byte today's
single `INSERT`. `OpenCoreAggregateConsistencyRegister` no-ops, so a state-stored save in a non-DCB application is
also unchanged. Registered unconditionally through `LicenceDecider::prepareDefinition()`, per the no-nullable-services
rule.

**Gating.** `DynamicConsistencyBoundaryConfiguration` remains the single switch. No new extension object and no new
option (unless OQ 2 is answered "explicit", in which case one `with*` method on it).

**Migration and backfill: none.** This is worth stating as loudly as the spec states the opposite for the tag index.
A counter's *absolute value is meaningless*; only "did it move since I captured it" is used. A missing row is
version 0, and the guarded path for a captured 0 is `INSERT … ON CONFLICT DO NOTHING`, which conflicts exactly when
someone else inserted first. So an aggregate with ten years of history and no counter row behaves correctly from the
first decision taken on it:

| | reader | competing writer |
|---|---|---|
| capture | `_aggregate:Wallet:w-1` = 0 (no row) | — |
| save | — | no row → `INSERT (…, 1) ON CONFLICT DO NOTHING` → 1 row, version 1 |
| guard | `INSERT (…, 1) ON CONFLICT DO NOTHING` → **0 rows** → conflict | — |

`ecotone:event-store:backfill-tags` is not involved and does not need to know about `_aggregate`. The one operator
rule is the rolling-deploy window in §4.7.

**Upgrade guide.** One paragraph in the DCB section, after "Aggregate saves are guarded on their tags": that an
aggregate injected into a DCB handler is inside the boundary; that this is the first optimistic lock Ecotone
enforces for state-stored aggregates and existing silent lost updates become `ConcurrencyException`s (OQ 3); and
that `#[Fetch]` remains read-only.

### 4.10 Test plan — black box, four engines plus in-memory

Every test through `EcotoneLite` and userland APIs only; no reflection, no direct SQL against Ecotone tables.

*Flow tests (`packages/Ecotone/tests/Modelling/DecisionModel/`, `InMemoryEventStore`)*

1. A DCB handler fetching an event-sourced aggregate decides on its current state; a competing save of that
   aggregate, injected through a service called during the handler, makes the append fail with
   `DecisionModelConcurrencyException` — the existing `DecisionModelRetryTest` pattern.
2. The same for a state-stored aggregate.
3. Two concurrent commands against a state-stored aggregate: one succeeds, one raises. (New behaviour, §3.2.)
4. Two fetched aggregates in one handler; both expectations enforced; opposite parameter order in a second handler
   still commits (no deadlock, and the sorted pass is the reason).
5. A fetched aggregate that does not exist yet, then created concurrently → conflict.
6. `?Customer $customer` receiving `null` contributes nothing and the handler still appends.
7. A fetched event-sourced aggregate with recorded events raises the "fetched aggregates are read-only" exception.
8. A `#[Fetch]` handler that is *not* a DCB handler behaves exactly as today — return value goes to the reply, no
   append, no transaction requirement. (The non-breaking-change test.)
9. DCB disabled: 1–8 behave as they do on `main`.

*Dbal integration (`packages/PdoEventSourcing/tests/Integration/Tagging/`, PostgreSQL / MySQL / MariaDB / SQLite)*

10. The §4.4 walkthrough end to end, asserted through `EventStore` and the handler's outcome.
11. Two connections, the `DeduplicationModuleTest.php:141` pattern already used by `DbalTaggedContentionTest`: a
    second connection's aggregate save blocks on the identity row, then loses with
    `DecisionModelConcurrencyException`; and the mirror case where the blocker rolls back and the waiter proceeds.
12. InnoDB `REPEATABLE READ`: an aggregate loaded before a competing commit fails its save (the §4.5 walkthrough,
    applied to the identity tag).
13. Transaction required: a fetched-aggregate boundary with no active transaction raises the
    `TagTransactionRequirement` message naming the switch.
14. A state-stored aggregate whose repository is on another connection is a bootstrap `ConfigurationException`.
15. An aggregate with existing history and no counter row (the no-backfill proof, §4.9).

---

## Part 5 — Open questions

Only the ones the constraints do not settle. Each has my recommended answer.

**OQ 1 — a handler whose *only* boundary participant is a fetched aggregate.**
Under the recommendation, a fetched aggregate joins the boundary of a handler that is already a DCB handler.
"Read `Customer c-1`, append an untagged `CreditReserved`" has no model and no boundary method, so it is not a DCB
handler and gets no boundary. Making any `#[Fetch]` handler that returns an array append its return would be a
silent behaviour change for existing Enterprise `#[Fetch]` users who enable DCB (a returned array of DTOs would
become events), which I am not willing to propose.
*Recommended answer:* give `#[DecisionBoundary]` the vocabulary — `EventCriteria::aggregate(Customer::class, $id)`,
a leaf that captures the identity counter and matches no events. The handler declares the boundary method it
already can declare, becomes a DCB handler through the existing rule, and no new attribute or name is introduced.

**OQ 2 — derived set or declared set?**
§4.7 derives the counting aggregates from the handler scan. The alternative is
`DynamicConsistencyBoundaryConfiguration::withAggregateBoundaries([Customer::class, Wallet::class])`, which matches
the house style for `withFilterOnlyTags()` and makes the invariant impossible to change by accident when someone
adds a parameter.
*Recommended answer:* derive. The best property of §4.4 is that the type hint *is* the assembly; a second list that
can silently disagree with the handler signatures is a new way to be wrong. The accident the declared list guards
against (someone adds a fetched aggregate to a handler and changes an application-wide invariant) is exactly the
accident we *want* to happen automatically.

**OQ 3 — state-stored aggregates gain a real optimistic lock. Is that acceptable as a side effect?**
With DCB enabled and `Wallet` in the derived set, two concurrent commands against `Wallet w-1` now conflict where
today the last write silently wins. This is a correctness improvement, but it is a behaviour change for an
application that merely enabled DCB for an unrelated reason, and it surfaces as `ConcurrencyException` in handlers
that never saw one.
*Recommended answer:* accept it and document it up front, in the same paragraph as the retry advice — an unretried
conflict surfacing where a lost update used to happen is the trade the whole design makes. If it must be avoidable,
the escape is the same as for tags: keep that aggregate out of any DCB handler.

**OQ 4 — the reserved name and the value's length budget.**
`tag_name = '_aggregate'`, `tag_value = '<aggregateType>:<aggregateId>'`, and `tag_value` is `VARCHAR(255)` with PHP
validation. A default aggregate type is the FQCN, which in a deeply namespaced application plus a UUID can exceed
255 characters. Options: a bootstrap check that the aggregate type is at most 150 characters, pointing at
`#[AggregateType('Wallet')]` as the fix; or widening `tag_value` (the §4.2 key-size arithmetic leaves room —
400 characters would still fit InnoDB's 3072-byte limit — and 2.0 has not shipped, so the published DDL can still
change).
*Recommended answer:* the bootstrap check, and keep the column as decided. It fails loudly at bootstrap, it points
at an attribute that already exists and is already documented, and it keeps the `_<sha1>`-style unreadable-key
temptation out of the design. The conflict message quotes the aggregate class and identifier, never
`_aggregate:…`.

**OQ 5 — should the identity tag also be readable (A2), later?**
A1 writes no index rows, so `loadByCriteria(EventCriteria::tag('_aggregate', 'Wallet:w-1'))` returns nothing. If
per-tag snapshots ship (§4.10 of the shipped spec lists them), A2 becomes cheap and an aggregate becomes a decision
model in the literal sense.
*Recommended answer:* not now, and record the reason in the decision log so the question is not reopened from
scratch — the blocker is the mandatory full backfill and the snapshot path, not the mechanism.

**OQ 6 — does this change the answer to readability-review items 3–6?**
It interacts with all four. Item 6 ("name a DCB-participating handler, build the map once") becomes more valuable,
because this proposal adds a fifth thing the module must know per handler — do it in the same pass. Item 3 ("give
the nouns types") gains one more decorated form, the identity tag, and `AppendCondition` gaining
`expectedAggregateIdentityVersion()` is one more array-shaped accessor on the `Api/` class item 3 flags. Items 4 and
5 are untouched; the recommendation deliberately adds nothing to `AppendStrategy` or to the store↔collaborator
signatures.
*Recommended answer:* land item 6 first, then this; item 3 either before this or not for a while, but not
half-and-half.
