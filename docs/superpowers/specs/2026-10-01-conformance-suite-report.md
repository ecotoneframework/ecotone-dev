# Rule 9 conformance suite — what shipped, what it found, and the two seams it cannot reach yet

Rule 9 says a feature needing external storage ships an in-memory implementation, **and one shared suite proves the
two behave identically**. The second half had never existed. This unit built it for the two seams that already have
two implementations — `EventStore` and `DocumentStore` — and it found more divergence than anyone had assumed.

## 1. Findings, most serious first

### T1 — `bootstrapFlowTestingWithEventStore()` indexes no `#[EventTag]`: every DCB flow test on it is vacuous

`EventSourcingConfiguration::createInMemory()` is what `EcotoneLite::bootstrapFlowTestingWithEventStore()` installs
by default, and `EventSourcingModule` wraps that `InMemoryEventStore` in `SerializingEventStore`. The store therefore
receives **serialized** events: `TagResolver::tagsCarriedBy()`
(`packages/Ecotone/src/EventSourcing/Tagging/TagResolver.php:181`) returns `[]` for a non-object payload, so no tag is
ever indexed. `loadByCriteria()` returns nothing, and no append condition can ever conflict: a flow test asserting
that a stale decision is rejected passes, because nothing was indexed, not because the guard works.

The same `InMemoryEventStore` passes all 15 tagged cases when `EcotoneLite::bootstrapFlowTesting()` wires it
**without** the event-sourcing module (`packages/Ecotone/src/Lite/Test/Configuration/EcotoneTestSupportModule.php:443`
registers it unwrapped). That is why the core DCB tests pass, and it shows the in-memory tag logic matches DBAL:
**T1 is purely wiring**. It is a plain bug with no design question in it, and it needs its own unit.

**Closed by the T1 unit** (`3db06fc57`): `InMemoryEventStore` now takes the application's events, as `DbalEventStore`
does, resolves their tags, and converts them to its recorded form only when it records them, through a
`RecordedEventFormat` — `AsGivenRecordedEventFormat` without the event-sourcing module, `SerializedRecordedEventFormat`
with it. What it records is unchanged; the wrapper keeps only the read side and is now `DeserializingEventStore`. T1
is gone from `KNOWN_DIVERGENCES`, and its 13 cases pass on the `in-memory` row on all four engines.
`DecisionModelOnInMemoryEventStoreTest` proves it through a `#[DecisionModel]` handler on the default bootstrap.
Converting at record time moved a converter's refusal after the tag version bump, so a refused append left the tag
moved; the in-memory tag collaborator now records before it bumps, as DBAL converts its rows before it bumps
(`8da3eb43d`).

Two corrections to the finding as written above. *No append condition can ever conflict* was too strong: the
in-memory tag version register bumps the tags an append condition names even when its events resolve none, so two
decisions on the same tag did conflict. What stayed vacuous was every tagged load — so every decision model was
rebuilt from nothing — and any conflict with a tagged event appended without a condition. And no DCB test in this
repository was vacuous: every one on `bootstrapFlowTestingWithEventStore()` boots DBAL, so the exposure was to
applications' own flow tests and to the conformance row. The design's tag carrier through `SerializingEventStore`
(`2026-09-20-dcb-design.md`) was never built; it is not needed now that both stores resolve tags from the event they
are given.

### E10 — DBAL never checks `AppendCondition::forAggregate()`'s expected version (rule 8)

`DbalEventStore::appendEventsWithAggregateCondition()` appends unconditionally. It relies on the unique index on
aggregate type, id and version, so a stale expectation whose new event carries a version **not yet recorded** is
appended. In-memory rejects it. This is the hole rule 8 is sequenced to close, found before the fix was written:
`EventStoreConformanceTest::test_appending_under_a_stale_aggregate_version_is_rejected` is the failing case the rule 8
unit inherits, recorded under `dbal` in `KNOWN_DIVERGENCES`.

**Closed by the rule 8 unit** (`0f47c62f6`): DBAL now reads the aggregate's current version first, on the plain and
the tagged path; `dbal` is removed from E10, and the entry is gone. The rule 8 record in
`docs/conventions-outstanding.md` lists what that unit shipped and the four limits of rule 8 it found.

### D5 — the DBAL document store cannot read back an array mixing value types

`['product' => 'milk', 'quantity' => 2]` is stored with document type `array<string,mixed>`, and reading it back
through JMS throws `ReflectionException: Class "mixed" does not exist` (`DbalDocumentStore.php:300`). In-memory
returns it. User-facing, DBAL-only, all four engines.

**Closed by the storage-defects unit.** The recorded type was the narrower half of the defect: it is sampled from an
array's first and last values only (`Type::createFromVariable()`), so `['product' => 'milk', 'quantity' => 2,
'lines' => ['a', 'b'], 'size' => 'large']` is recorded as `array<string,string>` and could not be read back either —
a new case, `test_an_array_document_keeps_the_type_of_every_value_not_only_of_its_first_and_last`, was red on DBAL
before the fix. `DbalDocumentStore` now reads a recorded type that names no class — `array<string,mixed>`,
`array<string,string>`, any nesting of those — as a plain `array`, which JMS decodes exactly; a type naming a class,
`array<Order>` or `array<array<Order>>`, is read as before, pinned by
`test_an_array_document_of_objects_is_returned_as_objects`. The write path and the stored `document_type` are
unchanged, so rows written before the fix read back too. D5 is gone from `KNOWN_DIVERGENCES`, and the D5 case now
also reads through `findDocument()` and `getAllDocuments()` and compares with `assertSame`, so a value cast to
another type fails it. Green on PostgreSQL, MySQL, MariaDB and SQLite.

### D4 — MySQL and MariaDB treat document ids differing only in case as the same document

The primary key's collation is case-insensitive, so `addDocument('orders', 'ORDER-A', …)` after `'order-a'` fails
with a duplicate-key `DocumentException`. In-memory, PostgreSQL and SQLite keep two documents. User-facing.

**Decided by the storage-defects unit: a documented engine limitation, kept in `KNOWN_DIVERGENCES`.** Measured on
MySQL 8.0.46 and MariaDB 11.4.12 before choosing:

- The collation is not Ecotone's. `DocumentStoreTableManager` sets none, so the key inherits the database default:
  `utf8mb4_0900_ai_ci` and `utf8mb4_uca1400_ai_ci`. Both ignore accents as well as case (`cafe` / `café` collide),
  and MariaDB's also ignores trailing spaces (`a` / `a `). A database created with a binary default already behaves
  like PostgreSQL
- The divergence cuts both ways. On today's tables `findDocument('orders', 'ORDER-A')` returns the document stored as
  `order-a`, so an application may depend on the case-insensitive lookup without knowing it
- Converting the key to `utf8mb4_bin` is refused `ALGORITHM=INPLACE` on both engines ("Cannot change column type"):
  it is a full table copy that blocks writes. After it, ids differing in case, accent or collection name are
  distinct, proved by a throwaway test through `DocumentStore` on both engines (not committed)

The options and their cost:

1. **Binary collation for new tables, an `ALTER` for existing ones.** New installations match the other engines. An
   existing table keeps colliding until someone runs the copy, so one application behaves differently by table age,
   and the conformance case — which always runs on a fresh table — would go green while production does not. Once
   altered, every lookup that relied on case or accent folding stops finding its document, silently. Doing it safely
   needs a collation check on the table, as `ecotone:event-store:verify-schema` does for the tag tables, and an
   upgrade step that rewrites users' tables
2. **Normalising ids** (lower-casing them on every engine). Changes behaviour on PostgreSQL, SQLite and in-memory
   too, needs a data migration on every engine for ids already stored, and still leaves accents folded on MySQL.
   Rejected
3. **Document the constraint, keep the divergence recorded with the engine named.** Nothing changes for an existing
   application; one that needs distinct ids runs the `ALTER` itself, knowing what it costs. Chosen

`upgrade-2.0.md` now states the constraint, the measured collations, and the opt-in statement with its cost; D4's
`sides` names the collations and the decision. **If the maintainer wants option 1**, the cheapest moment is before 2.0
ships: every 1.x table must already run an `ALTER` for the `version` column, and no 2.0 table exists yet, so the
collation could ride the same upgrade step — the lookup change would still need saying, and existing tables still
need detecting. For reference, the event stream and tag tables already create their id columns `utf8mb4_bin`.

### The remaining divergences

The suite records each of these as a skipped case with its id. Which side is right is a maintainer decision; each
test body states one proposed contract, so the chosen side may mean rewriting the body, not only deleting the entry.

| Id | Case | In-memory | DBAL |
|---|---|---|---|
| E1 | `load(count: 0)` | refuses (`count must be >= 1 or null`) | returns `[]` |
| E2 | `create()` on an existing stream | refuses (`Stream … already exists`) | no-op, appends the events |
| E3 | metadata match `NOT_IN []` | drops events lacking the key | keeps them (`1 = 1`) |
| E4 | metadata `'2'` against a recorded integer `2` | no match (strict) | match on PostgreSQL, MySQL and MariaDB; **no match on SQLite** |
| E5 | `loadAggregateEvents(…, '42')` for an id recorded as integer `42` | not found | found on PostgreSQL, MySQL and MariaDB; **not found on SQLite** |
| E6 | `load()` of a never-created stream | `[]` | `ConfigurationException` with the missing-table instructions |
| E7 | `appendTo(stream, [])` on a missing stream | creates the stream | no-op |
| E8 | unconditional append repeating an aggregate type, id and version | stores the duplicate | `ConcurrencyException`, whole batch rejected |
| E9 | append repeating an event id | stores the duplicate | `ConcurrencyException` (`UNIQUE (event_id)`) |
| E11 | `create()` / `appendTo()` on a stream nothing declares | creates it | MySQL and MariaDB refuse — rule 16 by design; PostgreSQL and SQLite create it |
| E13 | `load(deserialize: false)` | the unwrapped store (`bootstrapFlowTesting()`) returns payload objects | recorded data, as `createInMemory()` does |
| D1 | a document string that is not JSON | refused on add, **accepted on update** | refused on both by PostgreSQL, MySQL, MariaDB; **accepted on both by SQLite** |
| D3 | change an object after `addDocument()` | the stored document changes — it is held by reference | unchanged — a copy was stored |

E1 to E9 hold for both in-memory wirings, because they share `InMemoryEventStore`.

### Unspecified or not a divergence — kept out of the registry

- **`getAllDocuments()` order** — the DBAL query has no `ORDER BY`. In practice every engine returns primary-key
  order and in-memory returns insertion order, but neither is promised, so the case asserts content only
- **MySQL JSON normalisation** — MySQL returns event payload and document keys reordered, and JSON string documents
  re-spaced. The values are equal, so the cases compare JSON values, not bytes
- **An array document with no JSON converter registered** — DBAL raises a `ConversionException`, in-memory stores
  anything. This is configuration, not the store; the suite registers JMS, as an application would

### Noticed on the way, not in scope

Rule 12a: `EventStore` and `DocumentStore` are application-called, and their signatures and exceptions reach outside
`Api/` — `Ecotone\Modelling\Event`, `Ecotone\EventSourcing\EventStore\MetadataMatcher`, `Operator`, `FieldType`,
`Ecotone\Messaging\Support\ConcurrencyException`, `Ecotone\Messaging\Store\Document\DocumentException` and
`DocumentNotFound`.

## 2. What shipped

| Suite | Cases | Rows |
|---|---|---|
| `packages/PdoEventSourcing/tests/Conformance/EventStoreConformanceTest.php` | 36 | `in-memory`, `in-memory-without-event-sourcing-module`, `dbal` |
| `packages/PdoEventSourcing/tests/Conformance/TaggedEventStoreConformanceTest.php` (Enterprise) | 15 | the same three |
| `packages/Dbal/tests/Conformance/DocumentStoreConformanceTest.php` | 19 | `in-memory`, `dbal` |

**The shape.** Each suite is one `final` test class. A data provider `implementations()` yields one row per way an
application gets the seam; a private `bootstrap()` builds each one through `EcotoneLite` and returns
`getGateway(EventStore::class)` or `getGateway(DocumentStore::class)`, so nothing below the gateway is referenced
(rule 10). Every case runs its body through `conformanceCase($implementation, fn (Seam $seam) => …)`.

**The ratchet.** `KNOWN_DIVERGENCES` maps an id to one line naming what each side does and, per case, the targets it
diverges on — `in-memory`, `in-memory-without-event-sourcing-module`, `dbal`, or one engine such as `dbal:sqlite`.
`conformanceCase()` runs a listed case anyway: if it fails there it is **skipped**, naming the id, both sides and this
run's failure; if it passes there the suite **fails** and says which selector to remove. Fixing a divergence is
mechanical — delete the entry, the case runs — and the list can only shrink. A registry check fails if an entry names
a case the suite no longer has. The shape is `bin/suite-parity/php-environment.php`'s accepted-divergences list,
applied to behaviour.

The wrapper is explicit rather than a PHPUnit hook: `TestCase::invokeTestMethod()` exists only in PHPUnit 12 (a
backport from 13), and the PHP 8.2 floor runs PHPUnit 11. For the same reason the cases avoid `expectException()`,
whose check runs after the body returns: they use `try` / `self::fail()` / `catch`.

**Engines.** Every suite green on PostgreSQL 16, MySQL 8.0, MariaDB 11.4 and SQLite, with the recorded skips:

| Engine | EventStore | Tagged | DocumentStore |
|---|---|---|---|
| PostgreSQL | 109 tests, 20 skipped | 46, 13 | 39, 3 |
| MySQL | 109, 26 | 46, 13 | 39, 4 |
| MariaDB | 109, 26 | 46, 13 | 39, 4 |
| SQLite | 109, 22 | 46, 13 | 39, 5 |

**Proof it compares something.** An unknown divergence was injected into the in-memory side of each seam and caught,
with DBAL staying green, then reverted:

- `InMemoryEventStore::loadAggregateEvents()` ignoring its `eventNames` filter →
  `test_aggregate_events_filtered_by_event_names_return_only_those_events` red on both in-memory rows
- `InMemoryTagIndex` ignoring the criterion's event types → `test_an_event_type_filter_excludes_the_other_types_carrying_the_tag`
  red on `in-memory-without-event-sourcing-module` — the row that makes the in-memory tag logic testable while T1
  stands
- `InMemoryDocumentStore::countDocuments()` counting every collection → `test_collections_keep_their_documents_apart`
  red on `in-memory`

And for the ratchet: with E1 "fixed" (a count of 0 returning `[]`), both E1 rows failed, naming the entry to remove.

## 3. The two seams that do not exist yet

The audit's "ship an in-memory deduplication / dead-letter" skips a step: neither has a seam to put a second
implementation behind. Each needs an interface extracted first, and that is a design change for the maintainer.
Nothing below is built.

### Deduplication

`packages/Dbal/src/Deduplication/DeduplicationInterceptor.php:35` is one concrete class doing two jobs:

- **policy** — the deduplication key (message id or `#[Deduplicated]` expression, the consumer endpoint or tracking
  name, the routing slip or handler endpoint id), skipping a message already handled, and **when** to record it:
  before `proceed()` inside a transaction, so a concurrent duplicate fails on the unique key, after `proceed()`
  without one
- **storage** — the `SELECT`, the `INSERT`, the expiry `DELETE` in batches, and creating the table

The seam would take the storage out:

```php
interface HandledMessages
{
    public function wasHandled(DeduplicationKey $key): bool;

    public function recordHandled(DeduplicationKey $key, DateTimeImmutable $handledAt): void;

    public function removeHandledBefore(DateTimeImmutable $threshold, int $batchSize): void;
}
```

with `DbalHandledMessages` and `InMemoryHandledMessages`, named to mirror each other (rule 9). The contract the
conformance suite would pin is that **recording a key twice is refused** — the in-transaction insert-first ordering
depends on it — and that expiry removes only keys older than the threshold.

Decisions it needs:

1. **Where the policy lives.** The interceptor and `DeduplicationModule` are in `packages/Dbal`, so deduplication does
   not exist in a flow test without the DBAL module. An in-memory store is only useful if the policy moves to core,
   with DBAL supplying one implementation. That is a package-boundary move
2. **The transaction-dependent ordering** is a DBAL concern expressed in the policy. It either becomes part of the
   seam's contract, or the store owns it
3. **`$initialized`** on the interceptor is per-connection state on a singleton — rule 3, already deferred. Extracting
   the store is the natural place for it to become the table manager's concern

The conformance suite would drive it by sending the same message twice through a gateway and asserting the handler
ran once — the shape `e87212d14` already uses — with no row counting.

### Dead letter

The public surface is right already: `Ecotone\Api\Dbal\Gateway\DeadLetterGateway` (`store`, `list`, `show`, `count`,
`replay`, `replayAll`, `delete`, `deleteAll`), with `CustomDeadLetterGateway` registering more under their own
reference and connection. Behind every one of them is `DbalDeadLetterHandler`, a concrete class that mixes storage
(the rows) with replay (re-sending through `MessagingEntrypointService`).

The seam would split them:

```php
interface DeadLetterStore
{
    public function store(Message $message): void;

    /** @return ErrorContext[] */
    public function list(int $limit, int $offset): array;

    public function show(string $messageId): Message;

    public function count(): int;

    public function delete(array $messageIds): void;

    public function deleteAll(): void;
}
```

with `DbalDeadLetterStore` and `InMemoryDeadLetterStore`, while replay stays one open-core class that reads a message
from the store, re-sends it and deletes it.

Decisions it needs:

1. **Per-gateway stores.** Each `CustomDeadLetterGateway` names its own connection. The in-memory side needs one store
   per gateway reference, or `list()` would mix two dead-letter queues
2. **`list()` order and paging** — DBAL's order is whatever its query says; the contract has to state it, or the suite
   will find the same unspecified-order question as `getAllDocuments()`
3. **Where it lives.** Like deduplication it is in `packages/Dbal`, so an in-memory store for flow tests means moving
   the gateway wiring to core

The conformance suite would drive `getGateway(DeadLetterGateway::class)` on both: a failing handler routed to the
dead letter, then `count`, `list`, `show`, `replay` and `delete` as an application calls them.

## 4. How the shape generalises

The pattern needs three things from a seam: a gateway or public service an application fetches, a way to bootstrap
each implementation through `EcotoneLite`, and cases stated against the public contract. Adopting it is a data
provider row per implementation, a `bootstrap()` match arm, and the `conformanceCase()` / `KNOWN_DIVERGENCES` /
`targetOf()` trio, which is about 60 lines, copied today in three test classes.

Not lifted into a shared helper on purpose: `packages/Dbal` and `packages/PdoEventSourcing` do not share test
autoloading, and a helper in `Ecotone\Test` would put a PHPUnit-coupled class into core's shipped `src`. When a
fourth seam adopts the shape, the copy count justifies that move.

Not swept: the other `InMemory*` production classes. Most are infrastructure with no storage twin (annotation finder,
console writer, reference search). The ones with a twin are the next candidates, in the order rule 8 reaches them:
`InMemoryStateStoredRepository` and `InMemoryProjectionStateStorage` (rule 8's list, which should arrive already
proved by this shape), then `InMemoryConsumerPositionTracker` against `DbalConsumerPositionTracker`.
