# Where the decision read's PHP time goes, and how to cut it

*Research. 2026-09-29, base `dgafka/ecotone-2-0-dcb-design` @ `4f6d6e22` — answering OQ-F of
`2026-09-29-dcb-read-comparison.md`. Measurement and proposals only; nothing is implemented, no production code or
test is changed, and every instrument used here was thrown away.*

**The question.** The comparison established that for its headline shape — one tag-scoped and one aggregate-backed
decision model over a 1 000-event scope — the read phase is 38–46 ms of which 9–12 ms is SQL, and attributed the
remainder to PHP at 12–18 µs per event per model (Part 0, §2.2, §6.1(3), OQ-F). It named two suspects and asked for
a separate study. This is that study: where the time actually goes, and what can be taken back within the Run's
rules.

---

## Part 0 — The answer in one paragraph

**Neither of the comparison's two suspects is the cost, and the real one is somewhere neither revision looked: the
fold mints a fresh UUID v7 and reads the clock for every event it replays, for a message identity nothing reads.**
Suspect (i), `TagResolver::eventsMatching`, is real but small and not for the stated reason — it does **not** reflect
on attributes per event, because `EventTagRegistry` compiles its value sources once in its constructor and
`PropertyEventTagValueSource` holds a `ReflectionProperty` built there; the whole of it costs **1.1 µs per event per
model**, 3.3% of H3's read. Suspect (ii) is already shipped: events are deserialized once and folded per model from
the same objects, and both models receive the **identical `Event` instance and the identical `readonly` payload**,
verified by identity. What costs is `EventSourcingHandlerExecutor::fill`, at **6.3–6.8 µs per event per model, of
which 3.9 µs is `MessageBuilder` and 1.9 µs of that is `Uuid::v7()` plus a `NativeClock` read** in
`MessageHeaders::createMessageHeadersWith`, because a stream row's metadata carries no `id` and no `timestamp`
(the event's own live in the `event_id` and `created_at` columns, which the read does not select). Removing just
that identity minting — measured by ablation, against a null control whose spread is ±2% — cuts **26% off H3's
whole read phase** on PostgreSQL and 16% on MySQL. Adding the index-derived membership test — asking
`MatchedTagSequences` what the index already matched instead of re-deriving tags from the payload — makes the pair
**−32% on PostgreSQL and −21% on MySQL** (54.2 → 36.6 ms and 49.8 → 39.1 ms in their own paired sweeps). The whole
`MessageBuilder` call is worth −28%, and
letting the aggregate-backed model fold the events the tag read already deserialized — a ceiling, not a proposal,
because the framework cannot know in general that the two reads coincide — is worth a further −30%. **After the two
recommended changes SQL is still not the dominant cost of a thousand-event read**: its share of the read phase goes
from 17% to 23% on PostgreSQL and 21% to 27% on MySQL, so the comparison's verdict on option C stands unchanged.
For the snapshotted shape the picture is different — H5 goes from 38% SQL to **42% SQL**, and the two changes are
worth only 1.4 ms there, which is exactly comparison §6.2's second bullet and is why the recommendation is to take
the cheap PHP wins and leave the SQL alone.

---
## Part 1 — Method

### 1.1 The three instruments

Everything below is measured against the code at `4f6d6e22`, with `php -d xdebug.mode=off` (the compose image loads
`xdebug.mode=debug`, which inflates PHP three to five times), through three instruments — all throwaway, all
deleted after the run, none committed.

- **The shape harness** is the comparison's, rebuilt: `EcotoneLite::bootstrapFlowTestingWithEventStore` with
  `runForProductionEventStore: true`, an Enterprise licence, and a `Doctrine\DBAL\Connection` subclass recording
  every `executeQuery`/`executeStatement` with its elapsed time. Each handler's first statement is a marker, so the
  **read phase** is gateway entry to marker and the read's SQL is the statements logged before it. Every measured
  command runs inside an outer transaction that is rolled back, so each repetition sees the same database.
- **The span instrument** is a `hrtime` stopwatch driven by temporary `start`/`stop` pairs inserted into
  `DecisionModelBatchLoader::load`, `DbalTaggedEventReader::loadByCriteria`,
  `EventSourcingHandlerExecutor::fill` and `DbalEventStore::convertToEvent`. It attributes the read phase to named
  spans. **Per-event spans cost about as much as the work they measure** — two span pairs per event per model add
  ~10 ms to H3's 49 ms read — so the coarse spans and the per-event spans are never mixed, and the per-event split
  is taken from the micro-benchmark instead.
- **The micro-benchmark** drives the real collaborators — pulled out of the running container with
  `FlowTestSupport::getServiceFromContainer` — over the same thousand rows, in a hot loop. Its absolute numbers are
  a lower bound (no live object graph, no GC pressure from the rest of the read); its **proportions** are what it is
  used for.
- **The ablation switches** are temporary branches in the same four files, each turning off one cost and leaving
  the rest of the read intact. An ablation is not a proposal — it is an **upper bound** on what a proposal in that
  direction could be worth.

### 1.2 The noise floor, and how it is controlled

The compose stack's read-phase medians swing by tens of percent between processes. Three devices keep the
conclusions honest:

1. **Each configuration runs in its own process**, three warm-up commands then 25 measured repetitions, median.
2. **The order of (shape, ablation) pairs is shuffled within every pass**, so a slow period of the host cannot land
   on one configuration.
3. **Null controls.** `Z-null` is wired to a flag that changes nothing. Two more nulls come free: `C-sequences`
   cannot touch H2 (which has no tag-scoped model) and `D-reuse-events` cannot touch H1 (which reads no aggregate).
   A row is only read as real if it moves further than the nulls for that shape.

| shape | null controls | floor |
|---|---|---|
| **H3** | `Z-null` −1.9% (PostgreSQL, 16 passes), +7.8% (MySQL, 6) | **±2% on PostgreSQL, ±8% on MySQL** |
| **H3small** | `Z-null` −4.2% / +2.8% | ±4% |
| **H5** | `Z-null` +7.8% / −11.8% | ±12% |
| **H1** | `Z-null` +13.5%, `D-reuse-events` +18.1% | **±18% — too coarse for anything under a fifth** |
| **H2** | `Z-null` +17.7%, `C-sequences` +43.3% | **±43% — unusable for fine distinctions** |

H1 and H2 are reported for completeness and nothing is concluded from them alone. **H3 is the brief's headline
shape and the tightest**, and it carries the argument.

### 1.3 Engines and dataset

PostgreSQL 16.1 throughout; **MySQL 8.0 as the confirming engine**, as the brief allows. The dataset is the
comparison's §1.3 rebuilt on the shipped schema by real appends through `EventStore::appendTo`, so every row, index
row and counter is in the exact format the append path writes:

| | rows |
|---|---|
| `ecotone_event_stream` | 31 010 events |
| — `w-1`, the read instance | **1 000** events, `_aggregate_version` 1…1 000, each tagged `wallet:w-1` |
| — `w-2` … `w-301` | 100 events each |
| — `w-small` | **10** events |
| — `w-h5` | 1 000 events with both decision-model snapshots at `covered_position` **900** |
| `ecotone_tagged_events` | 31 010 index rows |
| `ecotone_tag_versions` | 302 counters |

`VACUUM ANALYZE` / `ANALYZE TABLE` after seeding.

### 1.4 The shapes

The comparison's, minus the ones this question does not need:

| | injects | scope |
|---|---|---|
| **H1** | `SpentToday` — `#[DecisionModel(tags: ['wallet'])]` | `wallet:w-1`, 1 000 events |
| **H2** | `WalletBalance` — `#[DecisionModel(aggregate: ResearchWallet::class)]` | `ResearchWallet:w-1`, 1 000 events |
| **H3** | both | both, 1 000 events, **the headline shape** |
| **H5** | both, `withSnapshotsFor([SpentToday, WalletBalance], 100)`, snapshots at 900 | a 100-event tail |
| **H3small** | both | `w-small`, 10 events |

---
## Part 2 — The profile

### 2.1 The baseline, and that it is the comparison's

Read phase only (gateway entry to the handler's marker), PostgreSQL, medians of 16 passes × 25 repetitions,
uninstrumented.

| shape | read statements | wall ms | SQL ms | PHP ms | PHP share | comparison §2.2 wall |
|---|---|---|---|---|---|---|
| H1 | 3 | 26.47 | 5.61 | 20.75 | 78% | 16.1–25.8 |
| H2 | 4 | 13.39 | 2.78 | 10.61 | 79% | 17.3–18.8 |
| **H3** | **5** | **49.67** | **8.88** | **40.52** | **82%** | **45.0–46.5** |
| H5 | 6 | 7.91 | 3.28 | 4.62 | 58% | 8.0–9.5 |
| H3small | 4 | 2.46 | 1.41 | 1.08 | 44% | 1.8–2.6 |

Statement counts and wall clocks reproduce the comparison's. **H3 is 49.67 ms of which 8.88 ms is SQL**; the 40.52
ms of PHP is what the rest of this document is about. Note already what H3small says: at ten events the read is
44% SQL, so everything below is a cost that scales with the fold, not a constant.

### 2.2 Where H3's read phase goes, by span

Coarse spans only, PostgreSQL, medians of 20. The instrumented read wall is 55.57 ms against 49.67 uninstrumented;
the sixteen span pairs cost about 0.1 ms, the rest is this stack's between-process spread, so the **shares** are
the number to read, not the absolutes.

| span | ms | % of read |
|---|---|---|
| `R1` criteria resolution | 0.039 | 0.1% |
| `R2` tag-scope snapshot lookup | 0.015 | 0.0% |
| `R3` aggregate instance resolution | 0.015 | 0.0% |
| **`R4` `loadEventsFor` — the one tag read** | **16.63** | **29.9%** |
| `R4a`   capture (`ecotone_tag_versions`) | 0.32 | 0.6% |
| `R4b`   `flagsFor` (`ecotone_tagged_events`, 1 000 rows) | 3.03 | 5.4% |
| `R4c`   `eventsReferencedBy` — SQL **and** `convertToEvent` ×1 000 | 9.63 | 17.3% |
| `R4d`   `matchingEvents` — flag join, `uasort`, `MatchedTagSequences::stampOn` | 3.39 | 6.1% |
| **`R5` the tag-scoped model** | **15.29** | **27.5%** |
| `R5a`   `eventsMatching` — the tag re-derivation | **1.83** | **3.3%** |
| `R5b`   `fold` | **13.01** | **23.4%** |
| **`R6` the aggregate-backed model** | **21.67** | **39.0%** |
| `R6a`   snapshot lookup | 0.01 | 0.0% |
| `R6b`   aggregate event read — SQL **and** `convertToEvent` ×1 000 again | 8.95 | 16.1% |
| `R6c`   `fold` | **12.49** | **22.5%** |

**Two folds are 46% of the read phase. Two deserializations of the same thousand events are 33%. The tag
re-derivation the comparison named as suspect (i) is 3.3%.**

### 2.3 Per event, per stage

Micro-benchmark, PostgreSQL, 1 000 events, medians of 25. Read as proportions (§1.1).

| stage | µs/event | what it is |
|---|---|---|
| **row → `Event`** | | |
| `convertToEvent`, `deserialize: true` | **3.07** | the whole row-to-`Event` conversion |
| `convertToEvent`, `deserialize: false` | 1.63 | the same without the payload conversion |
|   `json_decode` payload | 0.36 | |
|   `json_decode` metadata | 0.53 | |
|   `EventSerializer::deserialize` | 2.04 | |
|     `EventMapper` + `Type::create` | 0.13 | |
|     `ConversionService::convert` | 1.16 | array → payload object |
|     `Event::createWithType` + `array_merge` | 0.40 | |
| **index and matching** | | |
| `flagsFor` whole (SQL 1.50 of it) | 1.96 | per index row |
| `matchingEvents` whole | 2.88 | per flag, per branch |
|   `inSequenceOrder` — `uasort` + `stampOn` | 1.15 | one new `Event` per match for the stamp |
| **tag re-derivation, per model** | | |
| `TagResolver::eventsMatching` whole | **1.13** | |
|   `EventTagRegistry::tagsFor` | 0.50–0.60 | |
|     `EventTagValueNormalizer::normalize` | 0.20–0.23 | |
|       `preg_match_all('/./su')` alone | 0.09 | the UTF-8 length check |
|     `ReflectionProperty::isInitialized` + `getValue` | 0.054 | |
|     *a direct property read, for comparison* | *0.014* | *what a compiled extractor would cost* |
|     `get_parent_class` walk (`nearestTaggedClassOf`) | 0.021 | |
|   `matchesEventType` | 0.054 | |
|   `MatchedTagSequences::of` | 0.42 | |
| **the fold, per model** | | |
| `EventSourcingHandlerExecutor::fill` | **6.28–6.79** | |
|   `MessageBuilder` → `build()` | **3.91** | |
|     `Uuid::v7()->toRfc4122()` | **1.23** | |
|     `NativeClock`→`now()`→`inSeconds()` | **0.68** | |
|     `HeaderAccessor::create` + `setHeader` ×4 | 0.48 | |
|   `Assert::isObject`'s discarded message | 0.32 | `Type::createFromVariable(…)->toString()` |
|   *residual: `canHandle` + `executeMethod`* | *≈2.0* | *the user's own `#[EventSourcingHandler]`* |
| **free things, for the proposals** | | |
| `event_name` lookup in a hash (a type filter) | 0.030 | |

**The single largest identified cost in the read is `Uuid::v7()` + `NativeClock` at 1.9 µs per event per model** —
3.8 ms of H3's 49.67, before counting the allocator and GC pressure of the objects they produce, which the
ablations below show is a multiple of that.

### 2.4 Two facts that settle candidates (b) and (d) without an ablation

**Events are already shared.** `DecisionModelBatchLoader::foldInstances` hands every tag-scoped loader the same
`$loadedEvents->events`, and `TagResolver::eventsMatching` filters without copying. Verified by identity in the
harness: two models receive **the same `Event` instance** and **the same payload object**, and the payload class is
`readonly`. Candidate (b) — "deserialize each event once per read and fold per model from the same object" — is the
shipped behaviour, and it is safe. What is *not* shared is the aggregate read's events; see §3.4.

**The tag sources are already compiled.** `EventTagRegistry::__construct` turns its raw definitions into
`EventTagValueSource` objects once, and `PropertyEventTagValueSource::__construct` builds its `ReflectionProperty`
there. Candidate (d) — "compile the per-class tag sources into value extractors at bootstrap" — is done; §2.3 says
what is left for it to win is the 0.04 µs between `ReflectionProperty::getValue` and `$payload->walletId`.

---
## Part 3 — Why each cost is what it is

### 3.1 The fold's message is the largest single cost, and none of it is the user's handler

`EventSourcingHandlerExecutor::fill` builds one `Message` per event per model:

```php
$message = MessageBuilder::withPayload($eventPayload)->setMultipleHeaders($metadata)->build();
```

`build()` reaches `MessageHeaders::createMessageHeadersWith`, which, when the headers carry no `id` and no
`timestamp` — and a stream row's metadata carries neither; the event's own identity lives in the `event_id` and
`created_at` **columns**, which the read does not select — mints a **UUID v7**, reads the **clock**, and copies the
id into `correlationId`. That is done a thousand times per model for a thousand-event fold, for an identity nothing
reads: the message is function-scoped inside `fill`, it is handed to one `#[EventSourcingHandler]` invocation and
discarded, and a handler that asked for `#[Header('id')]` would receive a fresh random value unrelated to the
event. **Accidental.**

The remainder of the builder — `HeaderAccessor::create()`, a `setHeader` call per metadata key, the
`MessageHeaders` validation loop, and the `GenericMessage` allocation — is three object allocations and two array
copies per event per model. It is *inherent to passing a `Message`* to the handler invoker, and only accidental in
the sense that the fold does not need a distinct message per event for any reason the fold itself has.

### 3.2 `Assert::isObject` builds its failure message on every event

```php
Assert::isObject($eventPayload, 'Event returned by repository should be deserialized objects. Got: '
    . Type::createFromVariable($eventPayload)->toString());
```

PHP evaluates the argument before the call, so `Type::createFromVariable(...)->toString()` runs for every event of
every fold and its result is discarded unless the assertion fails. **Accidental**, and the cheapest thing in this
document to fix.

### 3.3 `tagsCarriedBy` does not reflect on attributes — the brief's hypothesis is wrong

OQ-F and §6.1(3) both suppose that `TagResolver::eventsMatching` pays for attribute reflection per event.
It does not. `EventTagRegistry` compiles its raw definitions into `EventTagValueSource` objects **once, in its
constructor**, and `PropertyEventTagValueSource` builds its `ReflectionProperty` there too. What remains per call
is `nearestTaggedClassOf` (a `get_parent_class` walk, 0.02 µs), `ReflectionProperty::isInitialized` +
`getValue` (0.05 µs), `EventTagValueNormalizer::normalize` (0.23 µs, of which a `preg_match_all('/./su')` UTF-8
length check is 0.09 µs), and the two `['name' => …, 'value' => …]` arrays plus the `TagKey` string concatenations
that `carriesAll` builds and throws away.

So candidate (d) — compiling the per-class tag sources into value extractors at bootstrap — is **already done**,
and what is left for it to win is the 0.04 µs between a `ReflectionProperty::getValue` and a direct property read.
The real waste in this stage is different and larger: the work is **repeated for a question the index already
answered**. `DbalTaggedEventReader::matchingEvents` has already tested `flagCarriesAllTags` against the index row
and stamped the matched tag keys onto the event as `MatchedTagSequences`; `carriesAll` then re-derives the same
membership from the payload. **Accidental.**

### 3.4 The events are already shared between models; the duplication is between the two reads

Candidate (b) asked whether an event could be deserialized once per read and folded per model from the same
object. Measured: it already is. `DecisionModelBatchLoader::foldInstances` hands every tag-scoped loader the same
`$loadedEvents->events` array, `TagResolver::eventsMatching` filters without copying, and both models receive the
identical `Event` instance and the identical payload object — verified by identity, with the payload class
`readonly`. Nothing needs changing there and nothing is unsafe.

What *is* duplicated is across the two **reads**. In H3 the tag read returns the thousand events of `wallet:w-1`
and `loadDecisionModelAggregateEvents` returns the same thousand rows again for `ResearchWallet:w-1`, and every one
of them is JSON-decoded and converted a second time. The framework cannot know in general that the two sets
coincide — an aggregate-scoped read and a tag-scoped read are different questions — so this is **inherent to the
current shape** and only removable by making the aggregate-backed model's scope reachable from the tag read, which
is option C's territory and was decided against (comparison Part 6).

### 3.5 `matchingEvents` builds a second `Event` for every match

`MatchedEvents::inSequenceOrder` ends with `MatchedTagSequences::stampOn`, and `stampOn` calls
`Event::withAddedMetadata`, which allocates **a new `Event` and a merged metadata array per matched event** —
1.15 µs each, 6.1% of H3's read inside `R4d`. It is the price of keeping the index's answer on the event rather
than in a side table, and it is what makes proposal P3 possible at all. **Inherent to the design, and it pays for
itself** once P3 uses the stamp instead of re-deriving.

### 3.6 The aggregate read converts the same thousand rows again, and fetches one page too many

`R6b` is 8.95 ms for a thousand-event aggregate read. Of that, the SQL measured alone is 1.92 ms and
`convertToEvent` ×1 000 is 3.07 µs each; the remainder is `selectEvents`' paging and the **second, empty page** the
comparison recorded at §2.3(ii) — the loop breaks on `count($rows) < $limit`, and 1 000 rows against a
`loadBatchSize` of 1 000 is not less than the limit. **Accidental**, one condition, and carried here unchanged
because the profile sees it again.

---

## Part 4 — Proposals, ranked by saving per unit of change

Each proposal names the collaborators it touches — names only, no code — states what it is worth against the null
control for that shape, and is checked against the Run's rules. Ablation letters refer to §4.6.

### 4.1 P1 — The fold stops minting an identity for every event it replays

**What changes.** `EventSourcingHandlerExecutor::fill` supplies `MessageHeaders::MESSAGE_ID` and
`MessageHeaders::TIMESTAMP` for the message it builds per event, so `MessageHeaders::createMessageHeadersWith`
takes neither the `Uuid::v7()` branch nor the `NativeClock` branch nor the `correlationId` copy. Two forms:

- **P1a, the honest one.** `DbalEventStore`'s two read statements (`loadEventsByNumbers`, `selectEvents`) also
  select `event_id`, `EventSerializer::deserialize` puts it on the `Event`, and the fold uses the event's own
  identity. The folded message then carries the identity of the event it replays — strictly more meaningful than
  today's fresh random value, which a handler asking for `#[Header('id')]` receives today and cannot use.
  Costs 36 bytes per row on the wire.
- **P1b, the cheap one.** One identity minted per `fill` call rather than per event. No read change, same saving,
  but the message identity stays meaningless.

**Measured (ablation A).** H3 **−26.0% of the whole read phase on PostgreSQL** (49.67 → 36.74 ms, 16 passes, null
−1.9%) and **−16.2% on MySQL** (47.52 → 39.81, 6 passes, null +7.8%). H5 −12.8% / −17.2%.
H3small −11.9% / −2.6% (inside MySQL's floor — at ten events there is nothing to save). The ablation gives the
message a constant id and timestamp, which is exactly P1b, so **this is a measurement of the proposal, not a
ceiling.**

**Rule check.** *Statelessness*: nothing is remembered between calls; the ids come from the events. *CQS*: `fill`
still only reads and folds. *One load per handler / capture-before-read*: untouched. *Black-box testability*: the
observable change is that a folded message's `id` header is the event's `event_id` (P1a) or constant within a fold
(P1b); the timing is review-protected, per the 2026-09-27 rule. *Open core*: `EventSourcingHandlerExecutor` is
`licence Apache-2.0` and is the **aggregate fold too**, so this changes open-core behaviour — see OQ-1.

### 4.2 P2 — `Assert::isObject` stops building a message it throws away

**What changes.** `EventSourcingHandlerExecutor::fill` tests the payload and composes the failure text only in the
failing branch, instead of evaluating `Type::createFromVariable($eventPayload)->toString()` for every event.

**Measured.** 0.32 µs per event per model, 0.6 ms of H3's read — below the noise floor as a standalone change, and
included here because it is two lines and strictly free.

**Rule check.** Nothing to check: no state, no query, no behaviour change except that the exception message is
built when the exception is thrown. Open-core file, same as P1.

### 4.3 P3 — A tag-scoped model's membership test reads the index's answer

**What changes.** `TagResolver::eventsMatching` decides "does this event carry all of the criteria's tags" from the
`MatchedTagSequences` the store stamped — the way it **already** decides position from them two lines later —
instead of `carriesAll` → `tagsCarriedBy` → `EventTagRegistry::tagsFor` per event per model.
`TagResolver::tagsCarriedBy` stays: the append path (`resolveEvents`, `resolveAppend`) still needs it.

**Correctness.** The stamp is exact for this purpose on both stores. On the Dbal side `DbalTagIndex::flagsFor`
emits a `MAX(CASE …)` column per tag of the **combined** criteria, so `knownSequencesOf` stamps every tag of the
union the event carries, whichever branch matched it; a per-model criteria's tags are a subset. On the in-memory
side `InMemoryTagIndex::sequencesOf` stamps the matching **branch**'s tags, and a decision model's criteria is one
branch, so the tags it asks about are exactly the ones stamped. A filter-only tag is stamped with sequence 0, not
null, so `isset` sees it.

**The behaviour it changes, and why it is arguably a fix.** Today the membership test is re-derived from the
payload with *today's* `#[EventTag]` attributes. If an event class's tags changed after some events were written,
today's code silently drops events the index says are in scope; P3 folds them. **P3 makes an event's tags what they
were when it was appended**, which is the stance `MatchedTagSequences` already takes for positioning and the stance
the backfill command exists to maintain. It is still a change and it needs the maintainer's word — see OQ-2.

**Measured (ablation C).** H3 **−7.0% on PostgreSQL** (16 passes, null −1.9%) and **−9.9% on MySQL** (null +7.8%,
so real on PostgreSQL and marginal on MySQL). The span instrument puts `R5a` at 1.83 ms, 3.3% of the read, so the
ablation saves about twice the direct work — the rest is the allocator and GC pressure of the
`['name' => …, 'value' => …]` arrays and `TagKey` strings `carriesAll` builds and discards per event per model.

**Rule check.** *Statelessness*: `TagResolver` keeps nothing; the stamp travels on the function-scoped event.
*CQS*: a pure predicate replaces a pure predicate. *One load per handler*: unchanged — it removes work, not a read.
*Capture-before-read*: untouched. *Black-box testability*: yes, and it **needs** a parity test (Part 6).
*Open core*: `TagResolver` is `licence Enterprise`; open core is untouched.

### 4.4 P4 — An event no model handles is not deserialized

**What changes.** The tag read narrows before it converts. `DbalTaggedEventReader` already holds the criteria and
therefore its `ofTypes`; `DbalTagIndex::eventsReferencedBy` → `DbalEventStore::loadEventsByNumbers` converts every
row it fetched. Testing `event_name` against the wanted set before calling `convertToEvent` costs **0.030 µs** and
saves **1.43 µs** per skipped event (`convertToEvent` is 3.07 µs with deserialization and 1.63 without) plus that
event's whole fold. `loadDecisionModelAggregateEvents` already narrows by `event_name` in SQL, so this is the tag
read's gap alone.

**Measured.** Not measurable in these shapes: every event in the dataset is handled by both models, so there is
nothing to skip and the ablation would be a no-op. **The saving is per skipped event and stated as such**: 1.43 µs
of conversion plus 6.3 µs of fold per model. A handler whose model handles one of four event types in its tag scope
would skip three quarters of both.

**Rule check.** *Statelessness*, *CQS*, *capture-before-read*: untouched. *One load per handler*: unchanged.
*Black-box testability*: the observable behaviour must be **identical** — a model must fold exactly what it folds
today — so the test is a model whose tag scope contains event types it does not handle. *Open core*: the narrowing
belongs on the Enterprise `DbalTaggedEventReader` side; `DbalEventStore::loadEventsByNumbers` is only reached from
it.

### 4.5 P5 — `selectEvents` stops issuing the empty extra page

Carried unchanged from comparison §2.3(ii) and Part 6, because this profile sees it again inside `R6b`. One
condition; 0.46–0.57 ms per aggregate whose event count is an exact multiple of `loadBatchSize`.

### 4.6 The ablation table, in full

Read phase, medians of independent processes (3 warm-ups + 25 repetitions each), shuffled order.
**`Z-null` changes nothing — read every row against it.**

| ablation | what it turns off | H3 PG (16 passes) | H3 MySQL (6) | H5 PG (8) | H3small PG (16) |
|---|---|---|---|---|---|
| *baseline* | — | *49.67 ms* | *47.52 ms* | *7.91 ms* | *2.46 ms* |
| **`Z-null`** | **nothing — the floor** | **−1.9%** | **+7.8%** | **+7.8%** | **−4.2%** |
| `A-identity` | the per-event UUID + clock (**= P1**) | **−26.0%** | **−16.2%** | −12.8% | −11.9% |
| `B-one-message` | the whole per-event `MessageBuilder` (**ceiling**) | **−28.0%** | **−29.9%** | −18.3% | −18.7% |
| `C-sequences` | the tag re-derivation (**= P3**) | **−7.0%** | −9.9% | −19.7% | −9.1% |
| `D-reuse-events` | the aggregate re-read and re-deserialization (**ceiling**) | **−30.4%** | −11.1% | −29.8% | −31.2% |
| `A+C` | **the recommended pair** | **−32.4%** | **−21.4%** | −17.1% | −27.4% |
| `A+C+D` | the pair plus the ceiling | −43.7% | −30.6% | −27.0% | −37.4% |
| `B+C+D` | every ceiling together | −57.6% | −52.5% | −43.1% | −38.5% |

*`A+C` is from its own paired sweep (8 passes per engine, baselines 54.16 ms PostgreSQL and 49.80 ms MySQL); the
other columns are from the main sweep. Percentages are always against the baseline of the same sweep.*

**`A-identity` and `C-sequences` are proposals; `B-one-message` and `D-reuse-events` are ceilings.** `B` replaces
the per-event message with one shared object, which is not a thing the fold may actually do — it measures how much
of the fold is message construction (P1 recovers about 90% of it on PostgreSQL). `D` lets the aggregate-backed
model fold the events the tag read already deserialized, which the framework cannot decide in general (§3.4) — it
measures what a merged read would be worth **on the PHP side**, 30% of the read phase, which is more than the
comparison found on the SQL side and is the one number that would strengthen option C's case.

### 4.7 Ranked

| rank | proposal | saving on H3 | cost |
|---|---|---|---|
| **1** | **P1** the fold stops minting an identity per event | **−26% PG / −16% MySQL of the whole read phase** | one branch in one open-core method (P1b), or that plus one column in two read statements (P1a) |
| **2** | **P3** membership from `MatchedTagSequences` | **−7% PG / −10% MySQL**, and −32% with P1 | one predicate in `TagResolver`, one decision about tag-history semantics (OQ-2), one parity test |
| **3** | **P2** `Assert::isObject`'s discarded message | 0.6 ms, below the floor alone | two lines |
| **4** | **P4** skip deserializing unhandled events | nothing in these shapes; **7.7 µs per skipped event per model** | one narrowing in the Enterprise reader |
| **5** | **P5** the empty extra page | 0.46–0.57 ms per exact-multiple aggregate | one condition |

---
## Part 5 — What this does to the read comparison's conclusion

### 5.1 The read phase, before and after, measured

`A+C` is P1 + P3 built as a throwaway patch and measured in its own paired sweep, 8 passes × 25 repetitions per
engine. P2, P4 and P5 are not in it: P2 is below the floor, P4 saves nothing in these shapes, P5 is one statement.

| shape | engine | today wall | today SQL | **SQL share today** | P1+P3 wall | P1+P3 SQL | **SQL share after** |
|---|---|---|---|---|---|---|---|
| **H3** | PostgreSQL | 54.16 | 9.22 | **17.0%** | **36.61** | 8.46 | **23.1%** |
| **H3** | MySQL | 49.80 | 10.47 | **21.0%** | **39.12** | 10.38 | **26.5%** |
| H5 | PostgreSQL | 8.30 | 3.12 | 37.6% | 6.89 | 2.89 | 42.0% |
| H5 | MySQL | 8.00 | 3.03 | 37.9% | 7.41 | 3.11 | 42.0% |
| H3small | PostgreSQL | 2.06 | 1.14 | 55.3% | 1.50 | 0.85 | 57.1% |
| H3small | MySQL | 2.03 | 1.08 | 53.2% | 1.78 | 1.06 | 59.7% |

### 5.2 Does SQL become dominant again?

**For the thousand-event shape, no.** H3's read phase goes from 17–21% SQL to 23–27% SQL. Three quarters of it is
still PHP, and the largest remaining item is the fold's `canHandle` + `executeMethod` — the user's own
`#[EventSourcingHandler]` methods and the parameter conversion that feeds them — plus two deserializations of the
same thousand rows. **Comparison §6.2's third bullet is not triggered and option C's case does not re-open on this
evidence.** Even the most aggressive ceiling in this document, `B+C+D` (−57.6% on PostgreSQL), leaves the read at
21.07 ms of which 5.66 ms — 27% — is SQL.

**For the small and snapshotted shapes it already was dominant, and stays so.** H3small is 53–55% SQL today and
57–60% after; H5 is 38% today and 42% after. These are exactly the two shapes the comparison found option C makes
*slower* (§3.3: three tiny index lookups beat one statement with three CTEs), so a rising SQL share there is not an
argument for option C — it is an argument that the constant per-statement cost is what matters at small scopes,
which is what the comparison already concluded.

**The one number that would move the comparison is `D`, and it is not a proposal.** Letting the aggregate-backed
model fold events the tag read already deserialized is worth −30% of the read phase on PostgreSQL and takes SQL
from 9.2 ms to 5.5 ms — a 40% cut in SQL, larger than anything Part 3 of the comparison measured for option C.
That is because it removes a **whole statement pair and a whole deserialization pass**, not because it shapes SQL
better. If option C is ever revisited under OQ-A (a remote database), this is the number to revisit it with: the
saving from merging the aggregate read into the tag read is **mostly PHP, not round trips**, and the comparison
measured only the round trips.

### 5.3 What the comparison's §6.1(3) should now say

> **3. Attack the fold, not the read.** H3 spends 9–12 ms in the database and 38–46 ms in the read phase.
> ~~`TagResolver::eventsMatching` re-derives every event's tags from its payload object for every model~~ — that is
> 3.3% of it. The fold mints a UUID v7 and reads the clock for every event of every model, which is 26% of it, and
> the two models' events are deserialized twice because the tag read and the aggregate read are separate
> statements, which is another 33%. See `2026-09-29-dcb-fold-cost.md`.

---

## Part 6 — Recommendation and build order

**Build P1b, P2, P3 and P5 as one unit; hold P1a and P4 for a decision.** That is a single Enterprise-plus-open-core
unit worth roughly a third of the decision read's wall clock on the brief's headline shape, on two engines, with no
schema change, no configuration, no new collaborator and no change to the guarantee set.

| order | item | why here |
|---|---|---|
| **1** | **P2** — `Assert::isObject` builds its message lazily | Two lines, no decision, no test beyond the existing ones. Do it first so the fold's later diff is small. |
| **2** | **P1b** — one message identity per fold, not per event | The largest single saving in this document and the smallest change that gets it. Open-core file, so it lands before anything Enterprise. |
| **3** | **P5** — `selectEvents` breaks on an empty page too | One condition, already agreed in comparison Part 6, and the profile sees it inside `R6b`. |
| **4** | **P3** — membership from `MatchedTagSequences` | Needs OQ-2 answered and needs the in-memory/Dbal parity test written first (Part 7 below is the test plan). |
| *hold* | **P1a** — the folded message carries the event's `event_id` | Better semantics than P1b and the same saving, but it widens two read statements and touches the `Event`/`EventSerializer` contract. Worth doing, worth doing separately. |
| *hold* | **P4** — skip deserializing unhandled events | Correct and cheap, but it saves nothing on any shape in the design's own examples. Build it when a shape that needs it appears, or alongside P1a since both touch the read statements. |
| *never* | **D** — the aggregate-backed model reuses the tag read's events | Not sound in general (§3.4). Recorded only as the ceiling it is, and as the number that would re-open option C (§5.2). |

**What does not change.** One load per handler, capture-before-read, the optimistic guarded `UPDATE`, the statement
count, the isolation analysis of comparison §2.4, the in-memory store as the semantic reference, and every
guarantee in the DCB spec. Nothing here reads a row it does not read today, writes a row, or opens a transaction.

---

## Part 7 — Test plan, and docs to touch

### 7.1 Tests — black box, behaviour only

Per the 2026-09-27 rule, **no per-event timing is asserted anywhere**; the numbers in this document are
review-protected like comparison Part 2's and revision 2 Part 12's. Every test below is an `EcotoneLite` test
expressed through userland API and observable behaviour.

| for | test |
|---|---|
| P1b | A decision model whose `#[EventSourcingHandler]` takes `#[Header(MessageHeaders::MESSAGE_ID)]` sees the same id for every event of one fold, and a different one for a second fold. *(Pins that the identity is per-fold, so a later change back to per-event is caught.)* |
| P1b | An aggregate folded from ten events produces the same state as today — the existing aggregate and decision-model suites cover this; the unit adds none. |
| P2 | Folding a repository result whose payload is an array still raises `InvalidArgumentException` with the type in the message. *(The only observable behaviour the lazy message can break.)* |
| P3 | **Parity**: the same handler, the same events, the same tag scope, folded identically on the in-memory store and on all four Dbal engines — a model over a multi-tag AND scope, a model over a filter-only tag mixed with a counted one, and a model whose scope is one branch of a handler whose other model uses a different tag. |
| P3 | A model scoped by tag `a` does **not** fold an event carrying only tag `b`, when a second model in the same handler is scoped by `b` and pulled that event into the shared read. *(The one case where reading the stamp could over-include if the stamp were not per-tag.)* |
| P3 | A snapshotted model with `tag_sequence > covered` pushed down folds exactly the tail. *(Already covered; re-run because P3 changes how the same events are admitted.)* |
| P3 + OQ-2 | If OQ-2 is answered "the index wins": an event written before a tag was added to its class, then backfilled, is folded by a model scoped by that tag. Today it is not. |
| P4 | A model whose tag scope contains event types it does not handle folds exactly the events it handles, and the handler observes the same state as today. |
| P5 | An aggregate whose event count is an exact multiple of `loadBatchSize` loads all of its events. *(Already true; the test pins it while the loop condition changes.)* |

### 7.2 Docs

| file | what |
|---|---|
| `docs/superpowers/specs/2026-09-29-dcb-read-comparison.md` | §6.1(3) replaced with §5.3 above; OQ-F answered with a pointer here |
| `docs/superpowers/specs/2026-09-29-dcb-single-read-snapshot-design.md` | §12.2's cost table gains a line saying what share of the read is PHP, so revision 3 is not written against SQL alone |
| `upgrade-2.0.md` | Only if P1a or the OQ-2 answer changes observable behaviour. P1b, P2, P3-as-a-no-op-change, P4 and P5 need no entry. |
| the DCB user documentation | Nothing. No API, no configuration and no guarantee changes. |

---

## Part 8 — Open questions, with recommended answers

**OQ-1 — P1 and P2 are in `EventSourcingHandlerExecutor`, which is open core and folds aggregates too. Is that in
scope for a DCB unit?** The saving is the same for `Repository::getFor()` on a long-lived event-sourced aggregate
as for a decision model — it is the same `fill`. *Recommendation: yes, and say so in the unit's goal. The Run has
already taken one open-core fix on this basis (the `tableExists` probe in `loadAggregateEvents`, comparison
§2.3(i)), for the same reason: it is one method, the fix is strictly better everywhere, and confining it to the
Enterprise path would mean duplicating the fold.*

**OQ-2 — under P3, whose answer is an event's tag set: the index's, or today's attributes?** They differ only when
an event class's `#[EventTag]` declarations changed after events were written. Today the payload wins and matching
events are silently dropped; under P3 the index wins and they are folded. *Recommendation: the index. It is what
the append wrote, it is what the backfill command exists to reconcile, it is already the authority for
`tag_sequence` and for whether the event is returned at all, and "silently drops events the index says are in
scope" is not behaviour worth preserving. It needs the maintainer's word because it is a semantic change, not a
performance one.*

**OQ-3 — P1a or P1b?** P1b is one branch and gets the whole measured saving. P1a costs two widened read
statements and a change to what `Event` carries, and in exchange the folded message's `id` is the event's own
`event_id` rather than a constant. *Recommendation: P1b now, P1a as a separate unit alongside P4 — both touch the
read statements and both are semantics-first rather than speed-first.*

**OQ-4 — should the fold hand the handler a `Message` at all?** `MessageBuilder` is 3.91 µs of the fold's 6.3–6.8
and P1 removes only 1.9 of that; the rest is `HeaderAccessor`, the `MessageHeaders` validation loop and the
`GenericMessage` allocation, which exist so that an `#[EventSourcingHandler]` can take `#[Header]` parameters.
Ablation `B` says removing all of it is worth −28% against P1's −26%. *Recommendation: no. The remaining 2 µs buys
the parameter-converter contract for event sourcing handlers, which is a public API; two percent of a
thousand-event read is not a reason to narrow it.*

**OQ-5 — is the measurement stable enough to build on?** The null control is ±2% on H3/PostgreSQL and ±8% on
H3/MySQL, and P1's and P1+P3's effects are three to sixteen times that on both. H1 (±18%) and H2 (±43%) are too
noisy to carry an argument and none is built on them. *Recommendation: yes for H3; re-measure H5 before claiming
anything about the snapshotted shape beyond "the two changes are worth about 1.4 ms there", because H5's floor is
±12% and P1+P3 moves it 7–17%.*

**OQ-6 — what is left after P1–P5, and is it worth another pass?** H3's read would be ~37 ms: about 9 ms SQL,
about 6 ms deserializing a thousand rows twice, about 3.4 ms in `matchingEvents`' sort and stamp, and the rest in
the two folds' `canHandle` + `executeMethod` and the parameter conversion feeding them. *Recommendation: no third
pass on the read. The next order-of-magnitude change for a thousand-event scope is the one already shipped —
snapshots (H5 is 7.9 ms against H3's 49.7) — and the documentation should point a user with a long scope at a
snapshot before at anything in this document.*

---

## Appendix — Reproducing this

One directory of throwaway PHP under `research-harness/`, run inside the compose `app` container with
`php -d xdebug.mode=off`, plus a throwaway instrumentation patch to four production files. Neither is committed and
both were reverted after the run. The shape, for anyone rebuilding it:

- `shapes.php` — the two decision models, the aggregate, the three event classes, three commands (one per shape,
  because one payload class may route to only one handler), the `#[Converter]`s and the two media-type converters
  the snapshots need, and the handler-entry marker that defines the read phase.
- `bootstrap.php` — the recording `Connection` subclass, the DSNs, and `bootstrapEcotone()` over
  `EcotoneLite::bootstrapFlowTestingWithEventStore` with `runForProductionEventStore: true` and an Enterprise
  licence.
- `seed.php`, `seed_h5.php` — §1.3's dataset, written through `EventStore::appendTo` in transactional batches so
  the rows, index rows and counters are byte-for-byte what a real append produces; `seed_h5.php` additionally
  commits one snapshotting command at 900 events and then appends 99 more.
- `profile.php` — the per-stage micro-benchmark of §2.3, driving the real collaborators pulled from the container.
- `micro.php` — §2.3's remaining rows and the identity checks of §2.4.
- `attribute.php` — the coarse span attribution of §2.2.
- `ablate.php`, `sweep.sh`, `sweep2.sh` — one process per (engine, shape, ablation, pass), shuffled within a pass.
- The patch: a `ResearchStopwatch` holding the span totals and the ablation switches, `start`/`stop` pairs in
  `DecisionModelBatchLoader::load`, `DbalTaggedEventReader::loadByCriteria`,
  `EventSourcingHandlerExecutor::fill` and `DbalEventStore::convertToEvent`, and the four ablation branches in the
  same files.

Per the 2026-09-27 rule none of this is a test and none of these numbers is asserted anywhere in the suite; they
are review-protected, as comparison Part 2's and revision 2 Part 12's are.
