# review-dcb-readability — design-level readability proposals

Reviewed commit: `0548c413` on `dgafka/ecotone-2-0-dcb-design`, diffed against `dgafka/ecotone-2-0-work`.
Read: design spec Parts 4–8, `upgrade-2.0.md` DCB section, `launch-execution.md`, then the code in the order a
newcomer meets it (attributes → `EventStore` → modules → flows → collaborators → tests).
Scope: design-level only. No code written, nothing modified. Every constraint in the brief is taken as settled.

Question answered: *what else can we refactor to make the design more readable and obvious on what is happening,
where and why.*

---

## The one-line diagnosis

The **mechanism** is in good shape — each class does one thing and the orchestrating methods read as step lists.
What is still hard is **vocabulary and topology**: the same concept has three names depending on which file you are
in, the design's central nouns (a tag, a tag version, a sequenced tag, a DCB-participating handler) exist only as
array shapes and `"Class::method"` strings, the store and its tag collaborator depend on each other in both
directions, the same on/off decision is made in four modules, and the spec a reader is told to read first is
written in table and column names that no longer exist. Nine of the ten proposals below are about naming a concept
that already exists, or removing one of two places that decide the same thing.

---

## Ranked proposals

### 1. Re-express the design spec in the names that shipped — S — docs only

**The confusion.** `docs/superpowers/specs/2026-09-20-dcb-design.md` is the designated first read, and its entire
Part 4 — the schema in §4.2, all three SQL blocks in §4.5, and every table in the §4.5b coupon walkthrough — is
written in draft names: `ecotone_event_tags`, `ecotone_event_tag_versions`, `tag_key`, `tag_value`, `stream`,
`event_no`, `tag_version`. None of those exist in the code or in a database. The real names
(`ecotone_tagged_events`, `ecotone_tag_versions`, `tag_name`, `tag_sequence`, `stream_name`) appear once, in a
parenthetical under the DDL that says "the DDL above keeps the draft names for continuity with the worked
examples". So a reader who does what the brief says — spec first, then code — shares no vocabulary between the
two, and `upgrade-2.0.md` (which uses the correct names) disagrees with the spec on every table.

**Also stale in the same document:** §4.9 still lists `#[MatchingTags]` as Enterprise-licensed although §4.10
records it as removed; §4.4 still describes `loadByCriteria` reachable "the same way every other `EventStore`
method is" without mentioning `EventStore::RAW_REFERENCE`, which is what the framework's own callers use.

**Touches:** `docs/superpowers/specs/2026-09-20-dcb-design.md` §4.2, §4.5, §4.5a, §4.5b, §4.9.
**Risk to constraints:** none. Pure rename inside prose; the decided names are what it renames *to*.

---

### 2. Settle on two words for the two numbers, and use them everywhere — M — core + Dbal + spec

**The confusion.** Part 4½ #4 decided deliberately that the two numbers are different things: `version` is the
optimistic-lock counter on `ecotone_tag_versions`, `tag_sequence` is an order stamp on `ecotone_tagged_events`
("an order stamp, not a version"). The code then uses, for these two numbers:

- `version`, `expectedVersion`, `capturedVersion`, `versionsByTagKey`, `currentVersions`, `bumpGuarded`
- `sequence`, `sequencedBy`, `sequencedAfterBump`, `tag_sequence`
- `tagVersion` (`MatchedEvents::consider`, `inTagVersionOrder`), `seq` (`DbalTaggedEventReader` `$flags`)

`EventsTags::sequencedBy(array $versionsByTagKey)` takes *versions* and emits *sequences*; `MatchedEvents` orders
by something it calls `tagVersion` that is read from the column called `tag_sequence`. A reader tracing "why are
these events in this order" has to prove to themselves three times that these are the same value.

**The fix, at design level:** one word per concept, applied through the whole path — `version` only for the
counter and its expectation, `sequence` only for the order stamp — and rename `MatchedEvents`' field and
`DbalTaggedEventReader`'s `seq` accordingly. Fix the spec in the same pass (item 1).

**Touches:** `EventsTags`, `AppendedTags`, `MatchedEvents`, `DbalTaggedEventReader`, `DbalTagIndex`,
`DbalTagVersionRegister`, `InMemoryTagVersions`, `InMemoryTagIndex`, spec §4.5a.
**Risk:** none — no persisted name changes, the columns already carry the decided names.

---

### 3. Give the design's nouns types instead of array shapes — M/L — core + Dbal

**The confusion.** The central concepts of DCB are carried as untyped arrays with the shape written in a docblock:

| Concept | How it travels today |
|---|---|
| a tag | `array{name: string, value: string}` |
| a tag that may or may not be counted | `array{name, value, counted: bool}` |
| a tag with the version expected of it | `array{name, value, expectedVersion: int}` |
| a tag stamped with its order | `array{name, value, sequence: int}` |
| an index row | `array{stream, eventNo, has: array<string,bool>, seq: array<string,?int>}` |
| the identity of a tag | `TagKey::of()` — a static `$name . "\0" . $value` string |

Nine classes repeat these shapes in docblocks, and each one re-derives `TagKey::of(...)` to index them. `TagKey`
is the value object this design is missing, but it is currently a string factory, so nothing is ever a tag — it is
always a two-key array plus a string that happens to encode the same pair.

**The fix.** Make `TagKey` (or `Tag`) the value object carrying name+value, and give the three decorated forms
names — the brief's own words for them already exist in the spec: a *counted* tag, an *expected version*, a
*sequenced* tag. Signatures then say what they carry, the docblock repetition disappears, and the "counted vs
filter-only" rule stops being a boolean re-checked in several places.

**Touches:** `TagKey`, `TagResolver`, `EventsTags`, `AppendedTags`, `EventTagRegistry`, `AppendCondition`,
`DbalTagVersionRegister`, `DbalTagIndex`, `DbalTaggedEventReader`, `InMemoryTag*`.
**Risk:** touches `AppendCondition`'s public array-returning accessors (`expectedTagVersions()`), which is
`Api/` and open-core — a userland-visible signature. Worth doing, but it is the one item on this list that
changes a public shape, so it needs a deliberate call.

---

### 4. Collapse `AppendStrategy` into the tag collaborator — one gate, not two — M — core + Dbal

**The confusion.** "Is DCB on?" is answered twice on the same call, by two different mechanisms, both selected by
`DynamicConsistencyBoundaryServices::definitionFor` in the same module method:

```
EventStore::appendTo
  → AppendStrategy (Standard | DynamicConsistencyBoundary)   ← gate 1: Standard throws "DCB is disabled"
      → back into the store: appendEventsWithTagCondition
          → DbalTagCollaborator (OpenCore | Enterprise)      ← gate 2: OpenCore throws "DCB is disabled"
```

Both gates throw `DynamicConsistencyBoundaryDisabled::exception()`, with the same message. A reader who finds one
has no reason to suspect the other exists, and `DynamicConsistencyBoundaryStrategy` is an 18-line class whose
entire body is one delegation. The same is true in-memory (`StandardConsistencyBoundaryStrategy` +
`OpenCoreInMemoryTagCollaborator`).

**The fix.** Keep one seam. The tag collaborator is the better one to keep — it already has the OpenCore/Enterprise
pair, the licence split, and the real behaviour; the strategy adds a dispatch hop and an `AppendableStore`
interface that exists only to let the strategy call back into its caller. The aggregate-vs-unconditional branch in
`StandardConsistencyBoundaryStrategy` is three lines and belongs on the store's own `appendTo`.

**Touches:** `AppendStrategy`, `AppendableStore`, `DynamicConsistencyBoundaryStrategy`,
`StandardConsistencyBoundaryStrategy`, `DbalEventStore::appendTo`, `InMemoryEventStore::appendTo`,
`EventSourcingModule::registerAppendStrategy`, `EcotoneTestSupportModule::registerAppendStrategy`, spec §4.9.
**Risk:** §4.9 names `AppendStrategy` explicitly as the runtime licence line, so this is a spec change as well as a
code change. The guarantee it protects (a hand-built tag-bearing `AppendCondition` throws at runtime when DCB is
off) is preserved by the collaborator gate, which already implements it. Names decided by the maintainer
(`DynamicConsistencyBoundaryStrategy` / `StandardConsistencyBoundaryStrategy`) would disappear — flag before doing.

---

### 5. Stop passing the store into its own collaborators — M — Dbal + core

**The confusion.** `DbalTagCollaborator`'s three real methods take the store back as their first argument:

```php
backfillTagsForStream(DbalEventStore $eventStore, Connection $connection, EventStreamSchema $schema,
    string $tableName, string $streamName, ?string $onlyEventName, ?int $fromNo, int $batchSize,
    bool $dryRun, bool $skipUndeserializable, TagBackfillReport $report): void
```

Eleven parameters, four of which (`$connection`, `$schema`, `$tableName`, `$streamName`) the store just derived and
could derive again, and the store itself — which the collaborator then calls back into for `rowsToAppend`,
`insertEventRows`, `loadEventsByNumbers`, `loadRowBatch`, `convertToEvent`, `tagTableContextKeyFor`,
`isAutomaticTableInitializationEnabled`, `consoleInvocationPrefix`. This is not a layering; store and collaborator
depend on each other, and eight `DbalEventStore` methods are public only to serve the callback. The in-memory side
does the same (`InMemoryTagCollaborator` takes `InMemoryEventStore`).

**The fix.** Name the thing being passed. One small value — a *stream write context* (connection, schema, table
name, stream name) — replaces four parameters and makes the signature readable; the handful of store operations the
tag side genuinely needs becomes one narrow collaborator it is *given*, rather than the whole store. The `$report`
out-parameter threaded console → store → collaborator → backfiller (item 10) goes the same way.

**Touches:** `DbalTagCollaborator` + both implementations, `DbalTagConditionalAppender`, `DbalTaggedEventReader`,
`DbalTagBackfiller`, `DbalTagIndex`, `DbalTagTables`, `DbalEventStore` (eight methods become private again),
`InMemoryTagCollaborator` + implementations.
**Risk:** none to the listed constraints. Largest single item here; splits cleanly into Dbal-then-in-memory.

---

### 6. Name "a handler that takes part in DCB", and build the map once — M — core

**The confusion.** `DecisionModelModule` walks every `#[CommandHandler]`/`#[EventHandler]`/`#[QueryHandler]` in the
application **four separate times**, in four private static finders, producing four parallel maps a reader must
hold simultaneously:

- `$rawDefinitions` — keyed by model class
- `$loaderDefinitionsByHandler` — keyed by `"Class::method"`
- `$appendEligibleMethods` — a list of `['class' => …, 'method' => …]`
- `$decisionBoundaryMethods` — keyed by `"Class::method"`, value the boundary method name

The `"Class::method"` string is the de facto identity of a DCB-participating handler and it is rebuilt by hand in
five places, including at runtime inside `DecisionModelAppendInterceptor::mergeDecisionBoundaryCondition`. The
concept behind all four maps — *this handler injects models and/or declares a boundary, so its returned events are
appended under a condition* — has no name and no object. "Append eligible" is the nearest, and it is defined by
what it excludes (aggregates), not by what it is.

**Also invisible here:** `DecisionModelModule::create` builds a **second** `EventTagRegistry`
(`EventTagRegistry::createWith(EventTagRegistryBuilder::buildRawDefinitions(...))`) — deliberately, because it runs
in `create()` before extension objects are available, but **without the filter-only tag names**. Nothing says so,
and it is why the rule promised by spec §4.3 and `upgrade-2.0.md:533` — *"A decision model scoped by a filter-only
tag name is a bootstrap `ConfigurationException`"* — **is not implemented anywhere** (no caller of
`EventTagRegistry::isFilterOnly` outside `TagResolver`). Either implement the guard or drop the promise from both
documents.

**The fix.** One scan producing one collection of named "decision-model handler" objects (class, method, loaders,
boundary method, appends-its-result), which the module then iterates once to register. The runtime interceptor is
given that object, not a string-keyed array.

**Touches:** `DecisionModelModule` (371 lines, would shrink substantially), `DecisionModelAppendInterceptor`,
`DecisionModelConverterBuilder::compileLoader`.
**Risk:** none. Behaviour-preserving; the filter-only guard is a separate, small decision.

---

### 7. One name and one hop for the append condition on an aggregate save — S/M — core

**The confusion.** The `AppendCondition` produced by the decision-model load reaches an aggregate save through two
transports with two different key constants, and is unpacked by the public API value object itself:

```
DecisionModelBatchLoader (before interceptor)
  → header  DecisionModelLoadedState::HEADER_NAME
      → SaveAggregateService re-packs it into save metadata under
        AggregateMessage::DECISION_MODEL_APPEND_CONDITION
          → EventStoreEventSourcedRepository / EventSourcingRepository call
            AppendCondition::forAggregateFromSaveMetadata(...)
              → which reads AggregateMessage::DECISION_MODEL_APPEND_CONDITION itself
```

So `packages/Ecotone/Api/EventSourcing/AppendCondition.php` — an `Api/`, open-core, userland-visible value object —
imports `Ecotone\Modelling\AggregateMessage` and knows a message-header constant. A reader looking for "where does
the aggregate save pick up the decision model's condition" has to find three files and two constants, and the
answer lives in the least likely of them.

**The fix.** Keep one name for the value in transit, and put the unpacking where the packing is (the save flow),
so `AppendCondition` stays a value object that knows nothing about messages. `MessageHeaders::unsetNonUserKeys`
already strips both keys, which is the other half of the story a reader currently has to discover separately.

**Touches:** `AppendCondition::forAggregateFromSaveMetadata`, `AggregateMessage::DECISION_MODEL_APPEND_CONDITION`,
`SaveAggregateService`, `EventStoreEventSourcedRepository`, `EventSourcingRepository`, `DecisionModelLoadedState`.
**Risk:** `forAggregateFromSaveMetadata` is public on an `Api/` class — check nothing userland is expected to call
it (it reads as internal). Constraint "the repository interface does not change" (§4.4) is preserved.

---

### 8. Give `#[DecisionBoundary]` a home — S — core

**The confusion.** The escape hatch is a first-class part of the public surface — its own attribute, its own spec
section, its own test file — but it has no class. It is discovered in
`DecisionModelModule::findDecisionBoundaryMethods` (matching handlers to boundary methods by first-parameter type
hint, a rule stated nowhere else) and **executed** inside `DecisionModelAppendInterceptor`'s private
`mergeDecisionBoundaryCondition`, which calls the user's static method and issues a **second, unbatched**
`loadByCriteria` — the one place the "one load per handler" guarantee of §4.4 does not hold. A reader asking
"what does `#[DecisionBoundary]` do, and what does it cost" will not look in the append interceptor for it.

**The fix.** One small named collaborator owning discovery + evaluation of the boundary, used by the interceptor;
and a line in the docs stating plainly that a boundary costs an extra read (or that it is folded into the batch
load, if that is preferred).

**Touches:** `DecisionModelModule::findDecisionBoundaryMethods`, `DecisionModelAppendInterceptor`,
`upgrade-2.0.md` DCB section, spec §4.4.
**Risk:** none.

---

### 9. Decide DCB is on in one place, and register it in one place — S/M — core + Dbal

**The confusion.** Four modules independently ask whether the extension object is present:

| Module | Asks | And then |
|---|---|---|
| `EventTaggingModule` | `contains(DynamicConsistencyBoundaryConfiguration)` | throws `LicensingException` |
| `DecisionModelModule` | `! contains(...)` + `usesDecisionModels()` | throws the disabled exception |
| Pdo `EventSourcingModule` | `contains(...)` | picks 3 service pairs |
| `EcotoneTestSupportModule` | `contains(...)` | picks 2 of the same 3 pairs |

`registerAppendStrategy` and `registerInMemoryTagCollaborator` are **near-verbatim duplicates** between
`EventSourcingModule` and `EcotoneTestSupportModule`. A reader asking "what does registering
`DynamicConsistencyBoundaryConfiguration` actually turn on?" gets four partial answers and has to union them.

**Worse for a user:** `DynamicConsistencyBoundaryConfiguration` has **zero options** — it is a marker — while the
one documented DCB option, `withFilterOnlyTags()`, lives on a *different* extension object
(`EventSourcingConfiguration` / `BaseEventSourcingConfiguration`). So enabling DCB and configuring DCB happen on
two unrelated objects, and the one named after DCB is the one you cannot configure.

**The fix.** One place that resolves the flag and registers the DCB service set (both stores' collaborators and,
if item 4 lands, nothing else), consumed by the modules that need it; and move `withFilterOnlyTags` onto
`DynamicConsistencyBoundaryConfiguration` where a reader will look for it.

**Touches:** `DynamicConsistencyBoundaryServices`, `EventSourcingModule`, `EcotoneTestSupportModule`,
`EventTaggingModule`, `DecisionModelModule`, `BaseEventSourcingConfiguration`,
`DynamicConsistencyBoundaryConfiguration`, `upgrade-2.0.md` "Enabling", spec §4.3/§4.9.
**Risk:** moving `withFilterOnlyTags` is a userland-visible move on a 2.0 branch — cheap now, not later. Constraint
"DCB is opt-in via `DynamicConsistencyBoundaryConfiguration`" is strengthened, not reversed.

---

### 10. Name the in-memory store as the mirror it is — S — core

**The confusion.** The whole flow-testing promise of §4.4 — *"`InMemoryEventStore` implements the full contract …
so flow tests exercise real conditional-append semantics without a database"* — rests on the two implementations
being semantically identical. The names make that impossible to check by reading:

| Role | DBAL | In-memory |
|---|---|---|
| Enterprise implementation | `EnterpriseDbalTagCollaborator` | `InMemoryTagConditionalStore` |
| open-core implementation | `OpenCoreDbalTagCollaborator` | `OpenCoreInMemoryTagCollaborator` |
| counters | `DbalTagVersionRegister` | `InMemoryTagVersions` |
| index | `DbalTagIndex` | `InMemoryTagIndex` |
| append | `DbalTagConditionalAppender` | *(inline)* |
| read | `DbalTaggedEventReader` | *(inline)* |
| backfill | `DbalTagBackfiller` | *(absent — by design)* |

One side's Enterprise class is named `Enterprise*`, the other's is named `*ConditionalStore`; one side's counter
table is a `Register`, the other's is `Versions`. Renaming the in-memory pair to mirror the DBAL pair makes the
correspondence checkable at a glance, and makes the two genuine asymmetries visible instead of hidden: the
in-memory side *holds* the tag state (so its collaborator is stateful where the DBAL one cannot be — the database
holds it), and it has no backfill.

**Touches:** `InMemoryTagConditionalStore`, `InMemoryTagVersions`, `InMemoryTagCollaborator`,
`EcotoneTestSupportModule`, `EventSourcingModule`.
**Risk:** none. Internal names only.

---

## Also noticed — small, grouped

- **The setup feature is the last draft name.** `TagTableManager::FEATURE_NAME = 'event_tags'` while the tables are
  `ecotone_tagged_events` / `ecotone_tag_versions` and every class is `Tag*` / `Tagged*`. A user typing
  `ecotone:migration:database:setup` sees a feature named after nothing else in the system. (S)
- **`ecotone:event-store:verify-schema` over-promises.** The class is `TagVerifySchemaConsoleCommand` and it checks
  only the tag tables (plus legacy NOT NULL relaxation). The command name reads as "verify the event store's
  schema". (S)
- **`EventStore::RAW_REFERENCE` states the what, never the why.** Its docblock says it bypasses the gateway;
  nothing says *why* the DCB interceptors must use it (staying inside the caller's transaction / not re-entering
  the bus). Three call sites, no explanation at any of them, and nothing in the spec. (S)
- **`EventCriteria` is quietly two shapes.** A leaf carries tags + types; `or()` returns a node with empty tags and
  types plus `branches`. `->or($x)->andTag(...)` therefore drops silently, and `tags()` on an OR node lies.
  `DbalTaggedEventReader` and `TagResolver` both defend against this by iterating `branches()` first. Worth making
  the two shapes explicit or making the composition total. (S/M — userland-visible `Api/` class.)
- **`DecisionModelBatchLoader` does not read as an interceptor.** Its `load(Message): array` returns a
  *header map*, and the fact that it is registered as a before-interceptor with `changeHeaders` lives only in
  `DecisionModelModule`. From the class alone a reader cannot tell when it runs or what consumes its return. (S)
- **`DecisionModelLoadedState::instanceCarriedBy` throws `ConfigurationException` at runtime** for a missing
  header — a bootstrap-category exception raised on the message path. (S)
- **`DecisionModelStreamResolver` does raw reflection per append** and guards with `class_exists(Stream::class)`
  because core cannot depend on `PdoEventSourcing`. That cross-package boundary is real and load-bearing, and
  `class_exists` is the only place it is expressed. (S/M)
- **Two answers to "what is tag `X` worth on this object".** `EventTagRegistry` + `EventTagValueSource` resolve
  tags *on events*; `MessageTagValueResolver` (different package, static, its own reflection, its own
  `name`/`nameId`/`name_id` convention) resolves them *on commands*. Both are `#[EventTag]`-driven. Nothing says
  they are deliberately different rules. (M)

---

## What is already clear — leave it alone

- **The user-facing surface.** `#[EventTag]`, `#[DecisionModel(tags:)]`, `#[DecisionBoundary]`, `EventCriteria`,
  `AppendCondition`, `LoadedEvents`. Small, attribute-first, and the type hint *is* the assembly — a handler
  signature reads as its own consistency boundary. This is the best part of the design and nothing below should
  disturb it.
- **`EventsTags` / `AppendedTags` / `MatchedEvents` as concepts.** The decision to give "the tags these events
  carry", "the tags this append involves" and "the events that matched" their own classes is exactly right; only
  their *contents* (item 3) and one method name (item 2) need work. `AppendedTags` in particular makes the
  merge rule (explicit condition wins over captured) readable in one method.
- **The orchestrating methods.** `DbalTagConditionalAppender::appendEventsWithTagCondition`,
  `DbalTaggedEventReader::loadByCriteria` and `DecisionModelBatchLoader::load` each read as a short list of named
  steps with no inline SQL. The cohesion pass achieved what it set out to.
- **The CQS split.** Reads no longer run DDL, bumps return `void`, `captureTagVersions` is pure. That is visible
  from the signatures now and should stay that way.
- **`TagTransactionRequirement`.** Two static asserts whose exception messages name the exact configuration switch
  to flip. This is the model for how the rest of the DCB errors should read.
- **The tests as documentation.** `CouponAggregateWalkthroughTest` / `…DbalTest` and `ThreeModelCourseExampleTest`
  are the spec's own worked examples, executable — the fastest way in for a new reader. Directory naming
  (`Tagging/`, `DecisionModel/`) matches the source tree.
- **`upgrade-2.0.md`'s DCB section.** Correct names, the release-order table, the "read this before using decision
  models" retry paragraph up front, and the transactions-required switches. It is the document that currently
  explains the system best — which is the argument for item 1.

---

## Suggested order

1 and 2 first (docs + one vocabulary, cheap, and they make every later diff readable), then 9, 7, 8, 10 (small,
independent), then 6, then 4, then 5 (largest), with 3 taken as a deliberate call because it touches `Api/`.