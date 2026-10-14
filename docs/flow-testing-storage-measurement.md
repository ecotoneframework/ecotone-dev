# Flow testing: which storage implementation each test executes

Measured with `bin/measure-storage-implementations.php` across the 15 root testsuites on PostgreSQL, before
(`010e46043`) and after (`6868ae6a3`) the unit that made the connection a flow test is given decide its storage. A test
counts for an implementation when it executes a line of that class outside its constructor.

| Seam | Implementation | Before | After |
|---|---|---:|---:|
| event store | storage | 436 | 444 |
| event store | in-memory | 371 | 370 |
| document store | storage | 116 | 119 |
| document store | in-memory | 1052 | 1784 |
| deduplication | storage | 63 | 118 |
| deduplication | in-memory | 43 | 131 |
| dead letter | storage | 43 | 47 |
| dead letter | in-memory | 19 | 16 |
| storage-backed, any seam | | 621 | 665 |

**No test lost storage on any seam.** Every rise is a test that was given a connection:

- **Deduplication +55.** 36 ran on in-memory deduplication despite their connection, because a `TestConfiguration` was
  present - among them `DbalDeduplicationModuleTest` (10) and `DbalDeduplicationInterceptorTest` (7), tests *of* DBAL
  deduplication that were exercising the in-memory implementation. 18 are event-sourcing projection and workflow tests
  that ran with deduplication off under the removed `bootstrapFlowTestingWithEventStore()`'s
  `DbalConfiguration::createForTesting()` and now run the production default; none of them asserts that a message is
  handled, retried, requeued or redelivered more than once, and the two that replay after a reset assert the read model
  their reset empties, so a swallowed replay would fail them. 1 is a proof test of this unit.
- **Event store +8, document store +3, dead letter +4:** proof tests of this unit, and tests that passed a connection
  but ran in memory under `runForProductionEventStore: false`, `createForTesting()`'s in-memory document store, or the
  `TestConfiguration` rule.
- **The in-memory rises are reach.** The document store's +738 touch only `InMemoryDocumentStore::createEmpty()`,
  called when the DBAL module loads; the deduplication rise was core tests running in-memory deduplication because the
  DBAL module loaded in them, which `6641a4f99` removes by running the core suite core-only.

**Harness exit codes are the same before and after - Core 1, DataProtection 2, Dbal 1, Amqp 1 - and none is a test
failure.** Core, Dbal and Amqp exit 1 on PHPUnit's "is not a valid target for code coverage" warnings, because the
harness limits `<source>` to the eight seam classes and `#[CoversClass]` targets outside them warn. DataProtection
exits 2 on four `FileTest` `@extra-large` errors, because the generated `big-generated-file` fixture does not exist in
the checkout.
